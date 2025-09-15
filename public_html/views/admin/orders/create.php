<?php
// /public_html/views/admin/orders/create.php
declare(strict_types=1);

/* ---------- Debug toggle ---------- */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
  @ini_set('display_errors','1');
  @ini_set('display_startup_errors','1');
  error_reporting(E_ALL);
} else {
  @ini_set('display_errors','0');
}

/* ---------- Paths ---------- */
$ROOT = dirname(__DIR__, 3); // /public_html
$DB_PHP = $ROOT . '/config/db.php';
$AUTH   = $ROOT . '/middleware/auth_login.php';
$HELP   = $ROOT . '/controllers/admin/orders/_helpers.php';

/* ---------- Bootstrap ---------- */
$bootstrap_err = '';
try { if (is_file($DB_PHP)) { require_once $DB_PHP; } else { $bootstrap_err .= 'Missing config: /config/db.php. '; } }
catch (Throwable $e) { $bootstrap_err .= 'Bootstrap error (db.php): ' . $e->getMessage() . ' '; }

/* Ensure db() exists */
if (!function_exists('db')) {
  function db(): PDO {
    /** @var mixed $pdo */
    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) { throw new RuntimeException('Database connection not initialized.'); }
    return $pdo;
  }
}

/* ---------- Auth ---------- */
try { 
  if (is_file($AUTH)) { 
    @require_once $AUTH; 
  } else { 
    $bootstrap_err .= 'Missing auth middleware. '; 
  } 
}
catch (Throwable $e) { 
  $bootstrap_err .= 'Auth include error: ' . htmlspecialchars($e->getMessage()) . ' '; 
}

if (function_exists('use_backend_session')) {
  try { 
    use_backend_session(); 
  } catch (Throwable $e) { 
    if (session_status() !== PHP_SESSION_ACTIVE) { 
      @session_start(); 
    } 
  }
} else {
  if (session_status() !== PHP_SESSION_ACTIVE) { 
    @session_start(); 
  }
}

if (!function_exists('auth_require_login')) {
  function auth_require_login(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    if (empty($_SESSION['user'])) { header('Location: /views/auth/login.php'); exit; }
  }
}
@auth_require_login();

/* ---------- Controller helpers (guarded) ---------- */
try { if (is_file($HELP)) { require_once $HELP; } } catch (Throwable $e) { /* ignore */ }

/* ---------- Local helpers (guarded) ---------- */
if (!function_exists('ensure_csrf_token')) {
  function ensure_csrf_token(string $name = 'csrf'): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    if (empty($_SESSION[$name]) || !is_string($_SESSION[$name])) { $_SESSION[$name] = bin2hex(random_bytes(32)); }
    return $_SESSION[$name];
  }
}
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fetch_enum_values')) {
  function fetch_enum_values(PDO $pdo, string $table, string $column): array {
    try {
      $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1";
      $st = $pdo->prepare($sql); $st->execute([':t'=>$table, ':c'=>$column]);
      $type = (string)$st->fetchColumn();
      if (!$type || stripos($type, 'enum(') !== 0) return [];
      $vals = substr($type, 5, -1);
      $vals = str_getcsv($vals, ',', "'", "\\");
      return array_map('strval', $vals);
    } catch (Throwable $e) { return []; }
  }
}
if (!function_exists('get_setting')) {
  // Use correct setting keys (without pos. prefix based on database schema)
  function get_setting(PDO $pdo, int $tenantId, string $key, $default = null) {
    try {
      $st = $pdo->prepare("SELECT value FROM settings WHERE tenant_id = :t AND `key` = :k ORDER BY id DESC LIMIT 1");
      $st->execute([':t'=>$tenantId, ':k'=>$key]);
      $val = $st->fetchColumn();
      return $val === false ? $default : $val;
    } catch (Throwable $e) { return $default; }
  }
}

/* ---------- CSRF ---------- */
$csrf = ensure_csrf_token('csrf_orders');

/* ---------- Page data ---------- */
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$db_msg = trim($bootstrap_err);

$tenantId = 0;
$branches = [];
$categories = [];
$orderTypes = [];
$discountOptions = [];
$aggregators = [];

// Settings with correct database keys
$taxPercent = 0.0;
$servicePercent = 0.0;
$currency = 'EGP';
$taxInclusive = false;

try {
  $pdo = db();
  $user = $_SESSION['user'] ?? null;
  $tenantId = (int)($user['tenant_id'] ?? 0);

  // Branches
  $st = $pdo->prepare("SELECT id, COALESCE(display_name, name) AS label
                       FROM branches
                       WHERE tenant_id = :t AND is_active = 1
                       ORDER BY label");
  $st->execute([':t'=>$tenantId]);
  $branches = $st->fetchAll(PDO::FETCH_ASSOC);

  // Categories
  $st = $pdo->prepare("SELECT id, COALESCE(name_en, name_ar) AS name
                       FROM categories
                       WHERE tenant_id = :t
                       ORDER BY sort_order, name_en");
  $st->execute([':t'=>$tenantId]);
  $categories = $st->fetchAll(PDO::FETCH_ASSOC);

  // Order types (enum) -> only show approved POS types
  $enumOrderTypes = fetch_enum_values($pdo, 'orders', 'order_type');
  $whitelist = ['dine_in','takeaway','delivery','pickup'];
  $orderTypes = array_values(array_intersect($enumOrderTypes ?: $whitelist, $whitelist));
  if (!$orderTypes) { $orderTypes = $whitelist; }

  // Line discount options
  try {
    $st = $pdo->prepare("SELECT discount_percent AS percent,
                                COALESCE(label, CONCAT(discount_percent, '%')) AS label
                         FROM line_discount_options
                         WHERE tenant_id = :t AND is_active = 1
                         ORDER BY sort_order, discount_percent");
    $st->execute([':t'=>$tenantId]);
    $discountOptions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { 
    // Fallback discount options
    $discountOptions = [
      ['percent' => 5, 'label' => '5% Off'],
      ['percent' => 10, 'label' => '10% Off'],
      ['percent' => 15, 'label' => '15% Off'],
      ['percent' => 20, 'label' => '20% Off'],
      ['percent' => 25, 'label' => '25% Off'],
      ['percent' => 50, 'label' => '50% Off'],
      ['percent' => 100, 'label' => '100% Off (Comp)']
    ];
  }

  // Delivery aggregators
  try {
    $st = $pdo->prepare("SELECT id, name, COALESCE(default_commission_percent, 0) AS commission_percent
                         FROM aggregators
                         WHERE tenant_id = :t AND is_active = 1
                         ORDER BY name");
    $st->execute([':t'=>$tenantId]);
    $aggregators = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { $aggregators = []; }

  // Settings (use correct keys from database schema)
  $taxPercent     = (float)(get_setting($pdo, $tenantId, 'tax_percent',     '14') ?? '14');
  $servicePercent = (float)(get_setting($pdo, $tenantId, 'service_percent', '10') ?? '10');
  $currency       = (string)(get_setting($pdo, $tenantId, 'currency',       'EGP')?? 'EGP');
  $taxInclusive   = (bool)  (get_setting($pdo, $tenantId, 'tax_inclusive',  '0')  ? true : false);

} catch (Throwable $e) {
  $db_msg .= ' ' . $e->getMessage();
}

/* ---------- Safe include helper ---------- */
if (!function_exists('include_first_existing')) {
  function include_first_existing(array $candidates): bool {
    foreach ($candidates as $f) { if (is_file($f)) { include $f; return true; } }
    return false;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Create Order · Smorll POS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      /* Microsoft 365 Color Palette */
      --bg-primary: #faf9f8;
      --bg-secondary: #f3f2f1;
      --card-bg: #ffffff;
      --text-primary: #323130;
      --text-secondary: #605e5c;
      --text-tertiary: #8a8886;
      --primary: #0078d4;
      --primary-hover: #106ebe;
      --primary-light: #deecf9;
      --primary-lighter: #f3f9fd;
      --border: #edebe9;
      --border-light: #f8f6f4;
      --hover: #f3f2f1;
      --success: #107c10;
      --success-light: #dff6dd;
      --warning: #ff8c00;
      --warning-light: #fff4ce;
      --danger: #d13438;
      --danger-light: #fdf2f2;
      --shadow-sm: 0 1px 2px rgba(0,0,0,.04), 0 1px 1px rgba(0,0,0,.06);
      --shadow-md: 0 4px 8px rgba(0,0,0,.04), 0 1px 3px rgba(0,0,0,.06);
      --shadow-lg: 0 8px 16px rgba(0,0,0,.06), 0 2px 4px rgba(0,0,0,.08);
      --transition: all .2s cubic-bezier(.1,.9,.2,1);
      --radius: 8px;
      --radius-lg: 12px;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      background: var(--bg-primary);
      font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, Roboto, 'Helvetica Neue', sans-serif;
      color: var(--text-primary);
      font-size: 14px;
      line-height: 1.5;
    }

    .container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 16px;
    }

    /* Page Header */
    .breadcrumb {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 16px;
      font-size: 14px;
    }

    .breadcrumb-link {
      color: var(--text-secondary);
      text-decoration: none;
      transition: var(--transition);
    }

    .breadcrumb-link:hover {
      color: var(--primary);
    }

    .breadcrumb-separator {
      color: var(--text-tertiary);
    }

    .breadcrumb-current {
      color: var(--text-primary);
      font-weight: 500;
    }

    .page-header {
      margin-bottom: 24px;
    }

    .page-title {
      font-size: 28px;
      font-weight: 600;
      color: var(--text-primary);
      margin: 0 0 6px 0;
    }

    .page-subtitle {
      font-size: 15px;
      color: var(--text-secondary);
      margin: 0;
    }

    /* Alert Components */
    .alert {
      padding: 16px 20px;
      border-radius: var(--radius-lg);
      margin-bottom: 16px;
      font-size: 14px;
      border: 1px solid;
      display: flex;
      align-items: center;
      gap: 12px;
      box-shadow: var(--shadow-sm);
    }

    .alert.success {
      background: var(--success-light);
      border-color: #a7f3d0;
      color: var(--success);
    }

    .alert.error {
      background: var(--danger-light);
      border-color: #fca5a5;
      color: var(--danger);
    }

    .alert.warning {
      background: var(--warning-light);
      border-color: #fcd34d;
      color: var(--warning);
    }

    /* Professional Icons */
    .icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 16px;
      height: 16px;
      font-size: 12px;
      font-style: normal;
      line-height: 1;
    }

    .icon-success::before { content: '✓'; }
    .icon-warning::before { content: '⚠'; }
    .icon-error::before { content: '✗'; }
    .icon-info::before { content: 'ℹ'; }
    .icon-store::before { content: '🏪'; }
    .icon-user::before { content: '👤'; }
    .icon-cart::before { content: '🛒'; }
    .icon-money::before { content: '💰'; }
    .icon-search::before { content: '🔍'; }
    .icon-plus::before { content: '+'; }
    .icon-edit::before { content: '✏'; }
    .icon-trash::before { content: '🗑'; }
    .icon-close::before { content: '✕'; }

    /* Section Layout */
    .section {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      margin-bottom: 20px;
      box-shadow: var(--shadow-sm);
      overflow: hidden;
    }

    .form-section {
      padding: 24px;
    }

    .section-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 20px;
    }

    .section-icon {
      width: 36px;
      height: 36px;
      background: var(--primary-light);
      color: var(--primary);
      border-radius: var(--radius);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
    }

    .section-title {
      font-size: 20px;
      font-weight: 600;
      color: var(--text-primary);
      margin: 0;
    }

    .section-subtitle {
      font-size: 14px;
      color: var(--text-secondary);
      margin-top: 2px;
    }

    /* Form Grid */
    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .form-group.full {
      grid-column: 1 / -1;
    }

    .form-label {
      font-size: 14px;
      font-weight: 500;
      color: var(--text-primary);
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .required {
      color: var(--danger);
    }

    .optional {
      color: var(--text-secondary);
      font-size: 12px;
      font-weight: 400;
      margin-left: auto;
    }

    /* Form Controls */
    .form-input,
    .form-select,
    .form-textarea {
      padding: 12px 14px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      background: var(--card-bg);
      font-size: 14px;
      color: var(--text-primary);
      font-family: inherit;
      outline: none;
      transition: var(--transition);
    }

    .form-textarea {
      resize: vertical;
      min-height: 80px;
    }

    .form-input:hover,
    .form-select:hover,
    .form-textarea:hover {
      border-color: var(--text-secondary);
    }

    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    }

    .form-input:disabled,
    .form-select:disabled {
      background: var(--bg-secondary);
      color: var(--text-tertiary);
      cursor: not-allowed;
    }

    .help-text {
      font-size: 12px;
      color: var(--text-secondary);
    }

    /* Customer Search */
    .customer-search-container {
      position: relative;
    }

    .customer-results {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-top: none;
      border-radius: 0 0 var(--radius) var(--radius);
      box-shadow: var(--shadow-lg);
      z-index: 100;
      max-height: 300px;
      overflow-y: auto;
      display: none;
    }

    .customer-results.show {
      display: block;
    }

    .customer-result {
      padding: 12px 16px;
      cursor: pointer;
      border-bottom: 1px solid var(--border-light);
      transition: var(--transition);
    }

    .customer-result:hover {
      background: var(--hover);
    }

    .customer-result:last-child {
      border-bottom: none;
    }

    .customer-name {
      font-weight: 600;
      margin-bottom: 4px;
    }

    .customer-details {
      font-size: 12px;
      color: var(--text-tertiary);
    }

    /* Button System */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 12px 20px;
      border: 1px solid transparent;
      border-radius: var(--radius);
      background: var(--card-bg);
      color: var(--text-secondary);
      font-size: 14px;
      font-weight: 500;
      font-family: inherit;
      text-decoration: none;
      cursor: pointer;
      transition: var(--transition);
      white-space: nowrap;
      outline: none;
      box-shadow: var(--shadow-sm);
    }

    .btn:hover:not(:disabled) {
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
      background: var(--hover);
      color: var(--text-primary);
      text-decoration: none;
    }

    .btn:active {
      transform: translateY(0);
    }

    .btn:disabled {
      background: var(--bg-secondary);
      color: var(--text-tertiary);
      cursor: not-allowed;
      border-color: var(--border);
      transform: none;
      box-shadow: var(--shadow-sm);
    }

    .btn-primary {
      background: var(--primary);
      border-color: var(--primary);
      color: white;
    }

    .btn-primary:hover:not(:disabled) {
      background: var(--primary-hover);
      border-color: var(--primary-hover);
      color: white;
    }

    .btn-sm {
      padding: 8px 14px;
      font-size: 12px;
    }

    .btn-danger {
      background: var(--danger);
      border-color: var(--danger);
      color: white;
    }

    .btn-danger:hover:not(:disabled) {
      background: #b91c1c;
      border-color: #b91c1c;
      color: white;
    }

    /* Table System */
    .table-container {
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      background: var(--card-bg);
      margin: 20px 0;
      box-shadow: var(--shadow-sm);
    }

    .table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      min-width: 800px;
    }

    .table thead {
      background: var(--bg-secondary);
    }

    .table th {
      padding: 14px 12px;
      text-align: left;
      font-size: 12px;
      font-weight: 600;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .table td {
      padding: 14px 12px;
      border-top: 1px solid var(--border-light);
      vertical-align: middle;
    }

    .table tbody tr:hover {
      background: var(--hover);
    }

    .table .text-right {
      text-align: right;
    }

    .table .text-center {
      text-align: center;
    }

    /* Status Badge */
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 600;
    }

    .status-badge.success {
      background: var(--success-light);
      color: var(--success);
    }

    .status-badge.warning {
      background: var(--warning-light);
      color: var(--warning);
    }

    .status-badge.error {
      background: var(--danger-light);
      color: var(--danger);
    }

    .status-badge.neutral {
      background: var(--bg-secondary);
      color: var(--text-secondary);
    }

    /* Totals Summary */
    .totals-summary {
      background: var(--bg-secondary);
      border-radius: var(--radius-lg);
      padding: 24px;
      margin: 20px 0;
      border: 1px solid var(--border);
      box-shadow: var(--shadow-sm);
    }

    .totals-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 16px;
    }

    .total-item {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 16px;
      text-align: center;
      box-shadow: var(--shadow-sm);
    }

    .total-label {
      font-size: 12px;
      color: var(--text-tertiary);
      display: block;
      margin-bottom: 6px;
      text-transform: uppercase;
      font-weight: 500;
      letter-spacing: 0.5px;
    }

    .total-value {
      font-size: 18px;
      font-weight: 600;
      color: var(--text-primary);
    }

    .final-total {
      background: var(--primary-lighter);
      border-color: var(--primary-light);
    }

    .final-total .total-value {
      font-size: 24px;
      color: var(--primary);
    }

    /* Loading States */
    .loading {
      opacity: 0.6;
      pointer-events: none;
    }

    .spinner {
      display: inline-block;
      width: 16px;
      height: 16px;
      border: 2px solid var(--border);
      border-top-color: var(--primary);
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    /* Empty States */
    .empty-state {
      text-align: center;
      padding: 32px;
      color: var(--text-tertiary);
    }

    .empty-state .icon {
      font-size: 32px;
      color: var(--text-secondary);
      margin-bottom: 12px;
      display: block;
    }

    /* Modal */
    .modal {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.4);
      z-index: 999;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .modal.show {
      display: flex;
    }

    .modal-content {
      background: var(--card-bg);
      border-radius: var(--radius-lg);
      padding: 24px;
      max-width: 600px;
      width: 100%;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: var(--shadow-lg);
      border: 1px solid var(--border);
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 16px;
      border-bottom: 1px solid var(--border-light);
    }

    .modal-title {
      font-size: 18px;
      font-weight: 600;
      margin: 0;
    }

    .modal-close {
      background: none;
      border: none;
      font-size: 16px;
      cursor: pointer;
      color: var(--text-tertiary);
      padding: 6px;
      border-radius: var(--radius);
      transition: var(--transition);
    }

    .modal-close:hover {
      background: var(--hover);
    }

    /* Modifiers */
    .modifiers-section {
      margin-top: 20px;
    }

    .modifier-group {
      margin-bottom: 20px;
      border: 1px solid var(--border-light);
      border-radius: var(--radius);
      padding: 16px;
      background: var(--bg-primary);
    }

    .modifier-group-title {
      font-size: 16px;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .required-badge {
      background: var(--danger-light);
      color: var(--danger);
      font-size: 10px;
      font-weight: 500;
      padding: 2px 6px;
      border-radius: 10px;
    }

    .modifier-options {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 10px;
    }

    .modifier-option {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      background: var(--card-bg);
      cursor: pointer;
      transition: var(--transition);
    }

    .modifier-option:hover {
      background: var(--hover);
      border-color: var(--primary);
    }

    .modifier-option.selected {
      background: var(--primary-lighter);
      border-color: var(--primary);
    }

    .modifier-option input {
      accent-color: var(--primary);
    }

    .modifier-option-label {
      flex: 1;
      font-size: 14px;
    }

    .modifier-price-delta {
      font-size: 12px;
      color: var(--text-secondary);
      font-weight: 500;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .container {
        padding: 12px;
      }

      .form-grid {
        grid-template-columns: 1fr;
      }

      .totals-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .table {
        min-width: 600px;
      }

      .modifier-options {
        grid-template-columns: 1fr;
      }
    }

    /* Hidden utility */
    .hidden {
      display: none !important;
    }

    /* Loyalty Section */
    .loyalty-info {
      background: var(--primary-lighter);
      border: 1px solid var(--primary-light);
      border-radius: var(--radius);
      padding: 16px;
      margin: 16px 0;
    }

    .loyalty-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: 12px;
      margin-bottom: 16px;
    }

    .loyalty-stat {
      text-align: center;
      padding: 12px;
      background: var(--card-bg);
      border-radius: var(--radius);
      border: 1px solid var(--border-light);
    }

    .loyalty-stat-value {
      font-size: 18px;
      font-weight: 600;
      color: var(--primary);
      display: block;
      margin-bottom: 4px;
    }

    .loyalty-stat-label {
      font-size: 12px;
      color: var(--text-tertiary);
    }
  </style>
</head>
<body>

<?php
$navIncluded = include_first_existing([
  __DIR__ . '/../../partials/admin_nav.php',
  dirname(__DIR__,2) . '/partials/admin_nav.php',
  $_SERVER['DOCUMENT_ROOT'] . '/views/partials/admin_nav.php',
  $_SERVER['DOCUMENT_ROOT'] . '/partials/admin_nav.php'
]);
if (!$navIncluded && $DEBUG) {
  echo "<div class='alert warning'><span class='icon icon-warning'></span>Navigation partial not found.</div>";
}
?>

<div class="container">
  <?php if($flash): ?><div class="alert success"><span class='icon icon-success'></span><?= h($flash) ?></div><?php endif; ?>
  <?php if($db_msg && $DEBUG): ?><div class="alert warning"><span class='icon icon-warning'></span><strong>DEBUG:</strong> <?= h($db_msg) ?></div><?php endif; ?>

  <!-- Breadcrumb -->
  <div class="breadcrumb">
    <a href="/views/admin/orders/index.php" class="breadcrumb-link">Orders</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Create Order</span>
  </div>

  <!-- Page Header -->
  <div class="page-header">
    <h1 class="page-title">Create New Order</h1>
    <p class="page-subtitle">Add customer details, select items with modifiers, and process the order</p>
  </div>

  <form method="post" action="/controllers/admin/orders/order_save.php" id="orderForm" novalidate>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="0">
    <input type="hidden" name="source_channel" value="pos">
    <input type="hidden" name="status" value="open">
    <input type="hidden" name="payment_status" value="unpaid">
    <input type="hidden" name="order_items_json" id="order_items_json" value="[]">
    <input type="hidden" name="loyalty_program_id" id="loyalty_program_id">
    <input type="hidden" name="voucher_redemptions" id="voucher_redemptions" value="[]">

    <!-- Basic Information -->
    <div class="section">
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">
            <span class="icon icon-store"></span>
          </div>
          <div>
            <div class="section-title">Basic Information</div>
            <div class="section-subtitle">Essential order details</div>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Branch <span class="required">*</span></label>
            <select class="form-select" name="branch_id" id="branchSelect" required>
              <option value="">Select branch</option>
              <?php foreach($branches as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= h($b['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="help-text">Choose which branch this order is for</div>
          </div>

          <div class="form-group">
            <label class="form-label">Order Type <span class="required">*</span></label>
            <select class="form-select" name="order_type" id="orderType" required>
              <?php foreach($orderTypes as $ot): ?>
                <option value="<?= h($ot) ?>"><?= ucwords(str_replace('_',' ', $ot)) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="help-text">Type of order service</div>
          </div>
        </div>

        <!-- Dine-in only -->
        <div class="form-grid" id="dineInFields" style="display:none;">
          <div class="form-group">
            <label class="form-label">Table</label>
            <select class="form-select" id="tableSelect" name="table_reference">
              <option value="">Select table</option>
            </select>
            <input class="form-input" id="tableInput" name="table_reference" placeholder="Table number" style="display:none">
          </div>
          <div class="form-group">
            <label class="form-label">Guest Count</label>
            <input class="form-input" type="number" min="1" step="1" name="guest_count" id="guestCount" placeholder="Number of guests" value="2">
          </div>
        </div>

        <!-- Delivery only - moved here as requested -->
        <div class="form-grid" id="deliveryFields" style="display:none;">
          <div class="form-group">
            <label class="form-label">Delivery Partner <span class="required">*</span></label>
            <select id="aggregatorSelect" name="aggregator_id" class="form-select" required>
              <option value="">Select delivery partner</option>
              <?php foreach($aggregators as $ag): ?>
                <option value="<?= (int)$ag['id'] ?>" data-commission="<?= htmlspecialchars((string)$ag['commission_percent']) ?>">
                  <?= h($ag['name']) ?> (<?= h($ag['commission_percent']) ?>% commission)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="help-text">Required for delivery orders</div>
          </div>
          <div class="form-group">
            <label class="form-label">External Order Reference</label>
            <input class="form-input" name="external_order_reference" maxlength="100" placeholder="Platform order ID (e.g., Talabat #12345)">
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Order Notes</label>
            <input class="form-input" name="order_notes" maxlength="255" placeholder="Special instructions or notes">
          </div>
          <div class="form-group">
            <label class="form-label">Receipt Reference</label>
            <input class="form-input" name="receipt_reference" maxlength="100" placeholder="Internal reference (optional)">
          </div>
        </div>
      </div>
    </div>

    <!-- Customer Information -->
    <div class="section">
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">
            <span class="icon icon-user"></span>
          </div>
          <div>
            <div class="section-title">Customer Information</div>
            <div class="section-subtitle">Search existing customers or create new</div>
          </div>
        </div>

        <!-- Customer Search & Results -->
        <div class="form-group" style="position:relative;max-width:580px">
          <label class="form-label">Customer Search</label>
          <div class="customer-search-container">
            <input class="form-input" id="customerSearch" placeholder="Search by name or mobile number..." autocomplete="off">
            <div id="customerResults" class="customer-results">
              <!-- Dynamic search results -->
            </div>
          </div>
          <div class="help-text">Start typing name or mobile number to search existing customers</div>
        </div>

        <!-- Selected / New customer fields -->
        <div class="form-grid" style="max-width:580px">
          <div class="form-group">
            <label class="form-label">Customer Name</label>
            <input class="form-input" id="customerName" name="customer_name" placeholder="Customer full name">
          </div>
          <div class="form-group">
            <label class="form-label">Mobile Number</label>
            <input class="form-input" id="customerPhone" name="customer_phone" placeholder="+20 100 xxx xxxx">
          </div>
        </div>
        <input type="hidden" id="customerId" name="customer_id" value="">
        
        <div style="margin-top:12px">
          <button type="button" class="btn btn-sm" id="btnCreateCustomer">
            <span class="icon icon-plus"></span>
            Create New Customer
          </button>
          <span class="help-text">Create customer account for loyalty programs and order history</span>
        </div>

        <!-- Loyalty -->
        <div id="loyaltySection" class="hidden">
          <div class="loyalty-info">
            <div id="loyaltyStats" class="loyalty-stats">
              <!-- Populated by JavaScript -->
            </div>
            
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">Loyalty Program</label>
                <select class="form-select" id="loyaltyProgramSelect">
                  <option value="">No program selected</option>
                </select>
                <div class="help-text">Apply loyalty program to this order</div>
              </div>
              
              <div class="form-group">
                <label class="form-label">Available Vouchers</label>
                <select class="form-select" id="voucherSelect">
                  <option value="">No voucher selected</option>
                </select>
                <div class="help-text">Redeem available customer vouchers</div>
              </div>
            </div>

            <div id="rewardsPreview" class="hidden">
              <div class="help-text">
                <strong>Rewards Earned:</strong>
                <span id="rewardsPreviewText">Select a loyalty program to preview rewards</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Add Items -->
    <div class="section">
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">
            <span class="icon icon-cart"></span>
          </div>
          <div>
            <div class="section-title">Add Items</div>
            <div class="section-subtitle">Search and add products to the order</div>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Search Products</label>
            <input type="text" id="productSearch" class="form-input" placeholder="Search by name or ID..." autocomplete="off">
          </div>
          <div class="form-group">
            <label class="form-label">Filter by Category</label>
            <select id="categoryFilter" class="form-select">
              <option value="">All categories</option>
              <?php foreach($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="table-container">
          <table class="table">
            <thead>
              <tr>
                <th style="width:40px">Select</th>
                <th>Product</th>
                <th style="width:100px">In Stock</th>
                <th style="width:140px" class="text-center">Modifiers</th>
                <th style="width:80px" class="text-center">Qty</th>
                <th style="width:120px" class="text-right">Price</th>
                <th style="width:90px">Action</th>
              </tr>
            </thead>
            <tbody id="productsTableBody">
              <tr><td colspan="7" class="empty-state">
                <span class="icon icon-search"></span>
                Select a branch to view available products
              </td></tr>
            </tbody>
          </table>
        </div>

        <div class="table-container">
          <table class="table">
            <thead>
              <tr>
                <th>Item Details</th>
                <th style="width:80px" class="text-center">Qty</th>
                <th style="width:120px" class="text-right">Unit Price</th>
                <th style="width:140px" class="text-right">Discount</th>
                <th style="width:120px" class="text-right">Line Total</th>
                <th style="width:60px"></th>
              </tr>
            </thead>
            <tbody id="orderItemsBody">
              <tr id="orderItemsEmpty"><td colspan="6" class="empty-state">
                <span class="icon icon-cart"></span>
                No items added yet
              </td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Review & Total -->
    <div class="section">
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">
            <span class="icon icon-money"></span>
          </div>
          <div>
            <div class="section-title">Review & Total</div>
            <div class="section-subtitle">Order summary and totals calculation</div>
          </div>
        </div>

        <!-- Enhanced Totals Summary -->
        <div class="totals-summary">
          <div class="totals-grid">
            <div class="total-item">
              <span class="total-label">Subtotal</span>
              <span class="total-value" id="subtotalDisplay">0.00</span>
            </div>
            <div class="total-item">
              <span class="total-label">Discount</span>
              <span class="total-value" id="discountDisplay">0.00</span>
            </div>
            <div class="total-item" id="taxItem">
              <span class="total-label">Tax (<?= h($taxPercent) ?>%)</span>
              <span class="total-value" id="taxDisplay">0.00</span>
            </div>
            <div class="total-item" id="serviceItem">
              <span class="total-label">Service (<?= h($servicePercent) ?>%)</span>
              <span class="total-value" id="serviceDisplay">0.00</span>
            </div>
            <div class="total-item hidden" id="commissionItem">
              <span class="total-label">Commission</span>
              <span class="total-value" id="commissionDisplay">0.00</span>
            </div>
            <div class="total-item final-total">
              <span class="total-label">Total</span>
              <span class="total-value" id="totalDisplay">0.00</span>
            </div>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:20px">
          <a class="btn" href="/views/admin/orders/index.php">Cancel</a>
          <button type="submit" class="btn btn-primary">
            <span class="icon icon-plus"></span>
            Create Order
          </button>
        </div>
      </div>
    </div>

    <!-- Modifiers Modal -->
    <div class="modal" id="modifiersModal">
      <div class="modal-content">
        <div class="modal-header">
          <h3 class="modal-title">Select Options — <span id="modalProductName"></span></h3>
          <button type="button" class="modal-close" onclick="closeModifiersModal()">
            <span class="icon icon-close"></span>
          </button>
        </div>
        <div class="modifiers-section" id="modifiersSection"></div>
        <div class="form-group">
          <label class="form-label">Special Instructions</label>
          <textarea id="modalNotes" class="form-textarea" placeholder="Any special requests or modifications..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:24px">
          <button type="button" class="btn" onclick="closeModifiersModal()">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="saveSelectedModifiers()">
            <span class="icon icon-plus"></span>
            Add to Order
          </button>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
/* ===== Globals ===== */
const tenantId = <?= (int)$tenantId ?>;
console.log('Tenant ID initialized:', tenantId); // DEBUG
let orderItems = [];
let loadedProducts = [];
let selectedModifiersByProduct = {}; // product_id -> [{group_id, value_id, value_name, price_delta}]
let selectedNotesByProduct = {};     // product_id -> string notes
let currentModifierProduct = null;
let currentPreselectedValueIds = [];

// Settings from database (correct keys without pos. prefix)
const TAX_PERCENT = <?= (float)$taxPercent ?>;
const SERVICE_PERCENT = <?= (float)$servicePercent ?>;
const CURRENCY = '<?= h($currency) ?>';
const TAX_INCLUSIVE = <?= $taxInclusive ? 'true' : 'false' ?>;

// Discounts
const DISCOUNT_OPTIONS = <?= json_encode($discountOptions, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;

/* ===== Helpers ===== */
const gid = id => document.getElementById(id);
function num(v){ const n = parseFloat(v); return isNaN(n) ? 0 : n; }
function esc(s){ return String(s).replace(/[&<>\"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;','\'':'&#39;'}[m])); }
function showAlert(msg, type='warning'){
  const el = document.createElement('div');
  el.className = `alert ${type}`;
  const iconClass = type === 'success' ? 'icon-success' : type === 'error' ? 'icon-error' : 'icon-warning';
  el.innerHTML = `<span class='icon ${iconClass}'></span>${msg}`;
  document.querySelector('.container').prepend(el);
  setTimeout(()=>el.remove(), 4000);
}

/* ===== Branch & Order Type ===== */
const branchSelect = gid('branchSelect');
const orderTypeEl = gid('orderType');
const dineInFields = gid('dineInFields');
const deliveryFields = gid('deliveryFields');
const tableSelect = gid('tableSelect');
const tableInput  = gid('tableInput');
const guestCount  = gid('guestCount');
const aggregatorSelect = gid('aggregatorSelect');
const taxItem = gid('taxItem');
const serviceItem = gid('serviceItem');
const commissionItem = gid('commissionItem');

branchSelect.addEventListener('change', () => {
  loadProducts();
  if (orderTypeEl.value === 'dine_in') { loadTablesForBranch(); }
  recalcTotals();
});

orderTypeEl.addEventListener('change', () => {
  const val = orderTypeEl.value;
  const dine = val === 'dine_in';
  const delivery = val === 'delivery';

  // Show/hide relevant fields
  dineInFields.style.display = dine ? '' : 'none';
  deliveryFields.style.display = delivery ? '' : 'none';
  
  // Show/hide totals items based on order type
  serviceItem.style.display = dine ? '' : 'none';
  commissionItem.classList.toggle('hidden', !delivery);
  
  if (dine) { 
    loadTablesForBranch(); 
    guestCount.required = true;
  } else {
    guestCount.required = false;
  }
  
  if (delivery) {
    aggregatorSelect.required = true;
  } else {
    aggregatorSelect.required = false;
    aggregatorSelect.value = '';
  }

  recalcTotals();
});

/* ===== Tables by branch (dine-in) ===== */
function useTableSelectMode(useSelect){
  tableSelect.style.display = useSelect ? '' : 'none';
  tableSelect.required = useSelect;
  tableInput.style.display = useSelect ? 'none' : '';
  tableInput.required = !useSelect;
}

function loadTablesForBranch(){
  const branchId = branchSelect.value;
  if (!branchId){ useTableSelectMode(false); return; }
  fetch(`/controllers/admin/orders/api/tables.php?branch_id=${encodeURIComponent(branchId)}&tenant_id=${tenantId}`)
    .then(r => r.json())
    .then(res => {
      if (!res || res.success !== true || !Array.isArray(res.data)) throw new Error();
      const tables = res.data;
      tableSelect.innerHTML = `<option value="">Select table</option>` + tables.map(t => {
        const label = esc(t.name || t.number || t.table_number || t.label || String(t.id));
        return `<option value="${esc(String(t.id))}" ${t.is_available===false?'disabled':''}>${label}${t.is_available===false?' (occupied)':''}</option>`;
      }).join('');
      useTableSelectMode(true);
    })
    .catch(() => { useTableSelectMode(false); });
}

/* ===== Products ===== */
function loadProducts() {
  const branchId = branchSelect.value;
  const tbody = gid('productsTableBody');
  if (!branchId) {
    tbody.innerHTML = `<tr><td colspan="7" class="empty-state"><span class="icon icon-search"></span>Select a branch to view available products</td></tr>`;
    return;
  }
  tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:32px"><span class="spinner"></span> Loading products...</td></tr>`;

  const body = new URLSearchParams({ branch_id: branchId, tenant_id: String(tenantId), include_variations: '1' });
  fetch('/controllers/admin/orders/api/products.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
  .then(r => r.json())
  .then(res => {
    if (!res || res.success !== true) throw new Error(res && res.message ? res.message : 'Failed');
    const data = res.data || {};
    loadedProducts = Array.isArray(data.products) ? data.products : [];
    selectedModifiersByProduct = {};
    selectedNotesByProduct = {};
    renderProductsTable(loadedProducts);
  })
  .catch(err => {
    tbody.innerHTML = `<tr><td colspan="7" class="empty-state"><span class="icon icon-warning"></span>Error loading products: ${esc(err.message || String(err))}</td></tr>`;
  });
}

function renderProductsTable(products) {
  const tbody = gid('productsTableBody');
  if (!products.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="empty-state"><span class="icon icon-search"></span>No products for the selected branch.</td></tr>`;
    return;
  }
  tbody.innerHTML = products.map(p => {
    const available = parseFloat(p.available_stock || 0);
    const stockStatus = available <= 0 ? 'error' : (available < 10 ? 'warning' : 'success');
    const hasVariations = !!(p.variations && p.variations.length);
    const categoryName = p.category_name || p.primary_category || '';

    return `
      <tr data-product-id="${p.product_id}" data-name="${esc(p.product_name)}" data-category="${esc(categoryName)}" data-available="${available}" data-has-variations="${hasVariations}">
        <td><input type="checkbox" class="product-checkbox"></td>
        <td>
          <div style="font-weight:600;margin-bottom:4px;">${esc(p.product_name)}</div>
          <div style="font-size:12px;color:var(--text-tertiary);">
            ${categoryName ? `<span class="status-badge neutral">${esc(categoryName)}</span> ` : ''}
            ID: ${p.product_id} • ${esc(p.inventory_unit || 'pc')}
            ${hasVariations ? ' • <span class="status-badge warning">Has Modifiers</span>' : ''}
          </div>
        </td>
        <td><span class="status-badge ${stockStatus}">${available.toFixed(1)}</span></td>
        <td class="text-center">
          ${hasVariations ? `<button type="button" class="btn btn-sm" onclick="openModifiersFor(${p.product_id})">Select</button>` : ''}
        </td>
        <td class="text-center"><input type="number" class="form-input qty-input" min="0.1" step="0.1" value="1" style="width:70px;height:32px;text-align:center"></td>
        <td class="text-right"><input type="number" class="form-input price-input" min="0" step="0.01" value="${(parseFloat(p.price)||0).toFixed(2)}" style="width:90px;height:32px;text-align:right"></td>
        <td><button type="button" class="btn btn-sm btn-primary" onclick="handleAddProduct(${p.product_id})"><span class="icon icon-plus"></span></button></td>
      </tr>`;
  }).join('');
}

gid('productSearch').addEventListener('input', filterProducts);
gid('categoryFilter').addEventListener('change', filterProducts);
function filterProducts(){
  const term = gid('productSearch').value.toLowerCase();
  const category = gid('categoryFilter').value;
  const rows = document.querySelectorAll('#productsTableBody tr[data-product-id]');
  rows.forEach(row => {
    const name = (row.dataset.name || '').toLowerCase();
    const cat = row.dataset.category || '';
    const matchesTerm = !term || name.includes(term);
    const matchesCategory = !category || cat === category;
    row.style.display = (matchesTerm && matchesCategory) ? '' : 'none';
  });
}

/* ===== Modifiers UX ===== */
function openModifiersFor(productId){
  const product = loadedProducts.find(p => String(p.product_id) === String(productId));
  if (!product) { showAlert('Product not found','error'); return; }
  currentModifierProduct = product;
  currentPreselectedValueIds = (selectedModifiersByProduct[productId] || []).map(m => parseInt(m.value_id,10));
  gid('modalProductName').textContent = product.product_name || 'Item';
  gid('modalNotes').value = selectedNotesByProduct[productId] || '';
  const container = gid('modifiersSection');
  container.innerHTML = '';

  (product.variations || []).forEach(group => {
    const values = (group.values || group.variation_values || []).map(v => ({
      id: parseInt(v.id), name: v.name_en || v.value_en || v.value || v.name_ar || '', price_delta: parseFloat(v.price_delta || v.price || 0)
    }));
    const html = `
      <div class="modifier-group">
        <div class="modifier-group-title">
          ${esc(group.name || group.name_en || 'Options')}
          ${group.is_required ? '<span class="required-badge">Required</span>' : ''}
          <span style="font-size:12px;color:var(--text-tertiary);font-weight:normal;margin-left:8px;">
            min ${group.min_select || 0} / max ${group.max_select || (group.is_required?1:0) || 0}
          </span>
        </div>
        <div class="modifier-options" data-group-id="${group.id}" data-min="${group.min_select||0}" data-max="${group.max_select||0}">
          ${values.map(v => `
            <label class="modifier-option" onclick="toggleModifier(this)">
              <input type="${(group.max_select === 1) ? 'radio' : 'checkbox'}" name="modifier_${group.id}" value="${v.id}" data-price-delta="${v.price_delta}">
              <span class="modifier-option-label">${esc(v.name)}</span>
              ${v.price_delta ? `<span class="modifier-price-delta">${v.price_delta>0?'+':''}${v.price_delta.toFixed(2)}</span>` : ''}
            </label>
          `).join('')}
        </div>
      </div>`;
    container.insertAdjacentHTML('beforeend', html);
  });

  // Preselect
  if (currentPreselectedValueIds.length) {
    container.querySelectorAll('input').forEach(inp => {
      const vid = parseInt(inp.value,10);
      if (currentPreselectedValueIds.includes(vid)) {
        inp.checked = true;
        inp.closest('.modifier-option').classList.add('selected');
      }
    });
  }
  gid('modifiersModal').classList.add('show');
}

function closeModifiersModal(){
  gid('modifiersModal').classList.remove('show');
  currentModifierProduct = null;
  currentPreselectedValueIds = [];
}

function toggleModifier(lbl){
  const input = lbl.querySelector('input'); if (!input) return;
  if (input.type==='radio'){
    lbl.closest('.modifier-options').querySelectorAll('.modifier-option').forEach(el=>el.classList.remove('selected'));
  }
  input.checked = !input.checked;
  lbl.classList.toggle('selected', input.checked);
  enforceGroupLimits(lbl.closest('.modifier-options'));
}

function enforceGroupLimits(container){
  const max = parseInt(container.dataset.max||'0');
  if (max>0){
    const selected = container.querySelectorAll('input:checked');
    if (selected.length>max){
      const last = selected[selected.length-1];
      last.checked=false;
      last.closest('.modifier-option').classList.remove('selected');
      showAlert('You can select at most '+max+' option(s) in this group.','warning');
    }
  }
}

function saveSelectedModifiers(){
  if (!currentModifierProduct){ closeModifiersModal(); return; }
  // require min selections
  const requiredGroups = document.querySelectorAll('#modifiersSection .modifier-options[data-min="1"]');
  for (const g of requiredGroups){
    if (!g.querySelector('input:checked')){ showAlert('Please select required modifiers','error'); return; }
  }
  const inputs = document.querySelectorAll('#modifiersSection input:checked');
  const selected = [];
  inputs.forEach(inp => {
    const priceDelta = parseFloat(inp.dataset.priceDelta)||0;
    const grp = inp.closest('.modifier-group');
    const groupName = grp.querySelector('.modifier-group-title').textContent.trim();
    const optionLabel = inp.closest('.modifier-option').querySelector('.modifier-option-label').textContent;
    selected.push({
      group_id: inp.closest('.modifier-options').dataset.groupId,
      group_name: groupName,
      value_id: inp.value,
      value_name: optionLabel,
      price_delta: priceDelta
    });
  });
  selectedModifiersByProduct[currentModifierProduct.product_id] = selected;
  selectedNotesByProduct[currentModifierProduct.product_id] = gid('modalNotes').value || '';
  closeModifiersModal();
}

/* ===== Add product ===== */
function handleAddProduct(productId){
  const product = loadedProducts.find(p => String(p.product_id) === String(productId));
  if (!product) { showAlert('Product not found','error'); return; }
  const row = document.querySelector(`tr[data-product-id="${productId}"]`);
  const qty = Math.max(0.1, num(row.querySelector('.qty-input').value));
  const price = Math.max(0, num(row.querySelector('.price-input').value));
  const available = parseFloat(row.dataset.available || '0');

  if (product.is_inventory_tracked && qty > available) { showAlert('Quantity exceeds available stock','error'); return; }

  const modifiers = selectedModifiersByProduct[productId] || [];
  const notes = selectedNotesByProduct[productId] || '';

  // Merge identical product+modifier lines
  const idx = orderItems.findIndex(it => it.product_id === product.product_id && JSON.stringify(it.modifiers||[]) === JSON.stringify(modifiers||[]));
  if (idx >= 0){ orderItems[idx].quantity += qty; }
  else {
    orderItems.push({
      product_id: product.product_id,
      name: product.product_name,
      quantity: qty,
      unit_price: price,
      discount_percent: 0,
      modifiers: modifiers,
      notes: notes
    });
  }
  renderOrderItems();
  updateOrderItemsPayload();
  recalcTotals();
  showAlert(`Added ${product.product_name} to order`, 'success');
}

/* ===== Order items table ===== */
function renderOrderItems(){
  const tbody = gid('orderItemsBody');
  if (!orderItems.length){
    tbody.innerHTML = `<tr id="orderItemsEmpty"><td colspan="6" class="empty-state"><span class="icon icon-cart"></span>No items added yet</td></tr>`;
    recalcTotals(); 
    return;
  }
  tbody.innerHTML = orderItems.map((it, idx) => {
    // Build modifier display
    const modifiersDisplay = it.modifiers && it.modifiers.length > 0 ? 
      `<div style="font-size:12px;color:var(--text-secondary);margin-top:4px;">
        ${it.modifiers.map(m => `${m.group_name}: ${m.value_name}${m.price_delta ? ` (+${m.price_delta.toFixed(2)})` : ''}`).join(', ')}
      </div>` : '';
    
    const notesDisplay = it.notes ? 
      `<div style="font-size:12px;color:var(--text-tertiary);margin-top:4px;font-style:italic;">Note: ${esc(it.notes)}</div>` : '';

    return `
      <tr data-index="${idx}">
        <td>
          <div style="font-weight:600;margin-bottom:4px;">${esc(it.name)}</div>
          <div style="font-size:12px;color:var(--text-tertiary);">ID: ${it.product_id}</div>
          ${modifiersDisplay}
          ${notesDisplay}
        </td>
        <td class="text-center"><input type="number" class="form-input qty-input" min="0.1" step="0.1" value="${it.quantity}" style="width:70px;height:32px;text-align:center"></td>
        <td class="text-right"><input type="number" class="form-input price-input" min="0" step="0.01" value="${(+it.unit_price).toFixed(2)}" style="width:90px;height:32px;text-align:right"></td>
        <td class="text-right">
          <select class="form-select discount-percent" style="width:120px;height:32px;">
            <option value="0">No Discount</option>
            ${(DISCOUNT_OPTIONS.length ? DISCOUNT_OPTIONS : [{percent:0,label:'No Discount'}])
               .map(o => `<option value="${o.percent}" ${String(it.discount_percent)===String(o.percent)?'selected':''}>${esc(o.label)}</option>`).join('')}
          </select>
        </td>
        <td class="text-right line-total" style="font-weight:600;">${calculateLineTotal(it).toFixed(2)}</td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-danger" onclick="removeOrderItem(${idx})"><span class="icon icon-trash"></span></button></td>
      </tr>`;
  }).join('');

  // Bind inputs
  tbody.querySelectorAll('tr[data-index]').forEach(row => {
    const idx = parseInt(row.dataset.index,10);
    const q = row.querySelector('.qty-input'), p = row.querySelector('.price-input'), d = row.querySelector('.discount-percent');
    q && q.addEventListener('input', ()=>updateOrderItem(idx,row));
    p && p.addEventListener('input', ()=>updateOrderItem(idx,row));
    d && d.addEventListener('change', ()=>updateOrderItem(idx,row));
  });
  recalcTotals();
}

function updateOrderItem(idx,row){
  const qty = Math.max(0.1, num(row.querySelector('.qty-input').value));
  const price = Math.max(0, num(row.querySelector('.price-input').value));
  const disc = Math.max(0, num(row.querySelector('.discount-percent').value));
  orderItems[idx].quantity = qty; 
  orderItems[idx].unit_price = price; 
  orderItems[idx].discount_percent = disc;
  row.querySelector('.line-total').textContent = calculateLineTotal(orderItems[idx]).toFixed(2);
  updateOrderItemsPayload();
  recalcTotals();
}

function calculateLineTotal(it){
  const base = it.quantity * it.unit_price;
  const discount = base * (it.discount_percent||0)/100;
  return base - discount;
}

function removeOrderItem(idx){ 
  orderItems.splice(idx,1); 
  renderOrderItems(); 
  updateOrderItemsPayload(); 
  recalcTotals(); 
}

function updateOrderItemsPayload(){
  const payload = orderItems.map(it => ({
    product_id: it.product_id,
    quantity: it.quantity,
    unit_price: it.unit_price,
    discount_amount: 0,
    discount_percent: it.discount_percent,
    notes: it.notes||'',
    modifiers: it.modifiers||[]
  }));
  gid('order_items_json').value = JSON.stringify(payload);
}

/* ===== Totals (enhanced with better client-side fallback) ===== */
let totalsAbort = null;
function recalcTotals(){
  const itemsPayload = gid('order_items_json').value;
  
  // If no items, clear totals
  if (!itemsPayload || itemsPayload === '[]' || orderItems.length === 0){
    setTotalsDisplay(0,0,0,0,0,0);
    return;
  }

  // Always do client-side calculation first for immediate feedback
  const items = JSON.parse(itemsPayload);
  const baseBeforeDisc = items.reduce((sum,it)=> sum + (Number(it.quantity||0) * Number(it.unit_price||0)), 0);
  const discountAmt = items.reduce((sum,it)=> sum + (Number(it.quantity||0) * Number(it.unit_price||0)) * (Number(it.discount_percent||0)/100), 0);
  const subtotal = Math.max(0, baseBeforeDisc - discountAmt);

  let taxAmount=0, serviceAmount=0, commissionAmount=0;
  
  if (TAX_INCLUSIVE){ 
    taxAmount = subtotal - (subtotal / (1 + (TAX_PERCENT/100))); 
  } else { 
    taxAmount = (TAX_PERCENT/100) * subtotal; 
  }

  if (orderTypeEl.value === 'dine_in'){ 
    serviceAmount = (SERVICE_PERCENT/100) * subtotal; 
  }

  if (orderTypeEl.value === 'delivery' && aggregatorSelect && aggregatorSelect.value){
    const opt = aggregatorSelect.options[aggregatorSelect.selectedIndex];
    const commPct = parseFloat(opt.getAttribute('data-commission') || '0') || 0;
    commissionAmount = (commPct/100) * subtotal;
  }

  const total = subtotal + taxAmount + serviceAmount + commissionAmount;
  setTotalsDisplay(subtotal, discountAmt, taxAmount, serviceAmount, commissionAmount, total);

  // Try server totals API for more accurate calculation (optional enhancement)
  if (totalsAbort) totalsAbort.abort();
  totalsAbort = new AbortController();

  const body = new URLSearchParams({
    tenant_id: String(tenantId),
    branch_id: branchSelect.value || '',
    order_type: orderTypeEl.value || '',
    aggregator_id: aggregatorSelect ? (aggregatorSelect.value || '') : '',
    items_json: itemsPayload
  });

  fetch('/controllers/admin/orders/api/totals.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body,
    signal: totalsAbort.signal
  })
  .then(r => r.json())
  .then(res => {
    if (res && res.success === true && res.data) {
      const d = res.data;
      setTotalsDisplay(+d.subtotal||0, +d.discount||0, +d.tax||0, +d.service||0, +d.commission||0, +d.total||0);
    }
  })
  .catch(() => {
    // Keep client-side calculation if server fails
    console.log('Using client-side totals calculation');
  });
}

function setTotalsDisplay(subtotal, discount, tax, service, commission, total){
  const subtotalEl = gid('subtotalDisplay');
  const discountEl = gid('discountDisplay');
  const taxEl = gid('taxDisplay');
  const serviceEl = gid('serviceDisplay');
  const commissionEl = gid('commissionDisplay');
  const totalEl = gid('totalDisplay');
  
  if (subtotalEl) subtotalEl.textContent = Number(subtotal||0).toFixed(2);
  if (discountEl) discountEl.textContent = Number(discount||0).toFixed(2);
  if (taxEl) taxEl.textContent = Number(tax||0).toFixed(2);
  if (serviceEl) serviceEl.textContent = Number(service||0).toFixed(2);
  if (commissionEl) commissionEl.textContent = Number(commission||0).toFixed(2);
  if (totalEl) totalEl.textContent = Number(total||0).toFixed(2);
  
  console.log('Totals updated:', { subtotal, discount, tax, service, commission, total });
}

if (aggregatorSelect) aggregatorSelect.addEventListener('change', recalcTotals);

/* ===== Enhanced Customer search & management ===== */
const customerSearch = gid('customerSearch');
const customerResults = gid('customerResults');
const customerIdInput = gid('customerId');
const customerNameInput = gid('customerName');
const customerPhoneInput = gid('customerPhone');
let customerSearchTimeout = null;

customerSearch.addEventListener('input', function() {
  const query = this.value.trim();
  
  if (customerSearchTimeout) clearTimeout(customerSearchTimeout);
  
  if (query.length === 0) {
    customerResults.innerHTML = '';
    customerResults.classList.remove('show');
    customerIdInput.value = '';
    hideLoyaltySection();
    return;
  }
  
  if (query.length < 2) return; // Wait for at least 2 characters
  
  customerSearchTimeout = setTimeout(() => searchCustomers(query), 300);
});

function searchCustomers(query) {
  const url = `/controllers/admin/orders/api/search.php?type=customer&q=${encodeURIComponent(query)}&tenant_id=${tenantId}`;
  console.log('Customer search URL:', url); // DEBUG
  
  fetch(url)
    .then(r => {
      console.log('Response status:', r.status);
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(data => {
      console.log('Search response:', data); // DEBUG
      if (data.success && Array.isArray(data.results)) {
        displayCustomerResults(data.results, query);
      } else {
        customerResults.innerHTML = `
          <div class="customer-result" onclick="createNewCustomer('${esc(query)}')">
            <div class="customer-name"><span class="icon icon-plus"></span> Create: "${esc(query)}"</div>
            <div class="customer-details">Click to create a new customer account</div>
          </div>
        `;
        customerResults.classList.add('show');
      }
    })
    .catch(err => {
      console.error('Customer search error:', err);
      customerResults.innerHTML = '';
      customerResults.classList.remove('show');
    });
}

function displayCustomerResults(results, originalQuery) {
  if (results.length === 0) {
    customerResults.innerHTML = `
      <div class="customer-result" onclick="createNewCustomer('${esc(originalQuery)}')">
        <div class="customer-name"><span class="icon icon-plus"></span> Create: "${esc(originalQuery)}"</div>
        <div class="customer-details">Click to create a new customer account</div>
      </div>
    `;
  } else {
    customerResults.innerHTML = results.map(customer => {
      const points = customer.points_balance || 0;
      const phone = customer.phone || '';
      const email = customer.email || '';
      const classification = (customer.classification || 'regular').toUpperCase();
      
      return `
        <div class="customer-result" onclick="selectCustomer(${customer.id}, '${esc(customer.name)}', '${esc(phone)}')">
          <div class="customer-name">${esc(customer.name)}</div>
          <div class="customer-details">
            ${phone ? `${esc(phone)} • ` : ''}
            ${email ? `${esc(email)} • ` : ''}
            ${esc(classification)}
            ${points > 0 ? ` • ${points} pts` : ''}
            ${customer.rewards_enrolled ? ' • Rewards Member' : ''}
          </div>
        </div>
      `;
    }).join('');
  }
  
  customerResults.classList.add('show');
}

function selectCustomer(id, name, phone) {
  customerIdInput.value = id;
  customerNameInput.value = name;
  customerPhoneInput.value = phone;
  customerSearch.value = name;
  customerResults.innerHTML = '';
  customerResults.classList.remove('show');
  
  if (id > 0) {
    loadCustomerLoyalty(id);
  } else {
    hideLoyaltySection();
  }
  showAlert('Customer selected: ' + name, 'success');
}

function createNewCustomer(suggestedName) {
  customerNameInput.value = suggestedName;
  customerPhoneInput.value = '';
  customerSearch.value = suggestedName;
  customerResults.innerHTML = '';
  customerResults.classList.remove('show');
  
  // Focus on phone field for completion
  customerPhoneInput.focus();
  showAlert('Fill in customer details and click Create New Customer', 'info');
}

// Create new customer button
gid('btnCreateCustomer').addEventListener('click', () => {
  const name = customerNameInput.value.trim();
  const phone = customerPhoneInput.value.trim();
  
  if (!name) {
    showAlert('Customer name is required', 'error');
    customerNameInput.focus();
    return;
  }
  
  // Create customer via API
  const body = new URLSearchParams({ 
    action: 'create', 
    name: name, 
    phone: phone, 
    tenant_id: String(tenantId) 
  });
  
  fetch('/controllers/admin/orders/api/customers.php', { 
    method: 'POST', 
    headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
    body 
  })
  .then(r => r.json())
  .then(res => {
    if (res && res.success && res.data && res.data.id) {
      customerIdInput.value = res.data.id;
      showAlert('Customer created successfully!', 'success');
      loadCustomerLoyalty(res.data.id);
    } else {
      showAlert(res.message || 'Could not create customer via API. Customer will be created on order save.', 'warning');
      customerIdInput.value = '';
    }
  })
  .catch(() => {
    showAlert('Customer will be created when the order is saved', 'info');
    customerIdInput.value = '';
  });
});

/* ===== Loyalty display ===== */
function loadCustomerLoyalty(customerId) {
  fetch(`/controllers/admin/orders/api/loyalty.php?action=customer_loyalty&customer_id=${customerId}&tenant_id=${tenantId}`)
    .then(r => r.json())
    .then(res => { 
      if (res && res.success) displayLoyaltyInfo(res.data); 
      else hideLoyaltySection(); 
    })
    .catch(hideLoyaltySection);
}

function displayLoyaltyInfo(data) {
  gid('loyaltySection').classList.remove('hidden');
  
  // Display stats
  const loyaltyStats = gid('loyaltyStats');
  let statsHtml = '';
  if (data.points_balance !== undefined) {
    statsHtml += `
      <div class="loyalty-stat">
        <span class="loyalty-stat-value">${data.points_balance}</span>
        <span class="loyalty-stat-label">Points</span>
      </div>
    `;
  }
  if (data.tier_code) {
    statsHtml += `
      <div class="loyalty-stat">
        <span class="loyalty-stat-value">${esc(data.tier_code)}</span>
        <span class="loyalty-stat-label">Tier</span>
      </div>
    `;
  }
  if (data.lifetime_points !== undefined) {
    statsHtml += `
      <div class="loyalty-stat">
        <span class="loyalty-stat-value">${data.lifetime_points}</span>
        <span class="loyalty-stat-label">Lifetime</span>
      </div>
    `;
  }
  loyaltyStats.innerHTML = statsHtml;
  
  // Populate loyalty programs
  const prog = gid('loyaltyProgramSelect'); 
  prog.innerHTML = `<option value="">No program selected</option>`;
  (data.programs||[]).forEach(p => { 
    const o = document.createElement('option'); 
    o.value = p.id; 
    o.textContent = p.name; 
    prog.appendChild(o); 
  });
  
  // Populate vouchers
  const vSel = gid('voucherSelect'); 
  vSel.innerHTML = `<option value="">No voucher selected</option>`;
  (data.vouchers||[]).forEach(v => { 
    vSel.innerHTML += `<option value="${v.id}" data-value="${v.value}" data-type="${v.type}">${esc(v.code)} — ${v.type==='percent'?v.value+'%':(v.value+' '+CURRENCY)}</option>`; 
  });
}

function hideLoyaltySection() { 
  gid('loyaltySection').classList.add('hidden'); 
}

// Loyalty program selection
gid('loyaltyProgramSelect').addEventListener('change', function(){
  gid('loyalty_program_id').value = this.value || '';
  const has = !!this.value;
  gid('rewardsPreview').classList.toggle('hidden', !has);
  if (has) { 
    gid('rewardsPreviewText').textContent = 'Program selected — rewards will be calculated at checkout.'; 
  }
});

/* ===== Submit hook (capture vouchers) ===== */
gid('orderForm').addEventListener('submit', function(e){
  // Validation
  if (!branchSelect.value) {
    e.preventDefault();
    showAlert('Please select a branch.', 'error');
    branchSelect.focus();
    return;
  }
  
  if (orderItems.length === 0) {
    e.preventDefault();
    showAlert('Please add at least one item to the order.', 'error');
    return;
  }
  
  if (orderTypeEl.value === 'delivery' && !aggregatorSelect.value) {
    e.preventDefault();
    showAlert('Please select a delivery partner for delivery orders.', 'error');
    aggregatorSelect.focus();
    return;
  }
  
  // Capture voucher redemptions
  const vSel = gid('voucherSelect');
  if (vSel && vSel.value){
    const opt = vSel.options[vSel.selectedIndex]; 
    const amount = parseFloat(opt.dataset.value||'0')||0;
    gid('voucher_redemptions').value = JSON.stringify([{ voucher_id: vSel.value, amount_applied: amount }]);
  } else {
    gid('voucher_redemptions').value = '[]';
  }
  
  updateOrderItemsPayload(); // ensure latest
  showAlert('Creating order...', 'info');
});

/* ===== Initial auto-selects ===== */
(function init(){
  // Auto-select single branch
  const opts = [...branchSelect.options].filter(o => o.value);
  if (opts.length === 1) { 
    branchSelect.value = opts[0].value; 
    loadProducts();
  }
  
  // Initialize order type handling
  orderTypeEl.dispatchEvent(new Event('change'));
  
  // Initial totals
  recalcTotals();
  
  // Click outside to hide dropdowns
  document.addEventListener('click', function(e) {
    if (!e.target.closest('.customer-search-container')) {
      customerResults.classList.remove('show');
    }
    
    // Close modals when clicking outside
    if (e.target.classList.contains('modal')) {
      e.target.classList.remove('show');
    }
  });
  
  console.log('Enhanced order creation page initialized with tenant:', tenantId);
})();
</script>
</body>
</html>