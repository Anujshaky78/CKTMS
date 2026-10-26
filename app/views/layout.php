<?php
declare(strict_types=1);
/* App shell: sidebar navigation, top bar, busy overlay. head()/foot() keep their old signatures. */

function tms_icon(string $n): string {
  $p = [
    'dash' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
    'box' => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
    'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
    'truck' => '<path d="M1 6h13v10H1z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="5.5" cy="18" r="1.8"/><circle cx="17.5" cy="18" r="1.8"/>',
    'pass' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
    'route' => '<circle cx="6" cy="19" r="2"/><circle cx="18" cy="5" r="2"/><path d="M8 19h7a3 3 0 000-6H9a3 3 0 010-6h7"/>',
    'dc' => '<path d="M3 21V9l9-6 9 6v12"/><path d="M9 21v-6h6v6"/>',
    'store' => '<path d="M4 10v10h16V10"/><path d="M2 10l2-6h16l2 6z"/><path d="M10 20v-5h4v5"/>',
    'van' => '<path d="M2 7h12l4 4h4v6H2z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
    'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'check' => '<path d="M4 12l5 5L20 6"/>',
    'out' => '<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4M6 12h10"/>',
    'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
  ][$n] ?? '';
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Navigation definition: [group, label, href, icon, match(file, tab)] */
function tms_nav(): array {
  return [
    ['Operations', 'Dashboard', 'INDEX.php', 'dash', ['INDEX.php', null]],
    ['Planning', 'PRD Pending', 'PLANNING.php?tab=prd_pending', 'box', ['PLANNING.php', 'prd_pending'], 'prd_pending'],
    ['Planning', 'Indents', 'PLANNING.php?tab=indents', 'list', ['PLANNING.php', 'indents'], 'indents'],
    ['Planning', 'Trips', 'PLANNING.php?tab=trips', 'truck', ['PLANNING.php', 'trips'], 'trips'],
    ['Planning', 'Dispatch desk', 'DISPATCH.php', 'van', ['DISPATCH.php', null]],
    ['Planning', 'Gate Pass', 'PLANNING.php?tab=gatepasses', 'pass', ['PLANNING.php', 'gatepasses'], 'gatepasses'],
    ['Planning', 'Trip Status', 'PLANNING.php?tab=tripstatus', 'route', ['PLANNING.php', 'tripstatus'], 'tripstatus'],
    ['Masters', 'Center DC', 'MASTER.php?tab=dcs', 'dc', ['MASTER.php', 'dcs']],
    ['Masters', 'Stores', 'MASTER.php?tab=stores', 'store', ['MASTER.php', 'stores']],
    ['Masters', 'Vehicles', 'MASTER.php?tab=vehicles', 'van', ['MASTER.php', 'vehicles']],
    ['Masters', 'TPT / Driver', 'MASTER.php?tab=drivers', 'user', ['MASTER.php', 'drivers']],
    ['Masters', 'Transporters & docks', 'MASTER_OPS.php?tab=transporters', 'dc', ['MASTER_OPS.php', null]],
    ['Control', 'Reports & SLA', 'REPORTS.php', 'chart', ['REPORTS.php', null]],
    ['Control', 'Transporter scorecard', 'SCORECARD.php', 'chart', ['SCORECARD.php', null]],
    ['Control', 'Oracle check', 'PRD_RECON.php', 'check', ['PRD_RECON.php', null]],
  ];
}

/** Current page → [group, title] for the top bar. */
function tms_where(string $fallback): array {
  $cur = basename((string)($_SERVER['PHP_SELF'] ?? ''));
  $defaults = ['PLANNING.php' => 'prd_pending', 'MASTER.php' => 'stores'];
  $tab = trim((string)($_GET['tab'] ?? ($defaults[$cur] ?? '')));
  foreach (tms_nav() as $n) if ($n[4][0] === $cur && ($n[4][1] === null || $n[4][1] === $tab)) return [$n[0], $n[1], $cur, $tab];
  $extra = ['TRIP_STATUS.php' => ['Planning', 'Trip Status'], 'INDENT_ADD.php' => ['Planning', 'Add indent'], 'MASTER_BULK.php' => ['Masters', 'Store bulk import'], 'MASTER_ADD.php' => ['Masters', $fallback]];
  return [...($extra[$cur] ?? ['', $fallback]), $cur, $tab];
}

function tms_oracle_pill(): string {
  try {
    $r = db()->query("SELECT ok, created_at FROM tms_sync_log WHERE kind='prd' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return ''; }
  if (!$r) return '<a class="ora-pill" href="PLANNING.php?tab=prd_pending" title="Oracle has not been synced yet"><i></i>Oracle: not synced</a>';
  $t = date('d M, H:i', strtotime((string)$r['created_at']));
  return $r['ok'] ? '<a class="ora-pill ok" href="PLANNING.php?tab=prd_pending" title="Last PRD sync from Oracle"><i></i>Oracle synced ' . e($t) . '</a>'
                  : '<a class="ora-pill bad" href="PLANNING.php?tab=prd_pending" title="Last PRD sync failed"><i></i>Oracle sync failed ' . e($t) . '</a>';
}

if (!function_exists('head')) {
function head(string $title): void {
  [$group, $label, $cur, $tab] = tms_where($title);
  $app = (string)(app_cfg('name') ?? 'TMS');
  $name = userName(); $initial = strtoupper(substr(trim($name) ?: 'U', 0, 1));
  ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($label) ?> | <?= e($app) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head><body><div class="app">
<aside class="side" id="side">
  <button class="collapse" type="button" onclick="toggleSide()" title="Collapse / expand menu" aria-label="Collapse menu">‹</button>
  <div class="brand"><span class="mark"><?= tms_icon('truck') ?></span><span class="brand-txt"><?= e($app) ?><small>Transport management</small></span></div>
  <nav class="nav" aria-label="Main">
  <?php $g = null; foreach (tms_nav() as $n):
    if ($n[0] !== $g) { $g = $n[0]; echo '<div class="nav-group">' . e($g) . '</div>'; }
    $active = $n[4][0] === $cur && ($n[4][1] === null || $n[4][1] === $tab) || ($cur === 'TRIP_STATUS.php' && ($n[5] ?? '') === 'tripstatus');
    $dt = isset($n[5]) ? ' data-planning-tab="' . e($n[5]) . '"' : '';
    echo '<a class="' . ($active ? 'active' : '') . '" href="../pages/' . e($n[2]) . '"' . $dt . ($active ? ' aria-current="page"' : '') . ' title="' . e($n[1]) . '">' . tms_icon($n[3]) . '<span>' . e($n[1]) . '</span></a>';
  endforeach; ?>
  </nav>
  <div class="sidebottom">
    <div class="userbox"><span class="av"><?= e($initial) ?></span><div>Signed in as<b><?= e($name) ?></b></div></div>
    <a class="logout" href="../pages/LOGOUT.php"><?= tms_icon('out') ?><span>Log out</span></a>
  </div>
</aside>
<main class="main">
<div id="appBusy" class="busy-overlay" aria-live="polite"><div class="busy-box"><div class="busy-spinner"></div><div id="busyTitle" class="busy-title">Working…</div><div id="busySub" class="busy-sub">Please wait</div></div></div>
<header class="top">
  <div style="display:flex;gap:10px;align-items:center">
    <button class="menu-btn" type="button" onclick="toggleSide()" aria-label="Open menu"><?= tms_icon('menu') ?></button>
    <div><?php if ($group): ?><div class="crumb"><?= e($group) ?></div><?php endif; ?><div class="title" id="topTitle"><?= e($label) ?></div></div>
  </div>
  <div class="top-right"><?= tms_oracle_pill() ?><span class="user"><?= e($name) ?></span></div>
</header>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<script>
// Add the CSRF token to every POST form at submit time (covers forms built by JavaScript too).
document.addEventListener('submit',function(ev){var f=ev.target;
  if(!f||!f.tagName||f.tagName!=='FORM'||String(f.method).toLowerCase()!=='post'||f.querySelector('input[name="csrf"]'))return;
  var i=document.createElement('input');i.type='hidden';i.name='csrf';i.value=<?= json_encode(csrf()) ?>;f.appendChild(i);
},true);
</script>
<?php }
}
if (!function_exists('foot')) { function foot(): void { echo '</main></div></body></html>'; } }
