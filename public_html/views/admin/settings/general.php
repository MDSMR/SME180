<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';
auth_require_login();

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { 
    @ini_set('display_errors','1'); 
    @ini_set('display_startup_errors','1'); 
    error_reporting(E_ALL); 
}

// Use backend session (already started by auth_require_login)
$user = $_SESSION['user'] ?? null;
if (!$user) { 
    header('Location: /views/auth/login.php'); 
    exit; 
}

// Helper function for HTML escaping
function h($s){ 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

// Generate CSRF token if not exists
if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/* Front-end defaults (actual values load via APIs) */
$identity = [
    'restaurant_name' => 'Smorll', 
    'logo_url' => '', 
    'contact_email' => 'hello@smorll.com',
    'contact_phone' => '+20 100 123 4567', 
    'website' => 'https://smorll.com',
    'description' => 'Fresh, delicious meals delivered to your door',
];

$operations = [
    'currency'=>'EGP',
    'timezone'=>'Africa/Cairo',
    'rounding_rule'=>'nearest',
    'tax_inclusive'=>0,
    'service_charge_auto'=>1,
    'language_primary'=>'en',
];

$receipt = [
    'paper_size'=>'80mm',
    'header_text'=>'Welcome to Smorll Restaurant',
    'footer_text'=>'Thank you for dining with us!',
    'terms_text'=>'All prices include applicable taxes. No refunds on perishable items.',
    'show_logo'=>1,
    'show_qr'=>1,
];

$active = 'settings_general';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>General Settings · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php
$csrf = csrf_token();
if ($csrf !== '') {
    echo '<meta name="csrf-token" content="'.h($csrf).'">' . "\n";
}
?>
<style>
:root {
  --primary:#3b82f6; --primary-50:#eff6ff; --primary-600:#2563eb; --success:#10b981; --success-50:#ecfdf5;
  --danger:#ef4444; --warning:#f59e0b; --warning-50:#fffbeb; --slate-50:#f8fafc; --slate-100:#f1f5f9; --slate-200:#e2e8f0;
  --slate-300:#cbd5e1; --slate-400:#94a3b8; --slate-500:#64748b; --slate-600:#475569; --slate-700:#334155; --slate-900:#0f172a;
  --white:#ffffff; --shadow-sm:0 1px 3px 0 rgb(0 0 0 / 0.1); --shadow-md:0 4px 6px -1px rgb(0 0 0 / 0.1);
}
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Inter',sans-serif; background:linear-gradient(135deg,#fafbfc 0%,#f1f5f9 100%); color:var(--slate-900); line-height:1.5; font-size:14px; min-height:100vh; }
.container { max-width:1200px; margin:0 auto; padding:20px; }

/* Header with better save button */
.header { position:sticky; top:0; z-index:50; background:rgba(255,255,255,0.95); backdrop-filter:blur(12px); border-bottom:1px solid var(--slate-200); margin:0 -20px 20px -20px; padding:16px 20px; }
.header-content { display:flex; align-items:center; justify-content:space-between; gap:20px; }
.header h1 { font-size:20px; font-weight:700; margin:0; }
.global-save-area { display:flex; align-items:center; gap:12px; }
.global-status { 
  padding:6px 12px; border-radius:6px; font-size:12px; font-weight:600; 
  display:flex; align-items:center; gap:6px; min-width:120px;
}
.global-status.saved { background:var(--success-50); color:#065f46; }
.global-status.unsaved { background:var(--warning-50); color:#92400e; }
.global-status-indicator { width:8px; height:8px; border-radius:50%; }
.global-status.saved .global-status-indicator { background:#10b981; }
.global-status.unsaved .global-status-indicator { background:#f59e0b; }

/* Grid / cards with better spacing */
.grid { display:grid; gap:24px; }
.grid-2 { grid-template-columns:repeat(auto-fit,minmax(420px,1fr)); }
.grid-full { grid-column:1 / -1; }
.card { background:var(--white); border:1px solid var(--slate-200); border-radius:16px; box-shadow:var(--shadow-sm); overflow:hidden; transition:all 0.2s ease; }
.card:hover { box-shadow:var(--shadow-md); }
.card-header { padding:20px 20px 16px; border-bottom:1px solid var(--slate-200); background:linear-gradient(135deg,var(--white) 0%,var(--slate-50) 100%); }
.card-header h2 { font-size:16px; font-weight:600; margin:0 0 4px; display:flex; align-items:center; gap:8px; }
.card-header p { color:var(--slate-600); font-size:13px; margin:0; line-height:1.4; }
.card-body { padding:24px; }

/* Simplified footer without individual save buttons */
.card-footer { 
  padding:12px 20px; border-top:1px solid var(--slate-200); 
  display:flex; align-items:center; justify-content:center;
  background:var(--slate-50); font-size:12px; color:var(--slate-500);
}

/* Form improvements with better labels and help */
.form-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:20px; margin-bottom:20px; }
.form-row:last-child { margin-bottom:0; }
.form-group { display:flex; flex-direction:column; }
.form-group.form-full { grid-column:1 / -1; }
.form-label { font-weight:600; font-size:13px; margin-bottom:8px; color:var(--slate-700); display:flex; align-items:center; gap:6px; }
.form-label.required::after { content:'*'; color:var(--danger); font-weight:700; }
.form-input, .form-select, .form-textarea { 
  width:100%; padding:12px 16px; border:2px solid var(--slate-200); border-radius:8px; 
  font-size:13px; font-family:inherit; background:var(--white); transition:all 0.2s ease; outline:none; 
}
.form-input:focus, .form-select:focus, .form-textarea:focus { 
  border-color:var(--primary); box-shadow:0 0 0 3px var(--primary-50); 
}
.form-textarea { min-height:80px; resize:vertical; }
.form-help { color:var(--slate-500); font-size:11px; margin-top:6px; line-height:1.3; }
.form-help.important { color:var(--primary-600); font-weight:500; }

/* Enhanced toggle switch */
.switch { position:relative; width:50px; height:28px; display:inline-block; vertical-align:middle; }
.switch input { display:none; }
.switch-slider { 
  position:absolute; inset:0; background:var(--slate-300); border-radius:999px; 
  transition:all .25s ease; cursor:pointer;
}
.switch-slider::after { 
  content:''; position:absolute; top:3px; left:3px; width:22px; height:22px; 
  background:#fff; border-radius:50%; transition:all .25s ease; 
  box-shadow:0 2px 4px rgba(0,0,0,.2); 
}
.switch input:checked + .switch-slider { background:var(--primary); }
.switch input:checked + .switch-slider::after { transform:translateX(22px); }
.switch-group { display:flex; align-items:center; gap:12px; margin-top:8px; }

/* Workflow option styling */
.workflow-option { 
  display:flex; align-items:start; gap:12px; padding:16px; 
  border:2px solid var(--slate-200); border-radius:8px; cursor:pointer; 
  transition:all 0.2s ease; 
}
.workflow-option input[type="radio"]:checked + div strong { color:var(--primary); }
.workflow-option:has(input[type="radio"]:checked) { 
  border-color:var(--primary); background:var(--primary-50); 
}
.workflow-option:hover { 
  border-color:var(--slate-300); background:var(--slate-50); 
}
.workflow-option:has(input[type="radio"]:checked):hover { 
  border-color:var(--primary); background:var(--primary-50); 
}

/* Enhanced table for mobile */
.table-container { background:var(--white); border-radius:12px; overflow:hidden; border:1px solid var(--slate-200); }
.table { width:100%; border-collapse:collapse; font-size:13px; }
.table th { 
  background:var(--slate-100); color:var(--slate-600); font-weight:600; 
  font-size:11px; text-transform:uppercase; letter-spacing:.05em; 
  padding:16px 12px; border-bottom:2px solid var(--slate-200); text-align:left; 
}
.table td { padding:12px; border-bottom:1px solid var(--slate-200); vertical-align:middle; }
.table-actions { display:flex; gap:6px; justify-content:flex-end; }

/* Mobile table adjustments */
@media (max-width: 768px) {
  .table-container { overflow-x: auto; }
  .table { min-width: 700px; }
  .table th, .table td { padding: 8px; font-size: 12px; }
  .table-actions { flex-direction: column; gap: 4px; }
  .table-actions .btn { font-size: 11px; padding: 6px 12px; }
}

/* Badges and buttons */
.badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:600; }
.badge-success { background:var(--success-50); color:#065f46; }
.badge-inactive { background:var(--slate-100); color:var(--slate-600); }

.btn { 
  display:inline-flex; align-items:center; gap:6px; padding:8px 18px; 
  border:2px solid var(--slate-200); background:var(--white); border-radius:8px; 
  font-weight:600; font-size:12px; cursor:pointer; transition:all .2s ease; 
  text-decoration:none; color:var(--slate-900); white-space:nowrap;
}
.btn:hover:not([disabled]) { background:var(--slate-50); border-color:var(--slate-300); transform:translateY(-1px); }
.btn-primary { background:var(--primary); color:#fff; border-color:var(--primary); }
.btn-primary:hover:not([disabled]) { background:var(--primary-600); transform:translateY(-1px); }
.btn-primary[disabled] { opacity:.6; cursor:not-allowed; transform:none; }
.btn-danger { background:var(--danger); color:#fff; border-color:var(--danger); }
.btn-danger:hover:not([disabled]) { background:#dc2626; transform:translateY(-1px); }

/* Global save button styling */
.btn-save-global { 
  padding:10px 24px; font-size:14px; font-weight:600; 
  box-shadow:var(--shadow-sm); min-width:120px; justify-content:center;
}
.btn-save-global[disabled] { 
  background:var(--slate-100); color:var(--slate-400); border-color:var(--slate-200); 
  box-shadow:none; cursor:not-allowed;
}

/* Enhanced notices and modals */
.notice { 
  background:var(--warning-50); border:1px solid #fcd34d; color:#92400e; 
  padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:13px; 
  display:flex; align-items:start; gap:8px;
}
.notice-icon { margin-top:1px; }

.modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,.75); display:flex; align-items:center; justify-content:center; z-index:1000; padding:20px; }
.modal-content { background:#fff; border-radius:16px; max-width:650px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgb(0 0 0 / 0.1); }
.modal-header { padding:20px; border-bottom:1px solid var(--slate-200); display:flex; align-items:center; justify-content:space-between; background:var(--slate-50); }
.modal-header h3 { margin:0; font-size:16px; font-weight:600; }
.modal-body { padding:24px; }
.modal-footer { padding:20px; border-top:1px solid var(--slate-200); display:flex; justify-content:flex-end; gap:12px; background:var(--slate-50); }

/* Enhanced toast */
.toast { 
  position:fixed; top:20px; right:20px; background:#10b981; color:#fff; 
  padding:12px 20px; border-radius:8px; font-weight:600; z-index:1001; 
  display:flex; align-items:center; gap:8px; font-size:13px; 
  box-shadow:var(--shadow-md); min-width:200px;
}
.toast.error { background:#ef4444; }

/* Section icons */
.section-icon { width:18px; height:18px; opacity:0.7; }

/* Responsive adjustments */
@media (max-width:1024px){ 
  .grid-2 { grid-template-columns:1fr; }
  .header-content { flex-direction:column; gap:12px; align-items:stretch; }
  .global-save-area { justify-content:center; }
}
@media (max-width:640px){ 
  .container { padding:16px; }
  .card-body { padding:20px; }
  .form-row { grid-template-columns:1fr; gap:16px; }
}

/* Floating Save Button */
.floating-save-btn { 
  position:fixed; bottom:24px; right:24px; z-index:200;
  background:var(--primary); color:white; border:none; border-radius:12px;
  padding:16px 24px; font-size:14px; font-weight:600; cursor:pointer;
  box-shadow:0 8px 25px rgba(59, 130, 246, 0.25); transition:all 0.3s ease;
  display:none; align-items:center; gap:8px; min-width:160px; justify-content:center;
}
.floating-save-btn.show { display:flex; animation:slideUp 0.3s ease-out; }
.floating-save-btn:hover:not([disabled]) { 
  background:var(--primary-600); transform:translateY(-2px); 
  box-shadow:0 12px 35px rgba(59, 130, 246, 0.35);
}
.floating-save-btn[disabled] { 
  background:var(--slate-400); cursor:not-allowed; transform:none;
  box-shadow:0 4px 12px rgba(0,0,0,0.1);
}
.floating-save-btn .save-icon { width:16px; height:16px; }

@keyframes slideUp { 
  from { opacity:0; transform:translateY(20px); }
  to { opacity:1; transform:translateY(0); }
}

/* Mobile adjustments for floating elements */
@media (max-width:640px){ 
  .floating-save-btn { 
    right:16px; bottom:16px; padding:14px 20px; font-size:13px; min-width:140px;
  }
}
</style>
</head>
<body>

<?php
  $active = 'settings_general';
  $nav_candidates = [
    __DIR__ . '/../partials/admin_nav.php',
    dirname(__DIR__, 2) . '/partials/admin_nav.php',
    dirname(__DIR__, 1) . '/partials/admin_nav.php'
  ];
  $nav_included = false;
  foreach ($nav_candidates as $cand) { 
    if (is_file($cand)) { 
      include $cand; 
      $nav_included = true; 
      break; 
    } 
  }
  if (!$nav_included) {
    echo '<div style="background:#0f172a;color:#fff;padding:12px 20px;font-weight:600">Smorll – Admin</div>';
  }
?>

<div class="header">
  <div class="container">
    <div class="header-content">
      <h1>General Settings</h1>
      <div class="global-save-area">
        <div class="global-status saved" id="globalStatus">
          <div class="global-status-indicator"></div>
          <span>All changes saved</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="container">
  <div class="grid grid-2">
    <!-- Restaurant Identity -->
    <div class="card">
      <div class="card-header">
        <h2>
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 8h5"></path>
          </svg>
          Restaurant Identity
        </h2>
        <p>Basic information and branding displayed to customers</p>
      </div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label required">Restaurant Name</label>
            <input id="restaurant_name" class="form-input" value="<?= h($identity['restaurant_name']) ?>" required>
            <div class="form-help">This appears on receipts, invoices, and customer-facing materials</div>
          </div>
          <div class="form-group">
            <label class="form-label">Website</label>
            <input id="website" class="form-input" value="<?= h($identity['website']) ?>">
            <div class="form-help">Your restaurant's website URL (optional)</div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Contact Email</label>
            <input id="contact_email" class="form-input" type="email" value="<?= h($identity['contact_email']) ?>">
            <div class="form-help">Primary email for customer inquiries</div>
          </div>
          <div class="form-group">
            <label class="form-label">Contact Phone</label>
            <input id="contact_phone" class="form-input" value="<?= h($identity['contact_phone']) ?>">
            <div class="form-help">Main phone number for customer contact</div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group form-full">
            <label class="form-label">Description</label>
            <textarea id="description" class="form-textarea"><?= h($identity['description']) ?></textarea>
            <div class="form-help">Brief description of your restaurant (appears in online listings)</div>
          </div>
        </div>
      </div>
      <div class="card-footer">
        Restaurant branding and contact information
      </div>
    </div>

    <!-- Business Operations -->
    <div class="card">
      <div class="card-header">
        <h2>
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
          </svg>
          Business Operations
        </h2>
        <p>Currency, timezone, language, and operational defaults</p>
      </div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label required">Currency</label>
            <select id="currency" class="form-select" required>
              <?php 
                $currencies = ['EGP'=>'Egyptian Pound (EGP)','USD'=>'US Dollar (USD)','EUR'=>'Euro (EUR)','SAR'=>'Saudi Riyal (SAR)','AED'=>'UAE Dirham (AED)','KWD'=>'Kuwaiti Dinar (KWD)'];
                foreach($currencies as $c=>$l) echo '<option value="'.h($c).'">'.h($l).'</option>';
              ?>
            </select>
            <div class="form-help important">Changing currency affects all pricing. Existing orders remain unchanged.</div>
          </div>
          <div class="form-group">
            <label class="form-label required">Default Timezone</label>
            <select id="timezone" class="form-select" required>
              <?php 
                $timezones = ['Africa/Cairo'=>'Cairo (GMT+2)','Asia/Riyadh'=>'Riyadh (GMT+3)','Asia/Dubai'=>'Dubai (GMT+4)','Asia/Kuwait'=>'Kuwait (GMT+3)','Europe/London'=>'London (GMT+0)','America/New_York'=>'New York (GMT-5)'];
                foreach($timezones as $tz=>$l) echo '<option value="'.h($tz).'">'.h($l).'</option>';
              ?>
            </select>
            <div class="form-help">Timezone for reports and timestamps</div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Primary Language</label>
            <select id="language_primary" class="form-select">
              <?php foreach(['en'=>'English','ar'=>'Arabic','fr'=>'French'] as $code=>$label) echo '<option value="'.h($code).'">'.h($label).'</option>'; ?>
            </select>
            <div class="form-help">Default language for system interface</div>
          </div>

          <!-- Tax-Inclusive Pricing (toggle) -->
          <div class="form-group">
            <label class="form-label">Tax-Inclusive Pricing</label>
            <div class="switch-group">
              <label class="switch" title="Show menu prices with taxes included">
                <input type="checkbox" id="tax_inclusive">
                <span class="switch-slider"></span>
              </label>
              <span style="font-size:12px; color:var(--slate-600);">Include tax in displayed prices</span>
            </div>
            <div class="form-help important">When enabled, menu prices shown to customers include tax. Tax will be calculated within the price rather than added on top.</div>
          </div>
        </div>

        <!-- Default Login Branch -->
        <div class="form-row">
          <div class="form-group form-full">
            <label class="form-label">Default Login Branch</label>
            <select id="default_branch_id" class="form-select">
              <option value="">– Select default branch –</option>
            </select>
            <div class="form-help">Branch automatically selected when staff log in to the POS. Staff can change this at any time.</div>
          </div>
        </div>
      </div>
      <div class="card-footer">
        Core business settings and operational defaults
      </div>
    </div>
  </div>

  <!-- Stock Transfer Workflow Settings -->
  <div class="card grid-full">
    <div class="card-header">
      <h2>
        <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3-3m0 0l-3 3m3-3v12"></path>
        </svg>
        Stock Transfer Workflow
      </h2>
      <p>Configure how stock transfers work between branches</p>
    </div>
    <div class="card-body">
      <!-- Primary Workflow Mode -->
      <div class="form-row">
        <div class="form-group form-full">
          <label class="form-label required">Transfer Workflow Mode</label>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 8px;">
            <label class="workflow-option">
              <input type="radio" name="transfer_workflow_mode" value="one_step" id="workflow_one_step" style="margin-top: 2px;">
              <div>
                <strong style="display: block; color: var(--slate-900); font-size: 14px; margin-bottom: 4px;">One-Step (Transfer Now)</strong>
                <div style="font-size: 12px; color: var(--slate-600); line-height: 1.4;">Create and complete transfer in one action. Stock moves immediately and transfer status becomes "received".</div>
              </div>
            </label>
            <label class="workflow-option">
              <input type="radio" name="transfer_workflow_mode" value="two_step" id="workflow_two_step" style="margin-top: 2px;">
              <div>
                <strong style="display: block; color: var(--slate-900); font-size: 14px; margin-bottom: 4px;">Two-Step (Ship → Receive)</strong>
                <div style="font-size: 12px; color: var(--slate-600); line-height: 1.4;">Create transfer as pending, then ship and receive separately. Better control and tracking.</div>
              </div>
            </label>
          </div>
          <div class="form-help important">
            <strong>Important:</strong> This setting only affects NEW transfers. Existing transfers continue with their current workflow.
          </div>
        </div>
      </div>

      <!-- Two-Step Mode Options (conditionally visible) -->
      <div id="twoStepOptions" style="display: none;">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Allow "Ship on Create"</label>
            <div class="switch-group">
              <label class="switch" title="Show Create & Ship button for eligible users">
                <input type="checkbox" id="transfer_allow_ship_on_create">
                <span class="switch-slider"></span>
              </label>
              <span style="font-size:12px; color:var(--slate-600);">Enable "Create & Ship" button</span>
            </div>
            <div class="form-help">When enabled, users with ship permission for the source branch can create and ship transfers in one action (but still require separate receiving).</div>
          </div>

          <div class="form-group">
            <label class="form-label">Separation of Duties</label>
            <div class="switch-group">
              <label class="switch" title="Prevent same user from shipping and receiving">
                <input type="checkbox" id="transfer_separation_of_duties">
                <span class="switch-slider"></span>
              </label>
              <span style="font-size:12px; color:var(--slate-600);">Require different users for ship/receive</span>
            </div>
            <div class="form-help">When enabled, the same user cannot both ship AND receive the same transfer. Improves internal controls and prevents fraud.</div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Reserve Stock on Pending</label>
            <div class="switch-group">
              <label class="switch" title="Reserve stock when transfer is created">
                <input type="checkbox" id="transfer_reserve_on_pending">
                <span class="switch-slider"></span>
              </label>
              <span style="font-size:12px; color:var(--slate-600);">Reserve stock for pending transfers</span>
            </div>
            <div class="form-help">When enabled, stock is reserved (unavailable for other transfers/sales) as soon as transfer is created. Released when shipped or cancelled.</div>
          </div>

          <div class="form-group">
            <!-- Spacer for alignment -->
          </div>
        </div>
      </div>
    </div>
    <div class="card-footer">
      Controls how stock transfers are created and managed between branches
    </div>
  </div>

  <!-- Branch Management -->
  <div class="card grid-full">
    <div class="card-header">
      <h2>
        <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 8h5"></path>
        </svg>
        Branch Management
      </h2>
      <p>Manage your restaurant locations and their settings</p>
    </div>
    <div class="card-body" style="padding:0;">
      <div class="table-container">
        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Branch Name</th>
              <th>Address</th>
              <th>Phone</th>
              <th>Email</th>
              <th>Status</th>
              <th style="width: 160px; text-align: center;">Actions</th>
            </tr>
          </thead>
          <tbody id="branchesTable">
            <!-- populated by JS -->
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer">
      <div style="display:flex; gap:12px; align-items:center;">
        <button class="btn btn-primary" onclick="openBranchModal()">
          <svg style="width:14px; height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
          Add Branch
        </button>
        <span style="font-size:12px; color:var(--slate-500);">Manage your restaurant locations</span>
      </div>
    </div>
  </div>

  <!-- Receipt Settings -->
  <div class="card grid-full">
    <div class="card-header">
      <h2>
        <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
        Receipt Settings
      </h2>
      <p>Configure how receipts appear and what information they display</p>
    </div>
    <div class="card-body">
      <div class="form-row">
        <div class="form-group form-full">
          <label class="form-label">Receipt Footer Text</label>
          <input id="footer_text" class="form-input" value="<?= h($receipt['footer_text']) ?>" maxlength="200">
          <div class="form-help">Message printed at the bottom of all receipts (max 200 characters). Common examples: "Thank you for dining with us!", "Visit us again soon!", or promotional messages.</div>
        </div>
      </div>
    </div>
    <div class="card-footer">
      Receipt appearance and footer messaging
    </div>
  </div>
</div>

<!-- Floating Save Button -->
<button class="floating-save-btn" id="floatingSaveBtn" onclick="saveAll()" disabled>
  <svg class="save-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3-3m0 0l-3 3m3-3v12"></path>
  </svg>
  Save All Changes
</button>

<script>
const API_GET              = '/controllers/admin/settings/general_get.php';
const API_SAVE             = '/controllers/admin/settings/general_save.php';
const API_BRANCH_LIST      = '/controllers/admin/branches/list.php';
const API_BRANCH_SAVE      = '/controllers/admin/branches/save.php';
const API_BRANCH_DELETE    = '/controllers/admin/branches/delete.php';

let isDirty = false;
let settingsState = { 
  service_charge_pct: 0, 
  aggregator_fees_mode: 'none', 
  default_branch_id: null,
  // Transfer workflow settings
  transfer_workflow_mode: 'two_step',
  transfer_allow_ship_on_create: false,
  transfer_separation_of_duties: false,
  transfer_reserve_on_pending: false
};

function csrfHeader(){
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta && meta.content ? { 'X-CSRF-Token': meta.content } : {};
}

function showToast(msg, isError){ 
  const t=document.createElement('div'); 
  t.className='toast'+(isError?' error':''); 
  t.innerHTML = (isError ? '❌ ' : '✅ ') + msg;
  document.body.appendChild(t); 
  setTimeout(()=>t.remove(), 4000); 
}

function parseJSONorThrow(text, status){ 
  try{ 
    return JSON.parse(text); 
  }catch(e){ 
    const s=(text||'').slice(0,300).replace(/\s+/g,' ').trim(); 
    throw new Error(`Invalid JSON (HTTP ${status}). Body: ${s||'[empty]'}`); 
  } 
}

async function fetchText(url, options){ 
  const res=await fetch(url, options); 
  const text=await res.text(); 
  return {res, text}; 
}

/* ------- Enhanced dirty tracking + global UI ------- */
function markDirty(){
  isDirty = true;
  updateGlobalSaveUI();
}

function updateGlobalSaveUI(){
  const headerStatus = document.getElementById('globalStatus');
  const floatingSaveBtn = document.getElementById('floatingSaveBtn');
  
  if (isDirty) {
    if (headerStatus) {
      headerStatus.className = 'global-status unsaved';
      headerStatus.innerHTML = '<div class="global-status-indicator"></div><span>Unsaved changes</span>';
    }
    if (floatingSaveBtn) {
      floatingSaveBtn.disabled = false;
      floatingSaveBtn.classList.add('show');
    }
  } else {
    if (headerStatus) {
      headerStatus.className = 'global-status saved';
      headerStatus.innerHTML = '<div class="global-status-indicator"></div><span>All changes saved</span>';
    }
    if (floatingSaveBtn) {
      floatingSaveBtn.disabled = true;
      floatingSaveBtn.classList.remove('show');
    }
  }
}

window.addEventListener('beforeunload', function(e) {
  if (isDirty) {
    const message = 'You have unsaved changes. Are you sure you want to leave?';
    e.preventDefault();
    e.returnValue = message;
    return message;
  }
});

/* ------- Transfer Workflow Settings ------- */
function toggleTwoStepOptions() {
  const twoStepOptions = document.getElementById('twoStepOptions');
  const isTwoStep = document.getElementById('workflow_two_step')?.checked;
  
  if (twoStepOptions) {
    twoStepOptions.style.display = isTwoStep ? 'block' : 'none';
  }
}

function initTransferWorkflowSettings() {
  // Add event listeners for workflow mode radio buttons
  const oneStepRadio = document.getElementById('workflow_one_step');
  const twoStepRadio = document.getElementById('workflow_two_step');
  
  if (oneStepRadio) {
    oneStepRadio.addEventListener('change', function() {
      if (this.checked) {
        settingsState.transfer_workflow_mode = 'one_step';
        toggleTwoStepOptions();
        markDirty();
      }
    });
  }
  
  if (twoStepRadio) {
    twoStepRadio.addEventListener('change', function() {
      if (this.checked) {
        settingsState.transfer_workflow_mode = 'two_step';
        toggleTwoStepOptions();
        markDirty();
      }
    });
  }
  
  // Add event listeners for toggle switches
  const toggleSettings = [
    'transfer_allow_ship_on_create',
    'transfer_separation_of_duties',
    'transfer_reserve_on_pending'
  ];
  
  toggleSettings.forEach(settingKey => {
    const element = document.getElementById(settingKey);
    if (element) {
      element.addEventListener('change', function() {
        settingsState[settingKey] = this.checked;
        markDirty();
      });
    }
  });
  
  // Initial toggle state
  toggleTwoStepOptions();
}

function loadTransferWorkflowSettings(data) {
  // Set workflow mode
  const workflowMode = data.transfer_workflow_mode || 'two_step';
  settingsState.transfer_workflow_mode = workflowMode;
  
  const oneStepRadio = document.getElementById('workflow_one_step');
  const twoStepRadio = document.getElementById('workflow_two_step');
  
  if (oneStepRadio && twoStepRadio) {
    if (workflowMode === 'one_step') {
      oneStepRadio.checked = true;
      twoStepRadio.checked = false;
    } else {
      oneStepRadio.checked = false;
      twoStepRadio.checked = true;
    }
  }
  
  // Set toggle switches
  const toggleSettings = {
    transfer_allow_ship_on_create: data.transfer_allow_ship_on_create,
    transfer_separation_of_duties: data.transfer_separation_of_duties,
    transfer_reserve_on_pending: data.transfer_reserve_on_pending
  };
  
  Object.keys(toggleSettings).forEach(key => {
    const element = document.getElementById(key);
    const value = toggleSettings[key];
    
    if (element) {
      element.checked = !!(value === true || value === 1 || value === '1');
      settingsState[key] = element.checked;
    }
  });
  
  // Update visibility
  toggleTwoStepOptions();
}

function prepareTransferWorkflowPayload() {
  return {
    transfer_workflow_mode: settingsState.transfer_workflow_mode,
    transfer_allow_ship_on_create: settingsState.transfer_allow_ship_on_create ? 1 : 0,
    transfer_separation_of_duties: settingsState.transfer_separation_of_duties ? 1 : 0,
    transfer_reserve_on_pending: settingsState.transfer_reserve_on_pending ? 1 : 0
  };
}

/* -------- Settings load/save -------- */
async function loadSettings(){
  try{
    const {res, text} = await fetchText(API_GET, {credentials:'same-origin'});
    const json = parseJSONorThrow(text, res.status);
    if(!res.ok || !json.ok){ 
      if(res.status===401){ 
        window.location='/views/auth/login.php'; 
        return; 
      } 
      throw new Error(json.error || `GET failed (${res.status})`); 
    }
    const d = json.data || {};
    const g = id => document.getElementById(id);
    
    if (g('restaurant_name') && d.brand_name) g('restaurant_name').value = d.brand_name;
    if (g('website') && d.website) g('website').value = d.website;
    if (g('contact_email') && d.contact_email) g('contact_email').value = d.contact_email;
    if (g('contact_phone') && d.contact_phone) g('contact_phone').value = d.contact_phone;
    if (g('description') && d.description) g('description').value = d.description;
    if (g('currency') && d.currency) g('currency').value = d.currency;
    if (g('timezone') && d.time_zone) g('timezone').value = d.time_zone;
    if (g('language_primary') && d.language) g('language_primary').value = d.language;
    if (g('tax_inclusive') && typeof d.tax_inclusive!=='undefined') g('tax_inclusive').checked = !!d.tax_inclusive;
    if (g('footer_text') && typeof d.receipt_footer==='string') g('footer_text').value = d.receipt_footer;

    if (typeof d.service_charge_pct !== 'undefined') settingsState.service_charge_pct = parseFloat(d.service_charge_pct) || 0;
    if (typeof d.aggregator_fees_mode === 'string') settingsState.aggregator_fees_mode = d.aggregator_fees_mode || 'none';
    if (typeof d.default_branch_id !== 'undefined') settingsState.default_branch_id = (d.default_branch_id == null ? null : parseInt(d.default_branch_id,10));

    // Load transfer workflow settings
    loadTransferWorkflowSettings(d);

    syncDefaultBranchSelect();

    isDirty = false;
    updateGlobalSaveUI();
  }catch(err){ 
    showToast(err.message || 'Error loading settings', true); 
  }
}

async function saveAll(){
  const floatingSaveBtn = document.getElementById('floatingSaveBtn');
  const originalText = floatingSaveBtn ? floatingSaveBtn.textContent : '';
  
  const payload={
    brand_name:(document.getElementById('restaurant_name')?.value||'').trim()||'Smorll',
    website:(document.getElementById('website')?.value||'').trim(),
    contact_email:(document.getElementById('contact_email')?.value||'').trim(),
    contact_phone:(document.getElementById('contact_phone')?.value||'').trim(),
    description:(document.getElementById('description')?.value||'').trim(),
    currency:document.getElementById('currency')?.value||'EGP',
    tax_inclusive:!!document.getElementById('tax_inclusive')?.checked,
    language:document.getElementById('language_primary')?.value||'en',
    time_zone:document.getElementById('timezone')?.value||'Africa/Cairo',
    receipt_footer:document.getElementById('footer_text')?.value||'',
    service_charge_pct:settingsState.service_charge_pct||0,
    aggregator_fees_mode:settingsState.aggregator_fees_mode||'none',
    default_branch_id:(function(){ 
      const v=document.getElementById('default_branch_id')?.value||''; 
      return v===''?null:parseInt(v,10); 
    })(),
    // Add transfer workflow settings
    ...prepareTransferWorkflowPayload()
  };
  
  try{
    if (floatingSaveBtn) {
      floatingSaveBtn.disabled = true;
      floatingSaveBtn.textContent = 'Saving...';
    }

    const {res,text}=await fetchText(API_SAVE,{
      method:'POST',
      headers:Object.assign({'Content-Type':'application/json'},csrfHeader()),
      credentials:'same-origin',
      body:JSON.stringify(payload)
    });
    const json=parseJSONorThrow(text,res.status);
    if(!res.ok||!json.ok){
      if(res.status===422&&json.fields){ 
        const f=Object.keys(json.fields)[0]||''; 
        throw new Error(`Validation error: ${json.fields[f]}`);
      }
      throw new Error(json.error||`Save failed (HTTP ${res.status})`);
    }
    const w=(typeof json.written==='number')?json.written:null;
    if(w===0) showToast('No changes detected (all values were already saved)', false); 
    else showToast(`Settings saved successfully${w!=null?` (${w} change${w===1?'':'s'})`:''}!`, false);

    if (json.data && typeof json.data==='object' && typeof json.data.default_branch_id !== 'undefined') {
      settingsState.default_branch_id = (json.data.default_branch_id == null ? null : parseInt(json.data.default_branch_id,10));
      syncDefaultBranchSelect();
    }
    isDirty=false;
    updateGlobalSaveUI();
    await loadSettings();
  }catch(err){
    showToast(err.message||'Error saving settings', true);
  }finally{
    if (floatingSaveBtn) floatingSaveBtn.textContent = originalText;
    updateGlobalSaveUI();
  }
}

/* -------- Branches CRUD + Default select -------- */
function branchRowHtml(b){
  const id = parseInt(b.id,10);
  const isActive = parseInt(b.is_active,10) === 1;
  return '<tr data-id="'+id+'">'+
    '<td><strong>#'+id+'</strong></td>'+
    '<td><strong>'+escapeHtml(b.name||'')+'</strong></td>'+
    '<td>'+escapeHtml(b.address||'')+'</td>'+
    '<td>'+escapeHtml(b.phone||'')+'</td>'+
    '<td>'+escapeHtml(b.email||'')+'</td>'+
    '<td><span class="badge '+(isActive?'badge-success':'badge-inactive')+'">'+
      (isActive?'✓ Active':'○ Inactive')+'</span></td>'+
    '<td><div class="table-actions">'+
      '<button class="btn" onclick="editBranch('+id+')">Edit</button>'+
      '<button class="btn btn-danger" onclick="removeBranch('+id+')">Delete</button>'+
    '</div></td>'+
  '</tr>';
}

let cachedBranches = [];

function populateDefaultBranchSelect(){
  const sel = document.getElementById('default_branch_id'); 
  if (!sel) return;
  const current = settingsState.default_branch_id == null ? '' : String(settingsState.default_branch_id);
  const opts = ['<option value="">– Select default branch –</option>']
    .concat(cachedBranches.map(b => '<option value="'+b.id+'">'+escapeHtml(b.name)+'</option>'));
  sel.innerHTML = opts.join('');
  if (current !== '') sel.value = current;
}

function syncDefaultBranchSelect(){
  const sel = document.getElementById('default_branch_id'); 
  if (!sel) return;
  const target = settingsState.default_branch_id == null ? '' : String(settingsState.default_branch_id);
  if (sel.value !== target) sel.value = target;
}

async function loadBranches(){
  try{
    const {res,text}=await fetchText(API_BRANCH_LIST,{credentials:'same-origin'});
    const json=parseJSONorThrow(text,res.status);
    if(!res.ok||!json.ok){ 
      if(res.status===401){ 
        window.location='/views/auth/login.php'; 
        return; 
      } 
      throw new Error(json.error||`List failed (${res.status})`); 
    }
    const tbody=document.getElementById('branchesTable'); 
    if(tbody){ 
      if (json.data && json.data.length > 0) {
        tbody.innerHTML = json.data.map(branchRowHtml).join(''); 
      } else {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:40px; color:var(--slate-500);">No branches found. <button class="btn btn-primary" onclick="openBranchModal()" style="margin-left:12px;">Add First Branch</button></td></tr>';
      }
    }
    cachedBranches = json.data || [];
    populateDefaultBranchSelect();
    syncDefaultBranchSelect();
  }catch(err){ 
    showToast(err.message||'Error loading branches', true); 
  }
}

function openBranchModal(data){
  const isEdit=!!data;
  const b=data||{id:0,name:'',address:'',phone:'',email:'',timezone:'Africa/Cairo',is_active:1};
  const modal=document.createElement('div');
  modal.className='modal-overlay';
  modal.innerHTML =
    '<div class="modal-content">'+
      '<div class="modal-header">'+
        '<h3>'+(isEdit?'Edit Branch: '+escapeHtml(b.name):'Add New Branch')+'</h3>'+
        '<button class="btn" onclick="closeModal()">✕ Close</button>'+
      '</div>'+
      '<div class="modal-body">'+
        '<div class="form-row">'+
          '<div class="form-group">'+
            '<label class="form-label required">Branch Name</label>'+
            '<input id="modal_name" class="form-input" value="'+escapeHtml(b.name)+'" required placeholder="e.g. Downtown Location">'+
            '<div class="form-help">This name appears in the POS branch selector</div>'+
          '</div>'+
          '<div class="form-group">'+
            '<label class="form-label">Phone Number</label>'+
            '<input id="modal_phone" class="form-input" value="'+escapeHtml(b.phone)+'" placeholder="e.g. +20 100 123 4567">'+
          '</div>'+
        '</div>'+
        '<div class="form-row">'+
          '<div class="form-group form-full">'+
            '<label class="form-label required">Address</label>'+
            '<input id="modal_address" class="form-input" value="'+escapeHtml(b.address)+'" required placeholder="Full street address">'+
            '<div class="form-help">Complete address for delivery and customer reference</div>'+
          '</div>'+
        '</div>'+
        '<div class="form-row">'+
          '<div class="form-group">'+
            '<label class="form-label">Email</label>'+
            '<input id="modal_email" class="form-input" type="email" value="'+escapeHtml(b.email)+'" placeholder="branch@restaurant.com">'+
          '</div>'+
          '<div class="form-group">'+
            '<label class="form-label">Timezone</label>'+
            '<select id="modal_timezone" class="form-select">'+
              '<option value="Africa/Cairo"'+(b.timezone==='Africa/Cairo'?' selected':'')+'>Cairo (GMT+2)</option>'+
              '<option value="Asia/Kuwait"'+(b.timezone==='Asia/Kuwait'?' selected':'')+'>Kuwait (GMT+3)</option>'+
              '<option value="Asia/Riyadh"'+(b.timezone==='Asia/Riyadh'?' selected':'')+'>Riyadh (GMT+3)</option>'+
              '<option value="Asia/Dubai"'+(b.timezone==='Asia/Dubai'?' selected':'')+'>Dubai (GMT+4)</option>'+
            '</select>'+
          '</div>'+
        '</div>'+
        '<div class="form-row">'+
          '<div class="form-group">'+
            '<label class="form-label">Branch Status</label>'+
            '<div class="switch-group">'+
              '<label class="switch">'+
                '<input type="checkbox" id="modal_active" '+(parseInt(b.is_active,10)===1?'checked':'')+'>'+
                '<span class="switch-slider"></span>'+
              '</label>'+
              '<span style="font-size:12px; color:var(--slate-600);">Branch is active</span>'+
            '</div>'+
            '<div class="form-help">Inactive branches are hidden from the POS but keep their data</div>'+
          '</div>'+
        '</div>'+
      '</div>'+
      '<div class="modal-footer">'+
        '<button class="btn" onclick="closeModal()">Cancel</button>'+
        '<button class="btn btn-primary" onclick="saveBranch('+parseInt(b.id||0,10)+')">'+(isEdit?'Update':'Add')+' Branch</button>'+
      '</div>'+
    '</div>';
  document.body.appendChild(modal);
  modal.addEventListener('click',(e)=>{ 
    if(e.target===modal) closeModal(); 
  });
  setTimeout(() => {
    const firstInput = modal.querySelector('#modal_name');
    if (firstInput) firstInput.focus();
  }, 100);
}

function closeModal(){ 
  const m=document.querySelector('.modal-overlay'); 
  if(m) m.remove(); 
}

function escapeHtml(t){ 
  const d=document.createElement('div'); 
  d.textContent=t||''; 
  return d.innerHTML; 
}

async function editBranch(id) {
  const branch = cachedBranches.find(b => parseInt(b.id,10) === id);
  if (branch) {
    openBranchModal(branch);
  } else {
    showToast('Branch not found', true);
  }
}

async function saveBranch(id){
  const payload={
    id: id>0 ? id : null,
    name: (document.getElementById('modal_name')?.value||'').trim(),
    address: (document.getElementById('modal_address')?.value||'').trim(),
    phone: (document.getElementById('modal_phone')?.value||'').trim(),
    email: (document.getElementById('modal_email')?.value||'').trim(),
    timezone: document.getElementById('modal_timezone')?.value || 'Africa/Cairo',
    is_active: !!document.getElementById('modal_active')?.checked
  };
  if(!payload.name || !payload.address){ 
    showToast('Branch name and address are required', true); 
    return; 
  }

  try{
    const {res,text}=await fetchText(API_BRANCH_SAVE,{
      method:'POST',
      headers:Object.assign({'Content-Type':'application/json'},csrfHeader()),
      credentials:'same-origin',
      body:JSON.stringify(payload)
    });
    const json=parseJSONorThrow(text,res.status);
    if(!res.ok||!json.ok){
      if(res.status===422 && json.fields){ 
        const f=Object.keys(json.fields)[0]||''; 
        throw new Error(`Validation error: ${json.fields[f]}`); 
      }
      throw new Error(json.error||`Save failed (${res.status})`);
    }
    closeModal();
    await loadBranches();
    syncDefaultBranchSelect();
    showToast(id>0 ? 'Branch updated successfully' : 'Branch added successfully');
  }catch(err){ 
    showToast(err.message||'Error saving branch', true); 
  }
}

async function removeBranch(id){
  const branch = cachedBranches.find(b => parseInt(b.id,10) === id);
  const branchName = branch ? branch.name : `Branch #${id}`;
  if(!confirm(`Are you sure you want to delete "${branchName}"?\n\nThis action cannot be undone.`)) return;
  
  try{
    const {res,text}=await fetchText(API_BRANCH_DELETE,{
      method:'POST',
      headers:Object.assign({'Content-Type':'application/json'},csrfHeader()),
      credentials:'same-origin',
      body:JSON.stringify({id})
    });
    const json=parseJSONorThrow(text,res.status);
    if(!res.ok||!json.ok) throw new Error(json.error||`Delete failed (${res.status})`);
    await loadBranches();
    const sel = document.getElementById('default_branch_id');
    if (sel && sel.value === String(id)) { 
      sel.value = ''; 
      markDirty(); 
    }
    showToast(`Branch "${branchName}" deleted successfully`);
  }catch(err){ 
    showToast(err.message||'Error deleting branch', true); 
  }
}

/* -------- Initialization -------- */
document.addEventListener('DOMContentLoaded', function(){
  // Initialize transfer workflow settings
  initTransferWorkflowSettings();
  
  // Add event listeners for dirty tracking
  document.querySelectorAll('input, textarea, select').forEach(el=>{
    el.addEventListener('input', markDirty);
    el.addEventListener('change', markDirty);
  });
  
  // Load data
  loadSettings();
  loadBranches();
  
  // Set initial UI state
  updateGlobalSaveUI();
  
  // Debug info (remove in production)
  console.log('General Settings page loaded with transfer workflow support');
  console.log('API endpoints configured:', {
    get: API_GET,
    save: API_SAVE,
    branches: API_BRANCH_LIST
  });
});
</script>
</body>
</html>