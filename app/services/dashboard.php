<?php
declare(strict_types=1);
/*
 * Numbers for the Dashboard tabs: Operations, Transport, In transit, PRD.
 * Trip tabs take the shared trip data of app/services/control_tower.php ($B = ct_build(...)); PRD reads the local prd_pending table.
 */
require_once TMS_ROOT . '/app/services/control_tower.php';

/** First time each trip reached each status (from the status history) and the latest status change: [trip id => ['t' => [status => ts], 'last' => ts, 'lastStatus' => s]]. */
function dash_hist(PDO $d, array $ids): array {
  $h = []; if (!$ids) return $h;
  foreach ($d->query("SELECT trip_id, status, MIN(created_at) first_at, MAX(created_at) last_at FROM tms_trip_status_history WHERE trip_id IN (" . implode(',', array_map('intval', $ids)) . ") GROUP BY trip_id, status")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $t = (int)$r['trip_id']; $h[$t]['t'][$r['status']] = $r['first_at'];
    if (!isset($h[$t]['last']) || strcmp((string)$r['last_at'], (string)$h[$t]['last']) > 0) { $h[$t]['last'] = $r['last_at']; $h[$t]['lastStatus'] = $r['status']; }
  }
  return $h;
}
/** Average minutes between two statuses over all trips that have both. */
function dash_avg_between(array $hist, string $a, string $b): ?int {
  $m = []; foreach ($hist as $h) { if (!empty($h['t'][$a]) && !empty($h['t'][$b])) { $x = (strtotime((string)$h['t'][$b]) - strtotime((string)$h['t'][$a])) / 60; if ($x >= 0 && $x < 60 * 24 * 30) $m[] = $x; } }
  return $m ? (int)round(array_sum($m) / count($m)) : null;
}
function dash_bucket(array $vals, array $edges, array $labels): array {   // edges ascending upper bounds, last label = above all
  $out = array_fill(0, count($labels), 0);
  foreach ($vals as $v) { $i = 0; while ($i < count($edges) && $v >= $edges[$i]) $i++; $out[$i]++; }
  return array_map(fn($l, $n) => [$l, $n], $labels, $out);
}

function dash_ops(PDO $d, string $from, string $to, array $B): array {
  $tj = $B['tj']; $now = tms_trk_now(); $q = $d->quote($from); $q2 = $d->quote($to);
  $st = $d->query("SELECT status, COUNT(*) FROM trips WHERE COALESCE(planned_date, created_at)::date BETWEEN $q AND $q2 GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
  $c = fn(string ...$k) => array_sum(array_map(fn($x) => (int)($st[$x] ?? 0), $k));
  $o = ['created' => array_sum($st), 'pending' => $c('Planned'), 'assigned' => $c('Assigned'), 'atSource' => $c('Reached at source'), 'loaded' => $c('Loaded', 'Gate Pass Created'), 'transit' => $c('In Transit', 'Breakdown', 'Accident'), 'delivered' => $c('Delivered'), 'cancelled' => $c('Cancelled')];
  $ids = array_map(fn($t) => (int)$t['id'], $tj); $hist = dash_hist($d, $ids);
  $o['stage'] = [['Created → accepted', dash_avg_between($hist, 'Planned', 'Assigned')], ['Accepted → at source', dash_avg_between($hist, 'Assigned', 'Reached at source')], ['At source → loaded', dash_avg_between($hist, 'Reached at source', 'Loaded')],
    ['Loaded → gate pass', dash_avg_between($hist, 'Loaded', 'Gate Pass Created')], ['Gate pass → in transit', dash_avg_between($hist, 'Gate Pass Created', 'In Transit')], ['In transit → delivered', dash_avg_between($hist, 'In Transit', 'Delivered')]];
  $o['acceptDist'] = dash_bucket(array_filter(array_column($tj, 'accMin'), fn($x) => $x !== null), [60, 240, 1440], ['< 1 h', '1 – 4 h', '4 – 24 h', '> 24 h']);
  $o['acceptDist'][] = ['Not accepted yet', count(array_filter($tj, fn($t) => !$t['acc']))];
  $ages = []; foreach ($tj as $t) if (!$t['acc']) $ages[] = ($now - strtotime((string)$t['created'])) / 86400;
  $o['pendingAge'] = dash_bucket($ages, [1, 2, 3], ['< 1 day', '1 – 2 days', '2 – 3 days', '> 3 days']);
  $o['cancelReasons'] = $d->query("SELECT COALESCE(NULLIF(BTRIM(cancel_reason),''),'(no reason given)') r, COUNT(*) n FROM trips WHERE status='Cancelled' AND COALESCE(planned_date, created_at)::date BETWEEN $q AND $q2 GROUP BY 1 ORDER BY 2 DESC LIMIT 8")->fetchAll(PDO::FETCH_NUM);
  $o['cancelByTpt'] = $d->query("SELECT COALESCE(NULLIF(BTRIM(tpt_name),''),'(not set)') r, COUNT(*) n FROM trips WHERE status='Cancelled' AND COALESCE(planned_date, created_at)::date BETWEEN $q AND $q2 GROUP BY 1 ORDER BY 2 DESC LIMIT 6")->fetchAll(PDO::FETCH_NUM);
  $wd = array_fill(0, 7, 0); $hr = array_fill(0, 24, 0); $dc = []; $vt = []; $sc = [1 => 0, 2 => 0, 3 => 0]; $maxPkt = max(1, (int)tms_trip_max_pkt());
  foreach ($tj as $t) {
    $ts = strtotime((string)$t['created']); $wd[(int)date('N', $ts) - 1]++; $hr[(int)date('G', $ts)]++;
    $k = $t['dc'] ?: '(not set)'; $dc[$k]['n'] = ($dc[$k]['n'] ?? 0) + 1; $dc[$k]['pkt'] = ($dc[$k]['pkt'] ?? 0) + $t['pkt'];
    $k = trim((string)$t['vtype']) ?: '(not set)'; $vt[$k]['n'] = ($vt[$k]['n'] ?? 0) + 1; $vt[$k]['pkt'] = ($vt[$k]['pkt'] ?? 0) + $t['pkt'];
    $n = min(3, max(1, count($t['stops']))); $sc[$n]++;
  }
  $o['weekday'] = array_map(null, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], $wd);
  $o['hours'] = $hr; $o['stopsPerTrip'] = [['1 store', $sc[1]], ['2 stores', $sc[2]], ['3 stores', $sc[3]]];
  $o['byDc'] = array_map(fn($k, $v) => [$k, $v['n'], (int)round($v['pkt'] / max(1, $v['n']))], array_keys($dc), $dc);
  $o['byVehicleType'] = array_map(fn($k, $v) => [$k, $v['n'], (int)round($v['pkt'] / max(1, $v['n'])), (int)round($v['pkt'] / max(1, $v['n']) * 100 / $maxPkt)], array_keys($vt), $vt);
  $o['avgPkt'] = $tj ? (int)round(array_sum(array_column($tj, 'pkt')) / count($tj)) : 0; $o['avgFill'] = (int)round($o['avgPkt'] * 100 / $maxPkt); $o['maxPkt'] = $maxPkt;
  $o['avgStops'] = $tj ? round(array_sum(array_map(fn($t) => count($t['stops']), $tj)) / count($tj), 1) : 0;
  // trips that sit in one status for too long: accepted but not loaded 12 h, loaded / gate pass 12 h, at source 8 h
  $lim = ['Assigned' => 24 * 60, 'Reached at source' => 8 * 60, 'Loaded' => 12 * 60, 'Gate Pass Created' => 12 * 60]; $slow = [];
  foreach ($tj as $t) { if (!isset($lim[$t['status']])) continue; $h = $hist[$t['id']] ?? null; $since = $h['t'][$t['status']] ?? ($h['last'] ?? $t['created']); $m = (int)floor(($now - strtotime((string)$since)) / 60); if ($m >= $lim[$t['status']]) $slow[] = ['id' => $t['id'], 'code' => $t['code'], 'status' => $t['status'], 'since' => $since, 'min' => $m, 'veh' => $t['veh'], 'dc' => $t['dc'], 'pkt' => $t['pkt']]; }
  usort($slow, fn($a, $b) => $b['min'] <=> $a['min']); $o['slow'] = array_slice($slow, 0, 10);
  $days = []; foreach ($d->query("SELECT created_at::date dt, COUNT(*) c, COUNT(*) FILTER (WHERE status='Cancelled') x FROM trips WHERE created_at::date BETWEEN ($q2::date - 13) AND $q2 GROUP BY 1")->fetchAll(PDO::FETCH_ASSOC) as $r) $days[$r['dt']] = $r;
  $o['daily'] = []; for ($i = 13; $i >= 0; $i--) { $k = date('Y-m-d', strtotime($to . " -$i days")); $o['daily'][] = ['d' => $k, 'c' => (int)($days[$k]['c'] ?? 0), 'x' => (int)($days[$k]['x'] ?? 0)]; }
  return $o;
}

function dash_transport(PDO $d, string $from, string $to, array $B): array {
  $tj = $B['tj']; $q = $d->quote($from); $q2 = $d->quote($to); $hist = dash_hist($d, array_map(fn($t) => (int)$t['id'], $tj)); $now = tms_trk_now();
  $canc = $d->query("SELECT COALESCE(NULLIF(BTRIM(tpt_name),''),'(not set)') r, COUNT(*) n FROM trips WHERE status='Cancelled' AND COALESCE(planned_date, created_at)::date BETWEEN $q AND $q2 GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
  $g = [];
  foreach ($tj as $t) {
    $k = trim((string)($t['tpt'] ?? '')); if ($k === '' || strtoupper($k) === 'UNASSIGNED') $k = '(not set)';
    $r = &$g[$k]; $r ??= ['trips' => 0, 'acc' => 0, 'accMin' => [], 'done' => 0, 'pkt' => 0, 'veh' => [], 'drv' => [], 'dStops' => 0, 'dLate' => 0, 'openDelayed' => 0, 'transit' => 0, 'hrs' => []];
    $r['trips']++; $r['pkt'] += $t['pkt']; if ($t['acc']) $r['acc']++; if ($t['accMin'] !== null) $r['accMin'][] = $t['accMin']; if ($t['veh']) $r['veh'][$t['veh']] = 1; if ($t['driver']) $r['drv'][$t['driver']] = 1;
    if (in_array($t['status'], ['In Transit', 'Breakdown', 'Accident'], true)) $r['transit']++;
    if ($t['status'] === 'Delivered') { $r['done']++; $h = $hist[$t['id']] ?? null; $a = $h['t']['In Transit'] ?? ($h['t']['Gate Pass Created'] ?? null); if ($a && $t['end']) { $m = (strtotime((string)$t['end']) - strtotime((string)$a)) / 3600; if ($m >= 0 && $m < 24 * 30) $r['hrs'][] = $m; } }
    foreach ($t['stops'] as $s) { if ($s['at']) { $r['dStops']++; if (!empty($s['dlate'])) $r['dLate']++; } elseif (!empty($s['late'])) $r['openDelayed']++; }
    unset($r);
  }
  foreach ($canc as $k => $n) { $g[$k]['cancelled'] = (int)$n; if (!isset($g[$k]['trips'])) $g[$k] += ['trips' => 0, 'acc' => 0, 'accMin' => [], 'done' => 0, 'pkt' => 0, 'veh' => [], 'drv' => [], 'dStops' => 0, 'dLate' => 0, 'openDelayed' => 0, 'transit' => 0, 'hrs' => []]; }
  $rows = [];
  foreach ($g as $k => $r) {
    $all = $r['trips'] + ($r['cancelled'] ?? 0); $accPct = $r['trips'] ? (int)round($r['acc'] * 100 / $r['trips']) : 0; $onTime = $r['dStops'] ? (int)round(($r['dStops'] - $r['dLate']) * 100 / $r['dStops']) : null; $cancPct = $all ? (int)round(($r['cancelled'] ?? 0) * 100 / $all) : 0;
    // score: 40% acceptance, 40% on-time delivery (counted as 100 until something is delivered), 20% not cancelled
    $score = (int)round($accPct * .4 + ($onTime ?? 100) * .4 + (100 - $cancPct) * .2);
    $rows[] = ['tpt' => $k, 'trips' => $r['trips'], 'cancelled' => $r['cancelled'] ?? 0, 'acc' => $r['acc'], 'accPct' => $accPct, 'accMin' => $r['accMin'] ? (int)round(array_sum($r['accMin']) / count($r['accMin'])) : null, 'done' => $r['done'], 'transit' => $r['transit'],
      'onTime' => $onTime, 'openDelayed' => $r['openDelayed'], 'pkt' => $r['pkt'], 'avgPkt' => $r['trips'] ? (int)round($r['pkt'] / $r['trips']) : 0, 'veh' => count($r['veh']), 'drv' => count($r['drv']), 'hrs' => $r['hrs'] ? round(array_sum($r['hrs']) / count($r['hrs']), 1) : null, 'cancPct' => $cancPct, 'score' => $score];
  }
  usort($rows, fn($a, $b) => [$b['trips'] > 0 ? 1 : 0, $b['score'], $b['trips']] <=> [$a['trips'] > 0 ? 1 : 0, $a['score'], $a['trips']]);
  // vehicles and drivers
  $vr = []; $dr = [];
  foreach ($tj as $t) {
    if ($t['veh']) { $r = &$vr[$t['veh']]; $r ??= ['trips' => 0, 'done' => 0, 'pkt' => 0, 'tpt' => $t['tpt'], 'last' => null]; $r['trips']++; $r['pkt'] += $t['pkt']; if ($t['status'] === 'Delivered') $r['done']++; $r['last'] = max((string)$r['last'], (string)$t['created']); unset($r); }
    if ($t['driver']) { $r = &$dr[$t['driver']]; $r ??= ['trips' => 0, 'done' => 0, 'pkt' => 0]; $r['trips']++; $r['pkt'] += $t['pkt']; if ($t['status'] === 'Delivered') $r['done']++; unset($r); }
  }
  uasort($vr, fn($a, $b) => $b['trips'] <=> $a['trips']); uasort($dr, fn($a, $b) => $b['trips'] <=> $a['trips']);
  return ['rows' => $rows, 'vehicles' => array_slice($vr, 0, 12, true), 'drivers' => array_slice($dr, 0, 12, true), 'nVeh' => count($vr), 'nDrv' => count($dr)];
}

function dash_transit(PDO $d, array $B): array {
  $tj = $B['tj']; $veh = $B['veh']; $now = tms_trk_now(); $hist = dash_hist($d, array_map(fn($t) => (int)$t['id'], $tj));
  $o = ['trips' => 0, 'stores' => 0, 'pkt' => 0, 'breakdown' => 0, 'veh' => ['moving' => 0, 'stopped' => 0, 'nosignal' => 0, 'unknown' => 0], 'rows' => [], 'ready' => [], 'byState' => [], 'byCluster' => []];
  $eta = ['Overdue' => 0, 'Next 4 hours' => 0, 'Later today' => 0, 'Tomorrow' => 0, 'After tomorrow' => 0, 'No ETA' => 0]; $today = date('Y-m-d', $now); $tomorrow = date('Y-m-d', $now + 86400); $speeds = []; $km = 0.0;
  foreach ($tj as $t) {
    $g = in_array($t['status'], ['In Transit', 'Breakdown', 'Accident'], true);
    $open = array_values(array_filter($t['stops'], fn($s) => !$s['at']));
    if ($g) {
      $o['trips']++; $o['stores'] += count($open); $o['pkt'] += array_sum(array_column($open, 'pkt')); if ($t['status'] !== 'In Transit') $o['breakdown']++;
      $v = $t['vk'] ? ($veh[$t['vk']] ?? null) : null;
      if (!$v) $o['veh']['unknown']++; elseif ($v['fresh'] === 'lost' || $v['state'] === 'NO SIGNAL') $o['veh']['nosignal']++; elseif ($v['state'] === 'RUNNING') { $o['veh']['moving']++; if ($v['speed'] !== null) $speeds[] = $v['speed']; } else $o['veh']['stopped']++;
      if ($v && $v['dayKm'] !== null) $km += (float)$v['dayKm'];
      foreach ($open as $s) {
        $e = $s['eta'] ? strtotime((string)$s['eta']) : null; $b = $e === null ? 'No ETA' : (!empty($s['late']) ? 'Overdue' : ($e <= $now + 4 * 3600 ? 'Next 4 hours' : (date('Y-m-d', $e) === $today ? 'Later today' : (date('Y-m-d', $e) === $tomorrow ? 'Tomorrow' : 'After tomorrow')))); $eta[$b]++;
        $sk = trim((string)$s['state']) ?: '(not set)'; $o['byState'][$sk] = ($o['byState'][$sk] ?? 0) + 1; $ck = trim((string)$s['cluster']) ?: '(not set)'; $o['byCluster'][$ck] = ($o['byCluster'][$ck] ?? 0) + 1;
      }
      $nx = $open[0] ?? null;
      $o['rows'][] = ['id' => $t['id'], 'code' => $t['code'], 'status' => $t['status'], 'veh' => $t['veh'], 'driver' => $t['driver'], 'state' => $v['state'] ?? null, 'speed' => $v['speed'] ?? null, 'age' => $v['age'] ?? null, 'fresh' => $v['fresh'] ?? null, 'dayKm' => $v['dayKm'] ?? null, 'halted' => $v['halted'] ?? null,
        'next' => $nx ? $nx['code'] : null, 'eta' => $nx['eta'] ?? null, 'late' => $nx ? !empty($nx['late']) : false, 'open' => count($open), 'pkt' => array_sum(array_column($open, 'pkt')), 'dc' => $t['dc'], 'delayed' => $t['delay']];
    } elseif ($t['acc'] && $t['status'] !== 'Delivered') {
      $h = $hist[$t['id']] ?? null; $since = $h['t'][$t['status']] ?? ($h['last'] ?? $t['created']);
      $o['ready'][] = ['id' => $t['id'], 'code' => $t['code'], 'status' => $t['status'], 'veh' => $t['veh'], 'driver' => $t['driver'], 'dc' => $t['dc'], 'stores' => count($t['stops']), 'pkt' => $t['pkt'], 'since' => $since, 'min' => max(0, (int)floor(($now - strtotime((string)$since)) / 60))];
    }
  }
  usort($o['rows'], fn($a, $b) => (int)$b['late'] <=> (int)$a['late']); usort($o['ready'], fn($a, $b) => $b['min'] <=> $a['min']);
  arsort($o['byState']); arsort($o['byCluster']); $o['byState'] = array_slice($o['byState'], 0, 8, true); $o['byCluster'] = array_slice($o['byCluster'], 0, 8, true);
  $o['eta'] = $eta; $o['avgSpeed'] = $speeds ? (int)round(array_sum($speeds) / count($speeds)) : null; $o['km'] = (int)round($km);
  return $o;
}

/**
 * PRD Pending data for the PRD tab, straight from the prd_pending table: exactly what the database holds as pending (nothing is taken off for trips or plans, no local
 * master is used: source, store, state, cluster, division and store status are the values that came with the Oracle data).
 * The page picks Division (all / APPS / GM), Source and Store status in the browser, so this returns the raw groups it needs:
 *   cells    [source, status, age group, then PKT, qty, value for all / APPS / GM]
 *   stores   [store, source, status, hasAPPS, hasGM]            (to count distinct stores for any selection)
 *   top      active stores older than 30 days, per source (PKT, qty, value and the four age buckets for all / APPS / GM)
 *   cluster, state   [name, status, source, PKT and value for all / APPS / GM]
 * Status is A (active), U (upcoming), O (inactive / other). Kept until the next PRD refresh.
 */
function dash_prd(PDO $d): array {
  require_once TMS_ROOT . '/app/services/prd_planner.php';
  $sid = (int)$d->query("SELECT COALESCE(MAX(id),0) FROM tms_sync_log WHERE kind='prd' AND ok")->fetchColumn(); $ck = 'dash_prd5';
  $row = $d->query("SELECT v, updated_at FROM tms_meta WHERE k=" . $d->quote($ck))->fetch(PDO::FETCH_ASSOC);
  if ($row && strtotime((string)$row['updated_at']) > time() - 6 * 3600 && ($j = json_decode((string)$row['v'], true)) && !empty($j['ok']) && ($j['syncId'] ?? -1) === $sid) return $j;
  $pk = prd_pkt_col('p'); $today = $d->quote(date('Y-m-d', tms_trk_now()));
  // the latest sync time is read once and used as a plain value (as a CTE join Postgres sometimes chose a plan that ran for minutes)
  $mx = $d->query("SELECT MAX(synced_at) FROM prd_pending WHERE source_system='ORACLE'")->fetchColumn();
  $base = "FROM prd_pending p WHERE p.source_system='ORACLE' AND p.synced_at >= " . $d->quote((string)$mx) . " AND UPPER(BTRIM(COALESCE(p.challan_status,'')))=" . $d->quote(prd_status_filter()) . " AND NULLIF(BTRIM(p.destination_name),'') IS NOT NULL";
  $age = "($today::date - p.challan_date::date)";
  $grp = "CASE WHEN p.challan_date IS NULL THEN 7 WHEN $age <= 7 THEN 0 WHEN $age <= 15 THEN 1 WHEN $age <= 30 THEN 2 WHEN $age <= 45 THEN 3 WHEN $age <= 60 THEN 4 WHEN $age <= 90 THEN 5 ELSE 6 END";
  $cls = "CASE WHEN UPPER(BTRIM(COALESCE(p.store_status,''))) LIKE 'ACTIVE%' THEN 'A' WHEN UPPER(BTRIM(COALESCE(p.store_status,''))) LIKE 'UPCOMING%' THEN 'U' ELSE 'O' END";
  $store = "lower(BTRIM(p.destination_name))"; $apps = "UPPER(BTRIM(COALESCE(p.sub_div,'')))='APPS'";
  $F = ['t' => '', 'a' => " FILTER (WHERE $apps)", 'g' => " FILTER (WHERE NOT ($apps))"];
  $pqv = []; $pv = []; foreach ($F as $m => $f) { $pqv[] = "COUNT(DISTINCT $pk)$f p_$m, COALESCE(SUM(p.qty)$f,0) q_$m, COALESCE(SUM(p.value)$f,0) v_$m"; $pv[] = "COUNT(DISTINCT $pk)$f p_$m, COALESCE(SUM(p.value)$f,0) v_$m"; } $pqv = implode(', ', $pqv); $pv = implode(', ', $pv);
  $nums = function (array $r, array $cols): array { $o = []; foreach ($cols as $c) $o[] = str_starts_with($c, 'p_') || str_starts_with($c, 'b') ? (int)$r[$c] : round((float)$r[$c], 2); return $o; };
  $c9 = ['p_t', 'q_t', 'v_t', 'p_a', 'q_a', 'v_a', 'p_g', 'q_g', 'v_g']; $c6 = ['p_t', 'v_t', 'p_a', 'v_a', 'p_g', 'v_g'];
  $labels = ['0 – 7 days', '8 – 15 days', '16 – 30 days', '31 – 45 days', '46 – 60 days', '61 – 90 days', '91 days & above', 'No challan date'];
  $sources = []; $cells = [];
  $rows = $d->query("SELECT p.source_site_code site, MAX(p.source_name) sname, $cls c, $grp g, $pqv $base GROUP BY 1, 3, 4 ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) { $site = (string)$r['site'] ?: '(none)'; if (!isset($sources[$site]) || $sources[$site] === '') $sources[$site] = trim((string)$r['sname']); }
  ksort($sources); $sidx = array_flip(array_keys($sources));
  foreach ($rows as $r) $cells[] = array_merge([$sidx[(string)$r['site'] ?: '(none)'], $r['c'], (int)$r['g']], $nums($r, $c9));
  // stores per source and status, with the divisions they hold (the page counts distinct stores for whatever is selected)
  $stores = []; $sk = [];
  foreach ($d->query("SELECT $store k, p.source_site_code site, $cls c, bool_or($apps) a, bool_or(NOT ($apps)) g $base GROUP BY 1, 2, 3")->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = $sk[$r['k']] ??= count($sk); $stores[] = [$k, $sidx[(string)$r['site'] ?: '(none)'], $r['c'], $r['a'] ? 1 : 0, $r['g'] ? 1 : 0]; }
  // active stores that hold challans older than 30 days, per source: PKT, qty, value and the PKT of each age bucket, for all / APPS / GM
  $bsel = []; foreach ($F as $m => $f) { $x = $m === 'a' ? " AND $apps" : ($m === 'g' ? " AND NOT ($apps)" : ''); foreach (['31 AND 45', '46 AND 60', '61 AND 90'] as $i => $b) $bsel[] = "COUNT(DISTINCT $pk) FILTER (WHERE $age BETWEEN $b$x) b{$i}_$m"; $bsel[] = "COUNT(DISTINCT $pk) FILTER (WHERE $age > 90$x) b3_$m"; } $bsel = implode(', ', $bsel);
  $cb = []; foreach (['t', 'a', 'g'] as $m) foreach ([0, 1, 2, 3] as $i) $cb[] = "b{$i}_$m";
  $top = [];
  foreach ($d->query("SELECT MAX(BTRIM(p.destination_name)) name, MAX(p.state) state, MAX(p.cluster_type) cluster, p.source_site_code site, $pqv, MAX($age) oldest, $bsel
       $base AND p.challan_date IS NOT NULL AND $age >= 31 AND ($cls) = 'A' GROUP BY $store, p.source_site_code")->fetchAll(PDO::FETCH_ASSOC) as $r)
    $top[] = array_merge([$r['name'], (string)$r['state'], (string)$r['cluster'], $sidx[(string)$r['site'] ?: '(none)']], $nums($r, $c9), $nums($r, $cb), [(int)$r['oldest']]);
  $split = function (string $col) use ($d, $base, $cls, $pv, $c6, $nums, $sidx) {
    $out = []; $key = "COALESCE(NULLIF(BTRIM(p.$col),''),'(not set)')";
    foreach ($d->query("SELECT $key k, $cls c, p.source_site_code site, $pv $base GROUP BY 1, 2, 3")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = array_merge([$r['k'], $r['c'], $sidx[(string)$r['site'] ?: '(none)']], $nums($r, $c6));
    return $out;
  };
  $oldest = $d->query("SELECT MIN(p.challan_date) $base")->fetchColumn(); $syn = $d->query("SELECT created_at FROM tms_sync_log WHERE kind='prd' AND ok ORDER BY id DESC LIMIT 1")->fetchColumn();
  $S = ['ok' => true, 'syncId' => $sid, 'labels' => $labels, 'sources' => array_map(fn($k, $v) => ['site' => (string)$k, 'name' => $v], array_keys($sources), $sources), 'cells' => $cells, 'stores' => $stores, 'top' => $top,
    'cluster' => $split('cluster_type'), 'state' => $split('state'), 'oldestAge' => $oldest ? max(0, (int)floor((strtotime(date('Y-m-d', tms_trk_now())) - strtotime((string)$oldest)) / 86400)) : null, 'sync' => $syn ?: null];
  try { $d->prepare("INSERT INTO tms_meta(k,v,updated_at) VALUES(?,?,CURRENT_TIMESTAMP) ON CONFLICT(k) DO UPDATE SET v=EXCLUDED.v,updated_at=CURRENT_TIMESTAMP")->execute([$ck, json_encode($S, JSON_INVALID_UTF8_SUBSTITUTE)]); } catch (Throwable $e) {}
  return $S;
}