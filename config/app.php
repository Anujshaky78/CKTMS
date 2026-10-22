<?php
// Application-wide settings and business rules.
// Server-specific overrides: config/app.local.php (same keys, never shipped in update ZIPs).
$local = is_file(__DIR__ . '/app.local.php') ? (require __DIR__ . '/app.local.php') : [];
return array_replace_recursive([
  'name' => 'Citykart TMS',
  'company' => 'Citykart',
  'trip' => [
    // Ek trip me maximum challan PKT. Har trip creation / edit / auto-plan par lagta hai.
    'max_pkt'    => 300,
    // Ek trip me maximum stores (stops).
    'max_stores' => 2,
  ],
], is_array($local) ? $local : []);
