<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// public_html/views/admin/rewards/cashback/rules.php
declare(strict_types=1);

/* Bootstrap */
$bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path=dirname(__DIR__,4).'/config/db.php';
if(!is_file($bootstrap_path)){ $bootstrap_warning='Configuration file not found: /config/db.php'; }
else{ $prev=set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
  try{ require_once $bootstrap_path; if(function_exists('db')&&function_exists('use_backend_session')){ $bootstrap_ok=true; use_backend_session(); }
  else{ $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';}}
  catch(Throwable $e){ $bootstrap_warning='Bootstrap error: '.$e->getMessage(); } finally{ if($prev) set_error_handler($prev); } }
if(!$bootstrap_ok){ echo "<h1>Cashback – Rules</h1><div style='color:red;'>".htmlspecialchars($bootstrap_warning)."</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id'];

/* DB */
try { $db = db(); } catch (Throwable $e) { http_response_code(500); echo 'DB error'; exit; }
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* Load current cashback program (latest active or latest any) & prefill JSON */
$prefJson = '';
try {
  $pid = (int)($_GET['program_id'] ?? 0);
  if ($pid <= 0) {
    $st = $db->prepare("SELECT id FROM loyalty_programs WHERE tenant_id=? AND program_type='cashback' AND status='active' ORDER BY id DESC LIMIT 1");
    $st->execute([$tenantId]);
    $pid = (int)($st->fetchColumn() ?: 0);
    if ($pid <= 0) {
      $st = $db->prepare("SELECT id FROM loyalty_programs WHERE tenant_id=? AND program_type='cashback' ORDER BY id DESC LIMIT 1");
      $st->execute([$tenantId]);
      $pid = (int)($st->fetchColumn() ?: 0);
    }
  }
  if ($pid > 0) {
    $s = $db->prepare("SELECT earn_rule_json FROM loyalty_programs WHERE id=? AND tenant_id=?");
    $s->execute([$pid, $tenantId]);
    $prefJson = (string)($s->fetchColumn() ?: '');
  }
} catch (Throwable $e) {}

$page_title="Rewards · Cashback · Rules";
include dirname(__DIR__,3).'/partials/admin_header.php';

function cashback_tabs(string $active): void { $b='/views/admin/rewards/cashback';
  $t=['overview'=>['Overview',"$b/overview.php"],'rules'=>['Rules',"$b/rules.php"],'ledger'=>['Ledger',"$b/ledger.php"],'wallets'=>['Wallets',"$b/wallets.php"],'adjust'=>['Adjustments',"$b/adjustments.php"],'reports'=>['Reports',"$b/reports.php"]];
  echo '<ul class="nav nav-tabs mb-3">'; foreach($t as $k=>[$l,$h]){ $a=$k===$active?'active':''; echo "<li class='nav-item'><a class='nav-link $a' href='$h'>$l</a></li>"; } echo '</ul>';
}
?>
<div class="container mt-4">
  <nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/views/admin/rewards/index.php">Rewards</a></li>
    <li class="breadcrumb-item"><a href="/views/admin/rewards/cashback/overview.php">Cashback</a></li>
    <li class="breadcrumb-item active" aria-current="page">Rules</li>
  </ol></nav>

  <h1 class="mb-2">Cashback · Rules</h1>
  <p class="text-muted">Define earn and redeem rules for cashback wallets.</p>

  <?php cashback_tabs('rules'); ?>

  <form method="post" action="/controllers/admin/rewards/cashback/rules_save.php">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_rewards_cashback']??'') ?>">
    <div class="card shadow-sm"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Rules (JSON)</h5>
        <button class="btn btn-primary btn-sm">Save</button>
      </div>
      <p class="text-muted mt-2 mb-3">Stored in <code>loyalty_programs.earn_rule_json</code> for the chosen cashback program.</p>
      <textarea name="rules_json" class="form-control" rows="16" placeholder='{"basis":"subtotal_excl_tax_service","ladder":[{"visit":1,"percent":5,"expires_days":30}]}' ><?= h($prefJson) ?></textarea>
    </div></div>
  </form>
</div>
<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>