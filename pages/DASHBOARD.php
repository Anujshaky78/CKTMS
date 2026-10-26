<?php
/*
 * Dashboard with four tabs: Operations (trips and where they are stuck), Transport (transporter performance), In transit (what is on the road),
 * PRD (what is still waiting to be planned). Numbers come from app/services/dashboard.php; every tile links to the matching trips.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
require_once TMS_ROOT . '/app/services/dashboard.php';
$d = db(); tms_trk_autoimport($d);
$tabs = ['operations' => 'Operations', 'transport' => 'Transport', 'transit' => 'In transit', 'prd' => 'PRD'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'operations';
$ok = fn($s) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s) ? $s : null;
$to = $ok($_GET['to'] ?? '') ?: date('Y-m-d'); $from = $ok($_GET['from'] ?? '') ?: date('Y-m-d', strtotime('-15 days'));
if ($from > $to) [$from, $to] = [$to, $from];
$B = $tab === 'prd' ? null : ct_build($d, $from, $to);

// ---- small helpers for the markup
$nf = fn($n) => number_format((float)$n);
$mins = fn($m) => $m === null ? '—' : ($m >= 1440 ? round($m / 1440, 1) . ' d' : ($m >= 60 ? floor($m / 60) . ' h ' . ($m % 60) . ' m' : $m . ' min'));
$inr = fn($v) => $v >= 10000000 ? '₹' . number_format($v / 10000000, 2) . ' Cr' : ($v >= 100000 ? '₹' . number_format($v / 100000, 2) . ' L' : '₹' . number_format((float)$v));
$ct = fn(array $p = []) => 'CONTROL_TOWER.php?' . http_build_query(array_merge(['from' => $from, 'to' => $to], $p));
$trips = fn(string $st) => 'PLANNING.php?tab=trips&st=' . urlencode($st);
$self = fn(array $p = []) => 'DASHBOARD.php?' . http_build_query(array_merge(['tab' => $tab, 'from' => $from, 'to' => $to], $p));
$tile = fn(string $label, string $val, string $sub, ?string $href = null, string $cls = '', ?int $bar = null) => ($href ? '<a href="' . e($href) . '"' : '<div') . ' class="ct-tile ' . $cls . '"><span>' . e($label) . '</span><b>' . $val . '</b>' . ($bar !== null ? '<i class="ct-bar2"><u style="width:' . max(0, min(100, $bar)) . '%"></u></i>' : '') . '<small>' . e($sub) . '</small>' . ($href ? '</a>' : '</div>');
/** Horizontal bars: rows of [label, value, note?, href?] */
$bars = function (array $rows, string $unit = '', string $cls = '') use ($nf) {
  if (!$rows) return '<p class="db-none">Nothing to show</p>'; $mx = max(1, ...array_map(fn($r) => (float)$r[1], $rows)); $h = '<div class="db-bars ' . $cls . '">';
  foreach ($rows as $r) { $lab = $r[3] ?? null ? '<a href="' . e($r[3]) . '">' . e($r[0]) . '</a>' : e($r[0]); $h .= '<div class="db-bar"><span title="' . e($r[0]) . '">' . $lab . '</span><div><u style="width:' . round((float)$r[1] * 100 / $mx) . '%"></u></div><b>' . $nf($r[1]) . e($unit) . '</b>' . (isset($r[2]) && $r[2] !== '' ? '<small>' . e($r[2]) . '</small>' : '') . '</div>'; }
  return $h . '</div>';
};
/** Table: $head = [[label, numeric?]], $rows = rows of ready HTML cells */
$tbl = function (array $head, array $rows, string $empty = 'Nothing to show') {
  $h = '<div class="db-tw"><table class="db-t"><thead><tr>'; foreach ($head as $c) $h .= '<th class="' . (!empty($c[1]) ? 'n' : '') . '">' . e($c[0]) . '</th>'; $h .= '</tr></thead><tbody>';
  foreach ($rows as $r) { $h .= '<tr>'; foreach ($r as $i => $cell) $h .= '<td class="' . (!empty($head[$i][1]) ? 'n' : '') . '">' . $cell . '</td>'; $h .= '</tr>'; }
  return $h . ($rows ? '' : '<tr><td colspan="' . count($head) . '" class="none">' . e($empty) . '</td></tr>') . '</tbody></table></div>';
};
$block = fn(string $title, string $body, string $cls = '', string $note = '') => '<section class="ct-blk ' . $cls . '"><h4>' . $title . ($note !== '' ? ' <small>' . $note . '</small>' : '') . '</h4>' . $body . '</section>';
$cols = function (array $vals, array $labels, string $title = '') { $mx = max(1, ...$vals); $h = '<div class="db-cols">'; foreach ($vals as $i => $v) $h .= '<div title="' . e($labels[$i] . ': ' . $v) . '"><u style="height:' . round($v * 100 / $mx) . '%"></u><small>' . e($labels[$i]) . '</small></div>'; return $h . '</div>'; };
$tripLink = fn($id, $code) => '<a href="' . e('CONTROL_TOWER.php?sel=' . (int)$id) . '"><b>' . e($code) . '</b></a>';
$pill = fn(string $s) => '<span class="db-pill db-' . (in_array($s, ['In Transit', 'Breakdown', 'Accident'], true) ? 'transit' : ($s === 'Delivered' ? 'done' : 'open')) . '">' . e($s === 'Planned' ? 'Pending' : ($s === 'Gate Pass Created' ? 'Gate pass' : $s)) . '</span>';

head('Dashboard');
?>
<link rel="stylesheet" href="<?= e(asset('assets/css/control_tower.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/dashboard.css')) ?>">
<div class="db<?= $tab === 'prd' ? ' db-prd' : '' ?>">
  <div class="db-head">
    <nav class="db-tabs" aria-label="Dashboard"><?php foreach ($tabs as $k => $l): ?><a class="<?= $k === $tab ? 'on' : '' ?>" href="<?= e('DASHBOARD.php?' . http_build_query(['tab' => $k, 'from' => $from, 'to' => $to])) ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
    <?php if ($tab !== 'prd'): ?>
    <form class="ct-dates" method="get"><input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="date" name="from" value="<?= e($from) ?>" aria-label="From date"><span>–</span><input type="date" name="to" value="<?= e($to) ?>" aria-label="To date"><button class="ct-btn" title="Apply the dates">Go</button></form>
    <span class="db-q"><?php foreach ([0 => 'Today', 7 => '7 days', 15 => '15 days', 30 => '30 days'] as $n => $l): ?><a href="<?= e('DASHBOARD.php?' . http_build_query(['tab' => $tab, 'from' => date('Y-m-d', strtotime("-$n days")), 'to' => date('Y-m-d')])) ?>"><?= e($l) ?></a><?php endforeach; ?></span>
    <?php endif; ?>
    <a class="db-go" href="<?= e($tab === 'prd' ? 'PLANNING.php?tab=prd_pending' : $ct()) ?>"><?= $tab === 'prd' ? 'Open PRD Pending' : 'Open Control Tower' ?> →</a>
  </div>

<?php if ($tab === 'operations'): $o = dash_ops($d, $from, $to, $B); ?>
  <div class="db-tiles db-t8">
    <?= $tile('Trips created', $nf($o['created']), $nf($o['avgPkt']) . ' PKT per trip · ' . $o['avgStops'] . ' stores', $trips(''), '') ?>
    <?= $tile('Pending', $nf($o['pending']), 'created, not accepted yet', $trips('Planned'), $o['pending'] ? 'warn' : '') ?>
    <?= $tile('Assigned', $nf($o['assigned']), 'accepted, not at source', $trips('Assigned')) ?>
    <?= $tile('At source', $nf($o['atSource']), 'vehicle reached the DC', $trips('Reached at source')) ?>
    <?= $tile('Loaded', $nf($o['loaded']), 'loaded / gate pass made', $trips('Loaded')) ?>
    <?= $tile('In transit', $nf($o['transit']), 'on the road', $trips('In Transit')) ?>
    <?= $tile('Delivered', $nf($o['delivered']), 'completed trips', $trips('Delivered'), 'good') ?>
    <?= $tile('Cancelled', $nf($o['cancelled']), $o['created'] ? round($o['cancelled'] * 100 / $o['created']) . '% of created' : '', $trips('Cancelled'), $o['cancelled'] ? 'bad' : '') ?>
  </div>
  <div class="db-grid3">
    <?= $block('Created vs cancelled', $cols(array_column($o['daily'], 'c'), array_map(fn($x) => substr($x['d'], 8), $o['daily'])) . '<div class="ct-key"><i class="c"></i>created per day (last 14 days)' . (array_sum(array_column($o['daily'], 'x')) ? ' · ' . array_sum(array_column($o['daily'], 'x')) . ' cancelled' : '') . '</div>', 'db-w2') ?>
    <?= $block('Time spent in each stage', $tbl([['Stage'], ['Average', 1]], array_map(fn($r) => [e($r[0]), '<b>' . e($mins($r[1])) . '</b>'], $o['stage'])), '', 'average over the trips of this range') ?>
    <?= $block('How fast trips are accepted', $bars($o['acceptDist'])) ?>
    <?= $block('Pending trips: how old', $bars($o['pendingAge'], '', 'warnbar')) ?>
    <?= $block('Trips by weekday', $cols(array_column($o['weekday'], 1), array_column($o['weekday'], 0))) ?>
    <?= $block('Trips created by hour', $cols($o['hours'], array_map(fn($h) => $h % 3 === 0 ? (string)$h : '', range(0, 23)))) ?>
    <?= $block('Stores per trip', $bars($o['stopsPerTrip'])) ?>
    <?= $block('Load per trip', '<dl class="ct-dl"><dt>Average PKT per trip</dt><dd>' . $nf($o['avgPkt']) . ' of ' . $nf($o['maxPkt']) . '</dd><dt>Average vehicle fill</dt><dd>' . $o['avgFill'] . '%</dd><dt>Average stores per trip</dt><dd>' . $o['avgStops'] . '</dd></dl><i class="ct-bar2"><u style="width:' . min(100, $o['avgFill']) . '%"></u></i>') ?>
    <?= $block('By source DC', $tbl([['Source'], ['Trips', 1], ['Avg PKT', 1]], array_map(fn($r) => ['<b>' . e($r[0]) . '</b>', $nf($r[1]), $nf($r[2])], $o['byDc']))) ?>
    <?= $block('By vehicle type', $tbl([['Type'], ['Trips', 1], ['Avg PKT', 1], ['Fill', 1]], array_map(fn($r) => ['<b>' . e($r[0]) . '</b>', $nf($r[1]), $nf($r[2]), '<span class="' . ($r[3] < 60 ? 'warnt' : '') . '">' . $r[3] . '%</span>'], $o['byVehicleType']))) ?>
    <?= $block('Trips waiting too long in a stage', $tbl([['Trip'], ['Status'], ['Vehicle'], ['Waiting', 1]], array_map(fn($r) => [$tripLink($r['id'], $r['code']), $pill($r['status']), e($r['veh'] ?: '—'), '<b class="warnt">' . e($mins($r['min'])) . '</b>'], $o['slow']), 'No trip is stuck'), 'db-w2', 'accepted 24 h, at source 8 h, loaded 12 h or more') ?>
    <?= $block('Why trips were cancelled', $bars(array_map(fn($r) => [$r[0], $r[1]], $o['cancelReasons']), '', 'badbar') . ($o['cancelByTpt'] ? '<h5>By transporter</h5>' . $bars($o['cancelByTpt'], '', 'badbar') : '')) ?>
  </div>

<?php elseif ($tab === 'transport'): $t = dash_transport($d, $from, $to, $B); $rows = $t['rows'];
  $all = array_sum(array_column($rows, 'trips')); $acc = array_sum(array_column($rows, 'acc')); $dl = array_sum(array_map(fn($r) => $r['onTime'] === null ? 0 : 1, $rows));
  $cs = array_sum(array_map(fn($r) => $r['openDelayed'], $rows)); $aw = array_filter(array_column($rows, 'accMin'), fn($x) => $x !== null); ?>
  <div class="db-tiles db-t6">
    <?= $tile('Transporters', $nf(count(array_filter($rows, fn($r) => $r['trips'] > 0 && $r['tpt'] !== '(not set)'))), 'with trips in this range', null) ?>
    <?= $tile('Vehicles used', $nf($t['nVeh']), $nf($t['nDrv']) . ' drivers', null) ?>
    <?= $tile('Acceptance', $all ? round($acc * 100 / $all) . '%' : '—', $nf($acc) . ' of ' . $nf($all) . ' trips', null, 'good', $all ? (int)round($acc * 100 / $all) : 0) ?>
    <?= $tile('Avg acceptance time', e($mins($aw ? (int)round(array_sum($aw) / count($aw)) : null)), 'created → accepted', null) ?>
    <?= $tile('Delayed stores now', $nf($cs), 'open stores past their due time', $ct(['f' => 'delayed']), $cs ? 'bad' : '') ?>
    <?= $tile('Delivered trips', $nf(array_sum(array_column($rows, 'done'))), $nf(array_sum(array_column($rows, 'transit'))) . ' in transit', $trips('Delivered'), 'good') ?>
  </div>
  <div class="db-grid3">
    <?= $block('Transporter performance', $tbl([['Transporter'], ['Score', 1], ['Trips', 1], ['Accepted', 1], ['Avg accept time', 1], ['Delivered', 1], ['In transit', 1], ['On-time stores', 1], ['Delayed stores', 1], ['Cancelled', 1], ['Avg PKT', 1], ['Vehicles', 1], ['Drivers', 1], ['Avg transit', 1]],
        array_map(fn($r) => ['<b>' . e($r['tpt']) . '</b>', '<span class="db-score ' . ($r['score'] >= 80 ? 'g' : ($r['score'] >= 60 ? 'a' : 'r')) . '">' . $r['score'] . '</span>', $nf($r['trips']), $r['acc'] . ' <small>' . $r['accPct'] . '%</small>', e($mins($r['accMin'])), $nf($r['done']), $nf($r['transit']), $r['onTime'] === null ? '<small>no delivery yet</small>' : $r['onTime'] . '%', '<span class="' . ($r['openDelayed'] ? 'badt' : '') . '">' . $r['openDelayed'] . '</span>', $r['cancelled'] . ' <small>' . $r['cancPct'] . '%</small>', $nf($r['avgPkt']), $r['veh'], $r['drv'], $r['hrs'] === null ? '—' : $r['hrs'] . ' h'], $rows), 'No trips in this range'), 'db-w3', 'score = 40% acceptance + 40% on-time stores + 20% not cancelled') ?>
    <?= $block('Score ranking', $bars(array_map(fn($r) => [$r['tpt'], $r['score'], $r['trips'] . ' trips'], array_values(array_filter($rows, fn($r) => $r['trips'] + $r['cancelled'] > 0))), '', 'goodbar')) ?>
    <?= $block('Trips per transporter', $bars(array_map(fn($r) => [$r['tpt'], $r['trips'], $nf($r['pkt']) . ' PKT'], array_values(array_filter($rows, fn($r) => $r['trips'] > 0))))) ?>
    <?= $block('Most used vehicles', $tbl([['Vehicle'], ['Transporter'], ['Trips', 1], ['Delivered', 1], ['PKT', 1]], array_map(fn($k, $v) => ['<b>' . e($k) . '</b>', e($v['tpt'] ?: '—'), $nf($v['trips']), $nf($v['done']), $nf($v['pkt'])], array_keys($t['vehicles']), $t['vehicles']), 'No vehicles yet')) ?>
    <?= $block('Most active drivers', $tbl([['Driver'], ['Trips', 1], ['Delivered', 1], ['PKT', 1]], array_map(fn($k, $v) => ['<b>' . e($k) . '</b>', $nf($v['trips']), $nf($v['done']), $nf($v['pkt'])], array_keys($t['drivers']), $t['drivers']), 'No drivers yet')) ?>
  </div>

<?php elseif ($tab === 'transit'): $x = dash_transit($d, $B); $v = $x['veh']; ?>
  <div class="db-tiles db-t8">
    <?= $tile('Trips in transit', $nf($x['trips']), $x['breakdown'] ? $x['breakdown'] . ' breakdown / accident' : 'on the road now', $ct(['f' => 'transit']), $x['breakdown'] ? 'bad' : '') ?>
    <?= $tile('Stores in transit', $nf($x['stores']), 'still to be delivered', $ct(['f' => 'transit'])) ?>
    <?= $tile('PKT on the road', $nf($x['pkt']), 'for those stores', null) ?>
    <?= $tile('Moving now', $nf($v['moving']), $x['avgSpeed'] !== null ? 'avg ' . $x['avgSpeed'] . ' km/h' : 'vehicles', null, 'good') ?>
    <?= $tile('Stopped', $nf($v['stopped']), 'vehicles not moving', null) ?>
    <?= $tile('No signal', $nf($v['nosignal']), 'GPS older than a day', null, $v['nosignal'] ? 'warn' : '') ?>
    <?= $tile('Not in GPS reports', $nf($v['unknown']), 'position not available', null) ?>
    <?= $tile('Km driven today', $nf($x['km']), 'by vehicles in transit (R-TRAC)', null) ?>
  </div>
  <div class="db-grid3">
    <?= $block('ETA of stores in transit', $bars(array_map(fn($k, $n) => [$k, $n], array_keys($x['eta']), $x['eta']), '', 'etabar')) ?>
    <?= $block('Stores in transit by state', $bars(array_map(fn($k, $n) => [$k, $n], array_keys($x['byState']), $x['byState']))) ?>
    <?= $block('Stores in transit by cluster', $bars(array_map(fn($k, $n) => [$k, $n], array_keys($x['byCluster']), $x['byCluster']))) ?>
    <?= $block('Trips in transit', $tbl([['Trip'], ['Vehicle'], ['Driver'], ['GPS'], ['Last update'], ['Km today', 1], ['Next store'], ['ETA'], ['Stores left', 1], ['PKT', 1]],
        array_map(fn($r) => [$tripLink($r['id'], $r['code']) . ' ' . $pill($r['status']), e($r['veh'] ?: '—'), e($r['driver'] ?: '—'), $r['state'] ? '<span class="db-pill ' . ($r['state'] === 'RUNNING' ? 'db-done' : 'db-open') . '">' . e($r['state']) . ($r['speed'] !== null ? ' · ' . $r['speed'] . ' km/h' : '') . '</span>' : '<small>not in GPS reports</small>', e($r['age'] ?: '—'), $r['dayKm'] !== null ? $nf($r['dayKm']) : '—', e($r['next'] ?: '—'), $r['eta'] ? '<span class="' . ($r['late'] ? 'badt' : '') . '">' . e(date('d M H:i', strtotime($r['eta']))) . '</span>' : '—', $nf($r['open']), $nf($r['pkt'])], $x['rows']), 'No trip is in transit right now'), 'db-w3') ?>
    <?= $block('Ready to leave: waiting at the DC', $tbl([['Trip'], ['Status'], ['Source'], ['Vehicle'], ['Driver'], ['Stores', 1], ['PKT', 1], ['Waiting', 1]],
        array_map(fn($r) => [$tripLink($r['id'], $r['code']), $pill($r['status']), e($r['dc'] ?: '—'), e($r['veh'] ?: '—'), e($r['driver'] ?: '—'), $nf($r['stores']), $nf($r['pkt']), '<b class="' . ($r['min'] >= 720 ? 'warnt' : '') . '">' . e($mins($r['min'])) . '</b>'], $x['ready']), 'Nothing is waiting'), 'db-w3', 'accepted trips that have not left yet') ?>
  </div>

<?php else: $p = dash_prd($d); $jf = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE; ?>
  <div class="prd-top">
    <p class="prd-head" id="prdHead">Loading…</p>
    <div class="prd-sel" id="prdSel">
      <div class="db-seg"><span>Division</span><button type="button" class="on" data-k="m" data-v="t">All</button><button type="button" data-k="m" data-v="a">APPS</button><button type="button" data-k="m" data-v="g">GM</button></div>
      <div class="db-seg"><span>Source</span><button type="button" class="on" data-k="s" data-v="all">All</button><?php foreach ($p['sources'] as $i => $sx): ?><button type="button" data-k="s" data-v="<?= (int)$i ?>" title="<?= e($sx['name']) ?>"><?= e($sx['site']) ?></button><?php endforeach; ?></div>
      <div class="db-seg"><span>Stores</span><button type="button" class="on" data-k="c" data-v="all">All</button><button type="button" data-k="c" data-v="A">Active</button><button type="button" data-k="c" data-v="U">Upcoming</button><button type="button" data-k="c" data-v="O">Inactive</button></div>
    </div>
  </div>
  <div class="prd-chips" id="prdChips"></div>
  <div class="db-grid3">
    <?= $block('Age group wise: active and upcoming stores', '<p class="prd-help">Pending PKT by days since the challan was made (today − challan date). The first block follows the three selections above; “By store status” follows Division and Source; “By source” follows Division and Stores. Shaded rows are older than 30 days.</p><div id="prdAge"></div>', 'db-w3') ?>
    <?= $block('Pending by source', '<p class="prd-help">Each warehouse (follows Division and Stores).</p><div id="prdSrc"></div>', 'db-w2') ?>
    <?= $block('Active and upcoming stores', '<p class="prd-help">Pending PKT by store status (follows Division and Source).</p><div id="prdStat"></div>') ?>
    <?= $block('Top active stores with challans older than 30 days', '<p class="prd-help">ACTIVE stores only (follows Division and Source). PKT, value and qty are of the pending stock of the store; the four age columns split its PKT older than 30 days.</p><div class="db-seg" data-for="top"><button type="button" class="on" data-t="p">By PKT</button><button type="button" data-t="v">By value</button></div><div id="prdTop"></div>', 'db-w3') ?>
    <?= $block('Cluster and state wise', '<p class="prd-help">Pending PKT and value of Active, Upcoming and Inactive stores (follows Division and Source).</p><div class="db-seg" data-for="cs"><button type="button" class="on" data-t="c">Cluster</button><button type="button" data-t="s">State</button></div><div id="prdCs"></div>', 'db-w3') ?>
  </div>
  <p class="db-note">Read from the pending challans in the database (last refresh: <?= $p['sync'] ? e(date('d M, H:i', strtotime((string)$p['sync']))) : 'never' ?>). Age = today's date − challan date. “PKT” is the pending challan count. A challan holding both divisions is counted in both APPS and GM, so APPS + GM can be a little more than All. Source, store, state, cluster, division and store status are the values that came with the Oracle data.</p>
  <script>
  (function(){
  'use strict';
  var D=<?= json_encode($p, $jf) ?>, MI={t:0,a:1,g:2}, st={m:'t',s:'all',c:'all',top:'p',cs:'c'}, CN={A:'Active',U:'Upcoming',O:'Inactive'};
  function $(id){return document.getElementById(id);}
  function nf(n){return Number(n||0).toLocaleString('en-US');}
  function cv(v){v=+v||0;return v>=1e7?(v/1e7).toFixed(2)+' Cr':(v>=1e5?(v/1e5).toFixed(1)+' L':nf(Math.round(v)));}
  function pc(a,b){return b>0?(a*100/b).toFixed(1)+'%':'—';}
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function mi(){return MI[st.m];}
  // cells: [source, status, age group, PKT, qty, value for all, APPS, GM]
  function sum(o){var i=mi(),a=[0,0,0];D.cells.forEach(function(r){
      if(o.site==='sel'){if(st.s!=='all'&&r[0]!==+st.s)return;}else if(o.site!=='any'&&r[0]!==o.site)return;
      if(o.cls==='sel'){if(st.c!=='all'&&r[1]!==st.c)return;}else if(o.cls!=='any'&&r[1]!==o.cls)return;
      if(o.g&&o.g.indexOf(r[2])<0)return;
      a[0]+=r[3+3*i];a[1]+=r[4+3*i];a[2]+=r[5+3*i];});return a;}
  function stores(site,cls){var i=mi(),seen={},n=0;D.stores.forEach(function(r){
      if(site==='sel'){if(st.s!=='all'&&r[1]!==+st.s)return;}else if(site!=='any'&&r[1]!==site)return;
      if(cls==='sel'){if(st.c!=='all'&&r[2]!==st.c)return;}else if(cls!=='any'&&r[2]!==cls)return;
      if(i===1&&!r[3])return;if(i===2&&!r[4])return;if(!seen[r[0]]){seen[r[0]]=1;n++;}});return n;}
  function selText(){var p=[];p.push({t:'All divisions',a:'APPS only',g:'GM only'}[st.m]);p.push(st.s==='all'?'all sources':'source '+D.sources[+st.s].site);p.push(st.c==='all'?'all stores':CN[st.c]+' stores');return p.join(' · ');}
  function td(v,c){return '<td class="'+(c||'n')+'">'+v+'</td>';}
  function th(v,c){return '<th class="'+(c||'n')+'">'+v+'</th>';}
  function table(head,rows,empty,cols){return '<div class="db-tw"><table class="db-t"><thead>'+head+'</thead><tbody>'+(rows||'<tr><td colspan="'+(cols||8)+'" class="none">'+(empty||'Nothing to show')+'</td></tr>')+'</tbody></table></div>';}
  var OLD=[3,4,5,6];
  function renderHead(){
    var t=sum({site:'sel',cls:'sel'}),o30=sum({site:'sel',cls:'sel',g:OLD}),o90=sum({site:'sel',cls:'sel',g:[6]}),n=stores('sel','sel');
    $('prdHead').innerHTML='<b>'+nf(t[0])+' PKT</b> worth <b>₹'+cv(t[2])+'</b> are pending in <b>'+nf(n)+' stores</b> <small>('+esc(selText())+')</small>. <b>'+pc(o30[0],t[0])+'</b> ('+nf(o30[0])+' PKT) is older than 30 days and <b>'+pc(o90[0],t[0])+'</b> ('+nf(o90[0])+' PKT) is older than 90 days.';
    function chip(l,v,c){return '<div class="prd-chip '+(c||'')+'"><span>'+l+'</span><b>'+v+'</b></div>';}
    $('prdChips').innerHTML=chip('Pending PKT',nf(t[0]))+chip('Value',cv(t[2]))+chip('Qty',nf(t[1]))+chip('Stores',nf(n))+chip('Older than 30 days',nf(o30[0])+' PKT · '+cv(o30[2]),'warn')+chip('Older than 90 days',nf(o90[0])+' PKT · '+cv(o90[2]),'bad')+chip('Oldest challan',D.oldestAge===null?'—':nf(D.oldestAge)+' days');}
  function renderAge(){
    var src=D.sources,tot=sum({site:'sel',cls:'sel'}),h='<tr><th rowspan="2">Age group</th><th class="n grp" colspan="4">Selected</th><th class="n grp" colspan="6">By store status</th><th class="n grp" colspan="'+(src.length*2)+'">By source</th></tr><tr>'
      +th('PKT','n grp')+th('% of PKT')+th('Value')+th('Qty')+th('Active PKT','n grp')+th('Value')+th('Upcoming PKT','n grp')+th('Value')+th('Inactive PKT','n grp')+th('Value');
    src.forEach(function(x){h+=th(x.site+' PKT','n grp')+th('Value');}); h+='</tr>';
    var rows='',hasNone=sum({site:'sel',cls:'sel',g:[7]})[0]>0;
    function line(lab,g,cls){
      var s=sum({site:'sel',cls:'sel',g:g}),A=sum({site:'sel',cls:'A',g:g}),U=sum({site:'sel',cls:'U',g:g}),O=sum({site:'sel',cls:'O',g:g});
      var r='<tr class="'+cls+'"><td><b>'+lab+'</b></td>'+td(nf(s[0]),'n grp')+td(g?pc(s[0],tot[0]):'100%')+td(cv(s[2]))+td(nf(s[1]))+td(nf(A[0]),'n grp')+td(cv(A[2]))+td(nf(U[0]),'n grp')+td(cv(U[2]))+td(nf(O[0]),'n grp')+td(cv(O[2]));
      src.forEach(function(x,i){var v=sum({site:i,cls:'sel',g:g});r+=td(nf(v[0]),'n grp')+td(cv(v[2]));});return r+'</tr>';}
    D.labels.forEach(function(l,g){if(g===7&&!hasNone)return;rows+=line(esc(l),[g],g>=5&&g<=6?'badr':(g===3||g===4?'warnr':''));});
    rows+=line('Total',null,'totr');
    $('prdAge').innerHTML=table(h,rows,'',12+src.length*2);}
  function renderSrc(){
    var h='<tr>'+th('Source','')+th('PKT')+th('Value')+th('Qty')+th('Stores')+th('Older than 30 days')+'</tr>',rows='';
    D.sources.forEach(function(x,i){var v=sum({site:i,cls:'sel'}),o=sum({site:i,cls:'sel',g:OLD});rows+='<tr class="'+(st.s===String(i)?'selr':'')+'"><td><b>'+esc(x.site)+'</b> <small>'+esc(x.name)+'</small></td>'+td(nf(v[0]))+td(cv(v[2]))+td(nf(v[1]))+td(nf(stores(i,'sel')))+td(nf(o[0])+' <small>('+pc(o[0],v[0])+')</small>')+'</tr>';});
    var t=sum({site:'any',cls:'sel'}),o=sum({site:'any',cls:'sel',g:OLD});
    rows+='<tr class="totr"><td><b>Total</b></td>'+td('<b>'+nf(t[0])+'</b>')+td('<b>'+cv(t[2])+'</b>')+td(nf(t[1]))+td(nf(stores('any','sel')))+td(nf(o[0])+' <small>('+pc(o[0],t[0])+')</small>')+'</tr>';
    $('prdSrc').innerHTML=table(h,rows,'',6);}
  function renderStat(){
    var all=sum({site:'sel',cls:'any'}),h='<tr>'+th('Store status','')+th('Stores')+th('PKT')+th('% of PKT')+th('Value')+'</tr>',rows='';
    ['A','U','O'].forEach(function(c){var v=sum({site:'sel',cls:c});rows+='<tr class="'+(st.c===c?'selr':'')+'"><td><b>'+CN[c]+'</b></td>'+td(nf(stores('sel',c)))+td(nf(v[0]))+td(pc(v[0],all[0]))+td(cv(v[2]))+'</tr>';});
    $('prdStat').innerHTML=table(h,rows,'',5);}
  // top rows: [store,state,cluster,source, PKT,qty,value x3 modes, buckets 31-45,46-60,61-90,91+ x3 modes, oldest]
  function renderTop(){
    var i=mi(),m={};D.top.forEach(function(r){if(st.s!=='all'&&r[3]!==+st.s)return;var p=r[4+3*i];if(!p)return;var x=m[r[0]]=m[r[0]]||{n:r[0],s:r[1],c:r[2],src:[],p:0,q:0,v:0,b:[0,0,0,0],o:0};
      x.p+=p;x.q+=r[5+3*i];x.v+=r[6+3*i];for(var k=0;k<4;k++)x.b[k]+=r[13+4*i+k];x.o=Math.max(x.o,r[25]);var sx=D.sources[r[3]].site;if(x.src.indexOf(sx)<0)x.src.push(sx);});
    var list=Object.keys(m).map(function(k){return m[k];});list.sort(function(a,b){return st.top==='v'?(b.v-a.v||b.p-a.p):(b.p-a.p||b.v-a.v);});
    var h='<tr>'+th('Store','')+th('State','')+th('Cluster','')+th('Source','')+th('PKT','n grp')+th('Value')+th('Qty')+th('31–45 d','n grp')+th('46–60 d')+th('61–90 d')+th('91+ d')+th('Oldest','n grp')+'</tr>',rows='';
    list.slice(0,15).forEach(function(x){rows+='<tr><td><b>'+esc(x.n)+'</b></td><td>'+esc(x.s)+'</td><td>'+esc(x.c)+'</td><td>'+esc(x.src.join(', '))+'</td>'+td('<b>'+nf(x.p)+'</b>','n grp')+td('<b>'+cv(x.v)+'</b>')+td(nf(x.q))+td(nf(x.b[0]),'n grp')+td(nf(x.b[1]))+td(nf(x.b[2]))+td('<b class="badt">'+nf(x.b[3])+'</b>')+td(x.o+' d','n grp')+'</tr>';});
    $('prdTop').innerHTML='<p class="prd-help prd-count">Top 15 of '+nf(list.length)+' active stores.</p>'+table(h,rows,'No active store holds a challan older than 30 days',12);}
  // cluster / state rows: [name, status, source, PKT and value for all / APPS / GM]
  function renderCs(){
    var i=mi(),rowsSrc=D[st.cs==='s'?'state':'cluster'],m={};rowsSrc.forEach(function(r){if(st.s!=='all'&&r[2]!==+st.s)return;var x=m[r[0]]=m[r[0]]||{n:r[0],A:[0,0],U:[0,0],O:[0,0]};x[r[1]][0]+=r[3+2*i];x[r[1]][1]+=r[4+2*i];});
    var list=Object.keys(m).map(function(k){var x=m[k];x.p=x.A[0]+x.U[0]+x.O[0];x.v=x.A[1]+x.U[1]+x.O[1];return x;}).filter(function(x){return x.p>0;}).sort(function(a,b){return b.p-a.p;});
    var h='<tr><th rowspan="2">'+(st.cs==='s'?'State':'Cluster')+'</th><th class="n grp" colspan="2">Active</th><th class="n grp" colspan="2">Upcoming</th><th class="n grp" colspan="2">Inactive</th><th class="n grp" colspan="2">Total</th></tr><tr>'+th('PKT','n grp')+th('Value')+th('PKT','n grp')+th('Value')+th('PKT','n grp')+th('Value')+th('PKT','n grp')+th('Value')+'</tr>',rows='';
    list.forEach(function(x){rows+='<tr><td><b>'+esc(x.n)+'</b></td>'+td(nf(x.A[0]),'n grp')+td(cv(x.A[1]))+td(nf(x.U[0]),'n grp')+td(cv(x.U[1]))+td(nf(x.O[0]),'n grp')+td(cv(x.O[1]))+td('<b>'+nf(x.p)+'</b>','n grp')+td('<b>'+cv(x.v)+'</b>')+'</tr>';});
    $('prdCs').innerHTML=table(h,rows,'',9);}
  function render(){renderHead();renderAge();renderSrc();renderStat();renderTop();renderCs();}
  document.addEventListener('click',function(e){var b=e.target.closest('.prd-sel button');
    if(b){st[b.dataset.k]=b.dataset.v;b.parentElement.querySelectorAll('button').forEach(function(x){x.classList.toggle('on',x===b);});render();return;}
    b=e.target.closest('.db-seg[data-for] button');if(!b)return;var g=b.parentElement.dataset['for'];st[g]=b.dataset.t;b.parentElement.querySelectorAll('button').forEach(function(x){x.classList.toggle('on',x===b);});if(g==='top')renderTop();else renderCs();});
  render();
  })();
  </script><?php endif; ?>
</div>
<?php foot(); ?>
