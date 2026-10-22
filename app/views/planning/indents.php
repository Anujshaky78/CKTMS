<?php $__pills = function (array $rows, string $key, array $first, string $total) { $c = tms_count_by($rows, $key); $o = [[$total, count($rows), 'dark']];
  $tone = ['Delivered' => 'good', 'Cancelled' => 'bad', 'Breakdown' => 'bad', 'Accident' => 'bad', 'In Transit' => 'warn'];
  foreach ($first as $k) if (!empty($c[$k])) { $o[] = [$k === 'Gate Pass Created' ? 'Gate pass' : $k, $c[$k], $tone[$k] ?? '']; unset($c[$k]); }
  foreach ($c as $k => $n) if ($k !== '') $o[] = [$k, $n, $tone[$k] ?? '']; return $o; };
$__pk = array_sum(array_map(fn($x) => (int)($x['packet_count'] ?? 0), $indents));
echo tms_stats(array_merge($__pills($indents, 'status', ['Pending', 'Planned', 'Trip Created', 'Dispatched', 'Delivered', 'Cancelled'], 'indents'), [['PKT', $__pk]])); ?>


<div class="card tab-actions">
  <div class="modulebar">
    <div><div class="section-title">Indent Management</div><div class="note">Create, import and manage indents from this module.</div></div>
    <div class="context-toolbar"><a class="btn success" href="INDENT_ADD.php">+ Add Indent</a><button type="button" class="btn primary" onclick="toggleIndentForm('bulkBox')">⇧ Bulk Upload</button></div>
  </div>
  <style>.compact-indent-form{padding:4px 0}.compact-grid{grid-template-columns:1.3fr 1fr 1.2fr;gap:10px;margin-bottom:10px}.store-list{display:flex;flex-direction:column;gap:7px}.compact-store-row{display:grid;grid-template-columns:2.1fr .8fr 1fr 1fr 1fr auto;gap:8px;align-items:end;padding:9px;border:1px solid var(--border,#d9dee7);border-radius:8px;background:#fafbfc}.compact-store-row .fg{margin:0}.compact-store-row label{font-size:11px;margin-bottom:4px}.indent-actions{display:flex;align-items:center;gap:8px;margin-top:9px}.indent-actions .note{font-size:11px}.remove-store{height:36px;white-space:nowrap}@media(max-width:1000px){.compact-grid{grid-template-columns:1fr 1fr}.compact-store-row{grid-template-columns:1fr 1fr}.indent-actions{flex-wrap:wrap}}.assign-form{display:flex;flex-direction:column;gap:7px}.assign-form .fg{margin:0}.assign-form label{font-size:11px}.assign-form .select{width:100%}
.trip-create-card{border:1px solid #dbe4ef;box-shadow:0 8px 28px rgba(15,23,42,.06)}.selection-count{font-weight:900;font-size:11px;color:#2563eb}.trip-create-grid{display:grid;grid-template-columns:1.1fr .8fr 1.7fr;gap:10px;padding:4px 0 10px}.trip-create-rule{display:flex;flex-direction:column;justify-content:center;padding:9px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}.trip-create-rule b{font-size:10px;text-transform:uppercase;color:#334155}.trip-create-rule span{font-size:10px;color:#64748b;margin-top:3px}.manual-indent-table-wrap{max-height:330px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px}.manual-indent-table{margin:0}.manual-indent-table th{position:sticky;top:0;background:#f8fafc;z-index:2}.manual-indent-row[hidden]{display:none}.manual-indent-row:hover{background:#f8fbff}.trip-create-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;padding-top:10px}.row-note{font-size:9px;color:#94a3b8}@media(max-width:900px){.trip-create-grid{grid-template-columns:1fr 1fr}.trip-create-rule{grid-column:1/-1}.trip-create-footer{align-items:flex-start;flex-direction:column}}
</style>
<div id="manualBox" class="action-panel" style="display:none">
  <div class="section-caption">Manual Indent</div>
  <form method="post" class="compact-indent-form" onsubmit="return validateStoreIndent()">
    <input type="hidden" name="action" value="manual_indent_multi">
    <div class="formgrid compact-grid">
      <div class="fg"><label>Center DC</label><select class="select" name="source" required><option value="">Select Center DC</option><?php foreach($dcs as $dc):?><option value="<?=e($dc['dc_code'])?>"><?=e($dc['dc_code'])?></option><?php endforeach;?></select></div>
      <div class="fg"><label>Indent Date</label><input class="input" type="date" name="indent_date" value="<?=e(date('Y-m-d'))?>" required></div>
      <div class="fg"><label>Indent Base No.</label><input class="input" name="indent_no" placeholder="Auto if blank"></div>
    </div>
    <div id="storeRows" class="store-list">
      <div class="store-row compact-store-row">
        <div class="fg"><label>Destination Store</label><select class="select" name="destination[]" required><option value="">Select Store</option><?php foreach($stores as $st):?><option value="<?=e($st['store_code'])?>"><?=e($st['store_code'])?></option><?php endforeach;?></select></div>
        <div class="fg"><label>Packets</label><input class="input" type="number" min="1" name="packet_count[]" required></div>
        <div class="fg"><label>Value (₹)</label><input class="input" type="number" step="0.01" min="1" name="indent_value[]" required></div>
        <div class="fg"><label>Invoice</label><input class="input" name="invoice_no[]"></div>
        <div class="fg"><label>Seal</label><input class="input" name="seal_no[]"></div>
      </div>
    </div>
    <div class="indent-actions"><button type="button" class="btn light" onclick="addStoreRow()">+ Add Store</button><span class="note">Second/third store sirf zarurat par add karo.</span><span class="grow"></span><button class="btn success">Save Indent</button><button type="button" class="btn light" onclick="toggleIndentForm('manualBox')">Cancel</button></div>
  </form>
</div>
<div id="bulkBox" class="action-panel" style="display:none">
    <div class="section-caption">Bulk Indent Upload</div>
    <form method="post" enctype="multipart/form-data" class="toolbar"><input type="hidden" name="action" value="upload_indents"><select class="select" name="bulk_source"><option value="">Center DC (file column)</option><?php foreach($dcs as $dc):?><option value="<?=e($dc['dc_code'])?>"><?=e($dc['dc_code'])?></option><?php endforeach;?></select><input class="input" type="file" name="indent_file" accept=".csv,.xlsx,.xlsm" required><button class="btn primary">Upload Indents</button><a class="btn light" href="../PLANNING_INDENT_TEMPLATE.csv">Download Template</a></form>
    <div class="note">Required: destination/store code, packets/pkt, value. Center DC file ke source/source DC column se ya upar dropdown se diya ja sakta hai. CSV/XLSX/XLSM supported.</div>
  </div>
</div>
<div class="card"><div class="section-caption">Uploaded Indents</div><div class="tablewrap"><table class="table"><tr><th>Indent</th><th>Source DC</th><th>Destination</th><th>Packets</th><th>Value</th><th>Status</th><th>Trip</th><th>Action</th></tr><?php foreach($indents as $i):?><tr><td><b><?=e($i['indent_no'])?></b></td><td><b><?=e($i['dc_code']??'—')?></b></td><td><?=e($i['store_code'])?></td><td><?=number_format($i['packet_count'])?></td><td><?=money($i['indent_value'])?></td><td><span class="badge <?=in_array($i['status'],['Delivered'])?'b-green':(in_array($i['status'],['Trip Created'])?'b-blue':'b-amber')?>"><?=e($i['status'])?></span></td><td><?=e($i['trip_code']??'—')?></td><td><div class="row-action"><button type="button" class="dots-btn" onclick="toggleRowMenu('indentMenu<?=$i['id']?>',event)" aria-label="Indent actions">⋮</button><div class="row-menu" id="indentMenu<?=$i['id']?>"><?php if(!empty($i['trip_id'])):?><a href="PLANNING.php?tab=trips&view=<?=$i['trip_id']?>">Open Trip</a><?php else:?><span class="row-note">Create from Trip</span><?php endif; ?><button type="button" onclick='editIndent(<?=json_encode($i,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>);closeRowMenus()'>Edit</button><form method="post"><input type="hidden" name="action" value="delete_indent"><input type="hidden" name="indent_id" value="<?=$i['id']?>"><button type="submit" class="danger-text" onclick="return confirm('Selected indent delete karein?')">Delete</button></form></div></div></td></tr><?php endforeach;?></table></div></div>
