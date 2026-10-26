<?php
declare(strict_types=1);
/*
 * Single entry for every page: session, config, helpers, database, auth, rules and the UI shell.
 * Old include paths (config/DB.php, auth/auth.php, includes/layout.php) are thin shims to this file.
 */
if (defined('TMS_ROOT')) return;
define('TMS_ROOT', dirname(__DIR__));
if (session_status() === PHP_SESSION_NONE) { ini_set('session.cookie_httponly', '1'); ini_set('session.use_strict_mode', '1'); ini_set('session.cookie_samesite', 'Lax'); session_start(); }
require_once TMS_ROOT . '/app/lib/helpers.php';
require_once TMS_ROOT . '/app/lib/database.php';
require_once TMS_ROOT . '/app/lib/auth.php';
require_once TMS_ROOT . '/app/lib/rules.php';
require_once TMS_ROOT . '/app/views/layout.php';
