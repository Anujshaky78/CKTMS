<?php
/*
 * "Create Trip" dialog (a load that is not in PRD Pending). Included by trips.php.
 * Posts to PLANNING.php (create_trip_form, see app/services/manual_trip.php); the CSRF token is added by _scripts.php.
 */
if (!can('plan')) return;
$fDcs = array_values(array_filter($dcs, fn($x) => !empty($x['active'])));
$fStores = array_values(array_filter($stores, fn($x) => !empty($x['active'])));
$fTypes = $d->query("SELECT type_name FROM tms_vehicle_types WHERE active ORDER BY lower(type_name)")->fetchAll(PDO::FETCH_COLUMN);
$fTpts = $d->query("SELECT id, COALESCE(NULLIF(name,''),transporter_name) n FROM tms_transporters WHERE active ORDER BY 2")->fetchAll(PDO::FETCH_ASSOC);
$fMax = (int)tms_trip_max_stores();
$fRoutes = [];
foreach ($d->query("SELECT r.id, r.route_code, r.route_name, r.dc_id FROM tms_routes r WHERE r.active ORDER BY r.route_code")->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $st = $d->prepare("SELECT s.store_code FROM tms_route_stops rs JOIN stores s ON s.id=rs.store_id WHERE rs.route_id=? ORDER BY rs.seq"); $st->execute([$r['id']]);
  $r['stores'] = $st->fetchAll(PDO::FETCH_COLUMN); $fRoutes[] = $r;
}
$dtl = function (string $id) use ($fStores) { echo '<datalist id="' . $id . '">'; foreach ($fStores as $s) echo '<option value="' . e($s['store_code']) . '" label="' . e($s['store_name']) . '"></option>'; echo '</datalist>'; };
$rowsHtml = function (string $qtyLabel) use ($fMax) { ob_start(); ?>
  <div class="fm-rows">
    <div class="fm-rh"><span>Destination store</span><span><?= e($qtyLabel) ?></span><span>Value (₹)</span><span></span></div>
    <?php for ($i = 0; $i < $fMax; $i++): ?>
    <div class="fm-row" <?= $i ? 'hidden' : '' ?>><input name="store[]" list="fmStores" placeholder="Store code" autocomplete="off" <?= $i ? '' : 'required' ?>><input name="pkt[]" type="number" min="1" placeholder="PKT" <?= $i ? '' : 'required' ?>><input name="value[]" type="number" min="1" step="0.01" placeholder="₹" <?= $i ? '' : 'required' ?>><button type="button" class="fm-x" title="Remove" onclick="fmDrop(this)" <?= $i ? '' : 'hidden' ?>>×</button></div>
    <?php endfor; ?>
    <button type="button" class="fm-add" onclick="fmAdd(this)">＋ Add store (max <?= $fMax ?>)</button>
  </div>
<?php return ob_get_clean(); };
$dcSel = function () use ($fDcs) { ob_start(); ?><select name="dc_id" required><option value="">Select an option</option><?php foreach ($fDcs as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['dc_code'] . ' · ' . $x['dc_name']) ?></option><?php endforeach; ?></select><?php return ob_get_clean(); };
$tptSel = function (string $extra = '') use ($fTpts) { ob_start(); ?><select name="transporter_id" <?= $extra ?>><option value="">Select an option</option><?php foreach ($fTpts as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['n']) ?></option><?php endforeach; ?></select><?php return ob_get_clean(); };
$vtSel = function () use ($fTypes) { ob_start(); ?><select name="vehicle_type"><option value="">Select an option</option><?php foreach ($fTypes as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select><?php return ob_get_clean(); };
?>
<style>
.fdlg{border:0;border-radius:12px;padding:0;width:min(860px,calc(100vw - 32px));max-height:calc(100vh - 40px);box-shadow:0 24px 70px rgba(15,23,42,.32)}
.fdlg::backdrop{background:rgba(15,23,42,.5)}.fdlg form{margin:0;display:flex;flex-direction:column;max-height:calc(100vh - 40px)}
.fdlg .fh{display:flex;justify-content:space-between;align-items:center;padding:16px 22px 6px}.fdlg .fh h3{margin:0;font-size:17px}.fdlg .fh button{border:0;background:none;font-size:22px;cursor:pointer;color:#667085}
.fdlg .fb{padding:6px 22px 4px;overflow:auto;display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}.fdlg .f{display:flex;flex-direction:column;gap:5px}.fdlg .f.w{grid-column:1/-1}
.fdlg label{font-size:13px;color:#101828}.fdlg label i{color:#d92d20;font-style:normal}.fdlg label small{color:#667085}
.fdlg .fb input:not([type=radio]):not([type=checkbox]),.fdlg .fb select,.fdlg .fb textarea{height:40px;border:1px solid var(--line);border-radius:8px;padding:0 12px;font-size:13.5px;font-family:inherit;width:100%;box-sizing:border-box;background:#fff}
.fdlg textarea{height:90px!important;padding:9px 12px!important;resize:vertical}
.fdlg .seg{display:grid;grid-template-columns:1fr 1fr;gap:10px}.fdlg .seg label{display:flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:8px;height:40px;padding:0 12px;cursor:pointer}
.fdlg .seg label:has(input:checked){border-color:var(--blue);background:#eff6ff}
.fm-rows{grid-column:1/-1}.fm-rh,.fm-row{display:grid;grid-template-columns:2fr 1fr 1fr 34px;gap:10px;align-items:center}.fm-rh{font-size:12px;color:#667085;padding:0 2px 6px;border-bottom:1px solid var(--line);margin-bottom:8px}
.fm-row[hidden],.fm-add[hidden],.fm-x[hidden]{display:none!important}
.fm-row{margin-bottom:8px}.fm-x{border:1px solid var(--line);background:#fff;border-radius:8px;height:34px;cursor:pointer}.fm-add{border:1px dashed var(--line);background:#fff;border-radius:8px;height:36px;padding:0 14px;cursor:pointer;color:#344054;font-size:13px}
.fdlg .ff{display:flex;gap:10px;padding:14px 22px 18px}.fdlg .ff .pri{background:var(--blue);color:#fff;border-color:var(--blue)}
.fdlg .ff button{height:40px;padding:0 20px;border:1px solid var(--line);background:#fff;border-radius:8px;font-size:14px;cursor:pointer}.fdlg .hint{font-size:12px;color:#667085}
@media(max-width:640px){.fdlg .fb{grid-template-columns:1fr}.fm-rh,.fm-row{grid-template-columns:1.6fr 1fr 1fr 30px}}
</style>
<?php $dtl('fmStores'); ?>
<?php
  $fVeh = $d->query("SELECT id, vehicle_number, vehicle_type, transporter_id FROM vehicles WHERE active IS TRUE AND id NOT IN (SELECT vehicle_id FROM trips WHERE vehicle_id IS NOT NULL AND status NOT IN ('Delivered','Cancelled')) ORDER BY vehicle_number")->fetchAll(PDO::FETCH_ASSOC);
  $fDrv = $d->query("SELECT id, driver_name, phone, tpt_name FROM drivers WHERE active IS TRUE AND id NOT IN (SELECT driver_id FROM trips WHERE driver_id IS NOT NULL AND status NOT IN ('Delivered','Cancelled')) ORDER BY driver_name")->fetchAll(PDO::FETCH_ASSOC); ?>
<dialog class="fdlg" id="dlgTrip"><form method="post"><input type="hidden" name="action" value="create_trip_form">
  <div class="fh"><h3>Create New Trip</h3><button type="button" onclick="this.closest('dialog').close()" aria-label="Close">×</button></div>
  <div class="fb">
    <div class="f"><label>Transporter</label><?= $tptSel('onchange="fmTpt(this)"') ?></div>
    <div class="f"><label>Driver</label><select name="driver_id"><option value="">Select an option</option><?php foreach ($fDrv as $x): ?><option value="<?= (int)$x['id'] ?>" data-tpt="<?= e(strtolower((string)$x['tpt_name'])) ?>"><?= e($x['driver_name'] . ($x['phone'] ? ' · ' . $x['phone'] : '')) ?></option><?php endforeach; ?></select></div>
    <div class="f"><label>Vehicle</label><select name="vehicle_id"><option value="">Select an option</option><?php foreach ($fVeh as $x): ?><option value="<?= (int)$x['id'] ?>" data-tid="<?= (int)$x['transporter_id'] ?>" data-vt="<?= e((string)$x['vehicle_type']) ?>"><?= e($x['vehicle_number'] . ($x['vehicle_type'] ? ' · ' . $x['vehicle_type'] : '')) ?></option><?php endforeach; ?></select><span class="hint">Only vehicles and drivers that are free are listed. Leave both empty to assign later with Accept.</span></div>
    <div class="f"><label>Start Date &amp; Time</label><input type="datetime-local" name="start_at" value="<?= e(date('Y-m-d\TH:i')) ?>"></div>
    <div class="f"><label>Source Location <i>*</i></label><?= $dcSel() ?></div>
    <div class="f"><label>Route <i>*</i></label><div class="seg"><label><input type="radio" name="rt" value="direct" checked onchange="fmMode(this)"> Direct <small>(Single)</small></label><label><input type="radio" name="rt" value="hops" onchange="fmMode(this)"> Hops <small>(Multiple)</small></label></div></div>
    <?= $rowsHtml('Pick / delivery qty (PKT)') ?>
    <div class="f w"><label>Comment</label><input name="notes" maxlength="500" placeholder="Optional"></div>
  </div>
  <div class="ff"><button class="pri">Submit</button><button type="reset" onclick="fmReset(this)">Reset</button></div></form></dialog>
<script>
function fmOpen(id){const d=document.getElementById(id); if(!d)return; d.querySelector('form').reset(); fmReset(d.querySelector('form')); d.showModal();}
function fmReq(f){f.querySelectorAll('.fm-row').forEach(r=>r.querySelectorAll('input').forEach(i=>{i.required=!r.hidden;}));}
function fmReset(el){const f=el.closest?el.closest('form'):el; setTimeout(()=>{f.querySelectorAll('.fm-row').forEach((r,i)=>{r.hidden=i>0;}); fmReq(f);const a=f.querySelector('.fm-add');if(a)a.hidden=false; f.querySelectorAll('option').forEach(o=>o.hidden=false); const m=f.querySelector('input[name=rt]'); if(m)fmMode(m,true);},0);}
function fmAdd(btn){const rows=[...btn.closest('form').querySelectorAll('.fm-row')],nx=rows.find(r=>r.hidden); if(!nx)return; nx.hidden=false;fmReq(btn.closest('form'));nx.querySelector('input').focus();nx.querySelector('.fm-x').hidden=false; if(!rows.some(r=>r.hidden))btn.hidden=true;}
function fmDrop(btn){const r=btn.closest('.fm-row');r.querySelectorAll('input').forEach(i=>i.value='');r.hidden=true;fmReq(r.closest('form'));const a=r.closest('form').querySelector('.fm-add');if(a)a.hidden=false;}
function fmMode(el,quiet){const f=el.closest('form'),one=f.querySelector('input[name=rt]:checked')?.value==='direct'; const a=f.querySelector('.fm-add'); if(one){f.querySelectorAll('.fm-row').forEach((r,i)=>{if(i>0){r.querySelectorAll('input').forEach(x=>x.value='');r.hidden=true;}}); if(a)a.hidden=true;} else if(a)a.hidden=false; fmReq(f);}
function fmRoute(sel){const o=sel.selectedOptions[0],f=sel.closest('form'); if(!o||!o.value)return; const st=JSON.parse(o.dataset.stores||'[]'); const rows=[...f.querySelectorAll('.fm-row')]; rows.forEach((r,i)=>{const on=i<st.length; r.hidden=!on&&i>0; r.querySelector('input[name="store[]"]').value=st[i]||'';}); fmReq(f); const dc=f.querySelector('select[name=dc_id]'); if(+o.dataset.dc)dc.value=o.dataset.dc;}
function fmTpt(sel){const f=sel.closest('form'),tid=sel.value,name=(sel.selectedOptions[0]?.textContent||'').trim().toLowerCase();
  f.querySelectorAll('select[name=vehicle_id] option[data-tid]').forEach(o=>o.hidden=!!tid&&!(+o.dataset.tid===+tid||+o.dataset.tid===0));
  f.querySelectorAll('select[name=driver_id] option[data-tpt]').forEach(o=>o.hidden=!!tid&&!(o.dataset.tpt===name||o.dataset.tpt===''||o.dataset.tpt==='tpt'));
  ['vehicle_id','driver_id'].forEach(n=>{const s=f.querySelector('select[name='+n+']'); if(s.selectedOptions[0]?.hidden)s.value='';});}
</script>
