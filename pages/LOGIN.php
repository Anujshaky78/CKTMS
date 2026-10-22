<?php
declare(strict_types=1); require_once __DIR__.'/../config/DB.php';
if(session_status()===PHP_SESSION_NONE)session_start();
if(isset($_SESSION['user_id'])) redirect('../pages/INDEX.php');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $u=trim($_POST['username']??'');$p=$_POST['password']??'';
 try{$st=db()->prepare("SELECT * FROM users WHERE username=:u OR COALESCE(email,'')=:u LIMIT 1");$st->execute(['u'=>$u]);$x=$st->fetch();
 if($x && (password_verify($p,$x['password'])||hash_equals((string)$x['password'],$p))){session_regenerate_id(true);$_SESSION['user_id']=$x['id'];$_SESSION['user_name']=$x['full_name']??$x['name']??$x['username']??'User';$_SESSION['role']=$x['role']??'dispatcher';redirect('../pages/INDEX.php');}else $error='Invalid username or password.';
 }catch(Throwable $e){$error='Database error: '.$e->getMessage();}
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in | <?= e((string)(app_cfg('name') ?? 'Citykart TMS')) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<style>
body{overflow:auto;background:var(--nav)}
.lg{min-height:100vh;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(380px,.9fr)}
.lg-brand{padding:48px 56px;color:#c3cee0;display:flex;flex-direction:column;justify-content:space-between;background:linear-gradient(160deg,#0c1526 0%,#13254a 100%)}
.lg-logo{display:flex;align-items:center;gap:12px;color:#fff;font-weight:700;font-size:17px}
.lg-logo span{width:38px;height:38px;border-radius:10px;background:var(--blue);display:grid;place-items:center}
.lg-logo svg{width:22px;height:22px}
.lg-brand h1{color:#fff;font-size:32px;line-height:1.2;margin:0 0 12px;font-weight:700;max-width:460px;letter-spacing:-.01em}
.lg-brand p{margin:0;max-width:440px;line-height:1.6;font-size:14px}
.lg-flow{list-style:none;margin:28px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:8px;counter-reset:f}
.lg-flow li{counter-increment:f;font-size:12px;color:#dbe4f3;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:999px;padding:6px 12px}
.lg-flow li::before{content:counter(f) "  ";color:#8fb0ff;font-weight:700}
.lg-foot{font-size:11.5px;color:#7487a3}
.lg-form{background:var(--canvas);display:flex;align-items:center;justify-content:center;padding:32px}
.lg-card{width:100%;max-width:380px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:32px;box-shadow:var(--shadow-lg)}
.lg-card h2{margin:0 0 4px;font-size:20px}.lg-card .note{margin-bottom:22px;font-size:12.5px}
.lg-card .fg{margin-bottom:14px}.lg-card .input{width:100%;height:40px;font-size:14px}
.lg-card .btn{width:100%;height:42px;font-size:14px;margin-top:6px}
.lg-err{background:var(--red-soft);color:#912018;border:1px solid #fecdca;border-radius:8px;padding:9px 12px;font-size:12.5px;margin-bottom:14px}
@media(max-width:860px){.lg{grid-template-columns:1fr}.lg-brand{padding:28px 24px;gap:20px}.lg-brand h1{font-size:24px}.lg-foot{display:none}}
</style></head><body>
<div class="lg">
  <section class="lg-brand">
    <div class="lg-logo"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 6h13v10H1z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="5.5" cy="18" r="1.8"/><circle cx="17.5" cy="18" r="1.8"/></svg></span><?= e((string)(app_cfg('name') ?? 'Citykart TMS')) ?></div>
    <div>
      <h1>From pending challans to delivered stores.</h1>
      <p>Plan dispatch from Oracle PRD Pending, build trips of up to <?= (int)tms_trip_max_pkt() ?> PKT and <?= (int)tms_trip_max_stores() ?> stores, and track every store to delivery.</p>
      <ol class="lg-flow"><li>PRD Pending</li><li>Trip</li><li>Gate Pass</li><li>In Transit</li><li>Delivered</li></ol>
    </div>
    <div class="lg-foot">Transport management · <?= e((string)(app_cfg('company') ?? 'Citykart')) ?></div>
  </section>
  <section class="lg-form">
    <form method="post" class="lg-card" autocomplete="on">
      <h2>Sign in</h2><div class="note">Use your TMS username or email.</div>
      <?php if ($error): ?><div class="lg-err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <div class="fg"><label for="u">Username or email</label><input class="input" id="u" name="username" value="<?= e($_POST['username'] ?? '') ?>" required autofocus autocomplete="username"></div>
      <div class="fg"><label for="p">Password</label><input class="input" id="p" type="password" name="password" required autocomplete="current-password"></div>
      <button class="btn primary" type="submit">Sign in</button>
    </form>
  </section>
</div>
</body></html>
