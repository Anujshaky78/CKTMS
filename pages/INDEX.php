<?php
// The dashboard is no longer in the menu: the app opens on PRD Pending. (The old dashboard code below is kept but not reached.)
header('Location: PLANNING.php?tab=prd_pending'); exit;
require_once __DIR__.'/../auth/auth.php';require_once __DIR__.'/../config/DB.php';require_once __DIR__.'/../includes/layout.php';require_login();$d=db();require_once TMS_ROOT.'/app/services/prd_planner.php';
$d->exec("UPDATE tms_trip_stops ts SET expected_delivery_at=COALESCE(ts.expected_delivery_at,t.dispatched_at + (COALESCE(s.transit_days,1)*interval '1 day')) FROM trips t, stores s WHERE ts.trip_id=t.id AND s.id=ts.store_id AND t.dispatched_at IS NOT NULL AND ts.status<>'Delivered'");
$k=$d->query("SELECT COUNT(*) trips,COUNT(*) FILTER(WHERE status IN ('Planned','Assigned','Loaded','Gate Pass Created')) queue,COUNT(*) FILTER(WHERE status='In Transit') transit,COUNT(*) FILTER(WHERE status='Delivered') delivered,COUNT(*) FILTER(WHERE status IN ('Breakdown','Accident')) incidents,COALESCE(SUM(total_value),0) value,COALESCE(SUM(total_boxes),0) packets FROM trips")->fetch(PDO::FETCH_ASSOC);
$delayed=(int)$d->query("SELECT COUNT(*) FROM tms_trip_stops ts JOIN trips t ON t.id=ts.trip_id JOIN stores s ON s.id=ts.store_id WHERE ts.status<>'Delivered' AND t.status='In Transit' AND t.dispatched_at IS NOT NULL AND CURRENT_TIMESTAMP>t.dispatched_at+(COALESCE(s.transit_days,1)*interval '1 day')")->fetchColumn();
$nd=(int)$d->query("SELECT COUNT(*) FROM trips WHERE status IN ('Planned','Assigned','Loaded','Gate Pass Created') AND COALESCE(dispatch_date,planned_date)<=CURRENT_DATE-7")->fetchColumn();
$vehicles=$d->query("SELECT COUNT(*) total,COUNT(*) FILTER(WHERE status='Available') available FROM vehicles")->fetch(PDO::FETCH_ASSOC);
$drivers=$d->query("SELECT COUNT(*) total,COUNT(*) FILTER(WHERE status='Available') available FROM drivers")->fetch(PDO::FETCH_ASSOC);
$stores=$d->query("SELECT COUNT(*) total,COUNT(*) FILTER(WHERE active) active FROM stores")->fetch(PDO::FETCH_ASSOC);
$tpt=$d->query("SELECT COALESCE(NULLIF(TRIM(t.tpt_name),''),'Unassigned TPT') tpt_name,COUNT(DISTINCT t.id) trips,COUNT(DISTINCT ts.store_id) stores,COALESCE(SUM(ts.packet_count),0) packets,COALESCE(SUM(ts.stop_value),0) value,STRING_AGG(DISTINCT COALESCE(v.vehicle_number,'—'),', ' ORDER BY COALESCE(v.vehicle_number,'—')) vehicles FROM trips t JOIN tms_trip_stops ts ON ts.trip_id=t.id LEFT JOIN vehicles v ON v.id=t.vehicle_id WHERE t.status='In Transit' AND ts.status<>'Delivered' GROUP BY 1 ORDER BY value DESC")->fetchAll(PDO::FETCH_ASSOC);
$sla=$d->query("SELECT ts.id stop_id,t.id trip_id,t.trip_code,s.store_code,s.store_name,s.transit_days,ts.status stop_status,ts.expected_delivery_at,ts.delivered_at,ts.delivery_result,ts.delivery_delay_days,CASE WHEN ts.status='Delivered' THEN COALESCE(ts.delivery_result,'Delivered') WHEN ts.expected_delivery_at IS NOT NULL AND CURRENT_TIMESTAMP>ts.expected_delivery_at THEN 'Delayed' ELSE 'On Track' END sla_state FROM tms_trip_stops ts JOIN trips t ON t.id=ts.trip_id JOIN stores s ON s.id=ts.store_id WHERE t.status IN ('In Transit','Delivered') ORDER BY CASE WHEN ts.status='Delivered' THEN 2 WHEN ts.expected_delivery_at IS NOT NULL AND CURRENT_TIMESTAMP>ts.expected_delivery_at THEN 0 ELSE 1 END,ts.expected_delivery_at LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$attention=$d->query("SELECT t.id,t.trip_code,CASE WHEN t.status IN ('Breakdown','Accident') THEN t.status WHEN t.status IN ('Planned','Assigned','Loaded','Gate Pass Created') AND COALESCE(t.dispatch_date,t.planned_date)<=CURRENT_DATE-7 THEN 'Not Dispatched >7 Days' ELSE 'Delayed Store' END alert,COALESCE(v.vehicle_number,'—') vehicle,COALESCE(t.total_value,0) value FROM trips t LEFT JOIN vehicles v ON v.id=t.vehicle_id WHERE t.status IN ('Breakdown','Accident') OR (t.status IN ('Planned','Assigned','Loaded','Gate Pass Created') AND COALESCE(t.dispatch_date,t.planned_date)<=CURRENT_DATE-7) OR EXISTS(SELECT 1 FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id WHERE ts.trip_id=t.id AND ts.status<>'Delivered' AND t.status='In Transit' AND t.dispatched_at IS NOT NULL AND CURRENT_TIMESTAMP>t.dispatched_at+(COALESCE(s.transit_days,1)*interval '1 day')) ORDER BY t.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$kpi=$d->query("SELECT COUNT(*) total_trips,COUNT(*) FILTER(WHERE status IN ('Planned','Assigned','Loaded','Gate Pass Created')) queue,COUNT(*) FILTER(WHERE status='In Transit') in_transit,COUNT(*) FILTER(WHERE status='Delivered') delivered,COUNT(*) FILTER(WHERE status IN ('Breakdown','Accident')) incidents,COALESCE(SUM(total_value),0) total_value,COALESCE(SUM(total_boxes),0) total_packets,COUNT(*) FILTER(WHERE status IN ('Planned','Assigned','Loaded','Gate Pass Created') AND COALESCE(dispatch_date,planned_date)<=CURRENT_DATE-7) not_dispatched FROM trips")->fetch(PDO::FETCH_ASSOC);
$stopKpi=$d->query("SELECT COUNT(*) total_stops,COUNT(*) FILTER(WHERE ts.status='Delivered') delivered_stops,COUNT(*) FILTER(WHERE ts.status<>'Delivered' AND t.status='In Transit' AND ts.expected_delivery_at IS NOT NULL AND CURRENT_TIMESTAMP>ts.expected_delivery_at) delayed_stops,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='On Time Delivered') on_time,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='Late Delivered') late FROM tms_trip_stops ts JOIN trips t ON t.id=ts.trip_id")->fetch(PDO::FETCH_ASSOC);
$recent=$d->query("SELECT t.id,t.trip_code,t.planned_date,t.status,t.total_boxes,t.total_value,COUNT(ts.id) stops FROM trips t LEFT JOIN tms_trip_stops ts ON ts.trip_id=t.id GROUP BY t.id ORDER BY t.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
// Pipeline counts by trip status + PRD pending (start of the flow)
$byStatus = []; foreach ($d->query("SELECT status, COUNT(*) n FROM trips GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) $byStatus[$r['status']] = (int)$r['n'];
$prd = ['pkt' => 0, 'stores' => 0, 'value' => 0.0];
try {
  $prd = $d->query("SELECT COUNT(DISTINCT " . prd_pkt_col('p') . ") pkt, COUNT(DISTINCT lower(p.destination_name)) stores, COALESCE(SUM(p.value),0) value FROM prd_pending p WHERE p.source_system='ORACLE' AND UPPER(BTRIM(COALESCE(p.challan_status,'')))=" . $d->quote(prd_status_filter()) . " AND " . prd_pending_where('p'))->fetch(PDO::FETCH_ASSOC) ?: $prd;
} catch (Throwable $e) {}
$deliveredToday = (int)$d->query("SELECT COUNT(*) FROM trips WHERE status='Delivered' AND completed_at::date=CURRENT_DATE")->fetchColumn();
$fmtL = fn($v) => '₹' . ($v >= 10000000 ? number_format($v / 10000000, 2) . ' Cr' : ($v >= 100000 ? number_format($v / 100000, 2) . ' L' : number_format((float)$v, 0)));
$stages = [
  ['PRD Pending', (int)$prd['pkt'], 'PKT · ' . number_format((int)$prd['stores']) . ((int)$prd['stores'] === 1 ? ' store' : ' stores'), 'PLANNING.php?tab=prd_pending'],
  ['Planned', $byStatus['Planned'] ?? 0, 'trips', 'PLANNING.php?tab=trips'],
  ['Assigned', $byStatus['Assigned'] ?? 0, 'vehicle + driver', 'PLANNING.php?tab=trips'],
  ['Loaded', $byStatus['Loaded'] ?? 0, 'ready for gate pass', 'PLANNING.php?tab=gatepasses'],
  ['Gate Pass', $byStatus['Gate Pass Created'] ?? 0, 'ready to dispatch', 'PLANNING.php?tab=gatepasses'],
  ['In Transit', $byStatus['In Transit'] ?? 0, 'on the road', 'PLANNING.php?tab=tripstatus'],
  ['Delivered', $byStatus['Delivered'] ?? 0, $deliveredToday . ' today', 'PLANNING.php?tab=tripstatus'],
];
$alerts = [
  ['Delayed stores', (int)$stopKpi['delayed_stops'], 'In transit past expected delivery'],
  ['Not dispatched > 7 days', (int)$kpi['not_dispatched'], 'Planned but still at the DC'],
  ['Incidents', (int)$kpi['incidents'], 'Breakdown or accident'],
  ['Late deliveries', (int)$stopKpi['late'], 'Delivered after SLA'],
];
// ---- Vehicles required: planned trips without a vehicle + PRD Pending suggested loads ----
$shiftDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['shift'] ?? '')) ? $_GET['shift'] : date('Y-m-d');
$needTrips = []; $prdNeed = []; $clusters = []; $storesToGo = ['prd' => 0, 'trips' => 0, 'held' => 0, 'due' => 0];
try {
  foreach ($d->query("SELECT COALESCE(NULLIF(t.vehicle_type,''),'Unspecified') vt, COUNT(*) n FROM trips t WHERE t.status='Planned' AND NOT EXISTS (SELECT 1 FROM tms_vehicle_placements vp WHERE vp.trip_id=t.id AND vp.status IN ('Requested','Confirmed','Placed')) GROUP BY 1 ORDER BY 2 DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) $needTrips[$r['vt']] = (int)$r['n'];
  $brd = prd_build_board($d, ['mode' => 'separate']);
  foreach ($brd['plans'] as $pl) foreach ($pl['vehicles'] as $v) $prdNeed[$v['type']] = ($prdNeed[$v['type']] ?? 0) + (int)$v['count'];
  foreach ($brd['groups'] as $g) {
    $c = $g['cluster_type'] !== '' ? $g['cluster_type'] : 'Not set';
    $clusters[$c] ??= ['stores' => [], 'pkt' => 0, 'value' => 0.0, 'due' => 0, 'held' => 0, 'trip_stores' => 0];
    $sk = strtolower(trim($g['destination_name']));
    $clusters[$c]['stores'][$sk] = 1; $clusters[$c]['pkt'] += $g['pkt']; $clusters[$c]['value'] += $g['value'];
    if ($g['hold']) $clusters[$c]['held_set'][$sk] = 1; elseif ($g['due']) $clusters[$c]['due_set'][$sk] = 1;
  }
  foreach ($clusters as &$cx) { $cx['held'] = count($cx['held_set'] ?? []); $cx['due'] = count(array_diff_key($cx['due_set'] ?? [], $cx['held_set'] ?? [])); } unset($cx);
  foreach ($d->query("SELECT COALESCE(NULLIF(s.cluster_type,''),'Not set') c, COUNT(DISTINCT ts.store_id) n FROM tms_trip_stops ts JOIN trips t ON t.id=ts.trip_id JOIN stores s ON s.id=ts.store_id WHERE t.status IN ('Planned','Assigned','Loaded','Gate Pass Created') GROUP BY 1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $clusters[$r['c']] ??= ['stores' => [], 'pkt' => 0, 'value' => 0.0, 'due' => 0, 'held' => 0, 'trip_stores' => 0];
    $clusters[$r['c']]['trip_stores'] = (int)$r['n'];
  }
  ksort($clusters);
  // Totals count each store once, even if its two warehouses report different clusters
  $allS = []; $allH = []; $allD = [];
  foreach ($clusters as $c) { $allS += $c['stores']; $allH += $c['held_set'] ?? []; $allD += $c['due_set'] ?? []; $storesToGo['trips'] += $c['trip_stores']; }
  $storesToGo['prd'] = count(array_diff_key($allS, $allH)); $storesToGo['held'] = count($allH); $storesToGo['due'] = count(array_diff_key($allD, $allH));
} catch (Throwable $e) {}
$vehReqTotal = array_sum($needTrips) + array_sum($prdNeed);

// ---- End-of-shift status for the chosen day ----
$sq = function (string $sql) use ($d, $shiftDay) { try { $q = $d->prepare($sql); $q->execute([$shiftDay]); return (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; } };
$shift = [
  ['Trips planned', $sq("SELECT COUNT(*) FROM trips WHERE planned_date=?"), ''],
  ['Vehicles requested', $sq("SELECT COUNT(*) FROM tms_vehicle_placements WHERE requested_at::date=?"), ''],
  ['Vehicles confirmed', $sq("SELECT COUNT(*) FROM tms_vehicle_placements WHERE confirmed_at::date=?"), ''],
  ['Vehicles placed', $sq("SELECT COUNT(*) FROM tms_vehicle_placements WHERE placed_at::date=?"), ''],
  ['Failed vehicles', $sq("SELECT COUNT(*) FROM tms_vehicle_placements WHERE status='Failed' AND failed_at::date=?"), 'bad'],
  ['Loaded', $sq("SELECT COUNT(DISTINCT trip_id) FROM tms_trip_status_history WHERE status='Loaded' AND created_at::date=?"), ''],
  ['Gate passes', $sq("SELECT COUNT(*) FROM tms_gate_passes WHERE gate_pass_date::date=?"), ''],
  ['Dispatched', $sq("SELECT COUNT(*) FROM trips WHERE dispatched_at::date=?"), 'good'],
  ['Delivered', $sq("SELECT COUNT(*) FROM trips WHERE completed_at::date=?"), 'good'],
  ['Cancelled trips', $sq("SELECT COUNT(*) FROM trips WHERE status='Cancelled' AND cancelled_at::date=?"), 'bad'],
];
$carry = [
  ['Planned, no vehicle yet', array_sum($needTrips)],
  ['Vehicle confirmed, not loaded', (int)$d->query("SELECT COUNT(*) FROM tms_vehicle_placements vp JOIN trips t ON t.id=vp.trip_id WHERE vp.status IN ('Confirmed','Placed') AND t.status IN ('Planned','Assigned')")->fetchColumn()],
  ['Loaded, not dispatched', (int)$d->query("SELECT COUNT(*) FROM trips WHERE status IN ('Loaded','Gate Pass Created')")->fetchColumn()],
  ['Stores on hold', $storesToGo['held']],
];
$failedList = $d->prepare("SELECT t.trip_code, tp.name tpt, vp.vehicle_no, vp.fail_reason, vp.remarks FROM tms_vehicle_placements vp JOIN trips t ON t.id=vp.trip_id LEFT JOIN tms_transporters tp ON tp.id=vp.transporter_id WHERE vp.status='Failed' AND vp.failed_at::date=? ORDER BY vp.failed_at DESC LIMIT 10");
$failedList->execute([$shiftDay]); $failedList = $failedList->fetchAll(PDO::FETCH_ASSOC);
$cancelList = $d->prepare("SELECT trip_code, cancel_reason FROM trips WHERE status='Cancelled' AND cancelled_at::date=? ORDER BY cancelled_at DESC LIMIT 10");
$cancelList->execute([$shiftDay]); $cancelList = $cancelList->fetchAll(PDO::FETCH_ASSOC);
head('Dashboard');
?>
<style>
.db-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.db-head h2{margin:0;font-size:20px}.db-head p{margin:3px 0 0;color:var(--muted);font-size:12px}
.db-actions{display:flex;gap:8px}
.pipe{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));background:#fff;border:1px solid var(--line);border-radius:var(--r-lg);overflow:hidden;margin-bottom:14px;box-shadow:var(--shadow)}
.pipe a{position:relative;display:block;padding:14px 16px 13px 18px;text-decoration:none;color:var(--ink);border-left:1px solid var(--line)}
.pipe a:first-child{border-left:0;background:var(--blue-soft)}
.pipe a:hover{background:var(--sunken)}
.pipe a:not(:first-child)::before{content:"";position:absolute;left:-7px;top:50%;width:12px;height:12px;background:#fff;border-top:1px solid var(--line);border-right:1px solid var(--line);transform:translateY(-50%) rotate(45deg);z-index:1}
.pipe a:nth-child(2)::before{background:var(--blue-soft)}
.pipe small{display:block;font-size:11.5px;color:var(--muted);font-weight:600}
.pipe b{display:block;font-size:26px;line-height:1.15;margin:4px 0 2px;font-variant-numeric:tabular-nums}
.pipe span{font-size:11px;color:var(--muted)}
.db-alerts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.db-alert{background:#fff;border:1px solid var(--line);border-radius:var(--r-lg);padding:12px 14px;display:flex;gap:12px;align-items:center}
.db-alert b{font-size:22px;min-width:34px;text-align:center;font-variant-numeric:tabular-nums;color:var(--green)}
.db-alert.on{border-color:#fecdca;background:var(--red-soft)}.db-alert.on b{color:var(--red)}
.db-alert div{font-size:12.5px;font-weight:650}.db-alert small{display:block;font-weight:400;color:var(--muted);font-size:11px}
.db-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:14px}
.db-grid .card{margin-bottom:14px;min-width:0}
.db-grid .table{min-width:0}.db-grid .table td,.db-grid .table th{padding:8px 9px}
.db-res{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.db-res div{background:var(--sunken);border-radius:var(--r);padding:10px 12px}
.db-res b{display:block;font-size:19px;font-variant-numeric:tabular-nums}.db-res span{font-size:11px;color:var(--muted)}
.tpt-row{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-top:1px solid #eef1f5;font-size:12px}
.tpt-row:first-child{border-top:0}.tpt-row small{color:var(--muted)}
.db-empty{padding:18px;text-align:center;color:var(--muted);font-size:12px}
.db-row3{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:14px}.db-row3 .card{margin-bottom:14px}
.db-big{display:flex;align-items:baseline;gap:8px;margin:2px 0 10px}.db-big b{font-size:30px;font-variant-numeric:tabular-nums}.db-big span{color:var(--muted)}
.db-split{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.db-chip{display:inline-block;background:var(--sunken);border:1px solid var(--line);border-radius:6px;padding:3px 8px;margin:0 6px 6px 0;font-size:12px}
.db-toggle{display:inline-flex;border:1px solid var(--line-2);border-radius:7px;overflow:hidden}.db-toggle button{border:0;background:#fff;padding:6px 12px;font:600 12px var(--font);cursor:pointer;color:var(--ink-2)}.db-toggle button.on{background:var(--ink);color:#fff}
.db-shift-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.db-shift-grid div{background:var(--sunken);border-radius:var(--r);padding:10px 12px}.db-shift-grid b{display:block;font-size:20px;font-variant-numeric:tabular-nums}.db-shift-grid span{font-size:11.5px;color:var(--muted)}
.db-shift-grid .bad{background:var(--red-soft)}.db-shift-grid .bad b{color:var(--red)}.db-shift-grid .good b{color:var(--green)}
@media(max-width:1000px){.db-row3,.db-split{grid-template-columns:1fr}.db-shift-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media print{.side,.top,.db-head .db-actions,.pipe,.db-alerts,.db-row3,.db-grid,.toolbar{display:none!important}body{overflow:visible;background:#fff}.main{height:auto;overflow:visible;padding:0}.db-shift{box-shadow:none;border:0}}
@media(max-width:1200px){.pipe{grid-template-columns:repeat(4,minmax(0,1fr))}.pipe a::before{display:none}.pipe a:nth-child(n+5){border-top:1px solid var(--line)}}
@media(max-width:1000px){.db-grid{grid-template-columns:1fr}.db-alerts,.db-res{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.pipe{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<div class="db-head">
  <div><h2>Dispatch today</h2><p><?= e(date('l, d M Y')) ?></p></div>
  <div class="db-actions"><a class="btn primary" href="PLANNING.php?tab=prd_pending">Plan from PRD Pending</a><a class="btn light" href="REPORTS.php">Reports &amp; SLA</a></div>
</div>

<nav class="pipe" aria-label="Dispatch pipeline">
<?php foreach ($stages as $s): ?><a href="<?= e($s[3]) ?>"><small><?= e($s[0]) ?></small><b><?= number_format((int)$s[1]) ?></b><span><?= e($s[2]) ?></span></a><?php endforeach; ?>
</nav>


<div class="db-row3">
  <section class="card"><div class="modulebar"><div><div class="section-title">Vehicles required</div></div><a class="btn light" href="DISPATCH.php">Dispatch desk</a></div>
    <div class="db-big"><b><?= number_format($vehReqTotal) ?></b><span>vehicles to arrange</span></div>
    <div class="db-split"><div><div class="section-caption">For planned trips · <?= array_sum($needTrips) ?></div><?php foreach ($needTrips as $t => $n): ?><span class="db-chip"><?= e($t) ?> <b><?= $n ?></b></span><?php endforeach; if (!$needTrips): ?><span class="note">All planned trips have a vehicle request.</span><?php endif; ?></div>
      <div><div class="section-caption">For PRD Pending (suggested) · <?= array_sum($prdNeed) ?></div><?php foreach ($prdNeed as $t => $n): ?><span class="db-chip"><?= e($t) ?> <b><?= $n ?></b></span><?php endforeach; if (!$prdNeed): ?><span class="note">No suggested loads.</span><?php endif; ?></div></div>
  </section>
  <section class="card"><div class="modulebar"><div><div class="section-title">Stores to be dispatched</div></div>
      <div class="db-toggle" role="tablist"><button type="button" class="on" onclick="dbView(this,'sum')">Total</button><button type="button" onclick="dbView(this,'clu')">By cluster</button></div></div>
    <div data-v="sum"><div class="db-res" style="grid-template-columns:repeat(4,minmax(0,1fr))"><div><b><?= number_format($storesToGo['prd'] + $storesToGo['trips']) ?></b><span>Total stores</span></div><div><b><?= number_format($storesToGo['prd']) ?></b><span>On PRD Pending</span></div><div><b><?= number_format($storesToGo['trips']) ?></b><span>In trips, not left</span></div><div><b style="color:<?= $storesToGo['due'] ? 'var(--red)' : 'inherit' ?>"><?= number_format($storesToGo['due']) ?></b><span>Waiting 3+ days</span></div></div></div>
    <div data-v="clu" hidden><div class="tablewrap" style="box-shadow:none"><table class="table" style="min-width:0"><tr><th>Cluster</th><th class="right">PRD stores</th><th class="right">PKT</th><th class="right">Value</th><th class="right">Due 3+ d</th><th class="right">On hold</th><th class="right">In trips</th></tr>
      <?php foreach ($clusters as $c => $x): ?><tr><td><b><?= e($c) ?></b></td><td class="right"><?= count($x['stores']) - $x['held'] ?></td><td class="right"><?= number_format($x['pkt']) ?></td><td class="right"><?= $fmtL($x['value']) ?></td><td class="right" style="color:<?= $x['due'] ? 'var(--red)' : 'inherit' ?>"><?= $x['due'] ?></td><td class="right"><?= $x['held'] ?></td><td class="right"><?= $x['trip_stores'] ?></td></tr><?php endforeach; if (!$clusters): ?><tr><td colspan="7" class="note">Nothing pending.</td></tr><?php endif; ?></table></div></div>
  </section>
</div>

<section class="card db-shift" id="shift"><div class="modulebar"><div><div class="section-title">End-of-shift status</div></div>
  <form method="get" class="toolbar" style="margin:0" data-no-busy><input class="input" type="date" name="shift" value="<?= e($shiftDay) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()"><button type="button" class="btn light" onclick="window.print()">Print shift report</button></form></div>
  <div class="db-shift-grid"><?php foreach ($shift as [$l, $n, $tone]): ?><div class="<?= $n && $tone ? $tone : '' ?>"><b><?= number_format($n) ?></b><span><?= e($l) ?></span></div><?php endforeach; ?></div>
  <div class="db-split" style="margin-top:12px">
    <div><div class="section-caption">Carried over (right now)</div><?php foreach ($carry as [$l, $n]): ?><div class="tpt-row"><span><?= e($l) ?></span><b><?= number_format($n) ?></b></div><?php endforeach; ?></div>
    <div><div class="section-caption">Failed vehicles &amp; cancelled trips</div>
      <?php foreach ($failedList as $f): ?><div class="tpt-row"><span><b><?= e($f['trip_code']) ?></b> · <?= e($f['tpt'] ?: '') ?> <?= e($f['vehicle_no'] ?: '') ?></span><span class="badge b-red"><?= e($f['fail_reason']) ?></span></div><?php endforeach; ?>
      <?php foreach ($cancelList as $c): ?><div class="tpt-row"><span><b><?= e($c['trip_code']) ?></b> cancelled</span><small><?= e($c['cancel_reason'] ?: '') ?></small></div><?php endforeach; ?>
      <?php if (!$failedList && !$cancelList): ?><div class="db-empty">None on this day.</div><?php endif; ?></div>
  </div>
</section>

<section class="card"><div class="modulebar"><div><div class="section-title">Needs action</div></div><span class="badge <?= $attention ? 'b-red' : 'b-green' ?>"><?= number_format(count($attention)) ?> open</span></div>
      <div class="tablewrap"><table class="table"><tr><th>Trip</th><th>Alert</th><th>Vehicle</th><th class="right">Value</th></tr>
      <?php foreach ($attention as $x): ?><tr class="dbl-detail" data-url="PLANNING.php?tab=trips&amp;view=<?= (int)$x['id'] ?>"><td><b><?= e($x['trip_code']) ?></b></td><td><span class="badge <?= in_array($x['alert'], ['Breakdown', 'Accident', 'Delayed Store'], true) ? 'b-red' : 'b-amber' ?>"><?= e($x['alert']) ?></span></td><td><?= e($x['vehicle']) ?></td><td class="right"><?= money($x['value']) ?></td></tr><?php endforeach; ?>
      <?php if (!$attention): ?><tr><td colspan="4"><div class="db-empty">Nothing needs action right now.</div></td></tr><?php endif; ?></table></div>
    </section>
<script>function dbView(b,v){b.parentNode.querySelectorAll('button').forEach(x=>x.classList.toggle('on',x===b));b.closest('section').querySelectorAll('[data-v]').forEach(x=>x.hidden=x.dataset.v!==v);}
setInterval(()=>{if(document.visibilityState==='visible'&&!document.querySelector('.busy-overlay.show'))location.reload()},60000);document.querySelectorAll('.dbl-detail[data-url]').forEach(r=>r.addEventListener('dblclick',()=>location.href=r.dataset.url));</script>
<?php foot(); ?>
