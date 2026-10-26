<?php
declare(strict_types=1);
/*
 * Vehicle tracking from exported GPS reports (G-TRAC "Vehicle Status Report", R-TRAC day summary).
 * The files are read from the paths in config/tracking.php; a changed file is imported once and every vehicle's latest data is kept in
 * tms_vehicle_tracking (one row per vehicle and source). No upload, no PHP zip extension needed.
 */

function tms_trk_cfg(): array { static $c = null; return $c ??= (array)require TMS_ROOT . '/config/tracking.php'; }
function tms_trk_tz(): DateTimeZone { return new DateTimeZone((string)(tms_trk_cfg()['timezone'] ?? 'Asia/Kolkata')); }
/** Wall-clock time of the GPS portals (IST), independent of the server's PHP timezone setting. */
function tms_trk_now(): int { return strtotime((new DateTime('now', tms_trk_tz()))->format('Y-m-d H:i:s')); }
function tms_trk_fmt(int $ts, string $fmt = 'd M, H:i'): string { return (new DateTime('@' . $ts))->setTimezone(tms_trk_tz())->format($fmt); }
function tms_trk_norm(string $no): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $no)); }

/** Minimal .xlsx reader (own zip reader + inflate, so the zip extension is not needed). Returns the first sheet as rows of strings. */
function tms_trk_zip_entries(string $path): array {
  $b = @file_get_contents($path); if ($b === false) throw new Exception("File cannot be read: $path");
  $n = strlen($b); $eocd = strrpos($b, "PK\x05\x06"); if ($eocd === false) throw new Exception('Not an .xlsx file (zip header missing): ' . basename($path));
  $cnt = unpack('v', substr($b, $eocd + 10, 2))[1]; $off = unpack('V', substr($b, $eocd + 16, 4))[1]; $out = [];
  for ($i = 0; $i < $cnt && $off + 46 <= $n; $i++) {
    $h = unpack('vmethod/x8/Vcsize/Vusize/vnl/vel/vcl', substr($b, $off + 10, 26)); $lho = unpack('V', substr($b, $off + 42, 4))[1];
    $name = substr($b, $off + 46, $h['nl']); $off += 46 + $h['nl'] + $h['el'] + $h['cl'];
    if (!preg_match('~^xl/(worksheets/sheet\d+\.xml|sharedStrings\.xml|workbook\.xml)$~', $name)) continue;
    $l = unpack('vnl/vel', substr($b, $lho + 26, 4)); $data = substr($b, $lho + 30 + $l['nl'] + $l['el'], $h['csize']);
    $out[$name] = $h['method'] === 8 ? (string)@gzinflate($data) : $data;
  }
  return $out;
}
function tms_trk_col(string $ref): int { preg_match('/^[A-Z]+/', $ref, $m); $s = $m[0] ?? 'A'; $n = 0; for ($i = 0; $i < strlen($s); $i++) $n = $n * 26 + (ord($s[$i]) - 64); return $n - 1; }
function tms_trk_read_xlsx(string $path): array {
  $z = tms_trk_zip_entries($path);
  $sheetName = null; foreach (array_keys($z) as $k) if (str_starts_with($k, 'xl/worksheets/')) { if ($sheetName === null || strnatcmp($k, $sheetName) < 0) $sheetName = $k; }
  if ($sheetName === null || $z[$sheetName] === '') throw new Exception('No worksheet found in ' . basename($path));
  $ss = [];
  if (!empty($z['xl/sharedStrings.xml']) && ($x = @simplexml_load_string($z['xl/sharedStrings.xml']))) foreach ($x->si as $si) { $t = ''; if (isset($si->t)) $t = (string)$si->t; else foreach ($si->r as $r) $t .= (string)$r->t; $ss[] = $t; }
  $sx = @simplexml_load_string($z[$sheetName]); if (!$sx) throw new Exception('Worksheet cannot be read in ' . basename($path));
  $rows = [];
  foreach ($sx->sheetData->row as $r) {
    $row = [];
    foreach ($r->c as $c) {
      $i = tms_trk_col((string)$c['r']); $t = (string)$c['t']; $v = (string)$c->v;
      if ($t === 's') $v = $ss[(int)$v] ?? ''; elseif ($t === 'inlineStr') $v = (string)$c->is->t;
      $row[$i] = trim(preg_replace('/\s+/', ' ', $v));
    }
    if ($row) { $max = max(array_keys($row)); $full = []; for ($i = 0; $i <= $max; $i++) $full[] = $row[$i] ?? ''; $rows[] = $full; }
  }
  return $rows;
}

function tms_trk_cut(string $s, int $n): string { return mb_substr($s, 0, $n); }
function tms_trk_time(string $s): ?string { $s = trim($s); if ($s === '' || strtolower($s) === 'undefined') return null; try { return (new DateTime($s, tms_trk_tz()))->format('Y-m-d H:i:s'); } catch (Throwable $e) { return null; } }
function tms_trk_num(string $s): ?float { $s = trim(str_replace(',', '', $s)); return is_numeric($s) ? (float)$s : null; }
function tms_trk_mins(string $s): ?int { if (!preg_match('/^\s*(?:(\d+)\s*H)?\s*(?:(\d+)\s*M)?\s*$/i', trim($s), $m) || trim($s) === '') return null; return (int)($m[1] ?? 0) * 60 + (int)($m[2] ?? 0); }
/** Text that was saved twice as UTF-8 (Hindi shows as "à¤¬à¤¾...") is turned back into the real text. */
function tms_trk_fix_text(string $s): string {
  if (!preg_match('/[\x{00C2}-\x{00F4}][\x{0080}-\x{00BF}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}\x{2013}\x{2014}\x{2018}-\x{201E}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}]/u', $s)) return $s;
  $b = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
  return ($b !== false && $b !== '' && mb_check_encoding($b, 'UTF-8') && $b !== $s) ? $b : $s;
}
function tms_trk_place(string $s): ?string { $s = tms_trk_fix_text(trim($s)); return ($s === '' || strtolower($s) === 'undefined') ? null : mb_substr($s, 0, 400); }

/** One row of tms_vehicle_tracking; the SQL follows the keys given (a G-TRAC row and an R-TRAC row carry different columns). */
function tms_trk_upsert(PDO $d, array $row): void {
  static $st = []; $cols = array_keys($row); $k = implode(',', $cols);
  if (!isset($st[$k])) {
    $set = [];
    foreach ($cols as $c) {
      if ($c === 'vehicle_number' || $c === 'source') continue;
      if ($c === 'location') $set[] = 'location=COALESCE(EXCLUDED.location, tms_vehicle_tracking.location)';   // the portal sometimes sends no address: keep the last known one
      elseif ($c === 'loc_time') $set[] = 'loc_time=CASE WHEN EXCLUDED.location IS NOT NULL THEN EXCLUDED.loc_time ELSE tms_vehicle_tracking.loc_time END';
      else $set[] = "$c=EXCLUDED.$c";
    }
    $st[$k] = $d->prepare("INSERT INTO tms_vehicle_tracking($k,imported_at) VALUES(" . implode(',', array_fill(0, count($cols), '?')) . ",CURRENT_TIMESTAMP) ON CONFLICT(vehicle_number,source) DO UPDATE SET " . implode(',', $set) . ",imported_at=CURRENT_TIMESTAMP");
  }
  $st[$k]->execute(array_values($row));
}
/** "26.24548,81.594479" -> ['lat','lng'] (only points inside India's box, so a swapped or empty cell is ignored). */
function tms_trk_coords(string $s): ?array {
  if (!preg_match('/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/', $s, $m)) return null; $la = (float)$m[1]; $ln = (float)$m[2];
  if ($la < 6 || $la > 38 || $ln < 66 || $ln > 98) { if ($ln >= 6 && $ln <= 38 && $la >= 66 && $la <= 98) { [$la, $ln] = [$ln, $la]; } else return null; }
  return ['lat' => round($la, 7), 'lng' => round($ln, 7)];
}

/** Imports one configured file. Returns [vehicles, matched] or throws. Understands the G-TRAC status report and both R-TRAC layouts (one row per vehicle, or one row per trip with start / end coordinates). */
function tms_trk_import_file(PDO $d, string $source, string $path): array {
  $rows = tms_trk_read_xlsx($path);
  $nk = fn(string $x): string => trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($x)));   // header names compare without case, dots, brackets or extra spaces
  $VN = ['vehicle no', 'vehicle number', 'vehicle', 'device name', 'vehicle name', 'reg no'];   // every name the vehicle column has had
  $hi = null; foreach ($rows as $i => $r) { if (array_intersect(array_map($nk, $r), $VN)) { $hi = $i; break; } }
  if ($hi === null) throw new Exception(basename($path) . ': header row (Vehicle No. / Device Name) not found.');
  $h = []; foreach ($rows[$hi] as $i => $n) if ($n !== '') $h[$nk($n)] = $i;
  $g = function (array $r, string ...$names) use ($h, $nk): string { foreach ($names as $n) { $n = $nk($n); if (isset($h[$n])) return (string)($r[$h[$n]] ?? ''); } return ''; };
  $veh = []; foreach ($d->query("SELECT id, vehicle_number FROM vehicles")->fetchAll(PDO::FETCH_ASSOC) as $v) $veh[tms_trk_norm((string)$v['vehicle_number'])] = (int)$v['id'];
  $data = array_slice($rows, $hi + 1);
  $segMode = isset($h['start coords']) || isset($h['end coords']) || isset($h['start coordinates']);
  $out = [];   // vehicle number => row for tms_vehicle_tracking
  if ($segMode) {
    // trip-wise report: group the trips of a vehicle; the last trip's end is where the vehicle is now
    $by = [];
    foreach ($data as $r) { $no = strtoupper(trim($g($r, ...$VN))); if ($no === '' || strtolower($no) === 'total') continue; $by[$no][] = $r; }
    foreach ($by as $no => $segs) {
      usort($segs, fn($a, $b) => strcmp((string)tms_trk_time($g($a, 'end time')), (string)tms_trk_time($g($b, 'end time'))));
      $first = $segs[0]; $last = end($segs); $km = 0.0; $mins = 0; $wait = 0; $maxs = null; $trail = []; $any = false;
      $p0 = tms_trk_coords($g($first, 'start coords')); if ($p0) $trail[] = [round($p0['lat'], 5), round($p0['lng'], 5)];
      foreach ($segs as $sg) {
        $km += (float)(tms_trk_num($g($sg, 'distance (kms)')) ?? 0); $mins += (int)(tms_trk_mins($g($sg, 'duration')) ?? 0); $wait += (int)(tms_trk_mins($g($sg, 'waiting time for next trip')) ?? 0);
        $ms = tms_trk_num($g($sg, 'max speed (km/h)', 'max speed')); if ($ms !== null && ($maxs === null || $ms > $maxs)) $maxs = $ms;
        $pe = tms_trk_coords($g($sg, 'end coords')); if ($pe) { $trail[] = [round($pe['lat'], 5), round($pe['lng'], 5)]; $any = true; }
      }
      if (count($trail) > 80) { $step = count($trail) / 80; $t2 = []; for ($i = 0; $i < 80; $i++) $t2[] = $trail[(int)floor($i * $step)]; $t2[] = end($trail); $trail = $t2; }
      $pe = tms_trk_coords($g($last, 'end coords')); $ps = $p0;
      $out[$no] = ['vehicle_number' => $no, 'source' => $source, 'day_km' => round($km, 2), 'avg_speed' => $mins > 0 ? round($km / ($mins / 60), 1) : null, 'max_speed' => $maxs, 'motion_min' => $mins, 'idle_min' => $wait ?: null,
        'start_address' => tms_trk_place($g($first, 'start address')), 'end_address' => tms_trk_place($g($last, 'end address')), 'report_from' => tms_trk_time($g($first, 'start time')), 'report_to' => tms_trk_time($g($last, 'end time')),
        'first_ignition' => tms_trk_time($g($first, 'start time')), 'last_ignition' => tms_trk_time($g($last, 'end time')),
        'lat' => $pe['lat'] ?? null, 'lng' => $pe['lng'] ?? null, 'start_lat' => $ps['lat'] ?? null, 'start_lng' => $ps['lng'] ?? null, 'trail' => $trail ? json_encode($trail) : null,
        'driver_name' => tms_trk_cut(trim($g($last, 'driver name')), 120) ?: null, 'driver_phone' => ($dm = preg_replace('/\D/', '', $g($last, 'driver mobile'))) !== '' ? tms_trk_cut($dm, 15) : null];
    }
  } else {
    foreach ($data as $r) {
      $no = strtoupper(trim($g($r, ...$VN))); if ($no === '' || strtolower($no) === 'total') continue;
      $dph = preg_replace('/\D/', '', $g($r, "driver's number")); $gt = tms_trk_time($g($r, 'gps time')); $loc = tms_trk_place($g($r, 'location'));
      $out[$no] = ['vehicle_number' => $no, 'source' => $source, 'gps_time' => $gt, 'location' => $loc, 'loc_time' => $loc !== null ? $gt : null, 'speed' => tms_trk_num($g($r, 'speed')), 'ignition' => tms_trk_cut(trim($g($r, 'ignition state')), 10) ?: null,
        'status' => tms_trk_cut(strtoupper(trim($g($r, 'status'))), 30) ?: null, 'halted_since' => tms_trk_time($g($r, 'halted since')), 'yesterday_km' => tms_trk_num($g($r, 'yesterday km')), 'fuel' => tms_trk_num($g($r, 'current fuel')),
        'driver_name' => tms_trk_cut(trim($g($r, "driver's name")), 120) ?: null, 'driver_phone' => $dph !== '' ? tms_trk_cut($dph, 15) : null,
        'day_km' => tms_trk_num($g($r, 'distance (kms)')), 'avg_speed' => tms_trk_num($g($r, 'avg speed')), 'max_speed' => tms_trk_num($g($r, 'max speed')), 'motion_min' => tms_trk_mins($g($r, 'motion time')), 'idle_min' => tms_trk_mins($g($r, 'idle time')), 'stop_min' => tms_trk_mins($g($r, 'stop time')),
        'start_address' => tms_trk_place($g($r, 'start address')), 'end_address' => tms_trk_place($g($r, 'end address')), 'first_ignition' => tms_trk_time($g($r, 'first ignition')), 'last_ignition' => tms_trk_time($g($r, 'last ignition')),
        'report_from' => tms_trk_time($g($r, 'start time')), 'report_to' => tms_trk_time($g($r, 'end time'))];
    }
  }
  $matched = 0; $d->beginTransaction();
  try {
    foreach ($out as $no => $row) { $row['vehicle_id'] = $veh[tms_trk_norm($no)] ?? null; if ($row['vehicle_id']) $matched++; tms_trk_upsert($d, $row); }
    $d->commit();
  } catch (Throwable $e) { if ($d->inTransaction()) $d->rollBack(); throw $e; }
  return [count($out), $matched];
}
/** Imports every configured file whose size / modified time changed since the last import (or all with $force). Never throws. */
function tms_trk_import(PDO $d, bool $force = false): array {
  $res = ['ok' => true, 'files' => [], 'message' => ''];
  foreach ((array)(tms_trk_cfg()['files'] ?? []) as $f) {
    $src = (string)$f['source']; $path = (string)$f['path'];
    try {
      if (!is_file($path)) throw new Exception('file not found: ' . $path);
      $sig = md5_file($path) . '|' . filesize($path);   // content, not the file time: copying or overwriting a file can keep an old modified time
      $mk = 'trk_file:' . $src; $q = $d->prepare("SELECT v FROM tms_meta WHERE k=?"); $q->execute([$mk]);
      if (!$force && $q->fetchColumn() === $sig) { $res['files'][$src] = 'unchanged'; continue; }
      [$n, $m] = tms_trk_import_file($d, $src, $path);
      $d->prepare("INSERT INTO tms_meta(k,v,updated_at) VALUES(?,?,CURRENT_TIMESTAMP) ON CONFLICT(k) DO UPDATE SET v=EXCLUDED.v,updated_at=CURRENT_TIMESTAMP")->execute([$mk, $sig]);
      $res['files'][$src] = "$n vehicle(s), $m in the vehicle master";
    } catch (Throwable $e) { $res['ok'] = false; $res['files'][$src] = 'failed: ' . $e->getMessage(); }
  }
  $res['message'] = implode(' · ', array_map(fn($k, $v) => "$k: $v", array_keys($res['files']), $res['files']));
  if ($res['ok'] && !array_filter($res['files'], fn($v) => $v !== 'unchanged')) return $res;   // nothing to report: no database write on every page open
  try { $d->prepare("INSERT INTO tms_meta(k,v,updated_at) VALUES('trk_last',?,CURRENT_TIMESTAMP) ON CONFLICT(k) DO UPDATE SET v=EXCLUDED.v,updated_at=CURRENT_TIMESTAMP")->execute([($res['ok'] ? 'ok|' : 'bad|') . $res['message']]); } catch (Throwable $e) {}
  return $res;
}
/** Cheap check on page open: re-imports only when a file changed (a stat() per file). */
function tms_trk_autoimport(PDO $d): void { static $done = false; if ($done) return; $done = true; try { tms_trk_import($d, false); } catch (Throwable $e) {} }

/** Latest merged data per vehicle: key = normalised vehicle number. G-TRAC gives the live point, R-TRAC the day summary and last address. */
function tms_trk_latest(PDO $d): array {
  $cfg = tms_trk_cfg(); $now = tms_trk_now(); $out = [];
  try { $rows = $d->query("SELECT * FROM tms_vehicle_tracking")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
  foreach ($rows as $r) {
    $k = tms_trk_norm((string)$r['vehicle_number']); $o = &$out[$k];
    $o ??= ['vehicle_number' => $r['vehicle_number'], 'vehicle_id' => $r['vehicle_id'], 'sources' => [], 'time' => null, 'location' => null, 'speed' => null, 'ignition' => null, 'status' => null, 'halted_since' => null,
            'day_km' => null, 'yesterday_km' => null, 'max_speed' => null, 'avg_speed' => null, 'idle_min' => null, 'stop_min' => null, 'motion_min' => null, 'driver_name' => null, 'driver_phone' => null, 'start_address' => null, 'fuel' => null, 'loc_time' => null, 'lat' => null, 'lng' => null, 'start_lat' => null, 'start_lng' => null, 'trail' => null];
    $o['sources'][] = $r['source'];
    $t = $r['gps_time'] ?: ($r['last_ignition'] ?: $r['report_to']); $loc = $r['location'] ?: $r['end_address']; $lt = $r['location'] ? ($r['loc_time'] ?: $r['gps_time']) : $t;
    if ($r['gps_time']) { // live point
      foreach (['speed', 'ignition', 'status', 'halted_since', 'yesterday_km', 'driver_name', 'driver_phone', 'fuel'] as $f) if ($r[$f] !== null) $o[$f] = $r[$f];
      if ($t && (!$o['time'] || strtotime((string)$t) >= strtotime((string)$o['time']))) { $o['time'] = $t; if ($loc) { $o['location'] = $loc; $o['loc_time'] = $lt; $o['lat'] = $r['lat'] ?? null; $o['lng'] = $r['lng'] ?? null; } }
      elseif ($loc && !$o['location']) { $o['location'] = $loc; $o['loc_time'] = $lt; $o['lat'] = $r['lat'] ?? null; $o['lng'] = $r['lng'] ?? null; }
    } else {     // day summary
      foreach (['day_km', 'avg_speed', 'max_speed', 'idle_min', 'stop_min', 'motion_min', 'start_address', 'start_lat', 'start_lng', 'trail'] as $f) if ($r[$f] !== null) $o[$f] = $r[$f];
      if ($loc && !$o['location']) { $o['location'] = $loc; $o['loc_time'] = $lt; $o['lat'] = $r['lat'] ?? null; $o['lng'] = $r['lng'] ?? null; if (!$o['time']) $o['time'] = $t; }
      if (!$o['time'] && $t) $o['time'] = $t;
      if (!$o['status'] && $r['day_km'] !== null) $o['status'] = ((float)$r['day_km'] > 0) ? 'DAY REPORT' : 'NO MOVEMENT';
    }
    unset($o);
  }
  foreach ($out as &$o) {
    $age = $o['time'] ? (int)floor(($now - strtotime((string)$o['time'])) / 60) : null; $o['age_min'] = $age;
    $o['loc_age_min'] = $o['loc_time'] ? max(0, (int)floor(($now - strtotime((string)$o['loc_time'])) / 60)) : null;
    $o['loc_old'] = $o['loc_age_min'] !== null && $age !== null && $o['loc_age_min'] - $age > 10;   // the address is older than the GPS point
    $o['fresh'] = $age === null ? 'none' : ($age >= (int)$cfg['lost_hours'] * 60 ? 'lost' : ($age >= (int)$cfg['stale_min'] ? 'old' : 'ok'));
    $s = strtoupper((string)$o['status']); $o['state'] = $o['fresh'] === 'lost' ? 'NO SIGNAL' : ($s !== '' ? $s : '—');
  }
  unset($o); return $out;
}
function tms_trk_age(?int $min): string { if ($min === null) return '—'; if ($min < 1) return 'just now'; if ($min < 60) return $min . ' min ago'; if ($min < 1440) return floor($min / 60) . ' h ' . ($min % 60) . ' m ago'; return floor($min / 1440) . ' d ago'; }
function tms_trk_dur(?int $min): string { return $min === null ? '—' : floor($min / 60) . ' h ' . ($min % 60) . ' m'; }

/** Distance in km between two points (haversine). */
function tms_trk_km(array $a, array $b): float {
  $r = 6371.0; $p1 = deg2rad($a['lat']); $p2 = deg2rad($b['lat']); $dp = $p2 - $p1; $dl = deg2rad($b['lng'] - $a['lng']);
  $x = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2; return 2 * $r * asin(min(1, sqrt($x)));
}
/** One OpenStreetMap Nominatim request, one per second across every page and user (a busy service answers 429). Returns [hit|null, failed?]. */
function tms_trk_nominatim(string $qs): array {
  static $gate = null; if ($gate === null) $gate = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tms_nominatim.gate', 'c+') ?: false;
  if ($gate && flock($gate, LOCK_EX)) { rewind($gate); $last = (float)stream_get_contents($gate); $wait = 1.1 - (microtime(true) - $last); if ($wait > 0) usleep((int)($wait * 1e6)); }
  $ch = curl_init('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=in&' . $qs);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => 'CitykartTMS/1.0 (vehicle position on trip map)', CURLOPT_HTTPHEADER => ['Accept-Language: en']]);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($gate) { ftruncate($gate, 0); rewind($gate); fwrite($gate, (string)microtime(true)); fflush($gate); flock($gate, LOCK_UN); }
  $j = ($r !== false && $code === 200) ? json_decode((string)$r, true) : null;
  if (!is_array($j)) return [null, true];
  return [!empty($j[0]['lat']) ? ['lat' => (float)$j[0]['lat'], 'lng' => (float)$j[0]['lon']] : null, false];
}
function tms_trk_geo_store(PDO $d, string $key, string $addr, ?array $hit): void {
  $d->prepare("INSERT INTO tms_geocache(addr_key,address,lat,lng,created_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(addr_key) DO UPDATE SET lat=EXCLUDED.lat,lng=EXCLUDED.lng,created_at=CURRENT_TIMESTAMP")
    ->execute([$key, mb_substr($addr, 0, 500), $hit['lat'] ?? null, $hit['lng'] ?? null]);
}
/** Centre of a 6-digit PIN code: our own stores with that PIN first (instant), else OpenStreetMap. [point|null, failed?] */
function tms_trk_pin_point(PDO $d, string $pin): array {
  $sq = $d->prepare("SELECT AVG(latitude) la, AVG(longitude) lo, COUNT(*) n FROM stores WHERE pincode=? AND COALESCE(latitude,0)<>0 AND COALESCE(longitude,0)<>0"); $sq->execute([$pin]); $sr = $sq->fetch(PDO::FETCH_ASSOC);
  if ($sr && (int)$sr['n'] > 0) return [['lat' => (float)$sr['la'], 'lng' => (float)$sr['lo']], false];
  $key = md5('pin:' . $pin); $q = $d->prepare("SELECT lat, lng FROM tms_geocache WHERE addr_key=?"); $q->execute([$key]); $c = $q->fetch(PDO::FETCH_ASSOC);
  if ($c && $c['lat'] !== null) return [['lat' => (float)$c['lat'], 'lng' => (float)$c['lng']], false];
  [$hit, $failed] = tms_trk_nominatim('postalcode=' . $pin);
  if ($hit) tms_trk_geo_store($d, $key, 'PIN ' . $pin, $hit);
  return [$hit, $failed];
}

/**
 * Coordinates of an address that the GPS reports give only as text. As exact as the text allows: road / village level when OpenStreetMap finds
 * the address near its PIN code (a road name alone often matches a wrong road far away, so a result more than 40 km from the PIN is thrown away),
 * otherwise the centre of the PIN code area. Answers are cached in tms_geocache; a service error or busy service is never remembered, a real
 * "not found" for 30 minutes. Returns ['lat'=>float,'lng'=>float] or null.
 */
function tms_trk_geocode(PDO $d, ?string $addr): ?array {
  $addr = trim((string)$addr); if ($addr === '' || strtolower($addr) === 'undefined') return null;
  $key = md5(mb_strtolower($addr));
  $q = $d->prepare("SELECT lat, lng, created_at FROM tms_geocache WHERE addr_key=?"); $q->execute([$key]); $c = $q->fetch(PDO::FETCH_ASSOC);
  if ($c) { if ($c['lat'] !== null) return ['lat' => (float)$c['lat'], 'lng' => (float)$c['lng']]; if (strtotime((string)$c['created_at']) > time() - 1800) return null; }
  $pin = preg_match_all('/\b(\d{6})\b/', $addr, $pm) ? end($pm[1]) : null;   // the last 6-digit number is the one printed after the state $pinPt = null; $failed = false;
  if ($pin) [$pinPt, $f1] = tms_trk_pin_point($d, $pin); else $f1 = false;
  $failed = $f1;
  // address parts without the country and without the "State PIN" part: street, locality …
  $parts = array_values(array_filter(array_map('trim', explode(',', $addr)), fn($x) => $x !== '' && strcasecmp($x, 'india') !== 0));
  $state = ''; if ($parts && preg_match('/^(.*?)\s*\d{6}$/', end($parts), $sm)) { $state = trim($sm[1]); array_pop($parts); }
  $queries = [];
  if (count($parts) >= 2) $queries[] = implode(', ', array_slice($parts, 0, 2)) . ($state !== '' ? ', ' . $state : '');   // street, locality, state
  if ($parts) $queries[] = implode(', ', array_slice($parts, -1)) . ($state !== '' ? ', ' . $state : '');                  // locality (village / town), state
  $hit = null;
  foreach (array_slice(array_unique($queries), 0, 2) as $qtext) {
    [$cand, $fl] = tms_trk_nominatim('q=' . rawurlencode($qtext . ', India')); if ($fl) $failed = true;
    if ($cand && (!$pinPt || tms_trk_km($pinPt, $cand) <= 40)) { $hit = $cand; break; }
  }
  if (!$hit) $hit = $pinPt;                       // area centre of the PIN code
  if (!$hit && !$pin && $parts) { [$cand, $fl] = tms_trk_nominatim('q=' . rawurlencode(implode(', ', $parts) . ', India')); if ($fl) $failed = true; $hit = $cand; }
  if (!$hit && $failed) return null;              // do not remember a failure caused by the service being busy or unreachable
  if ($hit) { $hit = ['lat' => round($hit['lat'], 7), 'lng' => round($hit['lng'], 7)]; }
  if ($hit && $failed) return $hit;               // a coarser answer while the service was busy: show it, but look again next time
  tms_trk_geo_store($d, $key, $addr, $hit);
  return $hit;
}

/** A position already known for this exact address (road level or PIN area), without any lookup. */
function tms_trk_cache_get(PDO $d, ?string $addr): ?array {
  $addr = trim((string)$addr); if ($addr === '') return null;
  $q = $d->prepare("SELECT lat, lng FROM tms_geocache WHERE addr_key=? AND lat IS NOT NULL"); $q->execute([md5(mb_strtolower($addr))]); $c = $q->fetch(PDO::FETCH_ASSOC);
  return $c ? ['lat' => (float)$c['lat'], 'lng' => (float)$c['lng']] : null;
}
/** True when a lookup for this address failed recently with "not found" (so it is pointless to wait for one). */
function tms_trk_cache_missing(PDO $d, ?string $addr): bool {
  $q = $d->prepare("SELECT created_at FROM tms_geocache WHERE addr_key=? AND lat IS NULL"); $q->execute([md5(mb_strtolower(trim((string)$addr)))]); $t = $q->fetchColumn();
  return $t !== false && strtotime((string)$t) > time() - 1800;
}
/**
 * Instant, offline estimate of where an address is: the centre of its PIN code, from the stores of our store master that have that PIN.
 * Coarser than a lookup, but available the moment the address is; the exact answer follows from the background job. (Town names are not guessed: a wrong place is worse than none.)
 */
function tms_trk_geocode_local(PDO $d, ?string $addr): ?array {
  $addr = trim((string)$addr); if ($addr === '' || strtolower($addr) === 'undefined') return null;
  if (preg_match_all('/\b(\d{6})\b/', $addr, $pm)) {
    $sq = $d->prepare("SELECT AVG(latitude) la, AVG(longitude) lo, COUNT(*) n FROM stores WHERE pincode=? AND COALESCE(latitude,0)<>0 AND COALESCE(longitude,0)<>0"); $sq->execute([end($pm[1])]); $sr = $sq->fetch(PDO::FETCH_ASSOC);
    if ($sr && (int)$sr['n'] > 0) return ['lat' => round((float)$sr['la'], 7), 'lng' => round((float)$sr['lo'], 7)];
  }
  return null;
}

/**
 * What the browser needs to look an address up itself (the web server cannot reach OpenStreetMap, the browser can): the PIN code, the centre of that PIN
 * when we already know it (our stores / an earlier lookup), and the free-text queries to try, most specific first. Results are validated against the PIN centre.
 */
function tms_trk_geo_plan(PDO $d, string $addr): array {
  $pin = preg_match_all('/\b(\d{6})\b/', $addr, $pm) ? end($pm[1]) : null; $pinPt = null;
  if ($pin) {
    $sq = $d->prepare("SELECT AVG(latitude) la, AVG(longitude) lo, COUNT(*) n FROM stores WHERE pincode=? AND COALESCE(latitude,0)<>0 AND COALESCE(longitude,0)<>0"); $sq->execute([$pin]); $sr = $sq->fetch(PDO::FETCH_ASSOC);
    if ($sr && (int)$sr['n'] > 0) $pinPt = ['lat' => round((float)$sr['la'], 7), 'lng' => round((float)$sr['lo'], 7)];
    else $pinPt = tms_trk_cache_get($d, 'PIN ' . $pin) ?: null;
  }
  $parts = array_values(array_filter(array_map('trim', explode(',', $addr)), fn($x) => $x !== '' && strcasecmp($x, 'india') !== 0));
  $state = ''; if ($parts && preg_match('/^(.*?)\s*\d{6}$/', end($parts), $sm)) { $state = trim($sm[1]); array_pop($parts); }
  $queries = [];
  if (count($parts) >= 2) $queries[] = implode(', ', array_slice($parts, 0, 2)) . ($state !== '' ? ', ' . $state : '');
  if ($parts) $queries[] = end($parts) . ($state !== '' ? ', ' . $state : '');
  return ['address' => $addr, 'pin' => $pin, 'pin_pt' => $pinPt, 'queries' => array_values(array_unique($queries))];
}
/** A position found by the browser, kept for this address (only addresses that are in the tracking table, and only points inside India's box). */
function tms_trk_cache_put(PDO $d, string $addr, float $lat, float $lng): bool {
  if ($lat < 6 || $lat > 38 || $lng < 66 || $lng > 98) return false;
  $q = $d->prepare("SELECT 1 FROM tms_vehicle_tracking WHERE location=? OR end_address=? OR start_address=? LIMIT 1"); $q->execute([$addr, $addr, $addr]); if (!$q->fetchColumn()) return false;
  tms_trk_geo_store($d, md5(mb_strtolower(trim($addr))), $addr, ['lat' => round($lat, 7), 'lng' => round($lng, 7)]); return true;
}
function tms_trk_pin_put(PDO $d, string $pin, float $lat, float $lng): bool {
  if (!preg_match('/^\d{6}$/', $pin) || $lat < 6 || $lat > 38 || $lng < 66 || $lng > 98) return false;
  tms_trk_geo_store($d, md5('pin:' . $pin), 'PIN ' . $pin, ['lat' => round($lat, 7), 'lng' => round($lng, 7)]); return true;
}