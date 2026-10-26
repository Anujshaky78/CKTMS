<?php
// PRD Pending · store × warehouse matrix (APPS / GM PKT and value, as in the Oracle PRD report) with vehicle suggestions.
// Included from pages/PLANNING.php. Needs $d, $dcs, e(), csrf().
require_once TMS_ROOT . '/app/services/prd_planner.php';
require_once TMS_ROOT . '/app/services/oracle_sync.php';
// The board refreshes only when someone clicks Refresh: that starts a background run and redirects here with ?syncing=<time>.
// The progress banner shows while a run is going on (or just started, with no finished run since the click).
$__lastSyncId = (int)$d->query("SELECT COALESCE(MAX(id),0) FROM tms_sync_log WHERE kind='prd'")->fetchColumn();
$__stamp = (int)($_GET['syncing'] ?? 0); $__syncing = tms_sync_running($d);
if (!$__syncing && $__stamp > 0 && time() - $__stamp < 180) { $__fin = $d->prepare("SELECT 1 FROM tms_sync_log WHERE kind='prd' AND created_at >= to_timestamp(?) LIMIT 1"); $__fin->execute([$__stamp]); $__syncing = !$__fin->fetchColumn(); }
$vo = prd_view_opts($_GET); if ($vo['wh'] === 'joint') $vo = ['mode' => 'separate', 'site' => null, 'wh' => 'all'];
$sug = ($_GET['sug'] ?? '') === 'separate' ? 'separate' : 'joint';
$M = prd_matrix($d, ['site' => $vo['site'], 'sug' => $sug]);
$S = $M['sites']; $R = $M['rows']; $T = $M['tot']; $cfg = $M['cfg'];
$oc = tms_oracle_cfg(); $allSites = [];
foreach ($oc['src_site_codes'] as $sc) $allSites[(string)$sc] = (string)$sc;
foreach ($S as $sc => $l) $allSites[$sc] = $l;
foreach ($allSites as $sc => $l) if ($l === $sc) { $q = $d->prepare("SELECT MAX(source_name) FROM prd_pending WHERE source_site_code=?"); $q->execute([$sc]); $allSites[$sc] = $q->fetchColumn() ?: $sc; }
$os = tms_oracle_status($d, 'prd');
$fmtT = fn($t) => $t ? date('d M, H:i', strtotime((string)$t)) : '';
$syncBtn = ['no_oci8' => 'Set up', 'not_configured' => 'Connect', 'never' => 'Refresh', 'ok' => 'Refresh', 'failed' => 'Retry refresh'][$os['state']];
$syncLine = ['no_oci8' => 'OCI8 extension not enabled', 'not_configured' => 'Source login not set', 'never' => 'Not refreshed yet',
  'ok' => 'Refreshed ' . $fmtT($os['last']['created_at'] ?? null), 'failed' => 'Refresh failed ' . $fmtT($os['last']['created_at'] ?? null)][$os['state']];
$L = fn($v) => $v >= 10000000 ? number_format($v / 10000000, 2) . ' Cr' : ($v >= 100000 ? number_format($v / 100000, 2) . ' L' : number_format((float)$v, 0));
$N = fn($v) => $v ? number_format((float)$v) : '<span class="z">–</span>';
$V = fn($v) => $v ? $L($v) : '<span class="z">–</span>';
$q = fn(array $over) => 'PLANNING.php?' . http_build_query(array_merge(['tab' => 'matrix', 'wh' => $vo['wh'], 'sug' => $sug], $over));
$states = []; $clusters = []; foreach ($R as $r) { if ($r['state'] !== '') $states[$r['state']] = 1; if ($r['cluster'] !== '') $clusters[$r['cluster']] = 1; } ksort($states); ksort($clusters);
$chipTypes = []; foreach ($R as $r) foreach ($r['chips'] as $c) $chipTypes[$c['type']] = $c['type'] === 'CLUB' ? 'Club load' : preg_replace('/ L\d+$/', '', $c['label']);
$js = []; foreach ($R as $id => $r) $js[$id] = ['keys' => $r['keys'], 'pkt' => $r['pkt'], 'val' => round($r['val'], 2), 'name' => $r['name'], 'sites' => array_keys($r['by'])];
$token = csrf();
$nSites = count($S);
// DISPLAYED numbers = what Oracle has Pending: every row the last sync returned, per store and site, including challans that are already on a trip.
// ($R / $js / the dock keep using only what is still left to plan; $Tplan holds those totals.)
$Tplan = $T; $__tz = ['apps_pkt' => 0, 'apps_val' => 0.0, 'gm_pkt' => 0, 'gm_val' => 0.0, 'pkt' => 0, 'val' => 0.0];
$__o = prd_oracle_totals($d, $S); $__ot = $__o['rows']; $T = $__o['tot'];
?>
<style>
.px{--ink:#101828;--sub:#5b6b80;--line:#e3e8ef;--soft:#f7f8fa;--blue:#1f4fd8;--red:#b42318;--amber:#b54708;--green:#067647}
.px-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.px-stats{display:flex;gap:6px;flex-wrap:wrap}.px-stats span{font-size:12px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px 10px;color:#344054;font-variant-numeric:tabular-nums}
.px-stats span.all{background:var(--ink);color:#fff;border-color:var(--ink)}.px-stats span.bad{background:#fef3f2;color:var(--red);border-color:#fecdca}
.px-t td.late{color:var(--red);font-weight:700}.px-t td small{color:var(--sub);font-weight:500}.px-t td.late small{color:var(--red)}
.px-t td.sg{min-width:190px;max-width:260px;white-space:normal;border-right:2px solid #e3e8ef}.px-t th.sgh{border-right:2px solid #d0d7e2}
.px-sync{display:flex;gap:8px;align-items:center}.px-sync .st{font-size:11.5px;color:var(--sub);display:flex;align-items:center;gap:6px}
.px-sync .st i{width:8px;height:8px;border-radius:50%;background:#98a2b3}.px-sync .st.ok i{background:#17b26a}.px-sync .st.bad{color:var(--red)}.px-sync .st.bad i{background:#f04438}
.btn.retry{background:#fff;color:var(--red);border:1px solid #fda29b}
/* toolbar */
.px-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px}
.px-tools .input,.px-tools .select{height:32px;min-width:0}
.px-seg{display:inline-flex;border:1px solid #d0d7e2;border-radius:8px;overflow:hidden;background:#fff}
.px-seg a{padding:6px 11px;font-size:12px;font-weight:600;color:#344054;text-decoration:none;border-left:1px solid #e3e8ef;white-space:nowrap}.px-seg a:first-child{border-left:0}
.px-seg a.on{background:var(--ink);color:#fff}
.px-tools .lbl{font-size:11.5px;color:var(--sub)}
.px-tools label.ck{font-size:12px;display:flex;gap:5px;align-items:center;color:#344054}
.px-count{font-size:11.5px;color:var(--sub);margin-left:auto}
/* matrix */
.px-wrap{border:1px solid var(--line);border-radius:12px;background:#fff;overflow:auto;max-height:calc(100vh - 330px);min-height:280px}
.px-t{border-collapse:separate;border-spacing:0;width:100%;font-size:12px}
.px-t th,.px-t td{padding:7px 9px;border-bottom:1px solid #eef1f5;white-space:nowrap}
.px-t thead th{position:sticky;background:#f2f4f7;color:#344054;font-weight:650;font-size:11px;z-index:3}
.px-t thead tr:first-child th{top:0;height:30px}.px-t thead tr:nth-child(2) th{top:30px}
.px-t th.grp{text-align:center;border-left:2px solid #d0d7e2;background:#e9edf3}
.px-t th.gs,.px-t td.gs{border-left:2px solid #e3e8ef}
.px-t th.tot,.px-t td.tot{background:#f5f8ff}.px-t th.grp.tot{background:#dfe8ff}
.px-t .n{text-align:right;font-variant-numeric:tabular-nums}
.px-t .z{color:#c3cad6}
.px-t th.s{cursor:pointer;user-select:none}.px-t th[aria-sort=descending]::after{content:" ▼";font-size:8px}.px-t th[aria-sort=ascending]::after{content:" ▲";font-size:8px}
.px-t td.ck,.px-t th.ck{position:sticky;left:0;z-index:2;background:inherit;width:30px}
.px-t td.st,.px-t th.st{position:sticky;left:30px;z-index:2;background:inherit;min-width:200px;box-shadow:1px 0 0 #e3e8ef}
.px-t thead th.ck,.px-t thead th.st{z-index:4;background:#f2f4f7}
.px-t tbody tr{background:#fff;cursor:pointer}.px-t tbody tr:hover{background:#fafcff}
.px-t tbody tr.sel{background:#eef3ff}
.px-t tbody tr.due td.ck{box-shadow:inset 3px 0 0 #f04438}
.px-t tbody tr.off{cursor:default;color:#667085}
.px-t .nm{font-weight:700;color:var(--ink);border:0;background:none;padding:0;cursor:pointer;font:inherit;font-weight:700;text-align:left}
.px-t .nm:hover{color:var(--blue);text-decoration:underline}
.px-t .meta{display:block;font-size:10.5px;color:var(--sub);font-weight:400;max-width:230px;overflow:hidden;text-overflow:ellipsis}
.px-t .wait{font-weight:700}.px-t .wait.d{color:var(--red)}
.px-t tfoot td{position:sticky;bottom:0;background:#101828;color:#fff;font-weight:700;z-index:3;border:0}
.px-t tfoot td.ck,.px-t tfoot td.st{z-index:4;background:#101828}
.chip{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;border-radius:6px;padding:2px 7px;margin:1px 4px 1px 0;color:#fff;background:#475467;white-space:nowrap}
.chip small,.px-t .chip small{font-weight:500;opacity:.9;color:inherit}.chip .src{opacity:.75;font-weight:600}
.c-NOW{background:#b42318}.c-FTL{background:#067647}.c-CLUB{background:#1849a9}.c-PTL{background:#6b4fbb}.c-HOLD{background:#fff;color:#93590c;border:1px solid #fedf89}.c-UNMAPPED{background:#fff;color:#475467;border:1px solid #d0d5dd}.c-CLOSED{background:#7a2e0e}.c-HELD{background:#fff;color:#7a2e0e;border:1px solid #f9dbaf}
.tag{display:inline-block;font-size:10px;font-weight:700;border-radius:4px;padding:1px 5px;margin-left:4px}
.tag.d{background:#fef3f2;color:var(--red)}.tag.h{background:#fffaeb;color:#93590c}.tag.m{background:#f2f4f7;color:#475467}
/* detail */
.px-det td{background:#fbfcfe!important;cursor:default;white-space:normal;padding:12px 14px 14px 44px}
.px-det .cols{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:16px}
.px-det h4{margin:0 0 6px;font-size:12px}
.px-det .rm{font-size:12px;margin-bottom:6px}.px-det .rm b{display:block}.px-det .rm span{color:var(--sub);font-size:11px}
.px-det .flags span{display:inline-block;font-size:10.5px;background:#f2f4f7;border-radius:4px;padding:2px 6px;margin:0 4px 4px 0}
.px-det .flags span.crit{background:#fef3f2;color:var(--red)}.px-det .flags span.warn{background:#fff6ed;color:#b93815}
.px-det .acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center}
.px-det .acts input{flex:1;min-width:200px;height:30px;border:1px solid #d0d5dd;border-radius:6px;padding:4px 8px;font-size:12px}
.px-det table{border-collapse:collapse;font-size:11px;width:100%}.px-det table th,.px-det table td{border-bottom:1px solid #eef1f5;padding:4px 8px 4px 0;text-align:left;position:static;background:none}
.px-det .ch{max-height:260px;overflow:auto}
/* selection analysis: cluster chips above the board, analysis panel above the dock */
.cl-chips{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 10px;align-items:center}.cl-chips .lbl{font-size:12px;color:var(--sub);font-weight:600}
.cl-chip{border:1px solid var(--line);background:#fff;border-radius:99px;padding:5px 12px;font-size:12px;cursor:pointer;display:inline-flex;gap:6px;align-items:baseline;color:#344054}
.cl-chip b{color:var(--ink)}.cl-chip small{color:var(--sub)}.cl-chip:hover{border-color:#98a2b3}.cl-chip.on{background:#eff4ff;border-color:#1d4ed8;color:#1d4ed8}.cl-chip.part{border-style:dashed;border-color:#1d4ed8}
.px-dw{position:sticky;bottom:0;z-index:6;margin-top:10px}.px-dw .px-dock{position:static;margin-top:0}
.an{background:#fff;border:1px solid var(--line);border-radius:12px 12px 0 0;box-shadow:0 -8px 24px rgba(16,24,40,.10);margin-bottom:-6px;padding-bottom:6px}
.an[hidden]{display:none}
.an-bar{display:flex;gap:14px;align-items:center;padding:9px 14px;cursor:pointer;flex-wrap:wrap}.an-bar b{font-size:13px}.an-bar .sum{font-size:12.5px;color:#344054;flex:1}.an-bar .sum i{font-style:normal;color:var(--sub)}
.an-bar .tg{font-size:12px;color:#1d4ed8;font-weight:600}.an-bar .wait{font-size:12px;color:var(--sub)}
.an-body{max-height:min(52vh,460px);overflow:auto;border-top:1px solid var(--line);padding:10px 14px}
.an-tabs{display:flex;gap:4px;margin:0 0 10px}.an-tabs button{border:1px solid var(--line);background:#fff;border-radius:8px;padding:5px 12px;font-size:12.5px;cursor:pointer;color:#475467}.an-tabs button.on{background:#101828;color:#fff;border-color:#101828}
.an-kpi{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 10px}.an-kpi div{background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:6px 12px}.an-kpi b{display:block;font-size:16px}.an-kpi span{font-size:11px;color:var(--sub)}
.an-trip{border:1px solid var(--line);border-radius:10px;padding:8px 12px;margin:0 0 8px}.an-trip.alone{background:#fffaf0}
.an-th{display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin-bottom:6px;flex-wrap:wrap}.an-th .f{font-weight:700}.an-th .f.low{color:#b54708}.an-th .f.over{color:#b42318}
.an-path{display:flex;flex-wrap:wrap;align-items:center;gap:4px 0}.an-st{background:#eff4ff;border:1px solid #c7d7fe;border-radius:8px;padding:3px 9px;font-size:12px;color:#1e3a8a;white-space:nowrap}.an-st small{color:#475467}.an-st em{font-style:normal;color:#667085;font-size:11px;margin-left:4px}
.an-hop{font-size:11px;color:var(--sub);padding:0 8px;white-space:nowrap}.an-hop::before{content:"";display:inline-block;width:12px;border-top:2px solid #cbd5e1;vertical-align:middle;margin-right:4px}
.an-why{font-size:12px;color:#93590c;margin-top:5px}.an-ok{font-size:12px;color:#067647;margin-top:5px}
.an-tbl{border-collapse:collapse;width:100%;font-size:12.5px}.an-tbl th,.an-tbl td{padding:6px 10px;border-bottom:1px solid #eef1f5;text-align:left}.an-tbl th{font-size:11.5px;color:var(--sub);font-weight:600}.an-tbl .n{text-align:right;font-variant-numeric:tabular-nums}
.an-excl{background:#fffaeb;border:1px solid #fedf89;border-radius:8px;padding:8px 12px;font-size:12.5px;margin:0 0 10px}.an-excl ul{margin:4px 0 0;padding-left:18px}
/* dock */
.px-dock{position:sticky;bottom:0;z-index:6;margin-top:10px;background:var(--ink);color:#fff;border-radius:12px;padding:10px 14px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;box-shadow:0 -8px 24px rgba(16,24,40,.12)}
.px-dock .k{font-size:10px;color:#9fb0c6}.px-dock .v{font-size:15px;font-weight:800;font-variant-numeric:tabular-nums}
.px-dock .hint{font-size:11.5px;color:#c7d2e0;flex:1;min-width:180px}
.px-dock .btn[disabled]{opacity:.45}.px-dock .clear{background:transparent;color:#c7d2e0;border:1px solid #344a66}
.px [hidden]{display:none!important}
/* drawer + dialog (review plan) */
.pb-scrim{position:fixed;inset:0;background:rgba(15,27,45,.35);z-index:9000;display:none}
.pb-drawer{position:fixed;top:0;right:0;bottom:0;width:min(620px,100%);background:#f7f8fa;z-index:9001;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .2s ease;box-shadow:-20px 0 50px rgba(15,27,45,.2)}
.pb-open .pb-scrim{display:block}.pb-open .pb-drawer{transform:none}
.pb-dh{background:#fff;border-bottom:1px solid var(--line);padding:14px 18px;display:flex;justify-content:space-between;gap:10px}
.pb-dh h3{margin:0;font-size:16px}.pb-dh p{margin:3px 0 0;font-size:11.5px;color:var(--sub)}.pb-dh .x{border:0;background:none;font-size:22px;cursor:pointer;color:var(--sub)}
.pb-mode{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:10px 18px;background:#fff;border-bottom:1px solid var(--line)}
.pb-mode label{display:flex;gap:8px;align-items:flex-start;border:1px solid var(--line);border-radius:8px;padding:8px 10px;cursor:pointer;font-size:12px}
.pb-mode label:has(input:checked){border-color:var(--blue);background:#f5f8ff}.pb-mode small{display:block;color:var(--sub);font-size:10.5px}
.pb-db{flex:1;overflow:auto;padding:14px 18px}
.pb-df{background:#fff;border-top:1px solid var(--line);padding:12px 18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}.pb-df .sum{flex:1;font-size:12px;color:var(--sub)}.pb-df .sum b{color:var(--ink)}
.pb-trip{background:#fff;border:1px solid var(--line);border-radius:10px;margin-bottom:10px;overflow:hidden}.pb-trip.skip{opacity:.5}
.pb-trip-h{display:flex;gap:10px;align-items:center;padding:10px 12px;border-bottom:1px solid #eef1f5}
.pb-trip-h label{display:flex;gap:8px;align-items:center;font-weight:700;font-size:13px;flex:1;cursor:pointer}.pb-trip-h .tot{font-size:11.5px;color:var(--sub);text-align:right}
.pb-pick{padding:8px 12px 0;font-size:11.5px;color:#1849a9}
.pb-trip-c{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:10px 12px 4px}.pb-trip-c label{font-size:10.5px;color:var(--sub);display:block;margin-bottom:3px}.pb-trip-c .select{width:100%;height:32px}.pb-dc{height:32px;display:flex;align-items:center;font-size:12.5px;font-weight:650}
.pb-trip-fill{padding:4px 12px 8px;font-size:11px;color:var(--sub);display:flex;gap:8px;align-items:center}
.pb-trip-fill .m{flex:1;height:6px;background:#e9eef5;border-radius:3px;overflow:hidden}.pb-trip-fill .m i{display:block;height:100%;background:#17b26a}.pb-trip-fill .m i.low{background:#f79009}.pb-trip-fill .m i.over{background:#f04438}
.pb-stops{list-style:none;margin:0;padding:0 12px 8px;counter-reset:s}
.pb-stops li{display:grid;grid-template-columns:22px minmax(0,1fr) auto auto;gap:8px;align-items:center;padding:6px 0;border-top:1px dashed #eaecf0;font-size:11.5px}
.pb-stops li::before{counter-increment:s;content:counter(s);width:20px;height:20px;border-radius:50%;background:#eef4ff;color:var(--blue);display:grid;place-items:center;font-size:10px;font-weight:800}
.pb-stops .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pb-stops .nm small{color:var(--sub)}.pb-stops .n{text-align:right;color:var(--sub);font-variant-numeric:tabular-nums}
.pb-part{display:inline-block;margin-left:4px;font-size:10px;background:#fff6ed;color:#b93815;border-radius:4px;padding:1px 5px}
.pb-warn{margin:0 12px 10px;padding:7px 9px;background:#fffaeb;border-radius:6px;font-size:11px;color:#93590c}
.pb-excl{background:#fff;border:1px solid #fecdca;border-radius:10px;padding:10px 12px;font-size:11.5px;margin-bottom:10px}.pb-excl b{color:var(--red)}.pb-excl ul{margin:6px 0 0 16px;padding:0}
.pb-err{background:#fef3f2;border:1px solid #fecdca;color:#912018;border-radius:10px;padding:9px 14px;font-size:12px;margin-bottom:10px}
.pb-loading{padding:40px;text-align:center;color:var(--sub)}
.pb-dialog{border:1px solid var(--line);border-radius:12px;padding:0;width:min(440px,94vw);box-shadow:0 20px 50px rgba(15,27,45,.25)}.pb-dialog::backdrop{background:rgba(15,27,45,.35)}
.pb-dialog form{padding:18px 20px;display:flex;flex-direction:column;gap:10px}.pb-dialog h3{margin:0;font-size:15px}
.pb-dialog label{font-size:11.5px;color:#344054;font-weight:600;display:flex;flex-direction:column;gap:4px}.pb-dialog .select,.pb-dialog .input{width:100%;height:auto;min-height:34px}
.pb-dlg-actions{display:flex;justify-content:flex-end;gap:8px}
@media(max-width:1200px){.px-sum{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:800px){.px-det .cols{grid-template-columns:1fr}.pb-trip-c{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.pb-drawer{transition:none}}
</style>

<div class="px" id="pbRoot">
<?php if ($__syncing): ?>
<div id="syncBanner" role="status" style="display:flex;gap:10px;align-items:center;margin:0 0 10px;padding:10px 14px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-size:13px">
  <style>@keyframes tmsSpin{to{transform:rotate(360deg)}}</style>
  <span id="syncSpin" style="width:14px;height:14px;border:2px solid #93c5fd;border-top-color:#1d4ed8;border-radius:50%;animation:tmsSpin .8s linear infinite;flex:none"></span>
  <span><b>Refreshing…</b> <span id="syncTxt">The latest pending data is being loaded. This board reloads by itself when it is done (about a minute).</span></span>
</div>
<script>(function(){
  var last=<?= (int)$__lastSyncId ?>, t0=Date.now(), idle=0, b=document.getElementById('syncBanner'), tx=document.getElementById('syncTxt'), sp=document.getElementById('syncSpin');
  function fail(m){ b.style.background='#fef2f2'; b.style.borderColor='#fecaca'; b.style.color='#991b1b'; sp.style.display='none'; tx.textContent=m; }
  function poll(){
    fetch('PLANNING.php?tab=prd_pending&sync_status=1',{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();}).then(function(s){
      if(s.running){ idle=0; }
      else if(s.last_id>last){ if(s.last_ok){ location.replace('PLANNING.php?tab=prd_pending'); return; } fail('Refresh failed: '+s.last_message+' (the board shows the last good data).'); return; }
      else if(++idle>8){ fail('The refresh did not start. The board shows the last good data; click Refresh again.'); return; }
      if(Date.now()-t0>600000){ fail('The sync is taking too long. The board shows the last good data.'); return; }
      setTimeout(poll,3000);
    }).catch(function(){ setTimeout(poll,5000); });
  }
  setTimeout(poll,3000);
})();</script>
<?php endif; ?>
<?php if (!empty($GLOBALS['prd_truncated'])): ?><div class="alert alert-err" role="alert"><b>Board is incomplete:</b> only the first <?= number_format((int)$GLOBALS['prd_truncated']) ?> pending rows were loaded, so some stores are missing. Raise <code>'max_rows'</code> in config/prd_planning.php.</div><?php endif; ?>
<div class="px-head">
  <div class="px-stats">
    <?php foreach ($S as $sc => $lbl): $t = $T[$sc]; ?><span title="<?= e($lbl) ?>"><b><?= e($sc) ?></b> <?= number_format($t['pkt']) ?> PKT · ₹<?= $L($t['val']) ?></span><?php endforeach; ?>
    <?php if ($nSites > 1): ?><span class="all"><b>Total</b> <?= number_format($T['all']['pkt']) ?> PKT · ₹<?= $L($T['all']['val']) ?></span><?php endif; ?>
    <?php // Complete count. The board lists only PKT that still need a trip. PKT already on a trip are not on the board; those that the LAST sync
          // still returned are Pending in Oracle, those it did not return were dispatched in Oracle and only linger on their trip.
      $__pk = prd_pkt_col('p');
      // "returned by the last sync" = synced_at equals the newest sync time (one sync stamps every row with the same time).
      $__q = $d->prepare("WITH lt AS (SELECT MAX(synced_at) mx FROM prd_pending WHERE source_system='ORACLE')
        SELECT COUNT(DISTINCT $__pk) FILTER (WHERE " . prd_on_trip_sql('p') . " AND p.synced_at >= lt.mx) in_trips,
               COUNT(DISTINCT $__pk) FILTER (WHERE " . prd_in_plan_sql('p') . " AND p.synced_at >= lt.mx) in_plans,
               COUNT(DISTINCT $__pk) FILTER (WHERE " . prd_on_trip_sql('p') . " AND p.synced_at < lt.mx) gone,
               COUNT(DISTINCT $__pk) FILTER (WHERE p.synced_at >= lt.mx) all_pkt
        FROM prd_pending p CROSS JOIN lt WHERE p.source_system='ORACLE' AND UPPER(BTRIM(COALESCE(p.challan_status,'')))=?");
      $__q->execute([prd_status_filter()]); $__c = $__q->fetch(PDO::FETCH_ASSOC) ?: ['in_trips' => 0, 'in_plans' => 0, 'gone' => 0, 'all_pkt' => 0]; ?>
    <span title="Part of the PKT shown above: still Pending in Oracle, but already on a trip, so they cannot be planned again."><b><?= number_format((int)$__c['in_trips']) ?></b> PKT already in trips</span>
    <?php if ((int)$__c['in_plans'] > 0): ?><span title="Part of the PKT shown above: waiting on the Review trips page, so they are not on this board. Cancel the plan to bring them back."><b><?= number_format((int)$__c['in_plans']) ?></b> PKT in pending plans</span><?php endif; ?>
    <span title="Part of the PKT shown above that is not on any trip yet: this is what the dock / Review plan works with."><b><?= number_format((int)$Tplan['all']['pkt']) ?></b> PKT left to plan</span>
    <?php if ((int)$__c['gone'] > 0): ?><span class="bad" title="These PKT sit on a trip in TMS, but the last Oracle sync did not return them: they were dispatched in Oracle already. They are NOT part of 'All pending'. Check those trips (Trips tab)."><b><?= number_format((int)$__c['gone']) ?></b> PKT on trips already dispatched</span><?php endif; ?>
    <span><b><?= number_format(count($R)) ?></b> stores</span>
    <?php if ($M['due']): ?><span class="bad"><b><?= $M['due'] ?></b> not dispatched > <?= (int)$cfg['due_days'] ?> days</span><?php endif; ?>
    <?php if ($M['held']): ?><span><b><?= $M['held'] ?></b> on hold</span><?php endif; ?>
  </div>
  <div class="px-sync">
    <span class="st <?= $os['state'] === 'ok' ? 'ok' : (in_array($os['state'], ['failed', 'no_oci8', 'not_configured'], true) ? 'bad' : '') ?>" title="<?= e($os['last']['message'] ?? '') ?>"><i></i><?= e($syncLine) ?></span>
    <?php if (in_array($os['state'], ['no_oci8', 'not_configured'], true)): ?><a class="btn light" href="../SETUP_ORACLE.txt" target="_blank">How to connect</a>
    <?php elseif (can('plan')): ?><form method="post" onsubmit="setTimeout(function(){window.showAppBusy&&showAppBusy('Starting the refresh','One moment…')},0)"><input type="hidden" name="action" value="sync_prd_oracle"><button class="btn <?= $os['state'] === 'failed' ? 'retry' : 'success' ?>"><?= e($syncBtn) ?></button></form><?php endif; ?>
    <a class="btn light" href="<?= e($q(['export' => 'prd_matrix'])) ?>" data-no-busy>Export</a>
  </div>
</div>
<?php if ($os['state'] === 'failed' && !empty($os['last']['message'])): ?><div class="pb-err"><b>Oracle:</b> <?= e($os['last']['message']) ?></div><?php endif; ?>

<div class="px-tools">
  <span class="px-seg" aria-label="Warehouse"><a class="<?= $vo['wh'] === 'all' ? 'on' : '' ?>" href="<?= e($q(['wh' => 'all'])) ?>">All</a><?php foreach ($allSites as $sc => $lbl): ?><a class="<?= $vo['wh'] === (string)$sc ? 'on' : '' ?>" href="<?= e($q(['wh' => $sc])) ?>" title="<?= e($lbl) ?>"><?= e($sc) ?></a><?php endforeach; ?></span>
  <span class="px-seg" aria-label="Suggestion"><a class="<?= $sug === 'joint' ? 'on' : '' ?>" href="<?= e($q(['sug' => 'joint'])) ?>" title="One vehicle may collect from both warehouses">Joint vehicle</a><a class="<?= $sug === 'separate' ? 'on' : '' ?>" href="<?= e($q(['sug' => 'separate'])) ?>" title="Each warehouse plans its own vehicles">Per warehouse</a></span>
  <input class="input" id="pxQ" placeholder="Search store" oninput="px.filter()" style="width:150px" aria-label="Search">
  <select class="select" id="pxState" onchange="px.filter()" aria-label="State"><option value="">State</option><?php foreach (array_keys($states) as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select>
  <select class="select" id="pxCluster" onchange="px.filter()" aria-label="Cluster"><option value="">Cluster</option><?php foreach (array_keys($clusters) as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select>
  <select class="select" id="pxType" onchange="px.filter()" aria-label="Suggestion"><option value="">Suggestion</option><?php foreach (['NOW' => 'Dispatch now', 'FTL' => 'Dispatch (full)', 'PTL' => 'Dispatch (part)', 'CLUB' => 'Club', 'HOLD' => 'Wait', 'HELD' => 'On hold'] as $k => $l): if (isset($chipTypes[$k])): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endif; endforeach; ?></select>
  <label class="ck"><input type="checkbox" id="pxDue" onchange="px.filter()"> > <?= (int)$cfg['due_days'] ?> days</label>
  <label class="ck"><input type="checkbox" id="pxCompact" onchange="px.compact(this.checked)"> Hide values</label>
  <span class="px-count" id="pxCount"></span>
</div>

<div class="cl-chips" id="clChips" aria-label="Select a whole cluster"></div>
<div class="px-wrap">
<?php if (!$R): ?><div style="padding:50px;text-align:center;color:var(--sub)"><b>No pending challans.</b><br><?= in_array($os['state'], ['no_oci8', 'not_configured'], true) ? 'Connect Oracle first.' : 'Click “' . e($syncBtn) . '”. Challans already in a trip are not shown.' ?></div>
<?php else: ?>
<table class="px-t" id="pxT">
<thead>
<tr><th class="ck" rowspan="2"><input type="checkbox" aria-label="Select all visible" onclick="px.all(this)"></th>
  <th class="st s" rowspan="2" data-k="name">Destination</th><th rowspan="2" class="s" data-k="state">State</th><th rowspan="2">Store status</th><th rowspan="2" class="s" data-k="cluster">Cluster</th><th rowspan="2" class="s" data-k="wait">Last dispatch</th><th rowspan="2" class="sgh">Dispatch suggestion</th>
  <?php foreach ($S as $sc => $lbl): ?><th class="grp" colspan="6" data-full="6" data-short="3" title="<?= e($lbl) ?>"><?= e($sc) ?> · <?= e($lbl) ?></th><?php endforeach; ?>
  <?php if ($nSites > 1): ?><th class="grp tot" colspan="6" data-full="6" data-short="3">Total</th><?php endif; ?>
  </tr>
<tr><?php $grpHdr = function ($pfx, $tot = false) { $c = $tot ? ' tot' : ''; return '<th class="gs n s' . $c . '" data-k="ap' . $pfx . '">APPS PKT</th><th class="n v' . $c . '">APPS Val</th><th class="n s' . $c . '" data-k="gp' . $pfx . '">GM PKT</th><th class="n v' . $c . '">GM Val</th><th class="n s' . $c . '" data-k="tp' . $pfx . '">Total PKT</th><th class="n v' . $c . '">Total Val</th>'; };
  foreach ($S as $sc => $_) echo $grpHdr(e($sc)); if ($nSites > 1) echo $grpHdr('all', true); ?></tr>
</thead>
<tbody>
<?php $cells = function (?array $b, bool $tot = false) use ($N, $V) { $c = $tot ? ' tot' : ''; return '<td class="gs n' . $c . '">' . $N($b['apps_pkt'] ?? 0) . '</td><td class="n v' . $c . '">' . $V($b['apps_val'] ?? 0) . '</td><td class="n' . $c . '">' . $N($b['gm_pkt'] ?? 0) . '</td><td class="n v' . $c . '">' . $V($b['gm_val'] ?? 0) . '</td><td class="n' . $c . '"><b>' . $N($b['pkt'] ?? 0) . '</b></td><td class="n v' . $c . '">' . $V($b['val'] ?? 0) . '</td>'; };
// Per store: PKT that are already on a trip but still Pending in Oracle (last sync returned them). Board PKT + these = what an Oracle report shows for the store.
$__it = [];
foreach ($d->query("WITH lt AS (SELECT MAX(synced_at) mx FROM prd_pending WHERE source_system='ORACLE')
    SELECT lower(BTRIM(p.destination_name)) k, p.source_site_code site, COUNT(DISTINCT " . prd_pkt_col('p') . ") pkt, STRING_AGG(DISTINCT t.trip_code || ' (' || t.status || ')', ', ') trips
    FROM prd_pending p CROSS JOIN lt JOIN trips t ON t.id=p.planned_trip_id
    WHERE p.source_system='ORACLE' AND COALESCE(t.status,'')<>'Cancelled' AND p.synced_at >= lt.mx AND UPPER(BTRIM(COALESCE(p.challan_status,'')))=" . $d->quote(prd_status_filter()) . "
    GROUP BY 1,2")->fetchAll(PDO::FETCH_ASSOC) as $__x) {
  $__it[$__x['k']]['pkt'] = ($__it[$__x['k']]['pkt'] ?? 0) + (int)$__x['pkt'];
  $__it[$__x['k']]['tip'][] = $__x['site'] . ': ' . $__x['pkt'] . ' PKT on ' . $__x['trips'];
}
// Per store: PKT reserved by a pending plan (not on the board until the plan is cancelled or turned into trips)
$__ip = [];
foreach ($d->query("WITH lt AS (SELECT MAX(synced_at) mx FROM prd_pending WHERE source_system='ORACLE')
    SELECT lower(BTRIM(p.destination_name)) k, p.source_site_code site, COUNT(DISTINCT " . prd_pkt_col('p') . ") pkt, STRING_AGG(DISTINCT pl.plan_no, ', ') plans
    FROM prd_pending p CROSS JOIN lt JOIN tms_plan_lines l ON l.id=p.plan_line_id AND l.status='Pending' JOIN tms_plans pl ON pl.id=l.plan_id
    WHERE p.source_system='ORACLE' AND NOT " . prd_on_trip_sql('p') . " AND p.synced_at >= lt.mx AND UPPER(BTRIM(COALESCE(p.challan_status,'')))=" . $d->quote(prd_status_filter()) . "
    GROUP BY 1,2")->fetchAll(PDO::FETCH_ASSOC) as $__x) {
  $__ip[$__x['k']]['pkt'] = ($__ip[$__x['k']]['pkt'] ?? 0) + (int)$__x['pkt'];
  $__ip[$__x['k']]['tip'][] = $__x['site'] . ': ' . $__x['pkt'] . ' PKT in ' . $__x['plans'];
}
foreach ($R as $id => $r): $types = implode(' ', array_column($r['chips'], 'type')); $isDue = $r['due'] && !$r['off'];
  $__k = strtolower(trim((string)$r['name'])); $dsp = $__ot[$__k]['all'] ?? $__tz; ?>
<tr class="<?= $r['off'] ? 'off' : '' ?><?= $isDue ? ' due' : '' ?>" data-id="<?= $id ?>" data-q="<?= e(strtolower($r['name'] . ' ' . ($r['store']['name'] ?? '') . ' ' . ($r['store']['city'] ?? ''))) ?>" data-state="<?= e($r['state']) ?>" data-cluster="<?= e($r['cluster']) ?>" data-types="<?= e($types) ?>" data-due="<?= $isDue ? 1 : 0 ?>"
  data-name="<?= e(strtolower($r['name'])) ?>" data-pkt="<?= $dsp['pkt'] ?>" data-val="<?= round($dsp['val']) ?>" data-wait="<?= (int)$r['wait'] ?>" data-apall="<?= $dsp['apps_pkt'] ?>" data-gpall="<?= $dsp['gm_pkt'] ?>" data-tpall="<?= $dsp['pkt'] ?>"<?php foreach ($S as $sc => $_): ?> data-ap<?= e($sc) ?>="<?= (int)($__ot[$__k][$sc]['apps_pkt'] ?? 0) ?>" data-gp<?= e($sc) ?>="<?= (int)($__ot[$__k][$sc]['gm_pkt'] ?? 0) ?>" data-tp<?= e($sc) ?>="<?= (int)($__ot[$__k][$sc]['pkt'] ?? 0) ?>"<?php endforeach; ?>>
  <td class="ck"><input type="checkbox" class="px-ck" value="<?= $id ?>" aria-label="Select <?= e($r['name']) ?>" <?= $r['off'] ? 'disabled' : '' ?>></td>
  <td class="st"><button type="button" class="nm" onclick="px.open('<?= $id ?>')" aria-expanded="false" title="<?= e($r['store'] ? ($r['store']['name'] . ($r['store']['city'] ? ', ' . $r['store']['city'] : '')) : 'Not in Store Master') ?>"><?= e($r['name']) ?></button><?= !$r['store'] ? '<span class="tag m" title="Not in Store Master">!</span>' : '' ?><?php $__k = strtolower(trim((string)$r['name'])); if (!empty($__it[$__k])): ?> <span style="font-size:10px;font-weight:700;color:#475569;background:#e2e8f0;border-radius:999px;padding:1px 7px;white-space:nowrap" title="Included in the PKT shown for this store (as in Oracle): already on a trip, so they cannot be planned again. Left to plan: <?= (int)$r['pkt'] ?> PKT.&#10;<?= e(implode("\n", $__it[$__k]['tip'])) ?>"><?= (int)$__it[$__k]['pkt'] ?> in trips</span><?php endif; ?><?php if (!empty($__ip[$__k])): ?> <span style="font-size:10px;font-weight:700;color:#1d4ed8;background:#eff4ff;border-radius:999px;padding:1px 7px;white-space:nowrap" title="Included in the PKT shown for this store (as in Oracle): waiting on the Review trips page.&#10;<?= e(implode("\n", $__ip[$__k]['tip'])) ?>"><?= (int)$__ip[$__k]['pkt'] ?> in plan</span><?php endif; ?></td>
  <td><?= e($r['state'] ?: '–') ?></td><td><?= e($r['status'] ?: '–') ?></td><td><?= e($r['cluster'] ?: '–') ?></td>
  <td class="<?= $isDue ? 'late' : '' ?>"><?= $r['last_dispatch'] ? e(date('d-M-y', strtotime((string)$r['last_dispatch']))) : '–' ?><?= $r['wait'] !== null ? ' <small>' . (int)$r['wait'] . 'd</small>' : '' ?></td>
  <td class="sg"><?php foreach ($r['chips'] as $src => $c): ?><span class="chip c-<?= e($c['type']) ?>" title="<?= e($c['remark'] . ($c['sub'] ? ' · ' . $c['sub'] : '')) ?>"><?= $sug === 'separate' && $nSites > 1 ? '<span class="src">' . e($src) . '</span>' : '' ?><?= e($c['label']) ?><?= $c['veh'] ? ' <small>' . e($c['veh']) . '</small>' : '' ?></span><?php endforeach; ?></td>
  <?php foreach ($S as $sc => $_) echo $cells($__ot[$__k][$sc] ?? null); if ($nSites > 1) echo $cells($dsp, true); ?>

</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td class="ck"></td><td class="st">GRAND TOTAL</td><td></td><td></td><td></td><td id="pxFootN"><?= count($R) ?> stores</td><td></td>
  <?php $ft = function (array $b) use ($L) { return '<td class="gs n">' . number_format($b['apps_pkt']) . '</td><td class="n v">' . $L($b['apps_val']) . '</td><td class="n">' . number_format($b['gm_pkt']) . '</td><td class="n v">' . $L($b['gm_val']) . '</td><td class="n">' . number_format($b['pkt']) . '</td><td class="n v">' . $L($b['val']) . '</td>'; };
  foreach ($S as $sc => $_) echo $ft($T[$sc]); if ($nSites > 1) echo $ft($T['all']); ?></tr></tfoot>
</table>
<?php endif; ?>
</div>

<?php // Full matrix is the reference table (APPS / GM split per warehouse). Picking stores, clusters and trip analysis live on the PRD Pending desk, so those widgets are not shown here. ?>
<style>
.cl-chips,.px-dw,td.ck,th.ck{display:none!important}
.px-head{margin:0 0 6px;gap:8px}.px-stats{gap:6px}.px-stats>span{padding:3px 10px!important;font-size:12px!important}
.px-tools{margin:0 0 6px!important;gap:6px!important}.px-tools .input,.px-tools .select,.px-tools .px-seg a{height:30px!important;font-size:12px!important}
.px-t{font-size:12px}.px-t td{padding:3px 8px!important;line-height:1.25}
/* two header rows that stay locked while the rows scroll: fixed heights so the second row sits exactly under the first */
.px-t thead th{padding:0 8px!important;font-size:11px;position:sticky!important;z-index:5}
.px-t thead tr:first-child th{top:0!important;height:28px!important;line-height:28px}
.px-t thead tr:nth-child(2) th{top:28px!important;height:26px!important;line-height:26px}
.px-t thead tr:first-child th[rowspan]{height:54px!important;vertical-align:middle}
.px-t td.st,.px-t th.st{left:0!important;min-width:150px!important;max-width:190px}
.px-t td.st{overflow:hidden;text-overflow:ellipsis}
.px-t th:not(.st):not(.grp):not(.gs):not(.sgh),.px-t td{min-width:0}
.px-t td.n,.px-t th.n{min-width:52px;width:1%}
.px-t td.sg{min-width:150px;max-width:210px}
.px-wrap{height:calc(100vh - 175px);max-height:none;min-height:240px;overflow:auto!important}
</style>
<?php if (!can('plan')): // view-only role: no store selection, plan review, or hold / release (the server refuses these too) ?>
<style>.px-dw,.cl-chips,.px-dock,.pb-drawer,.pb-scrim,td.ck input[type=checkbox],th.ck input[type=checkbox],.px-det .btn[onclick^="px.hold"],.px-det .btn[onclick^="px.release"]{display:none!important}</style>
<?php endif; ?>
<div class="px-dw">
<section class="an" id="anP" hidden aria-label="Selection analysis">
  <div class="an-bar" onclick="px.anToggle()"><b>Analysis</b><span class="sum" id="anSum"></span><span class="wait" id="anWait"></span><span class="tg" id="anTg">Show ▲</span></div>
  <div class="an-body" id="anBody" hidden></div>
</section>
<div class="px-dock" aria-live="polite">
  <div><div class="k">Selected</div><div class="v" id="dkStores">0</div></div>
  <div><div class="k">PKT</div><div class="v" id="dkPkt">0</div></div>
  <div><div class="k">Value</div><div class="v" id="dkVal">₹0</div></div>
  <div class="hint" id="dkHint">Tick the stores to dispatch, then click Review trips.</div>
  <button class="btn light" type="button" onclick="px.pickDue()" <?= $M['due'] ? '' : 'disabled' ?>>Select waiting > <?= (int)$cfg['due_days'] ?>d</button>
  <button class="btn clear" type="button" id="dkClear" onclick="px.clear()" hidden>Clear</button>
  <button class="btn primary" type="button" id="dkReview" onclick="px.makePlan()" disabled>Review trips</button>
</div>
</div>

<dialog class="pb-dialog" id="pbHold" aria-labelledby="pbHoldT"><form method="dialog" onsubmit="px.holdSave(event)" data-no-busy>
  <h3 id="pbHoldT">Hold store <span data-dest></span></h3><p class="note">The store leaves all suggestions and trip planning until released or the date passes.</p>
  <label>Reason<select class="select" name="reason" required><option value="">Choose a reason</option><?php foreach (PRD_HOLD_REASONS as $hr): ?><option><?= e($hr) ?></option><?php endforeach; ?></select></label>
  <label>Remarks<textarea class="input" name="remarks" rows="3" maxlength="500" placeholder="e.g. Audit till Friday, store manager informed"></textarea></label>
  <label>Hold until (optional)<input class="input" type="date" name="until" min="<?= date('Y-m-d') ?>"></label>
  <div id="pbHoldErr" style="color:var(--red);font-size:12px" role="alert"></div>
  <div class="pb-dlg-actions"><button type="button" class="btn light" onclick="this.closest('dialog').close()">Cancel</button><button class="btn primary" type="submit">Put on hold</button></div></form></dialog>

<div class="pb-scrim" onclick="px.close()"></div>
<aside class="pb-drawer" role="dialog" aria-modal="true" aria-labelledby="pbDrTitle">
  <div class="pb-dh"><div><h3 id="pbDrTitle">Review plan</h3><p>Each trip: max <?= tms_trip_max_stores() ?> stores, <?= tms_trip_max_pkt() ?> PKT. Change DC or vehicle if needed, then create.</p></div><button class="x" type="button" onclick="px.close()" aria-label="Close">×</button></div>
  <div class="pb-mode" role="radiogroup" aria-label="Planning mode">
    <label><input type="radio" name="pbmode" value="separate" <?= $sug === 'joint' ? '' : 'checked' ?> onchange="px.review()"> <b>Individual trips</b><small>Each warehouse loads its own vehicles</small></label>
    <label><input type="radio" name="pbmode" value="joint" <?= $sug === 'joint' ? 'checked' : '' ?> onchange="px.review()"> <b>Joint vehicle</b><small>One vehicle collects from both warehouses</small></label></div>
  <div class="pb-db" id="pbDrBody"></div>
  <div class="pb-df"><div class="sum" id="pbDrSum"></div><button class="btn light" type="button" onclick="px.close()">Back</button><button class="btn primary" type="button" id="pbDrGo" onclick="px.create()">Create trips</button></div>
</aside>
</div>

<script>
(function(){
  const D=<?= json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const DET=<?= json_encode(array_map(fn($r) => ['name' => $r['name'], 'hold' => $r['hold'] ? ['reason' => $r['hold']['reason'], 'remarks' => $r['hold']['remarks'], 'until' => $r['hold']['hold_until'], 'since' => substr((string)$r['hold']['created_at'], 0, 10)] : null,
    'note' => $r['note'], 'note_key' => $r['note_key'], 'chips' => $r['chips'], 'flags' => array_map(fn($f) => [$f[0], $f[1]], $r['flags']), 'last' => $r['last_dispatch'], 'oldest' => $r['oldest'], 'km' => $r['dc_km'] ? round($r['dc_km']) : null,
    'store' => $r['store'] ? ($r['store']['code'] . ' · ' . $r['store']['name'] . ($r['store']['city'] ? ', ' . $r['store']['city'] : '')) : null, 'by' => $r['by']], $R), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const SITELBL=<?= json_encode($vo['site'] ? (string)$vo['site'] : ($nSites > 1 ? 'both sites' : '')) ?>;
  const TOKEN=<?= json_encode($token) ?>, MAXPKT=<?= tms_trip_max_pkt() ?>, MAXST=<?= tms_trip_max_stores() ?>, MINFILL=<?= (int)$cfg['min_fill_pct'] ?>, NCOL=<?= 7 + 6 * $nSites + ($nSites > 1 ? 6 : 0) ?>;
  const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const L=v=>'₹'+(v>=1e7?(v/1e7).toFixed(2)+' Cr':v>=1e5?(v/1e5).toFixed(2)+' L':Math.round(v).toLocaleString('en-IN'));
  const rows=()=>$$('#pxT tbody tr[data-id]');
  let sortK=null,sortD=-1,plan=null,openId=null;
  function filter(){
    const q=($('#pxQ')?.value||'').toLowerCase().trim(),st=$('#pxState')?.value||'',cl=$('#pxCluster')?.value||'',ty=$('#pxType')?.value||'',due=$('#pxDue')?.checked;
    let n=0; rows().forEach(r=>{const ok=(!q||r.dataset.q.includes(q))&&(!st||r.dataset.state===st)&&(!cl||r.dataset.cluster===cl)&&(!ty||(' '+r.dataset.types+' ').includes(' '+ty+' '))&&(!due||r.dataset.due==='1');r.hidden=!ok;if(ok)n++;if(!ok&&openId===r.dataset.id)closeDet();});
    $('#pxCount').textContent=n+' of '+rows().length+' stores';
  }
  function sortBy(k){
    const tb=$('#pxT tbody'); sortD=sortK===k?-sortD:(['name','state','cluster'].includes(k)?1:-1); sortK=k; closeDet();
    $$('#pxT th[data-k]').forEach(h=>h.removeAttribute('aria-sort')); $('#pxT th[data-k="'+k+'"]').setAttribute('aria-sort',sortD>0?'ascending':'descending');
    rows().sort((a,b)=>{const x=a.dataset[k]||'',y=b.dataset[k]||'';return (['name','state','cluster'].includes(k)?x.localeCompare(y):(+x)-(+y))*sortD;}).forEach(r=>tb.appendChild(r));
  }
  function picked(){return $$('.px-ck:checked').map(c=>c.value);}
  function sel(){
    const ids=picked(); rows().forEach(r=>r.classList.toggle('sel',ids.includes(r.dataset.id)));
    const pkt=ids.reduce((s,i)=>s+D[i].pkt,0),val=ids.reduce((s,i)=>s+D[i].val,0);
    $('#dkStores').textContent=ids.length; $('#dkPkt').textContent=pkt.toLocaleString('en-IN'); $('#dkVal').textContent=L(val);
    $('#dkReview').disabled=!ids.length; $('#dkReview').textContent=ids.length?'Review trips for '+ids.length+' store'+(ids.length>1?'s':''):'Review trips'; $('#dkClear').hidden=!ids.length;
    if(typeof anSchedule==='function'){chips();anSchedule(ids);}
    $('#dkHint').textContent=ids.length?'The system proposes the trips for the ticked stores'+(SITELBL?' ('+SITELBL+')':'')+'; you confirm them on the next page.':'Tick the stores to dispatch, then click Review trips.'+(SITELBL?' Site: '+SITELBL+'.':'');
  }
  function clear(){$$('.px-ck').forEach(c=>c.checked=false);sel();}
  function all(x){$$('.px-ck').forEach(c=>{if(!c.closest('tr').hidden&&!c.disabled)c.checked=x.checked});sel();}
  function pickDue(){$$('#pxT tbody tr[data-due="1"] .px-ck').forEach(c=>{if(!c.disabled&&!c.closest('tr').hidden)c.checked=true});sel();}
  async function post(data){
    const fd=new FormData();fd.append('csrf',TOKEN);Object.entries(data).forEach(([k,v])=>Array.isArray(v)?v.forEach(x=>fd.append(k+'[]',x)):fd.append(k,v));
    const r=await fetch('PLANNING.php?tab=prd_pending',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    let j;try{j=await r.json();}catch(e){throw new Error('Server returned an unexpected response (HTTP '+r.status+'). Check the PHP error log.');}
    if(!j.ok)throw new Error(j.error||'Request failed');return j;
  }
  /* ---- store detail (lazy) ---- */
  function closeDet(){const x=$('#pxDet');if(x)x.remove();if(openId){const b=$('tr[data-id="'+openId+'"] .nm');if(b)b.setAttribute('aria-expanded','false');}openId=null;}
  async function open(id){
    if(openId===id){closeDet();return;} closeDet(); openId=id;
    const r=$('tr[data-id="'+id+'"]'), d=DET[id]; r.querySelector('.nm').setAttribute('aria-expanded','true');
    const sites=Object.keys(d.by);
    const sum=sites.map(s=>{const b=d.by[s];return '<tr><td><b>'+esc(s)+'</b></td><td class="n">'+b.apps_pkt+'</td><td class="n">'+L(b.apps_val)+'</td><td class="n">'+b.gm_pkt+'</td><td class="n">'+L(b.gm_val)+'</td><td>'+esc(b.oldest||'')+'</td><td>'+(b.wait??'')+'</td></tr>';}).join('');
    const chips=Object.entries(d.chips).map(([s,c])=>'<div class="rm"><b>'+(sites.length>1&&s!=='joint'?esc(s)+': ':'')+esc(c.remark)+'</b><span>'+esc(c.sub)+'</span></div>').join('');
    const flags=Object.values(d.flags).map(f=>'<span class="'+esc(f[0])+'">'+esc(f[1])+'</span>').join('');
    const hold=d.hold?'<div class="rm" style="color:#93590c"><b>On hold: '+esc(d.hold.reason)+'</b><span>'+esc(d.hold.remarks||'')+' · since '+esc(d.hold.since)+(d.hold.until?' · until '+esc(d.hold.until):'')+'</span></div><button type="button" class="btn light" onclick="px.release()">Release hold</button>'
      :(d.store?'<button type="button" class="btn light" onclick="px.hold()">Hold store…</button>':'<span class="note">Not in Store Master; add it to plan this store.</span>');
    const tr=document.createElement('tr'); tr.id='pxDet'; tr.className='px-det';
    tr.innerHTML='<td colspan="'+NCOL+'"><div class="cols"><div>'+
      '<h4>'+esc(d.store||d.name)+(d.km?' · '+d.km+' km from DC':'')+'</h4>'+
      '<table><tr><th>Site</th><th class="n">APPS PKT</th><th class="n">APPS Val</th><th class="n">GM PKT</th><th class="n">GM Val</th><th>Oldest challan</th><th>Days</th></tr>'+sum+'</table>'+
      '<div style="margin-top:10px">'+chips+'</div><div class="flags">'+flags+'</div>'+
      '<div class="note">Last dispatch: '+esc(d.last||'no record')+'</div>'+
      '<div class="acts">'+hold+'<input id="pxNote" maxlength="500" placeholder="Planner note for this store" value="'+esc(d.note)+'"><button type="button" class="btn light" onclick="px.saveNote()">Save note</button><span id="pxNoteMsg" class="note"></span></div>'+
      '</div><div><h4>Challans</h4><div class="ch" id="pxCh"><div class="note">Loading challans…</div></div></div></div></td>';
    r.after(tr);
    try{const j=await post({action:'prd_detail',keys:D[id].keys});
      $('#pxCh').innerHTML=j.rows.length?'<table><tr><th>Site</th><th>Challan</th><th>Div</th><th>Date</th><th class="n">Qty</th><th class="n">Value</th></tr>'+j.rows.map(x=>'<tr><td>'+esc(x.src)+'</td><td><b>'+esc(x.challan_no)+'</b></td><td>'+esc(x.div)+'</td><td>'+esc(x.dt||'')+'</td><td class="n">'+Math.round(x.qty)+'</td><td class="n">'+L(+x.val)+'</td></tr>').join('')+'</table>'+(j.rows.length>=1000?'<div class="note">First 1,000 lines shown. Export CSV for all.</div>':''):'<div class="note">No challans.</div>';
    }catch(e){$('#pxCh').innerHTML='<div class="pb-err">'+esc(e.message)+'</div>';}
  }
  async function saveNote(){const d=DET[openId],v=$('#pxNote').value.trim();try{await post({action:'save_prd_note',gkey:d.note_key,note:v,source_label:'',destination_name:d.name});d.note=v;$('#pxNoteMsg').textContent='Saved';}catch(e){$('#pxNoteMsg').textContent='Not saved: '+e.message;}}
  function hold(){const dlg=$('#pbHold');dlg.querySelector('[data-dest]').textContent=DET[openId].name;dlg.dataset.dest=DET[openId].name;dlg.querySelector('form').reset();$('#pbHoldErr').textContent='';dlg.showModal();}
  async function holdSave(e){e.preventDefault();const dlg=$('#pbHold'),f=e.target;try{await post({action:'prd_hold',dest:dlg.dataset.dest,reason:f.reason.value,remarks:f.remarks.value,until:f.until.value});location.reload();}catch(x){$('#pbHoldErr').textContent=x.message;}}
  async function release(){const n=DET[openId].name;if(!confirm('Release hold for '+n+'?'))return;try{await post({action:'prd_release',dest:n});location.reload();}catch(x){alert(x.message);}}
  /* ---- review plan ---- */
  function openDr(){$('#pbRoot').classList.add('pb-open');document.addEventListener('keydown',esck);}
  function close(){$('#pbRoot').classList.remove('pb-open');document.removeEventListener('keydown',esck);}
  function esck(e){if(e.key==='Escape')close();}
  async function review(){
    const keys=picked().flatMap(i=>D[i].keys); if(!keys.length)return;
    const mode=(document.querySelector('input[name=pbmode]:checked')||{}).value||'separate';
    $('#pbDrBody').innerHTML='<div class="pb-loading">Building the plan…</div>';$('#pbDrSum').textContent='';$('#pbDrGo').disabled=true;openDr();
    try{plan=await post({action:'prd_preview',keys,mode});renderPlan();}catch(e){$('#pbDrBody').innerHTML='<div class="pb-err">'+esc(e.message)+'</div>';}
  }
  function capOf(t){return String(t).split('+').reduce((s,p)=>{const m=p.trim().match(/^(\d+)×\s*(.+)$/);const c=plan.classes.find(x=>x.type===(m?m[2]:p.trim()));return s+(c?c.capacity*(m?+m[1]:1):0);},0);}
  function vehOpts(cur){const o=plan.classes.map(c=>c.type);if(cur&&!o.includes(cur))o.push(cur);return o.map(t=>'<option'+(t===cur?' selected':'')+'>'+esc(t)+'</option>').join('');}
  function renderPlan(){
    let h='';
    if(plan.excluded.length)h+='<div class="pb-excl"><b>'+plan.excluded.length+' store(s) left out</b><ul>'+plan.excluded.map(x=>'<li>'+esc(x.name)+': '+esc(x.reason)+'</li>').join('')+'</ul></div>';
    if(!plan.loads.length)h+='<div class="pb-err">None of the selected stores can be planned.</div>';
    plan.loads.forEach((l,i)=>{
      const dcTxt=l.dc?esc(l.dc.code+' · '+l.dc.name):'<span style="color:#b42318">Site not linked to a Center DC</span>';
      h+='<section class="pb-trip" data-i="'+i+'"><div class="pb-trip-h"><label><input type="checkbox" checked onchange="px.inc('+i+',this.checked)"> Trip '+(i+1)+' · '+esc(l.source)+'</label><div class="tot"><b>'+l.pkt+' PKT</b> · '+L(l.value)+'<br>'+l.stops.length+' stop'+(l.stops.length>1?'s':'')+(l.route_km!=null?' · ~'+l.route_km+' km':'')+'</div></div>'+
        (l.joint?'<div class="pb-pick">Pickups: '+l.pickups.map(p=>esc(p.source)+' <b>'+p.pkt+'</b>').join(' → ')+'</div>':'')+
        '<div class="pb-trip-c"><div><label>'+(l.joint?'Loads first at':'Loading DC')+'</label><div class="pb-dc">'+dcTxt+'</div></div><div><label for="vh'+i+'">Vehicle</label><select class="select" id="vh'+i+'" onchange="px.upd()">'+vehOpts(l.vehicle)+'</select></div></div>'+
        '<div class="pb-trip-fill"><span id="fl'+i+'"></span><div class="m"><i id="fm'+i+'"></i></div></div><ol class="pb-stops">'+
        l.stops.map(s=>'<li><span class="nm"><b>'+esc(s.code)+'</b> <small>'+esc(s.name)+(s.city?', '+esc(s.city):'')+'</small>'+(s.part?' <span class="pb-part">part '+esc(s.part)+'</span>':'')+(s.parts&&s.parts.length>1?' <small>('+s.parts.map(x=>esc(x.source)+' '+x.pkt).join(' + ')+')</small>':'')+'</span><span class="n">'+s.pkt+' PKT</span><span class="n">'+(s.km!=null?s.km+' km':'')+'</span></li>').join('')+
        '</ol>'+l.warnings.map(w=>'<div class="pb-warn">'+esc(w)+'</div>').join('')+'</section>';
      l.on=true;
    });
    $('#pbDrBody').innerHTML=h;upd();
  }
  function inc(i,on){plan.loads[i].on=on;$('.pb-trip[data-i="'+i+'"]').classList.toggle('skip',!on);upd();}
  function upd(){
    let n=0,st=0,pk=0,miss=0;
    plan.loads.forEach((l,i)=>{const cap=capOf($('#vh'+i).value),f=cap?Math.round(l.pkt*100/cap):0,m=$('#fm'+i);$('#fl'+i).textContent=f+'% of '+cap+' PKT';m.style.width=Math.min(100,f)+'%';m.className=f>100?'over':(f<MINFILL?'low':'');
      if(l.on){n++;st+=l.stops.length;pk+=l.pkt;if(!l.dc)miss++;}});
    $('#pbDrSum').innerHTML='<b>'+n+'</b> trip(s) · <b>'+st+'</b> stops · <b>'+pk.toLocaleString('en-IN')+'</b> PKT'+(miss?' · <span style="color:#b42318">'+miss+' trip(s) need the site linked to a DC (<a href="MASTER_OPS.php?tab=warehouses">Warehouses</a>)</span>':'');
    const g=$('#pbDrGo');g.disabled=!n||miss>0;g.textContent=n?'Create '+n+' trip'+(n>1?'s':''):'Create trips';
  }
  // Tick stores -> ONE plan (all ticked stores; the sites are the ones this board is showing). Trips are made from the plan later.
  async function makePlan(){
    const ids=picked(); if(!ids.length)return;
    const pkt=ids.reduce((s,i)=>s+D[i].pkt,0),val=ids.reduce((s,i)=>s+D[i].val,0);
    if(!confirm('Create ONE plan for '+ids.length+' store'+(ids.length>1?'s':'')+' · '+pkt.toLocaleString('en-IN')+' PKT · '+L(val)+(SITELBL?' · '+SITELBL:'')+'?'))return;
    const b=$('#dkReview');b.disabled=true;b.textContent='Creating plan…';
    try{const j=await post({action:'create_plan',keys:ids.flatMap(i=>D[i].keys)});location.href=j.redirect;}
    catch(e){alert('Plan was not created: '+e.message);sel();}
  }
  async function create(){
    const loads=plan.loads.map((l,i)=>({l,i})).filter(x=>x.l.on).map(x=>({joint:!!x.l.joint,stops:x.l.stops.map(s=>({parts:(s.parts||[{gkey:s.gkey,pkts:s.pkts}]).map(p=>({gkey:p.gkey,pkts:p.pkts}))})),dc_id:x.l.dc?+x.l.dc.id:0,vehicle_type:$('#vh'+x.i).value}));
    const g=$('#pbDrGo');g.disabled=true;g.textContent='Creating…';
    try{const j=await post({action:'create_prd_plan',loads:JSON.stringify(loads)});location.href=j.redirect;}
    catch(e){$('#pbDrBody').insertAdjacentHTML('afterbegin','<div class="pb-err"><b>Trips were not created.</b> '+esc(e.message)+'</div>');$('#pbDrBody').scrollTop=0;upd();}
  }
  $$('#pxT th[data-k]').forEach(h=>{h.tabIndex=0;h.addEventListener('click',()=>sortBy(h.dataset.k));h.addEventListener('keydown',e=>{if(e.key==='Enter')sortBy(h.dataset.k)});});
  $('#pxT')?.addEventListener('change',e=>{if(e.target.classList.contains('px-ck'))sel();});
  $('#pxT')?.addEventListener('click',e=>{const tr=e.target.closest('tbody tr[data-id]');if(!tr||e.target.closest('input,button,a'))return;const c=tr.querySelector('.px-ck');if(c&&!c.disabled){c.checked=!c.checked;sel();}});
  function compact(on){const t=$('#pxT');if(!t)return;t.classList.toggle('compact',on);$$('#pxT th.grp[data-full]').forEach(h=>h.colSpan=on?+h.dataset.short:+h.dataset.full);try{localStorage.setItem('px_compact',on?'1':'0')}catch(e){}}
  try{if(localStorage.getItem('px_compact')==='1'||(localStorage.getItem('px_compact')===null&&<?= $nSites ?>>=2)){$('#pxCompact').checked=true;compact(true);}}catch(e){}
  /* ---- cluster chips + live clubbing analysis of the ticked stores ---- */
  let anTok=0,anTimer=null,anTab='club',anOpen=true,anData=null,anManual=false;
  function chips(){
    const by={}; rows().forEach(r=>{const c=r.dataset.cluster; if(!c||c==='-')return; const ck=r.querySelector('.px-ck'); if(!ck||ck.disabled)return; const b=by[c]||(by[c]={n:0,on:0,pkt:0}); b.n++; b.pkt+=D[r.dataset.id].pkt; if(ck.checked)b.on++;});
    const names=Object.keys(by).sort(); const box=$('#clChips'); if(!box)return;
    box.innerHTML=names.length?'<span class="lbl">Clusters</span>'+names.map(c=>{const b=by[c];return '<button type="button" class="cl-chip '+(b.on===b.n?'on':(b.on?'part':''))+'" data-c="'+esc(c)+'" title="Tick / untick every store of this cluster that can be planned"><b>'+esc(c)+'</b> '+b.n+' stores <small>'+b.pkt.toLocaleString('en-IN')+' PKT'+(b.on&&b.on<b.n?' · '+b.on+' ticked':'')+'</small></button>';}).join(''):'';
  }
  function pickCluster(c){
    const list=rows().filter(r=>r.dataset.cluster===c).map(r=>r.querySelector('.px-ck')).filter(k=>k&&!k.disabled); const allOn=list.length&&list.every(k=>k.checked);
    list.forEach(k=>k.checked=!allOn); sel();
  }
  function anToggle(){anManual=true;anOpen=!anOpen;anShow();}
  function anShow(){const b=$('#anBody'); if(!b)return; b.hidden=!anOpen||!anData; $('#anTg').textContent=anOpen?'Hide ▼':'Show ▲';}
  function anSchedule(ids){
    const p=$('#anP'); if(!p)return; clearTimeout(anTimer);
    if(!ids.length){p.hidden=true;anData=null;return;}
    p.hidden=false; $('#anWait').textContent='analysing…';
    anTimer=setTimeout(()=>anRun(ids),450);
  }
  async function anRun(ids){
    const tok=++anTok, keys=ids.flatMap(i=>D[i].keys), mode=(document.querySelector('input[name=pbmode]:checked')||{}).value||'separate';
    try{const j=await post({action:'prd_preview',keys,mode}); if(tok!==anTok)return; anData=j; $('#anWait').textContent=''; anRender();}
    catch(e){if(tok!==anTok)return; anData=null; $('#anWait').textContent=''; $('#anSum').innerHTML='<span style="color:#b42318">'+esc(e.message)+'</span>'; $('#anBody').hidden=true;}
  }
  const fillCls=f=>f>100?'over':(f<MINFILL?'low':'');
  function anRender(){
    const j=anData, loads=j.loads||[], excl=j.excluded||[];
    const pk=loads.reduce((s,l)=>s+l.pkt,0), cap=loads.reduce((s,l)=>s+(l.capacity||0),0), km=loads.reduce((s,l)=>s+(l.route_km||0),0);
    const clubbed=loads.filter(l=>l.stops.length>1), alone=loads.filter(l=>l.stops.length===1), stores=loads.reduce((s,l)=>s+l.stops.length,0);
    const fill=cap?Math.round(pk*100/cap):0, veh={}; loads.forEach(l=>veh[l.vehicle]=(veh[l.vehicle]||0)+1);
    $('#anSum').innerHTML='<b>'+loads.length+'</b> trip'+(loads.length===1?'':'s')+' · <b>'+clubbed.length+'</b> clubbed, <b>'+alone.length+'</b> alone <i>·</i> avg fill <b>'+fill+'%</b> <i>·</i> ~<b>'+km.toLocaleString('en-IN')+'</b> km'+(excl.length?' <i>·</i> <span style="color:#b54708">'+excl.length+' left out</span>':'');
    const tabs=[['club','Trips & clubbing'],['cluster','By cluster'],['why','Not clubbed / left out ('+(alone.length+excl.length)+')']];
    let h='<div class="an-tabs">'+tabs.map(t=>'<button type="button" class="'+(anTab===t[0]?'on':'')+'" onclick="px.anTab(\''+t[0]+'\')">'+t[1]+'</button>').join('')+'</div>';
    h+='<div class="an-kpi"><div><b>'+loads.length+'</b><span>trips</span></div><div><b>'+stores+'</b><span>stores planned</span></div><div><b>'+pk.toLocaleString('en-IN')+'</b><span>PKT</span></div><div><b>'+fill+'%</b><span>avg vehicle fill</span></div><div><b>'+km.toLocaleString('en-IN')+'</b><span>km (DC → last store)</span></div><div><b>'+Object.entries(veh).map(([t,n])=>n+'× '+esc(t)).join(', ')+'</b><span>vehicles needed</span></div></div>';
    if(anTab==='club'){
      h+=loads.map((l,i)=>{
        const path='<div class="an-path"><span class="an-st"><b>'+esc((l.dc&&l.dc.code)||l.source)+'</b> <em>DC</em></span>'+l.stops.map(s=>'<span class="an-hop">'+(s.km!=null?s.km+' km':'km n/a')+'</span><span class="an-st"><b>'+esc(s.code)+'</b> <small>'+s.pkt+' PKT</small>'+(s.cluster&&s.cluster!=='-'?'<em>'+esc(s.cluster)+'</em>':'')+(s.part?'<em>part '+esc(s.part)+'</em>':'')+'</span>').join('')+'</div>';
        const hops=l.stops.slice(1).map(s=>s.km).filter(x=>x!=null), far=hops.length?Math.max(...hops):null, cl=[...new Set(l.stops.map(s=>s.cluster).filter(c=>c&&c!=='-'))];
        const note=l.stops.length>1?'<div class="an-ok">Clubbed: '+l.stops.map(s=>esc(s.code)).join(' + ')+(far!=null?' — stores are at most '+far+' km apart':'')+(cl.length>1?' · mixes clusters '+cl.map(esc).join(' + '):(cl.length?' · same cluster '+esc(cl[0]):''))+'.</div>':'<div class="an-why">Alone: '+esc(l.alone||'')+'</div>';
        return '<div class="an-trip '+(l.stops.length>1?'':'alone')+'"><div class="an-th"><span><b>Trip '+(i+1)+'</b> · '+esc(l.source)+' · '+esc(l.vehicle)+'</span><span><b>'+l.pkt+' PKT</b> · '+L(l.value)+' · <span class="f '+fillCls(l.fill)+'">'+l.fill+'% fill</span>'+(l.route_km!=null?' · ~'+l.route_km+' km':'')+'</span></div>'+path+note+(l.warnings||[]).map(w=>'<div class="an-why">⚠ '+esc(w)+'</div>').join('')+'</div>';
      }).join('')||'<div class="note">Nothing can be planned from this selection.</div>';
    }else if(anTab==='cluster'){
      const cs={}; loads.forEach((l,i)=>l.stops.forEach(s=>{const c=(s.cluster&&s.cluster!=='-')?s.cluster:'(no cluster)'; const o=cs[c]||(cs[c]={stores:new Set(),pkt:0,trips:new Set(),alone:0,club:0,fillSum:0,fillN:0,with:{}}); o.stores.add(s.code); o.pkt+=s.pkt; if(!o.trips.has(i)){o.trips.add(i);o.fillSum+=l.fill;o.fillN++;} if(l.stops.length>1)o.club++; else o.alone++; l.stops.forEach(t=>{const tc=(t.cluster&&t.cluster!=='-')?t.cluster:'(no cluster)'; if(tc!==c)o.with[tc]=(o.with[tc]||0)+1;});}));
      const names=Object.keys(cs).sort();
      h+=names.length?'<table class="an-tbl"><tr><th>Cluster</th><th class="n">Stores</th><th class="n">PKT</th><th class="n">Trips</th><th class="n">Clubbed stops</th><th class="n">Alone</th><th class="n">Avg fill</th><th>Also clubs with</th></tr>'+names.map(c=>{const o=cs[c];return '<tr><td><b>'+esc(c)+'</b></td><td class="n">'+o.stores.size+'</td><td class="n">'+o.pkt.toLocaleString('en-IN')+'</td><td class="n">'+o.trips.size+'</td><td class="n">'+o.club+'</td><td class="n">'+o.alone+'</td><td class="n">'+Math.round(o.fillSum/o.fillN)+'%</td><td>'+(Object.keys(o.with).length?Object.entries(o.with).map(([k,n])=>esc(k)+' ('+n+')').join(', '):'<span class="note">own cluster only</span>')+'</td></tr>';}).join('')+'</table>':'<div class="note">No planned trips.</div>';
    }else{
      if(excl.length)h+='<div class="an-excl"><b>'+excl.length+' store(s) cannot be planned</b><ul>'+excl.map(x=>'<li>'+esc(x.name)+': '+esc(x.reason)+'</li>').join('')+'</ul></div>';
      h+=alone.length?'<table class="an-tbl"><tr><th>Store</th><th class="n">PKT</th><th class="n">Fill</th><th>Why it travels alone</th></tr>'+alone.map(l=>{const s=l.stops[0];return '<tr><td><b>'+esc(s.code)+'</b> <span class="note">'+esc(s.name||'')+(s.cluster&&s.cluster!=='-'?' · '+esc(s.cluster):'')+'</span></td><td class="n">'+l.pkt+'</td><td class="n">'+l.fill+'%</td><td>'+esc(l.alone||'')+'</td></tr>';}).join('')+'</table>':'<div class="note">Every planned store is clubbed with another one.</div>';
    }
    $('#anBody').innerHTML=h; anShow();
  }
  function anTabSet(t){anTab=t; if(anData)anRender();}
  window.px={compact,filter,sel,clear,all,pickDue,open,saveNote,hold,holdSave,release,review,close,inc,upd,create,makePlan,anToggle,anTab:anTabSet,pickCluster};
  $('#clChips')?.addEventListener('click',e=>{const b=e.target.closest('.cl-chip');if(b)pickCluster(b.dataset.c);});
  chips();
  window.prd=window.px;
  filter();sel();
})();
</script>
