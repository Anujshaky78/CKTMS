<?php
require_once __DIR__.'/../auth/auth.php';
require_once __DIR__.'/../config/DB.php';
require_once __DIR__.'/../includes/layout.php';
require_login();
$d=db();
$stores=$d->query("SELECT id,store_code,store_name,active FROM stores WHERE active IS TRUE ORDER BY store_code")->fetchAll(PDO::FETCH_ASSOC);
$dcs=$d->query("SELECT id,dc_code,dc_name,active FROM tms_dc_master WHERE active IS TRUE ORDER BY dc_code")->fetchAll(PDO::FETCH_ASSOC);
head('Add Indent');
?>
<style>
.indent-page{max-width:1180px;margin:0 auto}.indent-card{padding:18px}.indent-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.indent-header h2{margin:0;font-size:18px}.indent-header .note{margin-top:3px}.base-grid{display:grid;grid-template-columns:1.3fr 1fr 1.2fr;gap:10px;margin-bottom:12px}.store-title{display:flex;align-items:center;justify-content:space-between;margin:4px 0 7px}.store-row{display:grid;grid-template-columns:minmax(260px,2fr) 110px 150px 150px 150px 78px;gap:8px;align-items:end;padding:10px;background:#f8fafc;border:1px solid #dbe3ec;border-radius:8px}.store-row .fg{margin:0}.store-row label{font-size:10px}.store-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb}.store-actions-right{display:flex;gap:8px}.store-row+.store-row{margin-top:7px}.remove-store{height:35px}@media(max-width:900px){.base-grid,.store-row{grid-template-columns:1fr 1fr}.store-row .destination{grid-column:1/-1}}
</style>
<div class="indent-page">
  <div class="page-title">
    <div><h2>Add Indent</h2><div class="note">Standard indent entry • one store by default • add another store only when required.</div></div>
    <a class="btn light" href="PLANNING.php?tab=indents">← Back</a>
  </div>
  <div class="card indent-card entry-card">
    <form method="post" action="PLANNING.php" id="indentForm">
      <input type="hidden" name="action" value="manual_indent_multi">
      <div class="section-caption">Indent Header</div>
      <div class="base-grid">
        <div class="fg"><label>Center DC</label><select class="select" name="source" required><option value="">Select Center DC</option><?php foreach($dcs as $dc): ?><option value="<?=e($dc['dc_code'])?>"><?=e($dc['dc_code'])?> — <?=e($dc['dc_name'])?></option><?php endforeach; ?></select></div>
        <div class="fg"><label>Indent Date</label><input class="input" type="date" name="indent_date" value="<?=e(date('Y-m-d'))?>" required></div>
        <div class="fg"><label>Indent No.</label><input class="input" name="indent_no" placeholder="Auto generate"></div>
      </div>
      <div class="store-title"><div class="section-caption" style="margin:0">Destination / Load Details</div><span class="note">Max 3 stores • ₹25L / 325 pkt combined</span></div>
      <div id="storeRows">
        <div class="store-row">
          <div class="fg destination"><label>Destination Store</label><select class="select" name="destination[]" required><option value="">Select Store</option><?php foreach($stores as $st): ?><option value="<?=e($st['store_code'])?>"><?=e($st['store_code'])?> — <?=e($st['store_name'])?></option><?php endforeach; ?></select></div>
          <div class="fg"><label>Packets</label><input class="input" type="number" min="1" name="packet_count[]" required></div>
          <div class="fg"><label>Value (₹)</label><input class="input" type="number" min="1" step="0.01" name="indent_value[]" required></div>
          <div class="fg"><label>Invoice No.</label><input class="input" name="invoice_no[]"></div>
          <div class="fg"><label>Seal No.</label><input class="input" name="seal_no[]"></div>
          <button type="button" class="btn light remove-store" style="display:none" onclick="removeStore(this)">Remove</button>
        </div>
      </div>
      <div class="store-actions">
        <button type="button" class="btn light" onclick="addStore()">+ Add Store</button>
        <div class="store-actions-right"><a class="btn light" href="PLANNING.php?tab=indents">Cancel</a><button class="btn success">Save Indent & Create Trip</button></div>
      </div>
    </form>
  </div>
</div>
<script>
function addStore(){const box=document.getElementById('storeRows'),rows=box.querySelectorAll('.store-row');if(rows.length>=3){alert('Maximum 3 stores allowed.');return;}const r=rows[0].cloneNode(true);r.querySelectorAll('input').forEach(x=>x.value='');r.querySelectorAll('select').forEach(x=>x.selectedIndex=0);r.querySelector('.remove-store').style.display='inline-block';box.appendChild(r);}
function removeStore(btn){const rows=document.querySelectorAll('#storeRows .store-row');if(rows.length>1)btn.closest('.store-row').remove();}
</script>
<?php foot(); ?>
