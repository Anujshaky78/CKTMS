<?php
// Copy this file to config/oracle.local.php and fill in the same OCI8 settings your
// working PKT Challan application uses. oracle.local.php is never shipped in update ZIPs.
return [
  'user'     => 'YOUR_ORACLE_USERNAME',
  'password' => 'YOUR_ORACLE_PASSWORD',
  'connect'  => '//YOUR_ORACLE_HOST:1521/YOUR_SERVICE',   // ya tnsnames.ora ka alias
  // Dono DC ke Oracle SITE_CODE:
  'src_site_codes' => ['123'],
  // Koi aur setting bhi yahan override kar sakte hain, jaise:
  // 'days_back' => 0,
  // 'pkt_basis' => 'CHALLAN_BARCODE',
];
