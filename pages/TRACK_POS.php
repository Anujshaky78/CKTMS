<?php
/* JSON: latest known position of one vehicle (from the GPS report files) for the trip map. GET ?vehicle=HR55AB1234 */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
session_write_close();   // a lookup can take seconds: do not hold the session lock, or every other page of this user waits for it
require_once TMS_ROOT . '/app/services/tracking.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$d = db(); $no = tms_trk_norm((string)($_GET['vehicle'] ?? ''));
tms_trk_autoimport($d);
$o = $no !== '' ? (tms_trk_latest($d)[$no] ?? null) : null;
if (!$o) { echo json_encode(['ok' => false, 'error' => 'No tracking data for this vehicle.']); exit; }
$exact = $o['lat'] !== null && $o['lng'] !== null;   // R-TRAC gives real coordinates; G-TRAC only an address that has to be looked up
// The web server cannot reach OpenStreetMap, so it never looks anything up: an exact answer if one is cached, otherwise an instant estimate
// from our store master, plus a "lookup" plan that the browser carries out (and saves back through TRACK_SAVE.php).
$lookup = null; $pos = null;
if ($exact) $pos = ['lat' => (float)$o['lat'], 'lng' => (float)$o['lng']];
elseif ($o['location']) {
  $pos = tms_trk_cache_get($d, $o['location']);
  if (!$pos) { $pos = tms_trk_geocode_local($d, $o['location']); if (!tms_trk_cache_missing($d, $o['location'])) $lookup = tms_trk_geo_plan($d, (string)$o['location']); }
}
$start = $o['start_lat'] !== null ? ['lat' => (float)$o['start_lat'], 'lng' => (float)$o['start_lng']] : (($o['start_address'] ?? null) ? tms_trk_cache_get($d, $o['start_address']) : null);
$trail = $o['trail'] ? json_decode((string)$o['trail'], true) : null;
echo json_encode(['ok' => true, 'vehicle' => $o['vehicle_number'], 'state' => $o['state'], 'fresh' => $o['fresh'], 'speed' => $o['speed'], 'ignition' => $o['ignition'],
  'location' => $o['location'], 'time' => $o['time'], 'age' => tms_trk_age($o['age_min']), 'day_km' => $o['day_km'], 'idle' => $o['idle_min'] !== null ? tms_trk_dur((int)$o['idle_min']) : null,
  'sources' => $o['sources'], 'loc_old' => !empty($o['loc_old']), 'loc_age' => tms_trk_age($o['loc_age_min'] ?? null), 'pos' => $pos, 'exact' => $exact, 'refining' => $lookup !== null, 'lookup' => $lookup, 'trail' => is_array($trail) ? $trail : null, 'start' => $start, 'start_address' => $o['start_address'] ?? null], JSON_UNESCAPED_UNICODE);
