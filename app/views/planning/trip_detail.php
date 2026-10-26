<?php
// Summary, route with km, stops with their status and the history of this trip (data comes from planning_data.php: $trip, $stops, $history).
$tdSt = (string)($trip['status'] ?? ''); $tdMax = (int)tms_trip_max_pkt();
$tdGeo = fn($lat, $lon) => ((float)$lat || (float)$lon) ? ['lat' => (float)$lat, 'lng' => (float)$lon] : null;
$tdPrev = $tdGeo($trip['dc_latitude'] ?? 0, $trip['dc_longitude'] ?? 0); $tdKm = []; $tdTot = 0;
foreach ($stops as $i => $s) { $g = $tdGeo($s['latitude'] ?? 0, $s['longitude'] ?? 0); $k = prd_km($tdPrev, $g); $tdKm[$i] = $k === null ? null : (int)round($k); if ($k !== null) $tdTot += (int)round($k); if ($g) $tdPrev = $g; }
$tdPkt = (int)($trip['total_boxes'] ?? 0);
?>
<link rel="stylesheet" href="<?= e(asset('assets/css/trips.css')) ?>">
<div class="trip-detail-layout simple-trip-open">
 <aside class="trip-side tr">
  <div class="trip-side-head"><div><div class="section-title"><?=e($trip['trip_code'])?></div><div class="note"><span class="tr-st st-<?= e(str_replace(' ', '-', $tdSt)) ?>"><?= e($tdSt === 'Gate Pass Created' ? 'Gate pass' : $tdSt) ?></span></div></div><a class="btn light" href="PLANNING.php?tab=trips">Back</a></div>
  <div class="td-sum"><div><small>Load</small><b><?= number_format($tdPkt) ?></b> / <?= $tdMax ?> PKT</div><div><small>Stores</small><b><?= count($stops) ?></b></div><div><small>Route</small><b><?= $tdTot ?: '—' ?></b><?= $tdTot ? ' km' : '' ?></div></div>
  <div class="td-h">Route</div>
  <ol class="td-stops">
    <li class="dc"><span class="no" style="background:#101828">DC</span><div><b><?= e($trip['dc_code'] ?? 'DC') ?></b><small><?= e($trip['dc_name'] ?? 'Loading point') ?></small></div></li>
    <?php foreach ($stops as $i => $s): $es = (string)($s['effective_status'] ?? $s['status'] ?? ''); ?>
    <li><span class="no"><?= $i + 1 ?></span><div><b><?= e($s['store_code']) ?></b> <?= e($s['store_name']) ?><?= !empty($s['city']) ? ', ' . e($s['city']) : '' ?>
        <small><?= (int)$s['packet_count'] ?> PKT<?= !empty($s['cluster_type']) && $s['cluster_type'] !== '-' ? ' · ' . e($s['cluster_type']) : '' ?> · <?= $tdKm[$i] !== null ? ($i === 0 ? 'from DC ' : '+') . $tdKm[$i] . ' km' : 'km n/a' ?></small></div>
      <span class="tr-st st-<?= e(str_replace(' ', '-', $es)) ?>" style="font-size:11px"><?= e($es) ?></span></li>
    <?php endforeach; ?>
  </ol>
  <div class="simple-trip-fields">
   <div><small>TPT Name</small><b><?=e($trip['tpt_name']??'Not Assigned')?></b></div>
   <div><small>Vehicle No.</small><b><?=e($trip['vehicle_number']??'Not Assigned')?></b><?php $tu=trim((string)($trip['tracking_url']??'')); if($tu!==''&&preg_match('~^https?://~i',$tu)): ?> <a class="btn light" style="height:26px;padding:0 10px;font-size:12px;margin-left:6px" href="<?=e($tu)?>" target="_blank" rel="noopener noreferrer" onclick="return tmsTrack(this.href)">📍 Track live</a><?php endif; ?></div>
   <?php require_once TMS_ROOT.'/app/services/tracking.php'; tms_trk_autoimport($d); $__lv=tms_trk_latest($d)[tms_trk_norm((string)($trip['vehicle_number']??''))]??null; if($__lv): ?>
   <div style="grid-column:span 2" id="trkLive"><small>Live position (<?=e(implode(' + ',$__lv['sources']))?>)</small><b><span class="tk-pill <?=$__lv['state']==='RUNNING'?'tk-run':(in_array($__lv['state'],['NO SIGNAL','NOT WORKING'],true)?'tk-lost':'tk-stop')?>"><?=e($__lv['state'])?></span>
     <?=$__lv['speed']!==null?e(round((float)$__lv['speed'])).' km/h · ':''?><span class="<?=$__lv['fresh']==='ok'?'':($__lv['fresh']==='lost'?'tk-lostt':'tk-old')?>"><?=e(tms_trk_age($__lv['age_min']))?></span></b>
     <small style="display:block;margin-top:2px"><?=e((string)($__lv['location']??'—'))?><?=$__lv['day_km']!==null?' · today '.e(number_format((float)$__lv['day_km'],1)).' km':''?><?=$__lv['idle_min']!==null?' · idle '.e(tms_trk_dur((int)$__lv['idle_min'])):''?></small></div>
   <?php endif; ?>
   <div><small>Driver Name</small><b><?=e($trip['driver_name']??'Not Assigned')?></b></div>
   <div><small>Driver Mobile No.</small><b><?=e($trip['driver_phone']??'—')?></b></div>
  </div>
  <div class="simple-trip-actions">
   <?php if(can('plan')&&in_array(($trip['status']??''),['Planned','Assigned'],true)): ?><button type="button" class="btn primary" onclick='acceptTrip(<?=json_encode($trip,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>Accept / Assign</button><?php endif; ?>
   <?php if(can('load')&&($trip['status']??'')==='Assigned'): ?><form method="post"><input type="hidden" name="action" value="load_trip"><input type="hidden" name="trip_id" value="<?=$trip['id']?>"><button class="btn primary">Load Trip</button></form><?php endif; ?>
   <?php if((($trip['status']??'')==='Loaded'&&can('load'))||($trip['status']??'')==='Gate Pass Created'): ?><a class="btn success" href="PLANNING.php?tab=gatepasses&trip_id=<?=$trip['id']?>"><?=($trip['status']==='Loaded'?'Create Gate Pass':'Open Gate Pass')?></a><?php endif; ?>
   <?php if(can('load')&&($trip['status']??'')==='Gate Pass Created'): ?><form method="post"><input type="hidden" name="action" value="dispatch_gatepass"><input type="hidden" name="trip_id" value="<?=$trip['id']?>"><button class="btn primary">Dispatch</button></form><?php endif; ?>
   <?php if(($trip['status']??'')==='Gate Pass Created' && !empty($gate)): ?><?php if(can('load')): ?><button type="button" class="btn light" onclick='editGate(<?=json_encode($gate,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>Edit Gate Pass</button><?php endif; ?><?php if(can('delete')): ?><form method="post"><input type="hidden" name="action" value="delete_gatepass"><input type="hidden" name="gatepass_id" value="<?=$gate['id']?>"><button class="btn light" type="submit" onclick="return confirm('Gate Pass delete karein? Trip Loaded me wapas aa jayega.')">Delete Gate Pass</button></form><?php endif; ?><?php endif; ?>
   <?php if(can('track')&&in_array(($trip['status']??''),['Gate Pass Created','In Transit','Breakdown','Accident'],true)): ?><a class="btn light" href="PLANNING.php?tab=tripstatus">Update status (In Transit / Delivered)</a><?php endif; ?>
   <?php if(can('plan')): ?><button type="button" class="btn light" onclick='editTrip(<?=json_encode($trip,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>Edit stores</button><?php endif; ?>
  </div>
  <?php if ($history): ?><div class="td-h">History</div>
  <ul class="td-hist"><?php foreach (array_slice($history, 0, 10) as $h): ?><li><b><?= e($h['status']) ?></b><small><?= e(date('d M, H:i', strtotime((string)$h['created_at']))) ?><?= !empty($h['remarks']) ? ' · ' . e($h['remarks']) : '' ?></small></li><?php endforeach; ?></ul><?php endif; ?>
 </aside>
 <main class="trip-map-full"><div class="map-topbar"><div><div class="section-title">Trip Route Map</div><div class="note">Center DC → store route</div></div><div id="routeNote" class="note">Loading road route…</div></div><div id="tripMap" class="trip-map"></div></main>
</div>
