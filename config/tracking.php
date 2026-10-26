<?php
// Vehicle tracking: the TMS reads the reports that the GPS portals export (Excel) from these paths. No upload needed.
// Server-specific values: create config/tracking.local.php (same keys). It is never shipped in update ZIPs.
// Tip: keep the files outside the website folder (for example C:/TMS-data/G-TRAC.xlsx) so nobody can download them by URL.
$local = is_file(__DIR__ . '/tracking.local.php') ? (require __DIR__ . '/tracking.local.php') : [];
return array_replace([
  'files' => [
    ['source' => 'G-TRAC', 'path' => 'C:/xampp/htdocs/TMS/G-TRAC.xlsx'],   // "Vehicle Status Report": last GPS point, speed, ignition, status
    ['source' => 'R-TRAC', 'path' => 'C:/xampp/htdocs/TMS/R-TRAC.xlsx'],   // day summary: distance, speeds, idle / stop time, start & end address
  ],
  'timezone' => 'Asia/Kolkata',   // the time zone the portals print their times in
  'stale_min' => 60,        // a position older than this is shown as "old" (amber)
  'lost_hours' => 24,       // older than this: "no signal" (red)
], is_array($local) ? $local : []);
