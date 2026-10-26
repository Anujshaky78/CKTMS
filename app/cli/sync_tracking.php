<?php
declare(strict_types=1);
/*
 * Vehicle tracking import for the command line: php.exe app/cli/sync_tracking.php [--force]
 * Reads the GPS report files from config/tracking.php and updates tms_vehicle_tracking (only when a file changed, unless --force).
 * Can be run by hand or by the Windows Task Scheduler; the Trips and Tracking pages also do the same check when they open.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TMS_ROOT', dirname(__DIR__, 2));
require_once TMS_ROOT . '/app/lib/helpers.php';
require_once TMS_ROOT . '/app/lib/database.php';
require_once TMS_ROOT . '/app/services/tracking.php';
$r = tms_trk_import(db(), in_array('--force', $argv ?? [], true));
echo date('c') . ' ' . ($r['ok'] ? 'OK' : 'FAILED') . ': ' . $r['message'] . "\n";
exit($r['ok'] ? 0 : 1);
