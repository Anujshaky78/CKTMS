<?php
// Compatibility shim: boot the app and require a signed-in user (same behaviour as before).
require_once __DIR__ . '/../app/bootstrap.php';
if (empty($_SESSION['user_id'])) redirect('LOGIN.php');
