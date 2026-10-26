<?php
/* POST: the browser found the position of an address (OpenStreetMap is reachable from the browser, not from this server); keep it so the next view is instant.
 * Fields: csrf, address, lat, lng, or csrf, pin, lat, lng for the centre of a PIN code. */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
require_once TMS_ROOT . '/app/services/tracking.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }
verify_csrf();
$d = db(); $lat = (float)($_POST['lat'] ?? 0); $lng = (float)($_POST['lng'] ?? 0);
$ok = isset($_POST['pin']) ? tms_trk_pin_put($d, (string)$_POST['pin'], $lat, $lng) : tms_trk_cache_put($d, trim((string)($_POST['address'] ?? '')), $lat, $lng);
echo json_encode(['ok' => $ok]);
