<?php
// PRD Pending · Vehicle Planning Engine settings.
// PKT = distinct challan count per Source + Destination store.
// Sab values yahin se tune karein; code change ki zaroorat nahi.
return [
  // Planner ke liye max stores per trip. Hard limit config/app.php ke trip.max_stores se aata hai
  // (dono me jo kam ho wahi lagta hai).
  'max_stops'       => 3,

  // Single store ka PKT, sabse badi gaadi ki capacity ka itna % ya zyada ho to direct FTL
  'ftl_fill_pct'    => 85,

  // Club / PTL plan isse kam fill ho to "Hold & consolidate" (overdue stores ko chhod kar)
  'min_fill_pct'    => 60,

  // Club banate waqt itna fill ho jaye to aur store add karna band
  'target_fill_pct' => 95,

  // Club load me do consecutive stores ke beech maximum distance (km, straight-line)
  'club_radius_km'  => 80,

  // Last dispatch se itne din = Watch / Critical (Critical stores low fill par bhi "Dispatch now")
  'watch_days'      => 2,
  'critical_days'   => 3,
  // Isse zyada din wait karne wale stores highlight + 'Dispatch now' suggest (kam fill par bhi)
  'due_days'        => 3,

  // Auto trips: "low stock" store = PKT is kam isse AND last dispatch itne din ke andar hua ho -> uska trip nahi banta
  // (na akela, na kisi aur trip me). Desk ke Auto trips panel me ye default values hain.
  'low_stock_pkt'   => 100,
  'low_stock_days'  => 3,

  // true = Vehicle Master (vehicles table) se gaadi types aur capacity lo.
  // Master khali ho to neeche wali fallback list use hogi.
  'use_vehicle_master' => true,

  // capacity = PKT (challans) per vehicle, value_cap = max load value (₹)
  'fallback_vehicles' => [
    ['type' => '14FT', 'capacity' => 120, 'value_cap' => 1000000],
    ['type' => '19FT', 'capacity' => 200, 'value_cap' => 1800000],
    ['type' => '24FT', 'capacity' => 260, 'value_cap' => 2200000],
    ['type' => '32FT', 'capacity' => 300, 'value_cap' => 2500000],
  ],

  // store_status me ye shabd hon to store "Closed - hold" mana jayega
  'closed_status_words' => ['closed', 'inactive', 'shut', 'temp closed', 'temporary closed'],
];
