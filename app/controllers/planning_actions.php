<?php
// Planning POST actions: indents, trips, gate pass, status, PRD sync.
// Included from pages/PLANNING.php; shares its variables ($d, $stores, $dcs, $msg, $err, $tab, …).
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf(); // every state-changing planning form carries the token (injected by layout.php)
  $action=$_POST['action']??'';
  if($action==='import_prd_csv'){
    $err='PRD Pending ab sirf Oracle se aata hai (CHALLAN_STATUS = Pending). CSV import band hai, "Sync Oracle" use karein.';
  } elseif($action==='sync_prd_oracle'){
    require_once TMS_ROOT.'/app/services/oracle_sync.php';
    $ora=null;
    try{
      $ocfg=tms_oracle_cfg(); $ora=tms_oracle_connect($ocfg); $parts=[];
      if(!empty($ocfg['sync_stores_with_prd'])){
        try{ $ss=tms_store_sync_from_oracle($d,$ora); $parts[]="Store Master: {$ss['inserted']} new, {$ss['updated']} updated"; }
        catch(Throwable $se){ $parts[]='Store Master sync skipped: '.$se->getMessage(); }
      }
      $ps=tms_prd_sync_from_oracle($d,$ora);
      $parts[]="PRD Pending: {$ps['challans']} pending challan(s) loaded".($ps['removed']?", {$ps['removed']} no-longer-pending removed":'').' (CHALLAN_STATUS = '.$ps['status'].($ps['days']?", last {$ps['days']} days":', all dates').')';
      @oci_close($ora); $ora=null;
      tms_sync_log($d,'prd',true,implode(' · ',$parts),(int)$ps['challans']);
      setflash('Oracle sync complete. '.implode(' · ',$parts).'.'); redirect('PLANNING.php?tab=prd_pending');
    }catch(Throwable $x){ if($d->inTransaction())$d->rollBack(); tms_sync_log($d,'prd',false,$x->getMessage()); $err='Oracle sync failed: '.$x->getMessage(); }
    finally{ if($ora) @oci_close($ora); }
  } elseif($action==='create_prd_trip'){
    $err='Please use “Review plan” on PRD Pending to create trips.';
  }
  // ---------- RECORD EDIT / DELETE ----------
  if($action==='edit_indent'){
    try{
      $id=(int)$_POST['indent_id'];
      $q=$d->prepare("SELECT * FROM tms_indents WHERE id=?");$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);if(!$old)throw new Exception('Indent not found.');
      if(in_array($old['status'],['Delivered','In Transit'],true))throw new Exception('Delivered/In Transit indent cannot be modified.');
      $dest=strtolower(trim($_POST['destination']??''));$src=strtolower(trim($_POST['source']??''));$pkt=(int)($_POST['packet_count']??0);$val=(float)str_replace([',','₹',' '],'',($_POST['indent_value']??0));
      if(!isset($dcByCode[$src]))throw new Exception('Valid Center DC select karo.');if(!isset($storeByCode[$dest]))throw new Exception('Valid destination store select karo.');if($pkt<=0||$val<=0)throw new Exception('Packet aur value required hai.');
      $tripId=(int)($old['trip_id']??0);$d->beginTransaction();
      $d->prepare("UPDATE tms_indents SET indent_date=?,source_dc_id=?,destination_store_id=?,packet_count=?,indent_value=?,invoice_no=?,seal_no=? WHERE id=?")->execute([$_POST['indent_date']??$old['indent_date'],(int)$dcByCode[$src]['id'],(int)$storeByCode[$dest]['id'],$pkt,$val,trim($_POST['invoice_no']??'')?:null,trim($_POST['seal_no']??'')?:null,$id]);
      if($tripId){
        $d->prepare("UPDATE tms_trip_stops SET store_id=?,packet_count=?,stop_value=? WHERE trip_id=? AND id=(SELECT id FROM tms_trip_stops WHERE trip_id=? AND store_id=? ORDER BY sequence_no LIMIT 1)")->execute([(int)$storeByCode[$dest]['id'],$pkt,$val,$tripId,$tripId,(int)$old['destination_store_id']]);
        $d->prepare("UPDATE trips SET planned_date=?,source_dc_id=?,total_boxes=(SELECT COALESCE(SUM(packet_count),0) FROM tms_trip_stops WHERE trip_id=?),total_value=(SELECT COALESCE(SUM(stop_value),0) FROM tms_trip_stops WHERE trip_id=?) WHERE id=? AND status='Planned'")->execute([$_POST['indent_date']??$old['indent_date'],(int)$dcByCode[$src]['id'],$tripId,$tripId,$tripId]);
      }
      $d->commit();setflash('Indent '.$old['indent_no'].' updated successfully.');redirect('PLANNING.php?tab=indents');
    }catch(Throwable $x){if($d->inTransaction())$d->rollBack();$err=$x->getMessage();}
  } elseif($action==='delete_indent'){
    try{$id=(int)$_POST['indent_id'];$q=$d->prepare("SELECT * FROM tms_indents WHERE id=?");$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new Exception('Indent not found.');if(in_array($r['status'],['Delivered','In Transit'],true))throw new Exception('Delivered/In Transit indent cannot be deleted.');$d->beginTransaction();if(!empty($r['trip_id'])){$tid=(int)$r['trip_id'];$d->prepare("DELETE FROM tms_trip_stops WHERE trip_id=? AND store_id=?")->execute([$tid,(int)$r['destination_store_id']]);$d->prepare("UPDATE trips SET total_boxes=(SELECT COALESCE(SUM(packet_count),0) FROM tms_trip_stops WHERE trip_id=?),total_value=(SELECT COALESCE(SUM(stop_value),0) FROM tms_trip_stops WHERE trip_id=?) WHERE id=? AND status='Planned'")->execute([$tid,$tid,$tid]);$left=$d->prepare("SELECT COUNT(*) FROM tms_trip_stops WHERE trip_id=?");$left->execute([$tid]);if((int)$left->fetchColumn()===0){$d->prepare("DELETE FROM tms_trip_status_history WHERE trip_id=?")->execute([$tid]);$d->prepare("DELETE FROM trips WHERE id=? AND status='Planned'")->execute([$tid]);}}$d->prepare("DELETE FROM tms_indents WHERE id=?")->execute([$id]);$d->commit();setflash('Indent deleted successfully.');redirect('PLANNING.php?tab=indents');}catch(Throwable $x){if($d->inTransaction())$d->rollBack();$err=$x->getMessage();}
  } elseif($action==='edit_trip'){
    try{
      $id=(int)$_POST['trip_id'];$q=$d->prepare("SELECT * FROM trips WHERE id=?");$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new Exception('Trip not found.');
      if(in_array($r['status'],['In Transit','Delivered','Gate Pass Created'],true))throw new Exception('Dispatched/Gate Pass/Delivered trip cannot be modified here.');
      $date=$_POST['planned_date']??$r['planned_date'];$tpt=trim($_POST['tpt_name']??'');$vehicle=(int)($_POST['vehicle_id']??0);$driver=(int)($_POST['driver_id']??0);
      $stopIds=array_values(array_filter(array_map('intval',$_POST['stop_id']??[])));$storeIds=array_values(array_map('intval',$_POST['store_id']??[]));$pkts=$_POST['stop_packets']??[];$vals=$_POST['stop_values']??[];$indentIds=array_values(array_map('intval',$_POST['indent_id']??[]));
      if(count($storeIds)<1||count($storeIds)>tms_trip_max_stores())throw new Exception('Trip me minimum 1 aur maximum '.tms_trip_max_stores().' stores allowed.');
      if(count($storeIds)!==count(array_unique($storeIds)))throw new Exception('Same store ek trip me repeat nahi kar sakte.');
      $storeQ=$d->prepare("SELECT id,store_code,store_name,latitude,longitude FROM stores WHERE id IN (".implode(',',array_fill(0,count($storeIds),'?')).")");$storeQ->execute($storeIds);$storeMap=[];foreach($storeQ->fetchAll(PDO::FETCH_ASSOC) as $sr)$storeMap[(int)$sr['id']]=$sr;
      if(count($storeMap)!==count($storeIds))throw new Exception('One or more selected stores are invalid.');
      $dcQ=$d->prepare("SELECT id,dc_code,latitude,longitude FROM tms_dc_master WHERE id=?");$dcQ->execute([(int)$r['source_dc_id']]);$dcRow=$dcQ->fetch(PDO::FETCH_ASSOC)?:[];
      foreach($storeIds as $sid){ if(empty($storeMap[$sid]['latitude'])&&empty($storeMap[$sid]['longitude'])) throw new Exception('Store '.$storeMap[$sid]['store_code'].' ke latitude/longitude missing hain. Master me coordinates add karo.'); }
      $d->beginTransaction();
      // Move all existing stop sequence numbers out of the unique-key range before re-numbering.
      // This prevents (trip_id, sequence_no) collisions when stores are reordered/added/removed.
      $d->prepare("UPDATE tms_trip_stops SET sequence_no=1000000+id WHERE trip_id=?")->execute([$id]);
      $d->prepare("UPDATE trips SET planned_date=?,dispatch_date=?,tpt_name=?,vehicle_id=?,driver_id=? WHERE id=?")->execute([$date,$date,$tpt?:null,$vehicle?:null,$driver?:null,$id]);
      $oldQ=$d->prepare("SELECT ts.id,ts.store_id,ts.packet_count,ts.stop_value FROM tms_trip_stops ts WHERE ts.trip_id=?");$oldQ->execute([$id]);$oldStops=$oldQ->fetchAll(PDO::FETCH_ASSOC);$oldByStore=[];foreach($oldStops as $os)$oldByStore[(int)$os['store_id']] = $os;
      $keep=[];
      foreach($storeIds as $k=>$sid){
        $pkt=(int)($pkts[$k]??0);$val=(float)str_replace([',','₹',' '],'',($vals[$k]??0));if($pkt<=0||$val<=0)throw new Exception('Every trip store needs valid packet and value.');
        $iid=(int)($indentIds[$k]??0);
        if(isset($oldByStore[$sid])){
          $stopId=(int)$oldByStore[$sid]['id'];$keep[]=$stopId;$d->prepare("UPDATE tms_trip_stops SET sequence_no=?,packet_count=?,stop_value=? WHERE id=? AND trip_id=?")->execute([$k+1,$pkt,$val,$stopId,$id]);
          if($iid>0){
            $iq=$d->prepare("SELECT id FROM tms_indents WHERE id=? AND destination_store_id=? AND source_dc_id=?");$iq->execute([$iid,$sid,(int)$r['source_dc_id']]);$matched=(int)($iq->fetchColumn()?:0);
            if(!$matched)throw new Exception('Selected Indent does not belong to this Trip source/store.');
            $d->prepare("UPDATE tms_indents SET trip_id=?,status='Trip Created',packet_count=?,indent_value=?,indent_date=? WHERE id=?")->execute([$id,$pkt,$val,$date,$matched]);
          } else {
            $d->prepare("UPDATE tms_indents SET trip_id=?,status='Trip Created',packet_count=?,indent_value=?,indent_date=? WHERE trip_id=? AND destination_store_id=?")->execute([$id,$pkt,$val,$date,$id,$sid]);
          }
        } else {
          if($iid<=0) throw new Exception('Trip me new store add karne ke liye pehle se created Planned Indent select karo.');
          $iq=$d->prepare("SELECT * FROM tms_indents WHERE id=? AND status='Planned' AND trip_id IS NULL FOR UPDATE");$iq->execute([$iid]);$in=$iq->fetch(PDO::FETCH_ASSOC);
          if(!$in||((int)$in['destination_store_id']!==$sid)||((int)($in['source_dc_id']??0)!==(int)($r['source_dc_id']??0)))throw new Exception('Selected Planned Indent / Store / Center DC mismatch.');
          $pkt=(int)$in['packet_count'];$val=(float)$in['indent_value'];
          $insStop=$d->prepare("INSERT INTO tms_trip_stops(trip_id,sequence_no,store_id,packet_count,stop_value,status,expected_delivery_at) VALUES(?,?,?,?,?,'Planned',NULL) RETURNING id");
          $insStop->execute([$id,$k+1,$sid,$pkt,$val]);
          $keep[]=(int)$insStop->fetchColumn();
          $d->prepare("UPDATE tms_indents SET trip_id=?,status='Trip Created' WHERE id=?")->execute([$id,$iid]);
        }
      }
      $keepSql=$keep?implode(',',array_fill(0,count($keep),'?')):'NULL';
      $params=$keep;$params[]=$id;
      $rel=$d->prepare("SELECT ts.id,ts.store_id FROM tms_trip_stops ts WHERE ts.trip_id=? AND ts.id NOT IN ($keepSql)");
      if($keep){$rel=$d->prepare("SELECT ts.id,ts.store_id FROM tms_trip_stops ts WHERE ts.trip_id=? AND ts.id NOT IN ($keepSql)");$rel->execute(array_merge([$id],$keep));}else{$rel=$d->prepare("SELECT ts.id,ts.store_id FROM tms_trip_stops ts WHERE ts.trip_id=?");$rel->execute([$id]);}
      foreach($rel->fetchAll(PDO::FETCH_ASSOC) as $rm){$d->prepare("UPDATE tms_indents SET trip_id=NULL,status='Planned' WHERE trip_id=? AND destination_store_id=?")->execute([$id,(int)$rm['store_id']]);$d->prepare("DELETE FROM tms_trip_stops WHERE id=?")->execute([(int)$rm['id']]);}
      $tq=$d->prepare("SELECT COUNT(*),COALESCE(SUM(packet_count),0) FROM tms_trip_stops WHERE trip_id=?");$tq->execute([$id]);[$stopCnt,$stopPkt]=$tq->fetch(PDO::FETCH_NUM);
      tms_trip_check((int)$stopCnt,(int)$stopPkt,'Trip '.$r['trip_code']);
      $d->prepare("UPDATE trips SET total_boxes=(SELECT COALESCE(SUM(packet_count),0) FROM tms_trip_stops WHERE trip_id=?),total_value=(SELECT COALESCE(SUM(stop_value),0) FROM tms_trip_stops WHERE trip_id=?) WHERE id=?")->execute([$id,$id,$id]);
      $d->commit();setflash('Trip '.$r['trip_code'].' modified successfully — stores added/removed and sequence updated.');redirect('PLANNING.php?tab=trips');
    }catch(Throwable $x){if($d->inTransaction())$d->rollBack();$err=$x->getMessage();}
  } elseif($action==='delete_trip'){
    try{$id=(int)$_POST['trip_id'];$q=$d->prepare("SELECT * FROM trips WHERE id=?");$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new Exception('Trip not found.');if(!in_array($r['status'],['Planned','Assigned','Loaded'],true))throw new Exception('Only Planned/Assigned/Loaded trip can be deleted.');$d->beginTransaction();if(!empty($r['vehicle_id']))$d->prepare("UPDATE vehicles SET status='Available' WHERE id=? AND status='Assigned'")->execute([(int)$r['vehicle_id']]);if(!empty($r['driver_id']))$d->prepare("UPDATE drivers SET status='Available' WHERE id=? AND status='Assigned'")->execute([(int)$r['driver_id']]);$d->prepare("UPDATE tms_indents SET trip_id=NULL,status='Planned' WHERE trip_id=?")->execute([$id]);$d->prepare("DELETE FROM tms_trip_status_history WHERE trip_id=?")->execute([$id]);$d->prepare("DELETE FROM tms_trip_stops WHERE trip_id=?")->execute([$id]);$d->prepare("DELETE FROM trips WHERE id=?")->execute([$id]);$d->commit();setflash('Trip deleted and its indents released back to Planned.');redirect('PLANNING.php?tab=trips');}catch(Throwable $x){if($d->inTransaction())$d->rollBack();$err=$x->getMessage();}
  } elseif($action==='edit_gatepass'){
    try{$id=(int)$_POST['gatepass_id'];$q=$d->prepare("SELECT g.*,t.status trip_status FROM tms_gate_passes g JOIN trips t ON t.id=g.trip_id WHERE g.id=?");$q->execute([$id]);$g=$q->fetch(PDO::FETCH_ASSOC);if(!$g)throw new Exception('Gate Pass not found.');if(in_array($g['trip_status'],['In Transit','Delivered'],true))throw new Exception('Dispatched/Delivered Gate Pass cannot be modified.');$d->prepare("UPDATE tms_gate_passes SET invoice_no=?,seal_no=?,remarks=? WHERE id=?")->execute([trim($_POST['invoice_no']??''),trim($_POST['seal_no']??''),trim($_POST['remarks']??''),$id]);setflash('Gate Pass updated successfully.');redirect('PLANNING.php?tab=gatepasses&trip_id='.(int)$g['trip_id']);}catch(Throwable $x){$err=$x->getMessage();}
  } elseif($action==='delete_gatepass'){
    try{$id=(int)$_POST['gatepass_id'];$q=$d->prepare("SELECT g.*,t.status trip_status,t.id trip_id FROM tms_gate_passes g JOIN trips t ON t.id=g.trip_id WHERE g.id=?");$q->execute([$id]);$g=$q->fetch(PDO::FETCH_ASSOC);if(!$g)throw new Exception('Gate Pass not found.');if(!in_array($g['trip_status'],['Gate Pass Created'],true))throw new Exception('Only Gate Pass Created record can be deleted.');$d->beginTransaction();$d->prepare("DELETE FROM tms_gate_passes WHERE id=?")->execute([$id]);$d->prepare("UPDATE trips SET status='Loaded' WHERE id=? AND status='Gate Pass Created'")->execute([(int)$g['trip_id']]);$d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)")->execute([(int)$g['trip_id'],'Loaded','Gate Pass deleted / returned to Loaded',$_SESSION['user_id']??null]);$d->commit();setflash('Gate Pass deleted; trip returned to Loaded.');redirect('PLANNING.php?tab=gatepasses&trip_id='.(int)$g['trip_id']);}catch(Throwable $x){if($d->inTransaction())$d->rollBack();$err=$x->getMessage();}
  } elseif($action==='quick_load_gatepass'){
    try{$tripId=(int)$_POST['trip_id'];$q=$d->prepare("SELECT * FROM trips WHERE id=?");$q->execute([$tripId]);$tr=$q->fetch(PDO::FETCH_ASSOC);if(!$tr)throw new Exception('Trip not found.');if(!in_array($tr['status'],['Assigned','Loaded'],true))throw new Exception('Trip must be Assigned before Gate Pass.');$lp=(int)($tr['loaded_packets']?:$tr['total_boxes']);$lv=(float)($tr['loaded_value']?:$tr['total_value']);if($lp<=0)throw new Exception('Trip has no packets to load.');$d->beginTransaction();$d->prepare("UPDATE trips SET status='Loaded',loaded_packets=?,loaded_value=? WHERE id=?")->execute([$lp,$lv,$tripId]);$gp='GP-'.date('ymd').'-'.str_pad((string)$tripId,5,'0',STR_PAD_LEFT);$exists=$d->prepare("SELECT id FROM tms_gate_passes WHERE trip_id=?");$exists->execute([$tripId]);if($exists->fetchColumn())throw new Exception('Gate Pass already exists.');$d->prepare("INSERT INTO tms_gate_passes(gate_pass_no,trip_id,invoice_no,seal_no,packet_count,packet_value,remarks,created_by) VALUES(?,?,?,?,?,?,?,?)")->execute([$gp,$tripId,trim($_POST['invoice_no']??''),trim($_POST['seal_no']??''),$lp,$lv,trim($_POST['remarks']??''),$_SESSION['user_id']??null]);$d->prepare("UPDATE trips SET status='Gate Pass Created' WHERE id=?")->execute([$tripId]);$d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)")->execute([$tripId,'Gate Pass Created','Quick Load + Gate Pass',$_SESSION['user_id']??null]);$d->commit();setflash('Gate Pass '.$gp.' created successfully.');redirect('PLANNING.php?tab=gatepasses&trip_id='.$tripId);}catch(Throwable $x){if($d->inTransaction())$d->rollBack();$err=$x->getMessage();}
  }
  if(isset($err) && $err!=='') { /* continue only for a failed record action */ }
  if(in_array($action,['edit_indent','delete_indent','edit_trip','delete_trip','quick_load_gatepass'],true)){ /* handled above */ }
  else {

  try{
    if($action==='upload_indents'){
      if(empty($_FILES['indent_file']['tmp_name']) || $_FILES['indent_file']['error']!==UPLOAD_ERR_OK) throw new Exception('Bulk indent file select karo.');
      $name=(string)$_FILES['indent_file']['name']; $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
      if(!in_array($ext,['csv','xlsx','xlsm'],true)) throw new Exception('Bulk Indent me CSV, XLSX ya XLSM file upload karo.');
      $defaultSrc=strtolower(trim((string)($_POST['bulk_source']??'')));
      if($defaultSrc!=='' && !isset($dcByCode[$defaultSrc])) throw new Exception('Valid Center DC select karo.');
      $rows=[];
      $norm=function($x){$x=str_replace("\xC2\xA0",' ',(string)$x); $x=preg_replace('/^\xEF\xBB\xBF/','',$x); $x=strtolower(trim($x)); $x=preg_replace('/[^a-z0-9]+/','_',$x); return trim($x,'_');};
      $aliases=['source'=>['source','source_code','from','origin','center_dc','center_dc_code','center_dc_name','centerdc','center_dc_code','dc','dc_code','dc_name','source_dc','source_dc_code','source_dc_name','source_dc_code_name'],'destination'=>['destination','destination_code','store','store_code','store_name','to','destination_store','destination_store_code','destination_store_name','destinationstore'],'pkt'=>['pkt','p_k_t','packets','packet','box','boxes','packet_count','packet_qty','qty','quantity'],'value'=>['value','indent_value','order_value','amount','invoice_value','indent_amount'],'indent_no'=>['indent_no','indent_number','reference','ref_no'],'invoice_no'=>['invoice_no','invoice'],'seal_no'=>['seal_no','seal']];
      $rows=[];
      if($ext==='csv'){
        $fh=fopen($_FILES['indent_file']['tmp_name'],'rb'); if(!$fh) throw new Exception('CSV open nahi ho saki.');
        $first=fgets($fh); if($first===false) throw new Exception('CSV empty hai.'); if(substr($first,0,3)==="\xEF\xBB\xBF")$first=substr($first,3);
        $tabCount=substr_count($first,"\t"); $semiCount=substr_count($first,';'); $commaCount=substr_count($first,',');
        $delim=$tabCount>max($semiCount,$commaCount)?"\t":($semiCount>$commaCount?';':',');
        $head=str_getcsv(rtrim($first,"\r\n"),$delim); $h=[]; foreach($head as $i=>$v)$h[$norm($v)]=$i;
        $idx=[]; foreach($aliases as $k=>$arr){foreach($arr as $a){if(array_key_exists($a,$h)){$idx[$k]=$h[$a];break;}}}
        foreach(['destination','pkt','value'] as $r)if(!isset($idx[$r]))throw new Exception('Bulk CSV me '.$r.' column nahi mila. Found: '.implode(', ',array_keys($h)));
        $line=1; while(($row=fgetcsv($fh,0,$delim))!==false){
          $line++; $blank=true; foreach($row as $v){if(trim((string)$v)!==''){$blank=false;break;}} if($blank)continue;
          $r=['_line'=>$line]; foreach($idx as $k=>$i)$r[$k]=trim((string)($row[$i]??'')); $rows[]=$r;
          if(count($rows)>5000)throw new Exception('Maximum 5,000 indent rows.');
        } fclose($fh);
      }else{
        if(!class_exists('ZipArchive'))throw new Exception('XLSX import ke liye PHP ZipArchive extension enable karo. CSV bhi supported hai.');
        $zip=new ZipArchive(); if($zip->open($_FILES['indent_file']['tmp_name'])!==true)throw new Exception('XLSX open nahi ho saki.');
        $shared=[]; $ss=$zip->getFromName('xl/sharedStrings.xml');
        if($ss!==false){$sx=simplexml_load_string($ss); if($sx!==false){foreach($sx->si as $si){$txt=''; foreach($si->xpath('.//t') as $tx)$txt.=(string)$tx; $shared[]=$txt;}}}
        // Find the workbook's first worksheet instead of assuming sheet1.xml.
        $sheetPath=''; $wb=$zip->getFromName('xl/workbook.xml'); $rels=$zip->getFromName('xl/_rels/workbook.xml.rels');
        if($wb!==false && $rels!==false){
          $wx=simplexml_load_string($wb); $rx=simplexml_load_string($rels);
          if($wx!==false && $rx!==false){
            $ns=$wx->getNameSpaces(true); $main=$wx->children($ns['']); $firstSheet=isset($main->sheets->sheet[0])?$main->sheets->sheet[0]:null; $rid=$firstSheet?(string)$firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id:'';
            if($rid){foreach($rx->Relationship as $rel){if((string)$rel['Id']===$rid){$target=(string)$rel['Target']; $sheetPath='xl/'.ltrim($target,'/'); break;}}}
          }
        }
        if($sheetPath==='')$sheetPath=$zip->getFromName('xl/worksheets/sheet1.xml')!==false?'xl/worksheets/sheet1.xml':'';
        if($sheetPath===''){$zip->close();throw new Exception('XLSX first worksheet nahi mila.');}
        $data=$zip->getFromName($sheetPath); $xml=simplexml_load_string($data); if($xml===false){$zip->close();throw new Exception('Invalid XLSX worksheet.');}
        $ci=function($ref){preg_match('/^[A-Z]+/i',$ref,$m);$a=strtoupper($m[0]??'A');$n=0;for($i=0;$i<strlen($a);$i++)$n=$n*26+(ord($a[$i])-64);return $n-1;};
        $map=[];$rn=0;$canonical=[];foreach($aliases as $k=>$arr)foreach($arr as $a)$canonical[$a]=$k;
        foreach($xml->sheetData->row as $rr){$rn++;$vals=[];foreach($rr->c as $c){$i=$ci((string)$c['r']);$typ=(string)$c['t'];$v=(string)($c->v??'');if($typ==='s')$v=$shared[(int)$v]??'';elseif($typ==='inlineStr'){$v='';foreach($c->is->t as $tx)$v.=(string)$tx;} $vals[$i]=trim($v);} if($rn===1){foreach($vals as $i=>$v)$map[$i]=$canonical[$norm($v)]??$norm($v); continue;} $blank=true;foreach($vals as $v){if($v!==''){$blank=false;break;}}if($blank)continue;$r=['_line'=>$rn];foreach($map as $i=>$k)if($k!=='')$r[$k]=$vals[$i]??'';$rows[]=$r;if(count($rows)>5000){$zip->close();throw new Exception('Maximum 5,000 indent rows.');}}
        foreach(['destination','pkt','value'] as $required){$found=false;foreach($map as $k)if($k===$required){$found=true;break;}if(!$found){$zip->close();throw new Exception('XLSX me '.$required.' column nahi mila.');}}
        $zip->close();
      }
      if(!$rows)throw new Exception('File me koi indent row nahi mili.');
      $ins=$d->prepare("INSERT INTO tms_indents(indent_no,indent_date,source_dc_id,destination_store_id,packet_count,indent_value,status,invoice_no,seal_no,created_by) VALUES(?,?,?,?,?,?,'Planned',?,?,?)");$count=0;$skip=0;$errors=[];$auto=0;$seenNos=[];
      foreach($rows as $r){$line=(int)$r['_line'];$dest=strtolower(trim((string)($r['destination']??'')));$src=strtolower(trim((string)($r['source']??'')));if($src===''&&$defaultSrc!=='')$src=$defaultSrc;$pkt=(int)preg_replace('/[^0-9]/','',(string)($r['pkt']??'0'));$rawVal=str_replace(['₹',' ',"\xc2\xa0",'INR'],'',(string)($r['value']??'0'));$rawVal=str_replace(',','',$rawVal);$rawVal=str_replace(['(',')'],'',$rawVal);$val=(float)$rawVal;$reason='';
        if($dest!=='' && !isset($storeByCode[$dest])){foreach($stores as $st){if(strtolower(trim((string)$st['store_name']))===$dest){$dest=strtolower(trim((string)$st['store_code']));break;}}}
        if($src!=='' && !isset($dcByCode[$src])){foreach($dcs as $dc){if(strtolower(trim((string)$dc['dc_name']))===$src){$src=strtolower(trim((string)$dc['dc_code']));break;}}}
        if($dest===''||!isset($storeByCode[$dest])) $reason='Destination store not found: '.$dest;
        elseif($src===''||!isset($dcByCode[$src])) $reason='Center DC missing/invalid: '.$src;
        elseif($pkt<=0) $reason='Packets must be greater than 0';
        elseif($val<=0) $reason='Value must be greater than 0';
        elseif($pkt>325||$val>2700000) $reason='Single indent limit: 325 packets / ₹27 lakh';
        if($reason!==''){ $skip++; $errors[]='Line '.$line.': '.$reason; continue; }
        $no=trim((string)($r['indent_no']??''));
        if($no==='') $no='IND-'.date('ymd-His').'-'.(++$auto);
        $key=strtolower($no);
        if(isset($seenNos[$key])){ $skip++; $errors[]='Line '.$line.': Duplicate Indent No '.$no; continue; }
        $seenNos[$key]=1;
        try{
          $createdBy=null;
          if(isset($_SESSION['user_id']) && ctype_digit((string)$_SESSION['user_id'])){
            $uq=$d->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
            $uq->execute([(int)$_SESSION['user_id']]);
            $createdBy=$uq->fetchColumn() ?: null;
          }
          $ins->execute([$no,date('Y-m-d'),(int)$dcByCode[$src]['id'],(int)$storeByCode[$dest]['id'],$pkt,$val,trim((string)($r['invoice_no']??''))?:null,trim((string)($r['seal_no']??''))?:null,$createdBy]);
          $count++;
        }catch(Throwable $x){ $skip++; $errors[]='Line '.$line.': '.$x->getMessage(); }
      }
      $msg="Bulk import complete: $count indent(s) created as Planned, $skip row(s) skipped. Trip tab se sirf unplanned indents ko route/group karke Trip create karein.";if($errors)$msg.=' '.implode(' | ',array_slice($errors,0,8));setflash($msg);redirect('PLANNING.php?tab=indents');
    }
    elseif($action==='manual_indent'){
      $dest=strtolower(trim($_POST['destination']??'')); $src=strtolower(trim($_POST['source']??'')); $pkt=(int)($_POST['packet_count']??0); $val=(float)str_replace([',','₹',' '],'',($_POST['indent_value']??0));
      if($src===''||!isset($dcByCode[$src])) throw new Exception('Valid Center DC select karo.');
      if($dest===''||!isset($storeByCode[$dest])) throw new Exception('Valid destination store select karo.');
      if($pkt<=0||$val<=0) throw new Exception('Packet aur value required hai.');
      $no=trim($_POST['indent_no']??''); if($no==='')$no='IND-'.date('ymd-His').'-'.mt_rand(100,999);
      $srcId=($src!==''&&isset($dcByCode[$src]))?(int)$dcByCode[$src]['id']:null;
      $d->prepare("INSERT INTO tms_indents(indent_no,indent_date,source_dc_id,destination_store_id,packet_count,indent_value,status,invoice_no,seal_no,created_by) VALUES(?,?,?,?,?,?,'Planned',?,?,?)")->execute([$no,($_POST['indent_date']??date('Y-m-d')),$srcId,(int)$storeByCode[$dest]['id'],$pkt,$val,trim($_POST['invoice_no']??'')?:null,trim($_POST['seal_no']??'')?:null,$_SESSION['user_id']??null]);
      setflash("Indent $no saved as Planned. Ab Trip module me is indent ko route/group karke Trip create kar sakte ho."); redirect('PLANNING.php?tab=indents');
    }
    elseif($action==='manual_indent_multi'){
      $src=strtolower(trim($_POST['source']??''));
      if($src===''||!isset($dcByCode[$src])) throw new Exception('Valid Center DC select karo.');
      $srcId=(int)$dcByCode[$src]['id'];
      $dests=$_POST['destination']??[]; $pkts=$_POST['packet_count']??[]; $vals=$_POST['indent_value']??[]; $invs=$_POST['invoice_no']??[]; $seals=$_POST['seal_no']??[];
      $items=[]; $seen=[]; $totalPkt=0; $totalVal=0;
      $rowCount=max(count($dests),count($pkts),count($vals),count($invs),count($seals));
      for($k=0;$k<$rowCount;$k++){
        $dest=strtolower(trim((string)($dests[$k]??''))); $pkt=(int)($pkts[$k]??0); $val=(float)str_replace([',','₹',' '],'',((string)($vals[$k]??0)));
        $inv=trim((string)($invs[$k]??'')); $seal=trim((string)($seals[$k]??''));
        // Completely empty optional row is ignored. A partially filled row is validated.
        if($dest==='' && $pkt===0 && $val===0 && $inv==='' && $seal==='') continue;
        if($dest===''||!isset($storeByCode[$dest])) throw new Exception('Store '.($k+1).' ka valid destination select karo.');
        if(isset($seen[$dest])) throw new Exception('Same destination store repeat nahi kar sakte.');
        if($pkt<=0||$val<=0) throw new Exception('Store '.($k+1).' ke packet aur value required hain.');
        $seen[$dest]=1; $totalPkt+=$pkt; $totalVal+=$val;
        $items[]=['dest'=>$dest,'pkt'=>$pkt,'val'=>$val,'inv'=>$inv?:null,'seal'=>$seal?:null];
      }
      if(count($items)<1 || count($items)>3) throw new Exception('Manual Indent me 1 se 3 stores add kar sakte ho.');
      if(count($dests)>3) throw new Exception('Trip me maximum 3 stores add kar sakte ho. Value aur packet/box limit apply nahi hoti.');
      $date=$_POST['indent_date']??date('Y-m-d'); $base=trim($_POST['indent_no']??'');
      $d->beginTransaction();
      try{
        $created=[]; $ins=$d->prepare("INSERT INTO tms_indents(indent_no,indent_date,source_dc_id,destination_store_id,packet_count,indent_value,status,invoice_no,seal_no,created_by) VALUES(?,?,?,?,?,?,'Planned',?,?,?)");
        foreach($items as $n=>$it){$no=$base!==''?$base.'-'.($n+1):'IND-'.date('ymd-His').'-'.mt_rand(100,999).'-'.($n+1); $ins->execute([$no,$date,$srcId,(int)$storeByCode[$it['dest']]['id'],$it['pkt'],$it['val'],$it['inv'],$it['seal'],$_SESSION['user_id']??null]); $created[]=['id'=>(int)$d->lastInsertId(),'dest'=>(int)$storeByCode[$it['dest']]['id'],'pkt'=>$it['pkt'],'val'=>$it['val']];}
        $d->commit(); setflash(count($created).' indent(s) created as Planned. Trip tab se Planned Indents ko route/group karke Trip create karein.'); redirect('PLANNING.php?tab=indents');
      }catch(Throwable $x){$d->rollBack(); throw $x;}
    }
    elseif($action==='auto_create_pending_trips'){
      try{
        $date=$_POST['planned_date']??date('Y-m-d');
        $iq=$d->query("SELECT i.*,s.store_code,s.store_name,s.latitude,s.longitude FROM tms_indents i JOIN stores s ON s.id=i.destination_store_id WHERE i.status='Planned' AND i.trip_id IS NULL AND COALESCE(s.active,true)=true ORDER BY i.source_dc_id,i.id");
        $pending=$iq->fetchAll(PDO::FETCH_ASSOC);
        if(!$pending) throw new Exception('Koi Pending Planned Indent available nahi hai.');
        $groups=[];
        foreach($pending as $r){ $groups[(int)$r['source_dc_id']][]=$r; }
        $d->beginTransaction(); $createdTrips=0; $createdIndents=0; $skipped=[];
        $maxPkt=tms_trip_max_pkt(); $maxStores=tms_trip_max_stores();
        foreach($groups as $dcId=>$items){
          // An indent bigger than one trip cannot be auto-planned; it must be split first.
          $items=array_values(array_filter($items,function($x)use($maxPkt,&$skipped){if((int)$x['packet_count']>$maxPkt){$skipped[]=($x['indent_no']??('#'.$x['id'])).' ('.(int)$x['packet_count'].' PKT)';return false;}return true;}));
          $oq=$d->prepare('SELECT latitude,longitude FROM tms_dc_master WHERE id=?');$oq->execute([$dcId]);$origin=$oq->fetch(PDO::FETCH_ASSOC)?:[];
          while($items){
            // Automatic (One Click) grouping: nearest store to the DC, then the nearest remaining
            // store that still fits, up to max stores and max PKT per trip (config/app.php).
            $route=[]; $cur=$origin;
            while($items && count($route)<$maxStores){
              $bestIdx=null; $bestDist=INF; $routePkt=array_sum(array_map(fn($x)=>(int)$x['packet_count'],$route));
              foreach($items as $ix=>$candidate){ if($routePkt+(int)$candidate['packet_count']>$maxPkt) continue; $dd=distance_km($cur,$candidate); if($dd<$bestDist){$bestDist=$dd;$bestIdx=$ix;} }
              if($bestIdx===null) break;
              $route[]=$items[$bestIdx]; $cur=$items[$bestIdx]; array_splice($items,$bestIdx,1);
            }
            $pkt=array_sum(array_map(fn($x)=>(int)$x['packet_count'],$route));
            $val=array_sum(array_map(fn($x)=>(float)$x['indent_value'],$route));
            $seq=$d->query('SELECT COALESCE(MAX(id),0)+1 FROM trips')->fetchColumn();
            $code='TRP-'.date('ymd').'-'.str_pad((string)$seq,4,'0',STR_PAD_LEFT);
            $tripId=insert_trip_compat($d,$code,$pkt,$val,$date,(int)$dcId,(int)$route[0]['destination_store_id']);
            $sq=$d->prepare("INSERT INTO tms_trip_stops(trip_id,sequence_no,store_id,packet_count,stop_value,status,expected_delivery_at) VALUES(?,?,?,?,?,'Planned',NULL)");
            $up=$d->prepare("UPDATE tms_indents SET trip_id=?,status='Trip Created',indent_date=? WHERE id=?");
            foreach($route as $n=>$r){$sq->execute([$tripId,$n+1,(int)$r['destination_store_id'],(int)$r['packet_count'],(float)$r['indent_value']]);$up->execute([$tripId,$date,(int)$r['id']]);$createdIndents++;}
            $d->prepare('INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)')->execute([$tripId,'Planned','Auto grouped from pending indents (max '.$maxStores.' stores, '.$maxPkt.' PKT)',$_SESSION['user_id']??null]);
            $createdTrips++;
          }
        }
        if(!$createdTrips && $skipped) throw new Exception('Koi trip nahi bana: har indent '.$maxPkt.' PKT se bada hai: '.implode(', ',$skipped).'. Indent ko chhote hisson me baantein.');
        $d->commit(); setflash($createdTrips.' trip(s) auto-created from '.$createdIndents.' pending indent(s). Har trip me max '.$maxStores.' nearest stores aur max '.$maxPkt.' PKT.'.($skipped?' Skipped (indent '.$maxPkt.' PKT se bada): '.implode(', ',$skipped).'.':'')); redirect('PLANNING.php?tab=trips');
      }catch(Throwable $x){if($d->inTransaction())$d->rollBack();$err=$x->getMessage();}
    }
    elseif($action==='create_manual_trip'){
      try{
        $dcId=(int)($_POST['source_dc_id']??0); $date=$_POST['planned_date']??date('Y-m-d');
        $ids=array_values(array_unique(array_filter(array_map('intval',$_POST['indent_ids']??[]))));
        if($dcId<=0) throw new Exception('Source Center DC select karo.');
        if(count($ids)<1||count($ids)>tms_trip_max_stores()) throw new Exception('Manual trip me 1 se '.tms_trip_max_stores().' planned indents select karein.');
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $params=array_merge([$dcId],$ids);
        $iq=$d->prepare("SELECT i.*,s.store_code,s.store_name,s.latitude,s.longitude FROM tms_indents i JOIN stores s ON s.id=i.destination_store_id WHERE i.source_dc_id=? AND i.id IN ($ph) AND i.status='Planned' AND i.trip_id IS NULL ORDER BY i.id");
        $iq->execute($params); $selected=$iq->fetchAll(PDO::FETCH_ASSOC);
        if(count($selected)!==count($ids)) throw new Exception('Selected Indent me se kuch already Trip me hain ya Planned nahi hain. Refresh karke dobara select karo.');
        $seen=[]; foreach($selected as $r){if(isset($seen[(int)$r['destination_store_id']]))throw new Exception('Same destination store ek Trip me repeat nahi ho sakta.');$seen[(int)$r['destination_store_id']]=1;if(empty($r['latitude'])&&empty($r['longitude']))throw new Exception('Store '.$r['store_code'].' ke coordinates missing hain.');}
        $originQ=$d->prepare('SELECT latitude,longitude FROM tms_dc_master WHERE id=?');$originQ->execute([$dcId]);$origin=$originQ->fetch(PDO::FETCH_ASSOC)?:[];if(empty($origin['latitude'])&&empty($origin['longitude']))throw new Exception('Selected Center DC ke coordinates missing hain.');
        // Manual creation intentionally has NO distance/route restriction. The user can adjust the route later.
        $ordered=$selected; $value=array_sum(array_map(fn($x)=>(float)$x['indent_value'],$ordered)); $pkt=array_sum(array_map(fn($x)=>(int)$x['packet_count'],$ordered));
        tms_trip_check(count($ordered),(int)$pkt,'Manual trip');
        $seq=$d->query('SELECT COALESCE(MAX(id),0)+1 FROM trips')->fetchColumn();$code='TRP-'.date('ymd').'-'.str_pad((string)$seq,4,'0',STR_PAD_LEFT);
        $d->beginTransaction();
        $tripId=insert_trip_compat($d,$code,$pkt,$value,$date,$dcId,(int)$ordered[0]['destination_store_id']);
        $sq=$d->prepare("INSERT INTO tms_trip_stops(trip_id,sequence_no,store_id,packet_count,stop_value,status,expected_delivery_at) VALUES(?,?,?,?,?,'Planned',NULL)");$up=$d->prepare("UPDATE tms_indents SET trip_id=?,status='Trip Created',indent_date=? WHERE id=?");
        foreach($ordered as $n=>$r){$sq->execute([$tripId,$n+1,(int)$r['destination_store_id'],(int)$r['packet_count'],(float)$r['indent_value']]);$up->execute([$tripId,$date,(int)$r['id']]);}
        $d->prepare('INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)')->execute([$tripId,'Planned','Manual Trip created from selected Indents',$_SESSION['user_id']??null]);
        $d->commit(); setflash('Trip '.$code.' created manually from '.count($ordered).' selected Indent(s).'); redirect('PLANNING.php?tab=trips&view='.$tripId);
      }catch(Throwable $x){if($d->inTransaction())$d->rollBack();$err=$x->getMessage();}
    }
    elseif($action==='assign_trip'){
      $tripId=(int)$_POST['trip_id']; $tpt=trim((string)($_POST['tpt_name']??'')); $vehicle=(int)($_POST['vehicle_id']??0); $driver=(int)($_POST['driver_id']??0);
      if($vehicle<=0||$driver<=0) throw new Exception('Vehicle aur Driver select karo.');
      $d->beginTransaction();
      $trQ=$d->prepare("SELECT * FROM trips WHERE id=? FOR UPDATE");$trQ->execute([$tripId]);$tr=$trQ->fetch(PDO::FETCH_ASSOC);if(!$tr)throw new Exception('Trip not found.');
      if(!in_array($tr['status'],['Planned','Assigned'],true)) throw new Exception('Sirf Planned/Assigned Trip accept/assign ho sakta hai.');
      $vr=$d->prepare("SELECT * FROM vehicles WHERE id=? AND active IS TRUE");$vr->execute([$vehicle]);$v=$vr->fetch(PDO::FETCH_ASSOC);if(!$v)throw new Exception('Vehicle not found/active.');
      $dr=$d->prepare("SELECT * FROM drivers WHERE id=? AND active IS TRUE");$dr->execute([$driver]);$dr=$dr->fetch(PDO::FETCH_ASSOC);if(!$dr)throw new Exception('Driver not found/active.');
      if($tpt!=='' && trim((string)($dr['tpt_name']??''))!=='' && strcasecmp($tpt,(string)$dr['tpt_name'])!==0)throw new Exception('Selected driver TPT se match nahi karta.');
      $busyV=$d->prepare("SELECT trip_code FROM trips WHERE vehicle_id=? AND id<>? AND status NOT IN ('Delivered','Cancelled') LIMIT 1");$busyV->execute([$vehicle,$tripId]);if($busyV->fetchColumn())throw new Exception('Selected vehicle already assigned to another active Trip.');
      $busyD=$d->prepare("SELECT trip_code FROM trips WHERE driver_id=? AND id<>? AND status NOT IN ('Delivered','Cancelled') LIMIT 1");$busyD->execute([$driver,$tripId]);if($busyD->fetchColumn())throw new Exception('Selected driver already assigned to another active Trip.');
      $oldVehicle=(int)($tr['vehicle_id']??0);$oldDriver=(int)($tr['driver_id']??0);
            if($oldVehicle && $oldVehicle!==$vehicle)$d->prepare("UPDATE vehicles SET status='Available' WHERE id=? AND status='Assigned'")->execute([$oldVehicle]);
      if($oldDriver && $oldDriver!==$driver)$d->prepare("UPDATE drivers SET status='Available' WHERE id=? AND status='Assigned'")->execute([$oldDriver]);
      $resolvedTpt=$tpt!==''?$tpt:(string)($dr['tpt_name']??'');
      $tq=$d->prepare("SELECT id FROM tms_transporters WHERE lower(name)=lower(?) AND active");$tq->execute([$resolvedTpt]);$tptId=$tq->fetchColumn()?:null;
      if($resolvedTpt!=='' && !$tptId) throw new Exception('Transporter “'.$resolvedTpt.'” master me nahi hai. Masters → Transporters me add karein.');
      $d->prepare("UPDATE trips SET tpt_name=?,transporter_id=?,vehicle_id=?,driver_id=?,status='Assigned' WHERE id=?")->execute([$resolvedTpt?:null,$tptId,$vehicle,$driver,$tripId]);
      $d->prepare("UPDATE vehicles SET status='Assigned' WHERE id=?")->execute([$vehicle]);
      $d->prepare("UPDATE drivers SET status='Assigned' WHERE id=?")->execute([$driver]);
      $d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)")->execute([$tripId,'Assigned','Trip accepted with TPT / Vehicle / Driver',$_SESSION['user_id']??null]);
      $d->commit();setflash('Trip '.$tr['trip_code'].' accepted. TPT / Vehicle / Driver updated.');redirect('PLANNING.php?tab=trips&view='.$tripId);
    }
    elseif($action==='load_trip'){
      $tripId=(int)$_POST['trip_id'];$d->beginTransaction();$q=$d->prepare("SELECT * FROM trips WHERE id=? FOR UPDATE");$q->execute([$tripId]);$tr=$q->fetch(PDO::FETCH_ASSOC);if(!$tr)throw new Exception('Trip not found.');
      if($tr['status']!=='Assigned')throw new Exception('Trip must be Assigned before vehicle load.');
      $lp=(int)$tr['total_boxes'];$lv=(float)$tr['total_value'];if($lp<=0||$lv<=0)throw new Exception('Trip has no packets/value to load.');if($lp>325||$lv>2700000)throw new Exception('Loaded limit: maximum 325 packets and ₹27 lakh.');
      $d->prepare("UPDATE trips SET status='Loaded',loaded_packets=?,loaded_value=? WHERE id=?")->execute([$lp,$lv,$tripId]);
      $d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)")->execute([$tripId,'Loaded','Vehicle load confirmed from Trip Action',$_SESSION['user_id']??null]);
      $d->commit();setflash('Trip '.$tr['trip_code'].' loaded successfully.');redirect('PLANNING.php?tab=trips&view='.$tripId);
    }
    elseif($action==='confirm_load'){
      $tripId=(int)$_POST['trip_id'];$tr=$d->prepare("SELECT * FROM trips WHERE id=?");$tr->execute([$tripId]);$tr=$tr->fetch(PDO::FETCH_ASSOC);if(!$tr)throw new Exception('Trip not found.');if($tr['status']!=='Assigned')throw new Exception('Trip must be Assigned before vehicle load.');$lp=(int)$_POST['loaded_packets'];$lv=(float)$_POST['loaded_value'];if($lp<=0||$lp>325||$lv<=0||$lv>2700000)throw new Exception('Loaded limit: maximum 325 packets and ₹27 lakh.');if($lp>(int)$tr['total_boxes']||$lv>(float)$tr['total_value'])throw new Exception('Actual load cannot exceed uploaded trip packet/value.');$d->prepare("UPDATE trips SET status='Loaded',loaded_packets=?,loaded_value=? WHERE id=?")->execute([$lp,$lv,$tripId]);$d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,created_by) VALUES(?,?,?)")->execute([$tripId,'Loaded',$_SESSION['user_id']??null]);$msg='Vehicle load confirmed.';
    }
    elseif($action==='create_gatepass'){
      $tripId=(int)$_POST['trip_id'];$tr=$d->prepare("SELECT * FROM trips WHERE id=?");$tr->execute([$tripId]);$tr=$tr->fetch(PDO::FETCH_ASSOC);if(!$tr)throw new Exception('Trip not found.');if($tr['status']!=='Loaded')throw new Exception('Gate Pass sirf vehicle load confirm hone ke baad create hoga.');
      $exists=$d->prepare("SELECT id FROM tms_gate_passes WHERE trip_id=?");$exists->execute([$tripId]);if($exists->fetchColumn())throw new Exception('Gate Pass already exists.');
      $path=null;if(!empty($_FILES['gate_file']['tmp_name'])){$ext=strtolower(pathinfo($_FILES['gate_file']['name'],PATHINFO_EXTENSION));if(!in_array($ext,['pdf','jpg','jpeg','png','xlsx','xls'],true))throw new Exception('Gate Pass file only PDF/JPG/PNG/XLS/XLSX allowed.');$dir=TMS_ROOT.'/uploads/gatepass';if(!is_dir($dir))mkdir($dir,0775,true);$fn='GP-'.$tripId.'-'.time().'.'.$ext;move_uploaded_file($_FILES['gate_file']['tmp_name'],$dir.'/'.$fn);$path='uploads/gatepass/'.$fn;}
      $gp='GP-'.date('ymd').'-'.str_pad((string)$tripId,5,'0',STR_PAD_LEFT);$d->prepare("INSERT INTO tms_gate_passes(gate_pass_no,trip_id,invoice_no,seal_no,packet_count,packet_value,document_path,remarks,created_by) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$gp,$tripId,trim($_POST['invoice_no']),trim($_POST['seal_no']), (int)($tr['loaded_packets']?:$tr['total_boxes']),(float)($tr['loaded_value']?:$tr['total_value']),$path,trim($_POST['remarks']),$_SESSION['user_id']??null]);$d->prepare("UPDATE trips SET status='Gate Pass Created' WHERE id=? AND status='Loaded'")->execute([$tripId]);$d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,created_by) VALUES(?,?,?)")->execute([$tripId,'Gate Pass Created',$_SESSION['user_id']??null]);$msg='Gate Pass created successfully.';redirect('PLANNING.php?tab=gatepasses&trip_id='.$tripId);
    }
    elseif($action==='trip_operational_update'){
      $tripId=(int)$_POST['trip_id']; $status=trim($_POST['status']??''); $remarks=trim($_POST['remarks']??'');
      if(!in_array($status,['In Transit','Breakdown','Accident','Delivered'],true)) throw new Exception('Invalid trip status.');
      $q=$d->prepare("SELECT * FROM trips WHERE id=?");$q->execute([$tripId]);$tr=$q->fetch(PDO::FETCH_ASSOC);if(!$tr)throw new Exception('Trip not found.');
      if($status==='In Transit' && !in_array($tr['status'],['Gate Pass Created','In Transit','Breakdown','Accident'],true)) throw new Exception('Gate Pass create hone ke baad hi In Transit karein.');
      $d->beginTransaction();
      if($status==='In Transit'){
        $d->prepare("UPDATE trips SET status='In Transit',dispatched_at=COALESCE(dispatched_at,CURRENT_TIMESTAMP) WHERE id=?")->execute([$tripId]);
        $eq=$d->prepare("SELECT ts.id, COALESCE(s.transit_days,1) transit_days FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id WHERE ts.trip_id=? AND ts.status<>'Delivered'"); $eq->execute([$tripId]);
        foreach($eq->fetchAll(PDO::FETCH_ASSOC) as $es){ $d->prepare("UPDATE tms_trip_stops SET expected_delivery_at=COALESCE(expected_delivery_at,(SELECT dispatched_at FROM trips WHERE id=?)+( ? * interval '1 day')) WHERE id=?")->execute([$tripId,(float)$es['transit_days'],(int)$es['id']]); }
        if(!empty($tr['vehicle_id']))$d->prepare("UPDATE vehicles SET status='Dispatched' WHERE id=?")->execute([(int)$tr['vehicle_id']]);
        if(!empty($tr['driver_id']))$d->prepare("UPDATE drivers SET status='Dispatched' WHERE id=?")->execute([(int)$tr['driver_id']]);
      } elseif($status==='Delivered'){
        $d->prepare("UPDATE tms_trip_stops SET status='Delivered',delivered_at=COALESCE(delivered_at,CURRENT_TIMESTAMP),delivery_result=CASE WHEN expected_delivery_at IS NULL OR CURRENT_TIMESTAMP<=expected_delivery_at THEN 'On Time Delivered' ELSE 'Late Delivered' END,delivery_delay_days=CASE WHEN expected_delivery_at IS NULL OR CURRENT_TIMESTAMP<=expected_delivery_at THEN 0 ELSE ROUND(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP-expected_delivery_at))/86400.0,2) END,delivery_remarks=COALESCE(NULLIF(?,''),delivery_remarks) WHERE trip_id=?")->execute([$remarks,$tripId]);
        $d->prepare("UPDATE tms_indents SET status='Delivered' WHERE trip_id=?")->execute([$tripId]);
        $d->prepare("UPDATE trips SET status='Delivered',completed_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$tripId]);
        if(!empty($tr['vehicle_id']))$d->prepare("UPDATE vehicles SET status='Available' WHERE id=?")->execute([(int)$tr['vehicle_id']]);
        if(!empty($tr['driver_id']))$d->prepare("UPDATE drivers SET status='Available' WHERE id=?")->execute([(int)$tr['driver_id']]);
      } else {
        $d->prepare("UPDATE trips SET status=? WHERE id=?")->execute([$status,$tripId]);
      }
      $d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)")->execute([$tripId,$status,$remarks,$_SESSION['user_id']??null]);
      $d->commit(); $msg='Trip '.$tr['trip_code'].' status updated to '.$status.'.';
    }
    elseif($action==='dispatch_gatepass'){
      $tripId=(int)$_POST['trip_id'];$tr=$d->prepare("SELECT * FROM trips WHERE id=?");$tr->execute([$tripId]);$tr=$tr->fetch(PDO::FETCH_ASSOC);if(!$tr)throw new Exception('Trip not found.');
      if($tr['status']!=='Gate Pass Created')throw new Exception('Trip must be Gate Pass Created before dispatch.');
      $d->beginTransaction();$d->prepare("UPDATE trips SET status='In Transit',dispatched_at=COALESCE(dispatched_at,CURRENT_TIMESTAMP) WHERE id=?")->execute([$tripId]);
      $eq=$d->prepare("SELECT ts.id, COALESCE(s.transit_days,1) transit_days FROM tms_trip_stops ts JOIN stores s ON s.id=ts.store_id WHERE ts.trip_id=? AND ts.status<>'Delivered'"); $eq->execute([$tripId]);
      foreach($eq->fetchAll(PDO::FETCH_ASSOC) as $es){ $d->prepare("UPDATE tms_trip_stops SET expected_delivery_at=COALESCE(expected_delivery_at,(SELECT dispatched_at FROM trips WHERE id=?)+( ? * interval '1 day')) WHERE id=?")->execute([$tripId,(float)$es['transit_days'],(int)$es['id']]); }
      if(!empty($tr['vehicle_id']))$d->prepare("UPDATE vehicles SET status='Dispatched' WHERE id=?")->execute([(int)$tr['vehicle_id']]);if(!empty($tr['driver_id']))$d->prepare("UPDATE drivers SET status='Dispatched' WHERE id=?")->execute([(int)$tr['driver_id']]);$d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)")->execute([$tripId,'In Transit','Dispatched from Gate Pass',$_SESSION['user_id']??null]);$d->commit();$msg='Trip '.$tr['trip_code'].' dispatched and status set to In Transit.';
    }
    elseif($action==='status_update'){
      $tripId=(int)$_POST['trip_id'];$status=trim($_POST['status']);$allowed=['In Transit','Breakdown','Accident','Delivered'];if(!in_array($status,$allowed,true))throw new Exception('Invalid status.');$tr=$d->prepare("SELECT * FROM trips WHERE id=?");$tr->execute([$tripId]);$tr=$tr->fetch(PDO::FETCH_ASSOC);if(!$tr)throw new Exception('Trip not found.');if($status==='Delivered'){ $d->prepare("UPDATE tms_trip_stops SET status='Delivered',delivered_at=CURRENT_TIMESTAMP,delivery_result=CASE WHEN expected_delivery_at IS NULL OR CURRENT_TIMESTAMP<=expected_delivery_at THEN 'On Time Delivered' ELSE 'Late Delivered' END,delivery_delay_days=CASE WHEN expected_delivery_at IS NULL OR CURRENT_TIMESTAMP<=expected_delivery_at THEN 0 ELSE ROUND(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP-expected_delivery_at))/86400.0,2) END WHERE trip_id=?")->execute([$tripId]);$d->prepare("UPDATE tms_indents SET status='Delivered' WHERE trip_id=?")->execute([$tripId]);$d->prepare("UPDATE trips SET status='Delivered',completed_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$tripId]);$d->prepare("UPDATE vehicles SET status='Available' WHERE id=?")->execute([(int)$tr['vehicle_id']]);$d->prepare("UPDATE drivers SET status='Available' WHERE id=?")->execute([(int)$tr['driver_id']]);}else{$d->prepare("UPDATE trips SET status=? ,dispatched_at=CASE WHEN ?='In Transit' AND dispatched_at IS NULL THEN CURRENT_TIMESTAMP ELSE dispatched_at END WHERE id=?")->execute([$status,$status,$tripId]);if($status==='In Transit'){$d->prepare("UPDATE vehicles SET status='Dispatched' WHERE id=?")->execute([(int)$tr['vehicle_id']]);$d->prepare("UPDATE drivers SET status='Dispatched' WHERE id=?")->execute([(int)$tr['driver_id']]);}}
      $d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)")->execute([$tripId,$status,trim($_POST['remarks']),$_SESSION['user_id']??null]);$msg='Trip status updated to '.$status.'.';
    }
  }catch(Throwable $x){$err=$x->getMessage();}
  }
}
