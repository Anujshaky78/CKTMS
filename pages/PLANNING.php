<?php
/*
 * Planning module (PRD Pending · Indents · Trips · Gate Pass · Trip Status).
 * Thin controller: actions and data live in app/controllers, markup in app/views/planning.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
require_once TMS_ROOT . '/app/services/prd_planner.php';
require_once TMS_ROOT . '/app/services/trip_service.php';
require_once TMS_ROOT . '/app/services/plan_service.php';
$d = db();

// JSON / CSV endpoints for PRD Pending (exit early)
require TMS_ROOT . '/app/controllers/prd_api.php';

$msg = ''; $err = '';
$tab = trim((string)($_GET['tab'] ?? 'prd_pending'));
if ($tab === 'indents') $tab = 'review'; // old Indents tab: its job is the Review step now
if (!in_array($tab, ['prd_pending', 'auto', 'matrix', 'review', 'trips', 'gatepasses', 'tripstatus'], true)) $tab = 'prd_pending';

// Master lookups (needed by actions to resolve Store / DC codes)
$stores = $d->query("SELECT id,store_code,store_name,latitude,longitude,active,city,state,address,pincode,transit_days FROM stores ORDER BY store_code")->fetchAll(PDO::FETCH_ASSOC);
$dcs = $d->query("SELECT id,dc_code,dc_name,latitude,longitude,active FROM tms_dc_master ORDER BY dc_code")->fetchAll(PDO::FETCH_ASSOC);
$storeByCode = []; foreach ($stores as $st) { $c = strtolower(trim((string)($st['store_code'] ?? ''))); if ($c !== '') $storeByCode[$c] = $st; }
$dcByCode = []; foreach ($dcs as $dc) { $c = strtolower(trim((string)($dc['dc_code'] ?? ''))); if ($c !== '') $dcByCode[$c] = $dc; }

require TMS_ROOT . '/app/controllers/planning_actions.php';
require TMS_ROOT . '/app/controllers/planning_data.php';

if (!empty($_SESSION['flash'])) { $msg = trim($msg . ' ' . $_SESSION['flash']); unset($_SESSION['flash']); }
$hideErr = $tab === 'prd_pending' && str_starts_with($err, 'Oracle sync failed'); // the board shows it in context

head('Planning');
?>
<script>document.documentElement.classList.add('planning-page');</script>
<link rel="stylesheet" href="<?= e(asset('assets/css/planning.css')) ?>">
<div class="page-alerts">
  <?php if ($msg): ?><div class="alert alert-ok" role="status"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err && !$hideErr): ?><div class="alert alert-err" role="alert"><?= e($err) ?></div><?php endif; ?>
</div>
<div class="planning-shell">
 <section class="planning-module-content">
<?php
if ($view && $trip && $tab === 'trips') include TMS_ROOT . '/app/views/planning/trip_detail.php';
elseif ($tab === 'prd_pending') include TMS_ROOT . '/app/views/planning/desk.php';
elseif ($tab === 'auto') include TMS_ROOT . '/app/views/planning/auto.php';
elseif ($tab === 'matrix') include TMS_ROOT . '/app/views/planning/prd_pending.php';
elseif ($tab === 'review') include TMS_ROOT . '/app/views/planning/review.php';
elseif ($tab === 'trips') include TMS_ROOT . '/app/views/planning/trips.php';
elseif ($tab === 'gatepasses') include TMS_ROOT . '/app/views/planning/gatepasses.php';
elseif ($tab === 'tripstatus') { $_GET['embedded'] = '1'; include __DIR__ . '/TRIP_STATUS.php'; }
?>
 </section>
</div>
<?php include TMS_ROOT . '/app/views/planning/_scripts.php'; ?>
<?php foot(); ?>
