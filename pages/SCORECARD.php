<?php
/* Transporter scorecard: vehicles required vs placed, TD and MTD. ?month=YYYY-MM&day=YYYY-MM-DD */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$d = db();
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['day'] ?? '')) ? $_GET['day'] : date('Y-m-d');
$mStart = date('Y-m-01', strtotime($day)); $mEnd = $day;

/* Required = vehicle requests raised (requests cancelled because the trip was cancelled are shown, not counted). */
$sql = "SELECT tp.id, tp.name,
  COUNT(vp.id) FILTER (WHERE vp.status<>'Cancelled') req,
  COUNT(vp.id) FILTER (WHERE vp.confirmed_at IS NOT NULL AND vp.status<>'Cancelled') conf,
  COUNT(vp.id) FILTER (WHERE vp.placed_at IS NOT NULL) placed,
  COUNT(vp.id) FILTER (WHERE vp.status='Failed') failed,
  COUNT(vp.id) FILTER (WHERE vp.status='Cancelled') cancelled,
  COUNT(vp.id) FILTER (WHERE vp.status IN ('Requested','Confirmed')) open_,
  AVG(EXTRACT(EPOCH FROM (vp.confirmed_at - vp.requested_at))/3600) FILTER (WHERE vp.confirmed_at IS NOT NULL) h_conf,
  AVG(EXTRACT(EPOCH FROM (vp.placed_at - vp.requested_at))/3600) FILTER (WHERE vp.placed_at IS NOT NULL) h_place
  FROM tms_transporters tp LEFT JOIN tms_vehicle_placements vp ON vp.transporter_id=tp.id AND vp.requested_at::date BETWEEN ? AND ?
  GROUP BY tp.id, tp.name";
$run = function ($a, $b) use ($d, $sql) { $q = $d->prepare($sql); $q->execute([$a, $b]); $o = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['id']] = $r; return $o; };
$td = $run($day, $day); $mtd = $run($mStart, $mEnd);
$moved = []; $mq = $d->prepare("SELECT transporter_id, COUNT(*) trips, COALESCE(SUM(COALESCE(loaded_packets,total_boxes)),0) pkt FROM trips WHERE dispatched_at::date BETWEEN ? AND ? AND transporter_id IS NOT NULL GROUP BY 1");
$mq->execute([$mStart, $mEnd]); foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $r) $moved[(int)$r['transporter_id']] = $r;
$reasons = $d->prepare("SELECT tp.name, vp.fail_reason, COUNT(*) n FROM tms_vehicle_placements vp JOIN tms_transporters tp ON tp.id=vp.transporter_id WHERE vp.status='Failed' AND vp.requested_at::date BETWEEN ? AND ? GROUP BY 1,2 ORDER BY 3 DESC");
$reasons->execute([$mStart, $mEnd]); $reasons = $reasons->fetchAll(PDO::FETCH_ASSOC);
$trend = $d->prepare("SELECT g::date dt, COUNT(vp.id) FILTER (WHERE vp.status<>'Cancelled') req, COUNT(vp.id) FILTER (WHERE vp.placed_at IS NOT NULL) placed, COUNT(vp.id) FILTER (WHERE vp.status='Failed') failed
  FROM generate_series(?::date, ?::date, interval '1 day') g LEFT JOIN tms_vehicle_placements vp ON vp.requested_at::date=g::date GROUP BY 1 ORDER BY 1");
$trend->execute([$mStart, $mEnd]); $trend = $trend->fetchAll(PDO::FETCH_ASSOC);
$pct = fn($a, $b) => $b > 0 ? round($a * 100 / $b) : null;
$rows = [];
foreach ($mtd as $id => $m) { $t = $td[$id] ?? []; if (!(int)$m['req'] && !(int)($t['req'] ?? 0) && !(int)$m['cancelled'] && empty($moved[$id])) continue; $rows[] = ['m' => $m, 't' => $t, 'mv' => $moved[$id] ?? null, 'score' => $pct((int)$m['placed'], (int)$m['req']) ?? -1]; }
usort($rows, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['m']['name'], $b['m']['name']));
$tot = fn($set, $k) => array_sum(array_map(fn($r) => (int)($r[$k] ?? 0), $set));
$maxTrend = max(1, ...array_map(fn($r) => (int)$r['req'], $trend ?: [['req' => 1]]));
$h = fn($v) => $v === null ? '—' : ($v < 1 ? round($v * 60) . ' min' : number_format((float)$v, 1) . ' h');
$band = fn($p) => $p === null ? '' : ($p >= 95 ? 'b-green' : ($p >= 85 ? 'b-amber' : 'b-red'));
head('Transporter scorecard');
?>
<style>
.sc-bar{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.sc-sum{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:14px}
.sc-sum .card{margin:0}.sc-sum .row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:8px}
.sc-sum b{display:block;font-size:22px;font-variant-numeric:tabular-nums}.sc-sum span{font-size:11.5px;color:var(--muted)}
.sc-t th.grp{text-align:center;background:#eef2f7}.sc-t td{font-variant-numeric:tabular-nums}
.trend{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(18px,1fr);gap:4px;align-items:end;height:120px;padding-top:8px}
.trend div{position:relative;display:flex;flex-direction:column-reverse;height:100%}
.trend i{display:block;background:#c7d7fe;border-radius:3px 3px 0 0}.trend i.p{background:var(--blue);position:absolute;bottom:0;left:0;right:0}
.trend span{position:absolute;bottom:-16px;left:0;right:0;text-align:center;font-size:9.5px;color:var(--muted)}
.lg{display:flex;gap:14px;font-size:11.5px;color:var(--muted);margin-top:22px}.lg i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px;vertical-align:-1px}
@media(max-width:1000px){.sc-sum{grid-template-columns:1fr}}
</style>
<div class="sc-bar">
  <div></div>
  <form method="get" class="toolbar" style="margin:0"><label class="note">As of</label><input class="input" type="date" name="day" value="<?= e($day) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()"><a class="btn light" href="DISPATCH.php">Dispatch desk</a></form>
</div>
<div class="sc-sum">
<?php foreach ([['Today (TD)', $td, date('d M Y', strtotime($day))], ['Month to date (MTD)', $mtd, date('d M', strtotime($mStart)) . ' – ' . date('d M Y', strtotime($mEnd))]] as [$lbl, $set, $range]): $rq = $tot($set, 'req'); $pl = $tot($set, 'placed'); $fl = $tot($set, 'failed'); ?>
  <section class="card"><div class="modulebar" style="margin:0"><div class="section-title"><?= e($lbl) ?></div><span class="note"><?= e($range) ?></span></div>
    <div class="row"><div><b><?= $rq ?></b><span>Vehicles required</span></div><div><b><?= $pl ?></b><span>Actually placed</span></div><div><b style="color:<?= $fl ? 'var(--red)' : 'inherit' ?>"><?= $fl ?></b><span>Failed</span></div><div><b><?= ($p = $pct($pl, $rq)) === null ? '—' : $p . '%' ?></b><span>Placement %</span></div></div></section>
<?php endforeach; ?>
</div>

<div class="tablewrap" style="margin-bottom:14px"><table class="table sc-t">
<tr><th rowspan="2">Transporter</th><th class="grp" colspan="3">Today</th><th class="grp" colspan="7">Month to date</th><th class="grp" colspan="2">Dispatched MTD</th></tr>
<tr><th class="right">Req.</th><th class="right">Placed</th><th class="right">%</th><th class="right">Req.</th><th class="right">Confirmed</th><th class="right">Placed</th><th class="right">Failed</th><th class="right">Placement %</th><th class="right">Avg confirm</th><th class="right">Avg to place</th><th class="right">Trips</th><th class="right">PKT</th></tr>
<?php foreach ($rows as $r): $m = $r['m']; $t = $r['t']; $tp = $pct((int)($t['placed'] ?? 0), (int)($t['req'] ?? 0)); $mp = $pct((int)$m['placed'], (int)$m['req']); ?>
<tr><td><b><?= e($m['name']) ?></b><?= (int)$m['open_'] ? '<div class="note">' . (int)$m['open_'] . ' still open</div>' : '' ?><?= (int)$m['cancelled'] ? '<div class="note">' . (int)$m['cancelled'] . ' closed by trip cancel</div>' : '' ?></td>
  <td class="right"><?= (int)($t['req'] ?? 0) ?></td><td class="right"><?= (int)($t['placed'] ?? 0) ?></td><td class="right"><?= $tp === null ? '—' : '<span class="badge ' . $band($tp) . '">' . $tp . '%</span>' ?></td>
  <td class="right"><?= (int)$m['req'] ?></td><td class="right"><?= (int)$m['conf'] ?></td><td class="right"><?= (int)$m['placed'] ?></td><td class="right" style="color:<?= (int)$m['failed'] ? 'var(--red)' : 'inherit' ?>"><?= (int)$m['failed'] ?></td>
  <td class="right"><?= $mp === null ? '—' : '<span class="badge ' . $band($mp) . '">' . $mp . '%</span>' ?></td><td class="right"><?= $h($m['h_conf'] !== null ? (float)$m['h_conf'] : null) ?></td><td class="right"><?= $h($m['h_place'] !== null ? (float)$m['h_place'] : null) ?></td>
  <td class="right"><?= (int)($r['mv']['trips'] ?? 0) ?></td><td class="right"><?= number_format((int)($r['mv']['pkt'] ?? 0)) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="13" class="note" style="padding:22px;text-align:center">No vehicle requests this month yet. Requests are raised on the Dispatch desk.</td></tr><?php endif; ?>
</table></div>

<div class="grid2">
  <section class="card"><div class="section-title">MTD daily: required vs placed</div>
    <div class="trend" role="img" aria-label="Daily required versus placed vehicles"><?php foreach ($trend as $r): $rq = (int)$r['req']; $pl = (int)$r['placed']; ?><div title="<?= e(date('d M', strtotime($r['dt']))) ?>: <?= $rq ?> required, <?= $pl ?> placed, <?= (int)$r['failed'] ?> failed"><i style="height:<?= round($rq * 100 / $maxTrend) ?>%"></i><i class="p" style="height:<?= round($pl * 100 / $maxTrend) ?>%"></i><span><?= e(date('j', strtotime($r['dt']))) ?></span></div><?php endforeach; ?></div>
    <div class="lg"><span><i style="background:#c7d7fe"></i>Required</span><span><i style="background:var(--blue)"></i>Placed</span></div></section>
  <section class="card"><div class="section-title">Failure reasons (MTD)</div>
    <div class="tablewrap" style="box-shadow:none;margin-top:8px"><table class="table" style="min-width:0"><tr><th>Transporter</th><th>Reason</th><th class="right">Count</th></tr>
    <?php foreach ($reasons as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['fail_reason'] ?: '—') ?></td><td class="right"><?= (int)$r['n'] ?></td></tr><?php endforeach; if (!$reasons): ?><tr><td colspan="3" class="note">No failures this month.</td></tr><?php endif; ?></table></div></section>
</div>
<?php foot(); ?>
