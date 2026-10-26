<?php
/*
 * Masters on the data-table component: Vehicle Types · Locations (DCs + stores) · Routes · Users.
 * Stores / vehicles / drivers / TPT keep their own pages (MASTER.php, MASTER_OPS.php); Locations links into the same store and DC editors.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
require_once TMS_ROOT . '/app/services/prd_planner.php';
$d = db();

$tabs = ['vehicle_types' => 'Vehicle types', 'locations' => 'Locations', 'routes' => 'Routes', 'users' => 'Users'];
$tab = (string)($_GET['tab'] ?? 'vehicle_types');
if (!isset($tabs[$tab]) || ($tab === 'users' && !can('users'))) $tab = 'vehicle_types';
$canEdit = $tab === 'users' ? can('users') : can('master');
$err = '';

/** Every save ends here: message for the next page load, then back to the tab. */
$done = function (string $msg, string $to) { setflash($msg); redirect('MASTERS.php?tab=' . $to); };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $action = (string)($_POST['action'] ?? ''); $id = (int)($_POST['id'] ?? 0);
  try {
    // ---- vehicle types
    if ($action === 'save_vtype') {
      require_perm('master'); $tab = 'vehicle_types';
      $name = trim((string)($_POST['type_name'] ?? '')); if ($name === '') throw new Exception('Type name is required.');
      $cap = (int)($_POST['capacity_pkt'] ?? 0); if ($cap < 1) throw new Exception('Capacity (PKT) must be at least 1.');
      $val = (float)($_POST['value_capacity'] ?? 0); $spd = (float)($_POST['avg_speed_kmh'] ?? 40); if ($spd <= 0) $spd = 40;
      $dim = fn(string $k) => ($v = trim((string)($_POST[$k] ?? ''))) !== '' && (float)$v > 0 ? round((float)$v, 2) : null;
      $ton = $dim('capacity_ton'); $len = $dim('length_ft'); $brd = $dim('breadth_ft'); $hgt = $dim('height_ft');
      $dup = $d->prepare("SELECT 1 FROM tms_vehicle_types WHERE lower(type_name)=lower(?) AND id<>?"); $dup->execute([$name, $id]);
      if ($dup->fetchColumn()) throw new Exception("Vehicle type $name already exists.");
      $act = isset($_POST['active']) ? 't' : 'f';
      $d->beginTransaction();
      if ($id) {
        $old = $d->prepare("SELECT type_name FROM tms_vehicle_types WHERE id=? FOR UPDATE"); $old->execute([$id]); $oldName = $old->fetchColumn();
        if ($oldName === false) throw new Exception('Vehicle type not found.');
        $d->prepare("UPDATE tms_vehicle_types SET type_name=?,capacity_pkt=?,value_capacity=?,avg_speed_kmh=?,capacity_ton=?,length_ft=?,breadth_ft=?,height_ft=?,active=?::boolean WHERE id=?")->execute([$name, $cap, $val, $spd, $ton, $len, $brd, $hgt, $act, $id]);
        // vehicles carry the type as text; a rename must follow
        if ($oldName !== $name) $d->prepare("UPDATE vehicles SET vehicle_type=? WHERE lower(vehicle_type)=lower(?)")->execute([$name, $oldName]);
      } else {
        $d->prepare("INSERT INTO tms_vehicle_types(type_name,capacity_pkt,value_capacity,avg_speed_kmh,capacity_ton,length_ft,breadth_ft,height_ft,active) VALUES(?,?,?,?,?,?,?,?,?::boolean)")->execute([$name, $cap, $val, $spd, $ton, $len, $brd, $hgt, $act]);
      }
      $d->commit(); $done($id ? "Vehicle type $name updated." : "Vehicle type $name added.", 'vehicle_types');
    }
    // ---- locations: quick coordinates (the full editors stay in MASTER.php)
    if ($action === 'set_geo') {
      require_perm('master'); $tab = 'locations';
      $kind = (string)($_POST['kind'] ?? ''); $table = $kind === 'dc' ? 'tms_dc_master' : ($kind === 'store' ? 'stores' : '');
      if ($table === '') throw new Exception('Invalid location type.');
      $lat = str_replace(',', '.', trim((string)($_POST['lat'] ?? ''))); $lng = str_replace(',', '.', trim((string)($_POST['lng'] ?? '')));
      if (!is_numeric($lat) || !is_numeric($lng)) throw new Exception('Latitude and longitude must be numbers.');
      if (abs((float)$lat) > 90 || abs((float)$lng) > 180) throw new Exception('Latitude must be within ±90 and longitude within ±180.');
      if ((float)$lat === 0.0 && (float)$lng === 0.0) throw new Exception('0, 0 is not a location.');
      $u = $d->prepare("UPDATE $table SET latitude=?,longitude=? WHERE id=?"); $u->execute([(float)$lat, (float)$lng, $id]);
      if (!$u->rowCount()) throw new Exception('Location not found.');
      $done('Coordinates saved.', 'locations');
    }
    // ---- routes
    if ($action === 'save_route') {
      require_perm('master'); $tab = 'routes';
      $code = strtoupper(trim((string)($_POST['route_code'] ?? ''))); $name = trim((string)($_POST['route_name'] ?? ''));
      if ($code === '' || $name === '') throw new Exception('Route code and name are required.');
      $dc = (int)($_POST['dc_id'] ?? 0);
      $codes = array_values(array_unique(array_filter(array_map('strtoupper', preg_split('/[\s,;]+/', (string)($_POST['stores'] ?? '')) ?: []))));
      if (!$codes) throw new Exception('Add at least one store code.');
      if (count($codes) > 60) throw new Exception('A route can have at most 60 stores.');
      $find = $d->prepare("SELECT id FROM stores WHERE upper(store_code)=?"); $ids = []; $unknown = [];
      foreach ($codes as $c) { $find->execute([$c]); $sid = $find->fetchColumn(); if ($sid === false) $unknown[] = $c; else $ids[] = (int)$sid; }
      if ($unknown) throw new Exception('Unknown store code(s): ' . implode(', ', $unknown));
      $dup = $d->prepare("SELECT 1 FROM tms_routes WHERE upper(route_code)=? AND id<>?"); $dup->execute([$code, $id]);
      if ($dup->fetchColumn()) throw new Exception("Route code $code already exists.");
      $act = isset($_POST['active']) ? 't' : 'f';
      $d->beginTransaction();
      if ($id) {
        $d->prepare("UPDATE tms_routes SET route_code=?,route_name=?,dc_id=?,active=?::boolean WHERE id=?")->execute([$code, $name, $dc ?: null, $act, $id]);
        $d->prepare("DELETE FROM tms_route_stops WHERE route_id=?")->execute([$id]);
      } else {
        $ins = $d->prepare("INSERT INTO tms_routes(route_code,route_name,dc_id,active) VALUES(?,?,?,?::boolean) RETURNING id"); $ins->execute([$code, $name, $dc ?: null, $act]); $id = (int)$ins->fetchColumn();
      }
      $st = $d->prepare("INSERT INTO tms_route_stops(route_id,seq,store_id) VALUES(?,?,?)");
      foreach ($ids as $i => $sid) $st->execute([$id, $i + 1, $sid]);
      $d->commit(); $done("Route $code saved with " . count($ids) . ' stores.', 'routes');
    }
    // ---- users (admin only)
    if (in_array($action, ['save_user', 'reset_password'], true)) {
      require_perm('users'); $tab = 'users';
      $me = (int)($_SESSION['user_id'] ?? 0);
      $roles = array_keys(tms_role_perms());
      if ($action === 'reset_password') {
        $pw = (string)($_POST['password'] ?? ''); if (strlen($pw) < 8) throw new Exception('Password must be at least 8 characters.');
        $u = $d->prepare("UPDATE users SET password=? WHERE id=?"); $u->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
        if (!$u->rowCount()) throw new Exception('User not found.');
        $done('Password changed.', 'users');
      }
      $username = trim((string)($_POST['username'] ?? '')); $full = trim((string)($_POST['full_name'] ?? '')); $email = trim((string)($_POST['email'] ?? ''));
      $role = strtolower(trim((string)($_POST['role'] ?? ''))); $act = isset($_POST['active']);
      if (!in_array($role, $roles, true)) throw new Exception('Choose a valid role.');
      if ($full === '') throw new Exception('Full name is required.');
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email address is not valid.');
      $d->beginTransaction();
      if ($id) {
        $cur = $d->prepare("SELECT username, lower(role) role, COALESCE(active,true) active FROM users WHERE id=? FOR UPDATE"); $cur->execute([$id]); $c = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new Exception('User not found.');
        if ($id === $me && (!$act || $role !== 'admin')) throw new Exception('You cannot deactivate yourself or take away your own admin role.');
        if ($c['role'] === 'admin' && $c['active'] && (!$act || $role !== 'admin')) {
          $n = (int)$d->query("SELECT COUNT(*) FROM users WHERE lower(role)='admin' AND COALESCE(active,true)")->fetchColumn();
          if ($n < 2) throw new Exception('This is the last active admin; make another user admin first.');
        }
        $d->prepare("UPDATE users SET full_name=?,name=?,email=NULLIF(?,''),role=?,active=? WHERE id=?")->execute([$full, $full, $email, $role, $act ? 't' : 'f', $id]);
      } else {
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) throw new Exception('Username: 3–40 letters, digits, dot, dash or underscore.');
        $pw = (string)($_POST['password'] ?? ''); if (strlen($pw) < 8) throw new Exception('Password must be at least 8 characters.');
        $dup = $d->prepare("SELECT 1 FROM users WHERE lower(username)=lower(?)"); $dup->execute([$username]); if ($dup->fetchColumn()) throw new Exception("Username $username is already taken.");
        $d->prepare("INSERT INTO users(username,password,full_name,name,email,role,active,created_by) VALUES(?,?,?,?,NULLIF(?,''),?,?::boolean,?)")
          ->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $full, $full, $email, $role, $act ? 't' : 'f', userName()]);
      }
      $d->commit(); $done($id ? 'User updated.' : "User $username created.", 'users');
    }
    throw new Exception('Unknown action.');
  } catch (Throwable $x) {
    if ($d->inTransaction()) $d->rollBack();
    $err = tms_friendly_error($x);
  }
}

// ---------------------------------------------------------------- data
$flag = fn($v) => $v === true || $v === 't' || $v === '1' || $v === 1;
$rowsVt = $rowsLoc = $rowsRt = $rowsUs = []; $dcOpts = [];
if ($tab === 'vehicle_types') {
  $rowsVt = $d->query("SELECT t.*, (SELECT COUNT(*) FROM vehicles v WHERE lower(v.vehicle_type)=lower(t.type_name) AND COALESCE(v.active,true)) vehicles FROM tms_vehicle_types t ORDER BY t.active DESC, lower(t.type_name)")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($tab === 'locations') {
  $rowsLoc = $d->query("SELECT 'dc' kind, id, dc_code code, dc_name name, NULL::text city, NULL::text state, '' cluster, latitude, longitude, active, created_at, NULL::text district, NULL::text grade, NULL::text region, NULL::text email, NULL::date opened, oracle_site_code site, NULL::text sstatus FROM tms_dc_master
    UNION ALL SELECT 'store', id, store_code, store_name, city, state, COALESCE(cluster_type,''), latitude, longitude, COALESCE(active,true), created_at, district, store_grade, region_type, email_id, opening_date, oracle_site_code, store_status FROM stores ORDER BY kind, code")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($tab === 'routes') {
  $dcRows = $d->query("SELECT id,dc_code,dc_name,latitude,longitude FROM tms_dc_master ORDER BY dc_code")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($dcRows as $r) $dcOpts[(int)$r['id']] = $r;
  $stops = [];
  foreach ($d->query("SELECT rs.route_id, rs.seq, s.store_code, s.latitude, s.longitude FROM tms_route_stops rs JOIN stores s ON s.id=rs.store_id ORDER BY rs.route_id, rs.seq")->fetchAll(PDO::FETCH_ASSOC) as $r) $stops[(int)$r['route_id']][] = $r;
  foreach ($d->query("SELECT * FROM tms_routes ORDER BY active DESC, route_code")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $legs = $stops[(int)$r['id']] ?? []; $dc = $dcOpts[(int)$r['dc_id']] ?? null;
    $pts = $dc ? [['lat' => $dc['latitude'], 'lng' => $dc['longitude']]] : []; foreach ($legs as $l) $pts[] = ['lat' => $l['latitude'], 'lng' => $l['longitude']];
    $km = 0.0; $known = count($pts) > 1;
    for ($i = 1; $i < count($pts); $i++) { $k = prd_km($pts[$i - 1], $pts[$i]); if ($k === null) { $known = false; break; } $km += $k; }
    $r['codes'] = array_column($legs, 'store_code'); $r['km'] = $known ? round($km) : null; $r['dc'] = $dc; $rowsRt[] = $r;
  }
} else {
  $rowsUs = $d->query("SELECT id, username, full_name, email, lower(COALESCE(role,'')) role, COALESCE(active,true) active, created_at, created_by FROM users ORDER BY COALESCE(active,true) DESC, lower(username)")->fetchAll(PDO::FETCH_ASSOC);
}
$activeStatuses = [['v' => 'Active', 'l' => 'Active'], ['v' => 'Inactive', 'l' => 'Inactive']];

head('Masters');
?>
<link rel="stylesheet" href="<?= e(asset('assets/css/dt.css')) ?>">
<script src="<?= e(asset('assets/js/dt.js')) ?>" defer></script>
<style>
.mt-tabs{display:flex;gap:2px;border-bottom:1px solid var(--line);margin:0 0 8px}
.top{margin-bottom:4px!important;padding-top:8px!important;padding-bottom:8px!important}
/* compact top area: tabs, counts and toolbar use as little height as possible so the table starts high on the screen */
.stats{margin:0 0 6px!important;gap:6px!important}.stats>*{padding:3px 10px!important;font-size:12px!important;border-radius:8px!important;min-height:0!important;line-height:1.4!important}
.dt-bar{gap:6px!important;margin:0 0 8px!important}
.dt-bar .dt-btn,.dt-bar select,.dt-date,.dt-search input{height:32px!important;font-size:12px!important}
.dt-bar .dt-btn{padding:0 10px!important}.dt-date input[type=date]{height:28px!important;font-size:12px!important;width:112px!important}
.dt-search{max-width:240px!important;flex:0 1 220px!important}.dt-bar select{min-width:120px!important}
.mt-tabs a{padding:6px 14px;font-size:13px;color:#475467;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}
.mt-tabs a.on{color:var(--blue);border-color:var(--blue);font-weight:600}
.mdlg{border:0;border-radius:12px;padding:0;width:min(560px,calc(100vw - 32px));box-shadow:0 24px 70px rgba(15,23,42,.32)}
.mdlg::backdrop{background:rgba(15,23,42,.5)}
.mdlg form{margin:0}.mdlg h3{margin:0;padding:14px 18px;font-size:15px;border-bottom:1px solid var(--line);background:#f8fafc;border-radius:12px 12px 0 0}
.mdlg .mb{padding:16px 18px;display:grid;grid-template-columns:1fr 1fr;gap:12px}
.mdlg .mb .f{display:flex;flex-direction:column;gap:4px}.mdlg .mb .f.w{grid-column:1/-1}
.mdlg label{font-size:12px;font-weight:600;color:#475467}.mdlg label i{color:#d92d20;font-style:normal}
.mdlg input:not([type=checkbox]),.mdlg select,.mdlg textarea{height:36px;border:1px solid var(--line);border-radius:8px;padding:0 10px;font-size:13px;font-family:inherit;width:100%;box-sizing:border-box}
.mdlg textarea{height:96px;padding:8px 10px;resize:vertical}
.mdlg .mf{display:flex;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid var(--line);background:#fafafa;border-radius:0 0 12px 12px}
.mdlg .hint{font-size:11.5px;color:#667085;font-weight:400}
.m-sub{display:block;color:#667085;font-size:11.5px}.m-warn{color:#b54708}.m-route{max-width:420px;line-height:1.5}
.m-act{display:flex;gap:6px}.m-act button,.m-act a{height:30px;border:1px solid var(--line);background:#fff;border-radius:7px;padding:0 10px;font-size:12px;cursor:pointer;color:#344054;text-decoration:none;display:inline-flex;align-items:center}
@media(max-width:560px){.mdlg .mb{grid-template-columns:1fr}}
</style>
<?php if ($err): ?><div class="page-alerts"><div class="alert alert-err" role="alert"><?= e($err) ?></div></div><?php endif; ?>
<?php flash(); ?>
<nav class="mt-tabs" aria-label="Masters">
  <?php foreach ($tabs as $k => $label): if ($k === 'users' && !can('users')) continue; ?><a class="<?= $k === $tab ? 'on' : '' ?>" href="MASTERS.php?tab=<?= e($k) ?>"><?= e($label) ?></a><?php endforeach; ?>
</nav>

<?php if ($tab === 'vehicle_types'):
  echo tms_stats([['types', count($rowsVt), 'dark'], ['active', count(array_filter($rowsVt, fn($r) => $flag($r['active']))), 'good'], ['vehicles', array_sum(array_column($rowsVt, 'vehicles'))]]); ?>
<div class="dt" data-key="mvt" data-default-status="Active" data-page-size="25" data-date-label="Created" data-export="vehicle-types" data-statuses="<?= e(json_encode($activeStatuses)) ?>">
  <div data-dt-actions><?php if ($canEdit): ?><button type="button" class="dt-btn pri" onclick="mOpen('d_vt')">＋ Add vehicle type</button><?php endif; ?></div>
  <div class="dt-wrap"><table class="dt-table"><thead><tr><th data-sort="text">Type</th><th data-sort="num" class="n">Capacity (Ton)</th><th data-sort="text">Size L×B×H (ft)</th><th data-sort="num" class="n">Capacity (PKT)</th><th data-sort="num" class="n">Value capacity</th><th data-sort="num" class="n">Avg speed</th><th data-sort="num" class="n">Vehicles</th><th data-sort="text">Status</th><?php if ($canEdit): ?><th data-nohide data-noexport></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($rowsVt as $r): $a = $flag($r['active']); ?>
    <tr data-row data-status="<?= $a ? 'Active' : 'Inactive' ?>" data-date="<?= e(substr((string)$r['created_at'], 0, 10)) ?>" data-q="<?= e(strtolower($r['type_name'])) ?>">
      <td data-v="<?= e($r['type_name']) ?>"><b><?= e($r['type_name']) ?></b></td>
      <?php $fm = fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 2), '0'), '.'); $sz = ($r['length_ft'] || $r['breadth_ft'] || $r['height_ft']) ? $fm($r['length_ft']) . ' × ' . $fm($r['breadth_ft']) . ' × ' . $fm($r['height_ft']) : ''; ?>
      <td class="n" data-v="<?= (float)$r['capacity_ton'] ?>"><?= $r['capacity_ton'] !== null ? e($fm($r['capacity_ton'])) . ' T' : '<span class="m-warn">–</span>' ?></td>
      <td data-v="<?= e($sz) ?>"><?= $sz !== '' ? e($sz) : '<span class="m-warn">–</span>' ?></td>
      <td class="n" data-v="<?= (int)$r['capacity_pkt'] ?>"><?= number_format((int)$r['capacity_pkt']) ?></td>
      <td class="n" data-v="<?= (float)$r['value_capacity'] ?>" data-x="<?= (float)$r['value_capacity'] ?>">₹<?= number_format((float)$r['value_capacity']) ?></td>
      <td class="n" data-v="<?= (float)$r['avg_speed_kmh'] ?>"><?= e(rtrim(rtrim(number_format((float)$r['avg_speed_kmh'], 1), '0'), '.')) ?> km/h</td>
      <td class="n" data-v="<?= (int)$r['vehicles'] ?>"><?= (int)$r['vehicles'] ?></td>
      <td data-v="<?= $a ? 'Active' : 'Inactive' ?>"><span class="dt-pill <?= $a ? 's-Delivered' : 's-Cancelled' ?>"><?= $a ? 'Active' : 'Inactive' ?></span></td>
      <?php if ($canEdit): ?><td><div class="m-act"><button type="button" onclick='mOpen("d_vt",<?= e(json_encode(['id' => (int)$r['id'], 'type_name' => $r['type_name'], 'capacity_pkt' => (int)$r['capacity_pkt'], 'value_capacity' => (float)$r['value_capacity'], 'avg_speed_kmh' => (float)$r['avg_speed_kmh'], 'capacity_ton' => $r['capacity_ton'], 'length_ft' => $r['length_ft'], 'breadth_ft' => $r['breadth_ft'], 'height_ft' => $r['height_ft'], 'active' => $a])) ?>)'>Edit</button></div></td><?php endif; ?>
    </tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<dialog class="mdlg" id="d_vt"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="save_vtype"><input type="hidden" name="id" value="0">
  <h3><span class="ttl">Vehicle type</span></h3>
  <div class="mb">
    <div class="f w"><label>Type name <i>*</i></label><input name="type_name" required maxlength="60" placeholder="e.g. 24FT"></div>
    <div class="f"><label>Capacity (Ton)</label><input name="capacity_ton" type="number" min="0" step="0.01" placeholder="e.g. 7"></div>
    <div class="f"><label>Length (ft)</label><input name="length_ft" type="number" min="0" step="0.01"></div>
    <div class="f"><label>Breadth (ft)</label><input name="breadth_ft" type="number" min="0" step="0.01"></div>
    <div class="f"><label>Height (ft)</label><input name="height_ft" type="number" min="0" step="0.01"></div>
    <div class="f"><label>Capacity (PKT) <i>*</i></label><input name="capacity_pkt" type="number" min="1" value="300" required></div>
    <div class="f"><label>Value capacity (₹)</label><input name="value_capacity" type="number" min="0" step="1" value="2500000"></div>
    <div class="f"><label>Average speed (km/h)</label><input name="avg_speed_kmh" type="number" min="1" step="0.5" value="40"></div>
    <div class="f"><label><input type="checkbox" name="active" checked> Active</label></div>
  </div>
  <div class="mf"><button type="button" class="dt-btn" onclick="this.closest('dialog').close()">Cancel</button><button class="dt-btn pri">Save</button></div></form></dialog>

<?php elseif ($tab === 'locations'):
  $locStatuses = [['v' => 'Active', 'l' => 'Active', 'in' => ['Active', 'NoGeo']], ['v' => 'NoGeo', 'l' => 'No coordinates'], ['v' => 'Inactive', 'l' => 'Inactive']]; ?>
<style>
#dtLoc .dt-wrap{max-height:calc(100vh - 215px)}
#dtLoc .dt-table{table-layout:fixed;font-size:12px}
#dtLoc .dt-table th{padding:7px 14px 7px 8px;font-size:11px;background:#eef3fb;color:#1d2f52}
#dtLoc .dt-table td{padding:4px 8px;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#dtLoc .m-act button,#dtLoc .m-act a{height:24px;padding:0 8px;font-size:11px}
</style>
<div class="dt" id="dtLoc" data-resize="1" data-key="mloc2" data-default-status="Active" data-page-size="50" data-date-label="Created" data-export="locations" data-statuses="<?= e(json_encode($locStatuses)) ?>">
  <div data-dt-actions><?php if ($canEdit): ?><form method="post" action="MASTER.php" style="display:inline;margin:0" onsubmit="return confirm('Refresh the stores from Oracle? New stores are added and names / state / cluster / status are updated. Your latitude / longitude are not changed.')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="sync_stores_oracle"><button class="dt-btn">↻ Refresh stores</button></form><a class="dt-btn" href="MASTER_BULK.php">Bulk import</a><a class="dt-btn" href="MASTER.php?tab=dcs&amp;add=1">＋ Add source (DC)</a><a class="dt-btn pri" href="MASTER.php?tab=stores&amp;add=1">＋ Add store</a><?php endif; ?></div>
  <div class="dt-wrap"><table class="dt-table"><thead><tr><th data-sort="text">SITE_CODE</th><th data-sort="text">STORE_NAME</th><th data-sort="text">INITIAL_NAME</th><th data-sort="date">OPENING_DATE</th><th data-sort="text">STATE</th><th data-sort="text">STORE_STATUS</th><th data-sort="text">CLUSTER_TYPE</th><th data-sort="text">CITY_NAME</th><th data-sort="text">DISTRICT</th><th data-sort="text">LOCATION</th><th data-sort="text">EMAIL_ID</th><th data-sort="text">STORE_GRADE</th><th data-sort="text">REGION_TYPE</th><th data-sort="num" class="n">LONGITUDE</th><th data-sort="num" class="n">LATITUDE</th><?php if ($canEdit): ?><th data-nohide data-noexport></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($rowsLoc as $r):
    $a = $flag($r['active']); $geo = (float)$r['latitude'] || (float)$r['longitude']; $stt = !$a ? 'Inactive' : ($geo ? 'Active' : 'NoGeo'); ?>
    <tr data-row data-status="<?= $stt ?>" data-q="<?= e(strtolower($r['site'] . ' ' . $r['kind'] . ' ' . $r['code'] . ' ' . $r['name'] . ' ' . $r['city'] . ' ' . $r['district'] . ' ' . $r['state'] . ' ' . $r['cluster'])) ?>">
      <?php $cell = fn($v, $b = false) => '<td data-v="' . e((string)$v) . '" title="' . e((string)$v) . '">' . ($b ? '<b>' . e((string)$v) . '</b>' : e((string)$v)) . '</td>'; ?>
      <?= $cell($r['site'] ?: $r['code'], true), $cell($r['name']), $cell($r['code']), $cell($r['opened'] ? date('d-M-Y', strtotime((string)$r['opened'])) : '') ?>
      <?= $cell($r['state']), $cell($r['sstatus'] ?? ''), $cell($r['cluster']), $cell($r['city']), $cell($r['district']), $cell($r['kind'] === 'dc' ? 'DC' : 'STORE'), $cell($r['email']), $cell($r['grade']), $cell($r['region']) ?>
      <td class="n" data-v="<?= (float)$r['longitude'] ?>"><?= (float)$r['longitude'] ? e(round((float)$r['longitude'], 5)) : '<span class="m-warn">–</span>' ?></td><td class="n" data-v="<?= (float)$r['latitude'] ?>"><?= (float)$r['latitude'] ? e(round((float)$r['latitude'], 5)) : '<span class="m-warn">–</span>' ?></td>
      <?php if ($canEdit): ?><td><div class="m-act">
        <button type="button" onclick='mOpen("d_geo",<?= e(json_encode(['id' => (int)$r['id'], 'kind' => $r['kind'], 'lat' => $geo ? (float)$r['latitude'] : '', 'lng' => $geo ? (float)$r['longitude'] : '', 'title' => $r['code'] . ' · ' . $r['name']])) ?>)'>Coordinates</button>
        <a href="MASTER.php?tab=<?= $r['kind'] === 'dc' ? 'dcs' : 'stores' ?>&amp;add=1&amp;edit=<?= (int)$r['id'] ?>">Edit</a></div></td><?php endif; ?>
    </tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<dialog class="mdlg" id="d_geo"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="set_geo"><input type="hidden" name="id" value="0"><input type="hidden" name="kind" value="">
  <h3><span class="ttl">Coordinates</span></h3>
  <div class="mb">
    <div class="f w hint" data-fill="title"></div>
    <div class="f"><label>Latitude <i>*</i></label><input name="lat" required inputmode="decimal" placeholder="25.6093"></div>
    <div class="f"><label>Longitude <i>*</i></label><input name="lng" required inputmode="decimal" placeholder="85.1376"></div>
    <div class="f w hint">Tip: in Google Maps right-click the place and click the numbers to copy “lat, lng”. Coordinates are used to club nearby stores in a trip.</div>
  </div>
  <div class="mf"><button type="button" class="dt-btn" onclick="this.closest('dialog').close()">Cancel</button><button class="dt-btn pri">Save</button></div></form></dialog>

<?php elseif ($tab === 'routes'):
  echo tms_stats([['routes', count($rowsRt), 'dark'], ['active', count(array_filter($rowsRt, fn($r) => $flag($r['active']))), 'good']]); ?>
<div class="dt" data-key="mrt" data-default-status="Active" data-page-size="25" data-date-label="Created" data-export="routes" data-statuses="<?= e(json_encode($activeStatuses)) ?>">
  <div data-dt-actions><?php if ($canEdit): ?><button type="button" class="dt-btn pri" onclick="mOpen('d_rt')">＋ Add route</button><?php endif; ?></div>
  <div class="dt-wrap"><table class="dt-table"><thead><tr><th data-sort="text">Code</th><th data-sort="text">Route name</th><th data-sort="text">From DC</th><th>Stores (in order)</th><th data-sort="num" class="n">Stops</th><th data-sort="num" class="n">Distance</th><th data-sort="text">Status</th><?php if ($canEdit): ?><th data-nohide data-noexport></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($rowsRt as $r): $a = $flag($r['active']); $path = implode(' → ', $r['codes']); ?>
    <tr data-row data-status="<?= $a ? 'Active' : 'Inactive' ?>" data-date="<?= e(substr((string)$r['created_at'], 0, 10)) ?>" data-q="<?= e(strtolower($r['route_code'] . ' ' . $r['route_name'] . ' ' . $path)) ?>">
      <td data-v="<?= e($r['route_code']) ?>"><b><?= e($r['route_code']) ?></b></td>
      <td data-v="<?= e($r['route_name']) ?>"><?= e($r['route_name']) ?></td>
      <td data-v="<?= e((string)($r['dc']['dc_code'] ?? '')) ?>"><?= $r['dc'] ? e($r['dc']['dc_code']) . '<span class="m-sub">' . e($r['dc']['dc_name']) . '</span>' : '–' ?></td>
      <td class="m-route" data-x="<?= e($path) ?>"><?= e($path) ?></td>
      <td class="n" data-v="<?= count($r['codes']) ?>"><?= count($r['codes']) ?></td>
      <td class="n" data-v="<?= (int)($r['km'] ?? 0) ?>"><?= $r['km'] !== null ? (int)$r['km'] . ' km' : '<span class="m-sub" title="A DC or store on this route has no coordinates">–</span>' ?></td>
      <td data-v="<?= $a ? 'Active' : 'Inactive' ?>"><span class="dt-pill <?= $a ? 's-Delivered' : 's-Cancelled' ?>"><?= $a ? 'Active' : 'Inactive' ?></span></td>
      <?php if ($canEdit): ?><td><div class="m-act"><button type="button" onclick='mOpen("d_rt",<?= e(json_encode(['id' => (int)$r['id'], 'route_code' => $r['route_code'], 'route_name' => $r['route_name'], 'dc_id' => (int)$r['dc_id'], 'stores' => implode(', ', $r['codes']), 'active' => $a])) ?>)'>Edit</button></div></td><?php endif; ?>
    </tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<dialog class="mdlg" id="d_rt"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="save_route"><input type="hidden" name="id" value="0">
  <h3><span class="ttl">Route</span></h3>
  <div class="mb">
    <div class="f"><label>Route code <i>*</i></label><input name="route_code" required maxlength="30" placeholder="e.g. BR-NORTH-1"></div>
    <div class="f"><label>From DC</label><select name="dc_id"><option value="0">—</option><?php foreach ($dcOpts as $dc): ?><option value="<?= (int)$dc['id'] ?>"><?= e($dc['dc_code'] . ' · ' . $dc['dc_name']) ?></option><?php endforeach; ?></select></div>
    <div class="f w"><label>Route name <i>*</i></label><input name="route_name" required maxlength="120"></div>
    <div class="f w"><label>Store codes in delivery order <i>*</i></label><textarea name="stores" required placeholder="MNR, HJD, PKM"></textarea><span class="hint">Separate with comma, space or new line. The first store is visited first.</span></div>
    <div class="f"><label><input type="checkbox" name="active" checked> Active</label></div>
  </div>
  <div class="mf"><button type="button" class="dt-btn" onclick="this.closest('dialog').close()">Cancel</button><button class="dt-btn pri">Save</button></div></form></dialog>

<?php else:
  $nAdm = count(array_filter($rowsUs, fn($r) => $r['role'] === 'admin' && $flag($r['active'])));
  echo tms_stats([['users', count($rowsUs), 'dark'], ['active', count(array_filter($rowsUs, fn($r) => $flag($r['active']))), 'good'], ['admins', $nAdm]]);
  $roleHelp = ['admin' => 'Everything', 'dispatcher' => 'Plan, load, track', 'picker' => 'Load & gate pass', 'auditor' => 'View only']; ?>
<div class="dt" data-key="musr" data-default-status="Active" data-page-size="25" data-date-label="Created" data-export="users" data-statuses="<?= e(json_encode($activeStatuses)) ?>">
  <div data-dt-actions><button type="button" class="dt-btn pri" onclick="mOpen('d_us')">＋ Add user</button></div>
  <div class="dt-wrap"><table class="dt-table"><thead><tr><th data-sort="text">Username</th><th data-sort="text">Full name</th><th data-sort="text">Email</th><th data-sort="text">Role</th><th data-sort="text">Status</th><th data-sort="date">Created</th><th data-nohide data-noexport></th></tr></thead><tbody>
  <?php foreach ($rowsUs as $r): $a = $flag($r['active']); $mine = (int)$r['id'] === (int)($_SESSION['user_id'] ?? 0); ?>
    <tr data-row data-status="<?= $a ? 'Active' : 'Inactive' ?>" data-date="<?= e(substr((string)$r['created_at'], 0, 10)) ?>" data-q="<?= e(strtolower($r['username'] . ' ' . $r['full_name'] . ' ' . $r['email'] . ' ' . $r['role'])) ?>">
      <td data-v="<?= e($r['username']) ?>"><b><?= e($r['username']) ?></b><?= $mine ? ' <span class="m-sub">(you)</span>' : '' ?></td>
      <td data-v="<?= e((string)$r['full_name']) ?>"><?= e((string)$r['full_name']) ?></td>
      <td data-v="<?= e((string)$r['email']) ?>"><?= e((string)$r['email']) ?></td>
      <td data-v="<?= e($r['role']) ?>"><?= e(ucfirst($r['role'])) ?><span class="m-sub"><?= e($roleHelp[$r['role']] ?? 'View only') ?></span></td>
      <td data-v="<?= $a ? 'Active' : 'Inactive' ?>"><span class="dt-pill <?= $a ? 's-Delivered' : 's-Cancelled' ?>"><?= $a ? 'Active' : 'Inactive' ?></span></td>
      <td data-v="<?= e((string)$r['created_at']) ?>" data-x="<?= e((string)$r['created_at']) ?>"><?= $r['created_at'] ? e(date('d M Y', strtotime((string)$r['created_at']))) : '–' ?></td>
      <td><div class="m-act">
        <button type="button" onclick='mOpen("d_us",<?= e(json_encode(['id' => (int)$r['id'], 'username' => $r['username'], 'full_name' => (string)$r['full_name'], 'email' => (string)$r['email'], 'role' => $r['role'], 'active' => $a])) ?>)'>Edit</button>
        <button type="button" onclick='mOpen("d_pw",<?= e(json_encode(['id' => (int)$r['id'], 'title' => $r['username']])) ?>)'>Password</button></div></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<dialog class="mdlg" id="d_us"><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="save_user"><input type="hidden" name="id" value="0">
  <h3><span class="ttl">User</span></h3>
  <div class="mb">
    <div class="f"><label>Username <i>*</i></label><input name="username" maxlength="40" autocomplete="off"></div>
    <div class="f"><label>Role <i>*</i></label><select name="role"><?php foreach (array_keys(tms_role_perms()) as $ro): ?><option value="<?= e($ro) ?>"><?= e(ucfirst($ro) . ' – ' . ($roleHelp[$ro] ?? 'View only')) ?></option><?php endforeach; ?></select></div>
    <div class="f w"><label>Full name <i>*</i></label><input name="full_name" required maxlength="120"></div>
    <div class="f w"><label>Email</label><input name="email" type="email" maxlength="150"></div>
    <div class="f w" data-new-only><label>Password <i>*</i></label><input name="password" type="password" minlength="8" autocomplete="new-password"><span class="hint">At least 8 characters. Share it with the user; they can sign in right away.</span></div>
    <div class="f"><label><input type="checkbox" name="active" checked> Active (can sign in)</label></div>
  </div>
  <div class="mf"><button type="button" class="dt-btn" onclick="this.closest('dialog').close()">Cancel</button><button class="dt-btn pri">Save</button></div></form></dialog>
<dialog class="mdlg" id="d_pw"><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="0">
  <h3><span class="ttl">Change password</span></h3>
  <div class="mb"><div class="f w hint" data-fill="title"></div>
    <div class="f w"><label>New password <i>*</i></label><input name="password" type="password" minlength="8" required autocomplete="new-password"></div></div>
  <div class="mf"><button type="button" class="dt-btn" onclick="this.closest('dialog').close()">Cancel</button><button class="dt-btn pri">Change password</button></div></form></dialog>
<?php endif; ?>

<script>
// Opens a dialog; with data the form is filled (edit), without it the form is blank (add).
function mOpen(id,data){
  const dlg=document.getElementById(id), f=dlg.querySelector('form'); f.reset(); f.elements.id.value=0;
  const edit=!!(data&&data.id);
  dlg.querySelectorAll('[data-fill]').forEach(n=>n.textContent=(data&&data[n.dataset.fill])||'');
  if(data) Object.keys(data).forEach(k=>{const el=f.elements[k]; if(!el||k==='title')return; if(el.type==='checkbox')el.checked=!!data[k]; else el.value=data[k];});
  dlg.querySelectorAll('[data-new-only]').forEach(n=>{n.style.display=edit?'none':'';const i=n.querySelector('input');if(i)i.required=!edit;});
  if(f.elements.username)f.elements.username.readOnly=edit, f.elements.username.required=!edit;
  const t=dlg.querySelector('.ttl'); if(t&&!t.dataset.base)t.dataset.base=t.textContent; if(t&&t.dataset.base!=='Change password'&&t.dataset.base!=='Coordinates')t.textContent=(edit?'Edit ':'Add ')+t.dataset.base.toLowerCase();
  dlg.showModal(); const first=f.querySelector('input:not([type=hidden]):not([readonly])'); if(first)first.focus();
}
</script>
<?php foot(); ?>
