<?php
declare(strict_types=1);
require_once TMS_ROOT . '/app/services/dispatch_service.php';
/*
 * PRD Pending · Vehicle Planning Engine
 * ------------------------------------------------------------------
 * Groups pending challans by Source + Destination store and produces a
 * vehicle plan for every store:
 *   FTL      - store alone fills a big vehicle (multi-vehicle split if needed)
 *   CLUB     - nearby stores of the same source clubbed into one load
 *   PTL      - single store, smaller vehicle, fill is healthy
 *   NOW      - fill is low but the store is overdue -> dispatch anyway
 *   HOLD     - fill is low and not urgent -> hold & consolidate
 *   UNMAPPED - destination not found in Store Master
 *   CLOSED   - store status says closed
 * PKT = COUNT(DISTINCT challan_no).
 * Read-only on prd_pending except the trip-link (planned_trip_id).
 */

function prd_cfg(): array {
  static $c = null;
  if ($c === null) {
    $c = [];
    $f = TMS_ROOT . '/config/prd_planning.php';
    if (is_file($f)) { $x = require $f; if (is_array($x)) $c = $x; }
    $c += [
      'max_stops' => 4, 'ftl_fill_pct' => 85, 'min_fill_pct' => 60, 'target_fill_pct' => 95,
      'club_radius_km' => 80, 'watch_days' => 2, 'critical_days' => 3, 'due_days' => 3, 'use_vehicle_master' => true,
      'fallback_vehicles' => [['type' => '32FT', 'capacity' => 300, 'value_cap' => 2500000]],
      'closed_status_words' => ['closed', 'inactive'],
      'max_rows' => 500000,
    ];
  }
  return $c;
}

/** Board shows only Oracle rows with the configured CHALLAN_STATUS (default PENDING). */
function prd_status_filter(): string {
  static $st = null;
  if ($st === null) { $st = 'PENDING'; try { $o = require TMS_ROOT . '/config/oracle.php'; if (!empty($o['challan_status'])) $st = strtoupper(trim((string)$o['challan_status'])); } catch (Throwable $e) {} }
  return $st;
}

/** PKT basis from config/oracle.php: CHALLAN_NO (default) or CHALLAN_BARCODE. */
function prd_pkt_basis(): string {
  static $b = null;
  if ($b === null) { $b = 'CHALLAN_NO'; try { $o = require TMS_ROOT . '/config/oracle.php'; if (strtoupper((string)($o['pkt_basis'] ?? '')) === 'CHALLAN_BARCODE') $b = 'CHALLAN_BARCODE'; } catch (Throwable $e) {} }
  return $b;
}
/** PKT = distinct challans (the report's TOTAL_PKT). APPS / GM PKT = challans that contain that division. */
function prd_pkt_col(string $a = 'p'): string { return prd_pkt_basis() === 'CHALLAN_BARCODE' ? "NULLIF(BTRIM($a.challan_barcode),'')" : "NULLIF(BTRIM($a.challan_no),'')"; }

/** Stable group key (same expression in SQL and PHP). */
function prd_key_sql(string $a = 'p'): string {
  return "md5(COALESCE($a.source_site_code,'')||'|'||lower(BTRIM(COALESCE($a.source_name,'')))||'|'||lower(BTRIM(COALESCE($a.destination_name,''))))";
}
/** A row is pending if it is not linked to a live (non-cancelled, existing) trip. */
function prd_pending_where(string $a = 'p', bool $ignorePlan = false): string {
  $trip = "($a.planned_trip_id IS NULL OR NOT EXISTS (SELECT 1 FROM trips tq WHERE tq.id=$a.planned_trip_id AND COALESCE(tq.status,'')<>'Cancelled'))";
  if ($ignorePlan) return $trip;
  // also not reserved by a pending plan line
  return "($trip AND ($a.plan_line_id IS NULL OR NOT EXISTS (SELECT 1 FROM tms_plan_lines pl0 WHERE pl0.id=$a.plan_line_id AND pl0.status='Pending')))";
}
/** SQL: the row belongs to a trip that is not cancelled. */
function prd_on_trip_sql(string $a = 'p'): string {
  return "($a.planned_trip_id IS NOT NULL AND EXISTS (SELECT 1 FROM trips tq WHERE tq.id=$a.planned_trip_id AND COALESCE(tq.status,'')<>'Cancelled'))";
}
/** SQL: the row is reserved by a pending plan line and is not on a trip yet. */
function prd_in_plan_sql(string $a = 'p'): string {
  return "(NOT " . prd_on_trip_sql($a) . " AND $a.plan_line_id IS NOT NULL AND EXISTS (SELECT 1 FROM tms_plan_lines pl0 WHERE pl0.id=$a.plan_line_id AND pl0.status='Pending'))";
}

/** Unicode-safe cut (falls back if mbstring is not enabled). */
function prd_cut(string $s, int $n): string { return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }

function prd_km(?array $a, ?array $b): ?float {
  if (!$a || !$b) return null;
  $la1 = (float)($a['lat'] ?? 0); $lo1 = (float)($a['lng'] ?? 0);
  $la2 = (float)($b['lat'] ?? 0); $lo2 = (float)($b['lng'] ?? 0);
  if ((!$la1 && !$lo1) || (!$la2 && !$lo2)) return null;
  $r = M_PI / 180; $dLa = ($la2 - $la1) * $r; $dLo = ($lo2 - $lo1) * $r;
  $h = sin($dLa / 2) ** 2 + cos($la1 * $r) * cos($la2 * $r) * sin($dLo / 2) ** 2;
  return 2 * 6371 * asin(min(1, sqrt($h)));
}

/** Best stop order from origin (exact for <=6 stops, nearest-neighbour beyond). $pts: key => ['lat','lng']|null */
function prd_best_order(?array $origin, array $pts): array {
  $keys = array_keys($pts);
  if (count($keys) < 2) return ['order' => $keys, 'km' => ($origin && $keys ? prd_km($origin, $pts[$keys[0]]) : null)];
  foreach ($pts as $p) if (!$p) return ['order' => $keys, 'km' => null];
  $cost = function (array $ord) use ($origin, $pts): float { $t = 0.0; $cur = $origin ?? $pts[$ord[0]]; foreach ($ord as $k) { $t += prd_km($cur, $pts[$k]) ?? 0; $cur = $pts[$k]; } return $t; };
  $best = null; $bestKm = INF;
  if (count($keys) <= 6) {
    $perm = function (array $items) use (&$perm) { if (count($items) <= 1) return [$items]; $o = []; foreach ($items as $i => $x) { $r = $items; unset($r[$i]); foreach ($perm(array_values($r)) as $p) $o[] = array_merge([$x], $p); } return $o; };
    foreach ($perm($keys) as $ord) { $c = $cost($ord); if ($c < $bestKm) { $bestKm = $c; $best = $ord; } }
  } else {
    $rem = $keys; $best = []; $cur = $origin ?? $pts[$keys[0]];
    while ($rem) { $bi = 0; $bd = INF; foreach ($rem as $i => $k) { $dk = prd_km($cur, $pts[$k]) ?? INF; if ($dk < $bd) { $bd = $dk; $bi = $i; } } $best[] = $rem[$bi]; $cur = $pts[$rem[$bi]]; array_splice($rem, $bi, 1); }
    $bestKm = $cost($best);
  }
  return ['order' => $best, 'km' => $origin ? $bestKm : null];
}

function prd_safe_rows(PDO $d, string $sql, array $p = []): array {
  try { $q = $d->prepare($sql); $q->execute($p); return $q->fetchAll(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return []; }
}

/** Old builds created indents named PRD-<row id> without linking rows; link them once. */
function prd_backfill_legacy_links(PDO $d): void {
  try {
    $d->exec("UPDATE prd_pending p SET planned_trip_id=i.trip_id, planned_at=COALESCE(p.planned_at,CURRENT_TIMESTAMP)
              FROM tms_indents i WHERE i.indent_no='PRD-'||p.id::text AND i.trip_id IS NOT NULL AND p.planned_trip_id IS NULL");
  } catch (Throwable $e) {}
}

function prd_vehicle_classes(PDO $d): array {
  $cfg = prd_cfg(); $out = [];
  if (!empty($cfg['use_vehicle_master'])) {
    $rows = prd_safe_rows($d, "SELECT COALESCE(NULLIF(BTRIM(vehicle_type),''),'Vehicle') vt, MAX(COALESCE(capacity_boxes,0)) cap,
        MAX(COALESCE(value_capacity,0)) vcap, COUNT(*) total,
        SUM(CASE WHEN lower(COALESCE(status,'available'))='available' THEN 1 ELSE 0 END) avail
      FROM vehicles WHERE COALESCE(active,TRUE) GROUP BY 1 HAVING MAX(COALESCE(capacity_boxes,0))>0");
    foreach ($rows as $r) $out[] = ['type' => (string)$r['vt'], 'capacity' => (int)$r['cap'], 'value_cap' => (float)$r['vcap'], 'total' => (int)$r['total'], 'available' => (int)$r['avail'], 'from_master' => true];
  }
  if (!$out) foreach ((array)$cfg['fallback_vehicles'] as $v) $out[] = ['type' => (string)$v['type'], 'capacity' => (int)$v['capacity'], 'value_cap' => (float)($v['value_cap'] ?? 0), 'total' => null, 'available' => null, 'from_master' => false];
  // A trip can never carry more than the business limit, whatever the vehicle size
  $lim = function_exists('tms_trip_max_pkt') ? tms_trip_max_pkt() : 300;
  foreach ($out as &$c) { $c['raw_capacity'] = $c['capacity']; $c['capacity'] = min($c['capacity'], $lim); } unset($c);
  usort($out, fn($a, $b) => $a['capacity'] <=> $b['capacity'] ?: $a['value_cap'] <=> $b['value_cap']);
  return $out;
}

/** Smallest vehicle that fits pkt (prefers one that also fits value). */
function prd_pick_vehicle(array $classes, int $pkt, float $value): ?array {
  $fit = null;
  foreach ($classes as $c) {
    if ($c['capacity'] < $pkt) continue;
    if ($fit === null) $fit = $c;
    if ($c['value_cap'] <= 0 || $c['value_cap'] >= $value) return $c;
  }
  return $fit;
}

function prd_next_dispatch_day(?string $days): array {
  $all = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  $set = array_values(array_intersect($all, array_map(fn($x) => ucfirst(strtolower(substr(trim($x), 0, 3))), explode(',', (string)$days))));
  if (!$set || count($set) === 7) return ['today' => true, 'next' => null];
  $today = date('D');
  if (in_array($today, $set, true)) return ['today' => true, 'next' => null];
  $i = array_search($today, $all, true);
  for ($k = 1; $k <= 7; $k++) { $n = $all[($i + $k) % 7]; if (in_array($n, $set, true)) return ['today' => false, 'next' => $n]; }
  return ['today' => true, 'next' => null];
}

/** Load pending challan lines and aggregate them into Source + Destination groups. */
/** Forget the per-request cache of the full pending list (call after anything that plans, holds or syncs challans). */
function prd_groups_cache_clear(): void { prd_load_groups_cache(null, true); }
/** The full (unfiltered) list is loaded once per request: the matrix builds a "separate" and a "joint" board, and loading it twice doubled memory (about 212 MB each for 85k rows). */
function prd_load_groups_cache(?array $set, bool $clear = false): ?array {
  static $c = null; if ($clear) { $c = null; return null; } if ($set !== null) $c = $set; return $c;
}
/**
 * $planLineIds: load exactly the challans reserved for these plan lines (still pending in the last sync, not on a trip). Used to turn plans into trips.
 * Without it, challans reserved by a pending plan are not loaded: they are no longer available on the board.
 */
function prd_load_groups(PDO $d, ?array $onlyKeys = null, ?array $planLineIds = null): array {
  if ($planLineIds !== null) return prd_load_groups_db($d, $onlyKeys, $planLineIds);
  if ($onlyKeys === null && ($hit = prd_load_groups_cache(null)) !== null) return $hit;
  $g = prd_load_groups_db($d, $onlyKeys);
  if ($onlyKeys === null) prd_load_groups_cache($g);
  return $g;
}
function prd_load_groups_db(PDO $d, ?array $onlyKeys = null, ?array $planLineIds = null): array {
  $k = prd_key_sql('p'); $params = [];
  $where = prd_pending_where('p', $planLineIds !== null);
  if ($planLineIds !== null) {
    $lids = array_values(array_unique(array_map('intval', $planLineIds))); if (!$lids) return [];
    $where .= " AND p.plan_line_id IN (" . implode(',', $lids) . ") AND p.synced_at >= (SELECT MAX(x.synced_at) FROM prd_pending x WHERE x.source_system='ORACLE')";
  }
  $sql = "SELECT $k AS gkey, p.id, p.source_site_code, p.source_name, p.destination_name, p.state, p.cluster_type, p.store_status, p.sub_div,
            p.challan_no, p.challan_barcode, p.challan_date, p.qty, p.value, p.challan_status, p.last_dispatch_date, p.source_system, p.synced_at, p.destination_site_code
          FROM prd_pending p WHERE $where AND NULLIF(BTRIM(p.destination_name),'') IS NOT NULL
            AND p.source_system='ORACLE' AND UPPER(BTRIM(COALESCE(p.challan_status,'')))=?";
  if ($onlyKeys !== null) {
    $onlyKeys = array_values(array_filter($onlyKeys, fn($x) => preg_match('/^[a-f0-9]{32}$/', (string)$x)));
    if (!$onlyKeys) return [];
    $sql .= " AND $k IN (" . implode(',', array_fill(0, count($onlyKeys), '?')) . ")"; $params = $onlyKeys;
  }
  array_unshift($params, prd_status_filter());
  // Safety valve only (the old fixed 50,000 cut stores off the board: A..MCN showed, the rest vanished). If it is ever reached, the board says so.
  $maxRows = max(1000, (int)(prd_cfg()['max_rows'] ?? 500000));
  $GLOBALS['prd_truncated'] = false; $nRows = 0;
  $sql .= " ORDER BY p.destination_name COLLATE \"C\", p.challan_no COLLATE \"C\" LIMIT " . ($maxRows + 1); // "C" = byte order: much faster than the locale sort on 90k rows (the board re-sorts by priority anyway)
  $q = $d->prepare($sql); $q->execute($params);
  $g = [];
  while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    if (++$nRows > $maxRows) { $GLOBALS['prd_truncated'] = $maxRows; break; }
    $key = $r['gkey'];
    if (!isset($g[$key])) {
      $g[$key] = ['gkey' => $key, 'source_site_code' => (string)$r['source_site_code'], 'source_name' => trim((string)$r['source_name']),
        'destination_name' => trim((string)$r['destination_name']), 'state' => trim((string)$r['state']), 'cluster_type' => trim((string)$r['cluster_type']),
        'store_status' => trim((string)$r['store_status']), 'dest_site' => trim((string)$r['destination_site_code']), 'ids' => [], 'challans' => [], 'qty' => 0.0, 'value' => 0.0, 'sub_divs' => [],
        'oldest_challan' => null, 'last_dispatch_src' => null, 'synced_at' => null, 'systems' => [], 'pkt_keys' => []];
    }
    $x = &$g[$key];
    $x['ids'][] = (int)$r['id'];
    $x['qty'] += (float)$r['qty']; $x['value'] += (float)$r['value'];
    if ($x['dest_site'] === '' && trim((string)$r['destination_site_code']) !== '') $x['dest_site'] = trim((string)$r['destination_site_code']);
    foreach (['state', 'cluster_type', 'store_status', 'source_name'] as $f) if ($x[$f] === '' && trim((string)$r[$f]) !== '') $x[$f] = trim((string)$r[$f]);
    foreach (explode(',', (string)$r['sub_div']) as $sd) if (trim($sd) !== '') $x['sub_divs'][trim($sd)] = true;
    $x['systems'][(string)$r['source_system']] = true;
    $base = trim((string)(prd_pkt_basis() === 'CHALLAN_BARCODE' ? $r['challan_barcode'] : $r['challan_no']));
    $div = strtoupper(trim((string)$r['sub_div'])) ?: 'GM';
    $x['div_value'][$div] = ($x['div_value'][$div] ?? 0) + (float)$r['value'];
    $pk = $base;
    if ($pk !== '') {
      if (!isset($x['pkt_keys'][$pk])) $x['pkt_keys'][$pk] = ['ids' => [], 'qty' => 0.0, 'value' => 0.0, 'date' => $r['challan_date'], 'challans' => [], 'divs' => []];
      $x['pkt_keys'][$pk]['divs'][$div] = true;
      $u = &$x['pkt_keys'][$pk];
      $u['ids'][] = (int)$r['id']; $u['qty'] += (float)$r['qty']; $u['value'] += (float)$r['value'];
      if ($r['challan_date'] && (!$u['date'] || $r['challan_date'] < $u['date'])) $u['date'] = $r['challan_date'];
      if (trim((string)$r['challan_no']) !== '') $u['challans'][trim((string)$r['challan_no'])] = true;
      unset($u);
    }
    $cn = trim((string)$r['challan_no']);
    if ($cn !== '') {
      if (!isset($x['challans'][$cn])) $x['challans'][$cn] = ['no' => $cn, 'barcode' => [], 'date' => $r['challan_date'], 'qty' => 0.0, 'value' => 0.0, 'div' => [], 'status' => $r['challan_status']];
      $c = &$x['challans'][$cn];
      $c['qty'] += (float)$r['qty']; $c['value'] += (float)$r['value'];
      if ($r['challan_barcode']) $c['barcode'][$r['challan_barcode']] = true;
      foreach (explode(',', (string)$r['sub_div']) as $sd) if (trim($sd) !== '') $c['div'][trim($sd)] = true;
      unset($c);
    }
    if ($r['challan_date'] && (!$x['oldest_challan'] || $r['challan_date'] < $x['oldest_challan'])) $x['oldest_challan'] = $r['challan_date'];
    if ($r['last_dispatch_date'] && (!$x['last_dispatch_src'] || $r['last_dispatch_date'] > $x['last_dispatch_src'])) $x['last_dispatch_src'] = $r['last_dispatch_date'];
    if ($r['synced_at'] && (!$x['synced_at'] || $r['synced_at'] > $x['synced_at'])) $x['synced_at'] = $r['synced_at'];
    unset($x);
  }
  foreach ($g as &$x) {
    // units: one per PKT (challan or barcode), oldest first, so a store can be split FIFO
    uasort($x['pkt_keys'], fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
    $x['units'] = $x['pkt_keys']; unset($x['pkt_keys']); $x['pkt'] = count($x['units']);
    $x['div_pkt'] = []; foreach ($x['units'] as $u) foreach (array_keys($u['divs']) as $dv) $x['div_pkt'][$dv] = ($x['div_pkt'][$dv] ?? 0) + 1;
    $x['div_value'] = $x['div_value'] ?? []; $x['sub_divs'] = array_keys($x['sub_divs']); $x['systems'] = array_keys($x['systems']); }
  unset($x);
  return $g;
}

/** Store, DC, last-dispatch and urgency enrichment. */
function prd_enrich(PDO $d, array $groups): array {
  $cfg = prd_cfg();
  $stores = prd_safe_rows($d, "SELECT id,store_code,store_name,latitude,longitude,dispatch_days,transit_days,city,state,oracle_site_code,cluster_type,store_status,active FROM stores WHERE COALESCE(active,TRUE) OR oracle_site_code IS NOT NULL");
  if (!$stores) $stores = prd_safe_rows($d, "SELECT id,store_code,store_name,latitude,longitude,dispatch_days,transit_days,city,state FROM stores WHERE COALESCE(active,TRUE)");
  $byCode = []; $byName = []; $bySite = [];
  foreach ($stores as $s) { if (!empty($s['oracle_site_code'])) $bySite[(string)$s['oracle_site_code']] = $s; $byCode[strtolower(trim((string)$s['store_code']))] = $s; $n = strtolower(trim((string)$s['store_name'])); if ($n !== '' && !isset($byName[$n])) $byName[$n] = $s; }
  $dcs = prd_safe_rows($d, "SELECT id,dc_code,dc_name,latitude,longitude,oracle_site_code FROM tms_dc_master WHERE COALESCE(active,TRUE)");
  if (!$dcs) $dcs = prd_safe_rows($d, "SELECT id,dc_code,dc_name,latitude,longitude FROM tms_dc_master WHERE COALESCE(active,TRUE)");
  $dcBySite = []; foreach ($dcs as $dc) { $sc = strtolower(trim((string)($dc['oracle_site_code'] ?? ''))); if ($sc !== '') $dcBySite[$sc] = $dc; }
  $dcIdx = [];
  foreach ($dcs as $dc) { foreach ([$dc['dc_code'], $dc['dc_name']] as $nm) { $nm = strtolower(trim((string)$nm)); if ($nm !== '') $dcIdx[$nm] = $dc; } }

  // Last dispatch per store: dispatched trips first, then indents as fallback.
  $lastTrip = [];
  foreach (prd_safe_rows($d, "SELECT ts.store_id, MAX(COALESCE(t.dispatched_at::date, t.dispatch_date, t.planned_date)) dt FROM tms_trip_stops ts JOIN trips t ON t.id=ts.trip_id
       WHERE t.status IN ('In Transit','Delivered') GROUP BY ts.store_id") as $r) $lastTrip[(int)$r['store_id']] = $r['dt'];
  $lastIndent = [];
  foreach (prd_safe_rows($d, "SELECT i.destination_store_id sid, MAX(i.indent_date) dt FROM tms_indents i LEFT JOIN trips t ON t.id=i.trip_id
       WHERE lower(COALESCE(i.status,'')) IN ('dispatched','in transit','delivered','completed') AND COALESCE(t.status,'')<>'Cancelled' GROUP BY 1") as $r) $lastIndent[(int)$r['sid']] = $r['dt'];

  $notes = [];
  foreach (prd_safe_rows($d, "SELECT gkey,note,updated_at FROM prd_planner_notes") as $r) $notes[$r['gkey']] = $r;

  $closedWords = array_map('strtolower', (array)$cfg['closed_status_words']);
  $today = strtotime(date('Y-m-d'));
  foreach ($groups as &$g) {
    $dn = strtolower($g['destination_name']);
    $st = ($g['dest_site'] !== '' ? ($bySite[$g['dest_site']] ?? null) : null) ?? $byCode[$dn] ?? $byName[$dn] ?? null;
    $g['store'] = $st ? ['id' => (int)$st['id'], 'code' => $st['store_code'], 'name' => $st['store_name'], 'city' => $st['city'], 'transit_days' => $st['transit_days'], 'dispatch_days' => $st['dispatch_days']] : null;
    $g['geo'] = ($st && ((float)$st['latitude'] || (float)$st['longitude'])) ? ['lat' => (float)$st['latitude'], 'lng' => (float)$st['longitude']] : null;
    if ($g['state'] === '' && $st && $st['state']) $g['state'] = (string)$st['state'];
    if ($g['cluster_type'] === '' && $st && !empty($st['cluster_type'])) $g['cluster_type'] = (string)$st['cluster_type'];

    $dc = $dcBySite[strtolower($g['source_site_code'])] ?? $dcIdx[strtolower($g['source_site_code'])] ?? $dcIdx[strtolower($g['source_name'])] ?? null;
    $g['dc'] = $dc ? ['id' => (int)$dc['id'], 'code' => $dc['dc_code'], 'name' => $dc['dc_name']] : null;
    $g['dc_geo'] = ($dc && ((float)$dc['latitude'] || (float)$dc['longitude'])) ? ['lat' => (float)$dc['latitude'], 'lng' => (float)$dc['longitude']] : null;
    $g['dc_km'] = prd_km($g['dc_geo'], $g['geo']);

    // Oracle last invoice date (report LAST_DISP_DATE) first; TMS dispatched trips only when Oracle has none
    $last = $g['last_dispatch_src'] ?: null; $srcLbl = $last ? 'oracle' : null;
    if (!$last && $st && !empty($lastTrip[(int)$st['id']])) { $last = $lastTrip[(int)$st['id']]; $srcLbl = 'trip'; }
    if (!$last && $st && !empty($lastIndent[(int)$st['id']])) { $last = $lastIndent[(int)$st['id']]; $srcLbl = 'indent'; }
    $g['last_dispatch'] = $last; $g['last_dispatch_basis'] = $srcLbl;
    $g['gap_days'] = $last ? max(0, (int)floor(($today - strtotime((string)$last)) / 86400)) : null;
    $g['age_days'] = $g['oldest_challan'] ? max(0, (int)floor(($today - strtotime((string)$g['oldest_challan'])) / 86400)) : null;
    $wait = $g['gap_days'] ?? $g['age_days'];
    $g['urgency'] = $wait === null ? 'unknown' : ($wait >= (int)$cfg['critical_days'] ? 'critical' : ($wait >= (int)$cfg['watch_days'] ? 'watch' : 'ok'));
    $g['closed'] = false;
    $masterStatus = $st ? trim((string)($st['store_status'] ?? '')) : '';
    foreach ($closedWords as $w) if ($w !== '' && (str_contains(strtolower($g['store_status']), $w) || str_contains(strtolower($masterStatus), $w))) $g['closed'] = true;
    if ($st && array_key_exists('active', $st) && !$st['active']) $g['closed'] = true;
    if ($g['closed'] && $masterStatus !== '' && !array_filter($closedWords, fn($w) => $w !== '' && str_contains(strtolower($g['store_status']), $w))) $g['store_status'] = $masterStatus . ' (Store Master)';
    if ($g['closed'] && $g['store_status'] === '') $g['store_status'] = 'Inactive in Store Master';
    $g['dispatch_day'] = prd_next_dispatch_day($g['store']['dispatch_days'] ?? null);
    $g['note'] = $notes[$g['gkey']]['note'] ?? '';
    $g['note_at'] = $notes[$g['gkey']]['updated_at'] ?? null;
    $g['source_label'] = $g['source_name'] !== '' ? $g['source_name'] : ($g['source_site_code'] ?: 'Unknown source');
  }
  unset($g);
  return $groups;
}

/** Build the full board: enriched groups + load plans + vehicle demand. */
/**
 * Joint view: one row per destination store across both warehouses.
 * Units are prefixed with their source group key ("gkey|pkt") so a trip can still take exact challans per DC.
 */
function prd_merge_joint(array $groups): array {
  $out = [];
  $rank = ['unknown' => 0, 'ok' => 1, 'watch' => 2, 'critical' => 3];
  foreach ($groups as $k => $g) {
    $jk = md5('JOINT|' . strtolower(trim($g['destination_name'])));
    $units = []; foreach ($g['units'] as $u => $x) $units[$k . '|' . $u] = $x;
    $part = ['gkey' => $k, 'pkt' => $g['pkt'], 'value' => $g['value'], 'source' => $g['source_label'], 'site' => $g['source_site_code'], 'dc' => $g['dc'], 'dc_geo' => $g['dc_geo']];
    if (!isset($out[$jk])) { $m = $g; $m['gkey'] = $jk; $m['units'] = $units; $m['parts'] = [$k => $part]; $out[$jk] = $m; continue; }
    $m = &$out[$jk];
    $m['units'] += $units; $m['parts'][$k] = $part; $m['ids'] = array_merge($m['ids'], $g['ids']); $m['challans'] += $g['challans'];
    $m['pkt'] += $g['pkt']; $m['qty'] += $g['qty']; $m['value'] += $g['value'];
    foreach ($g['div_pkt'] as $dv => $n) $m['div_pkt'][$dv] = ($m['div_pkt'][$dv] ?? 0) + $n;
    foreach ($g['div_value'] as $dv => $n) $m['div_value'][$dv] = ($m['div_value'][$dv] ?? 0) + $n;
    $m['sub_divs'] = array_values(array_unique(array_merge($m['sub_divs'], $g['sub_divs'])));
    if ($g['oldest_challan'] && (!$m['oldest_challan'] || $g['oldest_challan'] < $m['oldest_challan'])) $m['oldest_challan'] = $g['oldest_challan'];
    if ($g['last_dispatch'] && (!$m['last_dispatch'] || $g['last_dispatch'] > $m['last_dispatch'])) { $m['last_dispatch'] = $g['last_dispatch']; $m['gap_days'] = $g['gap_days']; $m['last_dispatch_basis'] = $g['last_dispatch_basis']; }
    $m['age_days'] = max((int)$m['age_days'], (int)$g['age_days']);
    if ($rank[$g['urgency']] > $rank[$m['urgency']]) $m['urgency'] = $g['urgency'];
    $m['closed'] = $m['closed'] || $g['closed'];
    if (!$m['store'] && $g['store']) { $m['store'] = $g['store']; $m['geo'] = $g['geo']; }
    if ($m['note'] === '' && $g['note'] !== '') $m['note'] = $g['note'];
    unset($m);
  }
  foreach ($out as &$m) {
    $m['source_label'] = implode(' + ', array_unique(array_column($m['parts'], 'source')));
    $m['source_site_code'] = implode('+', array_unique(array_column($m['parts'], 'site')));
    $m['joint'] = count($m['parts']) > 1;
  } unset($m);
  return $out;
}

/** Warehouse view options from the request: mode separate|joint, optional single site. */
function prd_view_opts(array $q): array {
  $wh = (string)($q['wh'] ?? 'all');
  if ($wh === 'joint') return ['mode' => 'joint', 'site' => null, 'wh' => 'joint'];
  if ($wh !== 'all' && preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $wh)) return ['mode' => 'separate', 'site' => $wh, 'wh' => $wh];
  return ['mode' => 'separate', 'site' => null, 'wh' => 'all'];
}

/** Holds, due flag and the warehouse view applied to enriched groups. */
function prd_prepare_groups(PDO $d, array $groups, array $opt): array {
  $cfg = prd_cfg();
  if (!empty($opt['site'])) $groups = array_filter($groups, fn($g) => (string)$g['source_site_code'] === (string)$opt['site']);
  if (($opt['mode'] ?? 'separate') === 'joint') $groups = prd_merge_joint($groups);
  else foreach ($groups as $k => &$g) { $g['parts'] = [$k => ['gkey' => $k, 'pkt' => $g['pkt'], 'value' => $g['value'], 'source' => $g['source_label'], 'site' => $g['source_site_code'], 'dc' => $g['dc'], 'dc_geo' => $g['dc_geo']]]; $g['joint'] = false; } unset($g);
  $holds = prd_active_holds($d);
  foreach ($groups as &$g) {
    $g['hold'] = $holds[prd_store_key($g['destination_name'])] ?? null;
    $wait = $g['gap_days'] ?? $g['age_days'];
    $g['wait_days'] = $wait;
    $g['due'] = $wait !== null && $wait >= (int)$cfg['due_days'];
    if ($g['due']) $g['urgency'] = 'critical';   // a due store is always urgent, so low fill still means "Dispatch now"
  } unset($g);
  return $groups;
}

function prd_build_board(PDO $d, array $opt = []): array {
  $cfg = prd_cfg();
  prd_backfill_legacy_links($d);
  $opt += ['mode' => 'separate', 'site' => null, 'wh' => 'all'];
  $groups = prd_prepare_groups($d, prd_enrich($d, prd_load_groups($d)), $opt);
  $classes = prd_vehicle_classes($d);
  $maxCap = min($classes ? max(array_column($classes, 'capacity')) : 300, function_exists('tms_trip_max_pkt') ? tms_trip_max_pkt() : 300);
  $ftlPkt = (int)ceil($maxCap * (float)$cfg['ftl_fill_pct'] / 100);
  $target = $maxCap * (float)$cfg['target_fill_pct'] / 100;
  $minFill = (float)$cfg['min_fill_pct'];
  $radius = (float)$cfg['club_radius_km'];
  $maxStops = max(1, min((int)$cfg['max_stops'], function_exists('tms_trip_max_stores') ? tms_trip_max_stores() : 2));
  $plans = []; $seq = 0;
  $newPlan = function (string $type, array $keys, array $vehicles, int $pkt, float $value, array $extra = []) use (&$plans, &$seq) {
    $id = 'L' . str_pad((string)(++$seq), 2, '0', STR_PAD_LEFT);
    $plans[$id] = ['id' => $id, 'type' => $type, 'keys' => $keys, 'vehicles' => $vehicles, 'pkt' => $pkt, 'value' => $value] + $extra;
    return $id;
  };
  $prio = function (array $g) use ($maxCap, $cfg): float {
    $wait = $g['gap_days'] ?? $g['age_days'] ?? 0;
    return min($g['pkt'] / max(1, $maxCap), 1.5) * 40 + min($wait / max(1, (int)$cfg['critical_days']), 2) * 35 + ($g['urgency'] === 'critical' ? 25 : 0);
  };

  $bySource = [];
  foreach ($groups as $k => $g) $bySource[$opt['mode'] === 'joint' ? 'JOINT' : $g['source_site_code'] . '|' . strtolower($g['source_label'])][] = $k;

  foreach ($bySource as $keys) {
    $pool = [];
    foreach ($keys as $k) {
      $g = &$groups[$k];
      $g['priority'] = round($prio($g), 1);
      if ($g['hold']) { $g['plan_id'] = $newPlan('HELD', [$k], [], $g['pkt'], $g['value']); unset($g); continue; }
      if ($g['closed']) { $g['plan_id'] = $newPlan('CLOSED', [$k], [], $g['pkt'], $g['value']); unset($g); continue; }
      if (!$g['store']) { $g['plan_id'] = $newPlan('UNMAPPED', [$k], [], $g['pkt'], $g['value']); unset($g); continue; }
      if ($g['pkt'] >= $ftlPkt) {
        // FTL: as many full vehicles as needed, remainder on the right-sized vehicle
        $full = intdiv($g['pkt'], $maxCap); $rem = $g['pkt'] - $full * $maxCap; $veh = [];
        $big = end($classes);
        if ($full) $veh[] = ['type' => $big['type'], 'count' => $full, 'capacity' => $big['capacity']];
        if ($rem > 0) { $v = $rem >= $ftlPkt ? $big : (prd_pick_vehicle($classes, $rem, 0) ?? $big); $veh[] = ['type' => $v['type'], 'count' => 1, 'capacity' => $v['capacity']]; }
        $capSum = array_sum(array_map(fn($v) => $v['capacity'] * $v['count'], $veh));
        $g['plan_id'] = $newPlan('FTL', [$k], $veh, $g['pkt'], $g['value'], ['fill' => $capSum ? round($g['pkt'] * 100 / $capSum) : 0, 'route_km' => $g['dc_km'], 'max_leg' => null]);
        unset($g); continue;
      }
      $pool[$k] = $g['priority'];
      unset($g);
    }
    arsort($pool);
    $left = $pool;
    while ($left) {
      $seed = array_key_first($left); unset($left[$seed]);
      $members = [$seed]; $load = $groups[$seed]['pkt']; $val = $groups[$seed]['value']; $geoMissing = !$groups[$seed]['geo'];
      while (count($members) < $maxStops && $load < $target && $left) {
        $best = null; $bestScore = INF;
        foreach (array_keys($left) as $c) {
          $cg = $groups[$c];
          if ($load + $cg['pkt'] > $maxCap) continue;
          if ($groups[$seed]['geo'] && $cg['geo']) {
            $dmin = INF; foreach ($members as $m) { $dk = prd_km($groups[$m]['geo'], $cg['geo']); if ($dk !== null) $dmin = min($dmin, $dk); }
            if ($dmin > $radius) continue;
            $score = $dmin - ($cg['cluster_type'] !== '' && $cg['cluster_type'] === $groups[$seed]['cluster_type'] ? 5 : 0) - ($cg['urgency'] === 'critical' ? 10 : 0);
          } elseif (!$groups[$seed]['geo'] && !$cg['geo']) {
            // No coordinates: only club within same State + Cluster
            if (strcasecmp($cg['state'], $groups[$seed]['state']) !== 0 || $cg['state'] === '' || strcasecmp($cg['cluster_type'], $groups[$seed]['cluster_type']) !== 0) continue;
            $score = 1000 - $cg['pkt'];
          } else continue;
          if ($score < $bestScore) { $bestScore = $score; $best = $c; }
        }
        if ($best === null) break;
        $members[] = $best; unset($left[$best]); $load += $groups[$best]['pkt']; $val += $groups[$best]['value'];
      }
      // Route order: shortest DC -> stores sequence
      $ordered = $members; $routeKm = null; $maxLeg = null;
      $allGeo = !array_filter($members, fn($m) => !$groups[$m]['geo']);
      if ($allGeo) {
        $pts = []; foreach ($members as $m) $pts[$m] = $groups[$m]['geo'];
        $bo = prd_best_order($groups[$seed]['dc_geo'], $pts); $ordered = $bo['order']; $routeKm = $bo['km'];
        $legs = []; for ($i = 1; $i < count($ordered); $i++) $legs[] = prd_km($groups[$ordered[$i - 1]]['geo'], $groups[$ordered[$i]]['geo']);
        $maxLeg = $legs ? max($legs) : null;
      }
      $veh = prd_pick_vehicle($classes, $load, $val) ?? end($classes);
      $fill = $veh['capacity'] ? round($load * 100 / $veh['capacity']) : 0;
      $anyCritical = (bool)array_filter($members, fn($m) => $groups[$m]['urgency'] === 'critical');
      if ($fill >= $minFill) $type = count($members) > 1 ? 'CLUB' : 'PTL';
      else $type = $anyCritical ? 'NOW' : 'HOLD';
      $vehList = $type === 'HOLD' ? [] : [['type' => $veh['type'], 'count' => 1, 'capacity' => $veh['capacity']]];
      $pid = $newPlan($type, $ordered, $vehList, $load, $val, ['fill' => $fill, 'route_km' => $routeKm, 'max_leg' => $maxLeg, 'hold_vehicle' => $veh['type'], 'cap' => $veh['capacity'],
        'value_over' => ($veh['value_cap'] > 0 && $val > $veh['value_cap']), 'value_cap' => $veh['value_cap'], 'geo_missing' => !$allGeo]);
      foreach ($members as $m) $groups[$m]['plan_id'] = $pid;
    }
  }

  // Renumber plans in display order (urgent first, then bigger loads)
  $tOrder = ['NOW' => 0, 'FTL' => 1, 'CLUB' => 2, 'PTL' => 3, 'HOLD' => 4, 'UNMAPPED' => 5, 'CLOSED' => 6, 'HELD' => 7];
  uasort($plans, fn($a, $b) => [$tOrder[$a['type']], -$a['pkt']] <=> [$tOrder[$b['type']], -$b['pkt']]);
  $renum = []; $n = 0; $np = [];
  foreach ($plans as $old => $p) { $id = 'L' . str_pad((string)(++$n), 2, '0', STR_PAD_LEFT); $renum[$old] = $id; $p['id'] = $id; $np[$id] = $p; }
  $plans = $np;
  foreach ($groups as &$g) $g['plan_id'] = $renum[$g['plan_id']];
  unset($g);

  // Remarks text + flags for each store
  foreach ($groups as $k => &$g) {
    $p = $plans[$g['plan_id']];
    $g['plan_type'] = $p['type'];
    $g['plan_stop'] = array_search($k, $p['keys'], true) + 1;
    $g['plan_size'] = count($p['keys']);
    $partners = array_values(array_map(fn($x) => $groups[$x]['store']['code'] ?? $groups[$x]['destination_name'], array_filter($p['keys'], fn($x) => $x !== $k)));
    $vehTxt = implode(' + ', array_map(fn($v) => ($v['count'] > 1 ? $v['count'] . '× ' : '') . $v['type'], $p['vehicles']));
    $flags = [];
    switch ($p['type']) {
      case 'FTL':
        $nTrips = array_sum(array_column($p['vehicles'], 'count'));
        if ($nTrips > 1) {
          $full = intdiv($g['pkt'], $maxCap); $rem = $g['pkt'] - $full * $maxCap;
          $g['remark'] = 'Split into ' . $nTrips . ' trips: ' . implode(' + ', array_merge(array_fill(0, $full, (string)$maxCap), $rem ? [(string)$rem] : [])) . ' PKT';
          $g['remark_sub'] = 'Max ' . $maxCap . ' PKT per trip, oldest challans first · ' . $vehTxt . ($p['route_km'] ? ' · ~' . round($p['route_km']) . ' km from DC' : '');
        } else { $g['remark'] = 'Direct FTL on ' . $vehTxt; $g['remark_sub'] = $p['fill'] . '% fill' . ($p['route_km'] ? ' · ~' . round($p['route_km']) . ' km from DC' : ''); }
        break;
      case 'CLUB':
        $g['remark'] = 'Club load ' . $p['id'] . ' on ' . $vehTxt . ' with ' . implode(', ', $partners);
        $g['remark_sub'] = 'Stop ' . $g['plan_stop'] . ' of ' . $g['plan_size'] . ' · ' . $p['pkt'] . ' PKT · ' . $p['fill'] . '% fill' . ($p['route_km'] ? ' · ~' . round($p['route_km']) . ' km route' : ''); break;
      case 'PTL':
        $g['remark'] = 'Direct PTL on ' . $vehTxt; $g['remark_sub'] = $p['fill'] . '% fill · ' . ($g['geo'] ? 'no nearby store within ' . round((float)$cfg['club_radius_km']) . ' km to club' : 'no lat/long, so no clubbing partner found'); break;
      case 'NOW':
        $wait = $g['gap_days'] ?? $g['age_days'];
        $g['remark'] = 'Dispatch now on ' . $vehTxt . ($partners ? ' with ' . implode(', ', $partners) : '');
        $g['remark_sub'] = 'Low fill ' . $p['fill'] . '% but ' . ($wait !== null ? $wait . ' days waiting' : 'overdue'); break;
      case 'HOLD':
        $g['remark'] = 'Hold & consolidate' . ($partners ? ' with ' . implode(', ', $partners) : '');
        $need = max(1, (int)ceil($minFill * $p['cap'] / 100) - $p['pkt']);
        $g['remark_sub'] = 'Only ' . $p['pkt'] . ' PKT = ' . $p['fill'] . '% of ' . $p['hold_vehicle'] . '. Needs ' . $need . ' more PKT to reach ' . (int)$minFill . '% fill'; break;
      case 'UNMAPPED':
        $g['remark'] = 'Map store in Store Master'; $g['remark_sub'] = '"' . $g['destination_name'] . '"' . ($g['dest_site'] !== '' ? ' (site ' . $g['dest_site'] . ')' : '') . ' not in Store Master. Run Store sync from Oracle or check T_STORE_OPENING_DATE.'; break;
      case 'HELD':
        $h = $g['hold'];
        $g['remark'] = 'On hold: ' . $h['reason'];
        $g['remark_sub'] = trim(($h['remarks'] ? $h['remarks'] . ' · ' : '') . 'since ' . date('d M', strtotime((string)$h['created_at'])) . ($h['hold_until'] ? ' · until ' . date('d M', strtotime((string)$h['hold_until'])) : ' · until released')); break;
      case 'CLOSED':
        $g['remark'] = 'Store closed – hold dispatch'; $g['remark_sub'] = 'Store status: ' . $g['store_status']; break;
    }
    if (!empty($p['value_over'])) $flags[] = ['warn', 'Value above ₹' . number_format($p['value_cap'] / 100000, 1) . 'L cap'];
    if (!$g['dispatch_day']['today'] && in_array($p['type'], ['FTL', 'CLUB', 'PTL', 'NOW'], true)) $flags[] = ['warn', 'Not a dispatch day · next ' . $g['dispatch_day']['next']];
    if ($g['store'] && !$g['geo']) $flags[] = ['info', 'Store lat/long missing'];
    if (!$g['dc'] && $g['store']) $flags[] = ['info', 'Source not in DC master'];
    if ($g['due'] && $p['type'] !== 'HELD') $flags[] = ['crit', 'Due: ' . $g['wait_days'] . ' days waiting'];
    elseif ($g['urgency'] === 'critical') $flags[] = ['crit', ($g['gap_days'] !== null ? $g['gap_days'] . 'd since dispatch' : 'Oldest challan ' . $g['age_days'] . 'd')];
    elseif ($g['urgency'] === 'watch') $flags[] = ['watch', ($g['gap_days'] !== null ? $g['gap_days'] . 'd since dispatch' : 'Oldest challan ' . $g['age_days'] . 'd')];
    if (!empty($p['max_leg']) && $p['max_leg'] > (float)$cfg['club_radius_km'] * 0.75) $flags[] = ['info', 'Longest store hop ' . round($p['max_leg']) . ' km'];
    $g['flags'] = $flags;
  }
  unset($g);

  // Vehicle demand vs availability
  $demand = [];
  foreach ($plans as $p) foreach ($p['vehicles'] as $v) { $demand[$v['type']] = ($demand[$v['type']] ?? 0) + $v['count']; }
  $demandRows = [];
  foreach ($classes as $c) if (!empty($demand[$c['type']]) || $c['from_master']) $demandRows[] = ['type' => $c['type'], 'capacity' => $c['capacity'], 'need' => (int)($demand[$c['type']] ?? 0), 'available' => $c['available']];

  // Sort rows by plan id (already in priority order), then stop number
  uasort($groups, fn($a, $b) => [$a['plan_id'], $a['plan_stop']] <=> [$b['plan_id'], $b['plan_stop']]);

  return ['groups' => $groups, 'plans' => $plans, 'classes' => $classes, 'demand' => $demandRows, 'max_cap' => $maxCap, 'ftl_pkt' => $ftlPkt, 'cfg' => $cfg, 'opt' => $opt];
}

/**
 * Turn a user selection (any number of stores, any DCs) into ready-to-create trips that obey the
 * business limits: max PKT per trip and max stores per trip (config/app.php).
 * A store with more PKT than one trip allows is split FIFO (oldest challans first): full trips of
 * max PKT, and the remainder can be clubbed with a nearby store.
 * Each trip stop carries the exact PKT keys (challan no. / barcode) it takes.
 */
/** Stores that already have an active (not cancelled) trip for today: [store_id => trip_code]. One trip per store per day. */
function prd_stores_with_trip_today(PDO $d): array {
  try {
    return $d->query("SELECT ts.store_id, MIN(t.trip_code) FROM tms_trip_stops ts JOIN trips t ON t.id=ts.trip_id
      WHERE COALESCE(t.status,'')<>'Cancelled' AND COALESCE(t.planned_date, t.created_at::date)=CURRENT_DATE GROUP BY ts.store_id")->fetchAll(PDO::FETCH_KEY_PAIR);
  } catch (Throwable $e) { return []; }
}

function prd_plan_selection(PDO $d, array $keys, string $mode = 'separate', ?array $planLineIds = null, array $opt = []): array {
  $cfg = prd_cfg();
  $joint = $mode === 'joint';
  // $opt (auto trips): 'balance' => spread stores evenly so no store is left alone (10 stores -> 3+3+2+2, not 3+3+3+1);
  //                    'max_stops' => 2..max (default: config); 'strict' => stores of different clusters never share a trip.
  $balance = !empty($opt['balance']); $strict = !empty($opt['strict']);
  // Keys may be joint keys (from the joint board); expand them to source group keys.
  // With $planLineIds only the challans reserved for those plan lines are considered (plan -> trips).
  $all = prd_enrich($d, $planLineIds !== null ? prd_load_groups($d, null, $planLineIds) : prd_load_groups($d));
  $byJoint = []; foreach ($all as $k => $g) $byJoint[md5('JOINT|' . strtolower(trim($g['destination_name'])))][] = $k;
  $src = [];
  foreach ($keys as $k) { if (isset($all[$k])) $src[$k] = true; elseif (isset($byJoint[$k])) foreach ($byJoint[$k] as $x) $src[$x] = true; }
  $picked = array_intersect_key($all, $src);
  $groups = prd_prepare_groups($d, $picked, ['mode' => $joint ? 'joint' : 'separate']);
  $classes = prd_vehicle_classes($d);
  $maxPkt = function_exists('tms_trip_max_pkt') ? tms_trip_max_pkt() : 300;
  $maxStops = max(1, min((int)$cfg['max_stops'], function_exists('tms_trip_max_stores') ? tms_trip_max_stores() : 2));
  if (!empty($opt['max_stops'])) $maxStops = max(1, min((int)$opt['max_stops'], function_exists('tms_trip_max_stores') ? tms_trip_max_stores() : 3));
  $radius = (float)$cfg['club_radius_km'];
  $singleMin = (int)(app_cfg('trip.single_min_pkt') ?? 400);
  $today = prd_stores_with_trip_today($d);
  $excluded = [];
  if (!$picked && $keys) $excluded[] = ['gkey' => '', 'name' => '—', 'reason' => 'Selected stores are no longer pending (already in a trip or dispatched in Oracle). Refresh the page.'];

  $pieces = []; $buckets = [];
  foreach ($groups as $k => $g) {
    if ($g['hold']) { $excluded[] = ['gkey' => $k, 'name' => $g['destination_name'], 'reason' => 'On hold: ' . $g['hold']['reason'] . ($g['hold']['remarks'] ? ' (' . $g['hold']['remarks'] . ')' : '') . '. Release the hold to plan it.']; continue; }
    if (!$g['store']) { $excluded[] = ['gkey' => $k, 'name' => $g['destination_name'], 'reason' => 'Not in Store Master. Run Store sync or add the store.']; continue; }
    if ($g['closed']) { $excluded[] = ['gkey' => $k, 'name' => $g['destination_name'], 'reason' => 'Store is closed (' . $g['store_status'] . ').']; continue; }
    if ($joint) foreach ($g['parts'] as $pt) if (!$pt['dc']) { $excluded[] = ['gkey' => $k, 'name' => $g['destination_name'], 'reason' => 'Source “' . $pt['source'] . '” is not in Center DC master, so a joint pickup cannot be routed.']; continue 2; }
    // One trip per store per day: a store that already has an active trip today is not planned again.
    $sid = (int)($g['store']['id'] ?? 0);
    if ($sid && isset($today[$sid])) { $excluded[] = ['gkey' => $k, 'name' => $g['destination_name'], 'reason' => 'Already on trip ' . $today[$sid] . ' today. A store gets only one trip per day.']; continue; }
    // A store of at least single_min PKT goes alone with ALL its PKT in one trip (never split); a smaller store only travels combined.
    if ($maxStops > 1 && $g['pkt'] > $maxPkt && $g['pkt'] < $singleMin) { $excluded[] = ['gkey' => $k, 'name' => $g['destination_name'], 'reason' => $g['pkt'] . ' PKT: too big to combine (limit ' . $maxPkt . ' PKT per combined trip) and below ' . $singleMin . ' PKT, so it cannot go alone either.']; continue; }
    $bucket = $joint ? 'JOINT' : $g['source_site_code'] . '|' . strtolower($g['source_label']);
    $chunks = [array_keys($g['units'])]; $n = 1;   // never split a store over two trips
    foreach ($chunks as $i => $ch) {
      $val = 0.0; $qty = 0.0; $parts = [];
      foreach ($ch as $u) {
        $val += $g['units'][$u]['value']; $qty += $g['units'][$u]['qty'];
        [$gk, $pk] = $joint ? explode('|', $u, 2) : [$k, $u];
        $parts[$gk][] = (string)$pk;
      }
      $partList = [];
      foreach ($parts as $gk => $pks) $partList[] = ['gkey' => $gk, 'pkts' => ($n > 1 || $joint) ? $pks : null, 'pkt' => count($pks), 'source' => $g['parts'][$gk]['source'] ?? $g['source_label'], 'dc' => $g['parts'][$gk]['dc'] ?? $g['dc']];
      $pid = $k . '#' . $i;
      $pieces[$pid] = ['gkey' => $k, 'parts' => $partList, 'pkt' => count($ch), 'value' => $val, 'qty' => $qty, 'part' => $n > 1 ? ($i + 1) . '/' . $n : null, 'geo' => $g['geo'], 'state' => $g['state'], 'cluster' => (string)($g['cluster_type'] ?? '')];
      $buckets[$bucket][] = $pid;
    }
  }

  $loads = [];
  $mk = function (array $members) use (&$pieces, &$groups, $classes, $radius, &$loads, $joint) {
    $first = $groups[$pieces[$members[0]]['gkey']];
    // pickups: DCs involved, biggest first
    $pick = [];
    foreach ($members as $m) foreach ($pieces[$m]['parts'] as $pt) { $id = $pt['dc']['id'] ?? 0; $pick[$id] = ['dc' => $pt['dc'], 'source' => $pt['source'], 'pkt' => ($pick[$id]['pkt'] ?? 0) + $pt['pkt']]; }
    uasort($pick, fn($a, $b) => $b['pkt'] <=> $a['pkt']);
    $origin = null; foreach ($pick as $pk) if (!empty($pk['dc'])) { foreach ($groups as $g) foreach ($g['parts'] as $pt) if (($pt['dc']['id'] ?? 0) === $pk['dc']['id'] && $pt['dc_geo']) { $origin = $pt['dc_geo']; break 3; } }
    $origin = $origin ?? $first['dc_geo'];
    $pts = []; foreach ($members as $m) $pts[$m] = $pieces[$m]['geo'];
    $bo = prd_best_order($origin, $pts);
    $pkt = 0; $val = 0.0; $stops = []; $prev = null; $maxLeg = 0.0; $warn = [];
    foreach ($bo['order'] as $m) {
      $pc = $pieces[$m]; $g = $groups[$pc['gkey']]; $pkt += $pc['pkt']; $val += $pc['value'];
      $km = $prev ? prd_km($prev, $pc['geo']) : prd_km($origin, $pc['geo']);
      if ($prev && $km !== null) $maxLeg = max($maxLeg, $km);
      if ($pc['geo']) $prev = $pc['geo'];
      $stops[] = ['gkey' => $pc['gkey'], 'parts' => array_map(fn($x) => ['gkey' => $x['gkey'], 'pkts' => $x['pkts'], 'pkt' => $x['pkt'], 'source' => $x['source']], $pc['parts']), 'part' => $pc['part'],
        'code' => $g['store']['code'], 'name' => $g['store']['name'], 'city' => $g['store']['city'], 'cluster' => trim((string)($g['cluster_type'] ?? '')), 'state' => (string)($g['state'] ?? ''), 'has_geo' => (bool)$pc['geo'], 'pkt' => $pc['pkt'], 'store_pkt' => $g['pkt'], 'value' => round($pc['value'], 2), 'km' => $km !== null ? round($km) : null, 'due' => $g['due'], 'wait' => $g['wait_days']];
      if (!$pc['geo']) $warn['geo'] = 'Route not verified: some stores have no latitude/longitude, so they are grouped by cluster and km is approximate.';
      if ($pc['part']) $warn['split' . $pc['gkey']] = $g['store']['code'] . ' has ' . $g['pkt'] . ' PKT, more than one trip allows, so it is split (part ' . $pc['part'] . ', oldest challans first).';
    }
    if (count($pick) > 1) $warn['joint'] = 'Joint vehicle: collects from ' . implode(' then ', array_map(fn($x) => $x['source'] . ' (' . $x['pkt'] . ' PKT)', $pick)) . ' before delivering.';
    $veh = prd_pick_vehicle($classes, $pkt, $val) ?? end($classes);
    if ($veh['value_cap'] > 0 && $val > $veh['value_cap']) $warn['val'] = 'Value ₹' . number_format($val / 100000, 2) . ' L is above the ' . $veh['type'] . ' value cap ₹' . number_format($veh['value_cap'] / 100000, 2) . ' L.';
    if ($pkt > $veh['capacity'] && count($stops) === 1) $warn['cap'] = $pkt . ' PKT is more than a ' . $veh['type'] . ' carries (' . $veh['capacity'] . '). Choose a bigger vehicle type for this trip.';
    if ($maxLeg > $radius) $warn['far'] = 'Store-to-store distance is ' . round($maxLeg) . ' km (planning radius ' . round($radius) . ' km).';
    $loads[] = ['source' => implode(' + ', array_map(fn($x) => $x['source'], $pick)), 'dc' => reset($pick)['dc'] ?? $first['dc'], 'pickups' => array_values(array_map(fn($x) => ['dc' => $x['dc'], 'source' => $x['source'], 'pkt' => $x['pkt']], $pick)),
      'joint' => count($pick) > 1, 'stops' => $stops, 'pkt' => $pkt, 'value' => round($val, 2), 'vehicle' => $veh['type'], 'capacity' => $veh['capacity'],
      'fill' => $veh['capacity'] ? (int)round($pkt * 100 / $veh['capacity']) : 0, 'route_km' => $bo['km'] !== null ? round($bo['km']) : null, 'warnings' => array_values($warn), '_m' => $members];
  };
  foreach ($buckets as $ids) {
    $pool = [];
    foreach ($ids as $pid) { if ($pieces[$pid]['pkt'] >= $singleMin || $maxStops === 1) $mk([$pid]); else $pool[$pid] = $pieces[$pid]['pkt'] + ($groups[$pieces[$pid]['gkey']]['due'] ? 1000 : 0); }
    arsort($pool); $left = $pool;   // due stores seed first
    $sets = [];
    while ($left) {
      $seed = array_key_first($left); unset($left[$seed]);
      $members = [$seed]; $load = $pieces[$seed]['pkt'];
      while (count($members) < $maxStops && $left) {
        $best = null; $bestScore = INF; $used = array_map(fn($m) => $pieces[$m]['gkey'], $members);
        foreach (array_keys($left) as $c) {
          $pc = $pieces[$c];
          if ($load + $pc['pkt'] > $maxPkt || in_array($pc['gkey'], $used, true)) continue;
          if ($strict && strtolower(trim($pc['cluster'])) !== strtolower(trim($pieces[$seed]['cluster']))) continue;
          if ($pieces[$seed]['geo'] && $pc['geo']) {
            $dmin = INF; foreach ($members as $m) { $dk = prd_km($pieces[$m]['geo'], $pc['geo']); if ($dk !== null) $dmin = min($dmin, $dk); }
            if ($dmin > $radius * 2) continue;
            $score = $dmin - $pc['pkt'] / 50 - (strtolower(trim($pc['cluster'])) === strtolower(trim($pieces[$seed]['cluster'])) && $pc['cluster'] !== '' ? 8 : 0);
          } else {
            // A store without coordinates cannot be placed on a route: it only joins a load of the SAME CLUSTER.
            $cl = strtolower(trim($pc['cluster'])); $cs = strtolower(trim($pieces[$seed]['cluster']));
            if ($cl === '' || $cl === '-' || $cl !== $cs) continue;
            $score = 500 - $pc['pkt'] / 50;
          }
          if ($score < $bestScore) { $bestScore = $score; $best = $c; }
        }
        if ($best === null) break;
        $members[] = $best; unset($left[$best]); $load += $pieces[$best]['pkt'];
      }
      $sets[] = $members;
    }
    // Balance: a store left alone (e.g. the 10th store when trips take 3) joins a neighbouring trip; if all of those are full, it takes
    // the nearest store off a full trip, so 10 stores become 3+3+2+2 instead of 3+3+3+1. Limits (max stores, max PKT, clusters) still hold.
    if ($balance && $maxStops > 1) {
      $setPkt = fn(array $s) => array_sum(array_map(fn($m) => $pieces[$m]['pkt'], $s));
      $same = fn($a, $b) => strtolower(trim($pieces[$a]['cluster'])) === strtolower(trim($pieces[$b]['cluster']));
      $near = function ($a, $b) use (&$pieces, $radius, $strict, $same) {   // km between two pieces, null = may not travel together
        if ($strict && !$same($a, $b)) return null;
        if ($pieces[$a]['geo'] && $pieces[$b]['geo']) { $k = prd_km($pieces[$a]['geo'], $pieces[$b]['geo']); return $k !== null && $k <= $radius * 2 ? $k : null; }
        $c = strtolower(trim($pieces[$a]['cluster'])); return ($c !== '' && $c !== '-' && $same($a, $b)) ? 500.0 : null;
      };
      for ($guard = 0; $guard < 200; $guard++) {
        $done = true;
        foreach ($sets as $si => $S) {
          if (count($S) !== 1 || $setPkt($S) >= $maxPkt) continue;
          $a = $S[0]; $bestMerge = null; $bestMergeD = INF; $bestSteal = null; $bestStealD = INF;
          foreach ($sets as $ti => $T) {
            if ($ti === $si) continue;
            foreach ($T as $x) {
              if ($pieces[$x]['gkey'] === $pieces[$a]['gkey']) continue 2;
              $dk = $near($a, $x); if ($dk === null) continue;
              if (count($T) < $maxStops && $setPkt($T) + $pieces[$a]['pkt'] <= $maxPkt && $dk < $bestMergeD) { $bestMergeD = $dk; $bestMerge = $ti; }
              if (count($T) === $maxStops && count($T) > 2 && $pieces[$a]['pkt'] + $pieces[$x]['pkt'] <= $maxPkt && $dk < $bestStealD) { $bestStealD = $dk; $bestSteal = [$ti, $x]; }
            }
          }
          if ($bestMerge !== null) { $sets[$bestMerge][] = $a; unset($sets[$si]); $done = false; break; }
          if ($bestSteal !== null) { [$ti, $x] = $bestSteal; $sets[$ti] = array_values(array_diff($sets[$ti], [$x])); $sets[$si][] = $x; $done = false; break; }
        }
        if ($done) break;
        $sets = array_values($sets);
      }
    }
    foreach ($sets as $members) $mk($members);
  }
  // Why a store travels alone (shown in the PRD analysis): full load / nothing else selected / too far / would not fit / no coordinates
  $bucketOf = []; foreach ($buckets as $b => $ids) foreach ($ids as $pid) $bucketOf[$pid] = $b;
  $drop = [];
  foreach ($loads as $li => &$ld) {
    $m = $ld['_m'] ?? []; unset($ld['_m']); $ld['alone'] = null;
    if (count($m) !== 1) continue;
    $me = $m[0]; $pc = $pieces[$me]; $near = null; $nearKm = INF; $others = 0;
    foreach ($buckets[$bucketOf[$me]] ?? [] as $o) {
      if ($o === $me || $pieces[$o]['gkey'] === $pc['gkey']) continue;
      $others++; $dk = ($pc['geo'] && $pieces[$o]['geo']) ? prd_km($pc['geo'], $pieces[$o]['geo']) : null;
      if ($dk !== null && $dk < $nearKm) { $nearKm = $dk; $near = $o; }
    }
    if ($pc['pkt'] >= $singleMin) $ld['alone'] = 'Goes alone: ' . $pc['pkt'] . ' PKT (a store of ' . $singleMin . '+ PKT gets its own trip).';
    elseif ($maxStops === 1) $ld['alone'] = 'Clubbing is off (max 1 store per trip).';
    elseif (!$others) $ld['alone'] = 'No other selected store from this source to club with.';
    elseif (!$pc['geo']) $ld['alone'] = 'No latitude/longitude, so it can only club with a store of the same cluster' . ($pc['cluster'] !== '' ? ' (' . $pc['cluster'] . ')' : '') . ' and none fits.';
    elseif ($near === null) $ld['alone'] = 'The other selected stores have no coordinates, so distance cannot be checked.';
    elseif ($nearKm > $radius * 2) $ld['alone'] = 'Nearest selected store (' . ($groups[$pieces[$near]['gkey']]['store']['code'] ?? '?') . ') is ' . round($nearKm) . ' km away; clubbing limit is ' . round($radius * 2) . ' km.';
    else $ld['alone'] = 'Nearest selected store (' . ($groups[$pieces[$near]['gkey']]['store']['code'] ?? '?') . ', ' . round($nearKm) . ' km) is already in another trip or would take it above ' . $maxPkt . ' PKT.';
    // A store below single_min PKT never gets a trip of its own: it waits until it can be combined.
    if ($maxStops > 1 && $pc['pkt'] < $singleMin) { $drop[] = $li; $excluded[] = ['gkey' => $pc['gkey'], 'name' => $groups[$pc['gkey']]['store']['code'] ?? $groups[$pc['gkey']]['destination_name'], 'reason' => 'No trip: ' . $pc['pkt'] . ' PKT is below ' . $singleMin . ' PKT, so it can only go combined, and ' . lcfirst((string)$ld['alone'])]; }
  }
  unset($ld);
  foreach ($drop as $li) unset($loads[$li]); $loads = array_values($loads);
  return ['loads' => $loads, 'excluded' => $excluded, 'classes' => array_map(fn($c) => ['type' => $c['type'], 'capacity' => $c['capacity']], $classes),
    'max_stops' => $maxStops, 'max_pkt' => $maxPkt, 'mode' => $joint ? 'joint' : 'separate'];
}

/**
 * One short dispatch suggestion for a store, e.g. "Dispatch · 24FT · 92%", "Club with BRB · 24FT · 88%", "Wait · 35% fill".
 * $partners: other stores in the same load.
 */
function prd_chip(array $p, array $g, array $partners = []): array {
  $veh = implode(' + ', array_map(fn($v) => ($v['count'] > 1 ? $v['count'] . '× ' : '') . $v['type'], $p['vehicles'] ?? []));
  $fill = isset($p['fill']) ? (int)$p['fill'] . '%' : '';
  switch ($p['type']) {
    case 'FTL': $t = 'Dispatch'; $d = trim($veh . ($fill ? ' · ' . $fill : '')); break;
    case 'PTL': $t = 'Dispatch'; $d = trim($veh . ($fill ? ' · ' . $fill : '')); break;
    case 'NOW': $t = 'Dispatch now'; $d = trim($veh . ($fill ? ' · ' . $fill : '')) . ($partners ? ' · with ' . implode(', ', $partners) : ''); break;
    case 'CLUB': $t = 'Club with ' . implode(', ', $partners); $d = trim($veh . ($fill ? ' · ' . $fill : '')); break;
    case 'HOLD': $t = 'Wait'; $d = ($fill ? $fill . ' fill' : '') . ($partners ? ' · with ' . implode(', ', $partners) : ''); break;
    case 'HELD': $t = 'On hold'; $d = (string)($g['hold']['reason'] ?? ''); break;
    case 'UNMAPPED': $t = 'Add to store master'; $d = ''; break;
    case 'CLOSED': $t = 'Store closed'; $d = ''; break;
    default: $t = $p['type']; $d = '';
  }
  return ['type' => $p['type'], 'label' => $t, 'veh' => $d, 'fill' => $p['fill'] ?? null, 'plan' => $p['id'], 'remark' => $g['remark'] ?? '', 'sub' => $g['remark_sub'] ?? '', 'flags' => $g['flags'] ?? []];
}

/**
 * PRD matrix (same shape as the Oracle PRD report): one row per destination store, per source site
 * APPS PKT / APPS value / GM PKT / GM value, combined totals, grand total, plus a vehicle suggestion.
 * $opt: site (one site or null for all), sug: separate|joint (how suggestions are made).
 */
function prd_matrix(PDO $d, array $opt = []): array {
  $site = $opt['site'] ?? null; $sug = ($opt['sug'] ?? 'joint') === 'separate' ? 'separate' : 'joint';
  $board = prd_build_board($d, ['mode' => 'separate', 'site' => $site, 'wh' => $site ?: 'all']);
  $jb = $sug === 'joint' ? prd_build_board($d, ['mode' => 'joint', 'site' => $site, 'wh' => 'joint']) : null;
  $cfg = function_exists('tms_oracle_cfg') ? tms_oracle_cfg() : ['src_site_codes' => []];
  $sites = [];
  foreach (($site ? [$site] : $cfg['src_site_codes']) as $sc) $sites[(string)$sc] = (string)$sc;
  foreach ($board['groups'] as $g) { $sc = (string)$g['source_site_code']; if (!isset($sites[$sc]) || $sites[$sc] === $sc) $sites[$sc] = $g['source_label'] ?: $sc; }
  $zero = ['apps_pkt' => 0, 'apps_val' => 0.0, 'gm_pkt' => 0, 'gm_val' => 0.0, 'pkt' => 0, 'val' => 0.0];
  $partners = function (array $bd, array $p, string $self): array { $o = []; foreach ($p['keys'] as $k) if ($k !== $self && isset($bd['groups'][$k])) $o[] = $bd['groups'][$k]['store']['code'] ?? $bd['groups'][$k]['destination_name']; return array_values(array_unique($o)); };
  $rows = []; $tot = ['all' => $zero]; foreach ($sites as $sc => $_) $tot[$sc] = $zero;
  foreach ($board['groups'] as $k => $g) {
    $sk = strtolower(trim($g['destination_name'])); $sc = (string)$g['source_site_code'];
    $rid = md5('ROW|' . $sk);
    if (!isset($rows[$rid])) $rows[$rid] = ['id' => $rid, 'name' => $g['destination_name'], 'store' => $g['store'], 'state' => $g['state'], 'cluster' => $g['cluster_type'], 'dest_site' => $g['dest_site'],
      'keys' => [], 'by' => [], 'chips' => [], 'flags' => [], 'wait' => null, 'due' => false, 'hold' => $g['hold'], 'note' => '', 'note_key' => $k, 'last_dispatch' => null, 'oldest' => null, 'dc_km' => $g['dc_km'], 'off' => true, 'status' => ''] + $zero;
    $r = &$rows[$rid];
    $ap = (int)($g['div_pkt']['APPS'] ?? 0); $gp = (int)($g['div_pkt']['GM'] ?? 0); $av = (float)($g['div_value']['APPS'] ?? 0); $gv = (float)($g['div_value']['GM'] ?? 0);
    $r['by'][$sc] = ['apps_pkt' => $ap, 'apps_val' => $av, 'gm_pkt' => $gp, 'gm_val' => $gv, 'pkt' => $g['pkt'], 'val' => $g['value'], 'gkey' => $k, 'oldest' => $g['oldest_challan'], 'wait' => $g['wait_days']];
    foreach ([&$r, &$tot[$sc], &$tot['all']] as &$t) { $t['apps_pkt'] += $ap; $t['apps_val'] += $av; $t['gm_pkt'] += $gp; $t['gm_val'] += $gv; $t['pkt'] += $g['pkt']; $t['val'] += $g['value']; } unset($t);
    $r['keys'][] = $k;
    if ($g['wait_days'] !== null) $r['wait'] = max((int)$r['wait'], (int)$g['wait_days']);
    $r['due'] = $r['due'] || ($g['due'] && !$g['hold']);
    if ($g['note'] !== '' && $r['note'] === '') { $r['note'] = $g['note']; $r['note_key'] = $k; }
    if ($g['last_dispatch'] && (!$r['last_dispatch'] || $g['last_dispatch'] > $r['last_dispatch'])) $r['last_dispatch'] = $g['last_dispatch'];
    if ($g['oldest_challan'] && (!$r['oldest'] || $g['oldest_challan'] < $r['oldest'])) $r['oldest'] = $g['oldest_challan'];
    if (!$r['store'] && $g['store']) $r['store'] = $g['store'];
    $p = $board['plans'][$g['plan_id']];
    if (!in_array($p['type'], ['UNMAPPED', 'CLOSED', 'HELD'], true)) $r['off'] = false;
    if ($sug === 'separate') $r['chips'][$sc] = prd_chip($p, $g, $partners($board, $p, $k));
    if ($g['store_status'] !== '' && empty($r['status'])) $r['status'] = $g['store_status'];
    foreach ($g['flags'] as $f) $r['flags'][$f[1]] = $f;
    unset($r);
  }
  if ($jb) foreach ($jb['groups'] as $jk => $g) {
    $rid = md5('ROW|' . strtolower(trim($g['destination_name'])));
    if (isset($rows[$rid])) $rows[$rid]['chips'] = ['joint' => prd_chip($jb['plans'][$g['plan_id']], $g, $partners($jb, $jb['plans'][$g['plan_id']], $jk))];
  }
  foreach ($rows as &$r) { $r['flags'] = array_values($r['flags']); } unset($r);
  uasort($rows, fn($a, $b) => [$b['due'] && !$b['off'], $b['pkt']] <=> [$a['due'] && !$a['off'], $a['pkt']]);
  $due = 0; $held = 0; foreach ($rows as $r) { if ($r['due'] && !$r['off']) $due++; if ($r['hold']) $held++; }
  $src = $jb ?: $board;
  return ['sites' => $sites, 'rows' => $rows, 'tot' => $tot, 'board' => $board, 'sug' => $sug, 'site' => $site, 'due' => $due, 'held' => $held,
    'demand' => $src['demand'], 'classes' => $board['classes'], 'cfg' => $board['cfg']];
}

/** CSV of the PRD matrix (same columns as the screen and the Oracle PRD report). */
function prd_matrix_csv(PDO $d, array $opt): void {
  $m = prd_matrix($d, $opt);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="PRD_PENDING_' . date('Ymd_Hi') . '.csv"');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
  $h = ['DESTINATION_NAME', 'STATE', 'STORE_STATUS', 'CLUSTER_TYPE', 'LAST_DISP_DATE'];
  foreach ($m['sites'] as $sc => $lbl) array_push($h, "$sc APPS_PKT", "$sc APPS_VAL", "$sc GM_PKT", "$sc GM_VAL", "$sc TOTAL_PKT", "$sc TOTAL_VAL");
  array_push($h, 'TOTAL_APPS_PKT', 'TOTAL_APPS_VAL', 'TOTAL_GM_PKT', 'TOTAL_GM_VAL', 'TOTAL_PKT', 'TOTAL_VAL', 'DAYS_SINCE_DISPATCH', 'SUGGESTION');
  fputcsv($o, $h);
  $cells = function (array $b) { return [$b['apps_pkt'] ?? 0, round($b['apps_val'] ?? 0, 2), $b['gm_pkt'] ?? 0, round($b['gm_val'] ?? 0, 2), $b['pkt'] ?? 0, round($b['val'] ?? 0, 2)]; };
  $ot = prd_oracle_totals($d, $m['sites']); // numbers as in Oracle (incl. challans already on trips)
  foreach ($m['rows'] as $r) {
    $k = strtolower(trim((string)$r['name']));
    $x = [$r['name'], $r['state'], $r['status'], $r['cluster'], $r['last_dispatch'] ?? ''];
    foreach ($m['sites'] as $sc => $_) array_push($x, ...$cells($ot['rows'][$k][(string)$sc] ?? []));
    array_push($x, ...$cells($ot['rows'][$k]['all'] ?? []));
    array_push($x, $r['wait'] ?? '', implode(' | ', array_map(fn($c) => trim($c['label'] . ' ' . $c['veh']), $r['chips'])));
    fputcsv($o, $x);
  }
  $x = ['GRAND TOTAL', '', '', '', ''];
  foreach ($m['sites'] as $sc => $_) array_push($x, ...$cells($ot['tot'][(string)$sc]));
  array_push($x, ...$cells($ot['tot']['all']));
  fputcsv($o, $x); fclose($o);
}

/**
 * What Oracle has Pending: every row the LAST sync returned (a sync stamps all its rows with the same synced_at), per store key
 * (lower-cased destination name) and site, plus totals per site and 'all'. This includes challans that are already on a trip, so the
 * numbers equal an Oracle report; the planning board itself still plans only what is not on a trip.
 * @param array $sites keys = site codes to include (the board's sites)
 * @return array{rows:array,tot:array}
 */
function prd_oracle_totals(PDO $d, array $sites): array {
  $z = ['apps_pkt' => 0, 'apps_val' => 0.0, 'gm_pkt' => 0, 'gm_val' => 0.0, 'pkt' => 0, 'val' => 0.0];
  $tot = []; foreach ($sites as $sc => $_) $tot[(string)$sc] = $z; $tot['all'] = $z; $rows = [];
  $pk = prd_pkt_col('p'); $apps = "UPPER(BTRIM(COALESCE(p.sub_div,'')))='APPS'";
  // These numbers only change when PRD Pending is refreshed, so keep the result per refresh (the COUNT DISTINCT over ~90k rows costs ~0.5 s per page).
  $ck = 'prd_totals:' . md5(implode(',', array_map('strval', array_keys($sites)))); $stamp = null;   // one cached copy per set of sites
  try {
    $stamp = $d->query("SELECT COALESCE(MAX(id),0) || '|' || COALESCE(MAX(created_at)::text,'') FROM tms_sync_log WHERE kind='prd'")->fetchColumn() . '|' . prd_status_filter();
    $h = $d->prepare("SELECT v FROM tms_meta WHERE k=?"); $h->execute([$ck]); $hit = json_decode((string)$h->fetchColumn(), true);
    if (is_array($hit) && ($hit['stamp'] ?? null) === $stamp && isset($hit['out']['rows'], $hit['out']['tot'])) return $hit['out'];
  } catch (Throwable $e) { $stamp = null; }
  $q = $d->prepare("WITH lt AS (SELECT MAX(synced_at) mx FROM prd_pending WHERE source_system='ORACLE')
    SELECT lower(BTRIM(p.destination_name)) k, p.source_site_code site,
           COUNT(DISTINCT $pk) FILTER (WHERE $apps) ap, COALESCE(SUM(p.value) FILTER (WHERE $apps),0) av,
           COUNT(DISTINCT $pk) FILTER (WHERE NOT $apps) gp, COALESCE(SUM(p.value) FILTER (WHERE NOT $apps),0) gv,
           COUNT(DISTINCT $pk) pkt, COALESCE(SUM(p.value),0) val
    FROM prd_pending p CROSS JOIN lt
    WHERE p.source_system='ORACLE' AND p.synced_at >= lt.mx AND UPPER(BTRIM(COALESCE(p.challan_status,'')))=? AND NULLIF(BTRIM(p.destination_name),'') IS NOT NULL
    GROUP BY 1,2");
  $q->execute([prd_status_filter()]);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
    $s = (string)$x['site']; if (!isset($tot[$s])) continue;
    $a = ['apps_pkt' => (int)$x['ap'], 'apps_val' => (float)$x['av'], 'gm_pkt' => (int)$x['gp'], 'gm_val' => (float)$x['gv'], 'pkt' => (int)$x['pkt'], 'val' => (float)$x['val']];
    $rows[$x['k']][$s] = $a;
    foreach ($a as $f => $v) { $tot[$s][$f] += $v; $tot['all'][$f] += $v; $rows[$x['k']]['all'][$f] = ($rows[$x['k']]['all'][$f] ?? 0) + $v; }
  }
  $out = ['rows' => $rows, 'tot' => $tot];
  if ($stamp !== null) {
    try { $d->prepare("INSERT INTO tms_meta(k,v,updated_at) VALUES(?,?,CURRENT_TIMESTAMP) ON CONFLICT(k) DO UPDATE SET v=EXCLUDED.v, updated_at=CURRENT_TIMESTAMP")->execute([$ck, json_encode(['stamp' => $stamp, 'out' => $out])]); } catch (Throwable $e) {}
  }
  return $out;
}

/** CSV export of the board (UTF-8 BOM for Excel). */
function prd_export_csv(PDO $d): void {
  $b = prd_build_board($d, prd_view_opts($_GET));
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="PRD_PENDING_PLAN_' . date('Ymd_Hi') . '.csv"');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
  fputcsv($o, ['Plan ID', 'Action', 'Source Site', 'Source Name', 'Destination / Store', 'Store Code (Master)', 'State', 'Cluster', 'Challan PKT', 'Qty', 'Value', 'Last Dispatch', 'Days Since Dispatch', 'Vehicle', 'Plan PKT', 'Fill %', 'Route km', 'Remarks', 'Flags', 'Planner Note', 'Challan Nos']);
  foreach ($b['groups'] as $g) {
    $p = $b['plans'][$g['plan_id']];
    fputcsv($o, [$p['id'], $p['type'], $g['source_site_code'], $g['source_label'], $g['destination_name'], $g['store']['code'] ?? '', $g['state'], $g['cluster_type'], $g['pkt'], round($g['qty'], 2), round($g['value'], 2), $g['last_dispatch'] ?? '', $g['gap_days'] ?? '',
      implode(' + ', array_map(fn($v) => $v['count'] . 'x ' . $v['type'], $p['vehicles'])), $p['pkt'], $p['fill'] ?? '', isset($p['route_km']) && $p['route_km'] ? round($p['route_km']) : '',
      $g['remark'] . ' | ' . $g['remark_sub'], implode('; ', array_map(fn($f) => $f[1], $g['flags'])), $g['note'], implode(' ', array_keys($g['challans']))]);
  }
  fclose($o);
}
