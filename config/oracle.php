<?php
// Oracle is used for SELECT-only reads. Match the OCI8 settings from your existing working app.
//
// CREDENTIALS: user / password / connect ko config/oracle.local.php me rakhein
// (oracle.local.example.php copy karke naam badlein). Wo file update ZIP me kabhi nahi aati,
// isliye naya version daalne par aapke credentials overwrite nahi honge.
// oracle.local.php me jo bhi key likhenge wo yahan ki value ko override karegi.
$local = is_file(__DIR__ . '/oracle.local.php') ? (require __DIR__ . '/oracle.local.php') : [];
if (!is_array($local)) $local = [];
return array_replace([
  'user' => getenv('TMS_ORACLE_USER') ?: 'MISRETAIL',
  'password' => getenv('TMS_ORACLE_PASSWORD') ?: 'CKSPL',
  // OCI8 connect string examples: //dbhost:1521/SERVICE_NAME or TNS alias from tnsnames.ora
  'connect' => getenv('TMS_ORACLE_CONNECT') ?: '//10.0.0.15/GINESYS',
  'charset' => 'AL32UTF8',

  // ---------------- PRD PENDING (MISRETAIL.CUBE$SALDC) ----------------
  'cube_code' => '15',
  // Put BOTH DC Oracle SITE_CODE values here, e.g. ['123', '456']; do not use DC display names.
  'src_site_codes' => array_values(array_filter(array_map('trim', explode(',', getenv('TMS_ORACLE_SRC_SITE_CODES') ?: '123','420')))),
  // Sirf isi CHALLAN_STATUS wale challans aayenge (case/space ignore hota hai).
  'challan_status' => 'PENDING',
  // Kitne din purane pending challans tak dekhna hai. 0 = koi date limit nahi (saare pending, PRD report jaisa).
  'days_back' => 0,
  // Division classes (PRD report jaisa): ye divisions = APPS, baaki = GM
  'apps_divisions' => ['MENS', 'KIDS', 'LADIES'],
  // Ye divisions / sections PRD me nahi aate. Item master me na mile ya section khali ho to bhi line bahar
  // (Oracle report ke "B.DIVISION NOT IN … AND B.SECTION NOT IN …" jaisa).
  'exclude_divisions' => ['NON-TRADING', 'FIXED ASSETS'],
  'exclude_sections' => ['RT_CONSUMABLES'],
  // true = har sync par jo challan ab Oracle me Pending nahi hai (dispatch/cancel ho gaya),
  // wo PRD Pending se hata diya jayega. Trip me already gaye challans kabhi nahi hatte.
  'remove_not_pending' => true,
  // PKT kaise gine: 'CHALLAN_NO' = distinct challan number, 'CHALLAN_BARCODE' = distinct challan barcode (har packet ka barcode).
  // PRD Reconciliation page dono counts dikhata hai; jo aapke PKT Challan app se match kare wahi rakhein.
  'pkt_basis' => 'CHALLAN_NO',
  // Qty/Value: 'line_sum' = har item line ka CHALLAN_QTY/AMOUNT jodo (default).
  // 'challan_max' = agar CHALLAN_QTY/AMOUNT har line par challan ka TOTAL repeat hota hai (Reconciliation page batayega).
  'qty_value_mode' => 'line_sum',

  // ---------------- STORE MASTER (MISRETAIL.T_STORE_OPENING_DATE) ----------------
  'store_table' => 'MISRETAIL.T_STORE_OPENING_DATE',
  // Optional extra filter (Oracle SQL, bina WHERE ke), jaise: "STORE_STATUS <> 'CLOSED'"
  'store_where' => '',
  // TMS store_code kis column se bane: 'INITIAL_NAME' (PRD me yahi destination naam aata hai) ya 'SITE_CODE'
  'store_code_from' => 'INITIAL_NAME',
  // src_site_codes wale DC sites ko Store Master me na daalein
  'store_exclude_dc_sites' => true,
  // Column auto-detect hota hai. Agar aapki table me naam alag hain to yahan batayein, jaise:
  // 'store_column_map' => ['city' => 'CITY_NAME', 'latitude' => 'LAT', 'longitude' => 'LON'],
  // Keys: site_code, initial_name, store_name, city, state, address, pincode, latitude, longitude, cluster_type, store_status
  'store_column_map' => [],
  // PRD Pending ka "Sync Oracle" button pehle Store Master bhi sync kare
  'sync_stores_with_prd' => true,
], $local);
