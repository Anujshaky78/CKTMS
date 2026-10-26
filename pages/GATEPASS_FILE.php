<?php
// Serves the document attached to a Gate Pass. Files live outside the web root; only a logged-in user can fetch one.
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$d = db();
$q = $d->prepare("SELECT document_path FROM tms_gate_passes WHERE id=?");
$q->execute([(int)($_GET['id'] ?? 0)]);
$rel = (string)($q->fetchColumn() ?: '');
if ($rel === '' || !preg_match('~^gatepass/[A-Za-z0-9._-]+$~', $rel)) { http_response_code(404); exit('File not found.'); }
$base = realpath(rtrim((string)app_cfg('storage_dir'), '/\\')); $file = $base ? realpath($base . '/' . $rel) : false;
if (!$file || strncmp($file, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0 || !is_file($file)) { http_response_code(404); exit('File not found.'); }
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'][$ext] ?? 'application/octet-stream';
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true);
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="GatePass-' . (int)$_GET['id'] . '.' . $ext . '"');
header('Content-Length: ' . filesize($file));
readfile($file);
