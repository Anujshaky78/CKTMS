<?php
declare(strict_types=1);
if (!function_exists('require_login')) {
  function require_login(): void { if (empty($_SESSION['user_id'])) { header('Location: LOGIN.php'); exit; } }
}
