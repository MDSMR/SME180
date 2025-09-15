<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';
auth_require_login();

// public_html/views/admin/settings/finance.php
// Setup → Finance & Channels (Taxes • Payments • Aggregators)

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { 
    @ini_set('display_errors','1'); 
    @ini_set('display_startup_errors','1'); 
    error_reporting(E_ALL); 
}

// Session & Auth
$user = $_SESSION['user'] ?? null;
if (!$user) { 
    header('Location: /views/auth/login.php'); 
    exit; 
}

// Helpers
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

// This will be populated from database via API
$tax_rates = [];
$payment_methods = [];
$aggregators = [];

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Finance &amp; Channels · Smorll POS</title>
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
  --danger:#ef4444; --warning:#f59e0b; --warning-50:#fffbeb;
  --slate-50:#f8fafc; --slate-100:#f1f5f9; --slate-200:#e2e8f0;
  --slate-300:#cbd5e1; --slate-400:#94a3b8; --slate-500:#64748b; --slate-600:#475569; --slate-900:#0f172a;
  --white:#ffffff; --shadow-sm:0 1px 3px 0 rgb(0 0 0 / .1);
  --shadow-md:0 4px 6px -1px rgb(0 0 0 / .1); --shadow-lg:0 10px 15px -3px rgb(0 0 0 / .1);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:linear-gradient(135deg,#fafbfc,#f1f5f9);color:var(--slate-900);line-height:1.5;font-size:14px;min-height:100vh}
.container{max-width:1200px;margin:0 auto;padding:20px}

/* Header with status indicator */
.header{position:sticky;top:0;z-index:50;background:rgba(255,255,255,.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--slate-200);margin:0 -20px 20px;padding:16px 20px}
.header-content{display:flex;align-items:center;justify-content:space-between;gap:20px}
.header h1{font-size:20px;font-weight:700;margin:0}
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

.grid{display:grid;gap:20px}
.grid-2{grid-template-columns:repeat(auto-fit,minmax(380px,1fr))}
.grid-full{grid-column:1 / -1}

.card{background:#fff;border:1px solid var(--slate-200);border-radius:16px;box-shadow:var(--shadow-sm);overflow:hidden}
.card:hover{box-shadow:var(--shadow-md)}
.card-header{padding:20px 20px 16px;border-bottom:1px solid var(--slate-200);background:linear-gradient(135deg,#fff,var(--slate-50))}
.card-header h2{font-size:16px;font-weight:600;margin:0 0 2px}
.card-header p{color:var(--slate-600);font-size:13px;margin:0}
.card-header-flex{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
.card-body{padding:20px}

.form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin-bottom:16px}
.form-group{display:flex;flex-direction:column}
.form-label{font-weight:600;font-size:13px;margin-bottom:6px}
.form-input,.form-select{width:100%;padding:12px;border:2px solid var(--slate-200);border-radius:8px;font-size:13px;background:#fff;transition:all .2s;outline:none}
.form-input:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-50)}
.form-help{color:var(--slate-500);font-size:11px;margin-top:3px}

.toggle-section{border-top:1px solid var(--slate-200);padding-top:20px;margin-top:20px}
.toggle-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
.toggle-item{display:flex;align-items:flex-start;justify-content:space-between;padding:16px;border:2px solid var(--slate-200);border-radius:8px;background:#fff;gap:16px}
.toggle-item:hover{border-color:var(--slate-300)}
.toggle-info h4{font-weight:600;font-size:13px;margin:0 0 2px}
.toggle-info p{color:var(--slate-600);font-size:11px;margin:0;line-height:1.4}
.switch{position:relative;width:44px;height:24px;display:inline-block;flex-shrink:0}
.switch input{display:none}
.switch-slider{position:absolute;cursor:pointer;background:var(--slate-300);border-radius:24px;inset:0;transition:all .3s}
.switch-slider:after{content:'';position:absolute;height:18px;width:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:all .3s}
.switch input:checked + .switch-slider{background:var(--primary)}
.switch input:checked + .switch-slider:after{transform:translateX(20px)}

.table-container{background:#fff;border-radius:12px;overflow:hidden;border:1px solid var(--slate-200)}
.table{width:100%;border-collapse:collapse;font-size:13px}
.table th{background:var(--slate-100);color:var(--slate-600);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em;padding:16px 12px;border-bottom:2px solid var(--slate-200);text-align:left}
.table td{padding:12px;border-bottom:1px solid var(--slate-200);vertical-align:middle}
.table tbody tr:hover{background:var(--slate-50)}
.table tbody tr:last-child td{border-bottom:none}

.badge{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:600}
.badge-success{background:var(--success-50);color:#065f46}
.badge-warning{background:var(--warning-50);color:#92400e}
.badge-gray{background:var(--slate-100);color:var(--slate-600)}

.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:2px solid var(--slate-200);background:#fff;border-radius:6px;font-weight:600;font-size:11px;cursor:pointer;transition:all .2s;color:var(--slate-900);text-decoration:none}
.btn:hover{background:var(--slate-50);border-color:var(--slate-300)}
.btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn-primary:hover{background:#2563eb;color:#fff}
.btn-success{background:var(--success);color:#fff;border-color:var(--success)}
.btn-success:hover{background:#059669;color:#fff}
.btn-danger{background:var(--danger);color:#fff;border-color:var(--danger)}
.btn-danger:hover{background:#dc2626;color:#fff}
.btn-sm{padding:4px 8px;font-size:11px}
.action-buttons{display:flex;gap:6px;justify-content:flex-end}

.notice{background:#fffbeb;border:1px solid #fcd34d;color:#92400e;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px}

.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.7);display:flex;align-items:center;justify-content:center;z-index:1000;padding:20px}
.modal-content{background:#fff;border-radius:16px;max-width:500px;width:100%;max-height:90vh;overflow-y:auto}
.modal-header{padding:20px;border-bottom:1px solid var(--slate-200);display:flex;align-items:center;justify-content:space-between;background:var(--slate-50)}
.modal-header h3{font-size:16px;font-weight:600;margin:0}
.modal-body{padding:20px}
.modal-footer{padding:20px;border-top:1px solid var(--slate-200);display:flex;justify-content:flex-end;gap:8px;background:var(--slate-50)}
.toast{position:fixed;top:20px;right:20px;background:var(--success);color:#fff;padding:12px 20px;border-radius:8px;font-weight:600;z-index:1001;display:flex;align-items:center;gap:8px;font-size:13px}
.toast.error{background:var(--danger)}
.percentage{color:var(--success);font-weight:600}
.currency{color:var(--slate-600);font-weight:500}

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

.empty-state { text-align:center; padding:40px; color:var(--slate-500); }

@media (max-width:1024px){.container{padding:16px}.grid-2{grid-template-columns:1fr}.form-row{grid-template-columns:1fr}.toggle-row{grid-template-columns:1fr}}
@media (max-width:768px){.header-content{flex-direction:column;align-items:stretch;gap:12px}.table-container{overflow-x:auto}.action-buttons{flex-direction:column}}
@media (max-width:640px){ 
  .floating-save-btn { 
    right:16px; bottom:16px; padding:14px 20px; font-size:13px; min-width:140px;
  }
}
</style>
</head>
<body>
<?php
  $active = 'settings_finance';  // Updated from 'settings' to 'settings_finance'
  $nav_candidates = [
    __DIR__ . '/../partials/admin_nav.php',
    dirname(__DIR__, 2) . '/partials/admin_nav.php',
    dirname(__DIR__, 1) . '/partials/admin_nav.php'
  ];
  $nav_included=false;
  foreach ($nav_candidates as $cand) {
    if (is_file($cand)) { 
        include $cand; 
        $nav_included=true; 
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
      <h1>Finance &amp; Channels</h1>
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
    <!-- Financial Settings -->
    <div class="card">
      <div class="card-header">
        <h2>Financial Settings</h2>
        <p>Tax calculation and financial preferences</p>
      </div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Default Tax Rate</label>
            <select id="default_tax_rate" class="form-select">
              <option value="">Select default tax</option>
            </select>
            <div class="form-help">Applied automatically to new orders</div>
          </div>
        </div>

        <div class="toggle-section">
          <div class="toggle-row">
            <div class="toggle-item">
              <div class="toggle-info">
                <h4>Auto-calculate tax</h4>
                <p>Automatically calculate tax on order items</p>
              </div>
              <label class="switch"><input type="checkbox" id="auto_calculate_tax" checked><span class="switch-slider"></span></label>
            </div>

            <div class="toggle-item">
              <div class="toggle-info">
                <h4>Compound tax</h4>
                <p>Apply tax on top of other taxes</p>
              </div>
              <label class="switch"><input type="checkbox" id="compound_tax"><span class="switch-slider"></span></label>
            </div>

            <div class="toggle-item">
              <div class="toggle-info">
                <h4>Tax on service charge</h4>
                <p>Apply tax to service charge amount</p>
              </div>
              <label class="switch"><input type="checkbox" id="tax_on_service_charge"><span class="switch-slider"></span></label>
            </div>

            <div class="toggle-item">
              <div class="toggle-info">
                <h4>Round tax calculations</h4>
                <p>Round tax amounts to nearest cent</p>
              </div>
              <label class="switch"><input type="checkbox" id="round_tax_calculations" checked><span class="switch-slider"></span></label>
            </div>

            <div class="toggle-item">
              <div class="toggle-info">
                <h4>Receipt tax breakdown</h4>
                <p>Show detailed tax breakdown on receipts</p>
              </div>
              <label class="switch"><input type="checkbox" id="receipt_tax_breakdown" checked><span class="switch-slider"></span></label>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tax Rates -->
  <div class="card grid-full">
    <div class="card-header card-header-flex">
      <div>
        <h2>Tax Rates</h2>
        <p>Configure VAT, service charges, and other taxes</p>
      </div>
      <button class="btn btn-success" onclick="openTaxRateModal()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Tax Rate
      </button>
    </div>
    <div class="card-body" style="padding:0">
      <div class="table-container">
        <table class="table">
          <thead><tr><th>Name</th><th>Rate</th><th>Type</th><th>Inclusive</th><th>Status</th><th style="width:120px">Actions</th></tr></thead>
          <tbody id="taxRatesTable">
            <tr><td colspan="6" class="empty-state">Loading tax rates...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Payment Methods -->
  <div class="card grid-full">
    <div class="card-header card-header-flex">
      <div>
        <h2>Payment Methods</h2>
        <p>Configure accepted payment types and surcharges</p>
      </div>
      <button class="btn btn-success" onclick="openPaymentMethodModal()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Payment Method
      </button>
    </div>
    <div class="card-body" style="padding:0">
      <div class="table-container">
        <table class="table">
          <thead><tr><th>Name</th><th>Type</th><th>Surcharge</th><th>Status</th><th style="width:120px">Actions</th></tr></thead>
          <tbody id="paymentMethodsTable">
            <tr><td colspan="5" class="empty-state">Loading payment methods...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Aggregators -->
  <div class="card grid-full">
    <div class="card-header card-header-flex">
      <div>
        <h2>Aggregators</h2>
        <p>Delivery partners with commission percentage</p>
      </div>
      <button class="btn btn-success" onclick="openAggregatorModal()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Aggregator
      </button>
    </div>
    <div class="card-body" style="padding:0">
      <div class="table-container">
        <table class="table">
          <thead><tr><th>Name</th><th>Commission</th><th>Status</th><th style="width:120px">Actions</th></tr></thead>
          <tbody id="aggregatorsTable">
            <tr><td colspan="4" class="empty-state">Loading aggregators...</td></tr>
          </tbody>
        </table>
      </div>
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
// API endpoints
const API_BASE = '/controllers/admin/finance/';
const API_TAX_LIST = API_BASE + 'tax_list.php';
const API_TAX_SAVE = API_BASE + 'tax_save.php';
const API_TAX_DELETE = API_BASE + 'tax_delete.php';
const API_PAYMENT_LIST = API_BASE + 'payment_list.php';
const API_PAYMENT_SAVE = API_BASE + 'payment_save.php';
const API_PAYMENT_DELETE = API_BASE + 'payment_delete.php';
const API_AGGREGATOR_LIST = API_BASE + 'aggregator_list.php';
const API_AGGREGATOR_SAVE = API_BASE + 'aggregator_save.php';
const API_AGGREGATOR_DELETE = API_BASE + 'aggregator_delete.php';
const API_SETTINGS_GET = API_BASE + 'settings_get.php';
const API_SETTINGS_SAVE = API_BASE + 'settings_save.php';

let isDirty = false;
let taxRates = [];
let paymentMethods = [];
let aggregators = [];

// CSRF token helper
function csrfHeader(){
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta && meta.content ? { 'X-CSRF-Token': meta.content } : {};
}

// Helpers
function escapeHtml(t){ 
    const d=document.createElement('div'); 
    d.textContent=t||''; 
    return d.innerHTML; 
}

function closeModal(){ 
    const m=document.querySelector('.modal-overlay'); 
    if (m) m.remove(); 
}

function showToast(msg, err){ 
    const t=document.createElement('div'); 
    t.className='toast'+(err?' error':''); 
    t.textContent=msg; 
    document.body.appendChild(t); 
    setTimeout(()=>t.remove(),3000); 
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

// Dirty tracking
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

// Load all data
async function loadAllData(){
    await Promise.all([
        loadTaxRates(),
        loadPaymentMethods(),
        loadAggregators(),
        loadSettings()
    ]);
}

async function loadSettings(){
    try{
        const {res, text} = await fetchText(API_SETTINGS_GET, {credentials:'same-origin'});
        const json = parseJSONorThrow(text, res.status);
        if(!res.ok || !json.ok){ 
            throw new Error(json.error || `GET failed (${res.status})`); 
        }
        const d = json.data || {};
        
        // Set toggle states
        if(document.getElementById('auto_calculate_tax')) 
            document.getElementById('auto_calculate_tax').checked = !!d.auto_calculate_tax;
        if(document.getElementById('compound_tax')) 
            document.getElementById('compound_tax').checked = !!d.compound_tax;
        if(document.getElementById('tax_on_service_charge')) 
            document.getElementById('tax_on_service_charge').checked = !!d.tax_on_service_charge;
        if(document.getElementById('round_tax_calculations')) 
            document.getElementById('round_tax_calculations').checked = !!d.round_tax_calculations;
        if(document.getElementById('receipt_tax_breakdown')) 
            document.getElementById('receipt_tax_breakdown').checked = !!d.receipt_tax_breakdown;
        
        // Set default tax rate
        if(document.getElementById('default_tax_rate') && d.default_tax_rate) 
            document.getElementById('default_tax_rate').value = d.default_tax_rate;
            
    }catch(err){ 
        console.log('Settings not loaded:', err.message);
    }
}

// TAX RATES
async function loadTaxRates(){
    try{
        const {res,text}=await fetchText(API_TAX_LIST,{credentials:'same-origin'});
        const json=parseJSONorThrow(text,res.status);
        if(!res.ok||!json.ok) throw new Error(json.error||`List failed (${res.status})`);
        
        taxRates = json.data || [];
        const tbody=document.getElementById('taxRatesTable');
        
        // Update default tax dropdown
        const defaultSelect = document.getElementById('default_tax_rate');
        if(defaultSelect){
            const currentValue = defaultSelect.value;
            defaultSelect.innerHTML = '<option value="">Select default tax</option>' + 
                taxRates.map(r => `<option value="${r.id}">${escapeHtml(r.name)} (${r.rate}%)</option>`).join('');
            defaultSelect.value = currentValue;
        }
        
        if(tbody){
            if(taxRates.length > 0){
                tbody.innerHTML = taxRates.map(r => 
                    `<tr data-id="${r.id}">
                        <td><strong>${escapeHtml(r.name)}</strong></td>
                        <td><span class="percentage">${Number(r.rate).toFixed(2)}%</span></td>
                        <td>${r.type.charAt(0).toUpperCase() + r.type.slice(1).replace('_',' ')}</td>
                        <td>${r.is_inclusive ? '<span class="badge badge-warning">Inclusive</span>' : '<span class="badge badge-gray">Exclusive</span>'}</td>
                        <td>${r.is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-gray">Inactive</span>'}</td>
                        <td><div class="action-buttons">
                            <button class="btn btn-sm" onclick="editTaxRate(${r.id})">Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="removeTaxRate(${r.id})">Delete</button>
                        </div></td>
                    </tr>`
                ).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="6" class="empty-state">No tax rates found. <button class="btn btn-primary" onclick="openTaxRateModal()" style="margin-left:12px;">Add First Tax Rate</button></td></tr>';
            }
        }
    }catch(err){
        showToast(err.message||'Error loading tax rates', true);
    }
}

function openTaxRateModal(data){
    const isEdit = !!data;
    const rate = data || { id:0, name:'', rate:0, type:'vat', is_inclusive:0, is_active:1 };
    const modal=document.createElement('div');
    modal.className='modal-overlay';
    modal.innerHTML =
        '<div class="modal-content">'+
            '<div class="modal-header"><h3>'+(isEdit?'Edit Tax Rate':'Add New Tax Rate')+'</h3><button class="btn btn-sm" onclick="closeModal()">×</button></div>'+
            '<div class="modal-body">'+
                '<div class="form-row">'+
                    '<div class="form-group"><label class="form-label">Tax Name *</label><input id="modal_tax_name" class="form-input" value="'+escapeHtml(rate.name)+'" placeholder="VAT Standard" required></div>'+
                    '<div class="form-group"><label class="form-label">Rate (%) *</label><input id="modal_tax_rate" class="form-input" type="number" step="0.01" min="0" max="100" value="'+(rate.rate||0)+'" required></div>'+
                '</div>'+
                '<div class="form-row">'+
                    '<div class="form-group"><label class="form-label">Tax Type</label>'+
                        '<select id="modal_tax_type" class="form-select">'+
                            '<option value="vat"'+(rate.type==='vat'?' selected':'')+'>VAT</option>'+
                            '<option value="service_charge"'+(rate.type==='service_charge'?' selected':'')+'>Service Charge</option>'+
                            '<option value="other"'+(rate.type==='other'?' selected':'')+'>Other</option>'+
                        '</select></div>'+
                    '<div class="form-group"><label class="form-label">Tax Calculation</label>'+
                        '<select id="modal_tax_inclusive" class="form-select">'+
                            '<option value="0"'+(!rate.is_inclusive?' selected':'')+'>Exclusive (added to price)</option>'+
                            '<option value="1"'+(rate.is_inclusive?' selected':'')+'>Inclusive (included in price)</option>'+
                        '</select></div>'+
                '</div>'+
                '<div class="form-row">'+
                    '<div class="form-group"><label class="form-label">Status</label>'+
                        '<select id="modal_tax_active" class="form-select">'+
                            '<option value="1"'+(rate.is_active?' selected':'')+'>Active</option>'+
                            '<option value="0"'+(!rate.is_active?' selected':'')+'>Inactive</option>'+
                        '</select></div>'+
                '</div>'+
            '</div>'+
            '<div class="modal-footer"><button class="btn" onclick="closeModal()">Cancel</button>'+
            '<button class="btn btn-primary" onclick="saveTaxRate('+(rate.id||0)+')">'+(isEdit?'Update':'Add')+' Tax Rate</button></div>'+
        '</div>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });
}

function editTaxRate(id){
    const rate = taxRates.find(r => r.id === id);
    if(rate) openTaxRateModal(rate);
}

async function saveTaxRate(id){
    const payload={
        id: id>0 ? id : null,
        name: document.getElementById('modal_tax_name').value.trim(),
        rate: parseFloat(document.getElementById('modal_tax_rate').value),
        type: document.getElementById('modal_tax_type').value,
        is_inclusive: parseInt(document.getElementById('modal_tax_inclusive').value),
        is_active: parseInt(document.getElementById('modal_tax_active').value)
    };
    
    if(!payload.name || isNaN(payload.rate) || payload.rate<0){ 
        showToast('Please provide valid tax name and rate.', true); 
        return; 
    }
    
    try{
        const {res,text}=await fetchText(API_TAX_SAVE,{
            method:'POST',
            headers:Object.assign({'Content-Type':'application/json'},csrfHeader()),
            credentials:'same-origin',
            body:JSON.stringify(payload)
        });
        const json=parseJSONorThrow(text,res.status);
        if(!res.ok||!json.ok) throw new Error(json.error||`Save failed (${res.status})`);
        
        closeModal();
        await loadTaxRates();
        showToast(id>0?'Tax rate updated successfully':'Tax rate added successfully');
    }catch(err){
        showToast(err.message||'Error saving tax rate', true);
    }
}

async function removeTaxRate(id){
    if(!confirm('Delete this tax rate? This may affect existing orders.')) return;
    
    try{
        const {res,text}=await fetchText(API_TAX_DELETE,{
            method:'POST',
            headers:Object.assign({'Content-Type':'application/json'},csrfHeader()),
            credentials:'same-origin',
            body:JSON.stringify({id})
        });
        const json=parseJSONorThrow(text,res.status);
        if(!res.ok||!json.ok) throw new Error(json.error||`Delete failed (${res.status})`);
        
        await loadTaxRates();
        showToast('Tax rate deleted successfully');
    }catch(err){
        showToast(err.message||'Error deleting tax rate', true);
    }
}

// PAYMENT METHODS
async function loadPaymentMethods(){
    try{
        const {res,text}=await fetchText(API_PAYMENT_LIST,{credentials:'same-origin'});
        const json=parseJSONorThrow(text,res.status);
        if(!res.ok||!json.ok) throw new Error(json.error||`List failed (${res.status})`);
        
        paymentMethods = json.data || [];
        const tbody=document.getElementById('paymentMethodsTable');
        
        if(tbody){
            if(paymentMethods.length > 0){
                tbody.innerHTML = paymentMethods.map(m => 
                    `<tr data-id="${m.id}">
                        <td><strong>${escapeHtml(m.name)}</strong></td>
                        <td>${m.type.charAt(0).toUpperCase() + m.type.slice(1).replace('_',' ')}</td>
                        <td>${m.surcharge_rate > 0 ? '<span class="percentage">'+Number(m.surcharge_rate).toFixed(2)+'%</span>' : '<span class="currency">No Charge</span>'}</td>
                        <td>${m.is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-gray">Inactive</span>'}</td>
                        <td><div class="action-buttons">
                            <button class="btn btn-sm" onclick="editPaymentMethod(${m.id})">Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="removePaymentMethod(${m.id})">Delete</button>
                        </div></td>
                    </tr>`
                ).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="5" class="empty-state">No payment methods found. <button class="btn btn-primary" onclick="openPaymentMethodModal()" style="margin-left:12px;">Add First Payment Method</button></td></tr>';
            }
        }
    }catch(err){
        showToast(err.message||'Error loading payment methods', true);
    }
}

function openPaymentMethodModal(data){
    const isEdit=!!data;
    const method=data || { id:0, name:'', type:'cash', surcharge_rate:0, is_active:1 };
    const modal=document.createElement('div'); 
    modal.className='modal-overlay';
    modal.innerHTML =
        '<div class="modal-content">'+
            '<div class="modal-header"><h3>'+(isEdit?'Edit Payment Method':'Add New Payment Method')+'</h3><button class="btn btn-sm" onclick="closeModal()">×</button></div>'+
            '<div class="modal-body">'+
                '<div class="form-row">'+
                    '<div class="form-group"><label class="form-label">Payment Method Name *</label><input id="modal_payment_name" class="form-input" value="'+escapeHtml(method.name)+'" placeholder="Credit Card" required></div>'+
                    '<div class="form-group"><label class="form-label">Type</label><select id="modal_payment_type" class="form-select">'+
                        '<option value="cash"'+(method.type==='cash'?' selected':'')+'>Cash</option>'+
                        '<option value="card"'+(method.type==='card'?' selected':'')+'>Credit/Debit Card</option>'+
                        '<option value="wallet"'+(method.type==='wallet'?' selected':'')+'>Mobile Wallet</option>'+
                        '<option value="bank_transfer"'+(method.type==='bank_transfer'?' selected':'')+'>Bank Transfer</option>'+
                        '<option value="other"'+(method.type==='other'?' selected':'')+'>Other</option>'+
                    '</select></div>'+
                '</div>'+
                '<div class="form-row">'+
                    '<div class="form-group"><label class="form-label">Surcharge Rate (%)</label><input id="modal_payment_surcharge" class="form-input" type="number" step="0.01" min="0" max="100" value="'+(method.surcharge_rate||0)+'"><div class="form-help">Additional fee charged for this method</div></div>'+
                    '<div class="form-group"><label class="form-label">Status</label>'+
                        '<select id="modal_payment_active" class="form-select">'+
                            '<option value="1"'+(method.is_active?' selected':'')+'>Active</option>'+
                            '<option value="0"'+(!method.is_active?' selected':'')+'>Inactive</option>'+
                        '</select></div>'+
                '</div>'+
            '</div>'+
            '<div class="modal-footer"><button class="btn" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="savePaymentMethod('+(method.id||0)+')">'+(isEdit?'Update':'Add')+' Payment Method</button></div>'+
        '</div>';
    document.body.appendChild(modal);
    modal.addEventListener('click',function(e){ if(e.target===modal) closeModal(); });
}

function editPaymentMethod(id){
    const method = paymentMethods.find(m => m.id === id);
    if(method) openPaymentMethodModal(method);
}

async function savePaymentMethod(id){
    const payload={
        id: id>0 ? id : null,
        name: document.getElementById('modal_payment_name').value.trim(),
        type: document.getElementById('modal_payment_type').value,
        surcharge_rate: parseFloat(document.getElementById('modal_payment_surcharge').value)||0,
        is_active: parseInt(document.getElementById('modal_payment_active').value)
    };
    
    if(!payload.name){ 
        showToast('Please provide payment method name.', true); 
        return; 
    }
    
    try{
        const {res,text}=await fetchText(API_PAYMENT_SAVE,{
            method:'POST',
            headers:Object.assign({'Content-Type':'application/json'},csrfHeader()),
            credentials:'same-origin',
            body:JSON.stringify(payload)
        });
        const json=parseJSONorThrow(text,res.status);
        if(!res.ok||!json.ok) throw new Error(json.error||`Save failed (${res.status})`);
        
        closeModal();
        await loadPaymentMethods();
        showToast(id>0?'Payment method updated successfully':'Payment method added successfully');
    }catch(err){
        showToast(err.message||'Error saving payment method', true);
    }
}

async function removePaymentMethod(id){
    if(!confirm('Delete this payment method?')) return;
    
    try{
        const {res,text}=await fetchText(API_PAYMENT_DELETE,{
            method:'POST',
            headers:Object.assign({'Content-Type':'application/json'},csrfHeader()),
            credentials:'same-origin',
            body:JSON.stringify({id})
        });
        const json=parseJSONorThrow(text,res.status);
        if(!res.ok||!json.ok) throw new Error(json.error||`Delete failed (${res.status})`);
        
        await loadPaymentMethods();
        showToast('Payment method deleted successfully');
    }catch(err){
        showToast(err.message||'Error deleting payment method', true);
    }
}

// AGGREGATORS
async function loadAggregators(){
    try{
        const {res,text}=await fetchText(API_AGGREGATOR_LIST,{credentials:'same-origin'});
        const json=parseJSONorThrow(text,res.status);
        if(!res.ok||!json.ok) throw new Error(json.error||`List failed (${res.status})`);
        
        aggregators = json.data || [];
        const tbody=document.getElementById('aggregatorsTable');
        
        if(tbody){
            if(aggregators.length > 0){
                tbody.innerHTML = aggregators.map(a => 
                    `<tr data-id="${a.id}">
                        <td><strong>${escapeHtml(a.name)}</strong></td>
                        <td><span class="percentage">${Number(a.commission_percent).toFixed(2)}%</span></td>
                        <td>${a.is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-gray">Inactive</span>'}</td>
                        <td><div class="action-buttons">
                            <button class="btn btn-sm" onclick="editAggregator(${a.id})">Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="removeAggregator(${a.id})">Delete</button>
                        </div></td>
                    </tr>`
                ).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="4" class="empty-state">No aggregators found. <button class="btn btn-primary" onclick="openAggregatorModal()" style="margin-left:12px;">Add First Aggregator</button></td></tr>';
            }
        }
    }catch(err){
        showToast(err.message||'Error loading aggregators', true);
    }
}

function openAggregatorModal(data){
    const isEdit=!!data;
    const ag=data || { id:0, name:'', commission_percent:0, is_active:1 };
    const modal=document.createElement('div'); 
    modal.className='modal-overlay';
    modal.innerHTML =
        '<div class="modal-content">'+
            '<div class="modal-header"><h3>'+(isEdit?'Edit Aggregator':'Add New Aggregator')+'</h3><button class="btn btn-sm" onclick="closeModal()">×</button></div>'+
            '<div class="modal-body">'+
                '<div class="form-row">'+
                    '<div class="form-group"><label class="form-label">Aggregator Name *</label><input id="modal_ag_name" class="form-input" value="'+escapeHtml(ag.name)+'" placeholder="Talabat" required></div>'+
                    '<div class="form-group"><label class="form-label">Commission (%)</label><input id="modal_ag_commission" class="form-input" type="number" step="0.01" min="0" max="100" value="'+(ag.commission_percent||0)+'"></div>'+
                '</div>'+
                '<div class="form-row">'+
                    '<div class="form-group"><label class="form-label">Status</label>'+
                        '<select id="modal_ag_active" class="form-select">'+
                            '<option value="1"'+(ag.is_active?' selected':'')+'>Active</option>'+
                            '<option value="0"'+(!ag.is_active?' selected':'')+'>Inactive</option>'+
                        '</select></div>'+
                '</div>'+
            '</div>'+
            '<div class="modal-footer"><button class="btn" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="saveAggregator('+(ag.id||0)+')">'+(isEdit?'Update':'Add')+' Aggregator</button></div>'+
        '</div>';
    document.body.appendChild(modal);
    modal.addEventListener('click',function(e){ if(e.target===modal) closeModal(); });
}

function editAggregator(id){
    const ag = aggregators.find(a => a.id === id);
    if(ag) openAggregatorModal(ag);
}

async function saveAggregator(id){
    const payload={
        id: id>0 ? id : null,
        name: document.getElementById('modal_ag_name').value.trim(),
        commission_percent: parseFloat(document.getElementById('modal_ag_commission').value)||0,
        is_active: parseInt(document.getElementById('modal_ag_active').value)
    };
    
    if(!payload.name){ 
        showToast('Please provide aggregator name.', true); 
        return; 
    }
    
    try{
        const {res,text}=await fetchText(API_AGGREGATOR_SAVE,{
            method:'POST',
            headers:Object.assign({'Content-Type':'application/json'},csrfHeader()),
            credentials:'same-origin',
            body:JSON.stringify(payload)
        });
        const json=parseJSONorThrow(text,res.status);
        if(!res.ok||!json.ok) throw new Error(json.error||`Save failed (${res.status})`);
        
        closeModal();
        await loadAggregators();
        showToast(id>0?'Aggregator updated successfully':'Aggregator added successfully');
    }catch(err){
        showToast(err.message||'Error saving aggregator', true);
    }
}

async function removeAggregator(id){
    if(!confirm('Delete this aggregator?')) return;
    
    try{
        const {res,text}=await fetchText(API_AGGREGATOR_DELETE,{
            method:'POST',
            headers:Object.assign({'Content-Type':'application/json'},csrfHeader()),
            credentials:'same-origin',
            body:JSON.stringify({id})
        });
        const json=parseJSONorThrow(text,res.status);
        if(!res.ok||!json.ok) throw new Error(json.error||`Delete failed (${res.status})`);
        
        await loadAggregators();
        showToast('Aggregator deleted successfully');
    }catch(err){
        showToast(err.message||'Error deleting aggregator', true);
    }
}

// Save all settings
async function saveAll(){
    const floatingSaveBtn = document.getElementById('floatingSaveBtn');
    const originalText = floatingSaveBtn ? floatingSaveBtn.textContent : '';
    
    const payload={
        default_tax_rate: document.getElementById('default_tax_rate')?.value || '',
        auto_calculate_tax: !!document.getElementById('auto_calculate_tax')?.checked,
        compound_tax: !!document.getElementById('compound_tax')?.checked,
        tax_on_service_charge: !!document.getElementById('tax_on_service_charge')?.checked,
        round_tax_calculations: !!document.getElementById('round_tax_calculations')?.checked,
        receipt_tax_breakdown: !!document.getElementById('receipt_tax_breakdown')?.checked
    };
    
    try{
        if (floatingSaveBtn) {
            floatingSaveBtn.disabled = true;
            floatingSaveBtn.textContent = 'Saving...';
        }

        const {res,text}=await fetchText(API_SETTINGS_SAVE,{
            method:'POST',
            headers:Object.assign({'Content-Type':'application/json'},csrfHeader()),
            credentials:'same-origin',
            body:JSON.stringify(payload)
        });
        const json=parseJSONorThrow(text,res.status);
        if(!res.ok||!json.ok) throw new Error(json.error||`Save failed (${res.status})`);
        
        isDirty=false;
        updateGlobalSaveUI();
        showToast('Finance settings saved successfully!');
    }catch(err){
        showToast(err.message||'Error saving settings', true);
    }finally{
        if (floatingSaveBtn) floatingSaveBtn.textContent = originalText;
        updateGlobalSaveUI();
    }
}

// Leave warning
window.addEventListener('beforeunload', function(e){
    if(!isDirty) return; 
    e.preventDefault(); 
    e.returnValue = 'You have unsaved changes';
});

// Initialize
document.addEventListener('DOMContentLoaded', function(){
    // Add event listeners for dirty tracking
    document.querySelectorAll('input, select').forEach(function(el){
        el.addEventListener('input', markDirty);
        el.addEventListener('change', markDirty);
    });
    
    // Load all data
    loadAllData();
    
    // Set initial UI state
    updateGlobalSaveUI();
    
    console.log('Finance page loaded');
});
</script>
</body>
</html>