<?php
// PRD Pending · Reconciliation: Oracle (SELECT only) vs TMS totals, with plain-language findings.
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/DB.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/oracle_sync.php';
require_login();
$d = db();
$cfg = tms_oracle_cfg();
$run = isset($_GET['run']);
$chq = trim((string)($_GET['challan'] ?? ''));
$err = ''; $O = []; $find = [];
$days = max(0, min(3650, (int)$cfg['days_back']));
$T = tms_store_table($cfg);
$n = fn($v) => number_format((float)$v, 0);
$r2 = fn($v) => number_format((float)$v, 2);

// ---------------- TMS side ----------------
$pk = prd_pkt_col('p');
$tms = [];
foreach ($d->query("SELECT COALESCE(source_site_code,'') src,
    COUNT(*) FILTER (WHERE " . prd_pending_where('p') . ") lines_p,
    COUNT(DISTINCT NULLIF(BTRIM(challan_no),'')) FILTER (WHERE " . prd_pending_where('p') . ") ch_p,
    COUNT(DISTINCT NULLIF(BTRIM(challan_barcode),'')) FILTER (WHERE " . prd_pending_where('p') . ") bc_p,
    COALESCE(SUM(qty) FILTER (WHERE " . prd_pending_where('p') . "),0) qty_p,
    COALESCE(SUM(value) FILTER (WHERE " . prd_pending_where('p') . "),0) val_p,
    COUNT(DISTINCT $pk) FILTER (WHERE NOT " . prd_pending_where('p') . ") pkt_trip,
    COALESCE(SUM(qty) FILTER (WHERE NOT " . prd_pending_where('p') . "),0) qty_trip,
    COALESCE(SUM(value) FILTER (WHERE NOT " . prd_pending_where('p') . "),0) val_trip,
    MAX(synced_at) synced
  FROM prd_pending p WHERE p.source_system='ORACLE' AND UPPER(BTRIM(COALESCE(p.challan_status,'')))=" . $d->quote(prd_status_filter()) . " GROUP BY 1 ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC) as $r) $tms[$r['src']] = $r;
$tmsSync = $tms ? max(array_column($tms, 'synced')) : null;

// ---------------- Oracle side ----------------
$ora = null;
if ($run || $chq !== '') {
  try {
    $ora = tms_oracle_connect($cfg);
    $fetchAll = function (string $sql, array $b) use ($ora) { $st = tms_oracle_query($ora, $sql, $b); $o = []; while (($r = oci_fetch_assoc($st)) !== false) $o[] = $r; oci_free_statement($st); return $o; };
    if ($run) {
      $b = []; $cte = tms_prd_base_cte($cfg, $b, true);
      $O['dc'] = $fetchAll("/*R:dc*/ WITH $cte SELECT TO_CHAR(ADMSITE_CODE_SRC) SRC, COUNT(*) LINES, COUNT(DISTINCT CHALLAN_NO) CH, COUNT(DISTINCT CHALLAN_BARCODE) BC,
          SUM(CHALLAN_QTY) QTY, SUM(CHALLAN_AMOUNT) VAL FROM C GROUP BY ADMSITE_CODE_SRC ORDER BY 1", $b);
      $O['hdr'] = $fetchAll("/*R:hdr*/ WITH $cte, H AS (SELECT CHALLAN_NO, CHALLAN_BARCODE, COUNT(*) N, COUNT(DISTINCT CHALLAN_QTY) DQ, COUNT(DISTINCT CHALLAN_AMOUNT) DA,
          MAX(CHALLAN_QTY) MQ, MAX(CHALLAN_AMOUNT) MA FROM C GROUP BY CHALLAN_NO, CHALLAN_BARCODE)
          SELECT COUNT(*) TOTAL, SUM(CASE WHEN N>1 THEN 1 ELSE 0 END) MULTI, SUM(CASE WHEN N>1 AND DQ=1 AND DA=1 THEN 1 ELSE 0 END) SAME, SUM(MQ) QTY_MAX, SUM(MA) VAL_MAX FROM H", $b)[0] ?? [];
      $O['dupsite'] = $fetchAll("/*R:dupsite*/ WITH $cte SELECT COUNT(*) DUPS, MAX(N) MAXN FROM (SELECT SITE_CODE, COUNT(*) N FROM $T WHERE SITE_CODE IN (SELECT ADMSITE_CODE_DES FROM C UNION SELECT ADMSITE_CODE_SRC FROM C) GROUP BY SITE_CODE HAVING COUNT(*)>1)", $b)[0] ?? [];
      $O['dupitem'] = $fetchAll("/*R:dupitem*/ WITH $cte SELECT COUNT(*) DUPS, MAX(N) MAXN FROM (SELECT ICODE, COUNT(*) N FROM MISRETAIL.MAS\$ITEM WHERE ICODE IN (SELECT BARCODE FROM C) GROUP BY ICODE HAVING COUNT(*)>1)", $b)[0] ?? [];
      $O['old'] = $fetchAll("/*R:old*/ WITH $cte SELECT COUNT(*) LINES, SUM(C.CHALLAN_QTY) QTY, SUM(C.CHALLAN_AMOUNT) VAL FROM C
          LEFT JOIN MISRETAIL.MAS\$ITEM B ON C.BARCODE=B.ICODE LEFT JOIN $T S1 ON C.ADMSITE_CODE_SRC=S1.SITE_CODE LEFT JOIN $T S2 ON C.ADMSITE_CODE_DES=S2.SITE_CODE", $b)[0] ?? [];
      $b2 = []; $cte2 = tms_prd_base_cte($cfg, $b2, false);
      $O['all'] = $fetchAll("/*R:all*/ WITH $cte2 SELECT COUNT(DISTINCT CHALLAN_NO) CH, COUNT(DISTINCT CHALLAN_BARCODE) BC, SUM(CHALLAN_QTY) QTY, SUM(CHALLAN_AMOUNT) VAL, MIN(CHALLAN_DATE) OLDEST FROM C", $b2)[0] ?? [];
      $b3 = [':cube' => (string)($cfg['cube_code'] ?? '15'), ':days' => $days ?: 30]; $ph = [];
      foreach ($cfg['src_site_codes'] as $i => $s) { $ph[] = ':site' . $i; $b3[':site' . $i] = $s; }
      $O['status'] = $fetchAll("/*R:status*/ SELECT NVL(UPPER(TRIM(CHALLAN_STATUS)),'(BLANK)') ST, COUNT(DISTINCT CHALLAN_NO) CH, SUM(CHALLAN_AMOUNT) VAL FROM MISRETAIL.CUBE\$SALDC
          WHERE CUBE_CODE=:cube AND ADMSITE_CODE_SRC IN (" . implode(',', $ph) . ") AND CHALLAN_DATE >= TRUNC(SYSDATE)-:days GROUP BY NVL(UPPER(TRIM(CHALLAN_STATUS)),'(BLANK)') ORDER BY 2 DESC", $b3);
    }
    if ($chq !== '') {
      $O['ch'] = $fetchAll("/*R:ch*/ SELECT TO_CHAR(ADMSITE_CODE_SRC) SRC, TO_CHAR(ADMSITE_CODE_DES) DES, CHALLAN_NO, CHALLAN_BARCODE, TRUNC(CHALLAN_DATE) CHALLAN_DATE, BARCODE, CHALLAN_QTY, CHALLAN_AMOUNT, CHALLAN_STATUS, CUBE_CODE
          FROM MISRETAIL.CUBE\$SALDC WHERE CUBE_CODE=:cube AND (TO_CHAR(CHALLAN_NO)=:ch OR TO_CHAR(CHALLAN_BARCODE)=:ch) AND ROWNUM<=500", [':cube' => (string)($cfg['cube_code'] ?? '15'), ':ch' => $chq]);
      $q = $d->prepare("SELECT source_site_code,destination_name,challan_no,challan_barcode,sub_div,qty,value,challan_status,planned_trip_id,synced_at FROM prd_pending WHERE challan_no=? OR challan_barcode=? ORDER BY id");
      $q->execute([$chq, $chq]); $O['ch_tms'] = $q->fetchAll(PDO::FETCH_ASSOC);
    }
  } catch (Throwable $x) { $err = $x->getMessage(); }
  finally { if ($ora) @oci_close($ora); }
}

// ---------------- Findings ----------------
if ($run && !$err) {
  $oSum = ['ch' => 0, 'bc' => 0, 'qty' => 0.0, 'val' => 0.0, 'lines' => 0];
  foreach ($O['dc'] as $r) { $oSum['ch'] += (int)$r['CH']; $oSum['bc'] += (int)$r['BC']; $oSum['qty'] += (float)$r['QTY']; $oSum['val'] += (float)$r['VAL']; $oSum['lines'] += (int)$r['LINES']; }
  $tSum = ['pkt' => 0, 'qty' => 0.0, 'val' => 0.0, 'pkt_trip' => 0, 'qty_trip' => 0.0, 'val_trip' => 0.0];
  $basis = prd_pkt_basis();
  foreach ($tms as $r) { $tSum['pkt'] += (int)($basis === 'CHALLAN_BARCODE' ? $r['bc_p'] : $r['ch_p']); $tSum['qty'] += (float)$r['qty_p']; $tSum['val'] += (float)$r['val_p']; $tSum['pkt_trip'] += (int)$r['pkt_trip']; $tSum['qty_trip'] += (float)$r['qty_trip']; $tSum['val_trip'] += (float)$r['val_trip']; }
  $hdr = $O['hdr']; $multi = (int)($hdr['MULTI'] ?? 0); $same = (int)($hdr['SAME'] ?? 0);
  $old = $O['old'];
  $ds = (int)($O['dupsite']['DUPS'] ?? 0); $di = (int)($O['dupitem']['DUPS'] ?? 0);
  if ($ds || $di || (float)($old['QTY'] ?? 0) > $oSum['qty'] + 0.01) {
    $f = (float)$oSum['val'] > 0 ? (float)($old['VAL'] ?? 0) / $oSum['val'] : 1;
    $find[] = ['bad', 'Join duplicates were inflating Qty/Value',
      ($ds ? "$ds site(s) appear more than once in $T (max " . (int)$O['dupsite']['MAXN'] . " rows). " : '') . ($di ? "$di item(s) appear more than once in MAS\$ITEM. " : '') .
      'With the old query, Value came to ₹' . $r2($old['VAL'] ?? 0) . ' instead of ₹' . $r2($oSum['val']) . ($f > 1.001 ? ' (' . number_format($f, 2) . '× too high)' : '') . '. This build removes the duplicates before joining. Click Sync Oracle once to reload correct numbers.'];
  } else $find[] = ['ok', 'No duplicate rows in store or item lookups', 'Store and item joins do not multiply any challan line.'];

  if ($multi > 0 && $same / $multi >= 0.9) {
    $find[] = [$cfg['qty_value_mode'] === 'challan_max' ? 'ok' : 'bad', 'CHALLAN_QTY / CHALLAN_AMOUNT look like challan totals repeated on every line',
      "$same of $multi multi-line challans carry the same qty and amount on every line. Adding them up gives Qty " . $n($oSum['qty']) . ' / ₹' . $r2($oSum['val']) . '; taking them once per challan gives Qty ' . $n($hdr['QTY_MAX'] ?? 0) . ' / ₹' . $r2($hdr['VAL_MAX'] ?? 0) . '. ' .
      ($cfg['qty_value_mode'] === 'challan_max' ? "qty_value_mode is already 'challan_max'." : "If the second pair matches your PKT Challan app, set 'qty_value_mode' => 'challan_max' in config/oracle.php and Sync Oracle.")];
  } elseif ($multi > 0) {
    $find[] = [$cfg['qty_value_mode'] === 'line_sum' ? 'ok' : 'bad', 'CHALLAN_QTY / CHALLAN_AMOUNT are per item line', "Lines carry different values, so adding them up is correct" . ($cfg['qty_value_mode'] === 'line_sum' ? '.' : ". Set 'qty_value_mode' => 'line_sum' in config/oracle.php.")];
  }

  if ($oSum['ch'] !== $oSum['bc']) {
    $find[] = ['info', 'Challan numbers and challan barcodes give different PKT', 'Pending in Oracle: ' . $n($oSum['ch']) . ' distinct CHALLAN_NO vs ' . $n($oSum['bc']) . ' distinct CHALLAN_BARCODE. PKT currently counts ' . ($basis === 'CHALLAN_BARCODE' ? 'barcodes' : 'challan numbers') . ". If your PKT Challan app counts the other one, change 'pkt_basis' in config/oracle.php (no re-sync needed)."];
  }

  $allCh = (int)($O['all']['CH'] ?? 0);
  if ($days > 0 && $allCh > $oSum['ch']) {
    $find[] = ['bad', 'Older pending challans are left out by the date window', $n($allCh - $oSum['ch']) . " pending challan(s) are older than $days days (oldest " . e(substr((string)($O['all']['OLDEST'] ?? ''), 0, 10)) . "). All pending: " . $n($allCh) . ' challans, Qty ' . $n($O['all']['QTY'] ?? 0) . ', ₹' . $r2($O['all']['VAL'] ?? 0) . ". Set 'days_back' => 0 in config/oracle.php to include them, then Sync Oracle."];
  }

  $oPkt = $basis === 'CHALLAN_BARCODE' ? $oSum['bc'] : $oSum['ch'];
  $dPkt = $oPkt - ($tSum['pkt'] + $tSum['pkt_trip']);
  $dVal = ($cfg['qty_value_mode'] === 'challan_max' ? (float)($hdr['VAL_MAX'] ?? 0) : $oSum['val']) - ($tSum['val'] + $tSum['val_trip']);
  if (abs($dPkt) > 0 || abs($dVal) > 1) {
    $find[] = ['bad', 'TMS data is out of date compared to Oracle right now', 'Oracle (same filters) has ' . $n($oPkt) . ' PKT; TMS has ' . $n($tSum['pkt']) . ' on the board + ' . $n($tSum['pkt_trip']) . ' already in trips. Value difference ₹' . $r2($dVal) . '. Last sync ' . e(substr((string)$tmsSync, 0, 16)) . '. Click Sync Oracle to refresh.'];
  } else $find[] = ['ok', 'TMS matches Oracle', 'Board PKT + PKT already in trips = Oracle PKT, and value matches.'];
  if ($tSum['pkt_trip'] > 0) $find[] = ['info', 'Challans in trips are not on the board', $n($tSum['pkt_trip']) . ' PKT (₹' . $r2($tSum['val_trip']) . ') are already in TMS trips but still Pending in Oracle. That is expected until the challan is dispatched in Oracle.'];
}

head('PRD Reconciliation');
?>
<style>
.rc{max-width:1200px}.rc h2{margin:0;font-size:19px}.rc .sub{color:#5b6b80;font-size:12px;margin:3px 0 12px}
.rc-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding-bottom:12px;border-bottom:2px solid #0f1b2d;margin-bottom:14px}
.rc-cfg{display:flex;gap:6px;flex-wrap:wrap;font-size:11px;margin-bottom:14px}.rc-cfg span{background:#f2f4f7;border-radius:6px;padding:4px 8px}.rc-cfg b{color:#0f1b2d}
.rc-f{border-left:4px solid #98a2b3;background:#fff;border-radius:0 8px 8px 0;padding:10px 14px;margin-bottom:8px;box-shadow:0 1px 0 #e4e7ec}
.rc-f.bad{border-color:#d92d20}.rc-f.ok{border-color:#079455}.rc-f.info{border-color:#1570ef}
.rc-f b{display:block;font-size:13px;color:#0f1b2d;margin-bottom:2px}.rc-f p{margin:0;font-size:12px;color:#344054;line-height:1.5;max-width:900px}
.rc table{width:100%;border-collapse:collapse;background:#fff;font-size:12px;margin-bottom:16px}
.rc th{background:#eef2f7;text-align:left;padding:8px;font-size:11px}.rc td{padding:7px 8px;border-top:1px solid #edf1f6}
.rc .num{text-align:right;font-variant-numeric:tabular-nums}.rc h3{font-size:14px;margin:18px 0 6px}
.rc-wrap{overflow-x:auto}
</style>
<div class="rc">
  <h2>PRD Pending · check totals against Oracle</h2>
  <div class="sub">Runs read-only checks on Oracle and compares them with what the TMS stored. Nothing is changed.</div>
  <div class="rc-bar">
    <a class="btn light" href="PLANNING.php?tab=prd_pending">Back to PRD Pending</a>
    <button class="btn primary" type="button" onclick="location.href='PRD_RECON.php?run=1'">Run check on Oracle</button>
    <form method="get" style="display:flex;gap:6px;margin-left:auto"><input class="input" name="challan" value="<?= e($chq) ?>" placeholder="Challan no. or barcode" style="width:200px"><button class="btn light">Trace one challan</button></form>
  </div>
  <div class="rc-cfg">
    <span>DC sites <b><?= e(implode(', ', $cfg['src_site_codes'])) ?></b></span>
    <span>Status <b><?= e(strtoupper((string)$cfg['challan_status'])) ?></b></span>
    <span>Window <b><?= $days ? "last $days days" : 'all dates' ?></b></span>
    <span>PKT = <b><?= e(prd_pkt_basis()) ?></b></span>
    <span>Qty/Value <b><?= e($cfg['qty_value_mode']) ?></b></span>
    <span>Cube <b><?= e((string)$cfg['cube_code']) ?></b></span>
  </div>
  <?php if ($err): ?><div class="rc-f bad"><b>Oracle check failed</b><p><?= e($err) ?></p></div><?php endif; ?>

  <?php if ($find): ?><h3>Findings</h3><?php foreach ($find as $f): ?><div class="rc-f <?= $f[0] ?>"><b><?= e($f[1]) ?></b><p><?= e($f[2]) ?></p></div><?php endforeach; endif; ?>

  <?php if ($run && !$err): ?>
  <h3>By DC</h3>
  <div class="rc-wrap"><table><tr><th>DC site</th><th class="num">Oracle lines</th><th class="num">Oracle PKT (distinct challans)</th><th class="num">Oracle barcodes</th><th class="num">Oracle qty</th><th class="num">Oracle value</th><th class="num">TMS board PKT</th><th class="num">TMS board qty</th><th class="num">TMS board value</th><th class="num">In trips PKT</th></tr>
  <?php foreach ($O['dc'] as $r): $t = $tms[$r['SRC']] ?? null; ?>
    <tr><td><b><?= e($r['SRC']) ?></b></td><td class="num"><?= $n($r['LINES']) ?></td><td class="num"><?= $n($r['CH']) ?></td><td class="num"><?= $n($r['BC']) ?></td><td class="num"><?= $r2($r['QTY']) ?></td><td class="num">₹<?= $r2($r['VAL']) ?></td>
      <td class="num"><?= $t ? $n(prd_pkt_basis() === 'CHALLAN_BARCODE' ? $t['bc_p'] : $t['ch_p']) : '—' ?></td><td class="num"><?= $t ? $r2($t['qty_p']) : '—' ?></td><td class="num"><?= $t ? '₹' . $r2($t['val_p']) : '—' ?></td><td class="num"><?= $t ? $n($t['pkt_trip']) : '—' ?></td></tr>
  <?php endforeach; ?></table></div>
  <h3>Challan status in Oracle (last <?= $days ?: 30 ?> days, same DCs)</h3>
  <div class="rc-wrap"><table><tr><th>CHALLAN_STATUS</th><th class="num">Challans</th><th class="num">Value</th><th>Shown on board?</th></tr>
  <?php foreach ($O['status'] as $r): ?><tr><td><?= e($r['ST']) ?></td><td class="num"><?= $n($r['CH']) ?></td><td class="num">₹<?= $r2($r['VAL']) ?></td><td><?= $r['ST'] === strtoupper((string)$cfg['challan_status']) ? 'Yes' : 'No' ?></td></tr><?php endforeach; ?></table></div>
  <?php endif; ?>

  <?php if ($chq !== '' && !$err): ?>
  <h3>Trace: <?= e($chq) ?></h3>
  <?php $lines = $O['ch'] ?? []; $sq = array_sum(array_map(fn($r) => (float)$r['CHALLAN_QTY'], $lines)); $sv = array_sum(array_map(fn($r) => (float)$r['CHALLAN_AMOUNT'], $lines)); ?>
  <div class="rc-f info"><b>Oracle: <?= count($lines) ?> line(s)<?= count($lines) >= 500 ? ' (first 500)' : '' ?></b><p>Sum of CHALLAN_QTY <?= $r2($sq) ?>, sum of CHALLAN_AMOUNT ₹<?= $r2($sv) ?>. Distinct CHALLAN_QTY values: <?= count(array_unique(array_column($lines, 'CHALLAN_QTY'))) ?>, distinct CHALLAN_AMOUNT values: <?= count(array_unique(array_column($lines, 'CHALLAN_AMOUNT'))) ?>. If both are 1 on a multi-line challan, qty/amount are challan totals repeated per line.</p></div>
  <div class="rc-wrap"><table><tr><th>Src</th><th>Dest</th><th>Challan</th><th>Challan barcode</th><th>Date</th><th>Item barcode</th><th class="num">CHALLAN_QTY</th><th class="num">CHALLAN_AMOUNT</th><th>Status</th></tr>
  <?php foreach (array_slice($lines, 0, 100) as $r): ?><tr><td><?= e($r['SRC']) ?></td><td><?= e($r['DES']) ?></td><td><?= e($r['CHALLAN_NO']) ?></td><td><?= e($r['CHALLAN_BARCODE']) ?></td><td><?= e(substr((string)$r['CHALLAN_DATE'], 0, 10)) ?></td><td><?= e($r['BARCODE']) ?></td><td class="num"><?= $r2($r['CHALLAN_QTY']) ?></td><td class="num"><?= $r2($r['CHALLAN_AMOUNT']) ?></td><td><?= e($r['CHALLAN_STATUS']) ?></td></tr><?php endforeach; ?>
  <?php if (count($lines) > 100): ?><tr><td colspan="9" class="note">… <?= count($lines) - 100 ?> more line(s) not shown</td></tr><?php endif; ?></table></div>
  <h3>TMS rows for this challan</h3>
  <div class="rc-wrap"><table><tr><th>DC</th><th>Destination</th><th>Challan</th><th>Barcode</th><th>Division</th><th class="num">Qty</th><th class="num">Value</th><th>Status</th><th>Trip</th><th>Synced</th></tr>
  <?php if (!$O['ch_tms']): ?><tr><td colspan="10" class="note">Not in TMS. It is either not <?= e(strtoupper((string)$cfg['challan_status'])) ?>, outside the date window, from another DC/cube, or not synced yet.</td></tr><?php endif; ?>
  <?php foreach ($O['ch_tms'] as $r): ?><tr><td><?= e($r['source_site_code']) ?></td><td><?= e($r['destination_name']) ?></td><td><?= e($r['challan_no']) ?></td><td><?= e($r['challan_barcode']) ?></td><td><?= e($r['sub_div']) ?></td><td class="num"><?= $r2($r['qty']) ?></td><td class="num">₹<?= $r2($r['value']) ?></td><td><?= e($r['challan_status']) ?></td><td><?= $r['planned_trip_id'] ? 'Trip #' . (int)$r['planned_trip_id'] : '—' ?></td><td><?= e(substr((string)$r['synced_at'], 0, 16)) ?></td></tr><?php endforeach; ?></table></div>
  <?php endif; ?>

  <?php if (!$run && $chq === '' && !$err): ?>
  <div class="rc-f info"><b>Click “Run check on Oracle”</b><p>It compares Oracle pending totals per DC with the TMS board, checks for duplicate rows in <?= e($T) ?> and MAS$ITEM, tests whether qty/amount are repeated per line, and shows pending challans left out by the date window. To check one challan against your PKT Challan app, use “Trace one challan”.</p></div>
  <?php endif; ?>
</div>
<?php foot(); ?>
