<?php
declare(strict_types=1);
/**
 * New Modifier (Variation Group)
 * Path: /public_html/views/admin/catalog/modifier_new.php
 * - Posts to /controllers/admin/modifiers_save.php
 */

/* Debug */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* Bootstrap /config/db.php */
if (!function_exists('db')) {
  $BOOT_OK = false;
  $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
  foreach ([
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../../../config/db.php',
    dirname(__DIR__, 3) . '/config/db.php',
    ($docroot ? $docroot . '/config/db.php' : ''),
    ($docroot ? $docroot . '/public_html/config/db.php' : ''),
  ] as $cand) { if ($cand && is_file($cand)) { require_once $cand; $BOOT_OK = true; break; } }
  if (!$BOOT_OK) { http_response_code(500); echo 'Configuration file not found: /config/db.php'; exit; }
}

/* Session + Auth */
if (function_exists('use_backend_session')) use_backend_session(); else if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!function_exists('auth_require_login')) {
  $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
  foreach ([
    __DIR__ . '/../../middleware/auth_login.php',
    __DIR__ . '/../../../middleware/auth_login.php',
    dirname(__DIR__,3).'/middleware/auth_login.php',
    ($docroot ? $docroot.'/middleware/auth_login.php' : ''),
    ($docroot ? $docroot.'/public_html/middleware/auth_login.php' : ''),
  ] as $cand) { if ($cand && is_file($cand)) { require_once $cand; break; } }
}
if (function_exists('auth_require_login')) auth_require_login();

/* Context */
$pdo = db();
$user = $_SESSION['user'] ?? null;
$tenantId = (int)($user['tenant_id'] ?? ($_SESSION['tenant_id'] ?? 0));
if ($tenantId <= 0) { http_response_code(500); echo 'Tenant context missing.'; exit; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
$active = 'modifiers';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>New Modifier · Smorll POS</title>
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
      --transition: all .1s cubic-bezier(.1,.9,.2,1);
      --radius: 4px;
      --radius-lg: 8px;
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
      line-height: 1.4;
    }

    /* Reset default margins */
    h1, h2, h3, h4, h5, h6, p {
      margin: 0;
    }

    /* Page Container */
    .page-container {
      padding: 12px;
      max-width: 800px;
      margin: 0 auto;
    }

    @media (max-width: 768px) {
      .page-container {
        padding: 8px;
      }
    }

    /* Breadcrumb */
    .breadcrumb {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 12px;
      font-size: 13px;
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

    /* Page Header */
    .page-header {
      margin-bottom: 16px;
    }

    .page-title {
      font-size: 20px;
      font-weight: 600;
      color: var(--text-primary);
      margin: 0 0 2px 0;
    }

    .page-subtitle {
      font-size: 13px;
      color: var(--text-secondary);
      margin: 0;
    }

    /* Form Card */
    .form-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-sm);
      overflow: hidden;
    }

    /* Form Sections */
    .form-section {
      padding: 20px;
      border-bottom: 1px solid var(--border);
    }

    .form-section:last-child {
      border-bottom: none;
    }

    .section-header {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 16px;
    }

    .section-icon {
      width: 28px;
      height: 28px;
      background: var(--primary-light);
      color: var(--primary);
      border-radius: var(--radius);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
    }

    .section-title {
      font-size: 16px;
      font-weight: 600;
      color: var(--text-primary);
    }

    .section-subtitle {
      font-size: 13px;
      color: var(--text-secondary);
      margin-top: 1px;
    }

    /* Form Grid */
    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .form-group.full {
      grid-column: 1 / -1;
    }

    /* Labels */
    .form-label {
      font-size: 13px;
      font-weight: 500;
      color: var(--text-primary);
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .required {
      color: var(--danger);
      font-weight: 400;
    }

    /* Form Controls */
    .form-input,
    .form-select {
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      font-size: 13px;
      font-family: inherit;
      background: var(--card-bg);
      color: var(--text-primary);
      transition: var(--transition);
      outline: none;
    }

    .form-input:hover,
    .form-select:hover {
      border-color: var(--text-secondary);
    }

    .form-input:focus,
    .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    }

    .form-input::placeholder {
      color: var(--text-tertiary);
    }

    .form-input[type="number"] {
      font-family: 'SF Mono', Monaco, 'Courier New', monospace;
    }

    .form-select {
      cursor: pointer;
      appearance: none;
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%238a8886' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
      background-position: right 10px center;
      background-repeat: no-repeat;
      background-size: 16px;
      padding-right: 40px;
    }

    /* Toggle Switch */
    .toggle-group {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      padding: 12px;
      background: var(--bg-secondary);
      border-radius: var(--radius);
    }

    .toggle-content {
      flex: 1;
    }

    .toggle-title {
      font-size: 13px;
      font-weight: 500;
      color: var(--text-primary);
      margin-bottom: 2px;
    }

    .toggle-description {
      font-size: 11px;
      color: var(--text-secondary);
    }

    .toggle-switch {
      width: 40px;
      height: 20px;
      background: #cbd5e1;
      border-radius: 10px;
      position: relative;
      cursor: pointer;
      transition: var(--transition);
      flex-shrink: 0;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
      position: absolute;
    }

    .toggle-slider {
      position: absolute;
      top: 2px;
      left: 2px;
      width: 16px;
      height: 16px;
      background: white;
      border-radius: 50%;
      transition: var(--transition);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .toggle-switch input:checked ~ .toggle-slider {
      transform: translateX(20px);
    }

    .toggle-switch.checked {
      background: var(--primary);
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 10px 16px;
      border: 1px solid transparent;
      border-radius: var(--radius);
      font-size: 13px;
      font-weight: 500;
      text-decoration: none;
      cursor: pointer;
      transition: var(--transition);
      outline: none;
      justify-content: center;
    }

    .btn:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    .btn:active {
      transform: translateY(0);
    }

    .btn-primary {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
    }

    .btn-primary:hover {
      background: var(--primary-hover);
      border-color: var(--primary-hover);
      color: white;
      text-decoration: none;
    }

    .btn-secondary {
      background: var(--card-bg);
      color: var(--text-primary);
      border-color: var(--border);
    }

    .btn-secondary:hover {
      background: var(--hover);
      color: var(--text-primary);
      text-decoration: none;
    }

    .btn-ghost {
      background: transparent;
      color: var(--text-secondary);
      border-color: transparent;
    }

    .btn-ghost:hover {
      background: var(--hover);
      color: var(--text-primary);
      text-decoration: none;
      box-shadow: none;
    }

    /* Form Footer */
    .form-footer {
      padding: 16px 20px;
      background: var(--bg-secondary);
      border-top: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
    }

    .form-actions {
      display: flex;
      gap: 8px;
    }

    /* Help Text */
    .help-text {
      font-size: 11px;
      color: var(--text-secondary);
      margin-top: 4px;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .form-footer {
        flex-direction: column-reverse;
        gap: 8px;
      }
      
      .form-actions {
        width: 100%;
        flex-direction: column;
      }
    }
  </style>
</head>
<body>

<?php
/* Admin navbar (robust include) */
$__docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
foreach ([
  __DIR__ . '/../partials/admin_nav.php',
  __DIR__ . '/../../partials/admin_nav.php',
  ($__docroot ? $__docroot . '/views/partials/admin_nav.php' : ''),
  ($__docroot ? $__docroot . '/public_html/views/partials/admin_nav.php' : ''),
] as $__nav) { if ($__nav && is_file($__nav)) { include $__nav; break; } }
?>

<div class="page-container">
  <!-- Breadcrumb -->
  <div class="breadcrumb">
    <a href="/views/admin/catalog/modifiers.php" class="breadcrumb-link">Modifiers</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">New Modifier</span>
  </div>

  <!-- Page Header -->
  <div class="page-header">
    <h1 class="page-title">New Modifier</h1>
    <p class="page-subtitle">Create a new modifier group for your products</p>
  </div>

  <!-- Form -->
  <form class="form-card" method="post" action="/controllers/admin/modifiers_save.php">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="sort_order" value="999">
    
    <!-- Basic Information -->
    <div class="form-section">
      <div class="section-header">
        <div class="section-icon">🔧</div>
        <div>
          <div class="section-title">Basic Information</div>
          <div class="section-subtitle">Essential modifier details</div>
        </div>
      </div>
      
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">
            Modifier Name 
            <span class="required">*</span>
          </label>
          <input type="text" name="name" class="form-input" required 
                 placeholder="e.g., Size, Sauce, Extra Toppings" autofocus>
        </div>
        
        <div class="form-group">
          <label class="form-label">Customer Required</label>
          <select name="is_required" class="form-select">
            <option value="0" selected>No</option>
            <option value="1">Yes</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Selection Rules -->
    <div class="form-section">
      <div class="section-header">
        <div class="section-icon">📊</div>
        <div>
          <div class="section-title">Selection Rules</div>
          <div class="section-subtitle">Configure selection constraints</div>
        </div>
      </div>
      
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Min Select</label>
          <input type="number" name="min_select" class="form-input" 
                 value="0" min="0" step="1">
          <div class="help-text">Minimum number of options customer must select</div>
        </div>
        
        <div class="form-group">
          <label class="form-label">Max Select</label>
          <input type="number" name="max_select" class="form-input" 
                 value="1" min="1" step="1">
          <div class="help-text">Maximum number of options customer can select</div>
        </div>
      </div>
    </div>

    <!-- Status Settings -->
    <div class="form-section">
      <div class="section-header">
        <div class="section-icon">⚙️</div>
        <div>
          <div class="section-title">Status Settings</div>
          <div class="section-subtitle">Configure modifier availability</div>
        </div>
      </div>
      
      <div class="form-grid">
        <div class="form-group">
          <div class="toggle-group">
            <div class="toggle-content">
              <div class="toggle-title">Active Status</div>
              <div class="toggle-description">Active modifiers can be used</div>
            </div>
            <label class="toggle-switch checked">
              <input type="checkbox" name="is_active" value="1" checked>
              <span class="toggle-slider"></span>
            </label>
          </div>
        </div>
        
        <div class="form-group">
          <div class="toggle-group">
            <div class="toggle-content">
              <div class="toggle-title">POS Visibility</div>
              <div class="toggle-description">Show in point of sale system</div>
            </div>
            <label class="toggle-switch checked">
              <input type="checkbox" name="pos_visible" value="1" checked>
              <span class="toggle-slider"></span>
            </label>
          </div>
        </div>
      </div>
    </div>

    <!-- Form Footer -->
    <div class="form-footer">
      <div class="form-actions">
        <a href="/views/admin/catalog/modifiers.php" class="btn btn-ghost">Cancel</a>
      </div>
      <button type="submit" class="btn btn-primary">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M12 5v14m7-7H5"/>
        </svg>
        Create Modifier
      </button>
    </div>
  </form>
</div>

<script>
// Toggle switches
document.querySelectorAll('.toggle-switch').forEach(toggle => {
  const checkbox = toggle.querySelector('input[type="checkbox"]');
  
  checkbox.addEventListener('change', () => {
    toggle.classList.toggle('checked', checkbox.checked);
  });
  
  // Initial state is already set from PHP
});

// Auto-format number inputs
document.querySelectorAll('input[type="number"]').forEach(input => {
  input.addEventListener('blur', () => {
    if (input.value && input.min !== undefined) {
      const min = parseInt(input.min);
      if (parseInt(input.value) < min) {
        input.value = min.toString();
      }
    }
  });
});
</script>
</body>
</html>