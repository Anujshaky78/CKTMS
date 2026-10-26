<?php
/*
 * Process bar on top of every Planning tab: the whole job from PRD Pending to delivery, one action per step, with how many things wait in each.
 *   1 Select & plan (Dispatch desk)  2 Assign  3 Load & gate pass  4 Dispatch  5 Deliver
 * Each step opens the tab (on Trips: the status filter) where that step is done. Uses $d, $tab and the 'st' query parameter.
 */
$stc = $d->query("SELECT status, COUNT(*) FROM trips GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$problem = (int)($stc['Breakdown'] ?? 0) + (int)($stc['Accident'] ?? 0);
$stSel = (string)($_GET['st'] ?? '');
$steps = [
  ['Select & plan', 'Tick stores, create trips', null, 'PLANNING.php?tab=prd_pending', 'prd_pending', null],
  ['Assign', 'Transporter, vehicle, driver', (int)($stc['Planned'] ?? 0), 'PLANNING.php?tab=trips&st=Planned', 'trips', 'Planned'],
  ['Load & gate pass', 'Load the vehicle, make gate pass', (int)($stc['Assigned'] ?? 0) + (int)($stc['Loaded'] ?? 0), 'PLANNING.php?tab=trips&st=Assigned', 'trips', 'Assigned'],
  ['Dispatch', 'Send the vehicle out', (int)($stc['Gate Pass Created'] ?? 0), 'PLANNING.php?tab=trips&st=' . rawurlencode('Gate Pass Created'), 'trips', 'Gate Pass Created'],
  ['Deliver', 'On the way, then delivered', (int)($stc['In Transit'] ?? 0) + $problem, 'PLANNING.php?tab=trips&st=' . rawurlencode('In Transit'), 'trips', 'In Transit'],
];
$here = $tab === 'gatepasses' ? 2 : ($tab === 'tripstatus' ? 4 : (in_array($tab, ['matrix', 'review', 'auto'], true) ? 0 : null));
foreach ($steps as $i => $s) if ($s[4] === $tab && ($s[5] === null || $s[5] === $stSel || ($s[5] === 'Assigned' && $stSel === 'Loaded'))) $here = $i;
if ($tab === 'trips' && $here === null && empty($_GET['view'])) $here = $stSel === 'Delivered' ? 4 : 1; // the trips list without a step filter: the first trip step
if ($tab === 'trips' && !empty($_GET['view'])) $here = null;
?>
<link rel="stylesheet" href="<?= e(asset('assets/css/steps.css')) ?>">
<nav class="ps" aria-label="Process">
<?php foreach ($steps as $i => $s): $cls = $here === $i ? 'now' : ($here !== null && $i < $here ? 'past' : ''); ?>
  <a class="ps-s <?= $cls ?>" href="<?= e($s[3]) ?>"<?= $here === $i ? ' aria-current="step"' : '' ?>>
    <span class="ps-n"><?= $i + 1 ?></span>
    <span class="ps-t"><b><?= e($s[0]) ?></b><small><?= e($s[1]) ?></small></span>
    <?php if ($s[2] !== null): ?><span class="ps-c<?= $s[2] ? ' has' : '' ?>"><?= number_format($s[2]) ?></span><?php endif; ?>
  </a>
<?php endforeach; ?>
<?php if ($problem): ?><a class="ps-prob" href="PLANNING.php?tab=trips&amp;st=problem">⚠ <?= $problem ?> breakdown / accident</a><?php endif; ?>
</nav>
