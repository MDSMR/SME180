<?php
// views/admin/setup/tax.php — Tax % setting
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../config/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// If embedded inside /admin/setup.php (which is already authed), skip extra auth/header
$embedded = !empty($_GET['embed']);
if (!$embedded) {
  // Optionally guard direct access:
  $authCandidates = [
    __DIR__ . '/../../../config/admin_auth.php',
    __DIR__ . '/../../../config/auth_check.php',
  ];
  foreach ($authCandidates as $p) { if (file_exists($p)) { require_once $p; break; } }
  if (function_exists('admin_require_auth')) { admin_require_auth(); }
}

// CSRF helpers
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_input(){ echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function csrf_ok($t){ return is_string($t ?? null) && hash_equals($_SESSION['csrf'], (string)$t); }

// Settings helpers
function get_setting(PDO $pdo, string $key): ?string {
  $st = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
  $st->execute([$key]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ? (string)$row['value'] : null;
}
function set_setting(PDO $pdo, string $key, string $value): void {
  $st = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
  $st->execute([$key, $value]);
}

$msg = null; $err = null;
try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? '')) throw new Exception('Invalid CSRF token.');
    $raw = trim((string)($_POST['tax_percent'] ?? ''));
    if ($raw === '') throw new Exception('Tax percent is required.');
    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $raw)) throw new Exception('Enter 0–100 (max 2 decimals).');
    $num = (float)$raw;
    if ($num < 0 || $num > 100) throw new Exception('Tax percent must be between 0 and 100.');
    set_setting($pdo, 'tax_percent', number_format($num, strpos($raw,'.')!==false ? 2 : 0, '.', ''));
    $msg = 'Saved.';
  }
} catch (Throwable $ex) { $err = $ex->getMessage(); }

$current = get_setting($pdo, 'tax_percent');
if ($current === null) $current = '0';
?>
<?php if (!$embedded): ?>
<!doctype html><html lang="en"><head>
  <meta charset="utf-8"><title>Tax Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head><body>
<?php include __DIR__ . '/../../_header.php'; ?>
<?php endif; ?>

<main style="padding:10px;max-width:720px;margin:0 auto;font-family:system-ui">
  <!-- Heading removed -->

  <?php if ($msg): ?>
    <div style="background:#eefbea;border:1px solid #b2e3b2;padding:10px;border-radius:10px;color:#08660b;margin:0 0 12px"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div style="background:#fde8e8;border:1px solid #f5c2c7;padding:10px;border-radius:10px;color:#842029;margin:0 0 12px"><?= e($err) ?></div>
  <?php endif; ?>

  <form method="post" style="display:grid;grid-template-columns:1fr auto;gap:10px;background:#fff;border:1px solid #eee;border-radius:12px;padding:12px">
    <?php csrf_input(); ?>
    <label style="display:flex;flex-direction:column;gap:6px">
      <span>Tax percent (%)</span>
      <input type="number" name="tax_percent" min="0" max="100" step="0.01" value="<?= e($current) ?>" required
             style="padding:10px;border:1px solid #ddd;border-radius:10px">
    </label>
    <div style="align-self:end">
      <button style="padding:10px 14px;border:0;border-radius:10px;background:#0d6efd;color:#fff;font-weight:700">Save</button>
    </div>
  </form>

  <p style="color:#666;margin-top:10px">Stored in <code>settings</code> as <code>tax_percent</code>.</p>
</main>

<?php if (!$embedded): ?>
</body></html>
<?php endif; ?>