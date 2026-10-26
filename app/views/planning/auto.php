<?php
// Auto trips: a form (site, clusters, rules) -> the system analyses the PRD Pending stores and proposes the trips as tables:
// cluster analysis, and the trip sequence (trip -> stop 1, 2, 3 with km). 2-3 close stores per trip, nobody left alone if a neighbour can take them,
// low-stock stores that were dispatched recently wait. Confirm = trips are created.
// Included from pages/PLANNING.php. Needs $d, e(), csrf().
require_once TMS_ROOT . '/app/services/prd_planner.php';
require_once TMS_ROOT . '/app/services/oracle_sync.php';
if (!can('plan')): ?><div class="alert alert-err">Your role cannot create trips.</div><?php return; endif;
$vo = prd_view_opts($_GET); if ($vo['wh'] === 'joint') $vo = ['mode' => 'separate', 'site' => null, 'wh' => 'all'];
$M = prd_matrix($d, ['site' => null, 'sug' => 'separate']); // every site: the form picks the sites
$S = $M['sites']; $R = $M['rows']; $cfg = $M['cfg']; $nSites = count($S); $pc = prd_cfg();
$oc = tms_oracle_cfg(); $allSites = [];
foreach ($oc['src_site_codes'] as $sc) $allSites[(string)$sc] = (string)$sc;
foreach ($S as $sc => $l) $allSites[$sc] = $l;
// Source = the DC (short code + name) behind each Oracle site code
$srcMap = []; foreach ($allSites as $sc => $lbl) $srcMap[$sc] = ['c' => $sc, 'n' => $lbl];
try { foreach ($d->query("SELECT oracle_site_code sc, dc_code, dc_name FROM tms_dc_master WHERE COALESCE(oracle_site_code,'')<>''")->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($srcMap[(string)$r['sc']])) $srcMap[(string)$r['sc']] = ['c' => $r['dc_code'], 'n' => $r['dc_name']]; } catch (Throwable $e) {}
$q = fn(array $over) => 'PLANNING.php?' . http_build_query(array_merge(['tab' => 'auto', 'wh' => $vo['wh']], $over));
$token = csrf(); $dueDays = (int)$cfg['due_days'];
$freeVeh = (int)$d->query("SELECT COUNT(*) FROM vehicles WHERE active IS TRUE AND id NOT IN (SELECT vehicle_id FROM trips WHERE vehicle_id IS NOT NULL AND status NOT IN ('Delivered','Cancelled'))")->fetchColumn();
$freeDrv = (int)$d->query("SELECT COUNT(*) FROM drivers WHERE active IS TRUE AND id NOT IN (SELECT driver_id FROM trips WHERE driver_id IS NOT NULL AND status NOT IN ('Delivered','Cancelled'))")->fetchColumn();

$clusters = []; $js = []; $total = 0; $states = [];
foreach ($R as $id => $r) {
  if ($r['off']) continue;                              // on hold / not in store master / closed: never planned
  $cl = trim((string)$r['cluster']); if ($cl === '-') $cl = '';
  $key = $cl !== '' ? $cl : '(no cluster)';
  $c = &$clusters[$key]; $c = $c ?? ['n' => 0, 'pkt' => 0, 'due' => 0]; $c['n']++; $c['pkt'] += (int)$r['pkt']; $c['due'] += $r['due'] ? 1 : 0; unset($c);
  if ($r['state'] !== '') $states[$r['state']] = 1;
  $by = []; foreach ($r['by'] as $sc => $b) $by[(string)$sc] = ['k' => $b['gkey'], 'p' => (int)$b['pkt'], 'v' => round((float)$b['val'], 2)];
  $js[$id] = ['st' => (string)$r['state'], 'by' => $by, 'keys' => $r['keys'], 'pkt' => (int)$r['pkt'], 'cl' => $key, 'due' => (bool)$r['due'], 'wait' => $r['wait'] !== null ? (int)$r['wait'] : null, 'code' => $r['store']['code'] ?? $r['name'], 'name' => $r['store']['name'] ?? $r['name']];
  $total++;
}
ksort($clusters); ksort($states);
$cfgSites = array_flip(array_map('strval', $oc['src_site_codes'] ?? [])); if ($cfgSites) $srcMap = array_intersect_key($srcMap, $cfgSites); // only the configured Oracle sources (123, 420); the 3 old LEGACY rows are not a source
?>
<link rel="stylesheet" href="<?= e(asset('assets/css/auto.css')) ?>">
<div class="au" id="au">
  <div class="au-bar"><h2>Auto trips</h2><div class="bt"><button type="button" class="au-btn pri big" onclick="au.open()">＋ Auto Trips</button></div></div>
  <div class="au-empty" id="auEmpty">Click <b>＋ Auto Trips</b> to plan trips.</div>

  <dialog class="au-dlg" id="auDlg">
    <form class="au-form" id="auForm" onsubmit="au.run();return false" autocomplete="off">
      <div class="dh"><h3>Create Auto Trips</h3><button type="button" class="x" onclick="au.close()" aria-label="Close">×</button></div>
      <input type="hidden" id="auMode" value="separate"><input type="hidden" id="auWho" value="all"><input type="hidden" id="auMax" value="3">
      <input type="radio" name="auClus" value="same" checked hidden><input type="checkbox" id="auLow" checked hidden>
      <div class="db">
        <div class="fld"><span>Source <i>*</i></span>
          <div class="ms" id="msSite"><button type="button" class="ms-b"><span class="v">Select An Option</span><i>▾</i></button>
            <div class="ms-p" hidden><input class="ms-s" type="search" placeholder="Search source" aria-label="Search source"><div class="ms-a"><a href="#" data-act="all">Select all</a><a href="#" data-act="none">Clear</a></div><div class="ms-l"></div></div></div></div>
        <div class="fld"><span>State</span>
          <div class="ms" id="msState"><button type="button" class="ms-b"><span class="v">Select An Option</span><i>▾</i></button>
            <div class="ms-p" hidden><input class="ms-s" type="search" placeholder="Search state" aria-label="Search state"><div class="ms-a"><a href="#" data-act="all">Select all</a><a href="#" data-act="none">Clear</a></div><div class="ms-l"></div></div></div></div>
        <div class="fld"><span>Cluster <i>*</i></span>
          <div class="ms" id="msCl"><button type="button" class="ms-b"><span class="v">Select An Option</span><i>▾</i></button>
            <div class="ms-p" hidden><input class="ms-s" type="search" placeholder="Search cluster" aria-label="Search cluster"><div class="ms-a"><a href="#" data-act="all">Select all</a><a href="#" data-act="none">Clear</a></div><div class="ms-l"></div></div></div></div>
        <div class="fld"><span>Store</span>
          <div class="ms" id="msSt"><button type="button" class="ms-b"><span class="v">Select An Option</span><i>▾</i></button>
            <div class="ms-p" hidden><input class="ms-s" type="search" placeholder="Search store" aria-label="Search store"><div class="ms-a"><a href="#" data-act="all">Select all shown</a><a href="#" data-act="none">Clear</a></div><div class="ms-l"></div></div></div></div>
        <label class="chk w" id="auBothRow" hidden><input type="checkbox" id="auBoth"><span>Only stores that can go <b>FTL from both DCs</b> <b id="auBothN"></b><small>Stores with PKT in both chosen DCs whose PKT together is <?= (int)(app_cfg('trip.single_min_pkt') ?? 400) ?> or more: one full truck collects from both DCs.</small></span></label>
        <label class="fld"><span>Low stock: PKT below</span><input id="auLowPkt" type="number" min="1" value="<?= (int)($pc['low_stock_pkt'] ?? 100) ?>"></label>
        <label class="fld"><span>and dispatched within (days)</span><input id="auLowDays" type="number" min="1" value="<?= (int)($pc['low_stock_days'] ?? 3) ?>"></label>
        <div class="w note" id="auPre"></div>
      </div>
      <div class="df"><button type="submit" class="au-btn pri big" id="auRun" <?= $total ? '' : 'disabled' ?>>Submit</button><button type="button" class="au-btn big" onclick="au.reset()">Reset</button><button type="button" class="au-btn big" onclick="au.close()">Close</button></div>
    </form>
  </dialog>
  <section class="au-res" id="auRes" hidden>
    <div class="tabs" id="auTabs" role="tablist"></div>
    <div id="auBody"></div>
    <div class="foot" id="auFoot"><div class="sum" id="auSum"></div><div class="btns"><button type="button" class="au-btn" onclick="au.csv()">Download CSV</button><button type="button" class="au-btn pri big" id="auGo" onclick="au.create()">Create trips</button></div></div>
  </section>
</div>
<script>
(function(){
  const D=<?= json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const TOKEN=<?= json_encode($token) ?>, MINFILL=<?= (int)$cfg['min_fill_pct'] ?>, FREEV=<?= $freeVeh ?>, FREED=<?= $freeDrv ?>, DEF=<?= json_encode(['lp' => (int)($pc['low_stock_pkt'] ?? 100), 'ld' => (int)($pc['low_stock_days'] ?? 3)]) ?>;
  const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const n0=v=>Math.round(v).toLocaleString('en-IN');
  const L=v=>'₹'+(v>=1e7?(v/1e7).toFixed(2)+' Cr':v>=1e5?(v/1e5).toFixed(2)+' L':n0(v));
  let plan=null,skipped=[],tab='seq',tok=0,rows=[],both=[],runSites=[];
  async function post(data){
    const fd=new FormData();fd.append('csrf',TOKEN);Object.entries(data).forEach(([k,v])=>Array.isArray(v)?v.forEach(x=>fd.append(k+'[]',x)):fd.append(k,v));
    const r=await fetch('PLANNING.php?tab=auto',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    let j;try{j=await r.json();}catch(e){throw new Error('Unexpected server response (HTTP '+r.status+').');}
    if(!j.ok)throw new Error(j.error||'Request failed');return j;
  }
  const SITES=<?= json_encode($srcMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, STATES=<?= json_encode(array_keys($states), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  // ---- searchable multi-select dropdown (Site, State, Cluster, Store) ----
  function ms(root,o){
    const b=$('.ms-b',root)||root.querySelector('.ms-b'), p=root.querySelector('.ms-p'), s=root.querySelector('.ms-s'), l=root.querySelector('.ms-l'), v=root.querySelector('.v');
    let items=[], sel=new Set();
    const shown=()=>{const q=s.value.toLowerCase().trim();return items.filter(i=>!q||i.t.toLowerCase().includes(q));};
    function label(){const n=sel.size; v.textContent=n===0?o.empty(items.length):(n===items.length&&o.allOk?o.allText(n):(n<=2?items.filter(i=>sel.has(i.v)).map(i=>i.short).join(', '):n+' selected')); v.classList.toggle('ph',n===0&&!o.allOk);}
    function draw(){l.innerHTML=shown().map(i=>'<label class="ms-i"><input type="checkbox" value="'+esc(i.v)+'"'+(sel.has(i.v)?' checked':'')+'><span class="t">'+esc(i.short)+(i.name?' <em>'+esc(i.name)+'</em>':'')+'</span><span class="u">'+esc(i.sub)+'</span></label>').join('')||'<div class="ms-n">No match</div>';}
    function change(){label();o.onChange&&o.onChange();}
    root.querySelector('.ms-b').addEventListener('click',()=>{const open=p.hidden;$$('.ms-p').forEach(x=>x.hidden=true);p.hidden=!open;if(open){s.value='';draw();s.focus();}});
    s.addEventListener('input',draw);
    l.addEventListener('change',e=>{const c=e.target;if(c.checked)sel.add(c.value);else sel.delete(c.value);change();});
    root.querySelector('.ms-a').addEventListener('click',e=>{const a=e.target.closest('a');if(!a)return;e.preventDefault();if(a.dataset.act==='all')shown().forEach(i=>sel.add(i.v));else sel.clear();draw();change();});
    document.addEventListener('click',e=>{if(!root.contains(e.target))p.hidden=true;});
    return {set(list,keep){items=list;const ok=new Set(list.map(i=>i.v));sel=new Set([...(keep?sel:[])].filter(x=>ok.has(x)));label();},get:()=>[...sel],all(){sel=new Set(items.map(i=>i.v));draw();label();},clear(){sel.clear();draw();label();},count:()=>items.length};
  }
  const CL=<?= json_encode($clusters, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const msSite=ms($('#msSite'),{allOk:true,empty:n=>'Select An Option',allText:n=>'All sources ('+n+')',onChange:()=>{fillClusters();fillStores();pre();}});
  const msState=ms($('#msState'),{allOk:true,empty:n=>'Select An Option',allText:n=>'All states ('+n+')',onChange:()=>{fillClusters();fillStores();pre();}});
  let clAuto=true; // true while the cluster selection is 'all': it follows the State / Site choice
  const msCl=ms($('#msCl'),{allOk:true,empty:n=>'Select An Option',allText:n=>'All clusters ('+n+')',onChange:()=>{clAuto=msCl.get().length===msCl.count();fillStores();pre();}});
  const msSt=ms($('#msSt'),{allOk:false,empty:n=>n?'All stores of the clusters ('+n+')':'No store',allText:n=>n+' selected',onChange:()=>pre()});
  msSite.set(Object.keys(SITES).map(c=>({v:c,short:SITES[c].c,name:'',t:SITES[c].c+' '+SITES[c].n,sub:SITES[c].n})),false); msSite.all();
  msState.set(STATES.map(c=>({v:c,short:c,name:'',t:c,sub:''})),false); msState.all();
  fillClusters(); msCl.all();
  // what a store brings from the chosen sites: its group keys, PKT and value
  function view(id){const sites=new Set(msSite.get()),d=D[id],o={keys:[],pkt:0,val:0};Object.entries(d.by).forEach(([sc,b])=>{if(sites.has(sc)){o.keys.push(b.k);o.pkt+=b.p;o.val+=b.v;}});return o;}
  const SINGLE=<?= (int)(app_cfg('trip.single_min_pkt') ?? 400) ?>;
  const sname=sc=>(SITES[sc]||{}).c||sc;   // short name of a source
  const sitesOf=id=>{const sites=new Set(msSite.get());return Object.entries(D[id].by).filter(([sc,b])=>sites.has(sc)&&b.p>0).map(([sc])=>sc);};
  const ftlBoth=id=>{const v=view(id);return sitesOf(id).length>1&&v.pkt>=SINGLE;};   // PKT from the chosen DCs together is a full truck
  const bothOn=()=>msSite.get().length>1&&$('#auBoth').checked;
  // only the clusters that exist in the chosen states / sites; a selection that was 'all' stays 'all'
  function fillClusters(){const sa=new Set(msState.get()),was=clAuto,by={};
    Object.entries(D).forEach(([id,d])=>{if(!sa.has(d.st))return;const v=view(id);if(!v.keys.length)return;const o=by[d.cl]=by[d.cl]||{n:0,pkt:0,due:0};o.n++;o.pkt+=v.pkt;if(d.due)o.due++;});
    msCl.set(Object.keys(by).sort().map(c=>({v:c,short:c,name:'',t:c,sub:by[c].n+' stores · '+Math.round(by[c].pkt).toLocaleString('en-IN')+' PKT'+(by[c].due?' · '+by[c].due+' overdue':'')})),true); if(was)msCl.all();}
  function fillStores(){const cs=new Set(msCl.get()),sa=new Set(msState.get()),multi=msSite.get().length>1;msSt.set(Object.entries(D).filter(([id,d])=>cs.has(d.cl)&&sa.has(d.st)&&view(id).keys.length&&(!bothOn()||ftlBoth(id))).sort((a,b)=>a[1].code.localeCompare(b[1].code)).map(([id,d])=>({v:id,short:d.code,name:d.name,t:d.code+' '+d.name+' '+d.cl,sub:multi?sitesOf(id).map(sc=>sname(sc)+' '+d.by[sc].p).join(' + ')+' PKT':d.cl+' · '+view(id).pkt+' PKT'})),true);}
  fillStores();
  const rules=()=>({both:bothOn(),cls:new Set(msCl.get()),sta:new Set(msState.get()),sto:new Set(msSt.get()),low:$('#auLow').checked,lp:+$('#auLowPkt').value||0,ld:+$('#auLowDays').value||0,who:$('#auWho').value,max:+$('#auMax').value||3,strict:($('input[name=auClus]:checked')||{}).value!=='mix'});
  function pick(){
    const r=rules(), go=[], wait=[], per={};
    Object.entries(D).forEach(([id,d])=>{
      if(!r.cls.has(d.cl)||!r.sta.has(d.st))return; if(r.sto.size&&!r.sto.has(id))return; if(r.who==='due'&&!d.due)return;
      const v=view(id); if(!v.keys.length)return; if(r.both&&!ftlBoth(id))return;
      if(r.low&&v.pkt<r.lp&&d.wait!==null&&d.wait<r.ld){wait.push(Object.assign({},d,{pkt:v.pkt}));per[d.cl]=(per[d.cl]||0)+1;return;}
      go.push(id);});
    return {go,wait,per,r};
  }
  function pre(){
    const ns=msSite.get().length; const bothN=Object.keys(D).filter(id=>ftlBoth(id)&&msCl.get().includes(D[id].cl)&&msState.get().includes(D[id].st)).length;
    $('#auBothRow').hidden=ns<2; $('#auBothN').textContent='('+bothN+')';
    const p=pick();
    const pk=p.go.reduce((s,i)=>s+view(i).pkt,0); $('#auPre').textContent=p.go.length+' stores · '+pk.toLocaleString('en-IN')+' PKT will be planned'+(p.wait.length?', '+p.wait.length+' will wait':'');
  }
  function reset(){$('#auBoth').checked=false;msSite.all();msState.all();msCl.all();fillStores();msSt.clear();$('#auMax').value='3';$('input[name=auClus][value=same]').checked=true;$('#auLow').checked=true;$('#auLowPkt').value=DEF.lp;$('#auLowDays').value=DEF.ld;$('#auWho').value='all';pre();$('#auRes').hidden=true;$('#auEmpty').hidden=false;plan=null;}
  function closeRes(){$('#auRes').hidden=true;$('#auEmpty').hidden=false;}
  async function run(){
    const p=pick(); skipped=p.wait; if(!p.go.length){alert('No store to plan with these settings.');return;}
    const b=$('#auRun'); b.disabled=true; b.textContent='Analysing…'; const t=++tok;
    try{
      $('#auDlg').close(); const ns=msSite.get().length; both=p.go.filter(i=>sitesOf(i).length>1).map(i=>({id:i,sites:sitesOf(i)})); runSites=msSite.get(); const j=await post({action:'prd_preview',keys:p.go.flatMap(i=>view(i).keys),mode:ns>1?'joint':'separate',balance:1,strict:p.r.strict?1:'',max_stops:p.r.max});
      if(t!==tok)return; plan=j; plan.loads.forEach(l=>{l.on=true;l.cl=clusterOf(l);}); tab='seq'; render(); $('#auEmpty').hidden=true; $('#auRes').hidden=false; $('#auRes').scrollIntoView({behavior:'smooth',block:'start'});
    }catch(e){alert('Analysis failed: '+e.message);$('#auDlg').showModal();}
    finally{b.disabled=false;b.textContent='⚡ Analyse & plan trips';}
  }
  function clusterOf(l){const c=l.stops.map(s=>(s.cluster&&s.cluster!=='-')?s.cluster:'(no cluster)'); const u=[...new Set(c)]; return u.join(' + ');}
  const capOf=t=>{const c=plan.classes.find(x=>x.type===t);return c?c.capacity:0;};
  const vehOpts=cur=>{const o=plan.classes.map(c=>c.type);if(cur&&!o.includes(cur))o.push(cur);return o.map(t=>'<option'+(t===cur?' selected':'')+'>'+esc(t)+'</option>').join('');};
  const COLS=[['',34],['Trip',62],['Cluster',64],['Source',120],['Destination 1',152],['Destination 2',152],['Destination 3',152],['PKT',54,'n'],['Value',74,'n'],['Route km',74,'n'],['Fill',118],['Vehicle',76],['Remark',300]];
  const COLKEY='au_cols_v5';
  const colW=()=>{try{const a=JSON.parse(localStorage.getItem(COLKEY)||'null');if(Array.isArray(a)&&a.length===COLS.length)return a;}catch(e){}return COLS.map(c=>c[1]);};
  // Remark: one short line per note, full text in the tooltip
  function remark(l){
    const hops=l.stops.slice(1).map(s=>s.km).filter(x=>x!=null), far=hops.length?Math.max(...hops):null;
    const t=l.stops.length>1?'Clubbed'+(far!=null?' · stores ≤ '+far+' km apart':''):'Alone: '+(l.alone||'');
    const w=(l.warnings||[]).slice(); if(!l.dc)w.push('Site not linked to a Center DC: cannot be created.');
    return '<span class="'+(l.stops.length>1?'ok':'alone')+'" title="'+esc(t)+'">'+esc(t)+'</span>'+(w.length?'<span class="wi" title="'+esc(w.join('\n'))+'">⚠ '+w.length+'</span>':'');
  }
  // ---- tab 1: trip table, one row per trip: Source -> Destination 1, 2, 3 (codes only; hover a code for its details) ----
  function seqTable(){
    const loads=plan.loads, w=colW(); rows=[['Trip','Cluster','Source','Destination 1','Destination 2','Destination 3','PKT per destination','Trip PKT','Value','Route km','Fill %','Vehicle','Remark']];
    let h='<div class="tw scr"><table class="tbl seq" style="width:'+w.reduce((a,b)=>a+b,0)+'px"><colgroup>'+w.map(x=>'<col style="width:'+x+'px">').join('')+'</colgroup><thead><tr>'+COLS.map((c,i)=>'<th'+(c[2]?' class="n"':'')+'>'+esc(c[0])+'<i class="rz" data-c="'+i+'" title="Drag to resize"></i></th>').join('')+'</tr></thead><tbody>';
    loads.forEach((l,i)=>{
      const vt=l.vsel||l.vehicle, dcc=l.dc?l.dc.code:'no DC'; let cum=0, known=true;
      const dest=[0,1,2].map(k=>{const s=l.stops[k]; if(!s)return '<td class="dst"></td>';
        if(s.km!=null&&known)cum+=s.km; else known=false;
        const tip=s.code+' · '+(s.name||'')+(s.city?', '+s.city:'')+' · '+s.pkt+' PKT · '+L(s.value)+' · '+(s.km!=null?s.km+' km from '+(k?l.stops[k-1].code:dcc):'km n/a')+(s.part?' · part '+s.part:'')+(s.due?' · '+(s.wait||'')+' days late':'');
        return '<td class="dst" title="'+esc(tip)+'"><span class="cd">'+esc(s.code)+'</span><span class="dv">'+s.pkt+' PKT · '+L(s.value)+'</span></td>';}).join('');
      l.stops.forEach(s=>{});
      const kmTot=l.route_km!=null?l.route_km:(known?cum:null);
      h+='<tr class="trip'+(l.on?'':' skip')+'" data-i="'+i+'"><td class="ck"><input type="checkbox" '+(l.on?'checked':'')+' onchange="au.inc('+i+',this.checked)" aria-label="Include trip '+(i+1)+'"></td><td class="tn"><b>Trip '+(i+1)+'</b></td><td title="'+esc(l.cl)+'">'+esc(l.cl)+'</td>'+
        '<td class="src" title="'+esc((l.pickups||[]).map(p=>p.source+' '+p.pkt+' PKT').join(' + ')||l.source)+'">'+((l.pickups&&l.pickups.length>1)?l.pickups.map(p=>'<span class="cd dc">'+esc(p.dc?p.dc.code:p.source)+'</span>').join(' '):'<span class="cd dc">'+esc(dcc)+'</span>')+'</td>'+dest+
        '<td class="n"><b>'+l.pkt+'</b></td><td class="n">'+L(l.value)+'</td><td class="n">'+(kmTot!=null?n0(kmTot):'–')+'</td>'+
        '<td class="fl"><span class="m"><i id="auf'+i+'"></i></span><span class="pc" id="aut'+i+'"></span></td>'+
        '<td><select id="auv'+i+'" onchange="au.upd()" aria-label="Vehicle">'+vehOpts(vt)+'</select></td>'+
        '<td class="rm">'+remark(l)+'</td></tr>';
      rows.push(['Trip '+(i+1),l.cl,dcc,(l.stops[0]||{}).code||'',(l.stops[1]||{}).code||'',(l.stops[2]||{}).code||'',l.stops.map(s=>s.pkt).join(' / '),l.pkt,Math.round(l.value),kmTot!=null?kmTot:'',l.fill,vt,(l.stops.length>1?'Clubbed':'Alone: '+(l.alone||''))]);
    });
    return h+'</tbody></table></div>';
  }
  // drag the edge of a heading: the column follows the mouse, the widths are remembered in this browser
  function initResize(){
    const t=$('#auBody table.seq'); if(!t)return; const cols=[...t.querySelectorAll('col')];
    t.querySelectorAll('thead .rz').forEach(h=>h.addEventListener('mousedown',e=>{
      e.preventDefault(); const c=+h.dataset.c, x0=e.clientX, w0=parseFloat(cols[c].style.width); h.classList.add('on'); document.body.classList.add('au-rz');
      const mv=ev=>{cols[c].style.width=Math.max(34,w0+ev.clientX-x0)+'px'; t.style.width=cols.reduce((s,k)=>s+parseFloat(k.style.width),0)+'px';};
      const up=()=>{document.removeEventListener('mousemove',mv);document.removeEventListener('mouseup',up);h.classList.remove('on');document.body.classList.remove('au-rz');try{localStorage.setItem(COLKEY,JSON.stringify(cols.map(k=>parseFloat(k.style.width))));}catch(x){}};
      document.addEventListener('mousemove',mv);document.addEventListener('mouseup',up);}));
  }
  // widths follow the content (capped), then are remembered like a manual drag
  function fitCols(){
    const t=$('#auBody table.seq'); if(!t)return; const cols=[...t.querySelectorAll('col')];
    t.style.tableLayout='auto'; t.style.width='auto'; cols.forEach(c=>c.style.width='');
    const ws=[...t.querySelectorAll('thead th')].map(th=>Math.min(340,Math.max(34,Math.ceil(th.getBoundingClientRect().width))));
    t.style.tableLayout=''; cols.forEach((c,i)=>c.style.width=ws[i]+'px'); t.style.width=ws.reduce((a,b)=>a+b,0)+'px';
    try{localStorage.setItem(COLKEY,JSON.stringify(ws));}catch(e){}
  }
  function resetCols(){try{localStorage.removeItem(COLKEY);}catch(e){} render();}
  // ---- tab 2: analysis per cluster ----
  function clusterTable(){
    const by={}; plan.loads.forEach(l=>{const c=l.cl;const o=by[c]=by[c]||{trips:0,stores:0,pkt:0,cap:0,km:0,club:0,alone:0,over:0};o.trips++;o.stores+=l.stops.length;o.pkt+=l.pkt;o.cap+=capOf(l.vsel||l.vehicle);o.km+=l.route_km||0;if(l.stops.length>1)o.club++;else o.alone++;o.over+=l.stops.filter(s=>s.due).length;});
    const w={}; skipped.forEach(s=>w[s.cl]=(w[s.cl]||0)+1);
    const names=[...new Set([...Object.keys(by),...Object.keys(w)])].sort(); let t={trips:0,stores:0,pkt:0,cap:0,km:0,club:0,alone:0,over:0,w:0};
    let h='<div class="tw"><table class="tbl"><thead><tr><th>Cluster</th><th class="n">Trips</th><th class="n">Stores on trips</th><th class="n">PKT</th><th class="n">Trips with 2–3 stores</th><th class="n">Single-store trips</th><th class="n">Avg fill</th><th class="n">Route km</th><th class="n">Overdue stores</th><th class="n">Waiting (low stock)</th></tr></thead><tbody>';
    names.forEach(c=>{const o=by[c]||{trips:0,stores:0,pkt:0,cap:0,km:0,club:0,alone:0,over:0}; const x=w[c]||0; ['trips','stores','pkt','cap','km','club','alone','over'].forEach(k=>t[k]+=o[k]); t.w+=x;
      h+='<tr><td><b>'+esc(c)+'</b></td><td class="n">'+o.trips+'</td><td class="n">'+o.stores+'</td><td class="n">'+n0(o.pkt)+'</td><td class="n">'+o.club+'</td><td class="n">'+o.alone+'</td><td class="n">'+(o.cap?Math.round(o.pkt*100/o.cap):0)+'%</td><td class="n">'+n0(o.km)+'</td><td class="n">'+o.over+'</td><td class="n">'+x+'</td></tr>';});
    return h+'</tbody><tfoot><tr><td>Total</td><td class="n">'+t.trips+'</td><td class="n">'+t.stores+'</td><td class="n">'+n0(t.pkt)+'</td><td class="n">'+t.club+'</td><td class="n">'+t.alone+'</td><td class="n">'+(t.cap?Math.round(t.pkt*100/t.cap):0)+'%</td><td class="n">'+n0(t.km)+'</td><td class="n">'+t.over+'</td><td class="n">'+t.w+'</td></tr></tfoot></table></div>';
  }
  // ---- stores that BOTH chosen sources can dispatch (one vehicle collects from both warehouses) ----
  function bothTable(){
    if(!both.length)return '<div class="note">No planned store has PKT in more than one of the chosen DCs.</div>';
    const trips=code=>plan.loads.map((l,i)=>l.stops.some(s=>s.code===code)?'Trip '+(i+1):null).filter(Boolean).join(', ')||'–';
    const rowsB=both.map(b=>{const d=D[b.id],per=runSites.map(sc=>((d.by[sc]||{}).p)||0),tot=per.reduce((x,y)=>x+y,0),each=per.filter(p=>p>0).every(p=>p>=SINGLE);
      return {d,per,tot,v:tot<SINGLE?3:(each?1:2),t:trips(d.code)};}).sort((a,b)=>a.v-b.v||b.tot-a.tot);
    const verdict={1:'<span class="ftl y">FTL from each DC</span>',2:'<span class="ftl y">FTL with both DCs together</span>',3:'<span class="ftl n">Not FTL ('+SINGLE+'+ needed)</span>'};
    const nF=rowsB.filter(r=>r.v<3).length;
    let h='<div class="tb"><span class="note"><b>'+nF+'</b> of '+rowsB.length+' stores can go FTL (a full truck of their own) when both DCs are used. FTL = '+SINGLE+'+ PKT.</span><span></span></div><div class="tw"><table class="tbl"><thead><tr><th>Store</th><th>Cluster</th><th>State</th>'+runSites.map(sc=>'<th class="n">PKT '+esc(sname(sc))+'</th>').join('')+'<th class="n">Total PKT</th><th>FTL?</th><th>Trip</th></tr></thead><tbody>';
    h+=rowsB.map(r=>'<tr><td><b>'+esc(r.d.code)+'</b> <span class="sub">'+esc(r.d.name)+'</span></td><td>'+esc(r.d.cl)+'</td><td>'+esc(r.d.st)+'</td>'+r.per.map(p=>'<td class="n">'+(p||'–')+'</td>').join('')+'<td class="n"><b>'+r.tot+'</b></td><td>'+verdict[r.v]+'</td><td>'+esc(r.t)+'</td></tr>').join('');
    return h+'</tbody></table></div>';
  }
  // ---- tab 3: stores that wait / cannot be planned ----
  function waitTable(){
    let h='';
    if(plan.excluded.length)h+='<div class="excl"><b>'+plan.excluded.length+' store(s) cannot be planned</b><ul>'+plan.excluded.map(x=>'<li>'+esc(x.name)+': '+esc(x.reason)+'</li>').join('')+'</ul></div>';
    h+=skipped.length?'<div class="tw"><table class="tbl"><thead><tr><th>#</th><th>Store</th><th>Cluster</th><th class="n">PKT</th><th class="n">Last dispatch</th><th>Why</th></tr></thead><tbody>'+skipped.map((s,i)=>'<tr><td class="n">'+(i+1)+'</td><td><b>'+esc(s.code)+'</b> <span class="sub">'+esc(s.name)+'</span></td><td>'+esc(s.cl)+'</td><td class="n">'+s.pkt+'</td><td class="n">'+s.wait+' day'+(s.wait===1?'':'s')+' ago</td><td>Low stock and dispatched recently: no trip yet</td></tr>').join('')+'</tbody></table></div>':'<div class="note">No store is waiting because of the low-stock rule.</div>';
    return h;
  }
  function render(){
    const loads=plan.loads, pk=loads.reduce((s,l)=>s+l.pkt,0), cap=loads.reduce((s,l)=>s+capOf(l.vsel||l.vehicle),0), km=loads.reduce((s,l)=>s+(l.route_km||0),0);
    const club=loads.filter(l=>l.stops.length>1).length, stores=loads.reduce((s,l)=>s+l.stops.length,0);
    $('#auTabs').innerHTML=[['seq','Trips ('+loads.length+')'],['cl','By cluster'],...(runSites.length>1?[['both','Both sources ('+both.length+')']]:[]),['wait','Waiting ('+(skipped.length+plan.excluded.length)+')']].map(t=>'<button type="button" class="'+(tab===t[0]?'on':'')+'" onclick="au.tab(\''+t[0]+'\')">'+t[1]+'</button>').join('')+
      '<span class="hl"><b>'+loads.length+'</b> trips · <b>'+stores+'</b> stores · <b>'+n0(pk)+'</b> PKT · fill <b>'+(cap?Math.round(pk*100/cap):0)+'%</b> · <b>'+n0(km)+'</b> km · <b>'+club+'</b> clubbed</span>'+(tab==='seq'?'<a href="#" class="lk" onclick="au.fit();return false">Fit columns</a><a href="#" class="lk" onclick="au.cols();return false">Reset widths</a>':'')+'<button type="button" class="au-btn" onclick="au.closeRes()">✕ Close</button>';
    $('#auBody').innerHTML=tab==='seq'?seqTable():(tab==='cl'?clusterTable():(tab==='both'?bothTable():waitTable())); if(tab==='seq')initResize(); upd();
  }
  function inc(i,on){plan.loads[i].on=on;const t=$('tr.trip[data-i="'+i+'"]');if(t)t.classList.toggle('skip',!on);upd();}
  function upd(){
    let n=0,st=0,pk=0,cap=0,miss=0;
    plan.loads.forEach((l,i)=>{const sel=$('#auv'+i); if(sel)l.vsel=sel.value; const v=l.vsel||l.vehicle,c=capOf(v),f=c?Math.round(l.pkt*100/c):0;
      if(sel){const m=$('#auf'+i);$('#aut'+i).textContent=f+'%';$('#aut'+i).parentNode.title=f+'% of '+c+' PKT';m.style.width=Math.min(100,f)+'%';m.className=f>100?'over':(f<MINFILL?'low':'');}
      if(l.on){n++;st+=l.stops.length;pk+=l.pkt;cap+=c;if(!l.dc)miss++;}});
    const sv=n>FREEV||n>FREED;
    $('#auSum').innerHTML='<b>'+n+'</b> trip'+(n===1?'':'s')+' · <b>'+st+'</b> stores · <b>'+n0(pk)+'</b> PKT · avg fill <b>'+(cap?Math.round(pk*100/cap):0)+'%</b><br><span class="'+(sv&&n?'red':'')+'">Needs '+n+' vehicle'+(n===1?'':'s')+' and driver'+(n===1?'':'s')+' · free now: '+FREEV+' / '+FREED+(sv&&n?' (you can assign later)':'')+'</span>';
    const g=$('#auGo');g.disabled=!n||miss>0;g.textContent=n?'Create '+n+' trip'+(n>1?'s':''):'Create trips';
  }
  async function create(){
    const loads=plan.loads.map((l,i)=>({l,i})).filter(x=>x.l.on).map(x=>({joint:!!x.l.joint,stops:x.l.stops.map(s=>({parts:(s.parts||[{gkey:s.gkey,pkts:s.pkts}]).map(p=>({gkey:p.gkey,pkts:p.pkts}))})),dc_id:x.l.dc?+x.l.dc.id:0,vehicle_type:x.l.vsel||x.l.vehicle}));
    if(!loads.length)return; const g=$('#auGo');g.disabled=true;g.textContent='Creating…';
    try{await post({action:'create_prd_plan',loads:JSON.stringify(loads)});location.href='PLANNING.php?tab=trips&st=Planned';}
    catch(e){alert('Trips were not created: '+e.message);upd();}
  }
  function csv(){
    if(!plan)return; if(tab!=='seq'){tab='seq';render();}
    const q=v=>'"'+String(v).replace(/"/g,'""')+'"', txt=rows.map(r=>r.map(q).join(',')).join('\r\n');
    const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob(['﻿'+txt],{type:'text/csv'})); a.download='auto-trips-'+new Date().toISOString().slice(0,10)+'.csv'; a.click();
  }
  function setTab(t){tab=t;render();}
  $('#auForm').addEventListener('change',pre); $('#auForm').addEventListener('input',e=>{if(e.target.type==='number')pre();});
  const dlg=$('#auDlg'); const open=()=>{pre();dlg.showModal();}, close=()=>dlg.close();
  window.au={fit:fitCols,closeRes,cols:resetCols,run,reset,inc,upd,create,csv,tab:setTab,open,close};
  pre();
})();
</script>
