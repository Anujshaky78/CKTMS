<?php
// Application-wide settings and business rules.
// Server-specific overrides: config/app.local.php (same keys, never shipped in update ZIPs).
$local = is_file(__DIR__ . '/app.local.php') ? (require __DIR__ . '/app.local.php') : [];
return array_replace_recursive([
  'name' => 'Citykart TMS',
  'company' => 'Citykart',
  // Uploaded files (Gate Pass documents) live OUTSIDE the web root and are served only through pages/GATEPASS_FILE.php (login required).
  'storage_dir' => getenv('TMS_STORAGE_DIR') ?: 'C:/xampp/tms_storage',
  'upload_max_mb' => 5,
  // PRD Pending refreshes itself from Oracle: opening the page starts a background sync when the last good one is older than max_age_sec
  // (the page shows "Refreshing from Oracle…" and reloads when it is done). After a failed attempt it waits retry_after_sec before trying again.
  // PRD Pending refreshes ONLY when someone clicks the Refresh button (it then runs in the background and the page reloads when done).
  // 'auto' => true would also start a refresh whenever the page is opened and its data is older than max_age_sec: it is off on purpose.
  'prd_sync' => [
    'auto' => false,
    'max_age_sec' => 120,
    'retry_after_sec' => 60,
    'php_cli' => getenv('TMS_PHP_CLI') ?: 'C:/xampp/php/php.exe',
  ],
  'trip' => [
    // Ek trip me maximum challan PKT. Har trip creation / edit / auto-plan par lagta hai.
    'max_pkt'    => 300,
    // Ek trip me maximum stores (stops).
    'max_stores' => 3,
    // Ek store akela (single-store trip) tabhi jata hai jab uska PKT itna ya zyada ho; tab poora PKT ek hi trip me jata hai
    // (max_pkt ki limit us trip par nahi lagti). Isse kam PKT wala store sirf doosre stores ke saath combine hokar jata hai.
    'single_min_pkt' => 400,
  ],
], is_array($local) ? $local : []);
