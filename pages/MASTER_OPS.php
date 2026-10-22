<?php
/* Masters: Transporters, Loading docks, Vehicle master CSV upload. */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
require_once TMS_ROOT . '/app/services/dispatch_service.php';
$d = db();
$tab = in_array($_GET['tab'] ?? '', ['transporters', 'docks', 'vehicles', 'warehouses'], true) ? $_GET['tab'] : 'transporters';
require_once TMS_ROOT . '/app/services/oracle_sync.php';
$extraSites = function () use ($d): array { try { return array_values(array_filter(array_map('trim', explode(',', (string)($d->query("SELECT v FROM tms_meta WHERE k='extra_src_site_codes'")->fetchColumn() ?: ''))))); } catch (Throwable $e) { return []; } };
$saveExtra = function (array $list) use ($d): void { $d->prepare("INSERT INTO tms_meta(k,v,updated_at) VALUES('extra_src_site_codes',?,CURRENT_TIMESTAMP) ON CONFLICT(k) DO UPDATE SET v=EXCLUDED.v,updated_at=CURRENT_TIMESTAMP")->execute([implode(',', array_values(array_unique($list)))]); };
$oraSites = null;
$err = ''; $report = null;

if (($_GET['template'] ?? '') === 'vehicles') {
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="VEHICLE_MASTER_TEMPLATE.csv"');
  echo "\xEF\xBB\xBFvehicle_number,vehicle_type,capacity_pkt,value_capacity,transporter,status\nHR55AB1234,32FT,300,2500000,ABC Roadlines,Available\nHR38XY9876,19FT,200,1800000,ABC Roadlines,Available\n"; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    verify_csrf(); $a = (string)($_POST['action'] ?? '');
    if ($a === 'tpt_save') {
      $name = trim((string)$_POST['name']); if ($name === '') throw new Exception('Transporter name likhein.');
      $id = (int)($_POST['id'] ?? 0);
      $vals = [$name, trim((string)($_POST['contact_person'] ?? '')) ?: null, trim((string)($_POST['phone'] ?? '')) ?: null, trim((string)($_POST['email'] ?? '')) ?: null, trim((string)($_POST['gst_no'] ?? '')) ?: null];
      $dup = $d->prepare("SELECT id FROM tms_transporters WHERE lower(name)=lower(?) AND id<>?"); $dup->execute([$name, $id]); if ($dup->fetchColumn()) throw new Exception("Transporter “{$name}” already exists.");
      if ($id) $d->prepare("UPDATE tms_transporters SET name=?,contact_person=?,phone=?,email=?,gst_no=? WHERE id=?")->execute([...$vals, $id]);
      else { $iq = $d->prepare("INSERT INTO tms_transporters(name,contact_person,phone,email,gst_no,active) VALUES(?,?,?,?,?,TRUE) RETURNING id"); $iq->execute($vals); $id = (int)$iq->fetchColumn(); }
      dsp_tpt_sync_legacy($d, $id);
      setflash('Transporter saved.'); redirect('MASTER_OPS.php?tab=transporters');
    }
    if ($a === 'tpt_toggle') { $d->prepare("UPDATE tms_transporters SET active=NOT active WHERE id=?")->execute([(int)$_POST['id']]); setflash('Transporter updated.'); redirect('MASTER_OPS.php?tab=transporters'); }
    if ($a === 'dock_save') {
      $dc = (int)$_POST['dc_id']; $code = strtoupper(trim((string)$_POST['dock_code'])); if (!$dc || $code === '') throw new Exception('DC aur dock code chahiye.');
      $dup = $d->prepare("SELECT id FROM tms_docks WHERE dc_id=? AND lower(dock_code)=lower(?)"); $dup->execute([$dc, $code]); if ($dup->fetchColumn()) throw new Exception("Dock $code already exists for this DC.");
      $d->prepare("INSERT INTO tms_docks(dc_id,dock_code,dock_name) VALUES(?,?,NULLIF(?,''))")->execute([$dc, $code, trim((string)($_POST['dock_name'] ?? ''))]);
      setflash("Dock $code added."); redirect('MASTER_OPS.php?tab=docks');
    }
    if ($a === 'dock_bulk') {
      $dc = (int)$_POST['dc_id']; $n = max(1, min(60, (int)$_POST['count'])); $pre = strtoupper(trim((string)($_POST['prefix'] ?? 'D'))) ?: 'D'; $made = 0;
      $ins = $d->prepare("INSERT INTO tms_docks(dc_id,dock_code) VALUES(?,?) ON CONFLICT DO NOTHING");
      for ($i = 1; $i <= $n; $i++) { $ins->execute([$dc, $pre . str_pad((string)$i, 2, '0', STR_PAD_LEFT)]); $made += $ins->rowCount(); }
      setflash("$made dock(s) added."); redirect('MASTER_OPS.php?tab=docks');
    }
    if ($a === 'dock_toggle') { $d->prepare("UPDATE tms_docks SET active=NOT active WHERE id=?")->execute([(int)$_POST['id']]); setflash('Dock updated.'); redirect('MASTER_OPS.php?tab=docks'); }
    if ($a === 'site_add') {
      $sc = trim((string)$_POST['site_code']);
      if (!preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $sc)) throw new Exception('Oracle site code sirf letters/numbers (jaise 420).');
      $saveExtra(array_merge($extraSites(), [$sc]));
      setflash("Site $sc added. Next “Refresh from Oracle” on PRD Pending will load its pending challans."); redirect('MASTER_OPS.php?tab=warehouses');
    }
    if ($a === 'site_remove') { $sc = trim((string)$_POST['site_code']); $saveExtra(array_diff($extraSites(), [$sc])); setflash("Site $sc removed from sync."); redirect('MASTER_OPS.php?tab=warehouses'); }
    if ($a === 'site_map') {
      $sc = trim((string)$_POST['site_code']); $dcId = (int)$_POST['dc_id'];
      $d->prepare("UPDATE tms_dc_master SET oracle_site_code=NULL WHERE oracle_site_code=?")->execute([$sc]);
      if ($dcId) $d->prepare("UPDATE tms_dc_master SET oracle_site_code=? WHERE id=?")->execute([$sc, $dcId]);
      setflash($dcId ? "Oracle site $sc linked to the Center DC." : "Link removed for site $sc."); redirect('MASTER_OPS.php?tab=warehouses');
    }
    if ($a === 'site_find') {
      $tab = 'warehouses'; $cfg = tms_oracle_cfg(); $conn = tms_oracle_connect($cfg);
      $b = [':cube' => (string)($cfg['cube_code'] ?? '15'), ':status' => strtoupper(trim((string)$cfg['challan_status'])) ?: 'PENDING']; $days = max(0, (int)$cfg['days_back']); if ($days) $b[':days'] = $days;
      $T = tms_store_table($cfg);
      $st = tms_oracle_query($conn, "/*R:sites*/ SELECT TO_CHAR(A.ADMSITE_CODE_SRC) SITE, MAX(S.INITIAL_NAME) NAME, COUNT(DISTINCT A.CHALLAN_NO) CH, MIN(TRUNC(A.CHALLAN_DATE)) OLDEST
        FROM MISRETAIL.CUBE\$SALDC A LEFT JOIN (SELECT SITE_CODE, MAX(INITIAL_NAME) INITIAL_NAME FROM $T GROUP BY SITE_CODE) S ON S.SITE_CODE=A.ADMSITE_CODE_SRC
        WHERE A.CUBE_CODE=:cube AND UPPER(TRIM(A.CHALLAN_STATUS))=:status" . ($days ? " AND A.CHALLAN_DATE >= TRUNC(SYSDATE)-:days" : '') . " GROUP BY A.ADMSITE_CODE_SRC ORDER BY 3 DESC", $b);
      $oraSites = []; while (($r = oci_fetch_assoc($st)) !== false) $oraSites[] = $r; oci_free_statement($st); @oci_close($conn);
    }
    if ($a === 'vehicle_upload') {
      $tab = 'vehicles';
      if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) throw new Exception('CSV file chunein.');
      $fh = fopen($_FILES['csv']['tmp_name'], 'r'); $hdr = fgetcsv($fh); if (!$hdr) throw new Exception('CSV khali hai.');
      $hdr = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h))), $hdr);
      $col = fn($n) => array_search($n, $hdr, true);
      if ($col('vehicle_number') === false) throw new Exception('Header me vehicle_number column chahiye (template download karein).');
      $capCol = $col('capacity_pkt') !== false ? $col('capacity_pkt') : $col('capacity_boxes');
      $report = ['inserted' => 0, 'updated' => 0, 'errors' => [], 'tpt_new' => 0];
      $d->beginTransaction(); $line = 1;
      $findV = $d->prepare("SELECT id FROM vehicles WHERE upper(regexp_replace(vehicle_number,'[^A-Za-z0-9]','','g'))=?");
      $findT = $d->prepare("SELECT id FROM tms_transporters WHERE lower(name)=lower(?)");
      $newT = $d->prepare("INSERT INTO tms_transporters(name,active) VALUES(?,TRUE) RETURNING id");
      while (($r = fgetcsv($fh)) !== false) {
        $line++; if (!array_filter($r, fn($x) => trim((string)$x) !== '')) continue;
        $get = fn($i) => $i === false ? '' : trim((string)($r[$i] ?? ''));
        $no = dsp_norm_vehicle_no($get($col('vehicle_number')));
        if (strlen($no) < 6) { $report['errors'][] = "Line $line: vehicle number “" . $get($col('vehicle_number')) . "” is not valid."; continue; }
        $cap = $get($capCol); $vcap = $get($col('value_capacity')); $type = $get($col('vehicle_type')); $tn = $get($col('transporter')); $st = $get($col('status')) ?: 'Available';
        if ($cap !== '' && (!ctype_digit($cap) || (int)$cap < 1)) { $report['errors'][] = "Line $line ($no): capacity must be a whole number."; continue; }
        if ($vcap !== '' && !is_numeric($vcap)) { $report['errors'][] = "Line $line ($no): value_capacity must be a number."; continue; }
        if (!in_array($st, ['Available', 'Maintenance', 'Inactive'], true)) { $report['errors'][] = "Line $line ($no): status must be Available, Maintenance or Inactive."; continue; }
        $tid = null;
        if ($tn !== '') { $findT->execute([$tn]); $tid = $findT->fetchColumn() ?: null; if (!$tid) { $newT->execute([$tn]); $tid = (int)$newT->fetchColumn(); dsp_tpt_sync_legacy($d, $tid); $report['tpt_new']++; } }
        $findV->execute([$no]); $vid = $findV->fetchColumn();
        if ($vid) {
          $d->prepare("UPDATE vehicles SET vehicle_type=COALESCE(NULLIF(?,''),vehicle_type), capacity_boxes=COALESCE(?,capacity_boxes), value_capacity=COALESCE(?,value_capacity), transporter_id=COALESCE(?,transporter_id),
            status=CASE WHEN status IN ('Assigned','In Transit') THEN status ELSE ? END, active=(? <> 'Inactive') WHERE id=?")
            ->execute([$type, $cap !== '' ? (int)$cap : null, $vcap !== '' ? (float)$vcap : null, $tid, $st, $st, (int)$vid]);
          $report['updated']++;
        } else {
          $d->prepare("INSERT INTO vehicles(vehicle_number,vehicle_type,capacity_boxes,value_capacity,transporter_id,status,active) VALUES(?,NULLIF(?,''),?,?,?,?,?)")
            ->execute([$no, $type, $cap !== '' ? (int)$cap : 300, $vcap !== '' ? (float)$vcap : 2500000, $tid, $st, $st !== 'Inactive' ? 'true' : 'false']);
          $report['inserted']++;
        }
      }
      $d->commit();
    }
  } catch (Throwable $x) { if ($d->inTransaction()) $d->rollBack(); $err = $x->getMessage(); }
}

$dcs = $d->query("SELECT id,dc_code,dc_name FROM tms_dc_master ORDER BY dc_code")->fetchAll(PDO::FETCH_ASSOC);
$tok = csrf();
head('Transporters & docks');
?>
<style>.mo-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:14px}.mo-grid .card{margin:0}.mo-form{display:flex;flex-direction:column;gap:10px}.mo-form .input,.mo-form .select{width:100%}@media(max-width:1000px){.mo-grid{grid-template-columns:1fr}}</style>
<div class="tabs">
  <a class="tab <?= $tab === 'transporters' ? 'active' : '' ?>" href="?tab=transporters">Transporters</a>
  <a class="tab <?= $tab === 'docks' ? 'active' : '' ?>" href="?tab=docks">Loading docks</a>
  <a class="tab <?= $tab === 'vehicles' ? 'active' : '' ?>" href="?tab=vehicles">Vehicle master upload</a>
  <a class="tab <?= $tab === 'warehouses' ? 'active' : '' ?>" href="?tab=warehouses">Warehouses (Oracle sites)</a>
</div>
<?php flash(); if ($err): ?><div class="alert alert-err" role="alert"><?= e($err) ?></div><?php endif; ?>
<?php try { echo tms_stats([
  ['transporters', (int)$d->query("SELECT COUNT(*) FROM tms_transporters WHERE active")->fetchColumn(), $tab === 'transporters' ? 'dark' : '', '?tab=transporters'],
  ['docks', (int)$d->query("SELECT COUNT(*) FROM tms_docks WHERE active")->fetchColumn(), $tab === 'docks' ? 'dark' : '', '?tab=docks'],
  ['vehicles', (int)$d->query("SELECT COUNT(*) FROM vehicles WHERE COALESCE(active,TRUE)")->fetchColumn(), $tab === 'vehicles' ? 'dark' : '', '?tab=vehicles'],
  ['Oracle sites synced', count(tms_oracle_cfg()['src_site_codes']), $tab === 'warehouses' ? 'dark' : '', '?tab=warehouses'],
]); } catch (Throwable $e) {} ?>

<?php if ($tab === 'transporters'): $rows = $d->query("SELECT t.*, (SELECT COUNT(*) FROM vehicles v WHERE v.transporter_id=t.id AND v.active) vehicles FROM tms_transporters t ORDER BY t.active DESC, lower(t.name)")->fetchAll(PDO::FETCH_ASSOC); $edit = null; foreach ($rows as $r) if ((int)$r['id'] === (int)($_GET['edit'] ?? 0)) $edit = $r; ?>
<div class="mo-grid">
  <div class="tablewrap"><table class="table"><tr><th>Transporter</th><th>Contact</th><th>Phone</th><th class="right">Vehicles</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $r): ?><tr><td><b><?= e($r['name']) ?></b><?= $r['gst_no'] ? '<div class="note">GST ' . e($r['gst_no']) . '</div>' : '' ?></td><td><?= e($r['contact_person'] ?: '—') ?></td><td><?= e($r['phone'] ?: '—') ?></td><td class="right"><?= (int)$r['vehicles'] ?></td>
    <td><span class="badge <?= $r['active'] ? 'b-green' : 'b-red' ?>"><?= $r['active'] ? 'Active' : 'Inactive' ?></span></td>
    <td class="right"><a class="btn light" href="?tab=transporters&amp;edit=<?= (int)$r['id'] ?>">Edit</a> <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="tpt_toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn light"><?= $r['active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="note">No transporters yet. Add the first one.</td></tr><?php endif; ?></table></div>
  <form method="post" class="card mo-form"><div class="section-title"><?= $edit ? 'Edit transporter' : 'Add transporter' ?></div>
    <input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="tpt_save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="fg"><label>Name</label><input class="input" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
    <div class="fg"><label>Contact person</label><input class="input" name="contact_person" value="<?= e($edit['contact_person'] ?? '') ?>"></div>
    <div class="fg"><label>Phone</label><input class="input" name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
    <div class="fg"><label>Email</label><input class="input" type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
    <div class="fg"><label>GST no.</label><input class="input" name="gst_no" value="<?= e($edit['gst_no'] ?? '') ?>"></div>
    <div class="toolbar" style="margin:0"><button class="btn primary"><?= $edit ? 'Save changes' : 'Add transporter' ?></button><?php if ($edit): ?><a class="btn light" href="?tab=transporters">Cancel</a><?php endif; ?></div>
  </form>
</div>

<?php elseif ($tab === 'docks'): $rows = dsp_docks($d, null, false); ?>
<div class="mo-grid">
  <div class="tablewrap"><table class="table"><tr><th>Warehouse</th><th>Dock</th><th>Name / area</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $r): ?><tr><td><?= e($r['dc_code'] . ' · ' . $r['dc_name']) ?></td><td><b><?= e($r['dock_code']) ?></b></td><td><?= e($r['dock_name'] ?: '—') ?></td><td><span class="badge <?= $r['active'] ? 'b-green' : 'b-red' ?>"><?= $r['active'] ? 'Active' : 'Inactive' ?></span></td>
    <td class="right"><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="dock_toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn light"><?= $r['active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="note">No docks yet. Add docks for each warehouse.</td></tr><?php endif; ?></table></div>
  <div>
    <form method="post" class="card mo-form"><div class="section-title">Add a dock</div><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="dock_save">
      <div class="fg"><label>Warehouse</label><select class="select" name="dc_id" required><?php foreach ($dcs as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['dc_code'] . ' · ' . $x['dc_name']) ?></option><?php endforeach; ?></select></div>
      <div class="fg"><label>Dock code</label><input class="input" name="dock_code" required placeholder="D01"></div>
      <div class="fg"><label>Name / area (optional)</label><input class="input" name="dock_name" placeholder="North bay"></div>
      <button class="btn primary">Add dock</button></form>
    <form method="post" class="card mo-form" style="margin-top:14px"><div class="section-title">Add several docks</div><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="dock_bulk">
      <div class="fg"><label>Warehouse</label><select class="select" name="dc_id" required><?php foreach ($dcs as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['dc_code'] . ' · ' . $x['dc_name']) ?></option><?php endforeach; ?></select></div>
      <div class="formgrid"><div class="fg"><label>Prefix</label><input class="input" name="prefix" value="D"></div><div class="fg"><label>How many</label><input class="input" type="number" name="count" min="1" max="60" value="10"></div></div>
      <button class="btn light">Create D01 … Dnn</button></form>
  </div>
</div>

<?php elseif ($tab === 'warehouses'):
  $cfgW = tms_oracle_cfg(); $fileSites = array_diff($cfgW['src_site_codes'], $extraSites()); $added = $extraSites();
  $seen = []; foreach ($d->query("SELECT source_site_code sc, MAX(source_name) nm, COUNT(DISTINCT challan_no) ch, MAX(synced_at) at FROM prd_pending WHERE source_system='ORACLE' GROUP BY 1")->fetchAll(PDO::FETCH_ASSOC) as $r) $seen[(string)$r['sc']] = $r;
  $all = array_values(array_unique(array_merge($cfgW['src_site_codes'], array_keys($seen))));
  $dcAll = $d->query("SELECT id,dc_code,dc_name,oracle_site_code FROM tms_dc_master ORDER BY dc_code")->fetchAll(PDO::FETCH_ASSOC);
  $mapOf = function ($sc) use ($dcAll) { foreach ($dcAll as $x) if ((string)$x['oracle_site_code'] === (string)$sc) return [(int)$x['id'], 'linked']; foreach ($dcAll as $x) if (strcasecmp((string)$x['dc_code'], (string)$sc) === 0) return [(int)$x['id'], 'code']; return [0, '']; };
?>
<div class="mo-grid">
  <div class="tablewrap"><table class="table"><tr><th>Oracle site</th><th>Name in Oracle</th><th>Loaded in sync?</th><th class="right">Challans in TMS</th><th>Center DC</th><th></th></tr>
  <?php foreach ($all as $sc): $in = in_array($sc, $cfgW['src_site_codes'], true); [$mid, $how] = $mapOf($sc); $r = $seen[$sc] ?? null; ?>
    <tr><td><b><?= e($sc) ?></b></td><td><?= e($r['nm'] ?? '—') ?></td>
      <td><?= $in ? '<span class="badge b-green">Yes · ' . (in_array($sc, $added, true) ? 'added here' : 'config file') . '</span>' : '<span class="badge b-red">No, not synced</span>' ?></td>
      <td class="right"><?= number_format((int)($r['ch'] ?? 0)) ?></td>
      <td><form method="post" class="toolbar" style="margin:0"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="site_map"><input type="hidden" name="site_code" value="<?= e($sc) ?>">
        <select class="select" name="dc_id"><option value="0">Not linked</option><?php foreach ($dcAll as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $mid === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['dc_code'] . ' · ' . $x['dc_name']) ?></option><?php endforeach; ?></select><button class="btn light">Save</button></form>
        <?= !$mid ? '<div class="note" style="color:var(--red)">Not linked: board shows “Source not in DC master”</div>' : ($how === 'code' ? '<div class="note">Matched by DC code</div>' : '') ?></td>
      <td class="right"><?php if (!$in): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="site_add"><input type="hidden" name="site_code" value="<?= e($sc) ?>"><button class="btn primary">Add to sync</button></form>
        <?php elseif (in_array($sc, $added, true)): ?><form method="post" style="display:inline" onsubmit="return confirm('Stop syncing site <?= e($sc) ?>?')"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="site_remove"><input type="hidden" name="site_code" value="<?= e($sc) ?>"><button class="btn light">Remove</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></table></div>
  <div>
    <form method="post" class="card mo-form"><div class="section-title">Add an Oracle site to sync</div><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="site_add">
      <div class="fg"><label>Oracle source site code (ADMSITE_CODE_SRC)</label><input class="input" name="site_code" required placeholder="420"></div><button class="btn primary">Add site</button>
      <div class="note">Then click “Refresh from Oracle” on PRD Pending.</div></form>
    <form method="post" class="card mo-form" style="margin-top:14px"><div class="section-title">Find sites in Oracle</div><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="site_find">
      <div class="note">Lists every source site that has challans with status <?= e(strtoupper((string)$cfgW['challan_status'])) ?> in cube <?= e((string)$cfgW['cube_code']) ?><?= (int)$cfgW['days_back'] ? ' (last ' . (int)$cfgW['days_back'] . ' days)' : '' ?>. Read-only; may take a minute.</div><button class="btn light">Search Oracle</button></form>
    <?php if (is_array($oraSites)): ?><div class="card" style="margin-top:14px"><div class="section-title">Sites with pending challans in Oracle</div>
      <table class="table" style="min-width:0;margin-top:8px"><tr><th>Site</th><th>Name</th><th class="right">Challans</th><th>Oldest</th><th></th></tr>
      <?php foreach ($oraSites as $o): $in = in_array((string)$o['SITE'], $cfgW['src_site_codes'], true); ?><tr><td><b><?= e($o['SITE']) ?></b></td><td><?= e($o['NAME'] ?? '') ?></td><td class="right"><?= number_format((int)$o['CH']) ?></td><td><?= e(substr((string)$o['OLDEST'], 0, 10)) ?></td>
        <td><?php if ($in): ?><span class="badge b-green">Synced</span><?php else: ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="site_add"><input type="hidden" name="site_code" value="<?= e($o['SITE']) ?>"><button class="btn primary">Add</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$oraSites): ?><tr><td colspan="5" class="note">No site has pending challans with these filters. Check cube code / status / days in config/oracle.php.</td></tr><?php endif; ?></table></div><?php endif; ?>
  </div>
</div>

<?php else: ?>
<div class="mo-grid">
  <form method="post" enctype="multipart/form-data" class="card mo-form"><div class="section-title">Upload vehicle master (CSV)</div>
    <div class="note">Columns: <b>vehicle_number</b> (required), vehicle_type, capacity_pkt, value_capacity, transporter, status (Available / Maintenance / Inactive). Existing vehicles are updated by number; new transporters are created automatically.</div>
    <input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="vehicle_upload">
    <input class="input" type="file" name="csv" accept=".csv,text/csv" required style="height:auto">
    <div class="toolbar" style="margin:0"><button class="btn primary">Upload</button><a class="btn light" href="?template=vehicles" data-no-busy>Download template</a><a class="btn light" href="MASTER.php?tab=vehicles">Open vehicle master</a></div>
  </form>
  <div class="card"><div class="section-title">Result</div>
  <?php if ($report): ?>
    <p><b><?= $report['inserted'] ?></b> added · <b><?= $report['updated'] ?></b> updated<?= $report['tpt_new'] ? ' · <b>' . $report['tpt_new'] . '</b> new transporter(s)' : '' ?> · <b style="color:<?= $report['errors'] ? 'var(--red)' : 'inherit' ?>"><?= count($report['errors']) ?></b> skipped</p>
    <?php if ($report['errors']): ?><ul style="margin:0;padding-left:18px;font-size:12px;color:var(--red)"><?php foreach (array_slice($report['errors'], 0, 50) as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php else: ?><p class="note">Upload a file to see what was added, updated or skipped.</p><?php endif; ?></div>
</div>
<?php endif; ?>
<?php foot(); ?>
