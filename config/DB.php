<?php
// Compatibility shim: the app now boots from app/bootstrap.php.
// Database settings live in config/database.php (overrides: config/database.local.php).
require_once __DIR__ . '/../app/bootstrap.php';
