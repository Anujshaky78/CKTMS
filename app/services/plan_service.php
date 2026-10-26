<?php
declare(strict_types=1);
require_once __DIR__ . '/prd_planner.php';
require_once __DIR__ . '/trip_service.php';
/*
 * Plans.
 *   1. PRD Pending: tick stores -> plan_create() makes ONE plan (tms_plans) with one line per store and source (tms_plan_lines).
 *      The challans of each line are reserved (prd_pending.plan_line_id), so they leave the PRD board.
 *      The sites in the plan are the ones the board was showing: one site selected -> that site only, both -> both (separate lines per source).
 *   2. Indents tab: pending plans -> plan_preview() groups the stores into trips (max stores / max PKT from config, nearest stores first,
 *      shortest route; stores without coordinates only join the same cluster) -> plan_create_trips() creates them.
 * Line status: Pending -> Trip Created (all its challans are on trips) | Closed (nothing left, dispatched at the source) | Cancelled.
 * Plan status: Pending | Partly | Trips Created | Cancelled.
 */

/** @return array{0:int,1:string} [id, plan_no] */
function plan_next(PDO $d): array {
  $id = (int)$d->query("SELECT nextval(pg_get_serial_sequence('tms_plans','id'))")->fetchColumn();
  return [$id, 'PLN-' . date('ymd') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT)];
}

function plan_pg_array(array $ids): string { return '{' . implode(',', array_map('intval', $ids)) . '}'; }

/** Recomputes the plan status from its lines. */
function plan_status_refresh(PDO $d, int $planId): string {
  $q = $d->prepare("SELECT status, COUNT(*) FROM tms_plan_lines WHERE plan_id=? GROUP BY 1"); $q->execute([$planId]);
  $c = $q->fetchAll(PDO::FETCH_KEY_PAIR);
  $pending = (int)($c['Pending'] ?? 0); $done = (int)($c['Trip Created'] ?? 0) + (int)($c['Closed'] ?? 0); $canc = (int)($c['Cancelled'] ?? 0);
  $st = $pending > 0 ? ($done > 0 ? 'Partly' : 'Pending') : ($done > 0 ? 'Trips Created' : 'Cancelled');
  $d->prepare("UPDATE tms_plans SET status=? WHERE id=?")->execute([$st, $planId]);
  return $st;
}

/**
 * One plan from the ticked store groups ($keys = group keys of the PRD board rows). Stores that cannot be planned (not in Store Master,
 * closed, on hold) are left out and reported in 'skipped'; the plan is made with the rest.
 * @return array{id:int,plan_no:string,stores:int,pkt:int,value:float,scope:string,skipped:array}
 */
function plan_create(PDO $d, array $keys, ?int $userId): array {
  $keys = array_values(array_unique(array_filter(array_map('strval', $keys), fn($k) => (bool)preg_match('/^[a-f0-9]{32}$/', $k))));
  if (!$keys) throw new Exception('Kam se kam ek store tick karein.');
  $own = !$d->inTransaction(); if ($own) $d->beginTransaction();
  try {
    $groups = prd_enrich($d, prd_load_groups($d, $keys));
    $holds = prd_active_holds($d); $ok = []; $skipped = [];
    $gone = count(array_filter($keys, fn($k) => !isset($groups[$k])));
    if ($gone) $skipped[] = ['name' => $gone . ' store(s)', 'reason' => 'ab pending nahi hain (doosre plan/trip me gaye ya source me dispatch ho gaye); page refresh karein'];
    foreach ($groups as $k => $g) {
      $n = $g['destination_name'];
      if (!$g['store']) $skipped[] = ['name' => $n, 'reason' => 'Store Master me nahi hai'];
      elseif ($g['closed']) $skipped[] = ['name' => $n, 'reason' => 'store closed hai (' . $g['store_status'] . ')'];
      elseif (isset($holds[prd_store_key($n)])) $skipped[] = ['name' => $n, 'reason' => 'hold par hai (' . $holds[prd_store_key($n)]['reason'] . ')'];
      elseif (!$g['units']) $skipped[] = ['name' => $n, 'reason' => 'koi challan nahi mila'];
      else $ok[$k] = $g;
    }
    if (!$ok) throw new Exception('Koi store plan me nahi ja saka: ' . implode('; ', array_map(fn($s) => $s['name'] . ' - ' . $s['reason'], $skipped)));
    $scope = implode('+', array_values(array_unique(array_map(fn($g) => (string)$g['source_site_code'], $ok))));
    [$pid, $no] = plan_next($d);
    $d->prepare("INSERT INTO tms_plans(id,plan_no,created_by,scope,status) VALUES(?,?,?,?,'Pending')")->execute([$pid, $no, $userId, $scope]);
    $insL = $d->prepare("INSERT INTO tms_plan_lines(plan_id,source_site_code,source_name,gkey,store_id,store_code,store_name,destination_name,cluster,state,pkt,qty,value,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending') RETURNING id");
    $lock = $d->prepare("SELECT id FROM prd_pending p WHERE p.id = ANY(?::bigint[]) AND " . prd_pending_where('p') . " FOR UPDATE");
    $res = $d->prepare("UPDATE prd_pending SET plan_id=?, plan_line_id=? WHERE id = ANY(?::bigint[])");
    $pkt = 0; $val = 0.0;
    foreach ($ok as $k => $g) {
      $arr = plan_pg_array($g['ids']);
      $lock->execute([$arr]);
      if (count($lock->fetchAll(PDO::FETCH_COLUMN)) !== count($g['ids'])) throw new Exception('Store ' . $g['destination_name'] . ' ke challan abhi kisi aur ne le liye. Page refresh karein.');
      $insL->execute([$pid, $g['source_site_code'], $g['source_label'], $k, (int)$g['store']['id'], $g['store']['code'], $g['store']['name'], $g['destination_name'],
        trim((string)$g['cluster_type']) !== '' ? $g['cluster_type'] : '-', $g['state'], (int)$g['pkt'], round((float)$g['qty'], 2), round((float)$g['value'], 2)]);
      $lineId = (int)$insL->fetchColumn();
      $res->execute([$pid, $lineId, $arr]);
      $pkt += (int)$g['pkt']; $val += (float)$g['value'];
    }
    if ($own) $d->commit();
    prd_groups_cache_clear();
    return ['id' => $pid, 'plan_no' => $no, 'stores' => count($ok), 'pkt' => $pkt, 'value' => round($val, 2), 'scope' => $scope, 'skipped' => $skipped];
  } catch (Throwable $e) { if ($own && $d->inTransaction()) $d->rollBack(); throw $e; }
}

/** Cancels a pending plan (or one pending line of it): its challans go back to the PRD board. @return int lines cancelled */
function plan_cancel(PDO $d, int $planId, ?int $lineId = null): int {
  $own = !$d->inTransaction(); if ($own) $d->beginTransaction();
  try {
    $q = $d->prepare("SELECT id FROM tms_plan_lines WHERE plan_id=? AND status='Pending'" . ($lineId ? ' AND id=?' : '') . " FOR UPDATE");
    $q->execute($lineId ? [$planId, $lineId] : [$planId]); $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) throw new Exception('Is plan me cancel karne layak pending line nahi hai.');
    $d->prepare("UPDATE prd_pending SET plan_id=NULL, plan_line_id=NULL WHERE plan_line_id = ANY(?::int[]) AND planned_trip_id IS NULL")->execute([plan_pg_array($ids)]);
    $d->prepare("UPDATE tms_plan_lines SET status='Cancelled' WHERE id = ANY(?::int[])")->execute([plan_pg_array($ids)]);
    plan_status_refresh($d, $planId);
    if ($own) $d->commit();
    prd_groups_cache_clear();
    return count($ids);
  } catch (Throwable $e) { if ($own && $d->inTransaction()) $d->rollBack(); throw $e; }
}

/**
 * Preview of the trips for the pending lines of these plans (nothing is written). 'sig' identifies the proposal so that confirming
 * cannot create something different from what was shown.
 */
function plan_preview(PDO $d, array $planIds): array {
  $planIds = array_values(array_unique(array_map('intval', $planIds)));
  if (!$planIds) throw new Exception('Koi plan select nahi hua.');
  $q = $d->prepare("SELECT l.*, p.plan_no FROM tms_plan_lines l JOIN tms_plans p ON p.id=l.plan_id WHERE l.plan_id = ANY(?::int[]) AND l.status='Pending' ORDER BY l.plan_id, l.id");
  $q->execute([plan_pg_array($planIds)]); $lines = $q->fetchAll(PDO::FETCH_ASSOC);
  if (!$lines) throw new Exception('Select kiye plans me pending store nahi hai.');
  $lineIds = array_map(fn($l) => (int)$l['id'], $lines);
  $keys = array_values(array_unique(array_column($lines, 'gkey')));
  $sel = prd_plan_selection($d, $keys, 'separate', $lineIds);
  // PKT of a store that the plan holds but that is no longer pending at the source (dispatched meanwhile)
  $inc = []; foreach ($sel['loads'] as $l) foreach ($l['stops'] as $s) foreach ($s['parts'] as $pt) $inc[$pt['gkey']] = ($inc[$pt['gkey']] ?? 0) + (int)$pt['pkt'];
  $excl = []; foreach ($sel['excluded'] as $x) $excl[$x['gkey']] = true;
  $want = []; foreach ($lines as $l) $want[$l['gkey']] = ($want[$l['gkey']] ?? 0) + (int)$l['pkt'];
  $gone = 0; foreach ($want as $gk => $n) if (!isset($excl[$gk])) $gone += max(0, $n - ($inc[$gk] ?? 0));
  $sig = md5(json_encode(array_map(fn($l) => array_map(fn($s) => [$s['code'], $s['pkt'], $s['part']], $l['stops']), $sel['loads'])) . '|' . implode(',', $lineIds));
  $planNos = array_values(array_unique(array_column($lines, 'plan_no')));
  return $sel + ['lines' => $lines, 'line_ids' => $lineIds, 'plan_ids' => $planIds, 'plan_nos' => $planNos, 'sig' => $sig, 'gone_pkt' => $gone, 'plan_pkt' => array_sum($want), 'trip_pkt' => array_sum($inc)];
}

/**
 * Creates the trips shown by plan_preview() (same plans, same $sig), one transaction. $vehicles = vehicle type per load (index = load number).
 * @return array{trips:array,lines_done:int}
 */
function plan_create_trips(PDO $d, array $planIds, string $sig, array $vehicles = []): array {
  $own = !$d->inTransaction(); if ($own) $d->beginTransaction();
  try {
    $pv = plan_preview($d, $planIds);
    if (!hash_equals($pv['sig'], $sig)) throw new Exception('Plan ya challan data badal gaya. Preview dobara dekh kar confirm karein.');
    if (!$pv['loads']) throw new Exception('In plans se koi trip nahi ban sakta' . ($pv['excluded'] ? ': ' . implode('; ', array_map(fn($x) => $x['name'] . ' - ' . $x['reason'], $pv['excluded'])) : '.'));
    if (count($pv['loads']) > 60) throw new Exception('Ek baar me maximum 60 trips.');
    $made = []; $tripsByG = [];
    foreach ($pv['loads'] as $i => $l) {
      if (empty($l['dc'])) throw new Exception('Trip ' . ($i + 1) . ': source ' . $l['source'] . ' Center DC se linked nahi hai.');
      $stops = array_map(fn($s) => ['parts' => array_map(fn($p) => ['gkey' => $p['gkey'], 'pkts' => $p['pkts']], $s['parts'])], $l['stops']);
      try { $m = prd_create_trip_tx($d, $stops, (int)$l['dc']['id'], (string)($vehicles[$i] ?? $l['vehicle']), false, $pv['line_ids']); }
      catch (Throwable $x) { throw new Exception('Trip ' . ($i + 1) . ': ' . $x->getMessage()); }
      $made[] = $m;
      foreach ($l['stops'] as $s) foreach ($s['parts'] as $p) $tripsByG[$p['gkey']][$m['code']] = true;
    }
    // lines: Trip Created when nothing of them is left to carry; Closed when nothing is left at all (dispatched at the source)
    $left = $d->prepare("SELECT COUNT(*) FROM prd_pending p WHERE p.plan_line_id=? AND " . prd_pending_where('p', true) . " AND p.synced_at >= (SELECT MAX(x.synced_at) FROM prd_pending x WHERE x.source_system='ORACLE')");
    $upd = $d->prepare("UPDATE tms_plan_lines SET status=?, trips=? WHERE id=?");
    $excl = []; foreach ($pv['excluded'] as $x) $excl[$x['gkey']] = true;
    $done = 0; $plans = [];
    foreach ($pv['lines'] as $ln) {
      $plans[(int)$ln['plan_id']] = true;
      $left->execute([(int)$ln['id']]); $rest = (int)$left->fetchColumn();
      $codes = array_keys($tripsByG[$ln['gkey']] ?? []);
      if ($rest === 0 && $codes) { $upd->execute(['Trip Created', implode(', ', $codes), (int)$ln['id']]); $done++; }
      elseif ($rest === 0 && !isset($excl[$ln['gkey']])) { $upd->execute(['Closed', null, (int)$ln['id']]); $done++; }
    }
    foreach (array_keys($plans) as $pid) plan_status_refresh($d, $pid);
    if ($own) $d->commit();
    prd_groups_cache_clear();
    return ['trips' => $made, 'lines_done' => $done];
  } catch (Throwable $e) { if ($own && $d->inTransaction()) $d->rollBack(); throw $e; }
}
