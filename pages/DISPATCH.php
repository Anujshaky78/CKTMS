<?php
/* Dispatch desk: request vehicle → transporter confirms vehicle no. → dock → placed (or failed); cancel trip. */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
require_once TMS_ROOT . '/app/services/prd_planner.php';
$d = db();
$err = '';
$dcFilter = (int)($_GET['dc'] ?? 0);
$back = 'DISPATCH.php' . ($dcFilter ? '?dc=' . $dcFilter : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    verify_csrf(); require_perm('plan');
    $a = (string)($_POST['action'] ?? '');
    $d->beginTransaction();
    switch ($a) {
      case 'request': dsp_request($d, (int)$_POST['trip_id'], (int)$_POST['transporter_id'], (string)($_POST['vehicle_type'] ?? '')); $m = 'Vehicle requested.'; break;
      case 'confirm': dsp_confirm($d, (int)$_POST['pid'], (string)$_POST['vehicle_no'], (string)($_POST['driver_name'] ?? ''), (string)($_POST['driver_phone'] ?? '')); $m = 'Vehicle number saved.'; break;
      case 'dock': dsp_dock($d, (int)$_POST['pid'], (int)$_POST['dock_id'], !empty($_POST['force'])); $m = 'Dock allocated.'; break;
      case 'placed': dsp_placed($d, (int)$_POST['pid']); $m = 'Vehicle marked as placed at dock.'; break;
      case 'fail': dsp_fail($d, (int)$_POST['pid'], (string)$_POST['reason'], (string)($_POST['remarks'] ?? '')); $m = 'Vehicle marked failed. Trip is back to “needs vehicle”.'; break;
      case 'cancel': dsp_cancel_trip($d, (int)$_POST['trip_id'], (string)$_POST['reason']); $m = 'Trip cancelled. Its PRD challans are back on PRD Pending.'; break;
      default: throw new Exception('Unknown action.');
    }
    $d->commit(); setflash($m); redirect($back . '#t' . (int)($_POST['trip_id'] ?? 0));
  } catch (Throwable $x) { if ($d->inTransaction()) $d->rollBack(); $err = tms_friendly_error($x); }
}

$dcs = $d->query("SELECT id,dc_code,dc_name FROM tms_dc_master WHERE COALESCE(active,TRUE) ORDER BY dc_code")->fetchAll(PDO::FETCH_ASSOC);
$tpts = dsp_transporters($d);
$docks = dsp_docks($d);
$docksByDc = []; foreach ($docks as $k) $docksByDc[(int)$k['dc_id']][] = $k;
$vclasses = prd_vehicle_classes($d); $vtypes = array_column($vclasses, 'type');
// Default vehicle type for a request: the trip's own type if valid, else the smallest vehicle that fits its PKT
$defType = function (array $t) use ($vtypes, $vclasses) { if (in_array($t['vehicle_type'], $vtypes, true)) return $t['vehicle_type']; $v = prd_pick_vehicle($vclasses, (int)$t['total_boxes'], (float)$t['total_value']) ?? end($vclasses); return $v['type'] ?? ''; };
$w = "t.status IN ('Planned','Assigned','Loaded','Gate Pass Created')"; $p = [];
if ($dcFilter) { $w .= " AND (t.source_dc_id=? OR EXISTS (SELECT 1 FROM tms_trip_pickups pk WHERE pk.trip_id=t.id AND pk.dc_id=?))"; $p = [$dcFilter, $dcFilter]; }
$q = $d->prepare("SELECT t.id,t.trip_code,t.status,t.total_boxes,t.total_value,t.planned_date,t.vehicle_type,t.is_joint,t.source_dc_id,dc.dc_code,
    vp.id pid,vp.status vp_status,vp.vehicle_no,vp.driver_name,vp.driver_phone,vp.requested_at,vp.confirmed_at,vp.placed_at,vp.vehicle_type vp_type,
    tp.name tpt,k.dock_code,k.id dock_id,
    (SELECT string_agg(s.store_code,' → ' ORDER BY ts.sequence_no) FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id WHERE ts.trip_id=t.id) route,
    (SELECT string_agg(d2.dc_code,' → ' ORDER BY pk.sequence_no) FROM tms_trip_pickups pk JOIN tms_dc_master d2 ON d2.id=pk.dc_id WHERE pk.trip_id=t.id) pickups,
    (SELECT COUNT(*) FROM tms_vehicle_placements f WHERE f.trip_id=t.id AND f.status='Failed') fails
  FROM trips t LEFT JOIN tms_dc_master dc ON dc.id=t.source_dc_id
  LEFT JOIN tms_vehicle_placements vp ON vp.id=t.placement_id AND vp.status IN ('Requested','Confirmed','Placed')
  LEFT JOIN tms_transporters tp ON tp.id=vp.transporter_id LEFT JOIN tms_docks k ON k.id=vp.dock_id
  WHERE $w ORDER BY CASE WHEN vp.id IS NULL AND t.status='Planned' THEN 0 WHEN vp.status='Requested' THEN 1 WHEN vp.status='Confirmed' THEN 2 ELSE 3 END, t.id");
$q->execute($p); $trips = $q->fetchAll(PDO::FETCH_ASSOC);
$cnt = ['need' => 0, 'Requested' => 0, 'Confirmed' => 0, 'docked' => 0, 'Placed' => 0];
foreach ($trips as $t) { if (!$t['pid'] && $t['status'] === 'Planned') $cnt['need']++; elseif ($t['vp_status']) { $cnt[$t['vp_status']]++; if ($t['dock_id'] && $t['vp_status'] === 'Confirmed') $cnt['docked']++; } }
$failedToday = (int)$d->query("SELECT COUNT(*) FROM tms_vehicle_placements WHERE status='Failed' AND failed_at::date=CURRENT_DATE")->fetchColumn();
$ago = function ($ts) { if (!$ts) return ''; $m = (int)round((time() - strtotime((string)$ts)) / 60); return $m < 60 ? $m . ' min ago' : ($m < 1440 ? round($m / 60) . ' h ago' : round($m / 1440) . ' d ago'); };
$tok = csrf();

head('Dispatch desk');
?>
<style>
.dd-bar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:12px}
.dd-sum{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.dd-sum div{background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px 10px;font-size:12px;color:#344054;display:flex;gap:6px;align-items:baseline}
.dd-sum b{font-size:14px;font-variant-numeric:tabular-nums;color:var(--ink)}
.dd-sum .warn{background:var(--red-soft);border-color:#fecdca}.dd-sum .warn b,.dd-sum .warn span{color:var(--red)}
.dd-t td{vertical-align:top;white-space:normal}
.dd-t .route{max-width:260px}
.dd-act{display:flex;flex-direction:column;gap:6px;min-width:300px}
.dd-act form{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.dd-act .input,.dd-act .select{height:30px;min-width:0;padding:4px 8px;font-size:12px}
.dd-act .btn{padding:5px 10px;font-size:12px}
.dd-act details{font-size:12px}.dd-act summary{cursor:pointer;color:var(--muted)}
.dd-act details form{margin-top:6px}
.st{display:inline-block;font-size:11px;font-weight:700;border-radius:999px;padding:2px 8px}
.st-need{background:var(--red-soft);color:var(--red)}.st-Requested{background:var(--amber-soft);color:var(--amber)}.st-Confirmed{background:var(--blue-soft);color:var(--blue)}.st-Placed{background:var(--green-soft);color:var(--green)}
.dd-small{font-size:11px;color:var(--muted)}

</style>
<?php flash(); if ($err): ?><div class="alert alert-err" role="alert"><?= e($err) ?></div><?php endif; ?>
<div class="dd-bar">
  <form method="get" class="toolbar" style="margin:0"><select class="select" name="dc" onchange="this.form.submit()"><option value="0">All warehouses</option><?php foreach ($dcs as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $dcFilter === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['dc_code'] . ' · ' . $x['dc_name']) ?></option><?php endforeach; ?></select></form>
  <div class="toolbar" style="margin:0">
    <a class="btn light" href="PRINT_DOCK.php<?= $dcFilter ? '?dc=' . $dcFilter : '' ?>" target="_blank" data-no-busy>Print dock plan</a>
    <a class="btn light" href="SCORECARD.php">Transporter scorecard</a>
    <a class="btn light" href="MASTER_OPS.php?tab=docks">Docks &amp; transporters</a>
  </div>
</div>
<?php if (!$tpts || !$docks): ?><div class="alert">Before using the desk: add <?= !$tpts ? '<a href="MASTER_OPS.php?tab=transporters">transporters</a>' : '' ?><?= !$tpts && !$docks ? ' and ' : '' ?><?= !$docks ? '<a href="MASTER_OPS.php?tab=docks">loading docks</a>' : '' ?> in Masters.</div><?php endif; ?>
<?= tms_stats([['need a vehicle', $cnt['need'], $cnt['need'] ? 'bad' : ''], ['waiting for transporter', $cnt['Requested'], $cnt['Requested'] ? 'warn' : ''], ['vehicle confirmed', $cnt['Confirmed']], ['with dock', $cnt['docked']], ['placed at dock', $cnt['Placed'], 'good'], ['failed today', $failedToday, $failedToday ? 'bad' : '']]) ?>
<div class="tablewrap"><table class="table dd-t">
<tr><th>Trip</th><th>Loading</th><th>Route</th><th class="right">PKT</th><th>Vehicle</th><th>Status</th><th>Next step</th></tr>
<?php foreach ($trips as $t): $state = $t['vp_status'] ?: ($t['status'] === 'Planned' ? 'need' : ''); $dockList = $docksByDc[(int)$t['source_dc_id']] ?? []; ?>
<tr id="t<?= (int)$t['id'] ?>">
  <td><a href="PLANNING.php?tab=trips&amp;view=<?= (int)$t['id'] ?>"><b><?= e($t['trip_code']) ?></b></a><div class="dd-small"><?= e($t['status']) ?><?= $t['fails'] ? ' · ' . (int)$t['fails'] . ' failed before' : '' ?></div></td>
  <td><?= e($t['pickups'] ?: $t['dc_code']) ?><?= $t['is_joint'] ? '<div><span class="badge b-blue">Joint</span></div>' : '' ?></td>
  <td class="route"><?= e($t['route'] ?: '—') ?></td>
  <td class="right"><?= number_format((int)$t['total_boxes']) ?></td>
  <td><?= e($t['vp_type'] ?: $t['vehicle_type'] ?: '—') ?><?php if ($t['vehicle_no']): ?><div><b><?= e($t['vehicle_no']) ?></b></div><div class="dd-small"><?= e(trim(($t['driver_name'] ?? '') . ' ' . ($t['driver_phone'] ?? ''))) ?></div><?php endif; ?><?= $t['tpt'] ? '<div class="dd-small">' . e($t['tpt']) . '</div>' : '' ?></td>
  <td><?php if ($state === 'need'): ?><span class="st st-need">Needs vehicle</span><?php elseif ($state): ?><span class="st st-<?= e($state) ?>"><?= e($state) ?></span><div class="dd-small"><?= e($ago($state === 'Requested' ? $t['requested_at'] : ($state === 'Confirmed' ? $t['confirmed_at'] : $t['placed_at']))) ?></div><?php else: ?><span class="dd-small"><?= e($t['status']) ?></span><?php endif; ?>
      <?= $t['dock_code'] ? '<div><span class="badge b-green">Dock ' . e($t['dock_code']) . '</span></div>' : '' ?></td>
  <td><div class="dd-act">
  <?php if (!can('plan')): ?><span class="dd-small">View only</span><?php else: ?>
  <?php if ($state === 'need'): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="request"><input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>">
      <select class="select" name="transporter_id" required aria-label="Transporter"><option value="">Transporter</option><?php foreach ($tpts as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['name']) ?></option><?php endforeach; ?></select>
      <select class="select" name="vehicle_type" aria-label="Vehicle type"><?php $dt = $defType($t); foreach ($vtypes as $vt): ?><option <?= $vt === $dt ? 'selected' : '' ?>><?= e($vt) ?></option><?php endforeach; ?></select>
      <button class="btn primary">Request vehicle</button></form>
  <?php elseif ($state === 'Requested'): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="confirm"><input type="hidden" name="pid" value="<?= (int)$t['pid'] ?>"><input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>">
      <input class="input" name="vehicle_no" placeholder="Vehicle no. e.g. HR55AB1234" required style="width:170px"><input class="input" name="driver_name" placeholder="Driver (optional)" style="width:120px"><input class="input" name="driver_phone" placeholder="Phone" style="width:100px">
      <button class="btn primary">Save vehicle</button></form>
  <?php elseif ($state === 'Confirmed' || $state === 'Placed'): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="dock"><input type="hidden" name="pid" value="<?= (int)$t['pid'] ?>"><input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>">
      <select class="select" name="dock_id" required aria-label="Dock"><option value=""><?= $dockList ? 'Choose dock' : 'No docks for ' . e($t['dc_code']) ?></option><?php foreach ($dockList as $k): ?><option value="<?= (int)$k['id'] ?>" <?= (int)$t['dock_id'] === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['dock_code'] . ($k['dock_name'] ? ' · ' . $k['dock_name'] : '')) ?></option><?php endforeach; ?></select>
      <label class="dd-small"><input type="checkbox" name="force" value="1"> allot even if busy</label><button class="btn <?= $t['dock_id'] ? 'light' : 'primary' ?>"><?= $t['dock_id'] ? 'Change dock' : 'Allot dock' ?></button></form>
    <?php if ($state === 'Confirmed'): ?><form method="post"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="placed"><input type="hidden" name="pid" value="<?= (int)$t['pid'] ?>"><input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>"><button class="btn success">Vehicle reported at dock</button></form>
    <?php else: ?><a class="btn light" href="PLANNING.php?tab=trips&amp;view=<?= (int)$t['id'] ?>">Open trip to load</a><?php endif; ?>
  <?php else: ?><span class="dd-small">Continue in the trip (load / gate pass).</span><?php endif; ?>
  <?php if (in_array($state, ['Requested', 'Confirmed', 'Placed'], true) && in_array($t['status'], ['Planned', 'Assigned'], true)): ?>
    <details><summary>Vehicle failed?</summary><form method="post"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="fail"><input type="hidden" name="pid" value="<?= (int)$t['pid'] ?>"><input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>">
      <select class="select" name="reason" required><option value="">Reason</option><?php foreach (DSP_FAIL_REASONS as $r): ?><option><?= e($r) ?></option><?php endforeach; ?></select><input class="input" name="remarks" placeholder="Remarks" style="width:140px"><button class="btn danger">Mark failed</button></form></details>
  <?php endif; ?>
  <?php if (in_array($t['status'], ['Planned', 'Assigned', 'Loaded'], true)): ?>
    <details><summary>Cancel trip</summary><form method="post" onsubmit="return confirm('Cancel <?= e($t['trip_code']) ?>? PRD challans go back to PRD Pending.')"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>">
      <input class="input" name="reason" placeholder="Why is the trip cancelled?" required style="width:220px"><button class="btn danger">Cancel trip</button></form></details>
  <?php endif; ?>
  <?php endif; /* can('plan') */ ?>
  </div></td>
</tr>
<?php endforeach; if (!$trips): ?><tr><td colspan="7" class="note" style="padding:24px;text-align:center">No open trips. Plan trips from PRD Pending first.</td></tr><?php endif; ?>
</table></div>
<?php foot(); ?>
