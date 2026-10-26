<div id="recordModal" class="record-modal" style="display:none"><div class="record-modal-box"><div class="record-modal-head"><b id="recordTitle">Edit</b><button type="button" onclick="closeRecordModal()">×</button></div><div id="recordBody"></div></div></div>
<script>
// CSRF: add the session token to every POST form on this page, including forms built in JS and submitted with form.submit().
(function(){
  const T=<?= json_encode(csrf()) ?>; window.TMS_CSRF=T;
  function addToken(f){
    if(!f||String(f.method).toLowerCase()!=='post'||f.querySelector('input[name="csrf"]'))return;
    const i=document.createElement('input');i.type='hidden';i.name='csrf';i.value=T;f.appendChild(i);
  }
  document.addEventListener('submit',e=>addToken(e.target),true);
  const nativeSubmit=HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit=function(){addToken(this);return nativeSubmit.call(this);};
})();
const masterTransporters=<?= json_encode(array_map(fn($t)=>$t['name'], dsp_transporters($d)), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?>;
const TRIP_MAX_STORES=<?= (int)tms_trip_max_stores() ?>, TRIP_MAX_PKT=<?= (int)tms_trip_max_pkt() ?>;
const masterStores=<?=json_encode(array_values($stores),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const masterDcs=<?=json_encode(array_values($dcs),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const masterVehicles=<?=json_encode(array_values($vehicles),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const masterDrivers=<?=json_encode(array_values($drivers),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const tripRowsData=<?=json_encode(array_values($trips),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const tripAddIndents=<?=json_encode(array_values($tripAddIndents),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
function showRecord(title,html){document.getElementById('recordTitle').textContent=title;document.getElementById('recordBody').innerHTML=html;document.getElementById('recordModal').style.display='flex'}
function closeRecordModal(){document.getElementById('recordModal').style.display='none'}
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function openTripBuilder(){const b=document.getElementById('tripBuilder');if(!b)return;b.style.display='block';updateManualTripCount();b.scrollIntoView({behavior:'smooth',block:'nearest'});}
function closeTripBuilder(){const b=document.getElementById('tripBuilder');if(b)b.style.display='none';}
function updateManualTripCount(){const checks=[...document.querySelectorAll('.pending-indent-check:checked')];const n=document.getElementById('manualTripCount');if(n)n.textContent=checks.length+' selected';if(checks.length>=4)document.querySelectorAll('.pending-indent-check:not(:checked)').forEach(x=>x.disabled=true);else document.querySelectorAll('.pending-indent-check').forEach(x=>x.disabled=false);}
function autoCreateTripsNow(){if(!tripAddIndents.length){alert('No Pending / Planned Indent available.');return;}if(!confirm('Saare pending indents ko nearest-store grouping se trips me banayein? Har trip me max '+TRIP_MAX_STORES+' stores aur max '+TRIP_MAX_PKT+' PKT.'))return;const f=document.createElement('form');f.method='post';f.innerHTML='<input type="hidden" name="action" value="auto_create_pending_trips"><input type="hidden" name="planned_date" value="<?=e(date('Y-m-d'))?>">';document.body.appendChild(f);f.submit();}
function manualCreateTripNow(){const checks=[...document.querySelectorAll('.pending-indent-check:checked')];if(!checks.length){alert('Manual Trip ke liye kam se kam 1 Pending Indent select karo.');return;}if(checks.length>TRIP_MAX_STORES){alert('Ek trip me maximum '+TRIP_MAX_STORES+' stores allowed hain.');return;}const selPkt=checks.reduce((t,x)=>t+(+x.dataset.pkt||0),0);if(selPkt>TRIP_MAX_PKT){alert('Selected indents '+selPkt+' PKT ke hain. Ek trip me maximum '+TRIP_MAX_PKT+' PKT allowed hai.');return;}const dc=checks[0].dataset.dc;if(checks.some(x=>x.dataset.dc!==dc)){alert('Manual Trip me same Center DC ke indents select karo.');return;}const ids=checks.map(x=>x.value);const f=document.createElement('form');f.method='post';f.innerHTML='<input type="hidden" name="action" value="create_manual_trip"><input type="hidden" name="source_dc_id" value="'+esc(dc)+'"><input type="hidden" name="planned_date" value="<?=e(date('Y-m-d'))?>">'+ids.map(id=>'<input type="hidden" name="indent_ids[]" value="'+esc(id)+'">').join('');document.body.appendChild(f);f.submit();}
function openPendingTripPreview(){openTripBuilder()}
function closePendingTripPreview(){closeTripBuilder()}
function renderPendingTripGroups(){}
function closeRowMenus(){document.querySelectorAll('.row-menu.open').forEach(x=>{x.classList.remove('open');x.style.top='';x.style.left='';});}
function toggleRowMenu(id,ev){
 const el=document.getElementById(id); if(!el)return;
 const was=el.classList.contains('open'); closeRowMenus();
 if(was)return;
 el.classList.add('open');
 if(ev&&ev.currentTarget){
   const r=ev.currentTarget.getBoundingClientRect();
   const w=Math.min(220,Math.max(190,el.offsetWidth||190));
   let left=r.right-w; if(left<8)left=8; if(left+w>window.innerWidth-8)left=window.innerWidth-w-8;
   let top=r.bottom+5; const h=el.offsetHeight||120; if(top+h>window.innerHeight-8)top=Math.max(8,r.top-h-5);
   el.style.position='fixed'; el.style.left=left+'px'; el.style.top=top+'px'; el.style.right='auto';
 }
}
document.addEventListener('click',e=>{if(!e.target.closest('.row-action'))closeRowMenus();});
window.addEventListener('scroll',closeRowMenus,true);
window.addEventListener('resize',closeRowMenus);
function closeActionMenus(){document.querySelectorAll('.action-dropdown-menu.open').forEach(x=>x.classList.remove('open'));}
function toggleActionMenu(id){const el=document.getElementById(id);if(!el)return;const was=el.classList.contains('open');closeActionMenus();if(!was)el.classList.add('open');}
document.addEventListener('click',e=>{if(!e.target.closest('.action-box'))closeActionMenus();});
function gatePassForm(x){if(x&&x.id)window.location.href='PLANNING.php?tab=gatepasses&trip_id='+encodeURIComponent(x.id);}
function acceptTrip(x){
  const tpts=masterTransporters.length?masterTransporters:[...new Set(masterDrivers.map(d=>d.tpt_name).filter(Boolean))];
  const tOpts=tpts.map(t=>`<option value="${esc(t)}">${esc(t)}</option>`).join('');
  const vOpts=masterVehicles.map(v=>`<option value="${v.id}">${esc(v.vehicle_number)} — ${esc(v.capacity_boxes)} pkt</option>`).join('');
  const dOpts=masterDrivers.map(d=>`<option value="${d.id}" data-tpt="${esc(d.tpt_name||'')}">${esc(d.driver_name)} — ${esc(d.phone||'')}</option>`).join('');
  showRecord('Accept Trip '+(x.trip_code||''),`<form method="post" class="record-form">
    <input type="hidden" name="action" value="assign_trip">
    <input type="hidden" name="trip_id" value="${x.id}">
    <label>Transporter<select class="select" data-search name="tpt_name" id="acceptTpt" required><option value="">Select transporter</option>${tOpts}</select></label>
    <label>Vehicle<select class="select" data-search name="vehicle_id" required><option value="">Select Vehicle</option>${vOpts}</select></label>
    <label>Driver<select class="select" data-search name="driver_id" id="acceptDriver" required><option value="">Select Driver</option>${dOpts}</select></label>
    <div class="record-actions"><button class="btn success">Accept Trip</button><button type="button" class="btn light" onclick="closeRecordModal()">Cancel</button></div>
  </form>`);
  const t=document.getElementById('acceptTpt'),dd=document.getElementById('acceptDriver');
  function fdd(){[...dd.options].forEach((o,i)=>{if(i)o.hidden=!!t.value&&o.dataset.tpt!==t.value;});if(dd.selectedOptions[0]&&dd.selectedOptions[0].hidden)dd.value='';}
  if(t&&dd)t.addEventListener('change',fdd);
}
function removeTripStopRow(btn){
  const rows=document.querySelectorAll('#tripStopEditRows .trip-stop-edit');
  if(rows.length<=1){alert('Trip me minimum 1 store honi chahiye.');return;}
  btn.closest('.trip-stop-edit').remove();
}
function addTripStopRow(){
  const sel=document.getElementById('tripStopAddSelect');const opt=sel.selectedOptions[0];
  if(!opt||!opt.value){alert('Ek Planned Indent select karo.');return;}
  const box=document.getElementById('tripStopEditRows');
  if(box.querySelectorAll('.trip-stop-edit').length>=TRIP_MAX_STORES){alert('Ek trip me maximum '+TRIP_MAX_STORES+' stores allowed hain.');return;}
  const storeId=opt.dataset.store;
  if([...box.querySelectorAll('input[name="store_id[]"]')].some(i=>i.value===storeId)){alert('Same destination store repeat nahi kar sakte.');return;}
  const div=document.createElement('div');div.className='trip-stop-edit';
  div.innerHTML='<b>'+esc(opt.dataset.label)+'</b>'
    +'<input class="input" name="stop_packets[]" type="number" min="1" value="'+esc(opt.dataset.pkt)+'" required>'
    +'<input class="input" name="stop_values[]" type="number" step="0.01" min="1" value="'+esc(opt.dataset.val)+'" required>'
    +'<button type="button" class="btn light" onclick="removeTripStopRow(this)">Remove</button>'
    +'<input type="hidden" name="store_id[]" value="'+esc(storeId)+'">'
    +'<input type="hidden" name="indent_id[]" value="'+esc(opt.value)+'">';
  box.appendChild(div);
  opt.remove();
}
function validateTripStopEdit(){
  const rows=[...document.querySelectorAll('#tripStopEditRows .trip-stop-edit')];
  if(!rows.length){alert('Trip me minimum 1 store honi chahiye.');return false;}
  if(rows.length>TRIP_MAX_STORES){alert('Ek trip me maximum '+TRIP_MAX_STORES+' stores allowed hain.');return false;}
  const tp=rows.reduce((t,r)=>t+(+(r.querySelector('input[name="stop_packets[]"]')?.value)||0),0);
  if(tp>TRIP_MAX_PKT){alert('Trip me '+tp+' PKT ho rahe hain. Maximum '+TRIP_MAX_PKT+' PKT allowed hai.');return false;}
  return true;
}
function editTrip(x){
  const stops=x.edit_stops||[];
  const availableIndents=tripAddIndents.filter(i=>String(i.source_dc_id)===String(x.source_dc_id));
  const rowHtml=s=>'<div class="trip-stop-edit">'
    +'<b>'+esc(s.store_code)+' — '+esc(s.store_name)+'</b>'
    +'<input class="input" name="stop_packets[]" type="number" min="1" value="'+esc(s.packet_count)+'" required>'
    +'<input class="input" name="stop_values[]" type="number" step="0.01" min="1" value="'+esc(s.stop_value)+'" required>'
    +'<button type="button" class="btn light" onclick="removeTripStopRow(this)">Remove</button>'
    +'<input type="hidden" name="store_id[]" value="'+esc(s.store_id)+'">'
    +'<input type="hidden" name="indent_id[]" value="'+esc(s.indent_id||0)+'">'
    +'</div>';
  const addOptions=availableIndents.map(i=>{
    const label=esc(i.store_code)+' — '+esc(i.store_name)+' ('+esc(i.indent_no)+')';
    return '<option value="'+i.id+'" data-store="'+i.destination_store_id+'" data-pkt="'+i.packet_count+'" data-val="'+i.indent_value+'" data-label="'+label+'">'+label+'</option>';
  }).join('');
  showRecord('Edit Trip '+(x.trip_code||'')+' — Add / Remove Store',
    '<form method="post" onsubmit="return validateTripStopEdit()">'
    +'<input type="hidden" name="action" value="edit_trip">'
    +'<input type="hidden" name="trip_id" value="'+x.id+'">'
    +'<div style="padding:16px 16px 0"><label style="font-size:11px;font-weight:800;color:#475569">Planned Date<br><input class="input" type="date" name="planned_date" value="'+esc(x.planned_date||'')+'"></label></div>'
    +'<div style="padding:12px 16px" id="tripStopEditRows">'+stops.map(rowHtml).join('')+'</div>'
    +'<div class="trip-add-row" style="padding:0 16px 12px">'
    +'<select class="select" id="tripStopAddSelect"><option value="">'+(availableIndents.length?'+ Select Planned Indent to add as Store':'No Planned Indent available for this Center DC')+'</option>'+addOptions+'</select>'
    +'<button type="button" class="btn light" onclick="addTripStopRow()">+ Add Store</button>'
    +'</div>'
    +'<div class="record-actions" style="padding:0 16px 16px">'
    +'<button class="btn success">Save Trip</button>'
    +'<button type="button" class="btn light" onclick="closeRecordModal()">Cancel</button>'
    +'</div>'
    +'</form>');
}
function editGate(x){showRecord('Edit Gate Pass',`<form method="post" class="record-form"><input type="hidden" name="action" value="edit_gatepass"><input type="hidden" name="gatepass_id" value="${x.gate_pass_id||x.id}"><label>Gate Pass<input class="input" value="${esc(x.gate_pass_no||'')}" disabled></label><label>Invoice No.<input class="input" name="invoice_no" value="${esc(x.gate_invoice_no||x.invoice_no||'')}"></label><label>Seal No.<input class="input" name="seal_no" value="${esc(x.gate_seal_no||x.seal_no||'')}"></label><label>Remarks<textarea class="input" name="remarks">${esc(x.gate_remarks||x.remarks||'')}</textarea></label><div class="record-actions"><button class="btn success">Save Changes</button><button type="button" class="btn light" onclick="closeRecordModal()">Cancel</button></div></form>`)}
</script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
function escapeHtml(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function initTripMap(){
  const el=document.getElementById('tripMap'); if(!el||typeof L==='undefined') return;
  const points=<?=json_encode(array_values(array_filter(array_merge((!empty($trip['dc_latitude'])||!empty($trip['dc_longitude']))?[['code'=>$trip['dc_code']??'DC','name'=>$trip['dc_name']??'Center DC','city'=>'','lat'=>(float)($trip['dc_latitude']??0),'lon'=>(float)($trip['dc_longitude']??0),'source'=>true,'stop_no'=>0]]:[],array_map(fn($i)=>['code'=>$i['store_code'],'name'=>$i['store_name'],'city'=>$i['city']??'','lat'=>(float)($i['latitude']??0),'lon'=>(float)($i['longitude']??0),'source'=>false,'stop_no'=>$i['sequence_no']],$stops)),fn($p)=>((float)$p['lat']!==0||(float)$p['lon']!==0))), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const vno=<?=json_encode((string)($trip['vehicle_number']??''))?>, hasVeh=!!vno&&vno!=='UNASSIGNED';   // the vehicle is drawn on every trip that has one, even when store coordinates are missing
  if(!points.length&&!hasVeh){el.innerHTML='<div style="padding:18px" class="note">Store/DC latitude and longitude are not available. Add coordinates in Master to show the route.</div>';return;}
  const map=L.map(el,{scrollWheelZoom:true});
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
  const latlngs=points.map(p=>[p.lat,p.lon]);
  const bounds=points.length?L.latLngBounds(latlngs):L.latLngBounds([[8,68],[35,97]]);
  points.forEach((p,i)=>{
    const label=p.source?'START • Center DC':'STOP • '+(p.stop_no||i);
    L.marker([p.lat,p.lon]).addTo(map).bindPopup('<span class="map-stop-no">'+escapeHtml(label)+'</span><br><b>'+escapeHtml(p.code)+'</b> — '+escapeHtml(p.name)+(p.city?'<br>'+escapeHtml(p.city):''));
  });
  const fallback=L.polyline(latlngs,{weight:4,dashArray:'8 6'}).addTo(map);
  map.fitBounds(bounds,{padding:[25,25]});
  // A small note on the map says why there is no vehicle icon (no vehicle yet / vehicle not in the GPS reports / no address in the report)
  const vnEl=L.DomUtil.create('div','trk-note'); let vnShown=false; const vnCtl=L.control({position:'topright'}); vnCtl.onAdd=()=>vnEl;
  const vnote=(t,ok)=>{vnEl.textContent=t||''; vnEl.classList.toggle('ok',!!ok); if(t&&!vnShown){vnCtl.addTo(map);vnShown=true;} else if(!t&&vnShown){map.removeControl(vnCtl);vnShown=false;}};
  if(!hasVeh)vnote('No vehicle assigned yet: its position appears here after Accept / Assign.');
  // Vehicle position from the GPS report files. R-TRAC gives coordinates. G-TRAC gives only an address: the server answers at once with what it knows (an exact spot it
  // has saved, or an estimate from the store master) and, for a new address, this browser looks the address up on OpenStreetMap (the server cannot reach it) and saves it for next time.
  if(hasVeh){
    const trkCsrf=<?=json_encode(csrf())?>;
    let vMarker=null,vDot=null,vTrail=null,fitted=false,looking=false;
    const ico=c=>L.divIcon({className:'',html:'<div class="trk-ico '+c+'">🚚</div>',iconSize:[34,34],iconAnchor:[17,17],popupAnchor:[0,-17]});
    const kmBetween=(a,b)=>{const R=6371,r=x=>x*Math.PI/180,dp=r(b.lat-a.lat),dl=r(b.lng-a.lng),x=Math.sin(dp/2)**2+Math.cos(r(a.lat))*Math.cos(r(b.lat))*Math.sin(dl/2)**2;return 2*R*Math.asin(Math.min(1,Math.sqrt(x)));};
    const sleep=ms=>new Promise(r=>setTimeout(r,ms));
    const nom=async qs=>{const r=await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=in&'+qs,{headers:{'Accept-Language':'en'}});if(!r.ok)throw new Error('lookup service answered '+r.status);const a=await r.json();return a[0]?{lat:+a[0].lat,lng:+a[0].lon}:null;};
    const save=f=>{const fd=new FormData();fd.append('csrf',trkCsrf);Object.keys(f).forEach(k=>fd.append(k,f[k]));return fetch('TRACK_SAVE.php',{method:'POST',body:fd,credentials:'same-origin'}).catch(()=>{});};
    // PIN centre first, then the road / village; a result more than 40 km from the PIN centre is a wrong road with the same name and is dropped
    const lookup=async plan=>{
      let pinPt=plan.pin_pt;
      if(!pinPt&&plan.pin){pinPt=await nom('postalcode='+plan.pin);if(pinPt)save({pin:plan.pin,lat:pinPt.lat,lng:pinPt.lng});await sleep(1100);}
      for(const q of plan.queries){const c=await nom('q='+encodeURIComponent(q+', India'));await sleep(1100);if(c&&(!pinPt||kmBetween(pinPt,c)<=40))return c;}
      return pinPt;
    };
    const render=j=>{
      const cls=j.state==='RUNNING'?'run':(j.fresh==='lost'||j.state==='NO SIGNAL'?'lost':'stop'), ll=[j.pos.lat,j.pos.lng];
      const lc=document.getElementById('trkLive');   // the Live position card on the left follows the same data
      if(lc){lc.innerHTML='<small>Live position ('+escapeHtml((j.sources||[]).join(' + '))+')</small><b><span class="tk-pill tk-'+(cls==='run'?'run':cls==='lost'?'lost':'stop')+'">'+escapeHtml(j.state)+'</span> '+(j.speed!==null?escapeHtml(Math.round(j.speed))+' km/h · ':'')+'<span class="'+(j.fresh==='ok'?'':j.fresh==='lost'?'tk-lostt':'tk-old')+'">'+escapeHtml(j.age)+'</span></b><small style="display:block;margin-top:2px">'+escapeHtml(j.location||'')+(j.day_km!==null?' · today '+escapeHtml(Number(j.day_km).toFixed(1))+' km':'')+(j.idle?' · idle '+escapeHtml(j.idle):'')+'</small>';}
      const html='<b>'+escapeHtml(j.vehicle)+'</b> <span class="tk-pill tk-'+(cls==='run'?'run':cls==='lost'?'lost':'stop')+'">'+escapeHtml(j.state)+'</span><br>'
        +(j.speed!==null?escapeHtml(Math.round(j.speed))+' km/h · ':'')+escapeHtml(j.age)+(j.ignition?' · ignition '+escapeHtml(j.ignition):'')+'<br>'+escapeHtml(j.location||'')+(j.loc_old?' <i>(last known address, '+escapeHtml(j.loc_age)+')</i>':'')
        +(j.day_km!==null?'<br>Today '+escapeHtml(Number(j.day_km).toFixed(1))+' km':'')+(j.idle?' · idle '+escapeHtml(j.idle):'')+'<br><small>'+(j.exact?'Position from the report coordinates':'Approximate position (from the report address)')+' · '+escapeHtml((j.sources||[]).join(' + '))+'</small>';
      const col=cls==='run'?'#17b26a':cls==='lost'?'#f04438':'#667085';
      if(!vDot)vDot=L.circleMarker(ll,{radius:11,color:col,weight:3,fillColor:'#fff',fillOpacity:.9}).addTo(map);else{vDot.setLatLng(ll);vDot.setStyle({color:col});}   // plain dot under the truck: still visible if the emoji icon cannot be drawn
      if(!vMarker)vMarker=L.marker(ll,{icon:ico(cls),zIndexOffset:1000}).addTo(map);else{vMarker.setLatLng(ll);vMarker.setIcon(ico(cls));}
      vMarker.bindPopup(html);
      vnote('Vehicle '+j.vehicle+' on the map · '+j.age+' · checked '+new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})+(j.lookup?' · finding the exact spot…':''),true);
      if(j.trail&&j.trail.length>1){const tl=j.trail.concat([ll]);if(!vTrail)vTrail=L.polyline(tl,{weight:3,color:'#f79009',dashArray:'4 6'}).addTo(map).bindTooltip("Today's movement of "+j.vehicle);else vTrail.setLatLngs(tl);}   // the whole day's path when the report has it
      else if(j.start){const tl=[[j.start.lat,j.start.lng],ll];if(!vTrail)vTrail=L.polyline(tl,{weight:3,color:'#f79009',dashArray:'4 6'}).addTo(map).bindTooltip("Today's movement: "+(j.start_address||'start')+' → now');else vTrail.setLatLngs(tl);}
      if(!fitted){fitted=true;if(points.length)map.fitBounds(bounds.extend(ll),{padding:[25,25]});else map.setView(ll,9);}
    };
    const loadVeh=()=>fetch('TRACK_POS.php?vehicle='+encodeURIComponent(vno),{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
      if(!j||!j.ok){vnote('Vehicle '+vno+' is not in the G-TRAC / R-TRAC reports, so its position is not available.');return;}
      if(j.pos)render(j);
      else vnote(j.lookup?'Finding the position of '+vno+' from its address… a few seconds.':(j.location?'The position of '+vno+' could not be found from its address.':'The GPS report has no location for '+vno+' ('+j.state+').'));
      if(j.lookup&&!looking){
        looking=true; const plan=j.lookup;
        lookup(plan).then(p=>{if(p){j.pos=p;j.lookup=null;render(j);save({address:plan.address,lat:p.lat,lng:p.lng});}else if(!j.pos)vnote('The position of '+vno+' could not be found from its address.');})
          .catch(e=>{if(!j.pos)vnote('Position lookup failed: '+(e&&e.message?e.message:e));}).finally(()=>{looking=false;});
      }
    }).catch(e=>{console.error('vehicle position:',e);vnote('Vehicle position could not be drawn: '+(e&&e.message?e.message:e));});
    loadVeh(); const vIv=setInterval(()=>{if(!document.getElementById('tripMap')||document.hidden){if(!document.getElementById('tripMap'))clearInterval(vIv);return;}loadVeh();},60000);   // stops when the trip page is left, idle while the tab is hidden
  }  const note=document.getElementById('routeNote'),distEl=document.getElementById('routeDistanceText');
  const routeLink=document.getElementById('openRoute');
  const coords=points.map(p=>p.lon+','+p.lat).join(';');
  if(routeLink)routeLink.href='https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route='+points.map(p=>p.lat+','+p.lon).join(';');
  if(points.length<2){if(note)note.textContent=points.length?'Single point available. Add store coordinates for a route.':'Store coordinates are not available; only the vehicle position is shown.';return;}
  fetch('https://router.project-osrm.org/route/v1/driving/'+coords+'?overview=full&geometries=geojson')
    .then(r=>r.ok?r.json():Promise.reject())
    .then(data=>{
      if(data.routes&&data.routes[0]&&data.routes[0].geometry){
        const road=data.routes[0].geometry.coordinates.map(c=>[c[1],c[0]]);
        map.removeLayer(fallback); L.polyline(road,{weight:5}).addTo(map);
        const km=(data.routes[0].distance/1000).toFixed(1), mins=Math.round(data.routes[0].duration/60);
        if(distEl)distEl.textContent='Road distance: '+km+' km • ETA: '+(mins>=60?Math.floor(mins/60)+'h '+mins%60+'m':mins+' min');if(note)note.textContent='Road route: '+km+' km • approx. '+(mins>=60?Math.floor(mins/60)+'h '+mins%60+'m':mins+' min')+' • '+points.length+' points.';
      }else if(note)note.textContent='Route points plotted; road routing unavailable right now.';
    }).catch(()=>{if(note)note.textContent='Route points plotted. Road routing service unavailable; Open Route can be used for directions.';});
}
document.addEventListener('DOMContentLoaded',initTripMap);
// No forced page reload: tab navigation is SPA-style. Route map remains static until user opens/refreshes the Trip.
function validateStoreIndent(){const rows=[...document.querySelectorAll('#storeRows .store-row')];if(!rows.length)return false;let totalP=0,totalV=0,seen=[];for(const r of rows){const st=r.querySelector('[name="destination[]"]')?.value||'';const p=Number(r.querySelector('[name="packet_count[]"]')?.value||0);const v=Number(r.querySelector('[name="indent_value[]"]')?.value||0);if(!st||p<=0||v<=0){alert('Destination Store, Packets aur Value complete karo.');return false;}if(seen.includes(st)){alert('Same destination store repeat nahi kar sakte.');return false;}seen.push(st);totalP+=p;totalV+=v;}if(rows.length>3){alert('Maximum 3 stores.');return false;}return true;}
function addStoreRow(){const box=document.getElementById('storeRows');const rows=box.querySelectorAll('.store-row');if(rows.length>=3){alert('Maximum 3 stores.');return;}const row=rows[0].cloneNode(true);row.querySelectorAll('input').forEach(x=>x.value='');row.querySelectorAll('select').forEach(x=>x.selectedIndex=0);const rm=document.createElement('button');rm.type='button';rm.className='btn light remove-store';rm.textContent='Remove';rm.onclick=()=>row.remove();row.appendChild(rm);box.appendChild(row);}
function toggleIndentForm(id){['manualBox','bulkBox'].forEach(x=>{const el=document.getElementById(x);if(el)el.style.display=(x===id&&el.style.display==='none')?'block':'none';});}
const t=document.getElementById('tpt'),d=document.getElementById('driver');function fd(){if(!t||!d)return;[...d.options].forEach((o,i)=>{if(i)o.hidden=!!t.value&&o.dataset.tpt!==t.value})}t?.addEventListener('change',fd);fd(); const dt=document.getElementById('detailTpt'),dd=document.getElementById('detailDriver'); function fdd(){if(!dt||!dd)return;[...dd.options].forEach((o,i)=>{if(i)o.hidden=!!dt.value&&o.dataset.tpt!==dt.value}); if(dd.selectedOptions[0]&&dd.selectedOptions[0].hidden)dd.value='';} dt?.addEventListener('change',fdd); fdd();
</script>
