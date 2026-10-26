<?php
// PRD Pending JSON/CSV endpoints: export, planner note, plan preview, create trips.
// Included from pages/PLANNING.php; shares its variables ($d, $stores, $dcs, $msg, $err, $tab, …).
// PRD Pending: background sync status, polled by the board while it says "Refreshing from Oracle…" (GET, JSON)
if(($_GET['sync_status']??'')==='1'){
  require_once TMS_ROOT.'/app/services/oracle_sync.php';
  header('Cache-Control: no-store');
  $ls=$d->query("SELECT id, ok, created_at::timestamp(0)::text t, LEFT(message,300) m FROM tms_sync_log WHERE kind='prd' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
  json_out(['ok'=>true,'running'=>tms_sync_running($d),'last_id'=>(int)($ls['id']??0),'last_ok'=>!empty($ls['ok']),'last_time'=>$ls['t']??null,'last_message'=>$ls['m']??'']);
}
// PRD Pending: CSV export of the planning board
if(($_GET['export']??'')==='prd_matrix'){ require_once TMS_ROOT.'/app/services/oracle_sync.php'; $vo=prd_view_opts($_GET); prd_matrix_csv($d,['site'=>$vo['site'],'sug'=>$_GET['sug']??'separate']); exit; }
if(($_GET['export']??'')==='prd_plan'){ prd_export_csv($d); exit; }
// PRD Pending: planner note save (AJAX, JSON)
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_prd_note'){
  header('Content-Type: application/json; charset=utf-8');
  try{
    if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))) throw new Exception('Session expired. Page refresh karein.');
    require_perm('plan',true);
    $gk=(string)($_POST['gkey']??''); if(!preg_match('/^[a-f0-9]{32}$/',$gk)) throw new Exception('Invalid store key.');
    $note=prd_cut(trim((string)($_POST['note']??'')),500);
    if($note===''){ $d->prepare("DELETE FROM prd_planner_notes WHERE gkey=?")->execute([$gk]); }
    else{ $d->prepare("INSERT INTO prd_planner_notes(gkey,source_label,destination_name,note,updated_by,updated_at) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(gkey) DO UPDATE SET note=EXCLUDED.note,updated_by=EXCLUDED.updated_by,updated_at=CURRENT_TIMESTAMP")
        ->execute([$gk,prd_cut((string)($_POST['source_label']??''),200),prd_cut((string)($_POST['destination_name']??''),150),$note,$_SESSION['user_id']??null]); }
    echo json_encode(['ok'=>true]);
  }catch(Throwable $x){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$x->getMessage()]); }
  exit;
}
// PRD Pending: challan detail for one store row, loaded on demand (AJAX, JSON)
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='prd_detail'){
  try{
    $keys=array_values(array_filter(array_map('strval',(array)($_POST['keys']??[])),fn($k)=>(bool)preg_match('/^[a-f0-9]{32}$/',$k)));
    if(!$keys) throw new Exception('No store.');
    $k=prd_key_sql('p'); $ph=implode(',',array_fill(0,count($keys),'?'));
    $q=$d->prepare("SELECT p.source_site_code src, MAX(p.source_name) src_name, p.challan_no, MAX(p.challan_barcode) barcode, COALESCE(p.sub_div,'') div, MIN(p.challan_date) dt, SUM(p.qty) qty, SUM(p.value) val
      FROM prd_pending p WHERE $k IN ($ph) AND ".prd_pending_where('p')." AND p.source_system='ORACLE' AND UPPER(BTRIM(COALESCE(p.challan_status,'')))=?
      GROUP BY 1,3,5 ORDER BY MIN(p.challan_date), 1, 3 LIMIT 1000");
    $q->execute(array_merge($keys,[prd_status_filter()]));
    json_out(['ok'=>true,'rows'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
  }catch(Throwable $x){ json_out(['ok'=>false,'error'=>$x->getMessage()],400); }
}
// PRD Pending: hold / release a store with a reason (AJAX, JSON)
if($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'',['prd_hold','prd_release'],true)){
  try{
    if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))) throw new Exception('Session expired. Page refresh karein.');
    require_perm('plan',true);
    $dest=trim((string)($_POST['dest']??'')); if($dest==='') throw new Exception('Store missing.');
    if($_POST['action']==='prd_hold') prd_hold_store($d,$dest,(string)($_POST['reason']??''),prd_cut((string)($_POST['remarks']??''),500),($_POST['until']??'')!==''?(string)$_POST['until']:null);
    else prd_release_store($d,$dest);
    json_out(['ok'=>true]);
  }catch(Throwable $x){ json_out(['ok'=>false,'error'=>$x->getMessage()],400); }
}
// PRD Pending: tick stores -> create ONE plan (AJAX, JSON). The plan appears in the Indents tab; trips are made from it later.
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='create_plan'){
  header('Content-Type: application/json; charset=utf-8');
  try{
    if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))) throw new Exception('Session expired. Page refresh karein.');
    require_perm('plan',true);
    $r=plan_create($d,array_map('strval',(array)($_POST['keys']??[])),isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null);
    setflash($r['stores'].' store(s), '.number_format($r['pkt']).' PKT review ke liye ready.'.($r['skipped']?' Nahi gaye: '.implode('; ',array_map(fn($s)=>$s['name'].' - '.$s['reason'],$r['skipped'])).'.':''));
    echo json_encode(['ok'=>true,'redirect'=>'PLANNING.php?tab=review']+$r);
  }catch(Throwable $x){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$x->getMessage()]); }
  exit;
}
// PRD Pending: plan preview + create trips (AJAX, JSON)
if($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'',['prd_preview','create_prd_plan'],true)){
  header('Content-Type: application/json; charset=utf-8');
  try{
    if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))) throw new Exception('Session expired. Page refresh karke dobara try karein.');
    require_perm('plan',true);
    if($_POST['action']==='prd_preview'){
      $keys=array_values(array_unique(array_filter(array_map('strval',(array)($_POST['keys']??[])),fn($k)=>(bool)preg_match('/^[a-f0-9]{32}$/',$k))));
      if(!$keys) throw new Exception('Kam se kam ek store select karein.');
      $plan=prd_plan_selection($d,$keys,($_POST['mode']??'')==='joint'?'joint':'separate',null,['balance'=>!empty($_POST['balance']),'strict'=>!empty($_POST['strict']),'max_stops'=>(int)($_POST['max_stops']??0),'low_stock'=>((int)($_POST['low_pkt']??0)>0&&(int)($_POST['low_days']??0)>0)?[(int)$_POST['low_pkt'],(int)$_POST['low_days']]:null]);
      $plan['dcs']=array_map(fn($x)=>['id'=>(int)$x['id'],'label'=>$x['dc_code'].' · '.$x['dc_name']],$d->query("SELECT id,dc_code,dc_name FROM tms_dc_master WHERE COALESCE(active,TRUE) ORDER BY dc_code")->fetchAll(PDO::FETCH_ASSOC));
      echo json_encode(['ok'=>true]+$plan); exit;
    }
    $loads=json_decode((string)($_POST['loads']??''),true);
    if(!is_array($loads)||!$loads) throw new Exception('Koi trip select nahi hua.');
    if(count($loads)>60) throw new Exception('Ek baar me maximum 60 trips.');
    $made=[]; $d->beginTransaction();
    foreach(array_values($loads) as $i=>$l){
      try{ $made[]=prd_create_trip_tx($d,(array)($l['stops']??$l['keys']??[]),(int)($l['dc_id']??0),(string)($l['vehicle_type']??''),!empty($l['joint'])); }
      catch(Throwable $x){ throw new Exception('Trip '.($i+1).': '.$x->getMessage()); }
    }
    $d->commit();
    $pk=array_sum(array_column($made,'pkt')); $st=array_sum(array_column($made,'stores'));
    $cs=array_column($made,'code'); setflash(count($made).' trip(s) created ('.(count($cs)>2?$cs[0].' … '.substr(end($cs),-4):implode(', ',$cs)).')'." · $st store(s) · $pk PKT.");
    echo json_encode(['ok'=>true,'trips'=>$made,'redirect'=>count($made)===1?'PLANNING.php?tab=trips&view='.$made[0]['id']:'PLANNING.php?tab=trips']);
  }catch(Throwable $x){ if($d->inTransaction())$d->rollBack(); http_response_code(400); echo json_encode(['ok'=>false,'error'=>$x->getMessage()]); }
  exit;
}
