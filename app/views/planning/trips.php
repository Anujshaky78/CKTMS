<?php
// Trips tab on the data-table component (assets/js/dt.js): Search, Status, Start-date range, Reset, View (columns), sort, pagination, Export.
// One row per trip: route (DC → stop → stop with km), load vs the PKT limit, vehicle / driver, start date, status (dropdown = next step), actions.
// Trips are made from plans (Indents tab); the old "indent → trip" builder is only shown while old indents are waiting.
require_once TMS_ROOT . '/app/services/tracking.php'; tms_trk_autoimport($d); $trk = tms_trk_latest($d);   // last GPS position per vehicle (re-read only when a report file changed)
$maxPkt = (int)tms_trip_max_pkt(); $maxSt = (int)tms_trip_max_stores(); $minFill = (int)(prd_cfg()['min_fill_pct'] ?? 60);
$L = fn($v) => $v >= 10000000 ? number_format($v / 10000000, 2) . ' Cr' : ($v >= 100000 ? number_format($v / 100000, 2) . ' L' : number_format((float)$v, 0));
$geo = fn($lat, $lon) => ((float)$lat || (float)$lon) ? ['lat' => (float)$lat, 'lng' => (float)$lon] : null;
$STEPS = ['Planned', 'Assigned', 'Loaded', 'Gate Pass Created', 'In Transit', 'Delivered'];
$jf = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$nOpen = 0; $pktOpen = 0;
foreach ($trips as $x) if (!in_array($x['status'], ['Delivered', 'Cancelled'], true)) { $nOpen++; if (!in_array($x['status'], ['In Transit', 'Breakdown', 'Accident'], true)) $pktOpen += (int)$x['total_boxes']; }
$statusOpts = [['v' => 'open', 'l' => 'Open (not delivered / cancelled)', 'in' => ['Planned', 'Assigned', 'Reached at source', 'Loaded', 'Gate Pass Created', 'In Transit', 'Breakdown', 'Accident']],
  ['v' => 'Planned', 'l' => 'Pending'], ['v' => 'Assigned', 'l' => 'Assigned'], ['v' => 'Reached at source', 'l' => 'Reached at source'], ['v' => 'Loaded', 'l' => 'Loaded'], ['v' => 'Gate Pass Created', 'l' => 'Gate pass'], ['v' => 'In Transit', 'l' => 'In transit'],
  ['v' => 'problem', 'l' => 'Problem (breakdown / accident)', 'in' => ['Breakdown', 'Accident']], ['v' => 'Delivered', 'l' => 'Delivered'], ['v' => 'Cancelled', 'l' => 'Cancelled']];
// "Indent" column: plan number (from the plan line that made the trip) or the number of a hand-made indent
$indByTrip = []; $tripByCode = []; foreach ($trips as $x) $tripByCode[$x['trip_code']] = (int)$x['id'];
foreach ($d->query("SELECT l.trips, p.plan_no FROM tms_plan_lines l JOIN tms_plans p ON p.id=l.plan_id WHERE COALESCE(l.trips,'')<>''")->fetchAll(PDO::FETCH_ASSOC) as $pl)
  foreach (array_filter(array_map('trim', explode(',', (string)$pl['trips']))) as $c) if (isset($tripByCode[$c])) { $tid = $tripByCode[$c]; $indByTrip[$tid] = isset($indByTrip[$tid]) && !str_contains($indByTrip[$tid], $pl['plan_no']) ? $indByTrip[$tid] . ', ' . $pl['plan_no'] : ($indByTrip[$tid] ?? $pl['plan_no']); }
$tidList = implode(',', array_map('intval', array_column($trips, 'id')));
if ($tidList) foreach ($d->query("SELECT trip_id, STRING_AGG(indent_no, ', ' ORDER BY id) n FROM tms_indents WHERE trip_id IN ($tidList) AND indent_no NOT LIKE 'PRD-T%' GROUP BY trip_id")->fetchAll(PDO::FETCH_KEY_PAIR) as $tid => $n) if (empty($indByTrip[(int)$tid])) $indByTrip[(int)$tid] = $n;
echo tms_stats([['trips', count($trips), 'dark'], ['open', $nOpen, $nOpen ? 'warn' : ''], ['PKT waiting to leave', $pktOpen, $pktOpen ? 'warn' : '']]);
?>
<link rel="stylesheet" href="<?= e(asset('assets/css/dt.css')) ?>"><link rel="stylesheet" href="<?= e(asset('assets/css/trips.css')) ?>">
<script src="<?= e(asset('assets/js/dt.js')) ?>" defer></script>
<?php if (empty($allTrips) && count($trips) < ($tripTotal ?? 0)): ?><div class="note" style="margin:0 0 10px">Showing <?= count($trips) ?> of <?= (int)$tripTotal ?> trips: all active trips plus the latest 300 Delivered / Cancelled. <a href="PLANNING.php?tab=trips&amp;all=1">Show all trips</a></div><?php endif; ?>
<?php if (!empty($overLimit)): ?>
<details class="alert alert-err tr-over" role="alert"><summary><b><?= count($overLimit) ?> planned trip(s) are over the limit</b> of <?= $maxPkt ?> PKT / <?= $maxSt ?> stores. Fix with “Edit stores” before dispatch. <u>Show trips</u></summary>
<?= implode(', ', array_map(fn($t) => '<a href="PLANNING.php?tab=trips&amp;view=' . (int)$t['id'] . '">' . e($t['trip_code']) . '</a> (' . (int)$t['stops'] . ' stores, ' . (int)$t['pkt'] . ' PKT)', array_slice($overLimit, 0, 50))) ?></details>
<?php endif; ?>
<div class="tr">
<div class="dt" id="dtTrips" data-resize="1" data-key="trips2" data-default-status="open" data-page-size="25" data-date-label="Start date" data-export="trips" data-statuses="<?= e(json_encode($statusOpts)) ?>">
  <div data-dt-actions><?php if (can('plan')): ?><button type="button" class="dt-btn pri" onclick="fmOpen('dlgTrip')">＋ Create Trip</button><a class="dt-btn" href="PLANNING.php?tab=prd_pending" title="Tick stores on PRD Pending to make trips">From PRD Pending</a><?php endif; ?></div>
  <div class="dt-wrap"><table class="dt-table">
  <thead><tr><th data-sort="date">Trip date</th><th data-sort="text">Trip no</th><th data-sort="text">Source</th><th data-sort="text">Route</th><th data-sort="text">Destination</th><th data-sort="num" class="n">Distance (km)</th><th data-sort="text">Cluster</th><th data-sort="text">Vehicle</th><th data-sort="text" title="Last position from the GPS report files">Live</th><th data-sort="text">Driver</th><th data-sort="text">Transporter</th><th data-sort="text">Status</th><th data-nohide data-noexport>Action</th></tr></thead>
  <tbody>
  <?php foreach ($trips as $t):
    $stops = $t['edit_stops'] ?? []; $prev = $geo($t['dc_latitude'] ?? 0, $t['dc_longitude'] ?? 0); $legs = []; $totKm = 0; $unk = false; $clus = [];
    foreach ($stops as $i => $s) { $g = $geo($s['latitude'] ?? 0, $s['longitude'] ?? 0); $k = prd_km($prev, $g); $legs[$i] = $k === null ? null : (int)round($k); if ($k === null) $unk = true; else $totKm += (int)round($k); if ($g) $prev = $g; $c = trim((string)($s['cluster_type'] ?? '')); if ($c !== '' && $c !== '-') $clus[$c] = true; }
    $pkt = (int)$t['total_boxes']; $pct = $maxPkt ? (int)round($pkt * 100 / $maxPkt) : 0; $barCls = $pkt > $maxPkt ? 'over' : ($pct < $minFill ? 'low' : '');
    $st = (string)$t['status']; $si = array_search($st, $STEPS, true); $stc = 's-' . str_replace(' ', '-', $st); $stl = $st === 'Gate Pass Created' ? 'Gate pass' : ($st === 'Planned' ? 'Pending' : $st);
    $age = !empty($t['planned_date']) ? (int)floor((time() - strtotime((string)$t['planned_date'])) / 86400) : null;
    $date = !empty($t['planned_date']) ? date('Y-m-d', strtotime((string)$t['planned_date'])) : '';
    $q = strtolower($t['trip_code'] . ' ' . ($t['store_route'] ?? '') . ' ' . implode(' ', array_column($stops, 'store_name')) . ' ' . ($t['vehicle_number'] ?? '') . ' ' . ($t['driver_name'] ?? '') . ' ' . ($t['tpt_name'] ?? '') . ' ' . ($t['dc_code'] ?? '') . ' ' . $st . ' ' . $stl);
    $indNo = $indByTrip[(int)$t['id']] ?? ''; $q .= ' ' . strtolower($indNo);
    $editable = in_array($st, ['Planned', 'Assigned', 'Reached at source', 'Loaded'], true); $assigned = !empty($t['vehicle_number']) && $t['vehicle_number'] !== 'UNASSIGNED';
    $next = [];  // what the status dropdown offers: only moves this role may make and the transition rules allow
    if (can('plan') && $st === 'Planned') $next[] = 'Assigned';
    if (can('load') && $st === 'Assigned') $next[] = 'Loaded';
    if (can('load') && $st === 'Loaded') $next[] = 'Gate Pass Created';
    if (can('load') && $st === 'Gate Pass Created') $next[] = 'In Transit';
    if (can('track') && $st === 'In Transit') array_push($next, 'Breakdown', 'Accident', 'Delivered');
    if (can('track') && in_array($st, ['Breakdown', 'Accident'], true)) array_push($next, 'In Transit', 'Delivered');
    if (can('delete') && $editable) $next[] = 'Cancelled'; ?>
    <tr data-row data-status="<?= e($st) ?>" data-date="<?= e($date) ?>" data-q="<?= e($q) ?>" data-href="PLANNING.php?tab=trips&amp;view=<?= (int)$t['id'] ?>">
      <td data-v="<?= e($date) ?>" data-x="<?= e($date) ?>"><?= $date ? e(date('d M Y', strtotime($date))) : '—' ?></td>
      <td data-v="<?= e($t['trip_code']) ?>"><a class="tr-code" href="PLANNING.php?tab=trips&amp;view=<?= (int)$t['id'] ?>"><?= e($t['trip_code']) ?></a></td>
      <td data-v="<?= e($t['dc_code'] ?? '') ?>" title="<?= e($t['dc_name'] ?? '') ?>"><b><?= e($t['dc_code'] ?? 'DC') ?></b></td>
      <td data-v="<?= e(implode(' / ', array_column($stops, 'store_code'))) ?>" data-x="<?= e(implode(' / ', array_column($stops, 'store_code'))) ?>" title="<?= e(implode(' → ', array_map(fn($s) => $s['store_code'] . ' ' . ($s['store_name'] ?? ''), $stops))) ?>"><?= $stops ? e(implode(' / ', array_column($stops, 'store_code'))) : '<span class="tr-muted">–</span>' ?></td>
      <?php $last = $stops ? end($stops) : null; ?>
      <td data-v="<?= e($last['store_code'] ?? '') ?>" data-x="<?= e($last ? $last['store_code'] . ' ' . ($last['store_name'] ?? '') : '') ?>" title="<?= e($last ? $last['store_code'] . ' · ' . ($last['store_name'] ?? '') : '') ?>"><?php if ($last): ?><b><?= e($last['store_code']) ?></b><?php if (strcasecmp(trim((string)($last['store_name'] ?? '')), (string)$last['store_code']) !== 0): ?><span class="tr-muted"><?= e($last['store_name'] ?? '') ?></span><?php endif; ?><?php else: ?><span class="tr-muted">–</span><?php endif; ?></td>
      <td class="n" data-v="<?= (int)$totKm ?>" data-x="<?= (int)$totKm ?>"><?= $totKm ? ($unk ? '≥ ' : '') . number_format($totKm) : '<span class="tr-muted" title="Add store coordinates">–</span>' ?></td>
      <td data-v="<?= e(implode(', ', array_keys($clus))) ?>"><?= $clus ? e(implode(', ', array_keys($clus))) : '<span class="tr-muted">–</span>' ?></td>
      <td data-v="<?= e($assigned ? $t['vehicle_number'] : '') ?>"><?php if ($assigned): ?><b><?= e($t['vehicle_number']) ?></b><?php $tu = trim((string)($t['tracking_url'] ?? '')); if ($tu !== '' && preg_match('~^https?://~i', $tu)): ?> <a class="tr-call" href="<?= e($tu) ?>" target="_blank" rel="noopener noreferrer" onclick="return tmsTrack(this.href)" title="Track live (<?= e((string)($t['tracking_source'] ?: 'portal')) ?>)" aria-label="Track <?= e($t['vehicle_number']) ?> live"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></a><?php endif; ?><?php else: ?><span class="none">Not assigned</span><?php endif; ?></td>
      <?php $lv = $assigned ? ($trk[tms_trk_norm((string)$t['vehicle_number'])] ?? null) : null;
        if ($lv): $lc = $lv['state'] === 'RUNNING' ? 'tk-run' : (in_array($lv['state'], ['NO SIGNAL', 'NOT WORKING'], true) ? 'tk-lost' : 'tk-stop'); $lt = trim(($lv['state'] ?? '') . ($lv['speed'] !== null ? ' · ' . round((float)$lv['speed']) . ' km/h' : '') . ' · ' . tms_trk_age($lv['age_min']) . ' · ' . ($lv['location'] ?? '')); ?>
      <td data-v="<?= e($lv['state']) ?>" data-x="<?= e($lt) ?>" title="<?= e($lt) ?>"><span class="tk-pill <?= $lc ?>"><?= e($lv['state']) ?></span> <span class="<?= $lv['fresh'] === 'ok' ? 'tr-muted' : ($lv['fresh'] === 'lost' ? 'tk-lostt' : 'tk-old') ?>"><?= e(tms_trk_age($lv['age_min'])) ?></span><?php if (!empty($lv['location'])): ?> <span class="tr-muted"><?= e(mb_strimwidth((string)$lv['location'], 0, 34, '…')) ?></span><?php endif; ?></td>
      <?php else: ?><td data-v="" data-x=""><span class="tr-muted">–</span></td><?php endif; ?>
      <td data-v="<?= e((string)($t['driver_name'] ?? '')) ?>" data-x="<?= e(trim(($t['driver_name'] ?? '') . ' ' . ($t['driver_phone'] ?? ''))) ?>" title="<?= e((string)($t['driver_phone'] ?? '')) ?>"><?php if (!empty($t['driver_name'])): ?><?= e($t['driver_name']) ?> <?php $ph = trim((string)($t['driver_phone'] ?? '')); if ($ph !== ''): ?><button type="button" class="tr-call" data-n="<?= e($t['driver_name']) ?>" data-ph="<?= e($ph) ?>" title="Show mobile number" aria-label="Show mobile number of <?= e($t['driver_name']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg></button><?php else: ?><span class="tr-call off" title="No mobile number saved"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7"/></svg></span><?php endif; ?><?php else: ?><span class="tr-muted">–</span><?php endif; ?></td>
      <td data-v="<?= e((string)($t['tpt_name'] ?? '')) ?>"><?= !empty($t['tpt_name']) && $t['tpt_name'] !== 'UNASSIGNED' ? e($t['tpt_name']) : '<span class="tr-muted">–</span>' ?></td>
      <td data-v="<?= e($stl) ?>" data-x="<?= e($stl) ?>"><span class="dt-pill <?= e($stc) ?>"><?= e($stl) ?></span></td>
      <td><div class="tr-act">
        <div class="row-action"><button type="button" class="tr-dots dots-btn" onclick="toggleRowMenu('tripMenu<?= (int)$t['id'] ?>',event)" aria-label="More actions for <?= e($t['trip_code']) ?>">⋮</button>
          <div class="row-menu" id="tripMenu<?= (int)$t['id'] ?>">
            <?php if (can('plan') && $st === 'Planned'): ?><button type="button" onclick='acceptTrip(<?= json_encode($t, $jf) ?>);closeRowMenus()'>Accept: vehicle &amp; driver</button><?php endif; ?>
            <?php if (can('load') && $st === 'Assigned'): ?><form method="post"><input type="hidden" name="action" value="reach_source"><input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>"><button type="submit">Reached at source</button></form><?php endif; ?>
            <?php if (can('load') && $st === 'Reached at source'): ?><button type="button" onclick="lgOpen(<?= (int)$t['id'] ?>,'<?= e($t['trip_code']) ?>',<?= (int)$t['total_boxes'] ?>);closeRowMenus()">Load</button><?php endif; ?>
            <?php if (can('load') && $st === 'Gate Pass Created'): ?><form method="post"><input type="hidden" name="action" value="dispatch_gatepass"><input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>"><button type="submit">Dispatch</button></form><?php endif; ?>
            <a href="PLANNING.php?tab=trips&amp;view=<?= (int)$t['id'] ?>">Open trip (map)</a>
            <?php if (can('plan') && $st === 'Assigned'): ?><button type="button" onclick='acceptTrip(<?= json_encode($t, $jf) ?>);closeRowMenus()'>Change vehicle / driver</button><?php endif; ?>
            <?php if (can('plan') && $editable): ?><button type="button" onclick='editTrip(<?= json_encode($t, $jf) ?>);closeRowMenus()'>Edit stores</button><?php endif; ?>
            <?php if (can('track') && in_array($st, ['Gate Pass Created', 'In Transit', 'Breakdown', 'Accident'], true)): ?><a href="PLANNING.php?tab=tripstatus">Update status</a><?php endif; ?>
            <?php if (can('delete') && $editable): ?><form method="post"><input type="hidden" name="action" value="delete_trip"><input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>"><button type="submit" class="danger-text" onclick="return confirm('Trip cancel karein? Iske PRD challans PRD Pending me wapas aa jayenge, manual indents Planned me aur vehicle/driver free ho jayenge.')">Cancel trip</button></form><?php endif; ?>
          </div></div>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
</div>
<?php if (can('load')): ?>
<dialog class="fdlg" id="dlgLoad"><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="load_gatepass"><input type="hidden" name="trip_id" value="0">
  <div class="fh"><h3>Load vehicle <span class="hint" id="lgCode"></span></h3><button type="button" onclick="this.closest('dialog').close()" aria-label="Close">×</button></div>
  <div class="fb">
    <div class="f w hint">Confirms that the vehicle is loaded with the trip's PKT. Dispatch comes next.</div>
    <div class="f"><label>Invoice no.</label><input name="invoice_no" maxlength="60"></div>
    <div class="f"><label>Seal no.</label><input name="seal_no" maxlength="60"></div>
    <div class="f w"><label>Remarks</label><input name="remarks" maxlength="300" placeholder="Optional"></div>
    <div class="f w"><label>Gate pass document <small>(PDF / image, optional)</small></label><input type="file" name="gate_file" accept=".pdf,.jpg,.jpeg,.png"></div>
  </div>
  <div class="ff"><button class="pri">Load</button><button type="button" onclick="this.closest('dialog').close()">Cancel</button></div></form></dialog>
<script>function lgOpen(id,code,pkt){const d=document.getElementById('dlgLoad');d.querySelector('form').reset();d.querySelector('[name=trip_id]').value=id;document.getElementById('lgCode').textContent=code?'· '+code+(pkt?' · '+pkt+' PKT':''):'';d.showModal();}</script>
<?php endif; ?>
<?php $formKind = 'trip'; include __DIR__ . '/_forms.php'; ?>
<script>
(function(){
  // inline status dropdown: each choice runs the same action the buttons use
  function post(action, f, url){
    const form=document.createElement('form'); form.method='post'; form.action=url||location.pathname+location.search; form.style.display='none';
    Object.entries(Object.assign({action},f)).forEach(([k,v])=>{const i=document.createElement('input');i.type='hidden';i.name=k;i.value=v;form.appendChild(i);});
    document.body.appendChild(form); form.submit();
  }
  async function status(id,to,remarks){
    const fd=new FormData(); fd.append('csrf',window.TMS_CSRF||''); fd.append('action','trip_status'); fd.append('trip_id',id); fd.append('status',to); fd.append('remarks',remarks||'');
    try{
      const r=await fetch('PLANNING.php?tab=tripstatus',{method:'POST',body:fd,credentials:'same-origin'}); const html=await r.text();
      const doc=new DOMParser().parseFromString(html,'text/html'); const bad=doc.querySelector('.alert-err'), ok=doc.querySelector('.alert-ok');
      if(!r.ok||bad){alert((bad&&bad.textContent.trim())||('Status not changed (HTTP '+r.status+')'));return false;}
      try{sessionStorage.setItem('tr_flash',(ok&&ok.textContent.trim())||'Status updated.');}catch(e){} location.href='PLANNING.php?tab=trips'; return true;
    }catch(e){alert('Status not changed: '+e.message);return false;}
  }
  document.querySelectorAll('select.dt-pill[data-trip]').forEach(sel=>sel.addEventListener('change',async()=>{
    const to=sel.value, cur=sel.dataset.cur, id=sel.dataset.trip; sel.value=cur;
    if(to===cur)return;
    if(cur==='Planned'&&to==='Assigned'){const t=(window.tripRowsData||tripRowsData).find(x=>+x.id===+id); if(t)acceptTrip(t); return;}
    if(cur==='Assigned'&&to==='Loaded'){const t=document.querySelector('select[data-trip="'+id+'"]').closest('tr').querySelector('.tr-code'); lgOpen(id,t?t.textContent:'',0); return;}
    if(cur==='Loaded'&&to==='Gate Pass Created'){location.href='PLANNING.php?tab=gatepasses&trip_id='+id; return;}
    if(cur==='Gate Pass Created'&&to==='In Transit'){if(confirm('Dispatch this trip now?'))post('dispatch_gatepass',{trip_id:id}); return;}
    if(to==='Cancelled'){if(confirm('Trip cancel karein? Iske PRD challans PRD Pending me wapas aa jayenge.'))post('delete_trip',{trip_id:id}); return;}
    if(['In Transit','Breakdown','Accident','Delivered'].includes(to)){
      const remarks=to==='Delivered'?'':(prompt('Remarks for '+to+' (optional)')||'');
      if(to==='Delivered'&&!confirm('Mark this trip as delivered? All its stores will be marked delivered.'))return;
      sel.disabled=true; if(!(await status(id,to,remarks)))sel.disabled=false;
    }
  }));
  // call icon: click shows the driver's mobile number (tap again or click elsewhere to close)
  (function(){
    let pop=null; const esc=x=>String(x).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const close=()=>{if(pop){pop.remove();pop=null;}};
    document.addEventListener('click',e=>{
      const b=e.target.closest('.tr-call[data-ph]');
      if(!b){if(!e.target.closest('.tr-pop'))close();return;}
      e.stopPropagation(); const same=pop&&pop._b===b; close(); if(same)return;
      pop=document.createElement('div'); pop.className='tr-pop'; pop._b=b;
      pop.innerHTML='<span class="pn">'+esc(b.dataset.n)+'</span><a class="pp" href="tel:'+esc(b.dataset.ph)+'">'+esc(b.dataset.ph)+'</a><button type="button" class="pc">Copy</button>';
      document.body.appendChild(pop);
      const r=b.getBoundingClientRect(), w=pop.offsetWidth; pop.style.left=Math.max(8,Math.min(r.left,window.innerWidth-w-8))+'px'; pop.style.top=Math.min(r.bottom+6,window.innerHeight-pop.offsetHeight-8)+'px';
      pop.querySelector('.pc').addEventListener('click',()=>{const t=b.dataset.ph; try{navigator.clipboard.writeText(t);}catch(x){} pop.querySelector('.pc').textContent='Copied';});
    });
    window.addEventListener('scroll',close,true);
  })();
  document.getElementById('dtTrips').addEventListener('dblclick',e=>{const r=e.target.closest('tr[data-row]'); if(r&&!e.target.closest('a,button,form,select,.row-action'))location.href=r.dataset.href.replace(/&amp;/g,'&');});
  try{const f=sessionStorage.getItem('tr_flash'); if(f){sessionStorage.removeItem('tr_flash'); const d=document.createElement('div'); d.className='alert alert-ok'; d.setAttribute('role','status'); d.textContent=f; const a=document.querySelector('.page-alerts')||document.querySelector('main'); a.insertBefore(d,a.firstChild);}}catch(e){}
})();
</script>
