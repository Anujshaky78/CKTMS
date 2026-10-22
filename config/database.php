<?php
// PostgreSQL connection for the TMS.
// Server-specific values: create config/database.local.php (same keys). It is never shipped in update ZIPs.
$local = is_file(__DIR__ . '/database.local.php') ? (require __DIR__ . '/database.local.php') : [];
return array_replace([
  'host' => getenv('TMS_DB_HOST') ?: '127.0.0.1',
  'port' => getenv('TMS_DB_PORT') ?: '5432',
  'name' => getenv('TMS_DB_NAME') ?: 'citykart_dc',
  'user' => getenv('TMS_DB_USER') ?: 'postgres',
  'password' => getenv('TMS_DB_PASSWORD') !== false ? getenv('TMS_DB_PASSWORD') : '123',
], is_array($local) ? $local : []);
