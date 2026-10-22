<?php
declare(strict_types=1);
require_once __DIR__.'/../auth/auth.php';
require_once __DIR__.'/../includes/layout.php';

$d=db();
$error='';
$notice='';
$sessionKey='tms_store_bulk_rows';

$headers=['store_code','store_name','city','state','address','pincode','latitude','longitude','transit_days','dispatch_day_1','dispatch_day_2','active'];
$labels=['store_code'=>'Store Code','store_name'=>'Store Name','city'=>'City','state'=>'State','address'=>'Address','pincode'=>'PIN Code','latitude'=>'Latitude','longitude'=>'Longitude','transit_days'=>'Transit / SLA Days','dispatch_day_1'=>'Dispatch Day 1','dispatch_day_2'=>'Dispatch Day 2','active'=>'Active'];
$allowedDays=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

function bulk_norm_header(string $v): string {
    $v=strtolower(trim($v));
    $v=preg_replace('/[^a-z0-9]+/','_',$v) ?? '';
    $aliases=[
      'store'=>'store_code','store_no'=>'store_code','store_number'=>'store_code','store_code'=>'store_code',
      'store_name'=>'store_name','name'=>'store_name','city'=>'city','state'=>'state','address'=>'address',
      'pin'=>'pincode','pin_code'=>'pincode','pincode'=>'pincode','postal_code'=>'pincode',
      'lat'=>'latitude','latitude'=>'latitude','latitud'=>'latitude','latitute'=>'latitude','latit'=>'latitude',
      'lng'=>'longitude','lon'=>'longitude','longitude'=>'longitude','longitu'=>'longitude','longitud'=>'longitude','longit'=>'longitude',
      'transit'=>'transit_days','transit_sla'=>'transit_days','transit_sla_days'=>'transit_days','transit_days'=>'transit_days',
      'dispatch_day1'=>'dispatch_day_1','dispatch_day_1'=>'dispatch_day_1','dispatch_day_1'=>'dispatch_day_1',
      'dispatch_day2'=>'dispatch_day_2','dispatch_day_2'=>'dispatch_day_2',
      'active'=>'active','status'=>'active'
    ];
    return $aliases[$v] ?? $v;
}
function bulk_clean(string $v): string { return trim(preg_replace('/\x{FEFF}/u','',$v) ?? ''); }
function bulk_parse_csv(string $path): array {
    $fh=fopen($path,'rb'); if(!$fh) throw new Exception('CSV file could not be opened.');
    $first=fgets($fh); if($first===false){fclose($fh);return [];} 
    $enc='UTF-8';
    if(substr($first,0,3)==="\xEF\xBB\xBF") $first=substr($first,3);
    $delim=(substr_count($first,'\t')>substr_count($first,',')?'\t':(substr_count($first,';')>substr_count($first,',')?';':','));
    $head=str_getcsv(rtrim($first,"\r\n"),$delim);
    $map=[]; foreach($head as $i=>$h){$key=bulk_norm_header((string)$h);if($key!=='')$map[$i]=$key;}
    if(!in_array('store_code',$map,true)||!in_array('store_name',$map,true)) throw new Exception('Template must contain Store Code and Store Name columns.');
    $rows=[];$line=1;
    while(($data=fgetcsv($fh,0,$delim))!==false){$line++;$blank=true;foreach($data as $v){if(trim((string)$v)!==''){$blank=false;break;}}if($blank)continue;$row=['_line'=>$line];foreach($map as $i=>$key)$row[$key]=bulk_clean((string)($data[$i]??''));$rows[]=$row;if(count($rows)>5000)throw new Exception('Maximum 5,000 rows per import.');}
    fclose($fh); return $rows;
}
function xlsx_col_index(string $ref): int {preg_match('/^[A-Z]+/i',$ref,$m);$s=strtoupper($m[0]??'A');$n=0;for($i=0;$i<strlen($s);$i++)$n=$n*26+(ord($s[$i])-64);return $n-1;}
function bulk_parse_xlsx(string $path): array {
    if(!class_exists('ZipArchive')) throw new Exception('XLSX import requires PHP ZipArchive extension. CSV import is available without it.');
    $zip=new ZipArchive();if($zip->open($path)!==true)throw new Exception('XLSX file could not be opened.');
    $shared=[];$ss=$zip->getFromName('xl/sharedStrings.xml');
    if($ss!==false){$xml=simplexml_load_string($ss);if($xml!==false){foreach($xml->si as $si){$txt='';foreach($si->xpath('.//t') as $t)$txt.=(string)$t;$shared[]=$txt;}}}
    $sheetName='xl/worksheets/sheet1.xml';
    if($zip->getFromName($sheetName)===false){$rels=$zip->getFromName('xl/workbook.xml');if($rels!==false){$wb=simplexml_load_string($rels);$ns=$wb->getDocNamespaces(true);$wb->registerXPathNamespace('m',$ns['']??'http://schemas.openxmlformats.org/spreadsheetml/2006/main');$sheets=$wb->xpath('//m:sheets/m:sheet');$target=(string)($sheets[0]['{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id']??'');if($target){$rd=$zip->getFromName('xl/_rels/workbook.xml.rels');if($rd!==false){$rxml=simplexml_load_string($rd);foreach($rxml->Relationship as $rel){if((string)$rel['Id']===$target){$p=ltrim((string)$rel['Target'],'/');$sheetName=str_starts_with($p,'xl/')?$p:'xl/'.$p;break;}}}}}}
    $data=$zip->getFromName($sheetName);if($data===false){$zip->close();throw new Exception('First worksheet not found in XLSX.');}
    $xml=simplexml_load_string($data);if($xml===false){$zip->close();throw new Exception('Invalid XLSX worksheet.');}
    $rows=[];$rowNum=0;
    foreach($xml->sheetData->row as $r){$rowNum++;$vals=[];foreach($r->c as $c){$idx=xlsx_col_index((string)$c['r']);$type=(string)$c['t'];$v=(string)($c->v??'');if($type==='s')$v=$shared[(int)$v]??'';elseif($type==='inlineStr')$v=implode('',array_map('strval',$c->is->t??[]));$vals[$idx]=bulk_clean($v);}
      if($rowNum===1){$map=[];foreach($vals as $i=>$h){$key=bulk_norm_header($h);if($key!=='')$map[$i]=$key;}if(!in_array('store_code',$map,true)||!in_array('store_name',$map,true)){$zip->close();throw new Exception('Template must contain Store Code and Store Name columns.');}continue;}
      $blank=true;foreach($vals as $v){if($v!==''){$blank=false;break;}}if($blank)continue;$row=['_line'=>$rowNum];foreach($map as $i=>$key)$row[$key]=$vals[$i]??'';$rows[]=$row;if(count($rows)>5000){$zip->close();throw new Exception('Maximum 5,000 rows per import.');}}
    $zip->close();return $rows;
}
function bulk_parse_file(string $path,string $ext): array {return in_array($ext,['xlsx','xlsm'],true)?bulk_parse_xlsx($path):bulk_parse_csv($path);}
function bulk_existing(PDO $d,string $code): ?array {$q=$d->prepare('SELECT * FROM stores WHERE UPPER(store_code)=UPPER(?) LIMIT 1');$q->execute([$code]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;}
function bulk_days_value(PDO $d,string $a,string $b): string {
    $days=array_values(array_unique([$a,$b]));
    $valid=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    $days=array_values(array_intersect($valid,$days));
    if(count($days)!==2) throw new Exception('Dispatch Day 1 and Dispatch Day 2 must be two different valid days.');
    $q=$d->query("SELECT udt_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='stores' AND column_name='dispatch_days'");$type=$q->fetchColumn();
    if($type && str_starts_with((string)$type,'_')) return '{'.implode(',',$days).'}';
    return implode(',',$days);
}
function bulk_bool(string $v,bool $default): bool {if($v==='')return $default;$v=strtolower(trim($v));if(in_array($v,['yes','y','true','1','active'],true))return true;if(in_array($v,['no','n','false','0','inactive'],true))return false;throw new Exception('Active must be Yes/No.');}
function bulk_num(string $v,float $default,string $label): float {$v=trim($v);if($v==='')return $default;$v=str_replace(',', '.', $v);if(!is_numeric($v))throw new Exception($label.' must be numeric.');$n=(float)$v;if($label==='Latitude'&&($n<-90||$n>90))throw new Exception('Latitude must be between -90 and 90.');if($label==='Longitude'&&($n<-180||$n>180))throw new Exception('Longitude must be between -180 and 180.');return $n;}

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  try{
    $action=$_POST['action']??'';
    if($action==='upload_preview'){
      if(empty($_FILES['bulk_file']['tmp_name'])||$_FILES['bulk_file']['error']!==UPLOAD_ERR_OK)throw new Exception('Please select a valid CSV or XLSX file.');
      $name=(string)$_FILES['bulk_file']['name'];$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($ext,['csv','xlsx','xlsm'],true))throw new Exception('Only CSV or XLSX files are supported.');
      $rows=bulk_parse_file($_FILES['bulk_file']['tmp_name'],$ext);if(!$rows)throw new Exception('No data rows found.');
      $seen=[];$valid=0;$errors=[];$preview=[];
      foreach($rows as $i=>$r){$line=(int)$r['_line'];$code=strtoupper(trim((string)($r['store_code']??'')));$namev=trim((string)($r['store_name']??''));$errs=[];if($code==='')$errs[]='Store Code required';if($namev==='')$errs[]='Store Name required';if($code!==''&&isset($seen[$code]))$errs[]='Duplicate Store Code in file (line '.$seen[$code].')';if($code!=='')$seen[$code]=$line;
        if(($r['pincode']??'')!==''&&!preg_match('/^\d{6}$/',trim((string)$r['pincode'])))$errs[]='PIN must be 6 digits';
        foreach(['latitude','longitude','transit_days'] as $n)if(($r[$n]??'')!==''&&!is_numeric(str_replace(',','.',$r[$n])))$errs[]=$labels[$n].' must be numeric';
        $d1=ucfirst(strtolower(trim((string)($r['dispatch_day_1']??''))));$d2=ucfirst(strtolower(trim((string)($r['dispatch_day_2']??''))));if($d1!==''&&!in_array($d1,$allowedDays,true))$errs[]='Invalid Dispatch Day 1';if($d2!==''&&!in_array($d2,$allowedDays,true))$errs[]='Invalid Dispatch Day 2';if($d1!==''&&$d2!==''&&$d1===$d2)$errs[]='Dispatch days must be different';
        if(($r['active']??'')!==''){try{bulk_bool((string)$r['active'],true);}catch(Throwable $e){$errs[]=$e->getMessage();}}
        $existing=$code!==''?bulk_existing($d,$code):null;$mode=$existing?'UPDATE':'NEW';if(!$existing&&($d1===''||$d2===''))$errs[]='New store requires 2 dispatch days';
        if(!$errs)$valid++;else $errors[]=['line'=>$line,'code'=>$code,'errors'=>$errs];
        if(count($preview)<20)$preview[]=['line'=>$line,'code'=>$code,'name'=>$namev,'mode'=>$mode,'error'=>implode('; ',$errs)];
      }
      $_SESSION[$sessionKey]=['rows'=>$rows,'name'=>$name,'created'=>time()];
      $notice=count($rows).' rows loaded. '.$valid.' ready; '.count($errors).' need correction.';
    }elseif($action==='commit_bulk'){
      $payload=$_SESSION[$sessionKey]??null;if(!$payload||empty($payload['rows']))throw new Exception('Import session expired. Upload the file again.');
      if(time()-(int)$payload['created']>1800)unset($_SESSION[$sessionKey]);
      $rows=$payload['rows'];$d->beginTransaction();$updated=0;$inserted=0;$failed=[];$seen=[];
      $cols=$d->query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='stores'")->fetchAll(PDO::FETCH_COLUMN);
      foreach($rows as $r){$line=(int)$r['_line'];try{
        $code=strtoupper(trim((string)($r['store_code']??'')));$namev=trim((string)($r['store_name']??''));if($code===''||$namev==='')throw new Exception('Store Code and Store Name are required.');if(isset($seen[$code]))throw new Exception('Duplicate Store Code in file.');$seen[$code]=true;
        $old=bulk_existing($d,$code);$old=$old?:[];
        $city=array_key_exists('city',$r)&&$r['city']!==''?$r['city']:($old['city']??'');$state=array_key_exists('state',$r)&&$r['state']!==''?$r['state']:($old['state']??'');$address=array_key_exists('address',$r)&&$r['address']!==''?$r['address']:($old['address']??'');$pin=array_key_exists('pincode',$r)&&$r['pincode']!==''?$r['pincode']:($old['pincode']??'');if($pin!==''&&!preg_match('/^\d{6}$/',$pin))throw new Exception('PIN Code must be exactly 6 digits.');
        $lat=bulk_num((string)($r['latitude']??''),(float)($old['latitude']??0),'Latitude');$lon=bulk_num((string)($r['longitude']??''),(float)($old['longitude']??0),'Longitude');$transit=bulk_num((string)($r['transit_days']??''),(float)($old['transit_days']??1),'Transit / SLA Days');if($transit<=0)throw new Exception('Transit / SLA Days must be greater than 0.');
        $oldDays=trim((string)($old['dispatch_days']??''),'{}" ');$od=array_values(array_filter(array_map('trim',preg_split('/[,\s]+/',$oldDays)?:[])));$d1=ucfirst(strtolower(trim((string)($r['dispatch_day_1']??''))));$d2=ucfirst(strtolower(trim((string)($r['dispatch_day_2']??''))));if($d1===''&&isset($od[0]))$d1=ucfirst(strtolower($od[0]));if($d2===''&&isset($od[1]))$d2=ucfirst(strtolower($od[1]));$dispatch=bulk_days_value($d,$d1,$d2);
        $active=bulk_bool((string)($r['active']??''),!empty($old)?!empty($old['active']):true);
        if($old){$sql='UPDATE stores SET store_code=?,store_name=?,latitude=?,longitude=?,dispatch_days=?,transit_days=?,active=?';$vals=[$code,$namev,$lat,$lon,$dispatch,$transit,$active];foreach([['city',$city],['state',$state],['address',$address],['pincode',$pin]] as [$c,$v])if(in_array($c,$cols,true)){$sql.=','.$c.'=?';$vals[]=$v;}$sql.=' WHERE id=?';$vals[]=$old['id'];$d->prepare($sql)->execute($vals);$updated++;}
        else{$names=['store_code','store_name','latitude','longitude','dispatch_days','transit_days','active'];$vals=[$code,$namev,$lat,$lon,$dispatch,$transit,$active];foreach([['city',$city],['state',$state],['address',$address],['pincode',$pin]] as [$c,$v])if(in_array($c,$cols,true)){$names[]=$c;$vals[]=$v;}$d->prepare('INSERT INTO stores('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')')->execute($vals);$old=bulk_existing($d,$code);$inserted++;}
        $verify=bulk_existing($d,$code);if(!$verify || abs((float)$verify['latitude']-$lat)>0.0000001 || abs((float)$verify['longitude']-$lon)>0.0000001)throw new Exception('Latitude / Longitude could not be verified after save.');
      }catch(Throwable $e){$failed[]=['line'=>$line,'code'=>$code??'','error'=>$e->getMessage()];}
      }
      if($failed){$d->commit();$_SESSION[$sessionKey]=['rows'=>array_values(array_filter($rows,function($r)use($failed){foreach($failed as $f)if((int)$f['line']===(int)$r['_line'])return true;return false;})),'name'=>$payload['name'],'created'=>time()];$notice="Import completed: $updated updated, $inserted inserted, ".count($failed).' failed. Failed rows were kept for correction.';}
      else{$d->commit();unset($_SESSION[$sessionKey]);setflash("Bulk Store Import completed: $updated updated, $inserted inserted, 0 failed.");redirect('../pages/MASTER.php?tab=stores');}
    }elseif($action==='clear_bulk'){unset($_SESSION[$sessionKey]);redirect('../pages/MASTER_BULK.php');}
  }catch(Throwable $e){if($d->inTransaction())$d->rollBack();$error=$e->getMessage();}
}
if(isset($_GET['template'])){
  $template='Store Code,Store Name,City,State,Address,PIN Code,Latitude,Longitude,Transit / SLA Days,Dispatch Day 1,Dispatch Day 2,Active\nST001,Sample Store,Delhi,Delhi,Example Address,110001,28.6139,77.2090,1,Mon,Thu,Yes\n';
  header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition:attachment; filename=Store_Master_Bulk_Template.csv');echo $template;exit;
}
$payload=$_SESSION[$sessionKey]??null;$rows=$payload['rows']??[];$preview=[];$errors=[];
if($rows){$seen=[];foreach($rows as $r){$code=strtoupper(trim((string)($r['store_code']??'')));$namev=trim((string)($r['store_name']??''));$errs=[];if($code==='')$errs[]='Store Code required';if($namev==='')$errs[]='Store Name required';if($code!==''&&isset($seen[$code]))$errs[]='Duplicate Store Code in file (line '.$seen[$code].')';if($code!=='')$seen[$code]=(int)$r['_line'];if(($r['pincode']??'')!==''&&!preg_match('/^\d{6}$/',(string)$r['pincode']))$errs[]='PIN must be 6 digits';$d1=ucfirst(strtolower(trim((string)($r['dispatch_day_1']??''))));$d2=ucfirst(strtolower(trim((string)($r['dispatch_day_2']??''))));if($d1!==''&&!in_array($d1,$allowedDays,true))$errs[]='Invalid Dispatch Day 1';if($d2!==''&&!in_array($d2,$allowedDays,true))$errs[]='Invalid Dispatch Day 2';if($d1!==''&&$d2!==''&&$d1===$d2)$errs[]='Dispatch days must be different';$existing=$code!==''?bulk_existing($d,$code):null;if(!$existing&&($d1===''||$d2===''))$errs[]='New store requires 2 dispatch days';if(count($preview)<25)$preview[]=['line'=>$r['_line'],'code'=>$code,'name'=>$namev,'mode'=>$existing?'UPDATE':'NEW','error'=>implode('; ',$errs)];if($errs)$errors[]=$r;}}
head('Store Master — Bulk Import / Update');flash();if($error)echo '<div class="alert" style="background:#fee2e2;color:#991b1b;border-color:#fecaca">'.e($error).'</div>';if($notice)echo '<div class="alert">'.e($notice).'</div>';
?>
<div class="page-title"><div><h2>Store Master — Bulk Import / Update</h2><div class="note">Upload CSV/XLSX. Existing Store Code = update; new Store Code = insert. Blank optional cells keep existing values on update.</div></div><div><a class="btn light" href="../pages/MASTER.php?tab=stores">← Store Master</a></div></div>
<div class="bulk-grid">
 <div class="card bulk-upload"><div class="section-title">1. Upload file</div><div class="bulk-actions"><a class="btn light" href="../pages/MASTER_BULK.php?template=1">Download Template</a><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="upload_preview"><input type="file" name="bulk_file" accept=".csv,.xlsx,.xlsm" required><button class="btn primary" type="submit">Upload & Preview</button></form></div><div class="note">Maximum 5,000 rows. Required: Store Code, Store Name. New stores require exactly 2 dispatch days.</div></div>
 <div class="card bulk-rules"><div class="section-title">2. Update rules</div><ul><li>Store Code is the unique matching key.</li><li>Existing code is updated; new code is inserted.</li><li>Blank optional fields do not erase existing data.</li><li>PIN must be exactly 6 digits.</li><li>Dispatch Day 1 and 2 must be different.</li></ul></div>
</div>
<?php if($rows): $ok=count($rows)-count($errors); ?><div class="card"><div class="toolbar"><b>3. Validation Preview</b><span class="grow"></span><span class="badge <?=count($errors)?'b-amber':'b-green'?>"><?=count($rows)?> rows • <?=count($errors)?> validation errors</span></div><div class="tablewrap"><table class="table bulk-table"><tr><th>Line</th><th>Store Code</th><th>Store Name</th><th>Operation</th><th>Validation</th></tr><?php foreach($preview as $p):?><tr><td><?=e($p['line'])?></td><td><b><?=e($p['code'])?></b></td><td><?=e($p['name'])?></td><td><span class="badge <?=$p['mode']==='UPDATE'?'b-blue':'b-green'?>"><?=e($p['mode'])?></span></td><td><?=e($p['error']?:'Ready')?></td></tr><?php endforeach;?></table></div><?php if(count($rows)>25):?><div class="note" style="margin-top:7px">Showing first 25 rows in preview. All uploaded rows will be validated during commit.</div><?php endif;?><div class="sticky-actions" style="margin-top:10px;position:static;display:flex;gap:7px;justify-content:flex-end"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="clear_bulk"><button class="btn light" type="submit">Clear</button></form><form method="post" onsubmit="return confirm('Commit this Store Master bulk update? Existing matching Store Codes will be updated and new codes inserted.');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="commit_bulk"><button class="btn success" type="submit">Commit <?=count($rows)?> Rows</button></form></div></div><?php endif; ?>
<div class="card"><div class="section-title">Template columns</div><div class="template-columns"><?php foreach($headers as $h):?><span><?=e($labels[$h])?></span><?php endforeach;?></div></div>
<style>.bulk-grid{display:grid;grid-template-columns:1.4fr 1fr;gap:10px;margin-bottom:10px}.bulk-upload,.bulk-rules{min-height:120px}.bulk-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:10px 0}.bulk-actions form{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.bulk-actions input[type=file]{max-width:300px}.bulk-rules ul{margin:8px 0 0 18px;padding:0;color:#475569;font-size:11px;line-height:1.65}.bulk-table{min-width:680px}.template-columns{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.template-columns span{padding:5px 8px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:10px;color:#475569}@media(max-width:850px){.bulk-grid{grid-template-columns:1fr}}</style>
<?php
foot();
