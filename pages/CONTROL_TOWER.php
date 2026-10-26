<?php
/*
 * Control Tower: every trip of a date range as a card on the left, a map on the right with the vehicles (live position from the GPS reports)
 * and the stores (clustered). Click a card to see its route and vehicle. Reads only; positions come from app/services/tracking.php.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
require_once TMS_ROOT . '/app/services/tracking.php';
require_once TMS_ROOT . '/app/services/control_tower.php';
$d = db();

if (isset($_GET['live'])) {   // refresh of the vehicle positions (every minute)
  session_write_close(); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  tms_trk_autoimport($d);
  $nos = array_slice(array_filter(array_map('trim', explode(',', (string)($_GET['nos'] ?? '')))), 0, 200);
  echo json_encode(['ok' => true, 'veh' => ct_positions($d, $nos)], JSON_UNESCAPED_UNICODE); exit;
}

tms_trk_autoimport($d);
$ok = fn($s) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s) ? $s : null;
$to = $ok($_GET['to'] ?? '') ?: date('Y-m-d'); $from = $ok($_GET['from'] ?? '') ?: date('Y-m-d', strtotime('-15 days'));
if ($from > $to) [$from, $to] = [$to, $from];
$B = ct_build($d, $from, $to); $tj = $B['tj']; $veh = $B['veh'];
$stores = array_map(fn($s) => [$s['store_code'], $s['store_name'], $s['city'], $s['state'], $s['cluster_type'], (float)$s['latitude'], (float)$s['longitude'], $s['store_status']],
  $d->query("SELECT store_code, store_name, city, state, cluster_type, latitude, longitude, store_status FROM stores WHERE COALESCE(active,true) AND COALESCE(latitude,0)<>0 AND COALESCE(longitude,0)<>0 ORDER BY store_code")->fetchAll(PDO::FETCH_ASSOC));
$jf = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
head('Control Tower');
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin="">
<link rel="stylesheet" href="<?= e(asset('assets/css/control_tower.css')) ?>">
<div class="ct-page">
<div class="ct" id="ct">
  <aside class="ct-list" id="ctList" aria-label="Trips">
    <div class="ct-chips" id="ctChips" role="tablist"></div>
    <div class="ct-cards" id="ctCards"></div>
  </aside>
  <section class="ct-map">
    <div class="ct-bar">
      <button type="button" class="ct-btn" id="ctPanel" title="Show / hide the trip list" aria-label="Show or hide the trip list">☰</button>
      <input class="ct-q" id="ctQ" type="search" placeholder="Search trip, vehicle, driver or store" aria-label="Search">
      <form class="ct-dates" method="get"><input type="date" name="from" value="<?= e($from) ?>" aria-label="From date"><span>–</span><input type="date" name="to" value="<?= e($to) ?>" aria-label="To date"><button class="ct-btn" title="Apply the dates">Go</button></form>
      <button type="button" class="ct-btn on" id="ctVeh" title="Live vehicles">🚚</button>
      <button type="button" class="ct-btn" id="ctSto" title="Store locations">🏬</button>
    </div>
    <div id="ctMap"></div>
    <div class="ct-note" id="ctNote" hidden></div>
  </section>
</div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<script>
(function(){
'use strict';
const T=<?= json_encode($tj, $jf) ?>, STORES=<?= json_encode($stores, $jf) ?>, CSRF=<?= json_encode(csrf(), $jf) ?>;
const V=<?= json_encode($veh, $jf) ?>;
const $=s=>document.querySelector(s), esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const MON=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const fmt=s=>{if(!s)return '—';const d=new Date(String(s).replace(' ','T'));if(isNaN(d))return String(s);let h=d.getHours(),m=String(d.getMinutes()).padStart(2,'0'),ap=h>=12?'PM':'AM';h=h%12||12;return MON[d.getMonth()]+' '+String(d.getDate()).padStart(2,'0')+', '+d.getFullYear()+' at '+String(h).padStart(2,'0')+':'+m+' '+ap;};
const grp=s=>['In Transit','Breakdown','Accident'].includes(s)?'transit':(s==='Delivered'?'done':'open');
const label=s=>s==='Delivered'?'Completed':(s==='Planned'?'Pending':(s==='Gate Pass Created'?'Gate pass':s));
const hasTransit=T.some(t=>grp(t.status)==='transit');
const FILTERS=['transit','active','pending','accepted','delayed','eta','done','all'], P=new URLSearchParams(location.search), INIT_SEL=+(P.get('sel')||0);
let filter=FILTERS.includes(P.get('f'))?P.get('f'):(INIT_SEL?'all':(hasTransit?'transit':'active')), q='', sel=null, showVeh=true, showSto=false;

// ---- map
const map=L.map('ctMap',{zoomControl:false}).setView([22.5,79],5); L.control.zoom({position:'bottomright'}).addTo(map);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
const vehLayer=L.layerGroup().addTo(map), routeLayer=L.layerGroup().addTo(map), cluster=L.markerClusterGroup({showCoverageOnHover:false,maxClusterRadius:55});
const vIcon=(g)=>L.divIcon({className:'',html:'<div class="ct-ico ct-'+g[0]+'">'+(g==='transit'?'⏳':g==='done'?'✔':'🚚')+'</div>',iconSize:[34,34],iconAnchor:[17,17],popupAnchor:[0,-17]});
STORES.forEach(s=>{const m=L.circleMarker([s[5],s[6]],{radius:6,color:'#1d4ed8',weight:2,fillColor:'#60a5fa',fillOpacity:.85}); m.bindPopup('<b>'+esc(s[0])+'</b> '+esc(s[1])+'<br>'+esc([s[2],s[3]].filter(Boolean).join(', '))+(s[4]?'<br>Cluster '+esc(s[4]):'')+(s[7]?' · '+esc(s[7]):'')); cluster.addLayer(m);});

// ---- list
const match=(t,f)=>{const g=grp(t.status); return f==='transit'?g==='transit':f==='active'?g!=='done':f==='done'?g==='done':f==='pending'?!t.acc:f==='accepted'?t.acc:f==='delayed'?t.delay:f==='eta'?t.etaToday:true;};
const tripsShown=()=>T.filter(t=>{ if(!match(t,filter))return false;
  if(!q)return true; const hay=(t.code+' '+(t.veh||'')+' '+(t.driver||'')+' '+(t.dc||'')+' '+t.stops.map(s=>s.code+' '+s.name).join(' ')).toLowerCase(); return hay.includes(q);});
const chips=[['transit','In transit'],['active','Not delivered'],['pending','Pending acceptance'],['delayed','Delayed'],['eta','ETA today'],['done','Completed'],['all','All']];
function renderChips(){$('#ctChips').innerHTML=chips.map(c=>{const n=T.filter(t=>match(t,c[0])).length;return '<button type="button" class="ct-chip'+(filter===c[0]?' on':'')+(c[0]==='delayed'&&n?' warn':'')+'" data-f="'+c[0]+'">'+c[1]+' <b>'+n+'</b></button>';}).join('');}function card(t){
  const g=grp(t.status), last=t.stops[t.stops.length-1], v=t.vk&&V[t.vk];
  const stopsHtml=t.stops.slice(0,3).map(s=>'<div class="ct-node'+(s.at?' done':'')+'"><span class="ct-dot"></span><div><b>'+(t.stops.length>1?'Stop '+s.n:'Destination')+'</b><span>'+esc(s.code)+(s.name&&s.name!==s.code?' · '+esc(s.name):'')+'</span><small>'+(s.at?'Delivered '+esc(fmt(s.at)):(s.eta?'ETA '+esc(fmt(s.eta)):'Pending'))+' · '+s.pkt+' PKT'+(s.late?' <b class="ct-late">Delayed'+(s.lateH?' '+s.lateH+' h':'')+'</b>':'')+'</small></div></div>').join('')+(t.stops.length>3?'<div class="ct-more">+ '+(t.stops.length-3)+' more stop(s)</div>':'');
  return '<article class="ct-card'+(sel===t.id?' sel':'')+(t.delay?' late':'')+'" data-id="'+t.id+'" tabindex="0"><header><div><b>'+esc(t.code)+'</b><span>'+esc(t.driver||'No driver')+'</span></div><em class="ct-pill ct-p-'+g+'">'+esc(label(t.status))+'</em></header>'
   +'<div class="ct-meta"><span class="ct-tag" title="Indent">'+esc(t.indents||'No indent')+'</span><span class="ct-tag">'+t.stops.length+' stop'+(t.stops.length===1?'':'s')+'</span><span class="ct-tag">'+t.pkt+' PKT</span>'
   +(t.phone?'<a class="ct-call" href="tel:'+esc(t.phone)+'" title="Call '+esc(t.driver||'driver')+'">📞 '+esc(t.phone)+'</a>':'')+'</div>'
   +'<div class="ct-tl"><div class="ct-node done"><span class="ct-dot"></span><div><b>Source</b><span>'+esc(t.dcName||t.dc||'—')+'</span><small>'+esc(fmt(t.start))+'</small></div></div>'+stopsHtml+'</div>'
   +'<footer>'+(t.veh?'<span class="ct-veh">🚚 '+esc(t.veh)+'</span>':'<span class="ct-veh none">No vehicle</span>')+(v?'<span class="ct-live '+(v.state==='RUNNING'?'run':v.fresh==='lost'?'lost':'')+'">'+esc(v.state)+(v.speed!==null?' · '+v.speed+' km/h':'')+' · '+esc(v.age)+'</span>':(t.veh?'<span class="ct-live none">not in GPS reports</span>':''))+'</footer></article>';
}
function renderList(){renderChips(); const list=tripsShown(); $('#ctCards').innerHTML=list.length?list.map(card).join(''):'<div class="ct-empty">No trips for this filter and date range.</div>';}

// ---- vehicles on the map
function renderVeh(){
  vehLayer.clearLayers(); const seen={};
  tripsShown().forEach(t=>{const v=t.vk&&V[t.vk]; if(!v||!v.pos||seen[t.vk])return; seen[t.vk]=1; const g=grp(t.status);
    const m=L.marker([v.pos.lat,v.pos.lng],{icon:vIcon(g),zIndexOffset:g==='transit'?1000:0}).addTo(vehLayer);
    m.bindPopup('<b>'+esc(v.no)+'</b> <span class="ct-pill ct-p-'+g+'">'+esc(label(t.status))+'</span><br>Trip '+esc(t.code)+(t.driver?' · '+esc(t.driver):'')+'<br>'+esc(v.state)+(v.speed!==null?' · '+v.speed+' km/h':'')+' · '+esc(v.age)+'<br>'+esc(v.location||'')+'<br><small>'+(v.exact?'Position from the report coordinates':'Approximate position (from the report address)')+'</small>');
    m.on('click',()=>selectTrip(t.id,true));});
}
// ---- route of the selected trip
function selectTrip(id,fromMap){
  sel=id; const t=T.find(x=>x.id===id); routeLayer.clearLayers(); if(!t){renderList();return;}
  const pts=[]; if(t.dcPos){pts.push(t.dcPos); L.circleMarker(t.dcPos,{radius:7,color:'#101828',fillColor:'#101828',fillOpacity:1}).addTo(routeLayer).bindTooltip('Source '+(t.dc||''),{permanent:false});}
  t.stops.forEach(s=>{if(s.pos){pts.push(s.pos); L.marker(s.pos,{icon:L.divIcon({className:'',html:'<div class="ct-stop">'+s.n+'</div>',iconSize:[24,24],iconAnchor:[12,12]})}).addTo(routeLayer).bindTooltip(esc(s.code)+' '+esc(s.name||''));}});
  const v=t.vk&&V[t.vk]; if(pts.length>1)L.polyline(pts,{weight:3,color:'#2563eb',dashArray:'8 6'}).addTo(routeLayer);
  if(v&&v.trail&&v.trail.length>1)L.polyline(v.trail,{weight:3,color:'#f79009'}).addTo(routeLayer).bindTooltip("Today's movement");
  const all=pts.slice(); if(v&&v.pos)all.push([v.pos.lat,v.pos.lng]);
  if(all.length)map.fitBounds(L.latLngBounds(all),{padding:[60,60],maxZoom:11});
  if(!showVeh){showVeh=true;$('#ctVeh').classList.add('on');map.addLayer(vehLayer);}
  renderList(); const el=document.querySelector('.ct-card.sel'); if(el&&fromMap)el.scrollIntoView({block:'nearest',behavior:'smooth'});
  vehLayer.eachLayer(l=>{ if(v&&v.pos&&l.getLatLng&&Math.abs(l.getLatLng().lat-v.pos.lat)<1e-6&&Math.abs(l.getLatLng().lng-v.pos.lng)<1e-6&&!fromMap)l.openPopup(); });
}
// ---- controls
$('#ctChips').addEventListener('click',e=>{const b=e.target.closest('.ct-chip');if(!b)return;filter=b.dataset.f;sel=null;routeLayer.clearLayers();renderList();renderVeh();});
$('#ctCards').addEventListener('click',e=>{if(e.target.closest('a'))return;const c=e.target.closest('.ct-card');if(c)selectTrip(+c.dataset.id,false);});
$('#ctCards').addEventListener('keydown',e=>{if(e.key==='Enter'){const c=e.target.closest('.ct-card');if(c)selectTrip(+c.dataset.id,false);}});
$('#ctQ').addEventListener('input',e=>{q=e.target.value.trim().toLowerCase();renderList();renderVeh();});
$('#ctVeh').addEventListener('click',e=>{showVeh=!showVeh;e.currentTarget.classList.toggle('on',showVeh);showVeh?map.addLayer(vehLayer):map.removeLayer(vehLayer);});
$('#ctSto').addEventListener('click',e=>{showSto=!showSto;e.currentTarget.classList.toggle('on',showSto);showSto?map.addLayer(cluster):map.removeLayer(cluster);});
$('#ctPanel').addEventListener('click',()=>{$('#ct').classList.toggle('nolist');setTimeout(()=>map.invalidateSize(),250);});
function fitInitial(){const b=[]; vehLayer.eachLayer(l=>b.push(l.getLatLng())); if(b.length)map.fitBounds(L.latLngBounds(b),{padding:[70,70],maxZoom:10}); else {const s=[]; T.forEach(t=>t.stops.forEach(x=>x.pos&&s.push(x.pos))); if(s.length)map.fitBounds(L.latLngBounds(s),{padding:[40,40]});}}

// ---- addresses the server could only estimate: this browser looks them up (the server cannot reach OpenStreetMap), one per second, and saves them
const kmBetween=(a,b)=>{const R=6371,r=x=>x*Math.PI/180,dp=r(b.lat-a.lat),dl=r(b.lng-a.lng),x=Math.sin(dp/2)**2+Math.cos(r(a.lat))*Math.cos(r(b.lat))*Math.sin(dl/2)**2;return 2*R*Math.asin(Math.min(1,Math.sqrt(x)));};
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const nom=async qs=>{const r=await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=in&'+qs,{headers:{'Accept-Language':'en'}});if(!r.ok)throw new Error('lookup service answered '+r.status);const a=await r.json();return a[0]?{lat:+a[0].lat,lng:+a[0].lon}:null;};
const save=f=>{const fd=new FormData();fd.append('csrf',CSRF);Object.keys(f).forEach(k=>fd.append(k,f[k]));return fetch('TRACK_SAVE.php',{method:'POST',body:fd,credentials:'same-origin'}).catch(()=>{});};
const lookup=async plan=>{let pinPt=plan.pin_pt; if(!pinPt&&plan.pin){pinPt=await nom('postalcode='+plan.pin);if(pinPt)save({pin:plan.pin,lat:pinPt.lat,lng:pinPt.lng});await sleep(1100);}
  for(const qq of plan.queries){const c=await nom('q='+encodeURIComponent(qq+', India'));await sleep(1100);if(c&&(!pinPt||kmBetween(pinPt,c)<=40))return c;} return pinPt;};
let working=false; const done={};
async function runLookups(){ if(working)return; working=true; const note=$('#ctNote');
  try{ for(const k of Object.keys(V)){const v=V[k]; if(!v.lookup||done[v.lookup.address])continue; done[v.lookup.address]=1; note.hidden=false; note.textContent='Finding the exact position of '+v.no+'…';
      try{const p=await lookup(v.lookup); if(p){v.pos=p;save({address:v.lookup.address,lat:p.lat,lng:p.lng});} v.lookup=null; renderVeh(); renderList();}catch(e){note.textContent='Position lookup failed: '+(e&&e.message?e.message:e);await sleep(2500);} } }
  finally{working=false; if(!Object.values(V).some(v=>v.lookup&&!done[v.lookup.address]))note.hidden=true;} }
async function refresh(){ if(document.hidden)return; const nos=[...new Set(T.map(t=>t.veh).filter(Boolean))].join(','); if(!nos)return;
  try{const r=await fetch('CONTROL_TOWER.php?live=1&nos='+encodeURIComponent(nos),{credentials:'same-origin'}); const j=await r.json(); if(j&&j.ok){Object.keys(j.veh).forEach(k=>{const n=j.veh[k],o=V[k]; if(o&&o.pos&&!n.exact&&n.lookup)n.pos=o.pos; V[k]=n;}); renderVeh(); renderList(); runLookups();}}catch(e){} }
renderList(); renderVeh(); fitInitial(); if(INIT_SEL&&T.some(t=>t.id===INIT_SEL))selectTrip(INIT_SEL,false); runLookups(); setInterval(refresh,60000);
})();
</script>
<?php foot(); ?>
