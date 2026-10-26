<?php
// Dispatch desk: the PRD Pending screen. One row per store (what is waiting, how long, what the system suggests), tick stores or a whole
// cluster, see right away how they club into trips, create the trips. The full Oracle-style matrix stays under "Full matrix".
// Included from pages/PLANNING.php. Needs $d, e(), csrf().
require_once TMS_ROOT . '/app/services/prd_planner.php';
require_once TMS_ROOT . '/app/services/oracle_sync.php';
$__lastSyncId = (int)$d->query("SELECT COALESCE(MAX(id),0) FROM tms_sync_log WHERE kind='prd'")->fetchColumn();
$__stamp = (int)($_GET['syncing'] ?? 0); $__syncing = tms_sync_running($d);
if (!$__syncing && $__stamp > 0 && time() - $__stamp < 180) { $__fin = $d->prepare("SELECT 1 FROM tms_sync_log WHERE kind='prd' AND created_at >= to_timestamp(?) LIMIT 1"); $__fin->execute([$__stamp]); $__syncing = !$__fin->fetchColumn(); }
$vo = prd_view_opts($_GET); if ($vo['wh'] === 'joint') $vo = ['mode' => 'separate', 'site' => null, 'wh' => 'all'];
$sug = ($_GET['sug'] ?? '') === 'separate' ? 'separate' : 'joint';
$M = prd_matrix($d, ['site' => $vo['site'], 'sug' => $sug]);
$S = $M['sites']; $R = $M['rows']; $cfg = $M['cfg']; $nSites = count($S);
$oc = tms_oracle_cfg(); $allSites = [];
foreach ($oc['src_site_codes'] as $sc) $allSites[(string)$sc] = (string)$sc;
foreach ($S as $sc => $l) $allSites[$sc] = $l;
$os = tms_oracle_status($d, 'prd');
$fmtT = fn($t) => $t ? date('d M, H:i', strtotime((string)$t)) : '';
$syncBtn = ['no_oci8' => 'Set up', 'not_configured' => 'Connect', 'never' => 'Refresh', 'ok' => 'Refresh', 'failed' => 'Retry refresh'][$os['state']];
$syncLine = ['no_oci8' => 'OCI8 extension not enabled', 'not_configured' => 'Source login not set', 'never' => 'Not refreshed yet',
  'ok' => 'Refreshed ' . $fmtT($os['last']['created_at'] ?? null), 'failed' => 'Refresh failed ' . $fmtT($os['last']['created_at'] ?? null)][$os['state']];
$L = fn($v) => $v >= 10000000 ? number_format($v / 10000000, 2) . ' Cr' : ($v >= 100000 ? number_format($v / 100000, 2) . ' L' : number_format((float)$v, 0));
$q = fn(array $over) => 'PLANNING.php?' . http_build_query(array_merge(['tab' => 'prd_pending', 'wh' => $vo['wh'], 'sug' => $sug], $over));
$token = csrf(); $dueDays = (int)$cfg['due_days'];
$__ora = prd_oracle_totals($d, $S); $oracleTot = $__ora['tot']['all']['pkt'] ?? 0; // what Oracle / the Excel report shows as pending (incl. PKT already on trips / in plans)
$oraOf = fn(string $name): int => (int)($__ora['rows'][strtolower(trim($name))]['all']['pkt'] ?? 0);

// Vehicles / drivers that are free right now (not on an active trip): the plan shows whether there are enough
$freeVeh = (int)$d->query("SELECT COUNT(*) FROM vehicles WHERE active IS TRUE AND id NOT IN (SELECT vehicle_id FROM trips WHERE vehicle_id IS NOT NULL AND status NOT IN ('Delivered','Cancelled'))")->fetchColumn();
$freeDrv = (int)$d->query("SELECT COUNT(*) FROM drivers WHERE active IS TRUE AND id NOT IN (SELECT driver_id FROM trips WHERE driver_id IS NOT NULL AND status NOT IN ('Delivered','Cancelled'))")->fetchColumn();
// Work-queue bands: must (waiting too long) > today (store's dispatch day is today) > ready (can go) > wait (not enough load yet) > blocked (hold / not mapped / closed)
$seg = ['must' => ['n' => 0, 'pkt' => 0], 'today' => ['n' => 0, 'pkt' => 0], 'ready' => ['n' => 0, 'pkt' => 0], 'wait' => ['n' => 0, 'pkt' => 0], 'blocked' => ['n' => 0, 'pkt' => 0]];
$rows = []; $states = []; $clusters = []; $ready = 0; $overdue = 0; $leftPkt = 0;
foreach ($R as $id => $r) {
  $chip = $r['chips'] ? reset($r['chips']) : null; $type = $chip['type'] ?? '';
  $isReady = !$r['off'] && in_array($type, ['NOW', 'FTL', 'PTL', 'CLUB'], true);
  $isDue = $r['due'] && !$r['off'];
  $dd = $r['store']['dispatch_days'] ?? null; if (is_array($dd)) $dd = implode(',', $dd); $dd = trim((string)$dd, '{}" ');
  $dday = prd_next_dispatch_day($dd); $isToday = $dday['today'] && $dd !== '' && count(array_filter(explode(',', $dd))) < 7;
  $band = $r['off'] ? 'blocked' : ($isDue ? 'must' : ($isReady ? ($isToday ? 'today' : 'ready') : ($type === 'HOLD' ? 'wait' : 'blocked')));
  $prio = ['must' => 1000 + (int)($r['wait'] ?? 0), 'today' => 800, 'ready' => 600, 'wait' => 300, 'blocked' => 0][$band] + min(99, (int)((int)$r['pkt'] / 10));
  $seg[$band]['n']++; $seg[$band]['pkt'] += (int)$r['pkt'];
  $sitePkt = []; foreach ($S as $sc => $_) $sitePkt[$sc] = (int)(($r['by'][$sc]['apps_pkt'] ?? 0) + ($r['by'][$sc]['gm_pkt'] ?? 0));
  $cl = trim((string)$r['cluster']); if ($cl === '-') $cl = '';
  if ($r['state'] !== '') $states[$r['state']] = 1; if ($cl !== '') $clusters[$cl] = ($clusters[$cl] ?? ['n' => 0, 'pkt' => 0, 'due' => 0]);
  if ($cl !== '' && !$r['off']) { $clusters[$cl]['n']++; $clusters[$cl]['pkt'] += (int)$r['pkt']; $clusters[$cl]['due'] += $isDue ? 1 : 0; }
  if ($isReady) $ready++; if ($isDue) $overdue++; if (!$r['off']) $leftPkt += (int)$r['pkt'];
  $rows[$id] = ['r' => $r, 'chip' => $chip, 'type' => $type, 'ready' => $isReady, 'due' => $isDue, 'site' => $sitePkt, 'cl' => $cl, 'band' => $band, 'prio' => $prio, 'days' => $dd, 'today' => $isToday, 'next' => $dday['next']];
}
ksort($states); ksort($clusters);
uasort($rows, fn($a, $b) => $b['prio'] <=> $a['prio']);
$firstSeg = $seg['must']['n'] ? 'must' : ($seg['today']['n'] ? 'today' : ($seg['ready']['n'] ? 'ready' : 'all'));
$typeLabel = ['NOW' => 'Dispatch now', 'FTL' => 'Full load', 'PTL' => 'Part load', 'CLUB' => 'Club', 'HOLD' => 'Wait', 'HELD' => 'On hold', 'UNMAPPED' => 'Not in store master', 'CLOSED' => 'Closed'];
$js = []; foreach ($rows as $id => $x) $js[$id] = ['keys' => $x['r']['keys'], 'pkt' => (int)$x['r']['pkt'], 'val' => round($x['r']['val'], 2), 'name' => $x['r']['name'], 'cl' => $x['cl'], 'ready' => $x['ready'], 'due' => $x['due'], 'band' => $x['band'], 'wait' => $x['r']['wait'] !== null ? (int)$x['r']['wait'] : null, 'code' => $x['r']['store']['code'] ?? $x['r']['name'], 'st' => (string)$x['r']['state'], 'sp' => $x['site'], 'sv' => array_map(fn($sc) => round((float)($x['r']['by'][$sc]['val'] ?? 0), 2), array_combine(array_keys($x['site']), array_keys($x['site']))), 'sg' => $typeLabel[$x['type']] ?? 'No suggestion'];
?>
<link rel="stylesheet" href="<?= e(asset('assets/css/desk.css')) ?>">
<div class="dk" id="dk">
<?php if ($__syncing): ?>
<div class="dk-sync" id="syncBanner" role="status"><span class="spin" id="syncSpin"></span><span><b>Refreshing…</b> <span id="syncTxt">The latest pending data is being loaded. This page reloads by itself when it is done (about a minute).</span></span></div>
<script>(function(){
  var last=<?= (int)$__lastSyncId ?>, t0=Date.now(), idle=0, b=document.getElementById('syncBanner'), tx=document.getElementById('syncTxt'), sp=document.getElementById('syncSpin');
  function fail(m){ b.classList.add('bad'); sp.style.display='none'; tx.textContent=m; }
  function poll(){
    fetch('PLANNING.php?tab=prd_pending&sync_status=1',{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();}).then(function(s){
      if(s.running){ idle=0; }
      else if(s.last_id>last){ if(s.last_ok){ location.replace('PLANNING.php?tab=prd_pending'); return; } fail('Refresh failed: '+s.last_message+' (the page shows the last good data).'); return; }
      else if(++idle>8){ fail('The refresh did not start. The page shows the last good data; click Refresh again.'); return; }
      if(Date.now()-t0>600000){ fail('The sync is taking too long. The page shows the last good data.'); return; }
      setTimeout(poll,3000);
    }).catch(function(){ setTimeout(poll,5000); });
  }
  setTimeout(poll,3000);
})();</script>
<?php endif; ?>
<?php if (!empty($GLOBALS['prd_truncated'])): ?><div class="alert alert-err" role="alert"><b>List is incomplete:</b> only the first <?= number_format((int)$GLOBALS['prd_truncated']) ?> pending rows were loaded. Raise <code>'max_rows'</code> in config/prd_planning.php.</div><?php endif; ?>

<div class="dk-head">
  <div><h2>Dispatch desk</h2></div>
  <div class="dk-sync-box">
    <span class="st <?= $os['state'] === 'ok' ? 'ok' : (in_array($os['state'], ['failed', 'no_oci8', 'not_configured'], true) ? 'bad' : '') ?>" title="<?= e($os['last']['message'] ?? '') ?>"><i></i><?= e($syncLine) ?></span>
    <?php if (in_array($os['state'], ['no_oci8', 'not_configured'], true)): ?><a class="dk-btn" href="../SETUP_ORACLE.txt" target="_blank">How to connect</a>
    <?php elseif (can('plan')): ?><form method="post" onsubmit="setTimeout(function(){window.showAppBusy&&showAppBusy('Starting the refresh','One moment…')},0)"><input type="hidden" name="action" value="sync_prd_oracle"><button class="dk-btn <?= $os['state'] === 'failed' ? 'bad' : 'pri' ?>"><?= e($syncBtn) ?></button></form><?php endif; ?>
    <a class="dk-btn" href="<?= e($q(['export' => 'prd_matrix'])) ?>" data-no-busy>Export</a>
    <a class="dk-btn" href="PLANNING.php?tab=matrix" title="The Oracle-style table: APPS / GM split, hold, notes, challans">Full matrix</a>
  </div>
</div>
<?php if ($os['state'] === 'failed' && !empty($os['last']['message'])): ?><div class="alert alert-err"><b>Oracle:</b> <?= e($os['last']['message']) ?></div><?php endif; ?>

<?php $inReview = (int)$d->query("SELECT COUNT(*) FROM tms_plan_lines WHERE status='Pending'")->fetchColumn();
if ($inReview && can('plan')): ?><div class="alert" style="background:#eff4ff;border:1px solid #c7d7fe;color:#1e3a8a"><b><?= $inReview ?> store(s)</b> from an earlier plan are waiting for their trips and are not in this list. <a href="PLANNING.php?tab=review">Review &amp; create their trips</a> or send them back from there.</div><?php endif; ?>
<div class="dk-line"><span><b><?= number_format($oracleTot) ?></b> PKT pending in Oracle (same as the Excel)</span><?php $onTripSum = 0; foreach ($rows as $__x) $onTripSum += max(0, $oraOf((string)$__x['r']['name']) - (int)$__x['r']['pkt']); ?><span title="PKT whose challans are already on a trip (Pending / Assigned / … / Delivered, not Cancelled) or in a plan: they are not offered again"><b><?= number_format($onTripSum) ?></b> PKT already on trips</span><span title="PKT of stores that are on hold, closed or not in the store master"><b><?= number_format(max(0, $oracleTot - $onTripSum - $leftPkt)) ?></b> PKT blocked</span><span><b><?= number_format($leftPkt) ?></b> PKT left to dispatch</span><span><b><?= count($rows) ?></b> stores</span>
  <span class="fv <?= $freeVeh ? '' : 'bad' ?>" title="Vehicles and drivers that are not on an active trip"><b><?= $freeVeh ?></b> free vehicles · <b><?= $freeDrv ?></b> free drivers</span></div>
<div class="dk-seg" id="dkSeg" role="tablist" aria-label="Work queue">
  <?php $segDef = [['must', 'Must go now', 'waiting more than ' . $dueDays . ' days', 'bad'], ['today', 'Dispatch day today', 'ready, store is served today', 'ok'], ['ready', 'Ready', 'can go, not their day', ''], ['wait', 'Wait', 'not enough load yet', ''], ['blocked', 'Blocked', 'hold / not mapped / closed', '']];
  foreach ($segDef as [$k, $t, $sub, $tone]): ?>
  <button type="button" role="tab" class="sg-card <?= $tone ?>" data-seg="<?= $k ?>" aria-selected="false"><span class="t"><?= e($t) ?></span><b><?= $seg[$k]['n'] ?></b><span class="s"><?= number_format($seg[$k]['pkt']) ?> PKT · <?= e($sub) ?></span></button>
  <?php endforeach; ?>
  <button type="button" role="tab" class="sg-card" data-seg="all" aria-selected="false"><span class="t">All stores</span><b><?= count($rows) ?></b><span class="s"><?= number_format($leftPkt) ?> PKT</span></button>
</div>

<section class="dk-an" id="dkAn" aria-label="Analysis">
  <div class="ah"><h3>Analysis</h3>
    <div class="dims" id="dkDims" role="tablist"><button type="button" data-d="cl" class="on">Cluster</button><button type="button" data-d="st">State</button><button type="button" data-d="wh">Warehouse</button><button type="button" data-d="age">Waiting days</button><button type="button" data-d="size">Store size</button><button type="button" data-d="sg">Suggestion</button></div>
    <button type="button" class="tg" id="dkAnTg" onclick="dk.anToggle()">Hide ▲</button></div>
  <div class="ab" id="dkAnBody"></div>
</section>


<div class="dk-tools">
  <span class="seg" aria-label="Warehouse"><a class="<?= $vo['wh'] === 'all' ? 'on' : '' ?>" href="<?= e($q(['wh' => 'all'])) ?>">All</a><?php foreach ($allSites as $sc => $lbl): ?><a class="<?= $vo['wh'] === (string)$sc ? 'on' : '' ?>" href="<?= e($q(['wh' => $sc])) ?>" title="<?= e($lbl) ?>"><?= e($sc) ?></a><?php endforeach; ?></span>
  <?php if ($nSites > 1): ?><span class="seg" aria-label="Vehicles"><a class="<?= $sug === 'separate' ? 'on' : '' ?>" href="<?= e($q(['sug' => 'separate'])) ?>" title="Each warehouse loads its own vehicles">Per warehouse</a><a class="<?= $sug === 'joint' ? 'on' : '' ?>" href="<?= e($q(['sug' => 'joint'])) ?>" title="One vehicle may collect from both warehouses">Joint vehicle</a></span><?php endif; ?>
  <input id="dkQ" placeholder="Search store" oninput="dk.filter()" aria-label="Search store">
  <select id="dkState" onchange="dk.filter()" aria-label="State"><option value="">All states</option><?php foreach (array_keys($states) as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select>
  <select id="dkClSel" onchange="dk.filter()" aria-label="Cluster"><option value="">All clusters</option><?php foreach (array_keys($clusters) as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select>
  <span class="cnt" id="dkCnt"></span>
</div>

<div class="dk-grid nopl">
  <section class="dk-list" aria-label="Stores">
  <?php if (!$rows): ?><div class="dk-empty"><b>No pending challans.</b><br><?= in_array($os['state'], ['no_oci8', 'not_configured'], true) ? 'Connect Oracle first.' : 'Click “' . e($syncBtn) . '”. Challans already on a trip or in a review are not shown.' ?></div>
  <?php else: ?>
  <table id="dkT"><thead><tr>
    <th class="ck"><input type="checkbox" aria-label="Tick all shown" onclick="dk.all(this)"></th>
    <th data-k="name" class="s">Store</th><th data-k="cl" class="s">Cluster</th><th class="n" title="PKT as in Oracle / Excel for this store (includes PKT already on a trip)">Oracle PKT</th><th data-k="pkt" class="n s" title="PKT still available to dispatch (Oracle PKT minus PKT already on trips)">To dispatch</th><th class="n">Value</th>
    <th data-k="wait" class="n s">Waiting</th><th>Dispatch days</th><th>Suggestion</th></tr></thead><tbody>
  <?php foreach ($rows as $id => $x): $r = $x['r']; $chip = $x['chip']; $typ = $x['type'];
    $store = $r['store']; $cls = ($r['off'] ? ' off' : '') . ($x['due'] ? ' due' : ''); ?>
    <tr class="<?= trim($cls) ?>" data-id="<?= e($id) ?>" data-q="<?= e(strtolower($r['name'] . ' ' . ($store['name'] ?? '') . ' ' . ($store['city'] ?? ''))) ?>" data-state="<?= e($r['state']) ?>" data-cl="<?= e($x['cl']) ?>" data-pkt="<?= (int)$r['pkt'] ?>" data-wait="<?= (int)($r['wait'] ?? -1) ?>" data-name="<?= e(strtolower($r['name'])) ?>" data-band="<?= $x['band'] ?>" data-prio="<?= (int)$x['prio'] ?>" data-due="<?= $x['due'] ? 1 : 0 ?>">
      <td class="ck"><input type="checkbox" class="dk-ck" value="<?= e($id) ?>" aria-label="Select <?= e($r['name']) ?>" <?= $r['off'] ? 'disabled' : '' ?>></td>
      <td class="nm"><b><?= e($store['code'] ?? $r['name']) ?></b> <span class="sub"><?= e($store ? $store['name'] . ($store['city'] ? ', ' . $store['city'] : '') : 'not in store master') ?></span>
        <span class="tags"><?= $r['state'] !== '' ? '<i>' . e($r['state']) . '</i>' : '' ?><?php if ($nSites > 1): foreach ($x['site'] as $sc => $n) if ($n) echo '<i title="PKT from warehouse ' . e($sc) . '">' . e($sc) . ': ' . number_format($n) . '</i>'; endif; ?></span></td>
      <td><?= $x['cl'] !== '' ? e($x['cl']) : '<span class="z">–</span>' ?></td>
      <?php $oraPkt = $oraOf((string)$r['name']); $onTrip = max(0, $oraPkt - (int)$r['pkt']); ?>
      <td class="n" title="<?= $onTrip ? e($onTrip . ' PKT of this store are already on a trip') : '' ?>"><?= $oraPkt ? number_format($oraPkt) : '<span class="z">–</span>' ?><?= $onTrip ? ' <small class="ot">−' . number_format($onTrip) . ' on trip</small>' : '' ?></td>
      <td class="n"><b><?= number_format((int)$r['pkt']) ?></b></td>
      <td class="n"><?= $L($r['val']) ?></td>
      <td class="n wt<?= $x['due'] ? ' late' : '' ?>" title="<?= e($r['last_dispatch'] ? 'Last dispatch ' . date('d M Y', strtotime((string)$r['last_dispatch'])) : 'No dispatch record') ?>"><?= $r['wait'] !== null ? (int)$r['wait'] . ' d' : '<span class="z">–</span>' ?></td>
      <td class="dd"><?= $x['days'] !== '' ? e(str_replace(',', ', ', $x['days'])) : '<span class="z">any day</span>' ?><?= $x['today'] ? ' <em class="td">today</em>' : ($x['next'] ? '<span class="why">next ' . e($x['next']) . '</span>' : '') ?></td>
      <td class="sg"><?php if ($chip): ?><span class="badge b-<?= e($typ) ?>" title="<?= e($chip['remark'] . ($chip['sub'] ? ' · ' . $chip['sub'] : '')) ?>"><?= e($typeLabel[$typ] ?? $chip['label']) ?></span><?= $chip['veh'] ? ' <small>' . e($chip['veh']) . '</small>' : '' ?><?php if (in_array($typ, ['CLUB', 'NOW', 'HOLD'], true) && $chip['remark']): ?><span class="why"><?= e($chip['remark']) ?></span><?php endif; ?><?php endif; ?></td>
    </tr>
  <?php endforeach; ?></tbody></table>
  <?php endif; ?>
  </section>

  <aside class="dk-plan" id="dkPlan" aria-label="Dispatch plan">
    <div class="ph"><h3>Dispatch plan</h3><span id="dkSel">0 stores ticked</span></div>
    <div class="pquick">
      <button type="button" id="dkClr" onclick="dk.clear()" hidden>Clear</button>
    </div>
    <div class="pb" id="dkPb"><div class="pe">Tick stores, or a whole cluster above, and the system shows here which stores travel together, how full each vehicle is and how far it goes.</div></div>
    <div class="pf" id="dkPf" hidden><div class="sum" id="dkSum"></div><button type="button" class="dk-btn pri big" id="dkGo" onclick="dk.create()">Create trips</button></div>
  </aside>
</div>
</div>

<?php if (!can('plan')): ?><style>.dk-plan,.dk-cl,td.ck input,th.ck input{display:none!important}.dk-grid{grid-template-columns:1fr}</style><?php endif; ?>
<script>
(function(){
  const D=<?= json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const TOKEN=<?= json_encode($token) ?>, MODE=<?= json_encode($sug) ?>, MINFILL=<?= (int)$cfg['min_fill_pct'] ?>;
  const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const L=v=>'₹'+(v>=1e7?(v/1e7).toFixed(2)+' Cr':v>=1e5?(v/1e5).toFixed(2)+' L':Math.round(v).toLocaleString('en-IN'));
  const rows=()=>$$('#dkT tbody tr[data-id]'), picked=()=>$$('.dk-ck:checked').map(c=>c.value);
  const FREEV=<?= (int)$freeVeh ?>, FREED=<?= (int)$freeDrv ?>;
  let plan=null,tok=0,timer=null,sortK=null,sortD=1,seg=<?= json_encode($firstSeg) ?>;
  async function post(data){
    const fd=new FormData();fd.append('csrf',TOKEN);Object.entries(data).forEach(([k,v])=>Array.isArray(v)?v.forEach(x=>fd.append(k+'[]',x)):fd.append(k,v));
    const r=await fetch('PLANNING.php?tab=prd_pending',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    let j;try{j=await r.json();}catch(e){throw new Error('Unexpected server response (HTTP '+r.status+').');}
    if(!j.ok)throw new Error(j.error||'Request failed');return j;
  }
  function filter(){
    const q=($('#dkQ').value||'').toLowerCase().trim(),st=$('#dkState').value,cl=$('#dkClSel').value; let n=0;
    rows().forEach(r=>{const ok=(seg==='all'||r.dataset.band===seg)&&(!q||r.dataset.q.includes(q))&&(!st||r.dataset.state===st)&&(!cl||r.dataset.cl===cl); r.hidden=!ok; if(ok)n++;});
    $('#dkCnt').textContent=n+' store'+(n===1?'':'s')+' in this list';
    $$('#dkSeg .sg-card').forEach(b=>{const on=b.dataset.seg===seg;b.classList.toggle('on',on);b.setAttribute('aria-selected',on?'true':'false');});
  }
  function setSeg(s){seg=s;filter();}
  function sortBy(k){
    sortD=sortK===k?-sortD:(k==='name'||k==='cl'?1:-1); sortK=k; const tb=$('#dkT tbody');
    $$('#dkT th[data-k]').forEach(h=>h.removeAttribute('aria-sort')); $('#dkT th[data-k="'+k+'"]').setAttribute('aria-sort',sortD>0?'ascending':'descending');
    rows().sort((a,b)=>{const x=a.dataset[k]||'',y=b.dataset[k]||'';return (k==='name'||k==='cl'?x.localeCompare(y):(+x)-(+y))*sortD;}).forEach(r=>tb.appendChild(r));
  }
  function sel(){
    const ids=picked(); rows().forEach(r=>r.classList.toggle('sel',ids.includes(r.dataset.id)));
    const pkt=ids.reduce((s,i)=>s+D[i].pkt,0);
    $('#dkSel').textContent=ids.length+' store'+(ids.length===1?'':'s')+' · '+pkt.toLocaleString('en-IN')+' PKT'; $('#dkClr').hidden=!ids.length; $('.dk-grid').classList.toggle('nopl',!ids.length);
    $$('#dkCl .cl').forEach(b=>{const l=rows().filter(r=>r.dataset.cl===b.dataset.c).map(r=>r.querySelector('.dk-ck')).filter(k=>k&&!k.disabled),on=l.filter(k=>k.checked).length;b.classList.toggle('on',l.length>0&&on===l.length);b.classList.toggle('part',on>0&&on<l.length);});
    clearTimeout(timer); tok++;
    if(!ids.length){plan=null;$('#dkPb').innerHTML='<div class="pe">Tick stores / a whole cluster, and the system shows here which stores travel together, how full each vehicle is and how far it goes.</div>';$('#dkPf').hidden=true;return;}
    $('#dkPb').classList.add('busy'); timer=setTimeout(()=>run(ids),400);
  }
  async function run(ids){
    const t=tok, keys=ids.flatMap(i=>D[i].keys);
    try{const j=await post({action:'prd_preview',keys,mode:MODE,balance:1}); if(t!==tok)return; plan=j; plan.loads.forEach(l=>l.on=true); render();}
    catch(e){if(t!==tok)return; plan=null; $('#dkPb').classList.remove('busy'); $('#dkPb').innerHTML='<div class="perr">'+esc(e.message)+'</div>'; $('#dkPf').hidden=true;}
  }
  const capOf=(t)=>{const c=plan.classes.find(x=>x.type===t);return c?c.capacity:0;};
  function vehOpts(cur){const o=plan.classes.map(c=>c.type);if(cur&&!o.includes(cur))o.push(cur);return o.map(t=>'<option'+(t===cur?' selected':'')+'>'+esc(t)+'</option>').join('');}
  function render(){
    $('#dkPb').classList.remove('busy'); let h='';
    if(plan.excluded.length)h+='<div class="pexcl"><b>'+plan.excluded.length+' store'+(plan.excluded.length>1?'s':'')+' cannot be planned</b><ul>'+plan.excluded.map(x=>'<li>'+esc(x.name)+': '+esc(x.reason)+'</li>').join('')+'</ul></div>';
    if(!plan.loads.length)h+='<div class="perr">None of the ticked stores can be planned.</div>';
    plan.loads.forEach((l,i)=>{
      const dc=l.dc?esc(l.dc.code):'<span class="red">no DC</span>', hops=l.stops.slice(1).map(s=>s.km).filter(x=>x!=null), far=hops.length?Math.max(...hops):null;
      const cl=[...new Set(l.stops.map(s=>s.cluster).filter(c=>c&&c!=='-'))];
      const path='<ol class="path"><li class="dc"><b>'+dc+'</b><small>'+esc(l.source)+'</small></li>'+l.stops.map(s=>'<li><span class="hop">'+(s.km!=null?s.km+' km':'km n/a')+'</span><b>'+esc(s.code)+'</b><small>'+s.pkt+' PKT'+(s.cluster&&s.cluster!=='-'?' · '+esc(s.cluster):'')+(s.part?' · part '+esc(s.part):'')+(s.due?' · <span class="red">'+(s.wait||'')+'d late</span>':'')+'</small></li>').join('')+'</ol>';
      const why=l.stops.length>1?'<div class="why ok">Travel together: '+l.stops.map(s=>esc(s.code)).join(' + ')+(far!=null?' · at most '+far+' km between stores':'')+(cl.length>1?' · clusters '+cl.map(esc).join(' + '):(cl.length?' · cluster '+esc(cl[0]):''))+'</div>':'<div class="why">Alone: '+esc(l.alone||'')+'</div>';
      h+='<div class="trip" data-i="'+i+'"><div class="th"><label><input type="checkbox" checked onchange="dk.inc('+i+',this.checked)"> <b>Trip '+(i+1)+'</b></label><span><b>'+l.pkt+'</b> PKT · '+L(l.value)+(l.route_km!=null?' · ~'+l.route_km+' km':'')+'</span></div>'+path+
        '<div class="fill"><div class="m"><i id="dkm'+i+'"></i></div><span id="dkf'+i+'"></span><select id="dkv'+i+'" onchange="dk.upd()" aria-label="Vehicle">'+vehOpts(l.vehicle)+'</select></div>'+why+(l.warnings||[]).map(w=>'<div class="why warn">⚠ '+esc(w)+'</div>').join('')+(l.dc?'':'<div class="why warn">Site is not linked to a Center DC (<a href="MASTER_OPS.php?tab=warehouses">Warehouses</a>); this trip cannot be created.</div>')+'</div>';
    });
    $('#dkPb').innerHTML=h; upd();
  }
  function inc(i,on){plan.loads[i].on=on;$('.trip[data-i="'+i+'"]').classList.toggle('skip',!on);upd();}
  function upd(){
    let n=0,st=0,pk=0,cap=0,km=0,miss=0,club=0;
    plan.loads.forEach((l,i)=>{const c=capOf($('#dkv'+i).value),f=c?Math.round(l.pkt*100/c):0,m=$('#dkm'+i);$('#dkf'+i).textContent=f+'% of '+c;m.style.width=Math.min(100,f)+'%';m.className=f>100?'over':(f<MINFILL?'low':'');
      if(l.on){n++;st+=l.stops.length;pk+=l.pkt;cap+=c;km+=l.route_km||0;if(!l.dc)miss++;if(l.stops.length>1)club++;}});
    const shortV=n>FREEV, shortD=n>FREED;
    $('#dkSum').innerHTML='<b>'+n+'</b> trip'+(n===1?'':'s')+' · <b>'+st+'</b> stores · <b>'+pk.toLocaleString('en-IN')+'</b> PKT<br>avg fill <b>'+(cap?Math.round(pk*100/cap):0)+'%</b> · ~<b>'+km.toLocaleString('en-IN')+'</b> km · '+club+' clubbed'+
      '<br><span class="'+((shortV||shortD)&&n?'red':'')+'">Needs '+n+' vehicle'+(n===1?'':'s')+' and '+n+' driver'+(n===1?'':'s')+' · free now: '+FREEV+' / '+FREED+((shortV||shortD)&&n?' — you can still create the trips and assign later':'')+'</span>';
    const g=$('#dkGo');g.disabled=!n||miss>0;g.textContent=n?'Create '+n+' trip'+(n>1?'s':''):'Create trips';$('#dkPf').hidden=false;
  }
  async function create(){
    const loads=plan.loads.map((l,i)=>({l,i})).filter(x=>x.l.on).map(x=>({joint:!!x.l.joint,stops:x.l.stops.map(s=>({parts:(s.parts||[{gkey:s.gkey,pkts:s.pkts}]).map(p=>({gkey:p.gkey,pkts:p.pkts}))})),dc_id:x.l.dc?+x.l.dc.id:0,vehicle_type:$('#dkv'+x.i).value}));
    if(!loads.length)return; const g=$('#dkGo');g.disabled=true;g.textContent='Creating…';
    try{const j=await post({action:'create_prd_plan',loads:JSON.stringify(loads)});location.href=j.redirect.indexOf('view=')>0?'PLANNING.php?tab=trips&st=Planned':j.redirect;}
    catch(e){$('#dkPb').insertAdjacentHTML('afterbegin','<div class="perr"><b>Trips were not created.</b> '+esc(e.message)+'</div>');$('#dkPb').scrollTop=0;upd();}
  }
  const fire=()=>sel();
  function all(x){$$('.dk-ck').forEach(c=>{if(!c.closest('tr').hidden&&!c.disabled)c.checked=x.checked});fire();}
  function clear(){$$('.dk-ck').forEach(c=>c.checked=false);fire();}
  function pickShown(){rows().forEach(r=>{const c=r.querySelector('.dk-ck');if(c&&!c.disabled&&!r.hidden&&['must','today','ready'].includes(r.dataset.band))c.checked=true});fire();}
  function pickCluster(c){const l=rows().filter(r=>r.dataset.cl===c).map(r=>r.querySelector('.dk-ck')).filter(k=>k&&!k.disabled),allOn=l.length&&l.every(k=>k.checked);l.forEach(k=>k.checked=!allOn);fire();}
  $$('#dkT th[data-k]').forEach(h=>{h.tabIndex=0;h.addEventListener('click',()=>sortBy(h.dataset.k));h.addEventListener('keydown',e=>{if(e.key==='Enter')sortBy(h.dataset.k)});});
  $('#dkT')?.addEventListener('change',e=>{if(e.target.classList.contains('dk-ck'))fire();});
  $('#dkT')?.addEventListener('click',e=>{const tr=e.target.closest('tbody tr[data-id]');if(!tr||e.target.closest('input,button,a'))return;const c=tr.querySelector('.dk-ck');if(c&&!c.disabled){c.checked=!c.checked;fire();}});
  $('#dkCl')?.addEventListener('click',e=>{const b=e.target.closest('.cl');if(b)pickCluster(b.dataset.c);});
  $('#dkSeg')?.addEventListener('click',e=>{const b=e.target.closest('.sg-card');if(b)setSeg(b.dataset.seg);});
  // ---- analysis: the same stores grouped in different ways ----
  let anDim='cl', anOpen=true;
  const AGE=[['0–1 days',0,1],['2 days',2,2],['3–5 days',3,5],['6–10 days',6,10],['11+ days',11,1e9]];
  const SIZE=[['Below 100 PKT',0,99],['100–299 PKT',100,299],['300–399 PKT (cannot go alone or combined)',300,399],['400+ PKT (own trip)',400,1e9]];
  function groupsFor(dim){
    const g={}, add=(k,id,pkt,val)=>{const o=g[k]=g[k]||{n:0,pkt:0,val:0,due:0,ready:0,w:0,wn:0,ord:0};const d=D[id];o.n++;o.pkt+=pkt;o.val+=val;if(d.due)o.due++;if(d.band==='must'||d.band==='today'||d.band==='ready')o.ready++;if(d.wait!==null){o.w+=d.wait;o.wn++;}};
    Object.keys(D).forEach(id=>{const d=D[id]; if(d.band==='blocked')return;
      if(dim==='cl')add(d.cl||'(no cluster)',id,d.pkt,d.val);
      else if(dim==='st')add(d.st||'(no state)',id,d.pkt,d.val);
      else if(dim==='wh')Object.keys(d.sp).forEach(sc=>{if(d.sp[sc]>0)add(sc,id,d.sp[sc],d.sv[sc]||0);});
      else if(dim==='age'){const k=d.wait===null?'No dispatch record':(AGE.find(a=>d.wait>=a[1]&&d.wait<=a[2])||AGE[4])[0];add(k,id,d.pkt,d.val);}
      else if(dim==='size'){const k=(SIZE.find(a=>d.pkt>=a[1]&&d.pkt<=a[2])||SIZE[3])[0];add(k,id,d.pkt,d.val);}
      else add(d.sg,id,d.pkt,d.val);});
    let keys=Object.keys(g);
    if(dim==='age')keys=['No dispatch record',...AGE.map(a=>a[0])].filter(k=>g[k]); else if(dim==='size')keys=SIZE.map(a=>a[0]).filter(k=>g[k]); else keys.sort((a,b)=>g[b].pkt-g[a].pkt);
    return {g,keys};
  }
  function anRender(){
    const {g,keys}=groupsFor(anDim), t={n:0,pkt:0,val:0,due:0,ready:0};
    keys.forEach(k=>{['n','pkt','val','due','ready'].forEach(f=>t[f]+=g[k][f]);});
    const head={cl:'Cluster',st:'State',wh:'Warehouse',age:'Days since last dispatch',size:'Store size',sg:'Suggestion'}[anDim], click=anDim==='cl'||anDim==='st';
    // summary tiles over every store that is not blocked + two side lists that use the free width next to the table
    const live=Object.keys(D).filter(id=>D[id].band!=='blocked').map(id=>D[id]), ws=live.filter(d=>d.wait!==null);
    const big=live.filter(d=>d.pkt>=400).length, stuck=live.filter(d=>d.pkt>=300&&d.pkt<400).length, small=live.filter(d=>d.pkt<100).length;
    const tile=(l,v,s,c)=>'<div class="kp'+(c?' '+c:'')+'"><span>'+l+'</span><b>'+v+'</b>'+(s?'<small>'+s+'</small>':'')+'</div>';
    const tiles='<div class="kps">'+tile('Stores waiting',live.length,'across '+keys.length+' '+head.toLowerCase().replace('days since last dispatch','groups'))+tile('PKT',t.pkt.toLocaleString('en-IN'),L(t.val))+tile('Overdue',t.due,'waiting too long',t.due?'red':'')+tile('Can go now',t.ready,'ready to club / dispatch','grn')+tile('Avg days waiting',ws.length?(ws.reduce((a,d)=>a+d.wait,0)/ws.length).toFixed(1):'–','since last dispatch')+tile('400+ PKT',big,'own full trip')+tile('300–399 PKT',stuck,'cannot go until 400',stuck?'amb':'')+tile('Under 100 PKT',small,'low stock, wait')+'</div>';
    const top=keys.slice().sort((a,b)=>g[b].pkt-g[a].pkt).slice(0,6), mx=top.length?g[top[0]].pkt||1:1;
    const bars='<div class="sd"><h4>Top '+head.toLowerCase().replace('days since last dispatch','groups')+' by PKT</h4>'+top.map(k=>'<div class="br"><span title="'+esc(k)+'">'+esc(k)+'</span><div><i style="width:'+Math.round(g[k].pkt*100/mx)+'%"></i></div><em>'+g[k].pkt.toLocaleString('en-IN')+'</em></div>').join('')+'</div>';
    const old=ws.slice().sort((a,b)=>b.wait-a.wait).slice(0,6);
    const wl='<div class="sd"><h4>Waiting longest</h4>'+(old.length?old.map(d=>'<div class="br"><span title="'+esc(d.name)+'">'+esc(d.code||d.name)+'</span><div><i class="o" style="width:'+Math.round(d.wait*100/(old[0].wait||1))+'%"></i></div><em>'+d.wait+'d · '+d.pkt+'</em></div>').join(''):'<p class="anote">No dispatch history yet.</p>')+'</div>';
    let h=tiles+'<div class="agrid"><div><div class="tw"><table class="atbl"><thead><tr><th>'+head+'</th><th class="n">Stores</th><th class="n">PKT</th><th class="n">Value</th><th>Share of PKT</th><th class="n">Overdue</th><th class="n">Can go now</th><th class="n">Avg days waiting</th></tr></thead><tbody>';
    h+=keys.map(k=>{const o=g[k],p=t.pkt?Math.round(o.pkt*100/t.pkt):0;return '<tr'+(click?' class="clk" data-k="'+esc(k)+'" title="Show only these stores in the list"':'')+'><td><b>'+esc(k)+'</b></td><td class="n">'+o.n+'</td><td class="n">'+o.pkt.toLocaleString('en-IN')+'</td><td class="n">'+L(o.val)+'</td><td><div class="sh"><i style="width:'+p+'%"></i></div><span>'+p+'%</span></td><td class="n'+(o.due?' red':'')+'">'+o.due+'</td><td class="n">'+o.ready+'</td><td class="n">'+(o.wn?(o.w/o.wn).toFixed(1):'–')+'</td></tr>';}).join('');
    h+='</tbody><tfoot><tr><td>Total</td><td class="n">'+t.n+'</td><td class="n">'+t.pkt.toLocaleString('en-IN')+'</td><td class="n">'+L(t.val)+'</td><td></td><td class="n">'+t.due+'</td><td class="n">'+t.ready+'</td><td></td></tr></tfoot></table></div>'+(anDim==='wh'?'<div class="anote">A store that has PKT in both warehouses is counted in each.</div>':'')+'</div><div class="aside">'+bars+wl+'</div></div>';
    $('#dkAnBody').innerHTML=h;
    $$('#dkDims button').forEach(b=>b.classList.toggle('on',b.dataset.d===anDim));
  }
  $('#dkDims')?.addEventListener('click',e=>{const b=e.target.closest('button');if(b){anDim=b.dataset.d;anRender();}});
  $('#dkAnBody')?.addEventListener('click',e=>{const r=e.target.closest('tr.clk');if(!r)return;const k=r.dataset.k,sel=anDim==='cl'?$('#dkClSel'):$('#dkState');const cur=sel.value===k?'':k;sel.value=cur;if(cur&&sel.value!==cur){sel.value='';}seg='all';filter();});
  function anToggle(){anOpen=!anOpen;$('#dkAnBody').hidden=!anOpen;$('#dkAnTg').textContent=anOpen?'Hide ▲':'Show ▼';}
  window.dk={filter,all,clear,pickShown,inc,upd,create,anToggle};
  anRender();
  filter();sel();
})();
</script>
