<div class="trip-detail-layout simple-trip-open">
 <aside class="trip-side">
  <div class="trip-side-head"><div><div class="section-title"><?=e($trip['trip_code'])?></div><div class="note">Trip Open</div></div><a class="btn light" href="PLANNING.php?tab=trips">Back</a></div>
  <div class="simple-trip-fields">
   <div><small>TPT Name</small><b><?=e($trip['tpt_name']??'Not Assigned')?></b></div>
   <div><small>Vehicle No.</small><b><?=e($trip['vehicle_number']??'Not Assigned')?></b></div>
   <div><small>Driver Name</small><b><?=e($trip['driver_name']??'Not Assigned')?></b></div>
   <div><small>Driver Mobile No.</small><b><?=e($trip['driver_phone']??'—')?></b></div>
  </div>
  <div class="simple-trip-actions">
   <?php if(in_array(($trip['status']??''),['Planned','Assigned'],true)): ?><button type="button" class="btn primary" onclick='acceptTrip(<?=json_encode($trip,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>Accept / Assign</button><?php endif; ?>
   <?php if(($trip['status']??'')==='Assigned'): ?><form method="post"><input type="hidden" name="action" value="load_trip"><input type="hidden" name="trip_id" value="<?=$trip['id']?>"><button class="btn primary">Load Trip</button></form><?php endif; ?>
   <?php if(in_array(($trip['status']??''),['Loaded','Gate Pass Created'],true)): ?><a class="btn success" href="PLANNING.php?tab=gatepasses&trip_id=<?=$trip['id']?>"><?=($trip['status']==='Loaded'?'Create Gate Pass':'Open Gate Pass')?></a><?php endif; ?>
   <?php if(($trip['status']??'')==='Gate Pass Created'): ?><form method="post"><input type="hidden" name="action" value="dispatch_gatepass"><input type="hidden" name="trip_id" value="<?=$trip['id']?>"><button class="btn primary">Dispatch</button></form><?php endif; ?>
   <?php if(($trip['status']??'')==='Gate Pass Created' && !empty($gate)): ?><button type="button" class="btn light" onclick='editGate(<?=json_encode($gate,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>Edit Gate Pass</button><form method="post"><input type="hidden" name="action" value="delete_gatepass"><input type="hidden" name="gatepass_id" value="<?=$gate['id']?>"><button class="btn light" type="submit" onclick="return confirm('Gate Pass delete karein? Trip Loaded me wapas aa jayega.')">Delete Gate Pass</button></form><?php endif; ?>
   <button type="button" class="btn light" onclick='editTrip(<?=json_encode($trip,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>Edit / Add / Remove Store</button>
  </div>
 </aside>
 <main class="trip-map-full"><div class="map-topbar"><div><div class="section-title">Trip Route Map</div><div class="note">Center DC → store route</div></div><div id="routeNote" class="note">Loading road route…</div></div><div id="tripMap" class="trip-map"></div></main>
</div>
