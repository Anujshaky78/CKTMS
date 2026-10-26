<?php
declare(strict_types=1);
require_once __DIR__ . '/trip_service.php';
/*
 * Manual entry behind the "Create Indent" and "Create Trip" dialogs (Indents / Trips tabs).
 * Plans (PRD) stay the main way to make work; these two cover loads that are not in PRD Pending.
 *   manual_indent_create(): one 'Planned' indent per destination store, with vehicle type / route / transporter / expiry / reporting time / comment.
 *   manual_trip_create():   the indents (already 'Trip Created'), the trip, its stops and pickup, and optionally transporter + vehicle + driver in one go.
 */

/** Destination rows from the dialog: [['store_id'=>, 'code'=>, 'pkt'=>, 'value'=>], …] (empty rows ignored, 1..$max stores, no repeats). */
function manual_rows(PDO $d, array $codes, array $pkts, array $vals, int $max): array {
  $find = $d->prepare("SELECT id, store_code FROM stores WHERE upper(store_code)=upper(?) AND COALESCE(active,true)"); $rows = []; $seen = [];
  foreach ($codes as $i => $c) {
    $c = trim((string)$c); $pkt = (int)($pkts[$i] ?? 0); $val = (float)str_replace([',', '₹', ' '], '', (string)($vals[$i] ?? 0));
    if ($c === '' && $pkt === 0 && $val == 0) continue;
    $find->execute([$c]); $s = $find->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new Exception('Row ' . ($i + 1) . ': store "' . $c . '" is not an active store.');
    if (isset($seen[$s['id']])) throw new Exception('Store ' . $s['store_code'] . ' is repeated.');
    if ($pkt <= 0 || $val <= 0) throw new Exception('Store ' . $s['store_code'] . ': packets and value are required.');
    $seen[$s['id']] = 1; $rows[] = ['store_id' => (int)$s['id'], 'code' => $s['store_code'], 'pkt' => $pkt, 'value' => $val];
  }
  if (!$rows) throw new Exception('Add at least one destination store.');
  if (count($rows) > $max) throw new Exception('At most ' . $max . ' stores in one trip.');
  return $rows;
}

function manual_dt(?string $s): ?string {
  $s = trim((string)$s); if ($s === '') return null;
  $t = strtotime($s); if ($t === false) throw new Exception('Date/time "' . $s . '" is not valid.');
  return date('Y-m-d H:i:s', $t);
}

/** @return array{count:int,nos:array} */
function manual_indent_create(PDO $d, array $in, ?int $userId): array {
  $dcId = (int)($in['dc_id'] ?? 0);
  $q = $d->prepare("SELECT 1 FROM tms_dc_master WHERE id=? AND COALESCE(active,true)"); $q->execute([$dcId]); if (!$q->fetchColumn()) throw new Exception('Choose the source location (DC).');
  $rows = manual_rows($d, (array)($in['store'] ?? []), (array)($in['pkt'] ?? []), (array)($in['value'] ?? []), tms_trip_max_stores());
  tms_trip_check(count($rows), array_sum(array_column($rows, 'pkt')), 'Indent');
  $expiry = manual_dt($in['expiry_at'] ?? null); $report = manual_dt($in['reporting_at'] ?? null);
  if ($expiry && $report && strtotime($report) > strtotime($expiry)) throw new Exception('Vehicle reporting time must be before the indent expiry time.');
  $vt = trim((string)($in['vehicle_type'] ?? '')); $route = (int)($in['route_id'] ?? 0) ?: null; $tpt = (int)($in['transporter_id'] ?? 0) ?: null; $note = trim((string)($in['notes'] ?? ''));
  $own = !$d->inTransaction(); if ($own) $d->beginTransaction();
  try {
    $ins = $d->prepare("INSERT INTO tms_indents(indent_no,indent_date,source_dc_id,destination_store_id,packet_count,indent_value,status,created_by,vehicle_type,route_id,transporter_id,expiry_at,reporting_at,notes) VALUES(?,?,?,?,?,?,'Planned',?,NULLIF(?,''),?,?,?,?,NULLIF(?,''))");
    $base = 'IND-' . date('ymd-His') . '-' . mt_rand(100, 999); $nos = [];
    foreach ($rows as $n => $r) { $no = $base . '-' . ($n + 1); $ins->execute([$no, date('Y-m-d'), $dcId, $r['store_id'], $r['pkt'], $r['value'], $userId, $vt, $route, $tpt, $expiry, $report, $note]); $nos[] = $no; }
    if ($own) $d->commit();
    return ['count' => count($nos), 'nos' => $nos];
  } catch (Throwable $e) { if ($own && $d->inTransaction()) $d->rollBack(); throw $e; }
}

/** @return array{id:int,code:string,assigned:bool} */
function manual_trip_create(PDO $d, array $in, ?int $userId): array {
  $dcId = (int)($in['dc_id'] ?? 0);
  $q = $d->prepare("SELECT 1 FROM tms_dc_master WHERE id=? AND COALESCE(active,true)"); $q->execute([$dcId]); if (!$q->fetchColumn()) throw new Exception('Choose the source location (DC).');
  $rows = manual_rows($d, (array)($in['store'] ?? []), (array)($in['pkt'] ?? []), (array)($in['value'] ?? []), tms_trip_max_stores());
  $pkt = (int)array_sum(array_column($rows, 'pkt')); $val = (float)array_sum(array_column($rows, 'value'));
  tms_trip_check(count($rows), $pkt, 'Trip');
  $vehicle = (int)($in['vehicle_id'] ?? 0); $driver = (int)($in['driver_id'] ?? 0); $tptId = (int)($in['transporter_id'] ?? 0);
  if (($vehicle > 0) !== ($driver > 0)) throw new Exception('Choose both a vehicle and a driver, or neither (assign later with Accept).');
  $start = manual_dt($in['start_at'] ?? null) ?? date('Y-m-d H:i:s');
  $own = !$d->inTransaction(); if ($own) $d->beginTransaction();
  try {
    $tptName = null; $v = null; $dr = null;
    if ($tptId) { $t = $d->prepare("SELECT COALESCE(NULLIF(name,''),transporter_name) n FROM tms_transporters WHERE id=? AND active"); $t->execute([$tptId]); $tptName = $t->fetchColumn(); if ($tptName === false) throw new Exception('Transporter not found or inactive.'); }
    if ($vehicle) {
      $vq = $d->prepare("SELECT * FROM vehicles WHERE id=? AND active IS TRUE FOR UPDATE"); $vq->execute([$vehicle]); $v = $vq->fetch(PDO::FETCH_ASSOC); if (!$v) throw new Exception('Vehicle not found or inactive.');
      $dq = $d->prepare("SELECT * FROM drivers WHERE id=? AND active IS TRUE FOR UPDATE"); $dq->execute([$driver]); $dr = $dq->fetch(PDO::FETCH_ASSOC); if (!$dr) throw new Exception('Driver not found or inactive.');
      if ($tptName && trim((string)($dr['tpt_name'] ?? '')) !== '' && strcasecmp($tptName, (string)$dr['tpt_name']) !== 0) throw new Exception('The driver belongs to another transporter.');
      if ($tptId && !empty($v['transporter_id']) && (int)$v['transporter_id'] !== $tptId) throw new Exception('The vehicle belongs to another transporter.');
      foreach ([['vehicle_id', $vehicle, 'vehicle'], ['driver_id', $driver, 'driver']] as [$col, $id, $what]) {
        $b = $d->prepare("SELECT trip_code FROM trips WHERE $col=? AND status NOT IN ('Delivered','Cancelled') LIMIT 1"); $b->execute([$id]);
        if ($b->fetchColumn()) throw new Exception("Selected $what is already on another active trip.");
      }
      if ($tptName === null && trim((string)($dr['tpt_name'] ?? '')) !== '') $tptName = (string)$dr['tpt_name'];
    }
    [$newId, $code] = tms_next_trip($d);
    $tripId = insert_trip_compat($d, $code, $pkt, $val, substr($start, 0, 10), $dcId, $rows[0]['store_id'], $newId);
    $stop = $d->prepare("INSERT INTO tms_trip_stops(trip_id,sequence_no,store_id,packet_count,stop_value,status,expected_delivery_at) VALUES(?,?,?,?,?,'Planned',NULL)");
    $ind = $d->prepare("INSERT INTO tms_indents(indent_no,indent_date,source_dc_id,destination_store_id,packet_count,indent_value,status,trip_id,created_by,vehicle_type,transporter_id,notes) VALUES(?,?,?,?,?,?,'Trip Created',?,?,NULLIF(?,''),?,NULLIF(?,''))");
    $vt = trim((string)($in['vehicle_type'] ?? '')) ?: (string)($v['vehicle_type'] ?? ''); $note = trim((string)($in['notes'] ?? ''));
    foreach ($rows as $i => $r) {
      $stop->execute([$tripId, $i + 1, $r['store_id'], $r['pkt'], $r['value']]);
      $ind->execute(['MAN-T' . $tripId . '-' . ($i + 1), substr($start, 0, 10), $dcId, $r['store_id'], $r['pkt'], $r['value'], $tripId, $userId, $vt, $tptId ?: null, $note]);
    }
    $d->prepare("INSERT INTO tms_trip_pickups(trip_id,sequence_no,dc_id,packet_count,pickup_value) VALUES(?,?,?,?,?)")->execute([$tripId, 1, $dcId, $pkt, $val]);
    if ($vt !== '') $d->prepare("UPDATE trips SET vehicle_type=? WHERE id=?")->execute([$vt, $tripId]); // the column is NOT NULL: keep the default when no type is known
    $d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)")->execute([$tripId, 'Planned', 'Created by hand: ' . count($rows) . ' store(s), ' . $pkt . ' PKT' . ($note !== '' ? ' · ' . $note : ''), $userId]);
    if ($vehicle) {
      $d->prepare("UPDATE trips SET tpt_name=?,transporter_id=?,vehicle_id=?,driver_id=?,status='Assigned' WHERE id=?")->execute([$tptName, $tptId ?: null, $vehicle, $driver, $tripId]);
      $d->prepare("UPDATE vehicles SET status='Assigned' WHERE id=?")->execute([$vehicle]);
      $d->prepare("UPDATE drivers SET status='Assigned' WHERE id=?")->execute([$driver]);
      $d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)")->execute([$tripId, 'Assigned', 'Transporter / vehicle / driver chosen while creating the trip', $userId]);
    }
    if ($own) $d->commit();
    return ['id' => $tripId, 'code' => $code, 'assigned' => (bool)$vehicle];
  } catch (Throwable $e) { if ($own && $d->inTransaction()) $d->rollBack(); throw $e; }
}
