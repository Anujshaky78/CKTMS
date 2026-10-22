<?php
require_once __DIR__.'/../auth/auth.php'; require_once __DIR__.'/../includes/layout.php';
require_login();
$d=db(); $error='';
$type=$_GET['type']??'store'; $isModal=($_GET['modal']??'')==='1'; if(!in_array($type,['dc','store','vehicle','driver'],true))$type='store';
$id=(int)($_GET['edit']??0); $edit=null;
$tab=$type==='dc'?'dcs':($type==='store'?'stores':($type==='vehicle'?'vehicles':'drivers'));
if($id){$table=$type==='dc'?'tms_dc_master':($type==='store'?'stores':($type==='vehicle'?'vehicles':'drivers'));$q=$d->prepare("SELECT * FROM $table WHERE id=?");$q->execute([$id]);$edit=$q->fetch();if(!$edit){setflash('Record not found.');redirect('../pages/MASTER.php?tab='.$tab);}}
$title=$type==='dc'?'Center DC':($type==='store'?'Store':($type==='vehicle'?'Vehicle':'TPT / Driver'));
if(!$isModal){head(($edit?'Edit ':'Add ').$title); flash(); if($error)echo '<div class="alert">'.e($error).'</div>'; } ?>
<style>
<?php if($isModal): ?>html,body{margin:0!important;background:#fff!important}body{overflow:auto!important}.master-entry{max-width:none!important;margin:0!important}.master-card{border:0!important;border-radius:0!important;box-shadow:none!important;min-height:100vh}.master-head{padding:14px 52px 12px 18px}.master-body{padding:14px 18px}.master-footer{position:sticky;bottom:0;z-index:5}
<?php endif; ?>
.master-entry{max-width:980px;margin:18px auto 28px}.master-card{background:#fff;border:1px solid #dbe3ec;border-radius:10px;overflow:hidden;box-shadow:0 4px 18px rgba(15,23,42,.05)}
.master-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:13px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb}.master-head h3{margin:0;font-size:14px}.master-head p{margin:3px 0 0;color:#64748b;font-size:11px}.required-note{font-size:10px;color:#64748b;white-space:nowrap}
.master-body{padding:15px 16px}.section-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#475569;padding-bottom:6px;margin:0 0 10px;border-bottom:1px solid #e8edf2}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px 12px;margin-bottom:15px}.grid .span-2{grid-column:span 2}.grid .span-3{grid-column:span 3}.grid .full{grid-column:1/-1}.fg{margin:0}.fg label{display:block;font-size:10px;font-weight:600;color:#475569;margin:0 0 4px}.input,.select{width:100%;height:34px;padding:6px 9px;font-size:12px}.textarea{min-height:56px;resize:vertical}.check-wrap{height:34px;display:flex;align-items:center;padding-top:13px}.check-wrap label{margin:0;display:flex;align-items:center;gap:6px;font-size:11px}.check-wrap input{margin:0}
.days-box{display:flex;gap:6px;flex-wrap:wrap}.day-chip{position:relative}.day-chip input{position:absolute;opacity:0}.day-chip span{display:inline-flex;align-items:center;justify-content:center;height:28px;min-width:38px;padding:0 8px;border:1px solid #d6dce5;border-radius:6px;background:#fff;color:#475569;font-size:11px;cursor:pointer}.day-chip input:checked+span{background:#e8f1ff;border-color:#4f7cff;color:#1d4ed8;font-weight:700}
.master-footer{display:flex;justify-content:flex-end;gap:8px;padding:11px 16px;background:#fafafa;border-top:1px solid #e5e7eb}.master-footer .btn{height:32px;padding:0 14px;font-size:11px}
@media(max-width:800px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.grid .span-3{grid-column:span 2}}@media(max-width:520px){.master-entry{margin:10px}.grid{grid-template-columns:1fr}.grid .span-2,.grid .span-3{grid-column:1/-1}.master-head{align-items:flex-start}.required-note{display:none}}

.store-form{padding-top:14px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:12px}.section-title small{font-size:9px;font-weight:500;text-transform:none;letter-spacing:0;color:#94a3b8}.section-title i,.fg label i,.dispatch-panel i{font-style:normal;color:#dc2626}.grid-identity{margin-bottom:13px}.address-grid{margin-bottom:13px}.service-grid{margin-bottom:10px}.textarea{min-height:58px!important;line-height:1.35}.input-suffix{position:relative}.input-suffix .input{padding-right:42px}.input-suffix span{position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:10px;color:#64748b;pointer-events:none}.status-field{display:flex;flex-direction:column}.switch-row{height:34px!important;display:flex!important;align-items:center!important;gap:8px!important;margin:0!important;cursor:pointer}.switch-row input{position:absolute;opacity:0}.switch{width:34px;height:18px;border-radius:999px;background:#cbd5e1;position:relative;transition:.15s}.switch:after{content:"";position:absolute;left:2px;top:2px;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.15);transition:.15s}.switch-row input:checked+.switch{background:#2563eb}.switch-row input:checked+.switch:after{left:18px}.switch-row>span:last-child{font-size:11px;color:#334155;font-weight:600}.dispatch-panel{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:10px 11px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:8px}.dispatch-panel>div:first-child{display:flex;flex-direction:column;gap:2px;min-width:190px}.dispatch-panel b{font-size:11px;color:#334155}.dispatch-panel span{font-size:9px;color:#94a3b8}.dispatch-panel .days-box{justify-content:flex-end}.day-chip span{height:30px;min-width:40px;border-radius:7px;font-size:10px}.master-footer{padding:10px 16px}.master-footer .btn{min-width:88px}
@media(max-width:720px){.dispatch-panel{align-items:flex-start;flex-direction:column}.dispatch-panel .days-box{justify-content:flex-start}.grid{grid-template-columns:1fr 1fr}.grid .span-2{grid-column:1/-1}}
</style>
<?php if(!$isModal): ?><div class="page-title">
  <div><h2><?=$edit?'Edit':'Add New'?> <?=e($title)?></h2><div class="note">Master data entry</div></div>
  <a class="btn light" href="../pages/MASTER.php?tab=<?=$tab?>">← Back to Master</a>
</div><?php endif; ?>
<div class="master-entry">
  <div class="master-card">
    <div class="master-head"><div><h3><?=$edit?'Update':'New'?> <?=e($title)?></h3><p>Enter master details and save the record.</p></div><span class="required-note">* Required fields</span></div>
    <form method="post" action="../pages/MASTER.php"<?= $isModal ? ' target="_top"' : '' ?>>
      <input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_master"><input type="hidden" name="type" value="<?=e($type)?>"><input type="hidden" name="id" value="<?=e($edit['id']??0)?>">
<?php if($type==='store'): ?>
      <div class="master-body store-form">
        <div class="section-title"><span>Store Identity</span><small>Core master information</small></div>
        <div class="grid grid-identity">
          <div class="fg"><label>Store Code <i>*</i></label><input class="input" name="code" value="<?=e($edit['store_code']??'')?>" required maxlength="30" placeholder="e.g. ST001"></div>
          <div class="fg span-2"><label>Store Name <i>*</i></label><input class="input" name="name" value="<?=e($edit['store_name']??'')?>" required maxlength="120" placeholder="Enter store name"></div>
          <div class="fg"><label>PIN Code</label><input class="input" name="pincode" value="<?=e($edit['pincode']??'')?>" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" placeholder="6 digit PIN"></div>
        </div>

        <div class="section-title"><span>Store Address</span><small>Used for routing & delivery</small></div>
        <div class="grid address-grid">
          <div class="fg"><label>City</label><input class="input" name="city" value="<?=e($edit['city']??'')?>" maxlength="100" placeholder="e.g. Ballia"></div>
          <div class="fg"><label>State</label><input class="input" name="state" value="<?=e($edit['state']??'')?>" maxlength="100" placeholder="e.g. Uttar Pradesh"></div>
          <div class="fg span-2"><label>Full Address</label><textarea class="input textarea" name="address" rows="2" maxlength="500" placeholder="House / building, street, landmark, area"><?=e($edit['address']??'')?></textarea></div>
        </div>

        <div class="section-title"><span>Location & Service</span><small>Routing, SLA and weekly dispatch setup</small></div>
        <div class="grid service-grid">
          <div class="fg"><label>Latitude</label><input class="input" name="lat" type="number" step="0.0000001" value="<?=e($edit['latitude']??0)?>" placeholder="25.0000000"></div>
          <div class="fg"><label>Longitude</label><input class="input" name="lon" type="number" step="0.0000001" value="<?=e($edit['longitude']??0)?>" placeholder="82.0000000"></div>
          <div class="fg"><label>Transit / SLA</label><div class="input-suffix"><input class="input" name="transit_days" type="number" min="0.1" step="0.1" value="<?=e($edit['transit_days']??1)?>"><span>days</span></div></div>
          <div class="fg status-field"><label>Store Status</label><label class="switch-row"><input type="checkbox" name="active" <?=(!isset($edit['active'])||$edit['active'])?'checked':''?>><span class="switch"></span><span>Active Store</span></label></div>
        </div>

        <div class="dispatch-panel">
          <div><b>Weekly Dispatch Days <i>*</i></b><span>Select exactly 2 days</span></div>
          <div class="days-box"><?php $rawDays=$edit['dispatch_days']??'Mon,Tue';$selectedDays=is_array($rawDays)?array_values(array_filter(array_map('trim',$rawDays))):array_values(array_filter(array_map('trim',explode(',',trim((string)$rawDays,'{}" ')))));foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?><label class="day-chip"><input type="checkbox" name="dispatch_days[]" value="<?=$day?>" <?=in_array($day,$selectedDays,true)?'checked':''?>><span><?=$day?></span></label><?php endforeach;?></div>
        </div>
      </div>
<?php elseif($type==='dc'): ?>
      <div class="master-body"><div class="section-title">Center DC Details</div><div class="grid">
        <div class="fg"><label>Center DC Code *</label><input class="input" name="dc_code" value="<?=e($edit['dc_code']??'')?>" required></div><div class="fg span-2"><label>Center DC Name *</label><input class="input" name="dc_name" value="<?=e($edit['dc_name']??'')?>" required></div><div class="fg check-wrap"><label><input type="checkbox" name="active" <?=(!isset($edit['active'])||$edit['active'])?'checked':''?>> Active</label></div>
        <div class="fg"><label>Latitude</label><input class="input" name="lat" type="number" step="0.0000001" value="<?=e($edit['latitude']??0)?>"></div><div class="fg"><label>Longitude</label><input class="input" name="lon" type="number" step="0.0000001" value="<?=e($edit['longitude']??0)?>"></div>
      </div></div>
<?php elseif($type==='vehicle'): ?>
      <div class="master-body"><div class="section-title">Vehicle Details</div><div class="grid">
        <div class="fg"><label>Vehicle No. *</label><input class="input" name="vehicle_number" value="<?=e($edit['vehicle_number']??'')?>" required></div><div class="fg"><label>Vehicle Type *</label><input class="input" name="vehicle_type" value="<?=e($edit['vehicle_type']??'24FT')?>" required></div><div class="fg"><label>Packet Capacity *</label><input class="input" name="capacity" type="number" min="1" value="<?=e($edit['capacity_boxes']??325)?>" required></div><div class="fg"><label>Value Capacity (₹) *</label><input class="input" name="value_capacity" type="number" step="0.01" value="<?=e($edit['value_capacity']??2500000)?>" required></div>
        <div class="fg"><label>Current Latitude</label><input class="input" name="current_lat" type="number" step="0.000001" value="<?=e($edit['current_lat']??0)?>"></div><div class="fg"><label>Current Longitude</label><input class="input" name="current_lon" type="number" step="0.000001" value="<?=e($edit['current_lon']??0)?>"></div><div class="fg"><label>Average Speed km/h</label><input class="input" name="speed" type="number" min="1" value="<?=e($edit['avg_speed_kmh']??40)?>"></div><div class="fg"><label>Status</label><select class="select" name="status"><?php foreach(['Available','Assigned','Dispatched','Inactive'] as $st):?><option <?=($edit['status']??'Available')===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select></div>
        <div class="fg check-wrap"><label><input type="checkbox" name="active" <?=(!isset($edit['active'])||$edit['active'])?'checked':''?>> Active</label></div>
      </div></div>
<?php else: ?>
      <div class="master-body"><div class="section-title">TPT / Driver Details</div><div class="grid">
        <div class="fg"><label>TPT Name</label><input class="input" name="tpt_name" value="<?=e($edit['tpt_name']??'TPT')?>"></div><div class="fg"><label>Driver Name *</label><input class="input" name="driver_name" value="<?=e($edit['driver_name']??'')?>" required></div><div class="fg"><label>Driver Code *</label><input class="input" name="driver_code" value="<?=e($edit['driver_code']??'')?>" required></div><div class="fg"><label>Phone</label><input class="input" name="phone" value="<?=e($edit['phone']??'')?>"></div><div class="fg"><label>License No.</label><input class="input" name="license_no" value="<?=e($edit['license_no']??'')?>"></div><div class="fg"><label>Status</label><select class="select" name="status"><?php foreach(['Available','Assigned','Dispatched','Inactive'] as $st):?><option <?=($edit['status']??'Available')===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select></div><div class="fg check-wrap"><label><input type="checkbox" name="active" <?=(!isset($edit['active'])||$edit['active'])?'checked':''?>> Active</label></div>
      </div></div>
<?php endif; ?>
      <div class="master-footer"><a class="btn light" href="../pages/MASTER.php?tab=<?=$tab?>"<?= $isModal ? ' onclick="parent.closeMasterModal();return false;"' : '' ?>>Cancel</a><button class="btn primary"><?=$edit?'Update':'Save'?> <?=$type==='store'?'Store':($type==='dc'?'Center DC':($type==='vehicle'?'Vehicle':'Record'))?></button></div>
    </form>
  </div>
</div>
<?php if(!$isModal) foot(); ?>
