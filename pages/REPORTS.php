<?php
require_once __DIR__.'/../auth/auth.php';require_once __DIR__.'/../config/DB.php';require_once __DIR__.'/../includes/layout.php';require_login();$d=db();
$d->exec("UPDATE tms_trip_stops ts SET expected_delivery_at=COALESCE(ts.expected_delivery_at,t.dispatched_at + (COALESCE(s.transit_days,1)*interval '1 day')) FROM trips t, stores s WHERE ts.trip_id=t.id AND s.id=ts.store_id AND t.dispatched_at IS NOT NULL AND ts.status<>'Delivered'");
if(!function_exists('distance_km')){
function distance_km($a,$b):float{ $lat1=(float)($a['latitude']??0);$lon1=(float)($a['longitude']??0);$lat2=(float)($b['latitude']??0);$lon2=(float)($b['longitude']??0); if(!$lat1&&!$lon1&&!$lat2&&!$lon2)return 0; $p=pi()/180;$x=($lon2-$lon1)*$p*cos(($lat1+$lat2)*$p/2);$y=($lat2-$lat1)*$p;return sqrt($x*$x+$y*$y)*6371; }
}
function trip_distance_km(PDO $d,int $tripId):float{
  static $cache=[]; if(isset($cache[$tripId])) return $cache[$tripId];
  $oq=$d->prepare("SELECT dc.latitude,dc.longitude FROM trips t JOIN tms_dc_master dc ON dc.id=t.source_dc_id WHERE t.id=?");$oq->execute([$tripId]);$origin=$oq->fetch(PDO::FETCH_ASSOC)?:['latitude'=>0,'longitude'=>0];
  $sq=$d->prepare("SELECT s.latitude,s.longitude FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id WHERE ts.trip_id=? ORDER BY ts.sequence_no");$sq->execute([$tripId]);
  $cur=$origin;$dist=0.0; foreach($sq->fetchAll(PDO::FETCH_ASSOC) as $st){ $dist+=distance_km($cur,$st); $cur=$st; }
  return $cache[$tripId]=$dist;
}
$from=$_GET['from']??date('Y-m-01');$to=$_GET['to']??date('Y-m-d');
$rtab=$_GET['rtab']??'overview';
head('Reports');
?>
<style>
/* v6.4: same compact look as the other tabs */
.page-title{justify-content:flex-end;margin-bottom:8px}.page-title h2,.page-title > div > .note{display:none}
.cards{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.cards .card{display:flex;flex-direction:row-reverse;justify-content:flex-end;align-items:baseline;gap:6px;padding:6px 10px;border-radius:8px;box-shadow:none;margin:0;min-width:0;background:#fff}
.cards .card .metric{font-size:14px;margin:0;font-weight:700}.cards .card .label{font-size:12px;color:var(--ink-2);text-transform:none;letter-spacing:0}
.cards .card .note,.cards .card small{display:none}
.section-title + .note,.modulebar .note{display:none}
.report-kpi-grid{display:flex!important;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.report-kpi{display:flex!important;flex-direction:row-reverse;justify-content:flex-end;align-items:baseline;gap:6px;padding:6px 10px!important;border:1px solid var(--line)!important;border-radius:8px!important;background:#fff!important;box-shadow:none!important;min-height:0!important;min-width:0!important}
.report-kpi b{font-size:14px!important;margin:0!important;color:var(--ink)!important}.report-kpi span{font-size:12px!important;text-transform:none!important;letter-spacing:0!important;color:var(--ink-2)!important;font-weight:500!important}
.report-kpi small{display:none!important}
.report-alerts{display:flex!important;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.report-alert{display:flex!important;flex-direction:row;align-items:baseline;gap:6px;padding:6px 10px!important;border:1px solid var(--line)!important;border-radius:8px!important;background:#fff!important;box-shadow:none!important;min-height:0!important}
.report-alert strong{order:-1;font-size:14px!important;color:var(--ink)!important;margin:0!important}.report-alert b{font-size:12px!important;font-weight:500!important;color:var(--ink-2)!important;text-transform:none!important;letter-spacing:0!important}
.report-alert span{display:none!important}
.report-alert.danger{background:var(--red-soft)!important;border-color:#fecdca!important}.report-alert.danger strong,.report-alert.danger b{color:var(--red)!important}
.report-kpi[class*="late"],.report-kpi.bad{background:var(--red-soft)!important}
</style>

<div class="page-title"><div><h2>Transport Analytics & Reports</h2><div class="note">Historical analysis for the selected period.</div></div><form class="toolbar"><input type="hidden" name="rtab" value="<?=e($rtab)?>"><input class="input" type="date" name="from" value="<?=e($from)?>"><input class="input" type="date" name="to" value="<?=e($to)?>"><button class="btn primary">Apply Period</button></form></div>
<div class="tabs">
<a class="tab <?=$rtab==='overview'?'active':''?>" href="REPORTS.php?rtab=overview&from=<?=e($from)?>&to=<?=e($to)?>">Overview</a>
<a class="tab <?=$rtab==='driver'?'active':''?>" href="REPORTS.php?rtab=driver&from=<?=e($from)?>&to=<?=e($to)?>">Driver Report</a>
<a class="tab <?=$rtab==='vehicle'?'active':''?>" href="REPORTS.php?rtab=vehicle&from=<?=e($from)?>&to=<?=e($to)?>">Vehicle Report</a>
<a class="tab <?=$rtab==='tpt'?'active':''?>" href="REPORTS.php?rtab=tpt&from=<?=e($from)?>&to=<?=e($to)?>">TPT Report</a>
<a class="tab <?=$rtab==='store'?'active':''?>" href="REPORTS.php?rtab=store&from=<?=e($from)?>&to=<?=e($to)?>">Store Report</a>
</div>
<?php if($rtab==='overview'):
$base=$d->prepare("SELECT COUNT(*) trips,COALESCE(SUM(t.total_value),0) value,COALESCE(SUM(t.total_boxes),0) packets,COUNT(*) FILTER(WHERE t.status='Delivered') delivered,COUNT(*) FILTER(WHERE t.status='In Transit') in_transit,COUNT(*) FILTER(WHERE t.status IN ('Planned','Assigned','Loaded','Gate Pass Created')) queue,COUNT(*) FILTER(WHERE t.status IN ('Breakdown','Accident')) incidents,COUNT(*) FILTER(WHERE t.status IN ('Planned','Assigned','Loaded','Gate Pass Created') AND COALESCE(t.dispatch_date,t.planned_date)<=CURRENT_DATE-7) old_queue,AVG(NULLIF(t.total_value,0)) avg_trip_value,AVG(NULLIF(t.total_boxes,0)) avg_trip_packets FROM trips t WHERE t.planned_date BETWEEN ? AND ?");$base->execute([$from,$to]);$k=$base->fetch(PDO::FETCH_ASSOC);
$q=$d->prepare("SELECT COUNT(*) stops,COALESCE(SUM(ts.packet_count),0) packets,COALESCE(SUM(ts.stop_value),0) value,COUNT(*) FILTER(WHERE ts.status='Delivered') delivered,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='On Time Delivered') on_time,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='Late Delivered') late,COUNT(*) FILTER(WHERE ts.status<>'Delivered' AND ts.expected_delivery_at IS NOT NULL AND CURRENT_TIMESTAMP>ts.expected_delivery_at) open_delay,COALESCE(AVG(ts.delivery_delay_days) FILTER(WHERE ts.status='Delivered'),0) avg_delay FROM tms_trip_stops ts JOIN trips t ON t.id=ts.trip_id WHERE t.planned_date BETWEEN ? AND ?");$q->execute([$from,$to]);$s=$q->fetch(PDO::FETCH_ASSOC);
$slaPct=((int)$s['delivered']>0)?round(((int)$s['on_time']/(int)$s['delivered'])*100,1):0;
$tpt=$d->prepare("SELECT COALESCE(NULLIF(TRIM(t.tpt_name),''),'Unassigned TPT') tpt_name,COUNT(DISTINCT t.id) trips,COUNT(DISTINCT ts.store_id) stores,COALESCE(SUM(ts.packet_count),0) packets,COALESCE(SUM(ts.stop_value),0) value,COUNT(*) FILTER(WHERE ts.status='Delivered') delivered,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='On Time Delivered') on_time,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='Late Delivered') late FROM trips t LEFT JOIN tms_trip_stops ts ON ts.trip_id=t.id WHERE t.planned_date BETWEEN ? AND ? GROUP BY 1 ORDER BY value DESC");$tpt->execute([$from,$to]);$tptRows=$tpt->fetchAll(PDO::FETCH_ASSOC);
$store=$d->prepare("SELECT s.store_code,s.store_name,COALESCE(s.city,'') city,COALESCE(s.state,'') state,COALESCE(s.transit_days,1) sla_days,COUNT(ts.id) stops,COUNT(*) FILTER(WHERE ts.status='Delivered') delivered,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='On Time Delivered') on_time,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='Late Delivered') late,COALESCE(SUM(ts.packet_count),0) packets,COALESCE(SUM(ts.stop_value),0) value,COALESCE(AVG(ts.delivery_delay_days) FILTER(WHERE ts.status='Delivered'),0) avg_delay FROM tms_trip_stops ts JOIN trips t ON t.id=ts.trip_id JOIN stores s ON s.id=ts.store_id WHERE t.planned_date BETWEEN ? AND ? GROUP BY s.id,s.store_code,s.store_name,s.city,s.state,s.transit_days ORDER BY late DESC,avg_delay DESC,value DESC");$store->execute([$from,$to]);$storeRows=$store->fetchAll(PDO::FETCH_ASSOC);
$daily=$d->prepare("SELECT t.planned_date,COUNT(*) trips,COUNT(*) FILTER(WHERE t.status='Delivered') delivered,COUNT(*) FILTER(WHERE t.status='In Transit') in_transit,COALESCE(SUM(t.total_boxes),0) packets,COALESCE(SUM(t.total_value),0) value FROM trips t WHERE t.planned_date BETWEEN ? AND ? GROUP BY t.planned_date ORDER BY t.planned_date");$daily->execute([$from,$to]);$dailyRows=$daily->fetchAll(PDO::FETCH_ASSOC);
$rows=$d->prepare("SELECT t.trip_code,t.planned_date,t.status,t.tpt_name,COALESCE(t.total_boxes,0) packets,COALESCE(t.total_value,0) value,COUNT(ts.id) stops,COUNT(*) FILTER(WHERE ts.status='Delivered') delivered_stops,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='Late Delivered') late_stops FROM trips t LEFT JOIN tms_trip_stops ts ON ts.trip_id=t.id WHERE t.planned_date BETWEEN ? AND ? GROUP BY t.id ORDER BY t.planned_date DESC,t.id DESC");$rows->execute([$from,$to]);$tripRows=$rows->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="report-kpi-grid">
<div class="report-kpi"><span>Trips</span><b><?=number_format($k['trips'])?></b><small>Period</small></div><div class="report-kpi"><span>Trip Value</span><b><?=money($k['value'])?></b><small>Period value</small></div><div class="report-kpi"><span>Packets</span><b><?=number_format($k['packets'])?></b><small>Planned load</small></div><div class="report-kpi"><span>Avg Trip Value</span><b><?=money($k['avg_trip_value'])?></b><small>Per trip</small></div><div class="report-kpi"><span>Avg Trip Packets</span><b><?=number_format($k['avg_trip_packets'])?></b><small>Per trip</small></div>
<div class="report-kpi"><span>Stops</span><b><?=number_format($s['stops'])?></b><small>Store stops</small></div><div class="report-kpi"><span>Delivered Stops</span><b><?=number_format($s['delivered'])?></b><small>Completed</small></div><div class="report-kpi"><span>SLA %</span><b><?=number_format($slaPct,1)?>%</b><small>On-time / delivered</small></div><div class="report-kpi"><span>Avg Delay</span><b><?=number_format($s['avg_delay'],2)?> d</b><small>Delivered late/average</small></div><div class="report-kpi"><span>Open SLA Delays</span><b><?=number_format($s['open_delay'])?></b><small>Currently overdue</small></div>
<div class="report-kpi"><span>Queue</span><b><?=number_format($k['queue'])?></b><small>Period trips</small></div><div class="report-kpi"><span>In Transit</span><b><?=number_format($k['in_transit'])?></b><small>Period trips</small></div><div class="report-kpi"><span>Delivered Trips</span><b><?=number_format($k['delivered'])?></b><small>Completed trips</small></div><div class="report-kpi"><span>Incidents</span><b><?=number_format($k['incidents'])?></b><small>Breakdown + Accident</small></div><div class="report-kpi"><span>Old Queue &gt;7D</span><b><?=number_format($k['old_queue'])?></b><small>Backlog</small></div>
</div>
<div class="report-alerts"><div class="report-alert"><b>On Time</b><strong><?=number_format($s['on_time'])?></strong><span>Delivered within SLA</span></div><div class="report-alert danger"><b>Late Delivered</b><strong><?=number_format($s['late'])?></strong><span>Delivered after SLA</span></div><div class="report-alert"><b>Performance</b><strong><?=number_format($slaPct,1)?>%</strong><span>Period SLA attainment</span></div></div>
<div class="card"><div class="modulebar"><div><div class="section-title">TPT Performance Analysis</div><div class="note">Compare transporter performance for the selected period. Click a TPT name for its individual report.</div></div></div><div class="tablewrap"><table class="table"><tr><th>TPT</th><th>Trips</th><th>Stores</th><th>Packets</th><th>Value</th><th>Delivered</th><th>On Time</th><th>Late</th><th>SLA %</th></tr><?php foreach($tptRows as $r):$p=((int)$r['delivered']>0)?round(((int)$r['on_time']/(int)$r['delivered'])*100,1):0;?><tr><td><a href="REPORTS.php?rtab=tpt&name=<?=urlencode($r['tpt_name'])?>&from=<?=e($from)?>&to=<?=e($to)?>"><b><?=e($r['tpt_name'])?></b></a></td><td><?=number_format($r['trips'])?></td><td><?=number_format($r['stores'])?></td><td><?=number_format($r['packets'])?></td><td><?=money($r['value'])?></td><td><?=number_format($r['delivered'])?></td><td><?=number_format($r['on_time'])?></td><td><?=number_format($r['late'])?></td><td><span class="badge <?=$p<90?'b-red':($p<98?'b-amber':'b-green')?>"><?=number_format($p,1)?>%</span></td></tr><?php endforeach;?><?php if(!$tptRows):?><tr><td colspan="9" class="note">No TPT data for selected period.</td></tr><?php endif;?></table></div></div>
<div class="card"><div class="modulebar"><div><div class="section-title">Store-wise SLA Performance</div><div class="note">Best/worst performing stores, volume and delay. Click a store for its individual report.</div></div></div><div class="tablewrap"><table class="table"><tr><th>Store</th><th>City / State</th><th>SLA</th><th>Stops</th><th>Packets</th><th>Value</th><th>Delivered</th><th>On Time</th><th>Late</th><th>SLA %</th><th>Avg Delay</th></tr><?php foreach($storeRows as $r):$p=((int)$r['delivered']>0)?round(((int)$r['on_time']/(int)$r['delivered'])*100,1):0;?><tr><td><a href="REPORTS.php?rtab=store&name=<?=urlencode($r['store_code'])?>&from=<?=e($from)?>&to=<?=e($to)?>"><b><?=e($r['store_code'])?></b> — <?=e($r['store_name'])?></a></td><td><?=e(trim($r['city'].' / '.$r['state'],' /'))?></td><td><?=e($r['sla_days'])?> d</td><td><?=number_format($r['stops'])?></td><td><?=number_format($r['packets'])?></td><td><?=money($r['value'])?></td><td><?=number_format($r['delivered'])?></td><td><?=number_format($r['on_time'])?></td><td><?=number_format($r['late'])?></td><td><span class="badge <?=$p<90?'b-red':($p<98?'b-amber':'b-green')?>"><?=number_format($p,1)?>%</span></td><td><?=number_format($r['avg_delay'],2)?> d</td></tr><?php endforeach;?><?php if(!$storeRows):?><tr><td colspan="11" class="note">No store SLA data for selected period.</td></tr><?php endif;?></table></div></div>
<div class="card"><div class="modulebar"><div><div class="section-title">Daily Trend</div><div class="note">Trip volume, delivery completion, packets and value by planned date.</div></div></div><div class="tablewrap"><table class="table compact-table"><tr><th>Date</th><th>Trips</th><th>Delivered</th><th>In Transit</th><th>Packets</th><th>Value</th></tr><?php foreach($dailyRows as $r):?><tr><td><?=e($r['planned_date'])?></td><td><?=number_format($r['trips'])?></td><td><?=number_format($r['delivered'])?></td><td><?=number_format($r['in_transit'])?></td><td><?=number_format($r['packets'])?></td><td><?=money($r['value'])?></td></tr><?php endforeach;?><?php if(!$dailyRows):?><tr><td colspan="6" class="note">No trend data for selected period.</td></tr><?php endif;?></table></div></div>
<div class="card"><div class="modulebar"><div><div class="section-title">Trip Register Report</div><div class="note">Historical trip-level export-style view. Double-click a trip for operational detail.</div></div></div><div class="tablewrap"><table class="table compact-table"><tr><th>Trip</th><th>Date</th><th>TPT</th><th>Stops</th><th>Delivered</th><th>Late Stops</th><th>Packets</th><th>Value</th><th>Status</th></tr><?php foreach($tripRows as $r):?><tr class="dbl-detail" data-url="PLANNING.php?view=<?=urlencode((string)$r['trip_code'])?>"><td><b><?=e($r['trip_code'])?></b></td><td><?=e($r['planned_date'])?></td><td><?=e($r['tpt_name']?:'—')?></td><td><?=number_format($r['stops'])?></td><td><?=number_format($r['delivered_stops'])?></td><td><?=number_format($r['late_stops'])?></td><td><?=number_format($r['packets'])?></td><td><?=money($r['value'])?></td><td><?=e($r['status'])?></td></tr><?php endforeach;?></table></div></div>
<?php endif; ?>

<?php if($rtab==='driver'):
$drivers=$d->query("SELECT id,driver_name,phone,COALESCE(tpt_name,'') tpt_name FROM drivers ORDER BY driver_name")->fetchAll(PDO::FETCH_ASSOC);
$selId=(int)($_GET['id']??0); $selName=trim($_GET['name']??'');
if(!$selId && $selName){foreach($drivers as $dr){if(strcasecmp($dr['driver_name'],$selName)===0){$selId=(int)$dr['id'];break;}}}
if(!$selId && $drivers) $selId=(int)$drivers[0]['id'];
$sel=null; foreach($drivers as $dr){if((int)$dr['id']===$selId){$sel=$dr;break;}}
?>
<div class="card"><div class="modulebar"><div><div class="section-title">Individual Driver Report</div><div class="note">Select a driver by name to see their trip-wise report for the selected period.</div></div>
<form class="toolbar"><input type="hidden" name="rtab" value="driver"><input type="hidden" name="from" value="<?=e($from)?>"><input type="hidden" name="to" value="<?=e($to)?>"><select class="select" name="id" onchange="this.form.submit()"><?php foreach($drivers as $dr):?><option value="<?=$dr['id']?>" <?=$selId===(int)$dr['id']?'selected':''?>><?=e($dr['driver_name'])?><?=$dr['phone']?' — '.e($dr['phone']):''?></option><?php endforeach;?></select></form>
</div>
<?php if(!$sel):?><div class="note" style="padding:16px">No driver master data available.</div><?php else:
$tq=$d->prepare("SELECT t.id,t.trip_code,t.planned_date,t.status,t.tpt_name,v.vehicle_number,dc.dc_code,COALESCE(t.total_boxes,0) packets,COALESCE(t.total_value,0) value,COUNT(ts.id) stops,COUNT(*) FILTER(WHERE ts.status='Delivered') delivered,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='Late Delivered') late FROM trips t LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN tms_dc_master dc ON dc.id=t.source_dc_id LEFT JOIN tms_trip_stops ts ON ts.trip_id=t.id WHERE t.driver_id=? AND t.planned_date BETWEEN ? AND ? GROUP BY t.id,v.vehicle_number,dc.dc_code ORDER BY t.planned_date DESC,t.id DESC");
$tq->execute([$selId,$from,$to]); $dtrips=$tq->fetchAll(PDO::FETCH_ASSOC);
$totTrips=count($dtrips);$totDelivered=0;$totLate=0;$totPkt=0;$totVal=0;$totDist=0.0;
foreach($dtrips as $r){if($r['status']==='Delivered')$totDelivered++;$totLate+=(int)$r['late'];$totPkt+=(int)$r['packets'];$totVal+=(float)$r['value'];$totDist+=trip_distance_km($d,(int)$r['id']);}
?>
<div class="report-kpi-grid" style="grid-template-columns:repeat(5,minmax(0,1fr))">
<div class="report-kpi"><span>Driver</span><b style="font-size:15px"><?=e($sel['driver_name'])?></b><small><?=e($sel['tpt_name']?:'—')?></small></div>
<div class="report-kpi"><span>Total Trips</span><b><?=number_format($totTrips)?></b><small>Period</small></div>
<div class="report-kpi"><span>Delivered</span><b><?=number_format($totDelivered)?></b><small>Completed trips</small></div>
<div class="report-kpi"><span>Late Stops</span><b><?=number_format($totLate)?></b><small>Delivered late</small></div>
<div class="report-kpi"><span>Distance</span><b><?=number_format($totDist,1)?> km</b><small>Total run</small></div>
</div>
<div class="tablewrap"><table class="table"><tr><th>Trip</th><th>Date</th><th>Source</th><th>Vehicle</th><th>TPT</th><th>Distance</th><th>Stops</th><th>Delivered</th><th>Late</th><th>Packets</th><th>Value</th><th>Status</th></tr>
<?php foreach($dtrips as $r):?><tr class="dbl-detail" data-url="PLANNING.php?view=<?=urlencode((string)$r['trip_code'])?>"><td><b><?=e($r['trip_code'])?></b></td><td><?=e($r['planned_date'])?></td><td><?=e($r['dc_code']?:'—')?></td><td><?=e($r['vehicle_number']?:'—')?></td><td><?=e($r['tpt_name']?:'—')?></td><td><?=number_format(trip_distance_km($d,(int)$r['id']),1)?> km</td><td><?=number_format($r['stops'])?></td><td><?=number_format($r['delivered'])?></td><td><?=number_format($r['late'])?></td><td><?=number_format($r['packets'])?></td><td><?=money($r['value'])?></td><td><?=e($r['status'])?></td></tr><?php endforeach;?>
<?php if(!$dtrips):?><tr><td colspan="12" class="note" style="text-align:center;padding:20px">No trips for this driver in the selected period.</td></tr><?php endif;?>
</table></div>
<?php endif;?>
</div>
<?php endif; ?>

<?php if($rtab==='vehicle'):
$vehicles=$d->query("SELECT id,vehicle_number,vehicle_type,COALESCE(capacity_boxes,0) capacity_boxes FROM vehicles ORDER BY vehicle_number")->fetchAll(PDO::FETCH_ASSOC);
$selId=(int)($_GET['id']??0); $selName=trim($_GET['name']??'');
if(!$selId && $selName){foreach($vehicles as $v){if(strcasecmp($v['vehicle_number'],$selName)===0){$selId=(int)$v['id'];break;}}}
if(!$selId && $vehicles) $selId=(int)$vehicles[0]['id'];
$sel=null; foreach($vehicles as $v){if((int)$v['id']===$selId){$sel=$v;break;}}
?>
<div class="card"><div class="modulebar"><div><div class="section-title">Individual Vehicle Report</div><div class="note">Select a vehicle number to see its trip-wise report for the selected period.</div></div>
<form class="toolbar"><input type="hidden" name="rtab" value="vehicle"><input type="hidden" name="from" value="<?=e($from)?>"><input type="hidden" name="to" value="<?=e($to)?>"><select class="select" name="id" onchange="this.form.submit()"><?php foreach($vehicles as $v):?><option value="<?=$v['id']?>" <?=$selId===(int)$v['id']?'selected':''?>><?=e($v['vehicle_number'])?> (<?=e($v['vehicle_type']?:'—')?>)</option><?php endforeach;?></select></form>
</div>
<?php if(!$sel):?><div class="note" style="padding:16px">No vehicle master data available.</div><?php else:
$tq=$d->prepare("SELECT t.id,t.trip_code,t.planned_date,t.status,t.tpt_name,dr.driver_name,dc.dc_code,COALESCE(t.total_boxes,0) packets,COALESCE(t.total_value,0) value,COUNT(ts.id) stops,COUNT(*) FILTER(WHERE ts.status='Delivered') delivered,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='Late Delivered') late FROM trips t LEFT JOIN drivers dr ON dr.id=t.driver_id LEFT JOIN tms_dc_master dc ON dc.id=t.source_dc_id LEFT JOIN tms_trip_stops ts ON ts.trip_id=t.id WHERE t.vehicle_id=? AND t.planned_date BETWEEN ? AND ? GROUP BY t.id,dr.driver_name,dc.dc_code ORDER BY t.planned_date DESC,t.id DESC");
$tq->execute([$selId,$from,$to]); $vtrips=$tq->fetchAll(PDO::FETCH_ASSOC);
$totTrips=count($vtrips);$totDelivered=0;$totLate=0;$totPkt=0;$totVal=0;$totDist=0.0;
foreach($vtrips as $r){if($r['status']==='Delivered')$totDelivered++;$totLate+=(int)$r['late'];$totPkt+=(int)$r['packets'];$totVal+=(float)$r['value'];$totDist+=trip_distance_km($d,(int)$r['id']);}
?>
<div class="report-kpi-grid" style="grid-template-columns:repeat(5,minmax(0,1fr))">
<div class="report-kpi"><span>Vehicle</span><b style="font-size:15px"><?=e($sel['vehicle_number'])?></b><small><?=e($sel['vehicle_type']?:'—')?> · <?=number_format($sel['capacity_boxes'])?> pkt cap.</small></div>
<div class="report-kpi"><span>Total Trips</span><b><?=number_format($totTrips)?></b><small>Period</small></div>
<div class="report-kpi"><span>Delivered</span><b><?=number_format($totDelivered)?></b><small>Completed trips</small></div>
<div class="report-kpi"><span>Late Stops</span><b><?=number_format($totLate)?></b><small>Delivered late</small></div>
<div class="report-kpi"><span>Distance</span><b><?=number_format($totDist,1)?> km</b><small>Total run</small></div>
</div>
<div class="tablewrap"><table class="table"><tr><th>Trip</th><th>Date</th><th>Source</th><th>Driver</th><th>TPT</th><th>Distance</th><th>Stops</th><th>Delivered</th><th>Late</th><th>Packets</th><th>Value</th><th>Status</th></tr>
<?php foreach($vtrips as $r):?><tr class="dbl-detail" data-url="PLANNING.php?view=<?=urlencode((string)$r['trip_code'])?>"><td><b><?=e($r['trip_code'])?></b></td><td><?=e($r['planned_date'])?></td><td><?=e($r['dc_code']?:'—')?></td><td><?=e($r['driver_name']?:'—')?></td><td><?=e($r['tpt_name']?:'—')?></td><td><?=number_format(trip_distance_km($d,(int)$r['id']),1)?> km</td><td><?=number_format($r['stops'])?></td><td><?=number_format($r['delivered'])?></td><td><?=number_format($r['late'])?></td><td><?=number_format($r['packets'])?></td><td><?=money($r['value'])?></td><td><?=e($r['status'])?></td></tr><?php endforeach;?>
<?php if(!$vtrips):?><tr><td colspan="12" class="note" style="text-align:center;padding:20px">No trips for this vehicle in the selected period.</td></tr><?php endif;?>
</table></div>
<?php endif;?>
</div>
<?php endif; ?>

<?php if($rtab==='tpt'):
$tpts=$d->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(tpt_name),''),'Unassigned TPT') tpt_name FROM trips ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$selName=trim($_GET['name']??''); if(!$selName && $tpts) $selName=$tpts[0];
?>
<div class="card"><div class="modulebar"><div><div class="section-title">Individual TPT Report</div><div class="note">Select a transporter by name to see their trip-wise report for the selected period.</div></div>
<form class="toolbar"><input type="hidden" name="rtab" value="tpt"><input type="hidden" name="from" value="<?=e($from)?>"><input type="hidden" name="to" value="<?=e($to)?>"><select class="select" name="name" onchange="this.form.submit()"><?php foreach($tpts as $tn):?><option value="<?=e($tn)?>" <?=$selName===$tn?'selected':''?>><?=e($tn)?></option><?php endforeach;?></select></form>
</div>
<?php if(!$tpts):?><div class="note" style="padding:16px">No TPT data available.</div><?php else:
$isUnassigned=($selName==='Unassigned TPT');
$tq=$isUnassigned
 ? $d->prepare("SELECT t.id,t.trip_code,t.planned_date,t.status,dr.driver_name,v.vehicle_number,dc.dc_code,COALESCE(t.total_boxes,0) packets,COALESCE(t.total_value,0) value,COUNT(ts.id) stops,COUNT(*) FILTER(WHERE ts.status='Delivered') delivered,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='Late Delivered') late FROM trips t LEFT JOIN drivers dr ON dr.id=t.driver_id LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN tms_dc_master dc ON dc.id=t.source_dc_id LEFT JOIN tms_trip_stops ts ON ts.trip_id=t.id WHERE (t.tpt_name IS NULL OR TRIM(t.tpt_name)='') AND t.planned_date BETWEEN ? AND ? GROUP BY t.id,dr.driver_name,v.vehicle_number,dc.dc_code ORDER BY t.planned_date DESC,t.id DESC")
 : $d->prepare("SELECT t.id,t.trip_code,t.planned_date,t.status,dr.driver_name,v.vehicle_number,dc.dc_code,COALESCE(t.total_boxes,0) packets,COALESCE(t.total_value,0) value,COUNT(ts.id) stops,COUNT(*) FILTER(WHERE ts.status='Delivered') delivered,COUNT(*) FILTER(WHERE ts.status='Delivered' AND ts.delivery_result='Late Delivered') late FROM trips t LEFT JOIN drivers dr ON dr.id=t.driver_id LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN tms_dc_master dc ON dc.id=t.source_dc_id LEFT JOIN tms_trip_stops ts ON ts.trip_id=t.id WHERE t.tpt_name=? AND t.planned_date BETWEEN ? AND ? GROUP BY t.id,dr.driver_name,v.vehicle_number,dc.dc_code ORDER BY t.planned_date DESC,t.id DESC");
$isUnassigned?$tq->execute([$from,$to]):$tq->execute([$selName,$from,$to]); $ttrips=$tq->fetchAll(PDO::FETCH_ASSOC);
$totTrips=count($ttrips);$totDelivered=0;$totLate=0;$totPkt=0;$totVal=0;$totDist=0.0;
foreach($ttrips as $r){if($r['status']==='Delivered')$totDelivered++;$totLate+=(int)$r['late'];$totPkt+=(int)$r['packets'];$totVal+=(float)$r['value'];$totDist+=trip_distance_km($d,(int)$r['id']);}
?>
<div class="report-kpi-grid" style="grid-template-columns:repeat(5,minmax(0,1fr))">
<div class="report-kpi"><span>TPT</span><b style="font-size:15px"><?=e($selName)?></b><small>Transporter</small></div>
<div class="report-kpi"><span>Total Trips</span><b><?=number_format($totTrips)?></b><small>Period</small></div>
<div class="report-kpi"><span>Delivered</span><b><?=number_format($totDelivered)?></b><small>Completed trips</small></div>
<div class="report-kpi"><span>Late Stops</span><b><?=number_format($totLate)?></b><small>Delivered late</small></div>
<div class="report-kpi"><span>Distance</span><b><?=number_format($totDist,1)?> km</b><small>Total run</small></div>
</div>
<div class="tablewrap"><table class="table"><tr><th>Trip</th><th>Date</th><th>Source</th><th>Driver</th><th>Vehicle</th><th>Distance</th><th>Stops</th><th>Delivered</th><th>Late</th><th>Packets</th><th>Value</th><th>Status</th></tr>
<?php foreach($ttrips as $r):?><tr class="dbl-detail" data-url="PLANNING.php?view=<?=urlencode((string)$r['trip_code'])?>"><td><b><?=e($r['trip_code'])?></b></td><td><?=e($r['planned_date'])?></td><td><?=e($r['dc_code']?:'—')?></td><td><?=e($r['driver_name']?:'—')?></td><td><?=e($r['vehicle_number']?:'—')?></td><td><?=number_format(trip_distance_km($d,(int)$r['id']),1)?> km</td><td><?=number_format($r['stops'])?></td><td><?=number_format($r['delivered'])?></td><td><?=number_format($r['late'])?></td><td><?=number_format($r['packets'])?></td><td><?=money($r['value'])?></td><td><?=e($r['status'])?></td></tr><?php endforeach;?>
<?php if(!$ttrips):?><tr><td colspan="12" class="note" style="text-align:center;padding:20px">No trips for this TPT in the selected period.</td></tr><?php endif;?>
</table></div>
<?php endif;?>
</div>
<?php endif; ?>

<?php if($rtab==='store'):
$stores=$d->query("SELECT id,store_code,store_name,COALESCE(city,'') city,COALESCE(state,'') state FROM stores ORDER BY store_code")->fetchAll(PDO::FETCH_ASSOC);
$selId=(int)($_GET['id']??0); $selName=trim($_GET['name']??'');
if(!$selId && $selName){foreach($stores as $st){if(strcasecmp($st['store_code'],$selName)===0){$selId=(int)$st['id'];break;}}}
if(!$selId && $stores) $selId=(int)$stores[0]['id'];
$sel=null; foreach($stores as $st){if((int)$st['id']===$selId){$sel=$st;break;}}
?>
<div class="card"><div class="modulebar"><div><div class="section-title">Individual Store Report</div><div class="note">Select a store to see its delivery-wise report for the selected period.</div></div>
<form class="toolbar"><input type="hidden" name="rtab" value="store"><input type="hidden" name="from" value="<?=e($from)?>"><input type="hidden" name="to" value="<?=e($to)?>"><select class="select" name="id" onchange="this.form.submit()"><?php foreach($stores as $st):?><option value="<?=$st['id']?>" <?=$selId===(int)$st['id']?'selected':''?>><?=e($st['store_code'])?> — <?=e($st['store_name'])?></option><?php endforeach;?></select></form>
</div>
<?php if(!$sel):?><div class="note" style="padding:16px">No store master data available.</div><?php else:
$sq=$d->prepare("SELECT ts.id stop_id,t.id trip_id,t.trip_code,t.planned_date,t.tpt_name,dr.driver_name,v.vehicle_number,ts.status,ts.packet_count,ts.stop_value,ts.expected_delivery_at,ts.delivered_at,ts.delivery_result,ts.delivery_delay_days FROM tms_trip_stops ts JOIN trips t ON t.id=ts.trip_id LEFT JOIN drivers dr ON dr.id=t.driver_id LEFT JOIN vehicles v ON v.id=t.vehicle_id WHERE ts.store_id=? AND t.planned_date BETWEEN ? AND ? ORDER BY t.planned_date DESC,t.id DESC");
$sq->execute([$selId,$from,$to]); $sstops=$sq->fetchAll(PDO::FETCH_ASSOC);
$totStops=count($sstops);$totDelivered=0;$totOnTime=0;$totLate=0;$totPkt=0;$totVal=0;
foreach($sstops as $r){if($r['status']==='Delivered'){$totDelivered++;if($r['delivery_result']==='On Time Delivered')$totOnTime++;if($r['delivery_result']==='Late Delivered')$totLate++;}$totPkt+=(int)$r['packet_count'];$totVal+=(float)$r['stop_value'];}
$slaPct=$totDelivered>0?round(($totOnTime/$totDelivered)*100,1):0;
?>
<div class="report-kpi-grid" style="grid-template-columns:repeat(5,minmax(0,1fr))">
<div class="report-kpi"><span>Store</span><b style="font-size:15px"><?=e($sel['store_code'])?></b><small><?=e($sel['store_name'])?></small></div>
<div class="report-kpi"><span>Total Stops</span><b><?=number_format($totStops)?></b><small>Period</small></div>
<div class="report-kpi"><span>Delivered</span><b><?=number_format($totDelivered)?></b><small>Completed</small></div>
<div class="report-kpi"><span>SLA %</span><b><?=number_format($slaPct,1)?>%</b><small>On-time delivery</small></div>
<div class="report-kpi"><span>Packets / Value</span><b><?=number_format($totPkt)?></b><small><?=money($totVal)?></small></div>
</div>
<div class="tablewrap"><table class="table"><tr><th>Trip</th><th>Date</th><th>Driver</th><th>Vehicle</th><th>TPT</th><th>Packets</th><th>Value</th><th>Expected</th><th>Actual</th><th>Delay</th><th>Status</th></tr>
<?php foreach($sstops as $r):?><tr class="dbl-detail" data-url="PLANNING.php?view=<?=urlencode((string)$r['trip_code'])?>"><td><b><?=e($r['trip_code'])?></b></td><td><?=e($r['planned_date'])?></td><td><?=e($r['driver_name']?:'—')?></td><td><?=e($r['vehicle_number']?:'—')?></td><td><?=e($r['tpt_name']?:'—')?></td><td><?=number_format($r['packet_count'])?></td><td><?=money($r['stop_value'])?></td><td><?=$r['expected_delivery_at']?e(date('d-m-Y H:i',strtotime($r['expected_delivery_at']))):'—'?></td><td><?=$r['delivered_at']?e(date('d-m-Y H:i',strtotime($r['delivered_at']))):'—'?></td><td><?=number_format($r['delivery_delay_days'],2)?> d</td><td><span class="badge <?=$r['status']==='Delivered'?'b-green':'b-amber'?>"><?=e($r['status'])?></span></td></tr><?php endforeach;?>
<?php if(!$sstops):?><tr><td colspan="11" class="note" style="text-align:center;padding:20px">No stops for this store in the selected period.</td></tr><?php endif;?>
</table></div>
<?php endif;?>
</div>
<?php endif; ?>

<style>.report-kpi-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;margin:10px 0}.report-kpi{background:#fff;border:1px solid var(--line);border-radius:8px;padding:9px 10px;min-height:67px}.report-kpi span{display:block;font-size:9px;text-transform:uppercase;font-weight:800;color:#64748b}.report-kpi b{display:block;font-size:20px;line-height:1.15;margin:4px 0 1px}.report-kpi small{font-size:8px;color:#64748b}.report-alerts{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:8px 0}.report-alert{border:1px solid var(--line);background:#fff;border-radius:8px;padding:10px}.report-alert b{display:block;font-size:9px;text-transform:uppercase;color:#64748b}.report-alert strong{display:block;font-size:22px;margin:2px 0}.report-alert span{font-size:9px;color:#64748b}.report-alert.danger{border-color:#fecaca;background:#fff7f7}@media(max-width:1200px){.report-kpi-grid{grid-template-columns:repeat(4,1fr)}}@media(max-width:900px){.report-kpi-grid{grid-template-columns:repeat(3,1fr)}.report-alerts{grid-template-columns:1fr}}@media(max-width:650px){.report-kpi-grid{grid-template-columns:repeat(2,1fr)}}</style><script>document.querySelectorAll('.dbl-detail[data-url]').forEach(r=>r.addEventListener('dblclick',()=>location.href=r.dataset.url));</script>
<?php foot(); ?>
