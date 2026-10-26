<?php
declare(strict_types=1);
/*
 * Shared by the Control Tower and the Dashboard: where the vehicles are, and every trip of a date range with its stores, ETA, delay and the summary numbers.
 */
require_once TMS_ROOT . '/app/services/tracking.php';

/** Position, state and (when the address still has to be looked up by the browser) a lookup plan for each vehicle number. */
function ct_positions(PDO $d, array $nos): array {
  $T = tms_trk_latest($d); $out = []; $plans = 0;
  foreach ($nos as $no) {
    $k = tms_trk_norm((string)$no); $o = $T[$k] ?? null; if (!$o) continue;
    $pos = null; $lookup = null; $exact = $o['lat'] !== null && $o['lng'] !== null;
    if ($exact) $pos = ['lat' => (float)$o['lat'], 'lng' => (float)$o['lng']];
    elseif ($o['location']) {
      $pos = tms_trk_cache_get($d, $o['location']);
      if (!$pos) { $pos = tms_trk_geocode_local($d, $o['location']); if ($plans < 12 && !tms_trk_cache_missing($d, $o['location'])) { $lookup = tms_trk_geo_plan($d, (string)$o['location']); $plans++; } }
    }
    $trail = $o['trail'] ? json_decode((string)$o['trail'], true) : null;
    $out[$k] = ['no' => $o['vehicle_number'], 'state' => $o['state'], 'fresh' => $o['fresh'], 'speed' => $o['speed'] !== null ? round((float)$o['speed']) : null, 'age' => tms_trk_age($o['age_min']),
      'location' => $o['location'], 'dayKm' => $o['day_km'], 'halted' => $o['halted_since'], 'pos' => $pos, 'exact' => $exact, 'lookup' => $lookup, 'trail' => is_array($trail) ? $trail : null, 'sources' => $o['sources']];
  }
  return $out;
}

/** Trips of a date range, enriched. Returns ['tj' => trips with stops (for the Control Tower), 'S' => summary numbers, 'veh' => vehicle positions, 'trips' => raw rows]. */
function ct_build(PDO $d, string $from, string $to): array {
$q = $d->prepare("SELECT t.id, t.trip_code, t.status, t.planned_date, t.dispatched_at, t.completed_at, t.created_at, t.total_boxes, t.store_count, t.route_km, t.vehicle_type,
    COALESCE(CASE WHEN upper(COALESCE(t.driver_name,'')) IN ('','UNASSIGNED') THEN NULL ELSE t.driver_name END, dr.driver_name) driver_name, COALESCE(NULLIF(t.driver_phone,''), dr.phone) driver_phone,
    COALESCE(NULLIF(v.vehicle_number,''), CASE WHEN upper(COALESCE(t.vehicle_number,'')) IN ('','UNASSIGNED') THEN NULL ELSE t.vehicle_number END) vehicle_number, t.tpt_name, dc.dc_code, dc.dc_name, dc.latitude dc_lat, dc.longitude dc_lng,
    (SELECT string_agg(i.indent_no, ', ' ORDER BY i.id) FROM tms_indents i WHERE i.trip_id=t.id) indents
  FROM trips t LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN drivers dr ON dr.id=t.driver_id LEFT JOIN tms_dc_master dc ON dc.id=t.source_dc_id
  WHERE t.status<>'Cancelled' AND COALESCE(t.planned_date, t.created_at)::date BETWEEN ? AND ? ORDER BY t.id DESC LIMIT 400");
$q->execute([$from, $to]); $trips = $q->fetchAll(PDO::FETCH_ASSOC);
$ids = array_map(fn($t) => (int)$t['id'], $trips); $stops = [];
if ($ids) foreach ($d->query("SELECT ts.trip_id, ts.sequence_no, ts.packet_count, ts.delivered_at, ts.expected_delivery_at, s.store_code, s.store_name, s.city, s.latitude, s.longitude, s.transit_days, s.state st_state, s.cluster_type st_cluster
    FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id WHERE ts.trip_id IN (" . implode(',', $ids) . ") ORDER BY ts.trip_id, ts.sequence_no")->fetchAll(PDO::FETCH_ASSOC) as $s) $stops[(int)$s['trip_id']][] = $s;
$veh = ct_positions($d, array_values(array_unique(array_filter(array_column($trips, 'vehicle_number')))));
// when a trip was created and when it was accepted (first "Assigned"): acceptance time
$hist = [];
if ($ids) foreach ($d->query("SELECT trip_id, MIN(created_at) FILTER (WHERE status='Planned') p, MIN(created_at) FILTER (WHERE status='Assigned') a FROM tms_trip_status_history WHERE trip_id IN (" . implode(',', $ids) . ") GROUP BY trip_id")->fetchAll(PDO::FETCH_ASSOC) as $h) $hist[(int)$h['trip_id']] = $h;
$cancelled = (int)$d->query("SELECT COUNT(*) FROM trips WHERE status='Cancelled' AND COALESCE(planned_date, created_at)::date BETWEEN " . $d->quote($from) . " AND " . $d->quote($to))->fetchColumn();

// ---- ETA and delay of every store of a trip.  ETA = leaving time + road km / 40 km/h + 45 min unloading per earlier stop; due = trip start + the store's transit (SLA) days.
$now = tms_trk_now(); $SPEED = 40.0; $UNLOAD = 45 * 60; $ROAD = 1.3; $today = date('Y-m-d', $now); $tomorrow = date('Y-m-d', $now + 86400);
$kmP = fn($a, $b) => ($a && $b) ? tms_trk_km(['lat' => $a[0], 'lng' => $a[1]], ['lat' => $b[0], 'lng' => $b[1]]) * $ROAD : null;
$tj = []; $S = ['created' => count($trips), 'cancelled' => $cancelled, 'accepted' => 0, 'pending' => 0, 'pendingLate' => 0, 'stopsTotal' => 0, 'stopsDone' => 0, 'stopsTransit' => 0, 'stopsReady' => 0, 'stopsPlanned' => 0,
  'etaToday' => 0, 'etaTomorrow' => 0, 'delayed' => 0, 'late' => 0, 'onTime' => 0, 'pkt' => 0, 'pktTransit' => 0, 'pktDone' => 0, 'veh' => ['moving' => 0, 'stopped' => 0, 'nosignal' => 0, 'unknown' => 0, 'none' => 0]];
$accMin = []; $delayList = []; $etaList = []; $day = [];
foreach ($trips as $t) {
  $tid = (int)$t['id']; $st = $stops[$tid] ?? []; $status = (string)$t['status'];
  $g = in_array($status, ['In Transit', 'Breakdown', 'Accident'], true) ? 'transit' : ($status === 'Delivered' ? 'done' : 'open');
  $accepted = $status !== 'Planned'; $startTs = strtotime((string)($t['dispatched_at'] ?: ($t['planned_date'] ?: $t['created_at'])));
  $dcPos = ((float)$t['dc_lat'] || (float)$t['dc_lng']) ? [(float)$t['dc_lat'], (float)$t['dc_lng']] : null;
  $vk = $t['vehicle_number'] ? tms_trk_norm((string)$t['vehicle_number']) : null; $vp = $vk && !empty($veh[$vk]['pos']) ? [(float)$veh[$vk]['pos']['lat'], (float)$veh[$vk]['pos']['lng']] : null;
  // where the vehicle is when the clock for the remaining stops starts
  if ($g === 'transit' && $vp) { $prev = $vp; $clock = $now; } elseif ($t['dispatched_at']) { $prev = $dcPos; $clock = strtotime((string)$t['dispatched_at']); } else { $prev = $dcPos; $clock = max($startTs, $now); }
  $sj = []; $tripDelayed = false; $tripEtaToday = false;
  foreach ($st as $s) {
    $pos = ((float)$s['latitude'] || (float)$s['longitude']) ? [(float)$s['latitude'], (float)$s['longitude']] : null; $pk = (int)$s['packet_count'];
    $due = $startTs + max(0.1, (float)($s['transit_days'] ?: 1)) * 86400; $eta = null; $dl = false; $dh = 0; $isLate = false;
    $S['stopsTotal']++; $S['pkt'] += $pk;
    if ($s['delivered_at']) {
      $S['stopsDone']++; $S['pktDone'] += $pk; $isLate = strtotime((string)$s['delivered_at']) > $due; $S['late'] += $isLate ? 1 : 0; $S['onTime'] += $isLate ? 0 : 1; $prev = $pos ?: $prev;
    } else {
      if ($accepted) {
        $km = $kmP($prev, $pos); $clock += (int)(($km !== null ? $km / $SPEED * 3600 : 5400)); $eta = $clock; $clock += $UNLOAD; $prev = $pos ?: $prev;
        $dl = $now > $due || $eta > $due; $dh = $dl ? (int)round((max($now, $eta) - $due) / 3600) : 0;
        if ($g === 'transit') { $S['stopsTransit']++; $S['pktTransit'] += $pk; } else $S['stopsReady']++;
        $ed = date('Y-m-d', $eta); if ($ed === $today) { $S['etaToday']++; $tripEtaToday = true; } elseif ($ed === $tomorrow) $S['etaTomorrow']++;
        if ($dl) { $S['delayed']++; $tripDelayed = true; $delayList[] = ['id' => $tid, 'trip' => $t['trip_code'], 'store' => $s['store_code'], 'name' => $s['store_name'], 'h' => $dh]; }
        $etaList[] = ['id' => $tid, 'trip' => $t['trip_code'], 'store' => $s['store_code'], 'name' => $s['store_name'], 'eta' => date('Y-m-d H:i:s', $eta), 'late' => $dl];
      } else $S['stopsPlanned']++;
    }
    $sj[] = ['n' => (int)$s['sequence_no'], 'code' => $s['store_code'], 'name' => $s['store_name'], 'city' => $s['city'], 'pkt' => $pk, 'at' => $s['delivered_at'], 'eta' => $eta ? date('Y-m-d H:i:s', $eta) : $s['expected_delivery_at'], 'late' => $dl, 'lateH' => $dh, 'dlate' => $isLate, 'pos' => $pos, 'state' => $s['st_state'], 'cluster' => $s['st_cluster']];
  }
  if ($accepted) $S['accepted']++; else { $S['pending']++; if (strtotime(date('Y-m-d', $startTs)) < strtotime($today)) $S['pendingLate']++; }
  $tripAcc = null; $h = $hist[$tid] ?? null; $p0 = $h['p'] ?? $t['created_at']; if ($h && !empty($h['a'])) { $m = (strtotime((string)$h['a']) - strtotime((string)$p0)) / 60; if ($m >= 0 && $m < 60 * 24 * 14) { $accMin[] = $m; $tripAcc = (int)round($m); } }
  $cd = substr((string)$t['created_at'], 0, 10); $day[$cd]['c'] = ($day[$cd]['c'] ?? 0) + 1; if ($h && !empty($h['a'])) { $ad = substr((string)$h['a'], 0, 10); $day[$ad]['a'] = ($day[$ad]['a'] ?? 0) + 1; }
  if ($t['completed_at']) { $dd = substr((string)$t['completed_at'], 0, 10); $day[$dd]['d'] = ($day[$dd]['d'] ?? 0) + 1; }
  if ($accepted && $g !== 'done' && $t['vehicle_number']) { $v = $vk ? ($veh[$vk] ?? null) : null; if (!$v) $S['veh']['unknown']++; elseif ($v['fresh'] === 'lost' || $v['state'] === 'NO SIGNAL') $S['veh']['nosignal']++; elseif ($v['state'] === 'RUNNING') $S['veh']['moving']++; else $S['veh']['stopped']++; }
  elseif ($accepted && $g !== 'done') $S['veh']['none']++;
  $tj[] = ['id' => $tid, 'code' => $t['trip_code'], 'status' => $status, 'driver' => $t['driver_name'], 'phone' => $t['driver_phone'], 'veh' => $t['vehicle_number'], 'vk' => $vk,
    'tpt' => $t['tpt_name'], 'dc' => $t['dc_code'], 'dcName' => $t['dc_name'], 'dcPos' => $dcPos,
    'indents' => $t['indents'], 'pkt' => (int)$t['total_boxes'], 'km' => $t['route_km'] !== null ? round((float)$t['route_km']) : null,
    'start' => $t['dispatched_at'] ?: ($t['planned_date'] ?: $t['created_at']), 'end' => $t['completed_at'], 'acc' => $accepted, 'accMin' => $tripAcc, 'created' => $t['created_at'], 'vtype' => $t['vehicle_type'], 'delay' => $tripDelayed, 'etaToday' => $tripEtaToday, 'stops' => $sj];
}
$S['acceptPct'] = $S['created'] ? (int)round($S['accepted'] * 100 / $S['created']) : 0;
$S['avgAccMin'] = $accMin ? (int)round(array_sum($accMin) / count($accMin)) : null;
$S['onTimePct'] = ($S['onTime'] + $S['late']) ? (int)round($S['onTime'] * 100 / ($S['onTime'] + $S['late'])) : null;
usort($delayList, fn($a, $b) => $b['h'] <=> $a['h']); $S['delayList'] = array_slice($delayList, 0, 8);
usort($etaList, fn($a, $b) => strcmp($a['eta'], $b['eta'])); $S['etaList'] = array_slice($etaList, 0, 8);
$fun = ['Pending' => 0, 'Assigned' => 0, 'At source' => 0, 'Loaded' => 0, 'In transit' => 0, 'Delivered' => 0];
foreach ($trips as $t) { $k = ['Planned' => 'Pending', 'Assigned' => 'Assigned', 'Reached at source' => 'At source', 'Loaded' => 'Loaded', 'Gate Pass Created' => 'Loaded', 'In Transit' => 'In transit', 'Breakdown' => 'In transit', 'Accident' => 'In transit', 'Delivered' => 'Delivered'][$t['status']] ?? null; if ($k) $fun[$k]++; }
$S['funnel'] = $fun;
$days = []; for ($i = 13; $i >= 0; $i--) { $k = date('Y-m-d', strtotime($to . " -$i days")); $days[] = ['d' => $k, 'c' => $day[$k]['c'] ?? 0, 'a' => $day[$k]['a'] ?? 0, 'x' => $day[$k]['d'] ?? 0]; } $S['days'] = $days;
  return ['tj' => $tj, 'S' => $S, 'veh' => $veh, 'trips' => $trips];
}
