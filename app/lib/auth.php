<?php
declare(strict_types=1);
if (!function_exists('require_login')) {
  function require_login(): void { if (empty($_SESSION['user_id'])) { header('Location: LOGIN.php'); exit; } }
}

/* ---------------- Roles ----------------
 * admin      everything
 * dispatcher view + plan (indents, trips, PRD, dispatch) + load / gate pass + track (in transit / delivered)
 * picker     view + load (load confirmation, gate pass)
 * auditor    view only
 * Not allowed for dispatcher: delete, master (stores/vehicles/drivers/DCs/warehouses), sync (Oracle).
 * A role that is not listed here is read-only. Everyone who is logged in can 'view'.
 */
if (!function_exists('tms_role')) {
  function tms_role(): string { $r = strtolower(trim((string)($_SESSION['role'] ?? ''))); return $r !== '' ? $r : 'dispatcher'; }
  function tms_role_perms(): array {
    return [
      'admin'      => ['*'],
      'dispatcher' => ['view', 'plan', 'load', 'track'],
      'picker'     => ['view', 'load'],
      'auditor'    => ['view'],
    ];
  }
  function can(string $perm): bool {
    $p = tms_role_perms()[tms_role()] ?? ['view'];
    return in_array('*', $p, true) || in_array($perm, $p, true);
  }
  /** Stops the request with HTTP 403 when the logged-in role lacks $perm. */
  function require_perm(string $perm, bool $json = false): void {
    if (can($perm)) return;
    http_response_code(403);
    $msg = 'Aapke role (' . tms_role() . ') ko ye action allowed nahi hai.';
    if ($json) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => $msg]); }
    else echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    exit;
  }
}

/* ---------------- Login throttle ----------------
 * 5 failed sign-ins per username+IP, or 20 per IP, lock further attempts until 10 minutes after the last failure.
 * Counters live in tms_meta (k = 'lf:<md5>' / 'lfi:<md5>'), so clearing cookies does not reset them.
 */
if (!function_exists('tms_login_keys')) {
  function tms_login_keys(string $user): array {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return ['user' => 'lf:' . md5(strtolower($user) . '|' . $ip), 'ip' => 'lfi:' . md5($ip)];
  }
  function tms_login_count(PDO $d, string $k): int {
    $q = $d->prepare("SELECT v FROM tms_meta WHERE k=? AND updated_at > CURRENT_TIMESTAMP - interval '10 minutes'");
    $q->execute([$k]); $v = $q->fetchColumn();
    return $v === false ? 0 : (int)$v;
  }
  function tms_login_locked(PDO $d, string $user): bool {
    $k = tms_login_keys($user);
    return tms_login_count($d, $k['user']) >= 5 || tms_login_count($d, $k['ip']) >= 20;
  }
  function tms_login_fail(PDO $d, string $user): void {
    $sql = "INSERT INTO tms_meta(k,v,updated_at) VALUES(?,'1',CURRENT_TIMESTAMP)
            ON CONFLICT(k) DO UPDATE SET v=(CASE WHEN tms_meta.updated_at > CURRENT_TIMESTAMP - interval '10 minutes' THEN (tms_meta.v::int + 1)::text ELSE '1' END), updated_at=CURRENT_TIMESTAMP";
    foreach (tms_login_keys($user) as $k) $d->prepare($sql)->execute([$k]);
  }
  function tms_login_clear(PDO $d, string $user): void {
    $d->prepare("DELETE FROM tms_meta WHERE k=?")->execute([tms_login_keys($user)['user']]);
  }
}
