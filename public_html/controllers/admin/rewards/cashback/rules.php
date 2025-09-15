<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// /views/admin/rewards/cashback/rules.php
declare(strict_types=1);

/* Bootstrap + session */
$bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path = dirname(__DIR__, 4) . '/config/db.php'; // cashback -> rewards -> admin -> views -> (..)-> config/db.php
if(!is_file($bootstrap_path)){ $bootstrap_warning='Configuration file not found: /config/db.php'; }
else{
  $prev=set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try{
    require_once $bootstrap_path;
    if(!function_exists('db')||!function_exists('use_backend_session')){
      $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';
    } else { $bootstrap_ok=true; }
  }catch(Throwable $e){ $bootstrap_warning='Bootstrap error: '.$e->getMessage(); }
  finally{ if($prev){ set_error_handler($prev); } }
}
if($bootstrap_ok){ try{ use_backend_session(); }catch(Throwable $e){ $bootstrap_warning=$bootstrap_warning?:('Session bootstrap error: '.$e->getMessage()); } }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId=(int)($user['tenant_id']??0);

/* Load existing cashback program for tenant */
$program=null; $db_msg='';
if($bootstrap_ok){
  try{
    $pdo=db();
    $st=$pdo->prepare("SELECT * FROM loyalty_programs WHERE tenant_id=:t AND program_type='cashback' LIMIT 1");
    $st->execute([':t'=>$tenantId]);
    $program=$st->fetch(PDO::FETCH_ASSOC);
  }catch(Throwable $e){ $db_msg=$e->getMessage(); }
}
$defaults = [
  'visit_window_days'=>15,
  'ladder'=>[
    ['visit'=>1,'earn_percent'=>10,'redeem_on_next'=>true],
    ['visit'=>2,'earn_percent'=>15,'redeem_on_next'=>true],
    ['visit'=>3,'earn_percent'=>20,'redeem_on_next'=>true],
  ],
  'amount_basis'=>'subtotal_excl_tax_service'
];
$earn_json = $program && !empty($program['earn_rule_json']) ? (string)$program['earn_rule_json'] : json_encode($defaults);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Cashback Rules · Rewards</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb}
*{box-sizing:border-box} body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto;color:var(--text)}
.container{max-width:900px;margin:20px auto;padding:0 16px}
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
.label{font-size:12px;color:var(--muted);margin-bottom:6px;display:block}
.input,.textarea{border:1px solid var(--border);border-radius:10px;padding:10px;background:#fff;width:100%}
.textarea{min-height:180px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:10px 14px;cursor:pointer;text-decoration:none;color:#111827}
.btn:hover{filter:brightness(.98)}
.btn-primary{background:var(--primary);color:#fff;border-color:#2563eb}
.small{color:var(--muted);font-size:12px}
.flash{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:10px;margin:10px 0;display:none}
.error{background:#fee2e2;border:1px solid #fecaca;color:#7f1d1d;padding:10px;border-radius:10px;margin:10px 0;display:none}
</style>
</head>
<body>

 $active='rewards'; require __DIR__.'/../../../partials/admin_nav.php'; ?>

<div class="container">
   if($bootstrap_warning): ?><div class="section small"><?= htmlspecialchars($bootstrap_warning,ENT_QUOTES,'UTF-8') ?></div> endif; ?>
   if($db_msg): ?><div class="section small">DEBUG: <?= htmlspecialchars($db_msg,ENT_QUOTES,'UTF-8') ?></div> endif; ?>

  <div class="section">
    <div class="h1">Cashback Rules</div>

    <p class="small">Define the visit ladder. Redeem is the exact amount earned on the previous visit, if used within the window (default 15 days). Basis = subtotal excluding tax & service.</p>

    <label class="label">Earn Rule JSON</label>
    <textarea id="earnJson" class="textarea"><?= htmlspecialchars($earn_json, ENT_QUOTES, 'UTF-8') ?></textarea>

    <div class="row" style="margin-top:10px">
      <button class="btn btn-primary" id="saveBtn">Save</button>
      <a class="btn" href="/views/admin/rewards/index.php">Back</a>
    </div>

    <div id="flashOk" class="flash">Saved.</div>
    <div id="flashErr" class="error">Error.</div>
  </div>
</div>

<script>
const saveBtn = document.getElementById('saveBtn');
const earnJson = document.getElementById('earnJson');
const okBox = document.getElementById('flashOk');
const errBox = document.getElementById('flashErr');

saveBtn.addEventListener('click', async ()=>{
  okBox.style.display='none'; errBox.style.display='none';
  let payload = {};
  try {
    payload = JSON.parse(earnJson.value);
  } catch(e) {
    errBox.textContent = 'Invalid JSON: ' + e.message;
    errBox.style.display='block';
    return;
  }
  const res = await fetch('/controllers/admin/rewards/cashback/rules_save.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ earn_rule: payload })
  });
  const data = await res.json().catch(()=>({ok:false,error:'Bad response'}));
  if (data.ok) {
    okBox.textContent = 'Saved.';
    okBox.style.display='block';
  } else {
    errBox.textContent = data.error || 'Error.';
    errBox.style.display='block';
  }
});
</script>
</body>
</html>