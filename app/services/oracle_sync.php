<?php
declare(strict_types=1);
require_once TMS_ROOT . '/app/services/prd_planner.php';
/*
 * Oracle -> PostgreSQL sync (SELECT only on Oracle).
 *   tms_store_sync_from_oracle() : MISRETAIL.T_STORE_OPENING_DATE -> stores
 *   tms_prd_sync_from_oracle()   : MISRETAIL.CUBE$SALDC (CHALLAN_STATUS = PENDING) -> prd_pending
 * Uses OCI8 (same as the working PKT Challan app). pdo_oci is not needed.
 */

function tms_oracle_cfg(): array {
  $c = require TMS_ROOT . '/config/oracle.php';
  $c += ['challan_status' => 'PENDING', 'days_back' => 0, 'remove_not_pending' => true, 'store_table' => 'MISRETAIL.T_STORE_OPENING_DATE',
    'store_where' => '', 'store_code_from' => 'INITIAL_NAME', 'store_exclude_dc_sites' => true, 'store_column_map' => [], 'sync_stores_with_prd' => true,
    'pkt_basis' => 'CHALLAN_NO', 'qty_value_mode' => 'line_sum', 'allow_empty_sync' => false];
  $c['pkt_basis'] = strtoupper((string)$c['pkt_basis']) === 'CHALLAN_BARCODE' ? 'CHALLAN_BARCODE' : 'CHALLAN_NO';
  $c['qty_value_mode'] = $c['qty_value_mode'] === 'challan_max' ? 'challan_max' : 'line_sum';
  $sites = $c['src_site_codes'] ?? ['123'];
  if (!is_array($sites)) $sites = explode(',', (string)$sites);
  // Sites added from Masters → Warehouses (stored in the database) are synced too
  try { $extra = function_exists('db') ? (string)(db()->query("SELECT v FROM tms_meta WHERE k='extra_src_site_codes'")->fetchColumn() ?: '') : ''; $sites = array_merge($sites, explode(',', $extra)); } catch (Throwable $e) {}
  $c['src_site_codes'] = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x), $sites), fn($x) => $x !== '')));
  return $c;
}

/** @return resource OCI8 connection */
function tms_oracle_connect(array $cfg) {
  if (!function_exists('oci_connect')) throw new Exception('PHP OCI8 extension enabled nahi hai. Aapke working application me jo Oracle OCI8 connection use ho raha hai, wahi XAMPP PHP/Apache me enable hona chahiye (extension=oci8_19 ya compatible OCI8 DLL), phir Apache restart karein. pdo_oci ki zaroorat nahi hai.');
  $u = (string)($cfg['user'] ?? ''); $p = (string)($cfg['password'] ?? ''); $cs = (string)($cfg['connect'] ?? '');
  if ($cs === '' && !empty($cfg['dsn']) && preg_match('~oci:dbname=(//[^;]+|[^;]+)~i', (string)$cfg['dsn'], $m)) $cs = trim($m[1]);
  if ($u === '' || $p === '' || $u === 'YOUR_ORACLE_USERNAME' || $p === 'YOUR_ORACLE_PASSWORD' || $cs === '' || stripos($cs, 'YOUR_ORACLE_') !== false)
    throw new Exception('Oracle connection configure nahi hai. config/oracle.php me working application wale user, password aur connect string copy karein.');
  $c = @oci_connect($u, $p, $cs, (string)($cfg['charset'] ?? 'AL32UTF8'));
  if (!$c) { $e = oci_error(); throw new Exception('Oracle connection failed: ' . trim((string)($e['message'] ?? 'unknown OCI8 error'))); }
  $s = oci_parse($c, "ALTER SESSION SET NLS_DATE_FORMAT='YYYY-MM-DD HH24:MI:SS'");
  if ($s) { @oci_execute($s); oci_free_statement($s); }
  return $c;
}

/** Parse + bind + execute a SELECT. */
function tms_oracle_query($conn, string $sql, array $binds = []) {
  $chk = preg_replace('~/\*.*?\*/~s', '', $sql);
  if (!preg_match('/^\s*(SELECT|WITH)\b/i', $chk) || preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|TRUNCATE|GRANT|BEGIN|EXECUTE)\b/i', $chk)) throw new Exception('Only SELECT is allowed on Oracle.');
  $st = oci_parse($conn, $sql);
  if (!$st) { $e = oci_error($conn); throw new Exception('Oracle query parse failed: ' . ($e['message'] ?? '')); }
  foreach ($binds as $k => $v) { $binds[$k] = (string)$v; oci_bind_by_name($st, $k, $binds[$k]); }
  oci_set_prefetch($st, 1000);
  if (!@oci_execute($st)) { $e = oci_error($st); oci_free_statement($st); throw new Exception('Oracle SELECT failed: ' . trim((string)($e['message'] ?? ''))); }
  return $st;
}

function tms_ora_date($raw): ?string {
  if ($raw instanceof DateTimeInterface) return $raw->format('Y-m-d');
  if ($raw === null || trim((string)$raw) === '') return null;
  $ts = strtotime((string)$raw); return $ts === false ? null : date('Y-m-d', $ts);
}

function tms_cut(?string $s, int $n): ?string {
  if ($s === null) return null; $s = trim($s); if ($s === '') return null;
  return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}

/** Record a sync attempt (shown on the PRD Pending button). */
function tms_sync_log(PDO $d, string $kind, bool $ok, string $msg, ?int $rows = null): void {
  try { $d->prepare("INSERT INTO tms_sync_log(kind,ok,message,row_count,created_by) VALUES(?,?,?,?,?)")->execute([$kind, $ok ? 'true' : 'false', tms_cut($msg, 2000), $rows, $_SESSION['user_id'] ?? null]); } catch (Throwable $e) {}
}

/**
 * Oracle connection state for the UI, without connecting:
 * no_oci8 | not_configured | never | ok | failed
 */
function tms_oracle_status(PDO $d, string $kind = 'prd'): array {
  $cfg = []; try { $cfg = tms_oracle_cfg(); } catch (Throwable $e) {}
  $u = (string)($cfg['user'] ?? ''); $p = (string)($cfg['password'] ?? ''); $c = (string)($cfg['connect'] ?? '');
  $configured = $u !== '' && $p !== '' && $c !== '' && $u !== 'YOUR_ORACLE_USERNAME' && $p !== 'YOUR_ORACLE_PASSWORD' && stripos($c, 'YOUR_ORACLE_') === false;
  $last = null; $lastOk = null;
  try {
    $q = $d->prepare("SELECT ok,message,row_count,created_at FROM tms_sync_log WHERE kind=? ORDER BY id DESC LIMIT 1"); $q->execute([$kind]); $last = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $q = $d->prepare("SELECT message,row_count,created_at FROM tms_sync_log WHERE kind=? AND ok ORDER BY id DESC LIMIT 1"); $q->execute([$kind]); $lastOk = $q->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {}
  if (!function_exists('oci_connect')) $state = 'no_oci8';
  elseif (!$configured) $state = 'not_configured';
  elseif (!$last) $state = 'never';
  else $state = $last['ok'] ? 'ok' : 'failed';
  return ['state' => $state, 'last' => $last, 'last_ok' => $lastOk, 'has_local' => is_file(TMS_ROOT . '/config/oracle.local.php')];
}

/* ======================================================================
 * STORE MASTER  <-  MISRETAIL.T_STORE_OPENING_DATE
 * ==================================================================== */
function tms_store_sync_from_oracle(PDO $d, $conn = null): array {
  $cfg = tms_oracle_cfg(); $own = false;
  if (!$conn) { $conn = tms_oracle_connect($cfg); $own = true; }
  $table = (string)$cfg['store_table'];
  if (!preg_match('/^[A-Za-z0-9_$#]+(\.[A-Za-z0-9_$#]+)?$/', $table)) throw new Exception('config/oracle.php: store_table ka naam galat hai.');
  // Stores (LOCATION='STORE') plus the two source DCs. Only the columns the TMS uses are read.
  $dcIn = []; $binds = [];
  foreach ((array)$cfg['src_site_codes'] as $i => $sc) { $dcIn[] = ':dc' . $i; $binds[':dc' . $i] = (string)$sc; }
  // Only stores with these STORE_STATUS values come in (config/oracle.php 'store_statuses'); the source DCs are taken whatever their status.
  $stIn = []; foreach (array_values((array)($cfg['store_statuses'] ?? ['ACTIVE', 'UPCOMING_STORE'])) as $i => $sv) { $stIn[] = ':ss' . $i; $binds[':ss' . $i] = strtoupper(trim((string)$sv)); }
  $st = tms_oracle_query($conn, "SELECT SITE_CODE, STORE_NAME, INITIAL_NAME, OPENING_DATE, STATE, STORE_STATUS, CLUSTER_TYPE, CITY_NAME, DISTRICT, LOCATION, EMAIL_ID, STORE_GRADE, REGION_TYPE, LONGITUDE, LATITUDE
    FROM $table WHERE (LOCATION = 'STORE'" . ($stIn ? " AND UPPER(TRIM(STORE_STATUS)) IN (" . implode(',', $stIn) . ")" : '') . ")" . ($dcIn ? " OR (LOCATION = 'DC' AND SITE_CODE IN (" . implode(',', $dcIn) . "))" : ''), $binds);

  // Column auto-detect (first match wins), config map overrides
  $cands = [
    'site_code' => ['SITE_CODE', 'ADMSITE_CODE', 'STORE_CODE'],
    'initial_name' => ['INITIAL_NAME', 'SHORT_NAME'],
    'store_name' => ['STORE_NAME', 'SITE_NAME', 'NAME', 'STORE_DESC'],
    'city' => ['CITY', 'CITY_NAME', 'DISTRICT', 'LOCATION'],
    'state' => ['STATE', 'STATE_NAME'],
    'address' => ['ADDRESS', 'ADDRESS1', 'SITE_ADDRESS', 'STORE_ADDRESS', 'ADD1'],
    'pincode' => ['PINCODE', 'PIN_CODE', 'PIN', 'ZIP', 'POSTAL_CODE'],
    'latitude' => ['LATITUDE', 'LAT'],
    'longitude' => ['LONGITUDE', 'LONG', 'LNG', 'LON'],
    'cluster_type' => ['CLUSTER_TYPE', 'CLUSTER'],
    'store_status' => ['STORE_STATUS', 'STATUS'],
    'location' => ['LOCATION'],
    'district' => ['DISTRICT'],
    'grade' => ['STORE_GRADE'],
    'region' => ['REGION_TYPE'],
    'email' => ['EMAIL_ID'],
    'opening' => ['OPENING_DATE'],
  ];
  $ncols = oci_num_fields($st); $have = [];
  for ($i = 1; $i <= $ncols; $i++) $have[strtoupper((string)oci_field_name($st, $i))] = true;
  $map = [];
  foreach ($cands as $k => $list) foreach ($list as $c) if (isset($have[$c])) { $map[$k] = $c; break; }
  foreach ((array)$cfg['store_column_map'] as $k => $c) { $c = strtoupper(trim((string)$c)); if ($c !== '' && isset($have[$c])) $map[$k] = $c; }
  if (empty($map['site_code'])) throw new Exception("$table me SITE_CODE column nahi mila. config/oracle.php ke store_column_map me 'site_code' set karein.");
  if (empty($map['initial_name']) && empty($map['store_name'])) throw new Exception("$table me INITIAL_NAME / STORE_NAME column nahi mila. store_column_map set karein.");

  $closedWords = ['closed', 'inactive', 'shut'];
  if (is_file(TMS_ROOT . '/config/prd_planning.php')) { $pc = require TMS_ROOT . '/config/prd_planning.php'; if (!empty($pc['closed_status_words'])) $closedWords = array_map('strtolower', (array)$pc['closed_status_words']); }
  $dcSites = [];
  if ($cfg['store_exclude_dc_sites']) {
    $dcSites = array_flip($cfg['src_site_codes']);
    try { foreach ($d->query("SELECT dc_code FROM tms_dc_master")->fetchAll(PDO::FETCH_COLUMN) as $c) $dcSites[trim((string)$c)] = 1; } catch (Throwable $e) {}
  }
  $codeFrom = strtoupper((string)$cfg['store_code_from']) === 'SITE_CODE' ? 'site_code' : 'initial_name';

  $bySite = $d->prepare("SELECT id FROM stores WHERE oracle_site_code=? LIMIT 1");
  $byCode = $d->prepare("SELECT id FROM stores WHERE oracle_site_code IS NULL AND (lower(store_code)=lower(?) OR lower(store_code)=lower(?)) LIMIT 1");
  $byName = $d->prepare("SELECT id FROM stores WHERE oracle_site_code IS NULL AND lower(store_name)=lower(?) LIMIT 1");
  $codeTaken = $d->prepare("SELECT 1 FROM stores WHERE lower(store_code)=lower(?) LIMIT 1");
  $upd = $d->prepare("UPDATE stores SET oracle_site_code=?, store_name=COALESCE(?,store_name), city=COALESCE(?,city), state=COALESCE(?,state), address=COALESCE(?,address),
      pincode=COALESCE(?,pincode), latitude=CASE WHEN ?::numeric<>0 THEN ?::numeric ELSE latitude END, longitude=CASE WHEN ?::numeric<>0 THEN ?::numeric ELSE longitude END,
      cluster_type=COALESCE(?,cluster_type), store_status=COALESCE(?,store_status), active=?,
      district=COALESCE(?,district), store_grade=COALESCE(?,store_grade), region_type=COALESCE(?,region_type), email_id=COALESCE(?,email_id), opening_date=COALESCE(?::date,opening_date),
      oracle_synced_at=CURRENT_TIMESTAMP WHERE id=?");
  $ins = $d->prepare("INSERT INTO stores(store_code,store_name,city,state,address,pincode,latitude,longitude,cluster_type,store_status,active,oracle_site_code,district,store_grade,region_type,email_id,opening_date,oracle_synced_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?::date,CURRENT_TIMESTAMP)");

  $stats = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'skipped_dc' => 0, 'skipped' => 0, 'no_geo' => 0, 'columns' => $map];
  $d->beginTransaction();
  try {
    while (($r = oci_fetch_assoc($st)) !== false) {
      $stats['read']++;
      $v = []; foreach ($cands as $k => $_) $v[$k] = isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : '';
      $site = $v['site_code'];
      if ($site === '') { $stats['skipped']++; continue; }
      if (strtoupper($v['location']) === 'DC' || isset($dcSites[$site])) {
        // A source DC: keep its name and map position in the DC master (matched by Oracle site code, else by DC code)
        $dl = is_numeric($v['latitude']) ? (float)$v['latitude'] : 0.0; $dg = is_numeric($v['longitude']) ? (float)$v['longitude'] : 0.0;
        if (abs($dl) > 90 || abs($dg) > 180) { $dl = 0.0; $dg = 0.0; }
        $dname = tms_cut($v['store_name'] !== '' ? $v['store_name'] : ($v['initial_name'] !== '' ? $v['initial_name'] : 'DC ' . $site), 150);
        $dq = $d->prepare("SELECT id FROM tms_dc_master WHERE oracle_site_code=? OR (oracle_site_code IS NULL AND (dc_code=? OR dc_code=?)) ORDER BY (oracle_site_code=?) DESC NULLS LAST LIMIT 1");
        $dq->execute([$site, $site, $v['initial_name'], $site]); $did = $dq->fetchColumn();
        if ($did) $d->prepare("UPDATE tms_dc_master SET oracle_site_code=?, dc_name=?, latitude=CASE WHEN ?::numeric<>0 THEN ?::numeric ELSE latitude END, longitude=CASE WHEN ?::numeric<>0 THEN ?::numeric ELSE longitude END WHERE id=?")->execute([$site, $dname, $dl, $dl, $dg, $dg, (int)$did]);
        else $d->prepare("INSERT INTO tms_dc_master(dc_code,dc_name,latitude,longitude,active,oracle_site_code) VALUES(?,?,?,?,TRUE,?)")->execute([tms_cut($v['initial_name'] !== '' ? $v['initial_name'] : $site, 60), $dname, $dl, $dg, $site]);
        $stats['skipped_dc']++; continue;
      }
      $extra = [tms_cut($v['district'], 100), tms_cut($v['grade'], 50), tms_cut($v['region'], 100), tms_cut($v['email'], 150), ($v['opening'] !== '' && ($ts = strtotime($v['opening'])) !== false) ? date('Y-m-d', $ts) : null];
      $initial = $v['initial_name'] !== '' ? $v['initial_name'] : $v['store_name'];
      $name = $v['store_name'] !== '' ? $v['store_name'] : $initial;
      if ($name === '') $name = 'SITE ' . $site;
      $lat = is_numeric($v['latitude']) ? (float)$v['latitude'] : 0.0; $lng = is_numeric($v['longitude']) ? (float)$v['longitude'] : 0.0;
      if (abs($lat) > 90 || abs($lng) > 180) { $lat = 0.0; $lng = 0.0; }
      $status = $v['store_status'];
      $active = true; foreach ($closedWords as $w) if ($w !== '' && str_contains(strtolower($status), $w)) $active = false;

      $bySite->execute([$site]); $id = $bySite->fetchColumn();
      if (!$id && $initial !== '') { $byCode->execute([$initial, $site]); $id = $byCode->fetchColumn(); }
      if (!$id) { $byCode->execute([$site, $site]); $id = $byCode->fetchColumn(); }
      if (!$id) { $byName->execute([$name]); $id = $byName->fetchColumn(); }
      // Existing store names are only replaced when Oracle has a real name column (not just INITIAL_NAME)
      $updName = $v['store_name'] !== '' ? tms_cut($v['store_name'], 150) : null;
      $common = [tms_cut($v['city'], 100), tms_cut($v['state'], 100), tms_cut($v['address'], 1000), tms_cut($v['pincode'], 10)];
      if ($id) {
        $upd->execute(array_merge([$site, $updName], $common, [$lat, $lat, $lng, $lng, tms_cut($v['cluster_type'], 100), tms_cut($status, 100), $active ? 'true' : 'false'], $extra, [(int)$id]));
        $stats['updated']++;
      } else {
        $code = $codeFrom === 'site_code' ? $site : ($initial !== '' ? $initial : $site);
        $code = tms_cut($code, 60);
        $codeTaken->execute([$code]); if ($codeTaken->fetchColumn()) $code = tms_cut($code, 50) . '-' . $site;
        $ins->execute(array_merge([$code, tms_cut($name, 150)], $common, [$lat, $lng, tms_cut($v['cluster_type'], 100), tms_cut($status, 100), $active ? 'true' : 'false', $site], $extra));
        $stats['inserted']++;
      }
    }
    // Stores that came from Oracle earlier but are not returned now (closed / not ACTIVE or UPCOMING_STORE) become inactive.
    // Rows touched above carry this transaction's timestamp, so older ones were not in the result. Nothing is deleted.
    $stats['deactivated'] = 0;
    if ($stats['inserted'] + $stats['updated'] > 0) $stats['deactivated'] = $d->exec("UPDATE stores SET active=FALSE WHERE oracle_site_code IS NOT NULL AND COALESCE(active,TRUE) AND oracle_synced_at < CURRENT_TIMESTAMP");
    $d->commit();
  } catch (Throwable $e) { if ($d->inTransaction()) $d->rollBack(); throw $e; }
  finally { oci_free_statement($st); if ($own) @oci_close($conn); }
  try { $stats['no_geo'] = (int)$d->query("SELECT COUNT(*) FROM stores WHERE oracle_site_code IS NOT NULL AND COALESCE(active,TRUE) AND COALESCE(latitude,0)=0 AND COALESCE(longitude,0)=0")->fetchColumn(); } catch (Throwable $e) {}
  return $stats;
}

/* ======================================================================
 * PRD PENDING  <-  MISRETAIL.CUBE$SALDC  (CHALLAN_STATUS = PENDING only)
 * ==================================================================== */
/** Bind a list of strings as :pfx0,:pfx1,… and return the placeholder list. */
function tms_bind_list(array $vals, string $pfx, array &$binds): string {
  $ph = []; foreach (array_values($vals) as $i => $v) { $ph[] = ':' . $pfx . $i; $binds[':' . $pfx . $i] = strtoupper(trim((string)$v)); }
  return implode(',', $ph);
}

/**
 * Pending-challan base shared by sync and reconciliation (same rules as the PRD Oracle report):
 *   C0 = cube lines (cube, source sites, status, optional date window)
 *   I  = item master, de-duplicated per ICODE (a repeated ICODE can never double a line)
 *   C  = lines whose item exists, division not excluded, section not excluded; DIV_CLASS = APPS | GM
 */
function tms_prd_base_cte(array $cfg, array &$binds, bool $withWindow = true): string {
  $sites = $cfg['src_site_codes'];
  if (!$sites) throw new Exception('Warehouses (Oracle sites) me kam se kam ek source site add karein.');
  $binds[':cube'] = (string)($cfg['cube_code'] ?? '15');
  $binds[':status'] = strtoupper(trim((string)$cfg['challan_status'])) ?: 'PENDING';
  $ph = []; foreach ($sites as $i => $x) { $ph[] = ':site' . $i; $binds[':site' . $i] = $x; }
  $days = max(0, min(3650, (int)$cfg['days_back']));
  if ($withWindow && $days > 0) $binds[':days'] = $days;
  $apps = (array)($cfg['apps_divisions'] ?? ['MENS', 'KIDS', 'LADIES']);
  $xd = (array)($cfg['exclude_divisions'] ?? ['NON-TRADING', 'FIXED ASSETS']);
  $xs = (array)($cfg['exclude_sections'] ?? ['RT_CONSUMABLES']);
  $w = [];
  if ($xd) $w[] = "UPPER(TRIM(I.DIVISION)) NOT IN (" . tms_bind_list($xd, 'xd', $binds) . ")";
  if ($xs) $w[] = "UPPER(TRIM(I.SECTION)) NOT IN (" . tms_bind_list($xs, 'xs', $binds) . ")";
  $appsIn = $apps ? tms_bind_list($apps, 'ad', $binds) : "'~'";
  return "C0 AS (
  SELECT A.ADMSITE_CODE_SRC, A.ADMSITE_CODE_DES, A.CHALLAN_NO, A.CHALLAN_BARCODE, TRUNC(A.CHALLAN_DATE) AS CHALLAN_DATE, A.BARCODE,
         A.CHALLAN_QTY, A.CHALLAN_AMOUNT, A.OUT_LOCATION, A.PREPARED_BY, A.CHALLAN_STATUS
  FROM MISRETAIL.CUBE\$SALDC A
  WHERE A.CUBE_CODE=:cube AND A.ADMSITE_CODE_SRC IN (" . implode(',', $ph) . ")
    AND UPPER(TRIM(A.CHALLAN_STATUS))=:status" . ($withWindow && $days > 0 ? " AND A.CHALLAN_DATE >= TRUNC(SYSDATE)-:days" : '') . "
),
I AS (SELECT ICODE, MAX(DIVISION) AS DIVISION, MAX(SECTION) AS SECTION FROM MISRETAIL.MAS\$ITEM WHERE ICODE IN (SELECT BARCODE FROM C0) GROUP BY ICODE),
C AS (
  SELECT C0.*, CASE WHEN UPPER(TRIM(I.DIVISION)) IN ($appsIn) THEN 'APPS' ELSE 'GM' END AS DIV_CLASS
  FROM C0 JOIN I ON C0.BARCODE=I.ICODE" . ($w ? " WHERE " . implode(' AND ', $w) : '') . "
)";
}
function tms_store_table(array $cfg): string {
  $t = (string)$cfg['store_table'];
  return preg_match('/^[A-Za-z0-9_$#]+(\.[A-Za-z0-9_$#]+)?$/', $t) ? $t : 'MISRETAIL.T_STORE_OPENING_DATE';
}
function tms_prd_sync_sql(array $cfg, array &$binds): string {
  $t = tms_store_table($cfg);
  $agg = $cfg['qty_value_mode'] === 'challan_max' ? 'MAX' : 'SUM';
  return "WITH " . tms_prd_base_cte($cfg, $binds) . ",
S AS (SELECT SITE_CODE, MAX(INITIAL_NAME) AS INITIAL_NAME, MAX(STATE) AS STATE, MAX(CLUSTER_TYPE) AS CLUSTER_TYPE, MAX(STORE_STATUS) AS STORE_STATUS
      FROM $t WHERE SITE_CODE IN (SELECT ADMSITE_CODE_SRC FROM C UNION SELECT ADMSITE_CODE_DES FROM C) GROUP BY SITE_CODE),
ST AS (SELECT SITE_CODE, INITIAL_NAME FROM $t WHERE INITIAL_NAME IN (SELECT INITIAL_NAME FROM S)),
L AS (SELECT ST.INITIAL_NAME, MAX(TX.INVOICE_DATE) AS LAST_INV FROM MISRETAIL.CUBE\$SALDC TX JOIN ST ON TX.ADMSITE_CODE_DES=ST.SITE_CODE
      WHERE TX.CUBE_CODE=:cube AND TX.INVOICE_DATE IS NOT NULL GROUP BY ST.INITIAL_NAME)
SELECT TO_CHAR(C.ADMSITE_CODE_SRC) AS SOURCE_SITE_CODE, MAX(S1.INITIAL_NAME) AS SOURCE_NAME,
       TO_CHAR(C.ADMSITE_CODE_DES) AS DESTINATION_SITE_CODE, COALESCE(MAX(S2.INITIAL_NAME), TO_CHAR(C.ADMSITE_CODE_DES)) AS DESTINATION_NAME,
       MAX(S2.STATE) AS STATE, MAX(S2.CLUSTER_TYPE) AS CLUSTER_TYPE, MAX(S2.STORE_STATUS) AS STORE_STATUS,
       C.DIV_CLASS AS SUB_DIV, C.CHALLAN_NO, C.CHALLAN_BARCODE, MIN(C.CHALLAN_DATE) AS CHALLAN_DATE,
       $agg(C.CHALLAN_QTY) AS QTY, $agg(C.CHALLAN_AMOUNT) AS VALUE,
       MAX(C.OUT_LOCATION) AS OUT_LOCATION, MAX(C.PREPARED_BY) AS PREPARED_BY, MAX(C.CHALLAN_STATUS) AS CHALLAN_STATUS, MAX(L.LAST_INV) AS LAST_DISPATCH_DATE
FROM C LEFT JOIN S S1 ON C.ADMSITE_CODE_SRC=S1.SITE_CODE LEFT JOIN S S2 ON C.ADMSITE_CODE_DES=S2.SITE_CODE LEFT JOIN L ON L.INITIAL_NAME=S2.INITIAL_NAME
GROUP BY C.ADMSITE_CODE_SRC, C.ADMSITE_CODE_DES, C.CHALLAN_NO, C.CHALLAN_BARCODE, C.DIV_CLASS";
}

function tms_prd_sync_from_oracle(PDO $d, $conn = null): array {
  $cfg = tms_oracle_cfg(); $own = false;
  if (!$conn) { $conn = tms_oracle_connect($cfg); $own = true; }
  $sites = $cfg['src_site_codes'];
  $status = strtoupper(trim((string)$cfg['challan_status'])) ?: 'PENDING';
  $days = max(0, min(3650, (int)$cfg['days_back']));
  $binds = [];
  $sql = tms_prd_sync_sql($cfg, $binds);
  $st = tms_oracle_query($conn, $sql, $binds);

  // One TMS row per (source site, challan, barcode, destination). Oracle returns one row per
  // division (APPS/GM/NT); merge them here so qty/value are never overwritten.
  $rows = []; $read = 0;
  while (($r = oci_fetch_assoc($st)) !== false) {
    $read++;
    $src = trim((string)($r['SOURCE_SITE_CODE'] ?? '')); $dest = trim((string)($r['DESTINATION_NAME'] ?? ''));
    $ch = trim((string)($r['CHALLAN_NO'] ?? '')); $bc = trim((string)($r['CHALLAN_BARCODE'] ?? ''));
    if ($src === '' || $dest === '' || $ch === '') continue;
    $div = strtoupper(trim((string)($r['SUB_DIV'] ?? ''))) ?: 'GM';
    $k = $src . "\x1f" . $ch . "\x1f" . $bc . "\x1f" . $dest . "\x1f" . $div;
    if (!isset($rows[$k])) $rows[$k] = ['src' => $src, 'src_name' => $r['SOURCE_NAME'] ?? null, 'dest_site' => $r['DESTINATION_SITE_CODE'] ?? null, 'dest' => $dest,
      'state' => $r['STATE'] ?? null, 'cluster' => $r['CLUSTER_TYPE'] ?? null, 'store_status' => $r['STORE_STATUS'] ?? null, 'div' => [], 'ch' => $ch, 'bc' => $bc,
      'date' => tms_ora_date($r['CHALLAN_DATE'] ?? null), 'qty' => 0.0, 'value' => 0.0, 'out' => $r['OUT_LOCATION'] ?? null, 'by' => $r['PREPARED_BY'] ?? null, 'status' => $r['CHALLAN_STATUS'] ?? null, 'last_inv' => tms_ora_date($r['LAST_DISPATCH_DATE'] ?? null)];
    $x = &$rows[$k];
    if ($cfg['qty_value_mode'] === 'challan_max') { $x['qty'] = max($x['qty'], (float)($r['QTY'] ?? 0)); $x['value'] = max($x['value'], (float)($r['VALUE'] ?? 0)); }
    else { $x['qty'] += (float)($r['QTY'] ?? 0); $x['value'] += (float)($r['VALUE'] ?? 0); }
    if (!empty($r['SUB_DIV'])) $x['div'][(string)$r['SUB_DIV']] = true;
    unset($x);
  }
  // oci_fetch_assoc() returns false both at the end of data and on an error, so check for an error before trusting the result.
  $fetchErr = oci_error($st);
  oci_free_statement($st); if ($own) @oci_close($conn);
  if ($fetchErr) throw new Exception('Oracle fetch failed after ' . $read . ' row(s); PRD Pending was left unchanged: ' . trim((string)($fetchErr['message'] ?? 'unknown OCI8 error')));
  // An empty result would make the "no longer pending" clean-up below delete the whole board, so it needs an explicit opt-in.
  if (!$rows && empty($cfg['allow_empty_sync'])) throw new Exception("Oracle returned 0 pending challans for these sites; PRD Pending was left unchanged. If the board really should be empty, set 'allow_empty_sync' => true in config/oracle.local.php for one sync.");

  $ins = $d->prepare("INSERT INTO prd_pending(source_site_code,source_name,destination_site_code,destination_name,state,cluster_type,store_status,sub_div,challan_no,challan_barcode,challan_date,qty,value,out_location,prepared_by,challan_status,last_dispatch_date,source_system,source_key,synced_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ORACLE',?,CURRENT_TIMESTAMP)
    ON CONFLICT (source_site_code, challan_no, (COALESCE(challan_barcode,'')), destination_name, (COALESCE(sub_div,''))) DO UPDATE SET source_name=EXCLUDED.source_name,destination_site_code=EXCLUDED.destination_site_code,state=EXCLUDED.state,
      cluster_type=EXCLUDED.cluster_type,store_status=EXCLUDED.store_status,last_dispatch_date=EXCLUDED.last_dispatch_date,sub_div=EXCLUDED.sub_div,challan_date=EXCLUDED.challan_date,qty=EXCLUDED.qty,value=EXCLUDED.value,
      out_location=EXCLUDED.out_location,prepared_by=EXCLUDED.prepared_by,challan_status=EXCLUDED.challan_status,source_system='ORACLE',source_key=EXCLUDED.source_key,synced_at=CURRENT_TIMESTAMP");
  $removed = 0;
  $d->beginTransaction();
  try {
    foreach ($rows as $x) {
      $div = implode(',', array_keys($x['div']));
      $ins->execute([$x['src'], tms_cut($x['src_name'], 150), tms_cut($x['dest_site'], 60), tms_cut($x['dest'], 150), tms_cut($x['state'], 100), tms_cut($x['cluster'], 100), tms_cut($x['store_status'], 100),
        tms_cut($div, 30), tms_cut($x['ch'], 100), $x['bc'], $x['date'], round($x['qty'], 2), round($x['value'], 2), tms_cut($x['out'], 150), tms_cut($x['by'], 150), tms_cut($x['status'], 100), $x['last_inv'],
        tms_cut($x['ch'] . '|' . $x['bc'], 180)]);
    }
    if (!empty($cfg['remove_not_pending'])) {
      // Rows of these DCs that Oracle did not return now are no longer pending (inside the window).
      // CURRENT_TIMESTAMP is fixed for the transaction, so untouched rows have an older synced_at.
      $sp = implode(',', array_fill(0, count($sites), '?'));
      // With days_back > 0 Oracle only returns challans inside the window, so only rows inside it may be judged "no longer pending".
      $win = $days > 0 ? " AND (p.challan_date IS NULL OR p.challan_date >= CURRENT_DATE - ?::int)" : '';
      $del = $d->prepare("DELETE FROM prd_pending p WHERE p.source_system='ORACLE' AND p.source_site_code IN ($sp) AND p.synced_at < CURRENT_TIMESTAMP AND " . prd_pending_where('p') . $win);
      $del->execute($days > 0 ? array_merge($sites, [$days]) : $sites); $removed = $del->rowCount();
    }
    // DC sites removed from config/oracle.php
    $sp = implode(',', array_fill(0, count($sites), '?'));
    $del = $d->prepare("DELETE FROM prd_pending p WHERE p.source_system='ORACLE' AND COALESCE(p.source_site_code,'') NOT IN ($sp) AND " . prd_pending_where('p'));
    $del->execute($sites); $removed += $del->rowCount();
    // Old CSV / non-Oracle rows are not part of the Oracle-only board any more
    $removed += $d->exec("DELETE FROM prd_pending p WHERE COALESCE(p.source_system,'')<>'ORACLE' AND " . prd_pending_where('p'));
    $d->commit();
  } catch (Throwable $e) { if ($d->inTransaction()) $d->rollBack(); throw $e; }
  return ['read' => $read, 'challans' => count($rows), 'removed' => $removed, 'status' => $status, 'days' => $days, 'sites' => $sites];
}

/* ======================================================================
 * SYNC RUNNER (manual button, page-triggered background run, scheduled task)
 * One sync at a time: a PostgreSQL advisory lock is held while it runs.
 * ==================================================================== */
function tms_sync_try_lock(PDO $d): bool { return (bool)$d->query("SELECT pg_try_advisory_lock(727002)")->fetchColumn(); }
function tms_sync_unlock(PDO $d): void { try { $d->query("SELECT pg_advisory_unlock(727002)"); } catch (Throwable $e) {} }
function tms_sync_running(PDO $d): bool { if (tms_sync_try_lock($d)) { tms_sync_unlock($d); return false; } return true; }

/**
 * Connect to Oracle, sync (Store Master only when $withStores) and PRD Pending, and write the sync log. Never throws.
 * The caller must hold the sync lock. $source is only a label in the log ('manual' | 'auto').
 * @return array{ok:bool,message:string,challans:int}
 */
function tms_run_prd_sync(PDO $d, bool $withStores, string $source = 'manual'): array {
  $ora = null;
  try {
    $ocfg = tms_oracle_cfg(); $ora = tms_oracle_connect($ocfg); $parts = [];
    if ($withStores && !empty($ocfg['sync_stores_with_prd'])) {
      try { $ss = tms_store_sync_from_oracle($d, $ora); $parts[] = "Store Master: {$ss['inserted']} new, {$ss['updated']} updated"; }
      catch (Throwable $se) { $parts[] = 'Store Master sync skipped: ' . $se->getMessage(); }
    }
    $ps = tms_prd_sync_from_oracle($d, $ora);
    $parts[] = "PRD Pending: {$ps['challans']} pending challan(s) loaded" . ($ps['removed'] ? ", {$ps['removed']} no-longer-pending removed" : '') . ' (CHALLAN_STATUS = ' . $ps['status'] . ($ps['days'] ? ", last {$ps['days']} days" : ', all dates') . ')';
    @oci_close($ora); $ora = null;
    $msg = ($source === 'auto' ? '[auto] ' : '') . implode(' · ', $parts);
    tms_sync_log($d, 'prd', true, $msg, (int)$ps['challans']);
    return ['ok' => true, 'message' => $msg, 'challans' => (int)$ps['challans']];
  } catch (Throwable $x) {
    if ($d->inTransaction()) $d->rollBack();
    tms_sync_log($d, 'prd', false, ($source === 'auto' ? '[auto] ' : '') . $x->getMessage());
    return ['ok' => false, 'message' => $x->getMessage(), 'challans' => 0];
  } finally { if ($ora) @oci_close($ora); }
}

/**
 * Called when the PRD Pending page opens. If the last good sync is older than prd_sync.max_age_sec and none is running, starts
 * app/cli/sync_prd.php in the background (the page does not wait for it).
 * @return array{state:string} off | fresh | running | started
 */
function tms_prd_autosync_kick(PDO $d): array {
  $c = (array)(app_cfg('prd_sync') ?? []);
  if (empty($c['auto'])) return ['state' => 'off'];
  $os = tms_oracle_status($d, 'prd');
  if (in_array($os['state'], ['no_oci8', 'not_configured'], true)) return ['state' => 'off'];
  if (tms_sync_running($d)) return ['state' => 'running'];
  $ageOk = $d->query("SELECT EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - MAX(created_at))) FROM tms_sync_log WHERE kind='prd' AND ok")->fetchColumn();
  $ageAny = $d->query("SELECT EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - MAX(created_at))) FROM tms_sync_log WHERE kind='prd'")->fetchColumn();
  if ($ageOk !== null && $ageOk !== false && (float)$ageOk <= (int)($c['max_age_sec'] ?? 120)) return ['state' => 'fresh'];
  if ($ageAny !== null && $ageAny !== false && (float)$ageAny < (int)($c['retry_after_sec'] ?? 60)) return ['state' => 'fresh']; // just tried (and failed): do not hammer Oracle on every refresh
  return ['state' => tms_prd_sync_spawn(false, false) ? 'started' : 'off'];
}

/** Starts app/cli/sync_prd.php in the background (Windows). $withStores also refreshes Store Master (admins only); $manual = started by the Refresh button. Returns false if it cannot be started. */
function tms_prd_sync_spawn(bool $withStores, bool $manual = true): bool {
  $c = (array)(app_cfg('prd_sync') ?? []);
  $php = str_replace('/', '\\', (string)($c['php_cli'] ?? 'C:/xampp/php/php.exe'));
  $script = str_replace('/', '\\', TMS_ROOT . '/app/cli/sync_prd.php');
  if (!is_file($php) || !is_file($script) || !function_exists('popen')) return false;
  pclose(popen('start "" /B "' . $php . '" "' . $script . '"' . ($withStores ? ' --stores' : '') . ($manual ? ' --manual' : '') . ' > NUL 2>&1', 'r'));
  return true;
}
