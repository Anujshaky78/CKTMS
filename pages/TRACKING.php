<?php
/* Vehicle tracking: latest position, status and day summary of every vehicle, read from the G-TRAC / R-TRAC report files. */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
require_once TMS_ROOT . '/app/services/tracking.php';
$d = db(); $msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf(); require_perm('plan');
  if (($_POST['action'] ?? '') === 'trk_import') {
    $r = tms_trk_import($d, true);
    if ($r['ok']) setflash('Tracking updated from the report files. ' . $r['message']); else setflash('Tracking update: ' . $r['message']);
    redirect('TRACKING.php');
  }
}
tms_trk_autoimport($d);
$T = tms_trk_latest($d);
$trips = [];   // the active trip of each vehicle
foreach ($d->query("SELECT t.id, t.vehicle_id, t.trip_code, t.status FROM trips t WHERE t.vehicle_id IS NOT NULL AND t.status NOT IN ('Delivered','Cancelled') ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC) as $x) $trips[(int)$x['vehicle_id']] = $x;
$vehId = []; foreach ($d->query("SELECT id, vehicle_number FROM vehicles")->fetchAll(PDO::FETCH_ASSOC) as $v) $vehId[tms_trk_norm((string)$v['vehicle_number'])] = (int)$v['id'];
$last = $d->query("SELECT v, updated_at FROM tms_meta WHERE k='trk_last'")->fetch(PDO::FETCH_ASSOC);
$files = []; foreach ((array)tms_trk_cfg()['files'] as $f) $files[] = ['src' => $f['source'], 'time' => is_file($f['path']) ? (int)filemtime($f['path']) : null];

$group = function (array $o): string { $s = $o['state']; return $s === 'RUNNING' ? 'Running' : (in_array($s, ['STOPPED', 'IDLE', 'NO MOVEMENT'], true) ? 'Stopped' : (in_array($s, ['NO SIGNAL', 'NOT WORKING'], true) ? 'No signal' : 'Other')); };
$cnt = ['Running' => 0, 'Stopped' => 0, 'No signal' => 0, 'Other' => 0]; foreach ($T as $o) $cnt[$group($o)]++;
uasort($T, fn($a, $b) => [$group($a) === 'Running' ? 0 : 1, strtolower((string)$a['vehicle_number'])] <=> [$group($b) === 'Running' ? 0 : 1, strtolower((string)$b['vehicle_number'])]);
$statuses = [['v' => 'all', 'l' => 'All vehicles', 'in' => ['Running', 'Stopped', 'No signal', 'Other']], ['v' => 'Running', 'l' => 'Running (' . $cnt['Running'] . ')'], ['v' => 'Stopped', 'l' => 'Stopped (' . $cnt['Stopped'] . ')'], ['v' => 'No signal', 'l' => 'No signal (' . $cnt['No signal'] . ')'], ['v' => 'Other', 'l' => 'Day report only (' . $cnt['Other'] . ')']];
$canRefresh = can('plan');
head('Tracking');
?>
<link rel="stylesheet" href="<?= e(asset('assets/css/dt.css')) ?>">
<script src="<?= e(asset('assets/js/dt.js')) ?>" defer></script>
<style>
.top{margin-bottom:4px!important;padding-top:8px!important;padding-bottom:8px!important}
.dt-bar{gap:6px!important;margin:0 0 8px!important}.dt-bar .dt-btn,.dt-bar select,.dt-search input{height:32px!important;font-size:12px!important}.dt-bar .dt-btn{padding:0 10px!important}.dt-search{max-width:240px!important;flex:0 1 220px!important}
#dtTrk .dt-wrap{max-height:calc(100vh - 175px)}#dtTrk .dt-table{table-layout:fixed;font-size:12px}
#dtTrk .dt-table th{padding:7px 14px 7px 8px;font-size:11px;background:#eef3fb;color:#1d2f52}
#dtTrk .dt-table td{padding:4px 8px;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tk-note{font-size:11.5px;color:#667085;margin:0 0 6px}
</style>
<?php flash(); ?>
<p class="tk-note">Read from the report files:
  <?php foreach ($files as $f): ?><b><?= e($f['src']) ?></b> <?= $f['time'] ? e(tms_trk_fmt((int)$f['time'])) : '<span class="tk-lostt">file not found</span>' ?> &nbsp;·&nbsp; <?php endforeach; ?>
  <?php if ($last): ?>last import <?= e(date('d M, H:i', strtotime((string)$last['updated_at']))) ?><?= str_starts_with((string)$last['v'], 'bad|') ? ' <span class="tk-lostt" title="' . e(substr((string)$last['v'], 4)) . '">(with errors)</span>' : '' ?><?php endif; ?>
  · The page updates itself when a report file changes.</p>
<div class="dt" id="dtTrk" data-resize="1" data-key="trk1" data-default-status="all" data-page-size="50" data-export="tracking" data-statuses="<?= e(json_encode($statuses)) ?>">
  <div data-dt-actions><?php if ($canRefresh): ?><form method="post" style="display:inline;margin:0"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="trk_import"><button class="dt-btn">↻ Read report files now</button></form><?php endif; ?></div>
  <div class="dt-wrap"><table class="dt-table"><thead><tr>
    <th data-sort="text">Vehicle</th><th data-sort="text">Trip</th><th data-sort="text">Status</th><th data-sort="num" class="n">Speed</th><th data-sort="text">Ignition</th><th data-sort="text">Location</th><th data-sort="num" class="n">Last update</th>
    <th data-sort="text">Halted since</th><th data-sort="num" class="n">Today km</th><th data-sort="num" class="n">Yesterday km</th><th data-sort="num" class="n">Max speed</th><th data-sort="text">Idle</th><th data-sort="text">Stopped</th><th data-sort="text">Driver</th><th data-sort="text">Source</th>
  </tr></thead><tbody>
  <?php if (!$T): ?><tr><td colspan="15" style="padding:30px;text-align:center;color:#667085">No tracking data yet. Check the file paths in config/tracking.php.</td></tr><?php endif; ?>
  <?php foreach ($T as $k => $o):
    $g = $group($o); $vid = $vehId[$k] ?? null; $tr = $vid ? ($trips[$vid] ?? null) : null;
    $pill = $g === 'Running' ? 'tk-run' : ($g === 'No signal' ? 'tk-lost' : ($g === 'Other' ? 'tk-oth' : 'tk-stop'));
    $ageCls = $o['fresh'] === 'lost' ? 'tk-lostt' : ($o['fresh'] === 'old' ? 'tk-old' : '');
    $c = fn($v, $t = null) => '<td data-v="' . e((string)$v) . '" title="' . e((string)($t ?? $v)) . '">' . e((string)$v) . '</td>';
    $num = fn($v, $dec = 0) => $v === null ? '<span class="tk-muted">–</span>' : e(number_format((float)$v, $dec)); ?>
    <tr data-row data-status="<?= e($g) ?>" data-q="<?= e(strtolower($o['vehicle_number'] . ' ' . $o['location'] . ' ' . $o['driver_name'] . ' ' . ($tr['trip_code'] ?? ''))) ?>">
      <td data-v="<?= e($o['vehicle_number']) ?>"><b><?= e($o['vehicle_number']) ?></b><?= $vid ? '' : ' <span class="tk-muted" title="Not in the Vehicle master">!</span>' ?></td>
      <td data-v="<?= e($tr['trip_code'] ?? '') ?>"><?= $tr ? '<a href="PLANNING.php?tab=trips&amp;view=' . (int)$tr['id'] . '">' . e($tr['trip_code']) . '</a> <span class="tk-muted">' . e($tr['status'] === 'Planned' ? 'Pending' : $tr['status']) . '</span>' : '<span class="tk-muted">–</span>' ?></td>
      <td data-v="<?= e($g) ?>"><span class="tk-pill <?= $pill ?>"><?= e($o['state']) ?></span></td>
      <td class="n" data-v="<?= (float)$o['speed'] ?>"><?= $o['speed'] === null ? '<span class="tk-muted">–</span>' : e(number_format((float)$o['speed'], 0)) . ' km/h' ?></td>
      <?= $c($o['ignition'] ?? '') ?>
      <td data-v="<?= e((string)$o['location']) ?>" title="<?= e((string)$o['location']) ?>"><?= $o['location'] ? e($o['location']) : '<span class="tk-muted">–</span>' ?></td>
      <td class="n <?= $ageCls ?>" data-v="<?= (int)($o['age_min'] ?? 999999) ?>" title="<?= e((string)$o['time']) ?>"><?= e(tms_trk_age($o['age_min'])) ?></td>
      <?= $c($o['halted_since'] ? date('d M H:i', strtotime((string)$o['halted_since'])) : '') ?>
      <td class="n" data-v="<?= (float)$o['day_km'] ?>"><?= $num($o['day_km'], 1) ?></td><td class="n" data-v="<?= (float)$o['yesterday_km'] ?>"><?= $num($o['yesterday_km']) ?></td><td class="n" data-v="<?= (float)$o['max_speed'] ?>"><?= $num($o['max_speed']) ?></td>
      <?= $c($o['idle_min'] !== null ? tms_trk_dur((int)$o['idle_min']) : '') ?><?= $c($o['stop_min'] !== null ? tms_trk_dur((int)$o['stop_min']) : '') ?>
      <td data-v="<?= e((string)$o['driver_name']) ?>" title="<?= e(trim($o['driver_name'] . ' ' . $o['driver_phone'])) ?>"><?= e((string)$o['driver_name']) ?: '<span class="tk-muted">–</span>' ?></td>
      <?= $c(implode(' + ', $o['sources'])) ?>
    </tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<?php foot(); ?>
