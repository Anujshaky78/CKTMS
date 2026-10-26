<?php
declare(strict_types=1);
// Small global helpers used by every page.

if (!function_exists('e')) { function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($v): string { return '₹' . number_format((float)$v, 2); } }
if (!function_exists('redirect')) { function redirect(string $url): never { header("Location: $url"); exit; } }
if (!function_exists('setflash')) { function setflash(string $m): void { $_SESSION['flash'] = $m; } }
if (!function_exists('flash')) {
  function flash(): void {
    if (!empty($_SESSION['flash'])) { echo '<div class="alert alert-ok" role="status">' . e($_SESSION['flash']) . '</div>'; unset($_SESSION['flash']); }
  }
}
if (!function_exists('csrf')) { function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; } }
if (!function_exists('verify_csrf')) { function verify_csrf(): void { if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Invalid request token. Page refresh karke dobara try karein.'); } } }
if (!function_exists('userName')) { function userName(): string { return (string)($_SESSION['user_name'] ?? 'User'); } }
function is_ajax(): bool { return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'; }
function json_out(array $data, int $code = 200): never { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); exit; }
function app_cfg(?string $key = null) {
  static $c = null; if ($c === null) $c = require TMS_ROOT . '/config/app.php';
  if ($key === null) return $c;
  $v = $c; foreach (explode('.', $key) as $p) { if (!is_array($v) || !array_key_exists($p, $v)) return null; $v = $v[$p]; }
  return $v;
}
/** Upload storage folder (config 'storage_dir', outside the web root) plus a sub folder; created on demand and closed to the web. */
function tms_storage_dir(string $sub): string {
  $base = rtrim(str_replace('\\', '/', (string)(app_cfg('storage_dir') ?: (TMS_ROOT . '/storage'))), '/');
  $dir = $base . '/' . $sub;
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new Exception('Storage folder nahi ban saka: ' . $dir);
  if (!is_file($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "Require all denied\n"); // only matters if storage_dir is ever placed under the web root
  return $dir;
}
/** Validates and stores one uploaded Gate Pass file (size, extension, real file type, random name). Returns [absolute path, document_path to save]. */
function tms_save_gatepass_file(array $f, int $tripId): array {
  if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new Exception('Gate Pass file upload nahi hui (error ' . (int)($f['error'] ?? -1) . ').');
  $mb = (int)(app_cfg('upload_max_mb') ?? 5);
  if ((int)$f['size'] <= 0 || (int)$f['size'] > $mb * 1048576) throw new Exception("Gate Pass file $mb MB se chhoti honi chahiye.");
  $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
  $types = ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream']];
  if (!isset($types[$ext])) throw new Exception('Gate Pass file only PDF/JPG/PNG/XLS/XLSX allowed.');
  if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) {
    $mime = (string)finfo_file($fi, (string)$f['tmp_name']); finfo_close($fi);
    if (!in_array($mime, $types[$ext], true)) throw new Exception("Gate Pass file ka type ($mime) .$ext se match nahi karta.");
  }
  $dir = tms_storage_dir('gatepass');
  $fn = 'GP-' . $tripId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
  if (!move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $fn)) throw new Exception('Gate Pass file save nahi hui.');
  return [$dir . '/' . $fn, 'gatepass/' . $fn];
}
/** URL to a file under the app root, from any page in /pages. */
function asset(string $path): string { $f = TMS_ROOT . '/' . ltrim($path, '/'); return '../' . ltrim($path, '/') . (is_file($f) ? '?v=' . filemtime($f) : ''); }

/** One-line stats strip used at the top of every tab (same look as PRD Pending). $pills: [[label, value, tone?, href?], …] */
function tms_stats(array $pills): string {
  $h = '<div class="stats">';
  foreach ($pills as $p) {
    [$label, $val] = $p; $tone = $p[2] ?? ''; $href = $p[3] ?? null;
    $txt = '<b>' . e(is_int($val) || is_float($val) ? number_format((float)$val) : (string)$val) . '</b> ' . e($label);
    $h .= $href ? '<a class="' . e($tone) . '" href="' . e($href) . '">' . $txt . '</a>' : '<span class="' . e($tone) . '">' . $txt . '</span>';
  }
  return $h . '</div>';
}
/** Count rows by a column value. */
function tms_count_by(array $rows, string $key): array { $o = []; foreach ($rows as $r) { $k = (string)($r[$key] ?? ''); $o[$k] = ($o[$k] ?? 0) + 1; } return $o; }
