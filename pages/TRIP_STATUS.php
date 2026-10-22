<?php
require_once __DIR__.'/../auth/auth.php'; require_once __DIR__.'/../config/DB.php'; require_once __DIR__.'/../includes/layout.php'; require_login();
$d=db(); $msg=''; $err=''; $embedded=(($_GET['embedded']??'')==='1');
/* Ensure SLA columns exist even when Trip Status is opened directly. */
foreach([
 "ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS expected_delivery_at TIMESTAMP NULL",
 "ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS delivery_result VARCHAR(40) NULL",
 "ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS delivery_delay_days NUMERIC(10,2) NOT NULL DEFAULT 0",
 "ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS delivery_remarks TEXT NULL"
] as $sql){try{$d->exec($sql);}catch(Throwable $ignore){}}

function set_trip_expected(PDO $d,int $tripId): void{
  $q=$d->prepare("SELECT ts.id,COALESCE(s.transit_days,1) transit_days FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id WHERE ts.trip_id=? AND ts.status<>'Delivered'");
  $q->execute([$tripId]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
    $d->prepare("UPDATE tms_trip_stops SET expected_delivery_at=COALESCE(expected_delivery_at,(SELECT dispatched_at FROM trips WHERE id=?)+( ? * interval '1 day')) WHERE id=?")
      ->execute([$tripId,(float)$r['transit_days'],(int)$r['id']]);
  }
}
if(!function_exists('distance_km')){
function distance_km($a,$b):float{ $lat1=(float)($a['latitude']??0);$lon1=(float)($a['longitude']??0);$lat2=(float)($b['latitude']??0);$lon2=(float)($b['longitude']??0); if(!$lat1&&!$lon1&&!$lat2&&!$lon2)return 0; $p=pi()/180;$x=($lon2-$lon1)*$p*cos(($lat1+$lat2)*$p/2);$y=($lat2-$lat1)*$p;return sqrt($x*$x+$y*$y)*6371; }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $action=$_POST['action']??'';
  if($action==='trip_status'){
   $tripId=(int)$_POST['trip_id']; $status=trim($_POST['status']??''); $remarks=trim($_POST['remarks']??'');
   if(!in_array($status,['In Transit','Breakdown','Accident','Delivered'],true)) throw new Exception('Invalid trip status.');
   $q=$d->prepare('SELECT * FROM trips WHERE id=?');$q->execute([$tripId]);$tr=$q->fetch(PDO::FETCH_ASSOC);if(!$tr)throw new Exception('Trip not found.');
   /* In Transit is ONLY allowed after Gate Pass has been created. */
   if($status==='In Transit' && !in_array($tr['status'],['Gate Pass Created','In Transit','Breakdown','Accident'],true)) throw new Exception('Gate Pass create hone ke baad hi In Transit karein.');
   if($status==='Delivered' && !in_array($tr['status'],['In Transit','Breakdown','Accident'],true)) throw new Exception('Trip Delivered karne se pehle In Transit hona chahiye.');
   $d->beginTransaction();
   if($status==='In Transit'){
    $d->prepare("UPDATE trips SET status='In Transit',dispatched_at=COALESCE(dispatched_at,CURRENT_TIMESTAMP) WHERE id=?")->execute([$tripId]);
    set_trip_expected($d,$tripId);
    if(!empty($tr['vehicle_id']))$d->prepare("UPDATE vehicles SET status='Dispatched' WHERE id=?")->execute([(int)$tr['vehicle_id']]);
    if(!empty($tr['driver_id']))$d->prepare("UPDATE drivers SET status='Dispatched' WHERE id=?")->execute([(int)$tr['driver_id']]);
   }elseif($status==='Delivered'){
    set_trip_expected($d,$tripId);
    $d->prepare("UPDATE tms_trip_stops SET status='Delivered',delivered_at=COALESCE(delivered_at,CURRENT_TIMESTAMP),delivery_result=CASE WHEN expected_delivery_at IS NULL OR CURRENT_TIMESTAMP<=expected_delivery_at THEN 'On Time Delivered' ELSE 'Late Delivered' END,delivery_delay_days=CASE WHEN expected_delivery_at IS NULL OR CURRENT_TIMESTAMP<=expected_delivery_at THEN 0 ELSE ROUND(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP-expected_delivery_at))/86400.0,2) END,delivery_remarks=COALESCE(NULLIF(?,''),delivery_remarks) WHERE trip_id=? AND status<>'Delivered'")->execute([$remarks,$tripId]);
    $d->prepare("UPDATE tms_indents SET status='Delivered' WHERE trip_id=?")->execute([$tripId]);
    $d->prepare("UPDATE trips SET status='Delivered',completed_at=COALESCE(completed_at,CURRENT_TIMESTAMP) WHERE id=?")->execute([$tripId]);
    if(!empty($tr['vehicle_id']))$d->prepare("UPDATE vehicles SET status='Available' WHERE id=?")->execute([(int)$tr['vehicle_id']]);
    if(!empty($tr['driver_id']))$d->prepare("UPDATE drivers SET status='Available' WHERE id=?")->execute([(int)$tr['driver_id']]);
   }else{
    $d->prepare("UPDATE trips SET status=? WHERE id=?")->execute([$status,$tripId]);
   }
   $d->prepare('INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)')->execute([$tripId,$status,$remarks,$_SESSION['user_id']??null]);
   $d->commit();$msg='Trip '.$tr['trip_code'].' updated to '.$status.'.';
  }
  elseif($action==='stop_status'){
   $stopId=(int)$_POST['stop_id'];$status=trim($_POST['stop_status']??'');$remarks=trim($_POST['remarks']??'');
   if(!in_array($status,['In Transit','Delayed','Delivered'],true))throw new Exception('Invalid store status.');
   $q=$d->prepare("SELECT ts.*,t.trip_code,t.status trip_status,t.vehicle_id,t.driver_id,t.dispatched_at,s.store_code,s.store_name,s.transit_days FROM tms_trip_stops ts JOIN trips t ON t.id=ts.trip_id JOIN stores s ON s.id=ts.store_id WHERE ts.id=?");$q->execute([$stopId]);$st=$q->fetch(PDO::FETCH_ASSOC);if(!$st)throw new Exception('Store stop not found.');
   if($st['trip_status']!=='In Transit' && $st['trip_status']!=='Breakdown' && $status!=='In Transit')throw new Exception('Store delivery update ke liye trip pehle In Transit hona chahiye.');
   $d->beginTransaction();
   if(!$st['expected_delivery_at'] && !empty($st['dispatched_at'])){
    $expected=date('Y-m-d H:i:s',strtotime($st['dispatched_at'].' + '.((float)($st['transit_days']?:1)).' days'));
   }else $expected=$st['expected_delivery_at'];
   $result=null;$delayDays=0;$del=$status==='Delivered';
   if($del){$actual=time();$expTs=$expected?strtotime($expected):$actual;$delayDays=max(0,round(($actual-$expTs)/86400,2));$result=$delayDays>0?'Late Delivered':'On Time Delivered';}
   $d->prepare("UPDATE tms_trip_stops SET status=?,expected_delivery_at=COALESCE(expected_delivery_at,?::timestamp),delivered_at=CASE WHEN ? THEN CURRENT_TIMESTAMP ELSE delivered_at END,delivery_remarks=COALESCE(NULLIF(?,''),delivery_remarks),delivery_result=?,delivery_delay_days=? WHERE id=?")->execute([$status,$expected,$del,$remarks?:null,$result,$delayDays,$stopId]);
   $d->prepare("UPDATE tms_indents SET status=? WHERE trip_id=? AND destination_store_id=?")->execute([$del?'Delivered':'In Transit',(int)$st['trip_id'],(int)$st['store_id']]);
   $d->prepare('INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)')->execute([(int)$st['trip_id'],'Store: '.$status,$st['store_code'].($remarks?' | '.$remarks:'').($result?' | '.$result:'') ,$_SESSION['user_id']??null]);
   $left=$d->prepare("SELECT COUNT(*) FROM tms_trip_stops WHERE trip_id=? AND status<>'Delivered'");$left->execute([(int)$st['trip_id']]);$remaining=(int)$left->fetchColumn();
   if($remaining===0){$d->prepare("UPDATE trips SET status='Delivered',completed_at=COALESCE(completed_at,CURRENT_TIMESTAMP) WHERE id=?")->execute([(int)$st['trip_id']]);if(!empty($st['vehicle_id']))$d->prepare("UPDATE vehicles SET status='Available' WHERE id=?")->execute([(int)$st['vehicle_id']]);if(!empty($st['driver_id']))$d->prepare("UPDATE drivers SET status='Available' WHERE id=?")->execute([(int)$st['driver_id']]);}
   else{$d->prepare("UPDATE trips SET status='In Transit' WHERE id=? AND status IN ('In Transit','Breakdown')")->execute([(int)$st['trip_id']]);}
   $d->commit();$msg='Store '.$st['store_code'].' updated to '.$status.'.'.($remaining===0?' Trip completed.':'');
  }
 }catch(Throwable $e){if($d->inTransaction())$d->rollBack();$err=$e->getMessage();}
}


$trips=$d->query("SELECT t.*,dc.dc_code,dc.latitude dc_latitude,dc.longitude dc_longitude,v.vehicle_number,dr.driver_name,dr.phone driver_phone FROM trips t LEFT JOIN tms_dc_master dc ON dc.id=t.source_dc_id LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN drivers dr ON dr.id=t.driver_id ORDER BY t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$stopsByTrip=[];
$sq=$d->query("SELECT ts.*,s.store_code,s.store_name,s.latitude,s.longitude FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id ORDER BY ts.trip_id,ts.sequence_no");
foreach($sq->fetchAll(PDO::FETCH_ASSOC) as $row){ $stopsByTrip[(int)$row['trip_id']][]=$row; }
foreach($trips as &$t){
  $stops=$stopsByTrip[(int)$t['id']]??[];
  $t['stops']=$stops;
  $t['destinations']=implode(', ',array_column($stops,'store_code'));
  $cur=['latitude'=>$t['dc_latitude']??0,'longitude'=>$t['dc_longitude']??0]; $dist=0.0;
  // A point without coordinates (0,0) gives a meaningless distance; show it as unknown
  $noGeo=fn($p)=>abs((float)($p['latitude']??0))<0.0001 && abs((float)($p['longitude']??0))<0.0001;
  $unknown=$noGeo($cur); foreach($stops as $st){ if($noGeo($st)){ $unknown=true; continue; } if(!$noGeo($cur)) $dist+=distance_km($cur,$st); $cur=$st; }
  $t['distance_km']=$unknown?null:$dist;
  $expArr=array_filter(array_column($stops,'expected_delivery_at'));
  $t['expected_all']=$expArr?max($expArr):null;
  $delArr=array_filter(array_column($stops,'delivered_at'));
  $t['actual_all']=$t['status']==='Delivered'&&$delArr?max($delArr):null;
  $t['stop_count']=count($stops);
  $t['delivered_count']=count(array_filter($stops,fn($x)=>$x['status']==='Delivered'));
}
unset($t);
if(!$embedded) head('Trip Status');
?>
<div class="page-title"><div><h2>Trip Status</h2><div class="note">Trip No., route, fleet and delivery status — update status here as trips move.</div></div><a class="btn light" href="PLANNING.php?tab=trips">Open Planning</a></div>
<?php $__pills = function (array $rows, string $key, array $first, string $total) { $c = tms_count_by($rows, $key); $o = [[$total, count($rows), 'dark']];
  $tone = ['Delivered' => 'good', 'Cancelled' => 'bad', 'Breakdown' => 'bad', 'Accident' => 'bad', 'In Transit' => 'warn'];
  foreach ($first as $k) if (!empty($c[$k])) { $o[] = [$k === 'Gate Pass Created' ? 'Gate pass' : $k, $c[$k], $tone[$k] ?? '']; unset($c[$k]); }
  foreach ($c as $k => $n) if ($k !== '') $o[] = [$k, $n, $tone[$k] ?? '']; return $o; }; echo tms_stats($__pills($trips, 'status', ['Planned', 'Assigned', 'Loaded', 'Gate Pass Created', 'In Transit', 'Delivered', 'Breakdown', 'Accident', 'Cancelled'], 'trips')); ?>
<?php if($msg):?><div class="alert" style="background:#ecfdf5;color:#047857;border-color:#a7f3d0"><?=e($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert" style="background:#fef2f2;color:#b91c1c;border-color:#fecaca"><?=e($err)?></div><?php endif;?>
<div class="toolbar"><input class="input search" id="q" placeholder="Search trip / store / vehicle / driver" oninput="filterTS()"><select class="select" id="statusFilter" onchange="filterTS()"><option value="all">All Status</option><?php foreach(['Planned','Assigned','Loaded','Gate Pass Created','In Transit','Breakdown','Accident','Delivered'] as $st):?><option value="<?=e($st)?>"><?=e($st)?></option><?php endforeach;?></select></div>
<div class="tablewrap"><table class="table">
<tr><th>Trip No.</th><th>Date</th><th>Source</th><th>Destination(s)</th><th>Distance</th><th>Driver</th><th>Vehicle</th><th>TPT</th><th>Expected Delivery</th><th>Actual Delivery</th><th>Current Status</th><th>Action</th></tr>
<?php foreach($trips as $t):
 $cls=$t['status']==='Delivered'?'b-green':(in_array($t['status'],['In Transit'],true)?'b-blue':(in_array($t['status'],['Breakdown','Accident'],true)?'b-red':'b-amber'));
 $searchStr=strtolower($t['trip_code'].' '.($t['vehicle_number']??'').' '.($t['driver_name']??'').' '.($t['tpt_name']??'').' '.$t['destinations']);
?>
<tr class="ts-row" data-search="<?=e($searchStr)?>" data-status="<?=e($t['status'])?>">
 <td><b><?=e($t['trip_code'])?></b></td>
 <td><?=e($t['planned_date']??$t['dispatch_date']??'—')?></td>
 <td><?=e($t['dc_code']??'—')?></td>
 <td><?=e($t['destinations']?:'—')?></td>
 <td><?=$t['distance_km']>0?number_format($t['distance_km'],1).' km':'—'?></td>
 <td><?=e($t['driver_name']??'Not Assigned')?></td>
 <td><?=e($t['vehicle_number']??'Not Assigned')?></td>
 <td><?=e($t['tpt_name']??'—')?></td>
 <td><?=$t['expected_all']?e(date('d-m-Y H:i',strtotime($t['expected_all']))):'—'?></td>
 <td><?=$t['actual_all']?e(date('d-m-Y H:i',strtotime($t['actual_all']))):'—'?></td>
 <td><span class="badge <?=$cls?>"><?=e($t['status'])?></span></td>
 <td>
  <div class="row-action"><button type="button" class="dots-btn" onclick="toggleRowMenu('tsMenu<?=$t['id']?>',event)">⋮</button>
  <div class="row-menu" id="tsMenu<?=$t['id']?>">
   <?php if($t['status']==='Gate Pass Created'):?><form method="post"><input type="hidden" name="action" value="trip_status"><input type="hidden" name="trip_id" value="<?=$t['id']?>"><input type="hidden" name="status" value="In Transit"><button type="submit">Mark In Transit</button></form><?php endif;?>
   <?php if(in_array($t['status'],['In Transit','Breakdown','Accident'],true)):?>
    <button type="button" onclick="openStopUpdate(<?=$t['id']?>);closeRowMenus()">Update Store Delivery</button>
    <form method="post"><input type="hidden" name="action" value="trip_status"><input type="hidden" name="trip_id" value="<?=$t['id']?>"><input type="hidden" name="status" value="Breakdown"><button type="submit" <?=$t['status']==='Breakdown'?'disabled':''?>>Mark Breakdown</button></form>
    <form method="post"><input type="hidden" name="action" value="trip_status"><input type="hidden" name="trip_id" value="<?=$t['id']?>"><input type="hidden" name="status" value="Accident"><button type="submit" <?=$t['status']==='Accident'?'disabled':''?>>Mark Accident</button></form>
    <form method="post"><input type="hidden" name="action" value="trip_status"><input type="hidden" name="trip_id" value="<?=$t['id']?>"><input type="hidden" name="status" value="Delivered"><button type="submit">Mark Delivered</button></form>
   <?php endif;?>
   <a href="PLANNING.php?tab=trips&view=<?=$t['id']?>">Open Trip Detail</a>
  </div></div>
 </td>
</tr>
<?php endforeach;?>
<?php if(!$trips):?><tr><td colspan="12" class="note" style="text-align:center;padding:24px">No trips available.</td></tr><?php endif;?>
</table></div>
<div id="stopModal" class="record-modal" style="display:none"><div class="record-modal-box"><div class="record-modal-head"><b>Update Store Delivery</b><button type="button" onclick="document.getElementById('stopModal').style.display='none'">×</button></div><div id="stopModalBody" style="padding:16px"></div></div></div>
<script>
window.tsStopsByTrip=<?=json_encode(array_map(fn($t)=>array_map(fn($s)=>['stop_id'=>$s['id'],'store_code'=>$s['store_code'],'store_name'=>$s['store_name'],'status'=>$s['status'],'expected_delivery_at'=>$s['expected_delivery_at']],$t['stops']),array_combine(array_column($trips,'id'),$trips)),JSON_UNESCAPED_UNICODE)?>;
function filterTS(){const q=(document.getElementById('q').value||'').toLowerCase().trim(),s=document.getElementById('statusFilter').value;document.querySelectorAll('.ts-row').forEach(r=>{const ok=(!q||r.dataset.search.includes(q))&&(s==='all'||r.dataset.status===s);r.style.display=ok?'':'none';});}
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function openStopUpdate(tripId){
 const stops=tsStopsByTrip[tripId]||[];
 const rows=stops.map(s=>'<div style="display:flex;gap:8px;align-items:center;padding:8px 0;border-bottom:1px solid #edf0f4">'
  +'<b style="min-width:90px">'+esc(s.store_code)+'</b>'
  +'<span class="note" style="flex:1">'+esc(s.status)+(s.expected_delivery_at?(' · Exp: '+esc(s.expected_delivery_at)):'')+'</span>'
  +(s.status!=='Delivered'?('<form method="post" style="display:flex;gap:6px"><input type="hidden" name="action" value="stop_status"><input type="hidden" name="stop_id" value="'+s.stop_id+'"><select class="select" name="stop_status"><option>In Transit</option><option>Delayed</option><option>Delivered</option></select><input class="input" name="remarks" placeholder="Remarks" style="min-width:120px"><button class="btn success">Save</button></form>'):'<span class="badge b-green">Delivered</span>')
  +'</div>').join('');
 document.getElementById('stopModalBody').innerHTML=rows||'<div class="note">No stores on this trip.</div>';
 document.getElementById('stopModal').style.display='flex';
}
function closeRowMenus(){document.querySelectorAll('.row-menu.open').forEach(x=>x.classList.remove('open'));}
function toggleRowMenu(id,ev){ev.stopPropagation();const el=document.getElementById(id);const was=el.classList.contains('open');closeRowMenus();if(!was){el.classList.add('open');el.style.position='fixed';const r=ev.target.getBoundingClientRect();el.style.top=(r.bottom+4)+'px';el.style.left=Math.min(r.left,window.innerWidth-210)+'px';}}
document.addEventListener('click',e=>{if(!e.target.closest('.row-action'))closeRowMenus();});
</script>
<style>
.row-action{position:relative;display:inline-block}.dots-btn{width:28px;height:28px;border:1px solid #d8e0ea;background:#fff;border-radius:7px;font-size:18px;line-height:18px;cursor:pointer;color:#334155}.dots-btn:hover{background:#f1f5f9}.row-menu{display:none;position:fixed;z-index:9999;min-width:190px;background:#fff;border:1px solid #dbe3ec;border-radius:9px;box-shadow:0 15px 35px rgba(15,23,42,.18);padding:5px}.row-menu.open{display:block}.row-menu button,.row-menu a,.row-menu form{display:block;width:100%}.row-menu button,.row-menu a{border:0;background:#fff;text-align:left;padding:9px 10px;border-radius:6px;font:inherit;font-size:11px;color:#334155;text-decoration:none;cursor:pointer;box-sizing:border-box}.row-menu button:hover,.row-menu a:hover{background:#f1f5f9}.row-menu button[disabled]{opacity:.4;cursor:not-allowed}
.record-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:5000;align-items:center;justify-content:center;padding:20px}.record-modal-box{width:min(560px,96vw);max-height:85vh;overflow:auto;background:#fff;border-radius:12px;box-shadow:0 25px 80px rgba(0,0,0,.3)}.record-modal-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #e5e7eb}.record-modal-head button{border:0;background:#f1f5f9;border-radius:6px;font-size:20px;width:32px;height:32px}
</style>
<?php if(!$embedded) foot(); ?>
