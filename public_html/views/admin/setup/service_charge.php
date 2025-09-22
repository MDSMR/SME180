<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/auth_check.php'; // your existing check
$pdo = get_pdo();

if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function csrf(){ return $_SESSION['csrf']; }
function getVal($pdo,$key){ $s=$pdo->prepare("SELECT `value` FROM settings WHERE `key`=?"); $s->execute([$key]); $v=$s->fetchColumn(); return $v!==false?$v:''; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST' && hash_equals(csrf(), $_POST['csrf'] ?? '')){
  $v = trim($_POST['service_charge_percent'] ?? '0');
  $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('service_charge_percent', ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")->execute([$v]);
  header('Location: service_charge.php?embed=' . ($_GET['embed']??'') . '&saved=1'); exit;
}
$svc = getVal($pdo,'service_charge_percent');

$embedded = !empty($_GET['embed']);
?>
<?php if (!$embedded): ?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>Service charge</title>
<link rel="stylesheet" href="/assets/css/admin.css"></head>
<body>
<?php include __DIR__ . '/../../_nav.php'; ?>
<div class="container">
<?php endif; ?>

<main style="padding:10px;max-width:720px;margin:0 auto;font-family:system-ui">
  <!-- Heading removed -->
  <?php if (!empty($_GET['saved'])): ?>
    <div class="card" style="margin-bottom:12px;border-left:4px solid #16a34a">Saved.</div>
  <?php endif; ?>
  <div class="card" style="max-width:520px">
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <label>Service charge %<input class="input" type="number" step="0.01" min="0" name="service_charge_percent" value="<?= htmlspecialchars($svc) ?>"></label>
      <div style="grid-column:1/-1"><button class="btn primary sm">Save</button></div>
    </form>
  </div>
</main>

<?php if (!$embedded): ?>
</div>
</body></html>
<?php endif; ?>