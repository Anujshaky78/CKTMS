<?php
declare(strict_types=1);
/*
 * Finds the exact position of every current vehicle address that has none yet (OpenStreetMap, one lookup per second) and caches it,
 * so the trip map shows the vehicle at once. Started in the background after a tracking file changed, or by the trip map when it needs one.
 * php.exe app/cli/geocode_tracking.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TMS_ROOT', dirname(__DIR__, 2));
set_time_limit(300);
require_once TMS_ROOT . '/app/lib/helpers.php';
require_once TMS_ROOT . '/app/lib/database.php';
require_once TMS_ROOT . '/app/services/tracking.php';
$d = db();
if (!$d->query("SELECT pg_try_advisory_lock(727003)")->fetchColumn()) { echo "another run is active\n"; exit(0); }
$done = 0;
try {
  foreach (tms_trk_latest($d) as $o) {
    if (!empty($o['lat']) || !$o['location'] || tms_trk_cache_get($d, $o['location']) || tms_trk_cache_missing($d, $o['location'])) continue;
    tms_trk_geocode($d, $o['location']); if (++$done >= 30) break;
  }
} finally { $d->query("SELECT pg_advisory_unlock(727003)"); }
echo date('c') . " looked up $done address(es)\n";
