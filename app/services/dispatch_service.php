<?php
declare(strict_types=1);
/*
 * Vehicle placement workflow for a trip:
 *   Requested (transporter asked) → Confirmed (vehicle no. given) → dock allocated → Placed (vehicle at dock)
 *   or Failed (transporter did not place) / Cancelled (trip cancelled).
 * One open placement per trip; history is kept for the transporter scorecard.
 */

const DSP_OPEN = ['Requested', 'Confirmed', 'Placed'];
const DSP_FAIL_REASONS = ['Vehicle not reported', 'Reported late', 'Wrong vehicle type', 'Vehicle breakdown before loading', 'Rejected at fitness / document check', 'Transporter cancelled', 'Other'];
const PRD_HOLD_REASONS = ['Store audit', 'Road closed / route blocked', 'Refurbishment going on', 'Store temporarily closed', 'Space constraint at store', 'Festival / local holiday', 'Commercial / payment hold', 'Other'];

function dsp_transporters(PDO $d, bool $activeOnly = true): array {
  return $d->query("SELECT * FROM tms_transporters" . ($activeOnly ? " WHERE active" : "") . " ORDER BY lower(name)")->fetchAll(PDO::FETCH_ASSOC);
}
/** Keep an older system's transporter-name column in step with `name` (if the table had one). */
function dsp_tpt_sync_legacy(PDO $d, int $id): void {
  try {
    $c = $d->query("SELECT v FROM tms_meta WHERE k='tpt_legacy_name_col'")->fetchColumn();
    if ($c && $c !== 'name' && preg_match('/^[a-z_][a-z0-9_]*$/', (string)$c)) $d->prepare("UPDATE tms_transporters SET \"$c\"=name WHERE id=?")->execute([$id]);
  } catch (Throwable $e) {}
}
function dsp_docks(PDO $d, ?int $dcId = null, bool $activeOnly = true): array {
  $w = []; $p = [];
  if ($activeOnly) $w[] = 'k.active';
  if ($dcId) { $w[] = 'k.dc_id=?'; $p[] = $dcId; }
  $q = $d->prepare("SELECT k.*, dc.dc_code, dc.dc_name FROM tms_docks k JOIN tms_dc_master dc ON dc.id=k.dc_id" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY dc.dc_code, k.dock_code");
  $q->execute($p); return $q->fetchAll(PDO::FETCH_ASSOC);
}
function dsp_norm_vehicle_no(string $v): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v)); }
function dsp_hist(PDO $d, int $tripId, string $status, string $remarks): void {
  $d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)")->execute([$tripId, $status, $remarks, $_SESSION['user_id'] ?? null]);
}
function dsp_trip(PDO $d, int $tripId, bool $lock = true): array {
  $q = $d->prepare("SELECT * FROM trips WHERE id=?" . ($lock ? " FOR UPDATE" : "")); $q->execute([$tripId]);
  $t = $q->fetch(PDO::FETCH_ASSOC); if (!$t) throw new Exception('Trip not found.'); return $t;
}
function dsp_placement(PDO $d, int $pid, bool $lock = true): array {
  $q = $d->prepare("SELECT * FROM tms_vehicle_placements WHERE id=?" . ($lock ? " FOR UPDATE" : "")); $q->execute([$pid]);
  $p = $q->fetch(PDO::FETCH_ASSOC); if (!$p) throw new Exception('Vehicle request not found.'); return $p;
}
function dsp_transporter_name(PDO $d, int $id): string {
  $q = $d->prepare("SELECT name FROM tms_transporters WHERE id=? AND active"); $q->execute([$id]); $n = $q->fetchColumn();
  if ($n === false) throw new Exception('Transporter select karein (Masters → Transporters me active hona chahiye).'); return (string)$n;
}

/** 1. Ask a transporter for a vehicle. */
function dsp_request(PDO $d, int $tripId, int $tptId, string $vehType): int {
  $t = dsp_trip($d, $tripId);
  if (!in_array($t['status'], ['Planned', 'Assigned'], true)) throw new Exception("Trip {$t['trip_code']} is {$t['status']}; vehicle request sirf Planned trip ke liye.");
  $open = $d->prepare("SELECT id FROM tms_vehicle_placements WHERE trip_id=? AND status IN ('Requested','Confirmed','Placed')"); $open->execute([$tripId]);
  if ($open->fetchColumn()) throw new Exception("Trip {$t['trip_code']} ke liye vehicle request already open hai. Pehle use Failed / cancel karein.");
  $name = dsp_transporter_name($d, $tptId);
  $vehType = trim($vehType) !== '' ? trim($vehType) : (string)($t['vehicle_type'] ?? '');
  $q = $d->prepare("INSERT INTO tms_vehicle_placements(trip_id,transporter_id,vehicle_type,status,created_by) VALUES(?,?,?,'Requested',?) RETURNING id");
  $q->execute([$tripId, $tptId, $vehType ?: null, $_SESSION['user_id'] ?? null]); $pid = (int)$q->fetchColumn();
  $d->prepare("UPDATE trips SET placement_id=?, transporter_id=?, tpt_name=? WHERE id=?")->execute([$pid, $tptId, $name, $tripId]);
  dsp_hist($d, $tripId, 'Vehicle Requested', "Vehicle requested from $name" . ($vehType ? " ($vehType)" : ''));
  return $pid;
}

/** 2. Transporter confirmed: record vehicle number (added to Vehicle Master if new). */
function dsp_confirm(PDO $d, int $pid, string $vehicleNo, string $driverName = '', string $driverPhone = ''): void {
  $p = dsp_placement($d, $pid);
  if (!in_array($p['status'], ['Requested', 'Confirmed'], true)) throw new Exception("Request is {$p['status']}; vehicle number cannot be changed now.");
  $t = dsp_trip($d, (int)$p['trip_id']);
  $no = dsp_norm_vehicle_no($vehicleNo);
  if (strlen($no) < 6) throw new Exception('Valid vehicle number daalein, jaise HR55AB1234.');
  $vq = $d->prepare("SELECT * FROM vehicles WHERE upper(regexp_replace(vehicle_number,'[^A-Za-z0-9]','','g'))=? LIMIT 1"); $vq->execute([$no]); $v = $vq->fetch(PDO::FETCH_ASSOC);
  if (!$v) {
    $ins = $d->prepare("INSERT INTO vehicles(vehicle_number,vehicle_type,status,active,transporter_id) VALUES(?,?,'Available',TRUE,?) RETURNING *");
    $ins->execute([$no, $p['vehicle_type'] ?: null, (int)$p['transporter_id']]); $v = $ins->fetch(PDO::FETCH_ASSOC);
  } elseif (!$v['transporter_id']) {
    $d->prepare("UPDATE vehicles SET transporter_id=? WHERE id=?")->execute([(int)$p['transporter_id'], (int)$v['id']]);
  }
  $busy = $d->prepare("SELECT trip_code FROM trips WHERE vehicle_id=? AND id<>? AND status NOT IN ('Delivered','Cancelled') LIMIT 1"); $busy->execute([(int)$v['id'], (int)$t['id']]);
  if ($bt = $busy->fetchColumn()) throw new Exception("Vehicle $no already active trip $bt par hai.");
  if ((int)($t['vehicle_id'] ?? 0) && (int)$t['vehicle_id'] !== (int)$v['id']) $d->prepare("UPDATE vehicles SET status='Available' WHERE id=? AND status='Assigned'")->execute([(int)$t['vehicle_id']]);
  $d->prepare("UPDATE tms_vehicle_placements SET status='Confirmed', vehicle_no=?, vehicle_id=?, driver_name=NULLIF(?,''), driver_phone=NULLIF(?,''), confirmed_at=COALESCE(confirmed_at,CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP WHERE id=?")
    ->execute([$no, (int)$v['id'], trim($driverName), trim($driverPhone), $pid]);
  $d->prepare("UPDATE trips SET vehicle_id=?, status=CASE WHEN status='Planned' THEN 'Assigned' ELSE status END WHERE id=?")->execute([(int)$v['id'], (int)$t['id']]);
  $d->prepare("UPDATE vehicles SET status='Assigned' WHERE id=?")->execute([(int)$v['id']]);
  dsp_hist($d, (int)$t['id'], 'Vehicle Confirmed', "Transporter confirmed vehicle $no" . (trim($driverName) !== '' ? ", driver " . trim($driverName) : ''));
}

/** 3. Dock for loading. Throws if another active vehicle holds the dock, unless $force. */
function dsp_dock(PDO $d, int $pid, int $dockId, bool $force = false): void {
  $p = dsp_placement($d, $pid);
  if (!in_array($p['status'], ['Confirmed', 'Placed'], true)) throw new Exception('Dock sirf confirmed vehicle ko allot hota hai (pehle vehicle number daalein).');
  $t = dsp_trip($d, (int)$p['trip_id']);
  $k = $d->prepare("SELECT * FROM tms_docks WHERE id=? AND active"); $k->execute([$dockId]); $dock = $k->fetch(PDO::FETCH_ASSOC);
  if (!$dock) throw new Exception('Dock select karein.');
  if ((int)$dock['dc_id'] !== (int)$t['source_dc_id']) throw new Exception("Dock {$dock['dock_code']} is trip ke loading DC ka nahi hai.");
  if (!$force) {
    $c = $d->prepare("SELECT t.trip_code, vp.vehicle_no FROM tms_vehicle_placements vp JOIN trips t ON t.id=vp.trip_id WHERE vp.dock_id=? AND vp.id<>? AND vp.status IN ('Confirmed','Placed') AND t.status IN ('Planned','Assigned','Loaded') LIMIT 1");
    $c->execute([$dockId, $pid]);
    if ($x = $c->fetch(PDO::FETCH_ASSOC)) throw new Exception("Dock {$dock['dock_code']} par already {$x['vehicle_no']} ({$x['trip_code']}) loading ke liye hai. Doosra dock chunein ya “allot anyway” karein.");
  }
  $d->prepare("UPDATE tms_vehicle_placements SET dock_id=?, dock_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$dockId, $pid]);
  $d->prepare("UPDATE trips SET dock_id=? WHERE id=?")->execute([$dockId, (int)$t['id']]);
  dsp_hist($d, (int)$t['id'], 'Dock Allocated', "Dock {$dock['dock_code']} allocated to {$p['vehicle_no']}");
}

/** 4. Vehicle reported at the dock. */
function dsp_placed(PDO $d, int $pid): void {
  $p = dsp_placement($d, $pid);
  if ($p['status'] !== 'Confirmed') throw new Exception('Sirf confirmed vehicle ko placed mark kar sakte hain.');
  $d->prepare("UPDATE tms_vehicle_placements SET status='Placed', placed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$pid]);
  dsp_hist($d, (int)$p['trip_id'], 'Vehicle Placed', "Vehicle {$p['vehicle_no']} reported for loading");
}

/** 5. Transporter failed to place. Trip goes back to Planned so another transporter can be asked. */
function dsp_fail(PDO $d, int $pid, string $reason, string $remarks = ''): void {
  $p = dsp_placement($d, $pid);
  if (!in_array($p['status'], DSP_OPEN, true)) throw new Exception("Request already {$p['status']}.");
  if (!in_array($reason, DSP_FAIL_REASONS, true)) throw new Exception('Failure reason select karein.');
  if ($reason === 'Other' && trim($remarks) === '') throw new Exception('“Other” ke liye remarks likhein.');
  $t = dsp_trip($d, (int)$p['trip_id']);
  if (!in_array($t['status'], ['Planned', 'Assigned'], true)) throw new Exception("Trip {$t['trip_code']} is {$t['status']}; loaded trip ka vehicle fail nahi kar sakte.");
  $d->prepare("UPDATE tms_vehicle_placements SET status='Failed', failed_at=CURRENT_TIMESTAMP, fail_reason=?, remarks=NULLIF(?,''), updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$reason, trim($remarks), $pid]);
  if ($p['vehicle_id']) $d->prepare("UPDATE vehicles SET status='Available' WHERE id=? AND status='Assigned'")->execute([(int)$p['vehicle_id']]);
  $d->prepare("UPDATE trips SET vehicle_id=NULL, dock_id=NULL, placement_id=NULL, status='Planned' WHERE id=?")->execute([(int)$t['id']]);
  dsp_hist($d, (int)$t['id'], 'Vehicle Failed', "$reason" . (trim($remarks) !== '' ? ": " . trim($remarks) : '') . ($p['vehicle_no'] ? " ({$p['vehicle_no']})" : ''));
}

/** Cancel a trip that has not left. PRD challans return to PRD Pending; manual indents return to Planned. */
function dsp_cancel_trip(PDO $d, int $tripId, string $reason): void {
  $t = dsp_trip($d, $tripId);
  if (!in_array($t['status'], ['Planned', 'Assigned', 'Loaded'], true)) throw new Exception("Trip {$t['trip_code']} is {$t['status']} and cannot be cancelled here" . ($t['status'] === 'Gate Pass Created' ? ' (delete the gate pass first).' : '.'));
  if (trim($reason) === '') throw new Exception('Cancel reason likhein.');
  $d->prepare("UPDATE tms_vehicle_placements SET status='Cancelled', updated_at=CURRENT_TIMESTAMP WHERE trip_id=? AND status IN ('Requested','Confirmed','Placed')")->execute([$tripId]);
  if ($t['vehicle_id']) $d->prepare("UPDATE vehicles SET status='Available' WHERE id=? AND status='Assigned'")->execute([(int)$t['vehicle_id']]);
  if ($t['driver_id']) $d->prepare("UPDATE drivers SET status='Available' WHERE id=? AND status='Assigned'")->execute([(int)$t['driver_id']]);
  $d->prepare("UPDATE tms_indents SET status='Cancelled' WHERE trip_id=? AND indent_no LIKE 'PRD-T%'")->execute([$tripId]);
  $d->prepare("UPDATE tms_indents SET status='Planned', trip_id=NULL WHERE trip_id=? AND indent_no NOT LIKE 'PRD-T%'")->execute([$tripId]);
  $d->prepare("UPDATE tms_trip_stops SET status='Cancelled' WHERE trip_id=?")->execute([$tripId]);
  $d->prepare("UPDATE trips SET status='Cancelled', cancelled_at=CURRENT_TIMESTAMP, cancel_reason=?, dock_id=NULL WHERE id=?")->execute([trim($reason), $tripId]);
  dsp_hist($d, $tripId, 'Cancelled', 'Trip cancelled: ' . trim($reason));
}

/* ---------------- Store holds (PRD Pending) ---------------- */
function prd_store_key(string $dest): string { return strtolower(trim($dest)); }
function prd_active_holds(PDO $d): array {
  $o = [];
  try { foreach ($d->query("SELECT * FROM prd_store_holds WHERE released_at IS NULL AND (hold_until IS NULL OR hold_until >= CURRENT_DATE) ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) as $h) if (!isset($o[$h['store_key']])) $o[$h['store_key']] = $h; } catch (Throwable $e) {}
  return $o;
}
function prd_hold_store(PDO $d, string $dest, string $reason, string $remarks, ?string $until): void {
  if (!in_array($reason, PRD_HOLD_REASONS, true)) throw new Exception('Hold reason select karein.');
  if ($reason === 'Other' && trim($remarks) === '') throw new Exception('“Other” ke liye remarks likhein.');
  if ($until !== null && $until !== '' && strtotime($until) < strtotime(date('Y-m-d'))) throw new Exception('Hold-until date aaj ya aage ki honi chahiye.');
  $k = prd_store_key($dest); if ($k === '') throw new Exception('Store missing.');
  $d->prepare("UPDATE prd_store_holds SET released_at=CURRENT_TIMESTAMP, released_by=? WHERE store_key=? AND released_at IS NULL")->execute([$_SESSION['user_id'] ?? null, $k]);
  $d->prepare("INSERT INTO prd_store_holds(store_key,store_label,reason,remarks,hold_until,created_by) VALUES(?,?,?,NULLIF(?,''),?,?)")->execute([$k, trim($dest), $reason, trim($remarks), $until ?: null, $_SESSION['user_id'] ?? null]);
}
function prd_release_store(PDO $d, string $dest): void {
  $d->prepare("UPDATE prd_store_holds SET released_at=CURRENT_TIMESTAMP, released_by=? WHERE store_key=? AND released_at IS NULL")->execute([$_SESSION['user_id'] ?? null, prd_store_key($dest)]);
}
