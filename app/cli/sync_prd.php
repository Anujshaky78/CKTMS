<?php
declare(strict_types=1);
/*
 * PRD Pending sync from Oracle, for the command line (php.exe app/cli/sync_prd.php).
 * Started by the PRD Pending page when its data is older than prd_sync.max_age_sec (it can also be run by hand or by a scheduler).
 * Only PRD Pending is synced here; Store Master stays on the manual Sync button
 * so that edits made in Masters are not overwritten every few minutes. Exits at once if another sync is running.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TMS_ROOT', dirname(__DIR__, 2));
set_time_limit(0); ini_set('memory_limit', '1024M');
require_once TMS_ROOT . '/app/lib/helpers.php';
require_once TMS_ROOT . '/app/lib/database.php';
require_once TMS_ROOT . '/app/lib/rules.php';
require_once TMS_ROOT . '/app/services/oracle_sync.php';

$d = db();
if (!tms_sync_try_lock($d)) { echo date('c') . " another sync is already running; nothing to do\n"; exit(0); }
$t0 = microtime(true);
// --stores: also refresh Store Master (the Refresh button does this); --manual: log it as a button run, not [auto]
$withStores = in_array('--stores', $argv ?? [], true); $source = in_array('--manual', $argv ?? [], true) ? 'manual' : 'auto';
try { $r = tms_run_prd_sync($d, $withStores, $source); }
finally { tms_sync_unlock($d); }
echo date('c') . ' ' . ($r['ok'] ? 'OK' : 'FAILED') . ' in ' . round(microtime(true) - $t0, 1) . 's: ' . $r['message'] . "\n";
exit($r['ok'] ? 0 : 1);
