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
    verify_csrf(); require_perm('master'); $a = (string)($_POST['action'] ?? '');
    if ($a === 'tpt_save') {
      $name = trim((string)$_POST['name']); if ($name === '') throw new Exception('Transporter name likhein.');
      $id = (int)($_POST['id'] ?? 0);
      $phone = trim((string)($_POST['phone'] ?? '')) ?: null;
      $dup = $d->prepare("SELECT id FROM tms_transporters WHERE lower(name)=lower(?) AND id<>?"); $dup->execute([$name, $id]); if ($dup->fetchColumn()) throw new Exception("Transporter “{$name}” already exists.");
      $owner = trim((string)($_POST['owner_name'] ?? '')) ?: null;
      $email = trim((string)($_POST['email'] ?? '')) ?: null; if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email id sahi nahi hai.');
      $addr = trim((string)($_POST['address'] ?? '')) ?: null;
      $aadhaar = preg_replace('/\D/', '', (string)($_POST['aadhaar_no'] ?? '')) ?: null; if ($aadhaar !== null && strlen($aadhaar) !== 12) throw new Exception('Aadhaar number 12 digit ka hona chahiye.');
      $pan = strtoupper(trim((string)($_POST['pan_no'] ?? ''))) ?: null; if ($pan !== null && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) throw new Exception('PAN format galat hai (jaise ABCDE1234F).');
      $gst = strtoupper(trim((string)($_POST['gst_no'] ?? ''))) ?: null; if ($gst !== null && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gst)) throw new Exception('GST number galat hai (15 character, jaise 06ABCDE1234F1Z5).');
      if ($id) $d->prepare("UPDATE tms_transporters SET name=?,phone=?,owner_name=?,email=?,gst_no=?,address=?,aadhaar_no=?,pan_no=? WHERE id=?")->execute([$name, $phone, $owner, $email, $gst, $addr, $aadhaar, $pan, $id]);
      else { $iq = $d->prepare("INSERT INTO tms_transporters(name,phone,owner_name,email,gst_no,address,aadhaar_no,pan_no,active) VALUES(?,?,?,?,?,?,?,?,TRUE) RETURNING id"); $iq->execute([$name, $phone, $owner, $email, $gst, $addr, $aadhaar, $pan]); $id = (int)$iq->fetchColumn(); }
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
<?php if (!can('master')): // view-only role: the add / edit / upload forms are hidden (the server refuses them too) ?><style>main form[method="post"]{display:none!important}</style><?php endif; ?>
<style>.mo-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:14px}.mo-grid .card{margin:0}.mo-form{display:flex;flex-direction:column;gap:10px}.mo-form .input,.mo-form .select{width:100%}@media(max-width:1000px){.mo-grid{grid-template-columns:1fr}}</style>
<?php // Only Transporters (TPT) is a menu entry now. The docks / vehicle-upload / warehouse screens still exist but are not offered as tabs. ?>
<?php if ($tab !== 'transporters'): ?><div class="tabs"><a class="tab" href="<?= $tab === 'vehicles' ? 'MASTER.php?tab=vehicles' : '?tab=transporters' ?>">← Back</a></div><?php endif; ?>
<?php flash(); if ($err): ?><div class="alert alert-err" role="alert"><?= e($err) ?></div><?php endif; ?>
<?php if ($tab !== 'transporters') try { echo tms_stats([
  ['transporters', (int)$d->query("SELECT COUNT(*) FROM tms_transporters WHERE active")->fetchColumn(), 'dark'],
]); } catch (Throwable $e) {} ?>

<?php if ($tab === 'transporters'): $rows = $d->query("SELECT t.*, (SELECT COUNT(*) FROM vehicles v WHERE v.transporter_id=t.id AND v.active) vehicles FROM tms_transporters t ORDER BY t.active DESC, lower(t.name)")->fetchAll(PDO::FETCH_ASSOC);
  $canEdit = can('master'); $tptStatuses = [['v' => 'Active', 'l' => 'Active'], ['v' => 'Inactive', 'l' => 'Inactive']];
  $reopen = ($err !== '' && ($_POST['action'] ?? '') === 'tpt_save') ? array_intersect_key($_POST, array_flip(['id', 'name', 'owner_name', 'phone', 'email', 'gst_no', 'pan_no', 'aadhaar_no', 'address'])) : null; ?>
<link rel="stylesheet" href="<?= e(asset('assets/css/dt.css')) ?>">
<script src="<?= e(asset('assets/js/dt.js')) ?>" defer></script>
<style>
.mdlg{border:0;border-radius:12px;padding:0;width:min(560px,calc(100vw - 32px));box-shadow:0 24px 70px rgba(15,23,42,.32)}
.mdlg::backdrop{background:rgba(15,23,42,.5)}
.mdlg form{margin:0}.mdlg h3{margin:0;padding:14px 18px;font-size:15px;border-bottom:1px solid var(--line);background:#f8fafc;border-radius:12px 12px 0 0}
.mdlg .mb{padding:16px 18px;display:grid;grid-template-columns:1fr 1fr;gap:12px}
.mdlg .mb .f{display:flex;flex-direction:column;gap:4px}.mdlg .mb .f.w{grid-column:1/-1}
.mdlg label{font-size:12px;font-weight:600;color:#475467}.mdlg label i{color:#d92d20;font-style:normal}
.mdlg input:not([type=checkbox]),.mdlg textarea{height:36px;border:1px solid var(--line);border-radius:8px;padding:0 10px;font-size:13px;font-family:inherit;width:100%;box-sizing:border-box}
.mdlg textarea{height:72px;padding:8px 10px;resize:vertical}
.mdlg .mf{display:flex;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid var(--line);background:#fafafa;border-radius:0 0 12px 12px}
.m-act{display:flex;gap:6px}.m-act button{height:24px;border:1px solid var(--line);background:#fff;border-radius:7px;padding:0 8px;font-size:11px;cursor:pointer;color:#344054}
.top{margin-bottom:4px!important;padding-top:8px!important;padding-bottom:8px!important}
.dt-bar{gap:6px!important;margin:0 0 8px!important}.dt-bar .dt-btn,.dt-bar select,.dt-search input{height:32px!important;font-size:12px!important}.dt-bar .dt-btn{padding:0 10px!important}.dt-search{max-width:240px!important;flex:0 1 220px!important}
#dtTpt .dt-wrap{max-height:calc(100vh - 150px)}#dtTpt .dt-table{table-layout:fixed;font-size:12px}
#dtTpt .dt-table th{padding:7px 14px 7px 8px;font-size:11px;background:#eef3fb;color:#1d2f52}
#dtTpt .dt-table td{padding:4px 8px;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:560px){.mdlg .mb{grid-template-columns:1fr}}
</style>
<div class="dt" id="dtTpt" data-resize="1" data-key="tpt2" data-default-status="Active" data-page-size="50" data-export="transporters" data-statuses="<?= e(json_encode($tptStatuses)) ?>">
  <div data-dt-actions><?php if ($canEdit): ?><button type="button" class="dt-btn pri" onclick="tptOpen()">＋ Add transporter</button><?php endif; ?></div>
  <div class="dt-wrap"><table class="dt-table"><thead><tr><th data-sort="text">Transporter</th><th data-sort="text">Owner</th><th data-sort="text">Mobile</th><th data-sort="text">Email</th><th data-sort="text">GST</th><th data-sort="text">PAN</th><th data-sort="text">Aadhaar</th><th data-sort="text">Address</th><th data-sort="num" class="n">Vehicles</th><th data-sort="text">Status</th><?php if ($canEdit): ?><th data-nohide data-noexport></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($rows as $r): $act = !empty($r['active']) && $r['active'] !== 'f';
    $c = fn($v, $b = false) => '<td data-v="' . e((string)$v) . '" title="' . e((string)$v) . '">' . ($b ? '<b>' . e((string)$v) . '</b>' : e((string)$v)) . '</td>'; ?>
    <tr data-row data-status="<?= $act ? 'Active' : 'Inactive' ?>" data-q="<?= e(strtolower($r['name'] . ' ' . $r['owner_name'] . ' ' . $r['phone'] . ' ' . $r['email'] . ' ' . $r['pan_no'])) ?>">
      <?= $c($r['name'], true), $c($r['owner_name']), $c($r['phone']), $c($r['email']), $c($r['gst_no']), $c($r['pan_no']), $c($r['aadhaar_no']), $c($r['address']) ?>
      <td class="n" data-v="<?= (int)$r['vehicles'] ?>"><?= (int)$r['vehicles'] ?></td>
      <td data-v="<?= $act ? 'Active' : 'Inactive' ?>"><span class="dt-pill <?= $act ? 's-Delivered' : 's-Cancelled' ?>"><?= $act ? 'Active' : 'Inactive' ?></span></td>
      <?php if ($canEdit): ?><td><div class="m-act">
        <button type="button" onclick='tptOpen(<?= e(json_encode(array_intersect_key($r, array_flip(['id', 'name', 'owner_name', 'phone', 'email', 'gst_no', 'pan_no', 'aadhaar_no', 'address'])))) ?>)'>Edit</button>
        <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="tpt_toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button><?= $act ? 'Deactivate' : 'Activate' ?></button></form></div></td><?php endif; ?>
    </tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<dialog class="mdlg" id="d_tpt"><form method="post"><input type="hidden" name="csrf" value="<?= e($tok) ?>"><input type="hidden" name="action" value="tpt_save"><input type="hidden" name="id" value="0">
  <h3><span class="ttl">Add transporter</span></h3>
  <?php if ($err !== ''): ?><div class="alert alert-err" role="alert" style="margin:12px 18px 0"><?= e($err) ?></div><?php endif; ?>
  <div class="mb">
    <div class="f w"><label>Transporter name <i>*</i></label><input name="name" required maxlength="120"></div>
    <div class="f"><label>Owner name</label><input name="owner_name" maxlength="120"></div>
    <div class="f"><label>Mobile</label><input name="phone" inputmode="numeric" maxlength="15"></div>
    <div class="f w"><label>Email id</label><input type="email" name="email" maxlength="150"></div>
    <div class="f"><label>GST no.</label><input name="gst_no" maxlength="15" style="text-transform:uppercase" placeholder="06ABCDE1234F1Z5"></div>
    <div class="f"><label>PAN</label><input name="pan_no" maxlength="10" style="text-transform:uppercase" placeholder="ABCDE1234F"></div>
    <div class="f"><label>Aadhaar</label><input name="aadhaar_no" inputmode="numeric" maxlength="12" placeholder="12 digit"></div>
    <div class="f w"><label>Address</label><textarea name="address" maxlength="500"></textarea></div>
  </div>
  <div class="mf"><button type="button" class="dt-btn" onclick="this.closest('dialog').close()">Cancel</button><button class="dt-btn pri">Save</button></div></form></dialog>
<script>
function tptOpen(data){
  const dlg=document.getElementById('d_tpt'), f=dlg.querySelector('form'); f.reset(); f.elements.id.value=0;
  const edit=!!(data&&+data.id);
  if(data) Object.keys(data).forEach(k=>{const el=f.elements[k]; if(el&&el.type!=='hidden'||k==='id'&&el) el.value=data[k]==null?'':data[k];});
  dlg.querySelector('.ttl').textContent=edit?'Edit transporter':'Add transporter';
  dlg.showModal(); f.elements.name.focus();
}
<?php if ($reopen): ?>document.addEventListener('DOMContentLoaded',()=>tptOpen(<?= json_encode($reopen, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>));<?php endif; ?>
</script>
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
