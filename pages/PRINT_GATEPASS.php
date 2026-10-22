<?php
/* Printable gate pass with N labelled copies. ?id=<gate pass id> or ?trip=<trip id>, &copies=1..10 */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$d = db();
$id = (int)($_GET['id'] ?? 0); $trip = (int)($_GET['trip'] ?? 0);
$copies = max(1, min(10, (int)($_GET['copies'] ?? 3)));
$q = $d->prepare("SELECT g.*, t.id trip_id, t.trip_code, t.status trip_status, t.planned_date, t.dispatched_at, t.tpt_name, t.is_joint, t.vehicle_type, t.loaded_packets, t.loaded_value, t.total_boxes, t.total_value,
    dc.dc_code, dc.dc_name, v.vehicle_number, v.vehicle_type v_type, dr.driver_name, dr.phone driver_phone, dr.license_no,
    vp.vehicle_no vp_no, vp.driver_name vp_driver, vp.driver_phone vp_phone, k.dock_code, k.dock_name, tp.name tpt_master, tp.phone tpt_phone,
    g.created_by
  FROM tms_gate_passes g JOIN trips t ON t.id=g.trip_id LEFT JOIN tms_dc_master dc ON dc.id=t.source_dc_id LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN drivers dr ON dr.id=t.driver_id
  LEFT JOIN tms_vehicle_placements vp ON vp.id=(SELECT MAX(id) FROM tms_vehicle_placements x WHERE x.trip_id=t.id AND x.status<>'Failed')
  LEFT JOIN tms_docks k ON k.id=COALESCE(t.dock_id, vp.dock_id) LEFT JOIN tms_transporters tp ON tp.id=COALESCE(t.transporter_id, vp.transporter_id)
  WHERE " . ($id ? 'g.id=?' : 'g.trip_id=?'));
$q->execute([$id ?: $trip]); $gp = $q->fetch(PDO::FETCH_ASSOC);
if (!$gp) { http_response_code(404); exit('Gate pass not found.'); }
// Prepared-by name: users tables differ between installs (full_name / name / username)
$gp['created_by_name'] = '';
if ($gp['created_by']) { try { $uq = $d->prepare("SELECT * FROM users WHERE id=?"); $uq->execute([(int)$gp['created_by']]); $u = $uq->fetch(PDO::FETCH_ASSOC) ?: []; $gp['created_by_name'] = (string)($u['full_name'] ?? $u['name'] ?? $u['username'] ?? ''); } catch (Throwable $e) {} }
$st = $d->prepare("SELECT ts.sequence_no, ts.packet_count, ts.stop_value, s.store_code, s.store_name, s.city, s.state, s.address, s.pincode,
    (SELECT string_agg(DISTINCT p.challan_no, ', ' ORDER BY p.challan_no) FROM prd_pending p WHERE p.planned_trip_id=ts.trip_id AND lower(BTRIM(p.destination_name)) IN (lower(s.store_code), lower(s.store_name))) challans,
    (SELECT string_agg(i.source_ref, ', ') FROM tms_indents i WHERE i.trip_id=ts.trip_id AND i.destination_store_id=ts.store_id AND i.source_ref IS NOT NULL) refs
  FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id WHERE ts.trip_id=? ORDER BY ts.sequence_no");
$st->execute([(int)$gp['trip_id']]); $stops = $st->fetchAll(PDO::FETCH_ASSOC);
$pk = $d->prepare("SELECT pk.sequence_no, pk.packet_count, dc.dc_code, dc.dc_name FROM tms_trip_pickups pk JOIN tms_dc_master dc ON dc.id=pk.dc_id WHERE pk.trip_id=? ORDER BY pk.sequence_no");
$pk->execute([(int)$gp['trip_id']]); $pickups = $pk->fetchAll(PDO::FETCH_ASSOC);
$labels = ['Original · Security', 'Duplicate · Transporter', 'Triplicate · Store', 'Quadruplicate · Office'];
$vehNo = $gp['vehicle_number'] ?: $gp['vp_no'] ?: '—';
$driver = $gp['driver_name'] ?: $gp['vp_driver'] ?: '—'; $phone = $gp['driver_phone'] ?: $gp['vp_phone'] ?: '';
$pkt = (int)($gp['packet_count'] ?: $gp['loaded_packets'] ?: $gp['total_boxes']); $val = (float)($gp['packet_value'] ?: $gp['loaded_value'] ?: $gp['total_value']);
$app = (string)(app_cfg('company') ?? 'Citykart');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($gp['gate_pass_no']) ?> · Gate pass</title>
<style>
@page{size:A4;margin:12mm}
*{box-sizing:border-box}body{margin:0;font:12px/1.4 "Segoe UI",Arial,sans-serif;color:#101828;background:#e9edf3}
.bar{position:sticky;top:0;background:#101828;color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.bar input{width:60px;padding:5px;border-radius:5px;border:0}.bar button,.bar a{background:#1f4fd8;color:#fff;border:0;border-radius:6px;padding:7px 14px;font-weight:600;cursor:pointer;text-decoration:none}
.bar a{background:#344054}
.sheet{width:186mm;margin:12px auto;background:#fff;padding:10mm;box-shadow:0 2px 10px rgba(0,0,0,.12);page-break-after:always}
.sheet:last-child{page-break-after:auto}
.hd{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #101828;padding-bottom:8px;margin-bottom:10px}
.hd h1{margin:0;font-size:18px}.hd .co{font-size:11px;color:#475467}
.copy{border:1.5px solid #101828;padding:4px 10px;font-weight:700;font-size:11px;text-transform:none;text-align:right}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px 14px;margin-bottom:10px}
.grid div{border-bottom:1px dotted #98a2b3;padding:3px 0}.grid small{display:block;color:#667085;font-size:10px}.grid b{font-size:12.5px}
table{width:100%;border-collapse:collapse;margin:6px 0 10px}th,td{border:1px solid #98a2b3;padding:5px 6px;text-align:left;vertical-align:top}th{background:#f2f4f7;font-size:10.5px}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}
.ch{font-size:9.5px;color:#475467;word-break:break-all}
.tot td{font-weight:700;background:#f8f9fb}
.sign{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:26px}.sign div{border-top:1px solid #101828;padding-top:4px;font-size:10.5px;text-align:center}
.foot{margin-top:10px;font-size:9.5px;color:#667085;display:flex;justify-content:space-between}
@media print{body{background:#fff}.bar{display:none}.sheet{margin:0;box-shadow:none;width:auto;padding:0}}
</style></head><body>
<form class="bar" method="get"><input type="hidden" name="<?= $id ? 'id' : 'trip' ?>" value="<?= (int)($id ?: $trip) ?>">
  <b><?= e($gp['gate_pass_no']) ?></b><label>Copies <input type="number" name="copies" min="1" max="10" value="<?= $copies ?>"></label><button type="submit">Update</button>
  <button type="button" onclick="window.print()">Print <?= $copies ?> cop<?= $copies > 1 ? 'ies' : 'y' ?></button><a href="PLANNING.php?tab=gatepasses">Back</a></form>
<?php for ($c = 1; $c <= $copies; $c++): ?>
<section class="sheet">
  <div class="hd"><div><h1>Gate Pass · <?= e($gp['gate_pass_no']) ?></h1><div class="co"><?= e($app) ?> · <?= e($gp['dc_code'] . ' ' . $gp['dc_name']) ?></div></div>
    <div class="copy"><?= e($labels[$c - 1] ?? ('Copy ' . $c)) ?><br><span style="font-weight:400">Copy <?= $c ?> of <?= $copies ?></span></div></div>
  <div class="grid">
    <div><small>Gate pass date</small><b><?= e(date('d M Y, H:i', strtotime((string)$gp['gate_pass_date']))) ?></b></div>
    <div><small>Trip</small><b><?= e($gp['trip_code']) ?></b><?= $gp['is_joint'] ? ' (joint)' : '' ?></div>
    <div><small>Status</small><b><?= e($gp['trip_status']) ?></b></div>
    <div><small>Vehicle no.</small><b><?= e($vehNo) ?></b></div>
    <div><small>Vehicle type</small><b><?= e($gp['v_type'] ?: $gp['vehicle_type'] ?: '—') ?></b></div>
    <div><small>Loading dock</small><b><?= e($gp['dock_code'] ? $gp['dock_code'] . ($gp['dock_name'] ? ' · ' . $gp['dock_name'] : '') : '—') ?></b></div>
    <div><small>Transporter</small><b><?= e($gp['tpt_master'] ?: $gp['tpt_name'] ?: '—') ?></b><?= $gp['tpt_phone'] ? ' · ' . e($gp['tpt_phone']) : '' ?></div>
    <div><small>Driver</small><b><?= e($driver) ?></b><?= $phone ? ' · ' . e($phone) : '' ?></div>
    <div><small>Driver licence</small><b><?= e($gp['license_no'] ?: '—') ?></b></div>
    <div><small>Invoice no.</small><b><?= e($gp['invoice_no'] ?: '—') ?></b></div>
    <div><small>Seal no.</small><b><?= e($gp['seal_no'] ?: '—') ?></b></div>
    <div><small>Packets · value</small><b><?= number_format($pkt) ?> PKT · ₹<?= number_format($val, 2) ?></b></div>
  </div>
  <?php if (count($pickups) > 1): ?><div style="margin-bottom:6px"><b>Pickups:</b> <?= e(implode(' → ', array_map(fn($p) => $p['dc_code'] . ' ' . $p['dc_name'] . ' (' . (int)$p['packet_count'] . ' PKT)', $pickups))) ?></div><?php endif; ?>
  <table><thead><tr><th style="width:26px">#</th><th>Store</th><th>Delivery address</th><th class="n">PKT</th><th class="n">Value (₹)</th><th>Received by (sign &amp; stamp)</th></tr></thead><tbody>
  <?php foreach ($stops as $s): $ch = $s['challans'] ?: $s['refs']; ?>
    <tr><td><?= (int)$s['sequence_no'] ?></td><td><b><?= e($s['store_code']) ?></b><br><?= e($s['store_name']) ?><?php if ($ch): $chList = array_filter(array_map('trim', explode(',', (string)$ch))); ?><div class="ch">Challans (<?= count($chList) ?>): <?= e(mb_strimwidth(implode(', ', $chList), 0, 600, '… see trip for full list')) ?></div><?php endif; ?></td>
      <td><?= e(trim(($s['address'] ?: '') . ' ' . ($s['city'] ?: '') . ' ' . ($s['state'] ?: '') . ' ' . ($s['pincode'] ?: '')) ?: '—') ?></td><td class="n"><?= number_format((int)$s['packet_count']) ?></td><td class="n"><?= number_format((float)$s['stop_value'], 2) ?></td><td style="height:38px"></td></tr>
  <?php endforeach; ?>
    <tr class="tot"><td colspan="3">Total</td><td class="n"><?= number_format(array_sum(array_column($stops, 'packet_count'))) ?></td><td class="n"><?= number_format(array_sum(array_column($stops, 'stop_value')), 2) ?></td><td></td></tr>
  </tbody></table>
  <?php if ($gp['remarks']): ?><div><b>Remarks:</b> <?= e($gp['remarks']) ?></div><?php endif; ?>
  <div class="sign"><div>Prepared by<br><?= e($gp['created_by_name'] ?? '') ?></div><div>Security (gate out)</div><div>Driver</div><div>Transporter</div></div>
  <div class="foot"><span>Printed <?= e(date('d M Y H:i')) ?> by <?= e(userName()) ?></span><span><?= e($gp['gate_pass_no']) ?> · <?= e($labels[$c - 1] ?? ('Copy ' . $c)) ?></span></div>
</section>
<?php endfor; ?>
</body></html>
