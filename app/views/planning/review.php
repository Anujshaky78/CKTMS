<?php
// Step 2 of the flow: review the trips the system proposes for the stores ticked on PRD Pending, then confirm.
// Every pending plan is shown together (a plan is only the container for the ticked stores); nothing is written until "Create trips".
$L = fn($v) => $v >= 10000000 ? number_format($v / 10000000, 2) . ' Cr' : ($v >= 100000 ? number_format($v / 100000, 2) . ' L' : number_format((float)$v, 0));
$minFill = (int)(prd_cfg()['min_fill_pct'] ?? 60);
$pendingPlanIds = array_map('intval', $d->query("SELECT DISTINCT plan_id FROM tms_plan_lines WHERE status='Pending' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN));
$lines = $pendingPlanIds ? $d->query("SELECT l.*, p.plan_no FROM tms_plan_lines l JOIN tms_plans p ON p.id=l.plan_id WHERE l.status='Pending' ORDER BY l.plan_id, l.source_site_code, l.store_code")->fetchAll(PDO::FETCH_ASSOC) : [];
$pv = null; $pvErr = '';
if ($pendingPlanIds) { try { $pv = plan_preview($d, $pendingPlanIds); } catch (Throwable $x) { $pvErr = $x->getMessage(); } }
echo tms_stats([['stores to review', count($lines), 'dark'], ['PKT', (int)array_sum(array_column($lines, 'pkt'))], ['value', '₹' . $L(array_sum(array_column($lines, 'value')))], ['← PRD Pending', '', '', 'PLANNING.php?tab=prd_pending']]);
?>
<link rel="stylesheet" href="<?= e(asset('assets/css/plans.css')) ?>">
<div class="pl">
<?php if ($pvErr): ?><div class="alert alert-err" role="alert"><?= e($pvErr) ?></div><?php endif; ?>
<?php if (!$lines): ?>
  <div class="pl-empty"><b>Nothing to review.</b><br>Go to <a href="PLANNING.php?tab=prd_pending">PRD Pending</a>, tick the stores you want to dispatch and click “Review trips”.</div>
<?php elseif ($pv): ?>
<section class="pv" id="pv">
  <div class="pv-h">
    <div><h3>Proposed trips</h3>
      <div class="sub">Max <?= (int)$pv['max_stops'] ?> stores and <?= (int)$pv['max_pkt'] ?> PKT per trip. Nearest stores go on one route; a store without coordinates only joins its own cluster. Change the vehicle if needed, then confirm.</div></div>
  </div>
  <?php if ($pv['excluded']): ?><div class="pv-excl"><b><?= count($pv['excluded']) ?> store(s) cannot be planned now</b> and stay here:<ul><?php foreach ($pv['excluded'] as $x): ?><li><?= e($x['name']) ?>: <?= e($x['reason']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($pv['gone_pkt'] > 0): ?><div class="pv-warn" style="margin:0 0 12px"><?= number_format($pv['gone_pkt']) ?> PKT are no longer pending at the source (dispatched meanwhile), so they are left out.</div><?php endif; ?>
  <?php if (!$pv['loads']): ?>
    <div class="pl-empty">No trip can be made right now.</div>
  <?php else:
    $tp = array_sum(array_column($pv['loads'], 'pkt')); $ts = array_sum(array_map(fn($l) => count($l['stops']), $pv['loads'])); $tv = array_sum(array_column($pv['loads'], 'value')); ?>
  <div class="pv-sum"><span><b><?= count($pv['loads']) ?></b> trips</span><span><b><?= $ts ?></b> stops</span><span><b><?= number_format($tp) ?></b> PKT</span><span>₹<b><?= $L($tv) ?></b></span></div>
  <form method="post" id="pvForm">
    <input type="hidden" name="action" value="create_plan_trips"><input type="hidden" name="sig" value="<?= e($pv['sig']) ?>">
    <?php foreach ($pv['plan_ids'] as $pid): ?><input type="hidden" name="plan_ids[]" value="<?= (int)$pid ?>"><?php endforeach; ?>
    <div class="pv-grid">
    <?php foreach ($pv['loads'] as $i => $l): $fill = (int)$l['fill']; $cls = $fill > 100 ? 'over' : ($fill < $minFill ? 'low' : ''); ?>
      <div class="pv-trip">
        <div class="pv-trip-h"><div><b>Trip <?= $i + 1 ?></b> <span class="src"><?= e($l['source']) ?></span></div><div><b><?= (int)$l['pkt'] ?></b> PKT · ₹<?= $L($l['value']) ?></div></div>
        <ol class="pv-route">
        <?php foreach ($l['stops'] as $j => $s): ?>
          <li><span class="pv-no"><?= $j + 1 ?></span>
            <span class="nm"><b><?= e($s['code']) ?> <small><?= e($s['name']) ?><?= $s['city'] ? ', ' . e($s['city']) : '' ?></small></b>
              <?php if ($s['part']): ?><small>part <?= e($s['part']) ?> of a big store (oldest challans first)</small><?php endif; ?></span>
            <span class="r"><b><?= (int)$s['pkt'] ?> PKT</b><small><?= $s['km'] !== null ? ($j === 0 ? 'from DC ' : '+') . (int)$s['km'] . ' km' : 'km n/a' ?></small></span></li>
        <?php endforeach; ?>
        </ol>
        <div class="pv-fill <?= $cls ?>"><i style="width:<?= min(100, $fill) ?>%"></i></div>
        <div class="pv-meta"><span><?= $fill ?>% of <?= (int)$l['capacity'] ?> PKT</span><span><?= $l['route_km'] !== null ? 'route ≈ ' . (int)$l['route_km'] . ' km' : 'route km n/a' ?></span></div>
        <div class="pv-veh"><label for="veh<?= $i ?>">Vehicle</label><select id="veh<?= $i ?>" name="veh[<?= $i ?>]"><?php foreach ($pv['classes'] as $c): ?><option <?= $c['type'] === $l['vehicle'] ? 'selected' : '' ?>><?= e($c['type']) ?></option><?php endforeach; ?></select></div>
        <?php foreach ($l['warnings'] as $w): ?><div class="pv-warn"><?= e($w) ?></div><?php endforeach; ?>
        <?php if (empty($l['dc'])): ?><div class="pv-warn" style="background:#fef3f2;color:#b42318">Source is not linked to a Center DC: this trip cannot be created.</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <div class="pv-foot"><button class="pl-btn pri" type="submit">Confirm: create <?= count($pv['loads']) ?> trip<?= count($pv['loads']) > 1 ? 's' : '' ?></button></div>
  </form>
  <?php endif; ?>
</section>

<section class="pv" style="margin-top:14px">
  <div class="pv-h"><div><h3>Stores in this review (<?= count($lines) ?>)</h3><div class="sub">Remove a store to send it back to PRD Pending.</div></div>
    <?php if (can('plan')): ?><form method="post" onsubmit="return confirm('Sab stores PRD Pending me wapas bhej dein?')"><input type="hidden" name="action" value="cancel_plan"><?php foreach ($pendingPlanIds as $pid): ?><input type="hidden" name="plan_ids[]" value="<?= (int)$pid ?>"><?php endforeach; ?><button class="pl-btn bad" type="submit">Cancel all, back to PRD Pending</button></form><?php endif; ?></div>
  <div class="dt-wrap"><table class="dt-table"><thead><tr><th>Store</th><th>Source</th><th>Cluster</th><th class="n">PKT</th><th class="n">Value</th><th></th></tr></thead><tbody>
  <?php foreach ($lines as $r): ?>
    <tr><td><b><?= e($r['store_code']) ?></b> <span class="sub" style="display:inline"><?= e($r['store_name']) ?></span></td><td><?= e($r['source_name']) ?></td><td><?= e($r['cluster']) ?></td><td class="n"><?= number_format((int)$r['pkt']) ?></td><td class="n">₹<?= $L($r['value']) ?></td>
      <td><?php if (can('plan')): ?><form method="post" style="margin:0"><input type="hidden" name="action" value="cancel_plan"><input type="hidden" name="plan_id" value="<?= (int)$r['plan_id'] ?>"><input type="hidden" name="line_id" value="<?= (int)$r['id'] ?>"><button class="pl-btn bad" type="submit">Remove</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</section>
<link rel="stylesheet" href="<?= e(asset('assets/css/dt.css')) ?>">
<?php endif; ?>
</div>
