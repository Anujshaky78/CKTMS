<?php
declare(strict_types=1); require_once __DIR__.'/../config/DB.php';
if(session_status()===PHP_SESSION_NONE)session_start();
if(isset($_SESSION['user_id'])) redirect('../pages/PLANNING.php?tab=prd_pending');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $u=trim((string)($_POST['username']??''));$p=(string)($_POST['password']??'');
 try{
 $d=db();
 if(tms_login_locked($d,$u)){ $error='Too many failed attempts. Please wait 10 minutes and try again.'; }
 else{
 $st=$d->prepare("SELECT * FROM users WHERE username=:u OR COALESCE(email,'')=:u LIMIT 1");$st->execute(['u'=>$u]);$x=$st->fetch();
 // Hashed passwords only (plaintext comparison removed; all existing users were already hashed).
 // A deactivated user (Masters > Users) cannot sign in; a missing/NULL flag counts as active.
 if($x && password_verify($p,(string)$x['password']) && ($x['active']??true)!==false){
  tms_login_clear($d,$u);
  if(password_needs_rehash((string)$x['password'],PASSWORD_DEFAULT)){ try{$d->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($p,PASSWORD_DEFAULT),$x['id']]);}catch(Throwable $e){} }
  session_regenerate_id(true);$_SESSION['user_id']=$x['id'];$_SESSION['user_name']=$x['full_name']??$x['name']??$x['username']??'User';$_SESSION['role']=strtolower(trim((string)($x['role']??'')))?:'dispatcher';redirect('../pages/PLANNING.php?tab=prd_pending');
 }else{ tms_login_fail($d,$u); $error='Invalid username or password.'; }
 }
 }catch(Throwable $e){ error_log('TMS login error: '.$e->getMessage()); $error='Sign-in is temporarily unavailable. Please try again.'; }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in | <?= e((string)(app_cfg('name') ?? 'Citykart TMS')) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<style>
body{overflow:auto;background:var(--canvas,#f2f4f7)}
.lg{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.lg-card{width:100%;max-width:360px;background:#fff;border:1px solid var(--line,#e4e7ec);border-radius:14px;padding:30px 30px 26px;box-shadow:0 12px 32px rgba(16,24,40,.08)}
.lg-logo{display:flex;align-items:center;gap:10px;margin:0 0 22px;font-weight:700;font-size:17px;color:#101828}
.lg-logo span{width:34px;height:34px;border-radius:9px;background:var(--blue,#1d4ed8);color:#fff;display:grid;place-items:center}.lg-logo svg{width:20px;height:20px}
.lg-card .fg{margin-bottom:14px}.lg-card label{display:block;font-size:12.5px;font-weight:600;color:#344054;margin-bottom:5px}
.lg-card .input{width:100%;height:40px;font-size:14px;box-sizing:border-box}
.lg-card .btn{width:100%;height:42px;font-size:14px;margin-top:6px}
.lg-err{background:#fef3f2;color:#912018;border:1px solid #fecdca;border-radius:8px;padding:9px 12px;font-size:12.5px;margin-bottom:14px}
</style></head><body>
<div class="lg">
  <form method="post" class="lg-card" autocomplete="on">
    <div class="lg-logo"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 6h13v10H1z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="5.5" cy="18" r="1.8"/><circle cx="17.5" cy="18" r="1.8"/></svg></span><?= e((string)(app_cfg('name') ?? 'Citykart TMS')) ?></div>
    <?php if ($error): ?><div class="lg-err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <div class="fg"><label for="u">Username</label><input class="input" id="u" name="username" value="<?= e($_POST['username'] ?? '') ?>" required autofocus autocomplete="username"></div>
    <div class="fg"><label for="p">Password</label><input class="input" id="p" type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn primary" type="submit">Sign in</button>
  </form>
</div>
</body></html>
