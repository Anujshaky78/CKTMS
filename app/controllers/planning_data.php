<?php
// Planning read models for the tabs (trips, indents, trip detail, lookups).
// Included from pages/PLANNING.php; shares its variables ($d, $stores, $dcs, $msg, $err, $tab, …).

$tpts=$d->query("SELECT DISTINCT COALESCE(NULLIF(tpt_name,''),'TPT') tpt_name FROM drivers WHERE active IS TRUE ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$vehicles=$d->query("SELECT id,vehicle_number,capacity_boxes,value_capacity,status FROM vehicles WHERE active IS TRUE ORDER BY vehicle_number")->fetchAll(PDO::FETCH_ASSOC);
$drivers=$d->query("SELECT id,driver_code,driver_name,tpt_name,phone,status FROM drivers WHERE active IS TRUE ORDER BY driver_name")->fetchAll(PDO::FETCH_ASSOC);
$trips=$d->query("SELECT t.*,dc.dc_code,dc.dc_name,dc.latitude dc_latitude,dc.longitude dc_longitude,v.vehicle_number,d.driver_name,d.phone AS driver_phone,(SELECT COUNT(*) FROM tms_trip_stops ts0 WHERE ts0.trip_id=t.id) stop_count,(SELECT COALESCE(SUM(ts0.packet_count),0) FROM tms_trip_stops ts0 WHERE ts0.trip_id=t.id) stop_packets,(SELECT COALESCE(SUM(ts0.stop_value),0) FROM tms_trip_stops ts0 WHERE ts0.trip_id=t.id) stop_value,(SELECT STRING_AGG(zs.store_code,' → ' ORDER BY z.sequence_no) FROM tms_trip_stops z JOIN stores zs ON zs.id=z.store_id WHERE z.trip_id=t.id) AS store_route,(SELECT gp.id FROM tms_gate_passes gp WHERE gp.trip_id=t.id LIMIT 1) gate_pass_id,(SELECT gate_pass_no FROM tms_gate_passes gp WHERE gp.trip_id=t.id LIMIT 1) gate_pass_no,(SELECT gp.invoice_no FROM tms_gate_passes gp WHERE gp.trip_id=t.id LIMIT 1) gate_invoice_no,(SELECT gp.seal_no FROM tms_gate_passes gp WHERE gp.trip_id=t.id LIMIT 1) gate_seal_no,(SELECT gp.remarks FROM tms_gate_passes gp WHERE gp.trip_id=t.id LIMIT 1) gate_remarks FROM trips t LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN drivers d ON d.id=t.driver_id LEFT JOIN tms_dc_master dc ON dc.id=t.source_dc_id ORDER BY t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$tripEditStops=[]; $eq=$d->query("SELECT ts.id stop_id,ts.trip_id,ts.sequence_no,ts.store_id,ts.packet_count,ts.stop_value,s.store_code,s.store_name,(SELECT i.id FROM tms_indents i WHERE i.trip_id=ts.trip_id AND i.destination_store_id=ts.store_id ORDER BY i.id DESC LIMIT 1) indent_id FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id ORDER BY ts.trip_id,ts.sequence_no"); foreach($eq->fetchAll(PDO::FETCH_ASSOC) as $es){ $tripEditStops[(int)$es['trip_id']][]=$es; }
$tripAddIndents=$d->query("SELECT i.id,i.indent_no,i.packet_count,i.indent_value,i.destination_store_id,i.source_dc_id,dc.dc_code,s.store_code,s.store_name,s.city,s.state FROM tms_indents i JOIN stores s ON s.id=i.destination_store_id LEFT JOIN tms_dc_master dc ON dc.id=i.source_dc_id WHERE i.status='Planned' AND i.trip_id IS NULL AND COALESCE(s.active,true)=true ORDER BY i.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$gatepasses=$d->query("SELECT gp.*,t.trip_code,t.status trip_status,t.planned_date,v.vehicle_number,d.driver_name FROM tms_gate_passes gp JOIN trips t ON t.id=gp.trip_id LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN drivers d ON d.id=t.driver_id ORDER BY gp.id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
$gateReady=array_values(array_filter($trips,fn($t)=>$t['status']==='Loaded'));

// Route distance is derived from the saved best-stop sequence so the Trip Register never depends on a missing DB column.
$stopQ=$d->prepare("SELECT ts.sequence_no,s.latitude,s.longitude FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id WHERE ts.trip_id=? ORDER BY ts.sequence_no");
foreach($trips as &$tr){
  $sq=$d->prepare("SELECT ts.sequence_no,s.store_code,s.latitude,s.longitude FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id WHERE ts.trip_id=? ORDER BY ts.sequence_no");$sq->execute([(int)$tr['id']]);$rs=$sq->fetchAll(PDO::FETCH_ASSOC);
  $cur=['latitude'=>$tr['dc_latitude']??0,'longitude'=>$tr['dc_longitude']??0];$rk=0.0;$maxLeg=0.0;$prev=null;$codes=[];$legList=[];
  foreach($rs as $rr){$rk+=distance_km($cur,$rr);$cur=$rr;$codes[]=$rr['store_code'];if($prev!==null){$sl=distance_km($prev,$rr);$maxLeg=max($maxLeg,$sl);$legList[]=$sl;}$prev=$rr;}
  $tr['route_distance_km']=$rk;$tr['store_route']=$codes?implode(' → ',$codes):'—';$tr['max_store_leg_km']=$legList?max($legList):0.0;$tr['store_leg_km']=$legList;$tr['edit_stops']=$tripEditStops[(int)$tr['id']]??[];
}
unset($tr);
$indents=$d->query("SELECT i.*,s.store_code,s.store_name,dc.dc_code,dc.dc_name,t.trip_code FROM tms_indents i JOIN stores s ON s.id=i.destination_store_id LEFT JOIN tms_dc_master dc ON dc.id=i.source_dc_id LEFT JOIN trips t ON t.id=i.trip_id ORDER BY i.id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
$view=(int)($_GET['view']??0);$trip=null;$stops=[];$history=[];$gate=null;if($view){$q=$d->prepare("SELECT t.*,dc.dc_code,dc.dc_name,dc.latitude dc_latitude,dc.longitude dc_longitude,v.vehicle_number,v.vehicle_type,d.driver_name,d.phone AS driver_phone FROM trips t LEFT JOIN tms_dc_master dc ON dc.id=t.source_dc_id LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN drivers d ON d.id=t.driver_id WHERE t.id=?");$q->execute([$view]);$trip=$q->fetch(PDO::FETCH_ASSOC);if($trip){
  if(!empty($trip['dispatched_at'])){
    $d->prepare("UPDATE tms_trip_stops ts SET expected_delivery_at=COALESCE(ts.expected_delivery_at,(SELECT t.dispatched_at + (COALESCE(s.transit_days,1)*interval '1 day') FROM trips t JOIN stores s ON s.id=ts.store_id WHERE t.id=ts.trip_id)) WHERE ts.trip_id=? AND ts.status<>'Delivered'")->execute([$view]);
  }
  $q=$d->prepare("SELECT ts.*,s.store_code,s.store_name,s.city,s.state,s.address,s.pincode,s.latitude,s.longitude,s.transit_days,CASE WHEN ts.status='Delivered' THEN 'Delivered' WHEN ts.expected_delivery_at IS NOT NULL AND CURRENT_TIMESTAMP>ts.expected_delivery_at THEN 'Delayed' ELSE CASE WHEN t.status='In Transit' THEN 'In Transit' ELSE ts.status END END effective_status FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id JOIN trips t ON t.id=ts.trip_id WHERE ts.trip_id=? ORDER BY sequence_no");$q->execute([$view]);$stops=$q->fetchAll(PDO::FETCH_ASSOC);
  $q=$d->prepare("SELECT * FROM tms_trip_status_history WHERE trip_id=? ORDER BY id DESC");$q->execute([$view]);$history=$q->fetchAll(PDO::FETCH_ASSOC);$q=$d->prepare("SELECT * FROM tms_gate_passes WHERE trip_id=?");$q->execute([$view]);$gate=$q->fetch(PDO::FETCH_ASSOC);
  $trip['edit_stops']=$tripEditStops[$view]??[];
}}

// Planned (not yet dispatched) trips that break the trip limits, e.g. created before the rule existed
$overLimit = [];
try {
  $ol = $d->prepare("SELECT t.id,t.trip_code,COUNT(ts.id) stops,COALESCE(SUM(ts.packet_count),0) pkt FROM trips t JOIN tms_trip_stops ts ON ts.trip_id=t.id
    WHERE t.status IN ('Planned','Assigned','Loaded') GROUP BY t.id,t.trip_code HAVING COUNT(ts.id)>? OR COALESCE(SUM(ts.packet_count),0)>? ORDER BY t.id DESC LIMIT 50");
  $ol->execute([tms_trip_max_stores(), tms_trip_max_pkt()]); $overLimit = $ol->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
