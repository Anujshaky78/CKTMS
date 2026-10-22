<?php
declare(strict_types=1);
// Trip helpers: distances, route ordering, legacy-safe trip insert, PRD trip creation.

if (!function_exists('col_exists')) {
function col_exists(PDO $d,string $table,string $col):bool{ $q=$d->prepare("SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name=? AND column_name=?)");$q->execute([$table,$col]);return (bool)$q->fetchColumn(); }
}
if (!function_exists('days_from_value')) {
function days_from_value(float $v):int{ if($v>=2000000) return 1; if($v>=1500000) return 2; return 3; }
}
if (!function_exists('distance_km')) {
function distance_km($a,$b):float{ $lat1=(float)($a['latitude']??0);$lon1=(float)($a['longitude']??0);$lat2=(float)($b['latitude']??0);$lon2=(float)($b['longitude']??0); if(!$lat1&&!$lon1&&!$lat2&&!$lon2)return 99999; $p=pi()/180;$x=($lon2-$lon1)*$p*cos(($lat1+$lat2)*$p/2);$y=($lat2-$lat1)*$p;return sqrt($x*$x+$y*$y)*6371; }
}
if (!function_exists('optimize_route')) {
function optimize_route(array $items,array $origin):array{if(count($items)<2)return $items;$rem=array_values($items);$route=[];$cur=$origin;while($rem){$bi=0;$bd=INF;foreach($rem as $i=>$r){$dd=distance_km($cur,$r);if($dd<$bd){$bd=$dd;$bi=$i;}}$route[]=$rem[$bi];$cur=$rem[$bi];array_splice($rem,$bi,1);}for($pass=0;$pass<20;$pass++){ $changed=false;for($i=0;$i<count($route)-1;$i++){for($j=$i+1;$j<count($route);$j++){$a=$i===0?$origin:$route[$i-1];$b=$route[$i];$c=$route[$j];$d=$j+1<count($route)?$route[$j+1]:null;$old=distance_km($a,$b)+($d?distance_km($c,$d):0);$new=distance_km($a,$c)+($d?distance_km($b,$d):0);if($new+0.001<$old){$route=array_merge(array_slice($route,0,$i),array_reverse(array_slice($route,$i,$j-$i+1)),array_slice($route,$j+1));$changed=true;}}}if(!$changed)break;}return $route;}
}
if (!function_exists('route_distance_km')) {
function route_distance_km(array $origin,array $stops):float{$total=0;$cur=$origin;foreach($stops as $s){$total+=distance_km($cur,$s);$cur=$s;}return $total;}
}

if (!function_exists('insert_trip_compat')) {
function insert_trip_compat(PDO $d, string $code, int $pkt, float $value, string $date, ?int $srcDcId, ?int $destId): int{
  $cols=$d->query("SELECT column_name,is_nullable,column_default,data_type,character_maximum_length FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='trips' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
  $names=array_column($cols,'column_name');
  $data=[];
  $lengths=[]; foreach($cols as $cc){ $lengths[$cc['column_name']]=$cc['character_maximum_length']!==null?(int)$cc['character_maximum_length']:null; }
  // Legacy trips tables commonly have VARCHAR(20) fields. Never send an oversized
  // compatibility value; keep the insert safe while preserving the real IDs in
  // source_dc_id/destination_store_id where those columns exist.
  $put=function(string $name,$value) use (&$data,$names,$lengths){
    if(!in_array($name,$names,true)) return;
    $max=$lengths[$name]??null;
    if($max!==null && is_string($value) && mb_strlen($value)>$max) $value=mb_substr($value,0,$max);
    $data[$name]=$value;
  };

  // Always populate legacy required trip number/code fields when present.
  $put('trip_number',$code); $put('trip_no',$code); $put('trip_code',$code); $put('trip_id_no',$code);
  $put('status','Planned'); $put('vehicle_type','24FT');
  // Legacy installations may require vehicle_number even before resource assignment. Use a unique pending placeholder; assignment later replaces it.
  if(in_array('vehicle_number',$names,true)) $put('vehicle_number','UNASSIGNED');
  if(in_array('driver_name',$names,true)) $put('driver_name','UNASSIGNED');
  if(in_array('tpt_name',$names,true)) $put('tpt_name','UNASSIGNED');
  foreach(['total_boxes','packet_count','packets','boxes'] as $n) $put($n,$pkt);
  foreach(['total_value','trip_value','value'] as $n) $put($n,$value);
  // Legacy trips schemas often have a mandatory text `source` column.
  // Populate it from the selected Center DC code; never leave it NULL.
  $srcCode='TMS';
  if($srcDcId){
    $q=$d->prepare("SELECT dc_code FROM tms_dc_master WHERE id=? LIMIT 1");
    $q->execute([$srcDcId]);
    $srcCode=(string)($q->fetchColumn() ?: 'TMS');
  }
  if(in_array('source',$names,true)){
    $sourceType='';
    foreach($cols as $cc){ if($cc['column_name']==='source'){ $sourceType=(string)$cc['data_type']; break; } }
    $put('source',in_array($sourceType,['integer','bigint','smallint'],true)?$srcDcId:$srcCode);
  }
  $put('source_dc',$srcCode); $put('origin',$srcCode);
  $put('source_dc_id',$srcDcId); $put('source_store_id',null);

  // Legacy installations may also require a mandatory `destination` column.
  // Resolve the selected destination store into the correct DB type.
  $destCode='STORE-'.$destId;
  if($destId){
    $q=$d->prepare("SELECT store_code,store_name FROM stores WHERE id=? LIMIT 1");
    $q->execute([$destId]);
    $dr=$q->fetch(PDO::FETCH_ASSOC);
    if($dr) $destCode=(string)($dr['store_code'] ?: ($dr['store_name'] ?: $destCode));
  }
  if(in_array('destination',$names,true)){
    $destinationType='';
    foreach($cols as $cc){ if($cc['column_name']==='destination'){ $destinationType=(string)$cc['data_type']; break; } }
    $put('destination',in_array($destinationType,['integer','bigint','smallint'],true)?$destId:$destCode);
  }
  $put('destination_store',$destCode); $put('destination_code',$destCode);
  $put('destination_store_id',$destId); $put('destination_id',$destId); $put('store_id',$destId);
  $put('planned_date',$date); $put('trip_date',$date); $put('dispatch_date',$date);
  $put('loaded_packets',0); $put('loaded_value',0);

  // Populate any other legacy NOT NULL column that has no default only when a safe value is known.
  foreach($cols as $c){
    $name=$c['column_name'];
    if($name==='id' || array_key_exists($name,$data) || $c['is_nullable']==='YES' || $c['column_default']!==null) continue;
    if(in_array($name,['driver_name','vehicle_number','tpt_name','vehicle_type'],true)){ $data[$name]=in_array($name,['driver_name','vehicle_number','tpt_name'],true)?'UNASSIGNED':'24FT'; continue; }
    throw new Exception("Existing trips table me required column '$name' hai. Uska default/value define karo.");
  }
  if(!isset($data['trip_number']) && in_array('trip_number',$names,true)) throw new Exception('Trip number generate nahi hua.');

  $cn=array_keys($data);
  $quoted=array_map(fn($x)=>'"'.str_replace('"','""',$x).'"',$cn);
  $ph=implode(',',array_fill(0,count($cn),'?'));
  $sql='INSERT INTO trips('.implode(',',$quoted).') VALUES('.$ph.') RETURNING id';
  $st=$d->prepare($sql); $st->execute(array_values($data));
  return (int)$st->fetchColumn();
}
}

if (!function_exists('route_store_leg_ok')) {
function route_store_leg_ok(array $route, float $maxKm=200.0):bool{
  for($i=1;$i<count($route);$i++) if(distance_km($route[$i-1],$route[$i])>$maxKm+0.001) return false;
  return true;
}
}
if (!function_exists('best_group_route')) {
function best_group_route(array $items,array $origin,float $maxStoreLeg=200.0):?array{
  $n=count($items); if($n<1||$n>3)return null;
  if($n===1)return ['route'=>$items,'store_km'=>0.0,'route_km'=>route_distance_km($origin,$items)];
  $perms=[];
  if($n===2){$perms=[[$items[0],$items[1]],[$items[1],$items[0]]];}
  else{foreach($items as $ai=>$a)foreach($items as $bi=>$b)foreach($items as $ci=>$c){if($ai===$bi||$ai===$ci||$bi===$ci)continue;$perms[]=[$a,$b,$c];}}
  $best=null;
  foreach($perms as $route){if(!route_store_leg_ok($route,$maxStoreLeg))continue;$storeKm=0.0;for($i=1;$i<count($route);$i++)$storeKm+=distance_km($route[$i-1],$route[$i]);$routeKm=route_distance_km($origin,$route);if($best===null||$storeKm<$best['store_km']-0.001||($storeKm<=$best['store_km']+0.001&&$routeKm<$best['route_km']))$best=['route'=>$route,'store_km'=>$storeKm,'route_km'=>$routeKm];}
  return $best;
}
}

if (!function_exists('prd_create_trip_tx')) {
/**
 * Create one trip from PRD stops. Caller owns the transaction.
 * $stops: list of ['parts' => [['gkey' => source group key, 'pkts' => null|[PKT keys]], …]]
 *   (older shapes ['gkey'=>…,'pkts'=>…] or a plain gkey are accepted too).
 * $joint: allow parts from more than one warehouse (one vehicle collects from both DCs).
 * $dcId: first pickup / loading DC.
 */
function prd_create_trip_tx(PDO $d, array $stops, int $dcId, string $veh, bool $joint = false): array {
  $want = [];  // gkey => null (whole) | [pkts]
  foreach ($stops as $st) {
    $parts = is_array($st) ? ($st['parts'] ?? [['gkey' => $st['gkey'] ?? '', 'pkts' => $st['pkts'] ?? null]]) : [['gkey' => (string)$st, 'pkts' => null]];
    foreach ($parts as $pt) {
      $k = (string)($pt['gkey'] ?? ''); if (!preg_match('/^[a-f0-9]{32}$/', $k)) continue;
      if (array_key_exists($k, $want)) throw new Exception('Same store-warehouse part do baar aa gaya. Page refresh karein.');
      $want[$k] = isset($pt['pkts']) && is_array($pt['pkts']) ? array_values(array_unique(array_map('strval', $pt['pkts']))) : null;
    }
  }
  if (!$want) throw new Exception('Is trip me koi store nahi hai.');
  if (!$dcId) throw new Exception('Is trip ki site kisi Center DC se linked nahi hai. Masters → Transporters & docks → Warehouses me site ko DC se link karein.');
  $groups = prd_enrich($d, prd_load_groups($d, array_keys($want)));
  if (count($groups) !== count($want)) throw new Exception('Kuch stores ab pending nahi hain (dusre trip me gaye ya Oracle me dispatch ho gaye). Page refresh karein.');
  $holds = prd_active_holds($d);
  $sources = []; $byStore = []; $pick = [];
  foreach ($groups as $k => $g) {
    if (!$g['store']) throw new Exception("Store '{$g['destination_name']}' Store Master me nahi mila.");
    if ($g['closed']) throw new Exception("Store '{$g['destination_name']}' closed hai.");
    if (isset($holds[prd_store_key($g['destination_name'])])) throw new Exception("Store '{$g['destination_name']}' hold par hai ({$holds[prd_store_key($g['destination_name'])]['reason']}). Pehle hold release karein.");
    $srcKey = $g['source_site_code'] . '|' . strtolower($g['source_label']); $sources[$srcKey] = true;
    $units = $want[$k] ?? array_keys($g['units']);
    $ids = []; $val = 0.0; $ch = [];
    foreach ($units as $u) {
      if (!isset($g['units'][$u])) throw new Exception("Store '{$g['destination_name']}': PKT '$u' ab pending nahi hai. Page refresh karein.");
      $ids = array_merge($ids, $g['units'][$u]['ids']); $val += $g['units'][$u]['value']; $ch += $g['units'][$u]['challans'];
    }
    $partDc = (int)($g['dc']['id'] ?? 0) ?: $dcId;
    if (count($sources) > 1 && !$joint) throw new Exception('Ek trip me sirf ek warehouse ke stores (ya “Joint vehicle” mode chunein).');
    if ($joint && !$g['dc']) throw new Exception("Source '{$g['source_label']}' Center DC master me nahi hai; joint pickup ke liye DC master me add karein (dc_code = site code).");
    $sid = (int)$g['store']['id'];
    $byStore[$sid]['g'] = $g; $byStore[$sid]['parts'][] = ['dc' => $partDc, 'ids' => $ids, 'pkt' => count($units), 'value' => $val, 'challans' => array_keys($ch)];
    $pick[$partDc] = ['pkt' => ($pick[$partDc]['pkt'] ?? 0) + count($units), 'value' => ($pick[$partDc]['value'] ?? 0) + $val];
  }
  $packets = 0; $value = 0.0; foreach ($byStore as $x) foreach ($x['parts'] as $pt) { $packets += $pt['pkt']; $value += $pt['value']; }
  tms_trip_check(count($byStore), $packets);
  if (!isset($pick[$dcId])) throw new Exception('Chosen loading DC is trip ke kisi store ka source nahi hai.');
  // pickup order: chosen DC first, then the rest by size
  $order = array_keys($pick); usort($order, fn($a, $b) => ($a === $dcId ? -1 : ($b === $dcId ? 1 : $pick[$b]['pkt'] <=> $pick[$a]['pkt'])));
  $oq = $d->prepare('SELECT id,latitude,longitude FROM tms_dc_master WHERE id=?'); $oq->execute([$dcId]); $origin = $oq->fetch(PDO::FETCH_ASSOC); if (!$origin) throw new Exception('Center DC invalid hai.');
  $lastPick = end($order); $oq->execute([$lastPick]); $lp = $oq->fetch(PDO::FETCH_ASSOC) ?: $origin;
  $oGeo = ((float)$lp['latitude'] || (float)$lp['longitude']) ? ['lat' => (float)$lp['latitude'], 'lng' => (float)$lp['longitude']] : null;
  $pts = []; foreach ($byStore as $sid => $x) $pts[$sid] = $x['g']['geo'];
  $ordered = array_map(fn($sid) => $byStore[$sid], prd_best_order($oGeo, $pts)['order']);
  $allIds = []; foreach ($ordered as $x) foreach ($x['parts'] as $pt) $allIds = array_merge($allIds, $pt['ids']);
  $ph = implode(',', array_fill(0, count($allIds), '?'));
  $lk = $d->prepare("SELECT id FROM prd_pending p WHERE id IN ($ph) AND " . prd_pending_where('p') . " FOR UPDATE"); $lk->execute($allIds);
  if (count($lk->fetchAll(PDO::FETCH_COLUMN)) !== count($allIds)) throw new Exception('Selected challans abhi kisi aur ne plan kar diye. Page refresh karein.');
  $seq = (int)$d->query('SELECT COALESCE(MAX(id),0)+1 FROM trips')->fetchColumn(); $code = 'TRP-' . date('ymd') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
  $tripId = insert_trip_compat($d, $code, $packets, $value, date('Y-m-d'), $dcId, (int)$ordered[0]['g']['store']['id']);
  $stop = $d->prepare("INSERT INTO tms_trip_stops(trip_id,sequence_no,store_id,packet_count,stop_value,status,expected_delivery_at) VALUES(?,?,?,?,?,'Planned',NULL)");
  $ind = $d->prepare("INSERT INTO tms_indents(indent_no,indent_date,source_dc_id,destination_store_id,packet_count,indent_value,status,trip_id,source_ref,created_by) VALUES(?,?,?,?,?,?,'Trip Created',?,?,?) ON CONFLICT(indent_no) DO UPDATE SET trip_id=EXCLUDED.trip_id,status='Trip Created'");
  foreach ($ordered as $i => $x) {
    $sp = array_sum(array_column($x['parts'], 'pkt')); $sv = array_sum(array_column($x['parts'], 'value'));
    $stop->execute([$tripId, $i + 1, $x['g']['store']['id'], $sp, $sv]);
    foreach ($x['parts'] as $j => $pt) $ind->execute(['PRD-T' . $tripId . '-' . ($i + 1) . (count($x['parts']) > 1 ? '-' . ($j + 1) : ''), date('Y-m-d'), $pt['dc'], $x['g']['store']['id'], $pt['pkt'], $pt['value'], $tripId, prd_cut(implode(',', $pt['challans']), 120), $_SESSION['user_id'] ?? null]);
  }
  $pq = $d->prepare("INSERT INTO tms_trip_pickups(trip_id,sequence_no,dc_id,packet_count,pickup_value) VALUES(?,?,?,?,?)");
  foreach ($order as $i => $dc) $pq->execute([$tripId, $i + 1, $dc, $pick[$dc]['pkt'], $pick[$dc]['value']]);
  $d->prepare("UPDATE prd_pending SET planned_trip_id=?,planned_at=CURRENT_TIMESTAMP WHERE id IN ($ph)")->execute(array_merge([$tripId], $allIds));
  $veh = prd_cut(trim($veh), 60);
  $d->prepare("UPDATE trips SET is_joint=?, vehicle_type=NULLIF(?,'') WHERE id=?")->execute([count($pick) > 1 ? 'true' : 'false', $veh, $tripId]);
  $d->prepare("INSERT INTO tms_trip_status_history(trip_id,status,remarks,created_by) VALUES(?,?,?,?)")->execute([$tripId, 'Planned', 'PRD plan: ' . count($ordered) . ' store(s), ' . $packets . ' PKT' . (count($pick) > 1 ? ', joint pickup from ' . count($pick) . ' warehouses' : '') . ($veh !== '' ? ', vehicle ' . $veh : ''), $_SESSION['user_id'] ?? null]);
  return ['id' => $tripId, 'code' => $code, 'stores' => count($ordered), 'pkt' => $packets, 'joint' => count($pick) > 1];
}
}
