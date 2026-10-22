<?php
/* Dock-wise loading plan: which vehicle loads at which dock for which stores. ?dc=<id>&layout=dock|summary */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$d = db();
$dc = (int)($_GET['dc'] ?? 0);
$layout = ($_GET['layout'] ?? 'dock') === 'summary' ? 'summary' : 'dock';
$dcs = $d->query("SELECT id,dc_code,dc_name FROM tms_dc_master ORDER BY dc_code")->fetchAll(PDO::FETCH_ASSOC);
$p = []; $w = "vp.status IN ('Confirmed','Placed') AND t.status IN ('Planned','Assigned','Loaded','Gate Pass Created')";
if ($dc) { $w .= " AND t.source_dc_id=?"; $p[] = $dc; }
$q = $d->prepare("SELECT vp.vehicle_no, vp.vehicle_type, vp.status vp_status, vp.driver_name, vp.driver_phone, vp.placed_at, vp.confirmed_at, tp.name tpt,
    t.id trip_id, t.trip_code, t.status, t.total_boxes, t.total_value, t.is_joint, dc.id dc_id, dc.dc_code, dc.dc_name, k.id dock_id, k.dock_code, k.dock_name,
    (SELECT json_agg(json_build_object('seq',ts.sequence_no,'code',s.store_code,'name',s.store_name,'city',s.city,'pkt',ts.packet_count) ORDER BY ts.sequence_no) FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id WHERE ts.trip_id=t.id) stops,
    (SELECT string_agg(d2.dc_code || ' ' || pk.packet_count, ' → ' ORDER BY pk.sequence_no) FROM tms_trip_pickups pk JOIN tms_dc_master d2 ON d2.id=pk.dc_id WHERE pk.trip_id=t.id) pickups
  FROM tms_vehicle_placements vp JOIN trips t ON t.id=vp.trip_id LEFT JOIN tms_transporters tp ON tp.id=vp.transporter_id
  LEFT JOIN tms_dc_master dc ON dc.id=t.source_dc_id LEFT JOIN tms_docks k ON k.id=vp.dock_id
  WHERE $w ORDER BY dc.dc_code, k.dock_code NULLS LAST, vp.confirmed_at");
$q->execute($p); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
$byDock = []; $noDock = [];
foreach ($rows as &$r) $r['stops'] = json_decode((string)$r['stops'], true) ?: []; unset($r);
foreach ($rows as $r) { if ($r['dock_id']) $byDock[$r['dc_code'] . '|' . $r['dock_code']][] = $r; else $noDock[] = $r; }
$when = date('d M Y, H:i');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>Dock plan · <?= e($when) ?></title>
<style>
@page{size:A4;margin:12mm}*{box-sizing:border-box}
body{margin:0;font:12px/1.4 "Segoe UI",Arial,sans-serif;color:#101828;background:#e9edf3}
.bar{position:sticky;top:0;background:#101828;color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.bar select{padding:5px;border-radius:5px}.bar button,.bar a{background:#1f4fd8;color:#fff;border:0;border-radius:6px;padding:7px 14px;font-weight:600;cursor:pointer;text-decoration:none}.bar a{background:#344054}
.sheet{width:186mm;margin:12px auto;background:#fff;padding:10mm;box-shadow:0 2px 10px rgba(0,0,0,.12);page-break-after:always}.sheet:last-child{page-break-after:auto}
.dock-hd{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:3px solid #101828;padding-bottom:8px;margin-bottom:12px}
.dock-hd .code{font-size:44px;font-weight:800;line-height:1;letter-spacing:-.02em}.dock-hd .meta{text-align:right;font-size:11px;color:#475467}
.veh{border:1.5px solid #101828;border-radius:8px;padding:10px 12px;margin-bottom:12px;break-inside:avoid}
.veh-hd{display:flex;justify-content:space-between;gap:10px;align-items:baseline;margin-bottom:6px}
.veh-hd .no{font-size:22px;font-weight:800;letter-spacing:.02em}.veh-hd .st{font-size:11px;border:1px solid #101828;border-radius:999px;padding:2px 8px}
.veh small{color:#475467}
table{width:100%;border-collapse:collapse;margin-top:6px}th,td{border:1px solid #98a2b3;padding:5px 6px;text-align:left}th{background:#f2f4f7;font-size:10.5px}td.n,th.n{text-align:right}
.big td{font-size:13px}
.note{font-size:10.5px;color:#475467}
@media print{body{background:#fff}.bar{display:none}.sheet{margin:0;box-shadow:none;width:auto;padding:0}}
</style></head><body>
<form class="bar" method="get">
  <b>Dock plan</b>
  <select name="dc" onchange="this.form.submit()"><option value="0">All warehouses</option><?php foreach ($dcs as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $dc === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['dc_code'] . ' · ' . $x['dc_name']) ?></option><?php endforeach; ?></select>
  <select name="layout" onchange="this.form.submit()"><option value="dock" <?= $layout === 'dock' ? 'selected' : '' ?>>One page per dock (to post at the dock)</option><option value="summary" <?= $layout === 'summary' ? 'selected' : '' ?>>Summary sheet (supervisor)</option></select>
  <button type="button" onclick="window.print()">Print</button><a href="DISPATCH.php">Back to dispatch desk</a>
</form>
<?php if (!$rows): ?><section class="sheet"><h2>No vehicles confirmed yet</h2><p class="note">Vehicles appear here once a transporter confirms the vehicle number on the Dispatch desk.</p></section><?php endif; ?>

<?php if ($layout === 'dock'): foreach ($byDock as $key => $list): [$dcc, $dock] = explode('|', $key, 2); $f = $list[0]; ?>
<section class="sheet">
  <div class="dock-hd"><div><div class="note"><?= e($f['dc_code'] . ' · ' . $f['dc_name']) ?></div><div class="code">Dock <?= e($dock) ?></div><?= $f['dock_name'] ? '<div>' . e($f['dock_name']) . '</div>' : '' ?></div>
    <div class="meta">Loading plan<br><b><?= e($when) ?></b><br><?= count($list) ?> vehicle<?= count($list) > 1 ? 's' : '' ?></div></div>
  <?php foreach ($list as $i => $r): ?>
  <div class="veh">
    <div class="veh-hd"><div><span class="no"><?= e($r['vehicle_no']) ?></span> <small><?= e($r['vehicle_type'] ?: '') ?> · <?= e($r['tpt'] ?: '') ?></small></div><span class="st"><?= $i === 0 ? 'Load now' : 'Next #' . ($i + 1) ?> · <?= e($r['vp_status'] === 'Placed' ? 'At dock' : 'Expected') ?></span></div>
    <div><b><?= e($r['trip_code']) ?></b> · <?= number_format((int)$r['total_boxes']) ?> PKT<?= $r['is_joint'] ? ' · joint pickup: ' . e((string)$r['pickups']) : '' ?> · Driver <?= e(trim(($r['driver_name'] ?: '—') . ' ' . ($r['driver_phone'] ?: ''))) ?></div>
    <table class="big"><tr><th style="width:30px">Stop</th><th>Store / location</th><th class="n">PKT</th><th style="width:90px">Loaded ✓</th></tr>
    <?php foreach ($r['stops'] as $s): ?><tr><td><?= (int)$s['seq'] ?></td><td><b><?= e($s['code']) ?></b> · <?= e($s['name']) ?><?= $s['city'] ? ', ' . e($s['city']) : '' ?></td><td class="n"><?= number_format((int)$s['pkt']) ?></td><td></td></tr><?php endforeach; ?></table>
  </div>
  <?php endforeach; ?>
</section>
<?php endforeach; endif; ?>

<?php if ($layout === 'summary' && $rows): ?>
<section class="sheet">
  <div class="dock-hd"><div><div class="code" style="font-size:26px">Dock allocation summary</div><div class="note"><?= $dc ? e($rows[0]['dc_code'] . ' · ' . $rows[0]['dc_name']) : 'All warehouses' ?></div></div><div class="meta"><b><?= e($when) ?></b><br><?= count($rows) ?> vehicles</div></div>
  <table><tr><th>Dock</th><th>Vehicle</th><th>Transporter</th><th>Trip</th><th>Stores (in order)</th><th class="n">PKT</th><th>Status</th></tr>
  <?php foreach ($rows as $r): ?><tr><td><b><?= e($r['dock_code'] ?: '—') ?></b></td><td><?= e($r['vehicle_no']) ?><br><small><?= e($r['vehicle_type'] ?: '') ?></small></td><td><?= e($r['tpt'] ?: '') ?></td><td><?= e($r['trip_code']) ?></td>
    <td><?= e(implode(' → ', array_map(fn($s) => $s['code'] . ($s['city'] ? ' (' . $s['city'] . ')' : ''), $r['stops']))) ?></td><td class="n"><?= number_format((int)$r['total_boxes']) ?></td><td><?= e($r['vp_status'] === 'Placed' ? 'At dock' : 'Expected') ?></td></tr><?php endforeach; ?></table>
</section>
<?php endif; ?>

<?php if ($layout === 'dock' && $noDock): ?>
<section class="sheet"><div class="dock-hd"><div><div class="code" style="font-size:26px">Confirmed, no dock yet</div></div><div class="meta"><b><?= e($when) ?></b></div></div>
  <table><tr><th>Vehicle</th><th>Transporter</th><th>Trip</th><th>Stores</th><th class="n">PKT</th></tr>
  <?php foreach ($noDock as $r): ?><tr><td><?= e($r['vehicle_no']) ?></td><td><?= e($r['tpt'] ?: '') ?></td><td><?= e($r['trip_code']) ?></td><td><?= e(implode(' → ', array_map(fn($s) => $s['code'], $r['stops']))) ?></td><td class="n"><?= number_format((int)$r['total_boxes']) ?></td></tr><?php endforeach; ?></table>
  <p class="note">Allot a dock on the Dispatch desk so these vehicles appear on a dock sheet.</p></section>
<?php endif; ?>
</body></html>
