<?php declare(strict_types=1);
/**
 * Simplified Tables & Zones Management
 * Path: /public_html/views/admin/settings/tables/index.php
 */

require_once __DIR__ . '/../../../../config/db.php';
require_once __DIR__ . '/../../../../middleware/auth_login.php';
if (function_exists('use_backend_session')) { use_backend_session(); }
auth_require_login();

$user = $_SESSION['user'] ?? [];
$tenant_id = (int)($user['tenant_id'] ?? 1);
$user_id = (int)($user['id'] ?? 1);
$active = 'settings_tables'; // Changed from 'tables' to 'settings_tables'

// Debug information
$debug_mode = isset($_GET['debug']) ? true : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tables Management - Smorll POS</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --ms-white: #ffffff;
      --ms-gray-10: #faf9f8;
      --ms-gray-20: #f3f2f1;
      --ms-gray-30: #edebe9;
      --ms-gray-40: #e1dfdd;
      --ms-gray-60: #c8c6c4;
      --ms-gray-110: #8a8886;
      --ms-gray-130: #605e5c;
      --ms-gray-160: #323130;
      
      --ms-blue: #0078d4;
      --ms-blue-hover: #106ebe;
      --ms-blue-light: #c7e0f4;
      --ms-blue-lighter: #deecf9;
      
      --ms-green: #107c10;
      --ms-green-light: #dff6dd;
      
      --ms-red: #d13438;
      --ms-red-light: #fdf2f2;
      
      --ms-purple: #5c2d91;
      --ms-purple-light: #f3e8ff;
      
      --ms-teal: #008272;
      --ms-teal-light: #ccfbf1;
      
      --ms-orange: #d83b01;
      --ms-orange-light: #fff4e6;
      
      --ms-shadow-1: 0 1px 2px rgba(0,0,0,0.05);
      --ms-shadow-2: 0 1.6px 3.6px 0 rgba(0,0,0,.132), 0 0.3px 0.9px 0 rgba(0,0,0,.108);
      --ms-shadow-3: 0 2px 8px rgba(0,0,0,0.092);
      
      --ms-radius: 4px;
      --ms-radius-lg: 8px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
      font-size: 14px;
      line-height: 1.5;
      color: var(--ms-gray-160);
      background: var(--ms-gray-10);
    }

    .container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 24px;
    }

    /* Debug Panel */
    .debug-panel {
      background: #fffbf0;
      border: 2px solid #ff9800;
      border-radius: var(--ms-radius-lg);
      padding: 16px;
      margin-bottom: 24px;
      font-family: monospace;
      font-size: 12px;
    }

    .debug-panel h3 {
      color: #ff6500;
      margin-bottom: 8px;
    }

    .debug-panel pre {
      background: #fff;
      padding: 8px;
      border-radius: 4px;
      overflow-x: auto;
    }

    /* Page Header */
    .page-header {
      margin-bottom: 24px;
    }

    .page-title {
      font-size: 28px;
      font-weight: 600;
      color: var(--ms-gray-160);
      margin: 0;
    }

    .page-subtitle {
      font-size: 14px;
      color: var(--ms-gray-110);
      margin-top: 4px;
    }

    /* Stats Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }

    .stat-card {
      background: white;
      padding: 24px;
      border-radius: var(--ms-radius-lg);
      box-shadow: var(--ms-shadow-2);
      transition: all 0.2s ease;
      border: 1px solid transparent;
      position: relative;
      overflow: hidden;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--ms-blue), var(--ms-purple));
    }

    .stat-card.zones::before { background: linear-gradient(90deg, var(--ms-purple), var(--ms-teal)); }
    .stat-card.tables::before { background: linear-gradient(90deg, var(--ms-blue), var(--ms-green)); }
    .stat-card.seats::before { background: linear-gradient(90deg, var(--ms-orange), var(--ms-purple)); }

    .stat-card:hover {
      box-shadow: var(--ms-shadow-3);
      transform: translateY(-2px);
    }
    .stat-value {
      font-size: 36px;
      font-weight: 700;
      line-height: 1.2;
      margin-bottom: 4px;
    }

    .stat-label {
      font-size: 12px;
      font-weight: 600;
      color: var(--ms-gray-110);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* Action Bar */
    .action-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 16px 20px;
      background: white;
      border-radius: var(--ms-radius-lg);
      box-shadow: var(--ms-shadow-2);
      margin-bottom: 24px;
      gap: 16px;
      flex-wrap: wrap;
    }

    .filter-group {
      display: flex;
      align-items: center;
      gap: 12px;
      flex: 1;
    }

    .filter-label {
      font-size: 13px;
      font-weight: 600;
      color: var(--ms-gray-130);
    }

    .filter-select {
      padding: 8px 12px;
      border: 1px solid var(--ms-gray-60);
      border-radius: var(--ms-radius);
      font-size: 14px;
      background: white;
      transition: all 0.2s ease;
      font-family: inherit;
      min-width: 200px;
    }

    .filter-input {
      padding: 8px 12px;
      border: 1px solid var(--ms-gray-60);
      border-radius: var(--ms-radius);
      font-size: 14px;
      background: white;
      transition: all 0.2s ease;
      font-family: inherit;
      min-width: 200px;
    }

    .filter-select:focus,
    .filter-input:focus {
      outline: none;
      border-color: var(--ms-blue);
      box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.25);
    }

    .action-buttons {
      display: flex;
      gap: 8px;
    }

    /* Button Styles */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 10px 16px;
      border-radius: var(--ms-radius);
      font-size: 14px;
      font-weight: 500;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.1s ease;
      border: 1px solid transparent;
      font-family: inherit;
      white-space: nowrap;
    }

    .btn-primary {
      background: var(--ms-blue);
      color: white;
      border-color: var(--ms-blue);
    }

    .btn-primary:hover {
      background: var(--ms-blue-hover);
      border-color: var(--ms-blue-hover);
    }

    .btn-secondary {
      background: white;
      color: var(--ms-gray-160);
      border: 1px solid var(--ms-gray-60);
    }

    .btn-secondary:hover {
      background: var(--ms-gray-20);
      border-color: var(--ms-gray-110);
    }

    .btn-success {
      background: var(--ms-green);
      color: white;
      border-color: var(--ms-green);
    }

    .btn-success:hover {
      background: #0e5e0e;
      border-color: #0e5e0e;
    }

    .btn-danger {
      background: white;
      color: var(--ms-red);
      border: 1px solid var(--ms-red-light);
    }

    .btn-danger:hover {
      background: var(--ms-red-light);
      border-color: var(--ms-red);
    }

    .btn.small {
      padding: 6px 12px;
      font-size: 12px;
    }

    .btn.icon-only {
      padding: 6px;
      width: 32px;
      height: 32px;
      justify-content: center;
    }

    /* Zones Container */
    .zones-container {
      display: flex;
      flex-direction: column;
      gap: 24px;
    }

    .zone-section {
      background: white;
      border-radius: var(--ms-radius-lg);
      box-shadow: var(--ms-shadow-2);
      overflow: hidden;
      border-left: 4px solid;
      transition: all 0.2s ease;
    }

    .zone-section:hover {
      box-shadow: var(--ms-shadow-3);
    }

    /* Zone Colors */
    .zone-section:nth-child(6n+1) { border-left-color: var(--ms-blue); }
    .zone-section:nth-child(6n+2) { border-left-color: var(--ms-green); }
    .zone-section:nth-child(6n+3) { border-left-color: var(--ms-purple); }
    .zone-section:nth-child(6n+4) { border-left-color: var(--ms-teal); }
    .zone-section:nth-child(6n+5) { border-left-color: var(--ms-orange); }
    .zone-section:nth-child(6n+6) { border-left-color: var(--ms-red); }

    .zone-header {
      padding: 16px 20px;
      background: var(--ms-gray-10);
      border-bottom: 1px solid var(--ms-gray-30);
      display: flex;
      justify-content: space-between;
      align-items: center;
      cursor: pointer;
    }

    .zone-header:hover {
      background: var(--ms-gray-20);
    }

    .zone-info {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .zone-name {
      font-size: 18px;
      font-weight: 600;
      color: var(--ms-gray-160);
      text-transform: capitalize;
    }

    .zone-stats {
      display: flex;
      gap: 12px;
      font-size: 13px;
      color: var(--ms-gray-110);
    }

    .zone-stat {
      padding: 4px 10px;
      background: var(--ms-gray-20);
      border-radius: 12px;
    }

    .zone-actions {
      display: flex;
      gap: 4px;
    }

    .zone-body {
      padding: 20px;
    }

    .zone-body.collapsed {
      display: none;
    }

    .tables-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
      gap: 12px;
      min-height: 60px;
    }

    .table-card {
      background: var(--ms-gray-20);
      border: 2px solid var(--ms-gray-40);
      border-radius: var(--ms-radius);
      padding: 12px;
      text-align: center;
      transition: all 0.2s ease;
      cursor: pointer;
      position: relative;
    }

    .table-card:hover {
      background: var(--ms-blue-lighter);
      border-color: var(--ms-blue-light);
      transform: translateY(-2px);
      box-shadow: var(--ms-shadow-2);
    }

    .table-number {
      font-weight: 600;
      font-size: 16px;
      color: var(--ms-gray-160);
      margin-bottom: 4px;
    }

    .table-seats {
      font-size: 12px;
      color: var(--ms-gray-110);
    }

    .table-card .delete-btn {
      position: absolute;
      top: -8px;
      right: -8px;
      width: 24px;
      height: 24px;
      background: var(--ms-red);
      color: white;
      border: 2px solid white;
      border-radius: 50%;
      display: none;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 16px;
      font-weight: bold;
      box-shadow: var(--ms-shadow-2);
    }

    .table-card:hover .delete-btn {
      display: flex;
    }

    .empty-zone {
      padding: 40px 20px;
      text-align: center;
      color: var(--ms-gray-110);
      font-style: italic;
      background: var(--ms-gray-10);
      border-radius: var(--ms-radius);
      border: 2px dashed var(--ms-gray-40);
    }

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.6);
      z-index: 1000;
      backdrop-filter: blur(4px);
    }

    .modal.show {
      display: flex;
      align-items: center;
      justify-content: center;
      animation: fadeIn 0.2s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    .modal-content {
      background: white;
      border-radius: var(--ms-radius-lg);
      max-width: 500px;
      width: 90%;
      max-height: 90vh;
      overflow: hidden;
      animation: slideIn 0.3s ease;
    }

    @keyframes slideIn {
      from { transform: translateY(-20px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    .modal-header {
      padding: 20px 24px;
      border-bottom: 1px solid var(--ms-gray-30);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .modal-title {
      font-size: 18px;
      font-weight: 600;
      color: var(--ms-gray-160);
    }

    .modal-close {
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: transparent;
      border: none;
      color: var(--ms-gray-110);
      cursor: pointer;
      border-radius: var(--ms-radius);
      font-size: 20px;
    }

    .modal-close:hover {
      background: var(--ms-gray-20);
    }

    .modal-body {
      padding: 24px;
    }

    .modal-footer {
      padding: 16px 24px;
      border-top: 1px solid var(--ms-gray-30);
      display: flex;
      justify-content: flex-end;
      gap: 8px;
    }

    /* Form Elements */
    .form-group {
      margin-bottom: 20px;
    }

    .form-label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: var(--ms-gray-130);
      margin-bottom: 6px;
    }

    .form-input,
    .form-select {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--ms-gray-60);
      border-radius: var(--ms-radius);
      font-size: 14px;
      background: white;
      transition: all 0.2s ease;
      font-family: inherit;
    }

    .form-input:focus,
    .form-select:focus {
      outline: none;
      border-color: var(--ms-blue);
      box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.25);
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .required {
      color: var(--ms-red);
    }

    /* Toast */
    .toast-container {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 9999;
    }

    .toast {
      background: white;
      border-radius: var(--ms-radius-lg);
      padding: 16px 20px;
      margin-bottom: 12px;
      box-shadow: var(--ms-shadow-3);
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 300px;
      animation: slideInRight 0.3s ease;
      border-left: 4px solid;
    }

    @keyframes slideInRight {
      from { transform: translateX(100%); }
      to { transform: translateX(0); }
    }

    .toast.success { border-left-color: var(--ms-green); }
    .toast.error { border-left-color: var(--ms-red); }
    .toast.warning { border-left-color: var(--ms-orange); }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 60px 20px;
      background: white;
      border-radius: var(--ms-radius-lg);
      border: 2px dashed var(--ms-gray-40);
    }

    .empty-state h3 {
      font-size: 18px;
      margin-bottom: 8px;
      color: var(--ms-gray-130);
    }

    .empty-state p {
      color: var(--ms-gray-110);
      margin-bottom: 20px;
    }

    .toggle-icon {
      transition: transform 0.3s ease;
      margin-left: 8px;
    }

    .zone-header.collapsed .toggle-icon {
      transform: rotate(-90deg);
    }

    /* Loading State */
    .loading {
      text-align: center;
      padding: 40px;
      color: var(--ms-gray-110);
    }

    .loading::after {
      content: '';
      display: inline-block;
      width: 20px;
      height: 20px;
      border: 3px solid var(--ms-gray-60);
      border-top-color: var(--ms-blue);
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      margin-left: 10px;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    /* Responsive */
    @media (max-width: 768px) {
      .container { padding: 16px; }
      .form-row { grid-template-columns: 1fr; }
      .action-bar { flex-direction: column; align-items: stretch; }
      .filter-group { width: 100%; }
      .tables-grid { grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); }
    }
  </style>
</head>
<body>

<?php
  // Updated nav path for new location
  $nav_path = __DIR__ . '/../../../partials/admin_nav.php';
  if (is_file($nav_path)) { require $nav_path; }
?>

<div class="container">
  <div class="toast-container" id="toastContainer"></div>

  <!-- Debug Panel (remove in production) -->
  <?php if ($debug_mode): ?>
  <div class="debug-panel">
    <h3>Debug Information</h3>
    <pre>
User: <?= json_encode($user, JSON_PRETTY_PRINT) ?>
Tenant ID: <?= $tenant_id ?>
User ID: <?= $user_id ?>
Session: <?= json_encode($_SESSION, JSON_PRETTY_PRINT) ?>
    </pre>
  </div>
  <?php endif; ?>

  <!-- Page Header -->
  <div class="page-header">
    <h1 class="page-title">Tables Management</h1>
    <p class="page-subtitle">Tenant ID: <?= $tenant_id ?> | Manage your restaurant zones and tables</p>
  </div>

  <!-- Stats Dashboard -->
  <div class="stats-grid">
    <div class="stat-card zones">
      <div class="stat-value" id="totalZones">0</div>
      <div class="stat-label">Total Zones</div>
    </div>
    <div class="stat-card tables">
      <div class="stat-value" id="totalTables">0</div>
      <div class="stat-label">Total Tables</div>
    </div>
    <div class="stat-card seats">
      <div class="stat-value" id="totalSeats">0</div>
      <div class="stat-label">Total Seats</div>
    </div>
  </div>

  <!-- Action Bar -->
  <div class="action-bar">
    <div class="filter-group">
      <label class="filter-label">Filter:</label>
      <select class="filter-select" id="filterType" onchange="updateFilter()">
        <option value="all">All Tables</option>
        <option value="zone">By Zone</option>
        <option value="table">By Table</option>
      </select>
      <input type="text" class="filter-input" id="filterInput" placeholder="Type to filter..." style="display:none;" oninput="applyFilter()">
      <select class="filter-select" id="zoneSelect" style="display:none;" onchange="applyFilter()">
        <option value="">All Zones</option>
      </select>
    </div>
    <div class="action-buttons">
      <button class="btn btn-primary" onclick="showZoneModal()">+ Add Zone</button>
      <button class="btn btn-secondary" onclick="showTableModal()">+ Add Tables</button>
    </div>
  </div>

  <!-- Loading State -->
  <div id="loadingState" class="loading" style="display: none;">
    Loading tables...
  </div>

  <!-- Zones Container -->
  <div class="zones-container" id="zonesContainer">
    <!-- Zones will be rendered here -->
  </div>

  <!-- Empty State -->
  <div id="emptyState" class="empty-state" style="display: none;">
    <h3>No zones created yet</h3>
    <p>Start by creating your first zone to organize your tables</p>
    <button class="btn btn-primary" onclick="showZoneModal()">+ Create Your First Zone</button>
  </div>
</div>

<!-- Zone Modal -->
<div class="modal" id="zoneModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 class="modal-title" id="zoneModalTitle">Create Zone</h3>
      <button class="modal-close" onclick="closeModal('zoneModal')">&times;</button>
    </div>
    <form onsubmit="saveZone(event)">
      <div class="modal-body">
        <input type="hidden" id="zoneId">
        <input type="hidden" id="oldZoneName">
        <div class="form-group">
          <label class="form-label">Zone Name <span class="required">*</span></label>
          <input type="text" class="form-input" id="zoneName" placeholder="e.g., Main Hall, VIP Section, Outdoor" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('zoneModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Zone</button>
      </div>
    </form>
  </div>
</div>

<!-- Table Modal -->
<div class="modal" id="tableModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 class="modal-title" id="tableModalTitle">Add Table</h3>
      <button class="modal-close" onclick="closeModal('tableModal')">&times;</button>
    </div>
    <form id="tableForm" onsubmit="saveTable(event)">
      <div class="modal-body">
        <input type="hidden" id="tableId">
        
        <div class="form-group">
          <label class="form-label">Zone <span class="required">*</span></label>
          <select class="form-select" id="tableZone" required>
            <option value="">Select Zone</option>
          </select>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Table Number <span class="required">*</span></label>
            <input type="text" class="form-input" id="tableNumber" placeholder="e.g., T1, A1" required>
          </div>
          <div class="form-group">
            <label class="form-label">Number of Seats <span class="required">*</span></label>
            <input type="number" class="form-input" id="tableSeats" min="1" max="20" value="4" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('tableModal')">Cancel</button>
        <button type="submit" name="action" value="save_close" class="btn btn-primary">Save & Close</button>
        <button type="submit" name="action" value="save_new" class="btn btn-success">Save & Add Another</button>
      </div>
    </form>
  </div>
</div>

<script>
  const API_BASE = '/controllers/admin/tables/setup.php'; // This remains the same
  const TENANT_ID = <?= json_encode($tenant_id) ?>;
  const DEBUG_MODE = <?= json_encode($debug_mode) ?>;
  
  let zones = {};
  let allTables = [];
  let currentFilter = { type: 'all', value: '' };
  let collapsedZones = new Set();

  document.addEventListener('DOMContentLoaded', () => {
    console.log('Page loaded, initializing...');
    console.log('API_BASE:', API_BASE);
    console.log('TENANT_ID:', TENANT_ID);
    loadData();
    
    // Add event listener for Save & Add Another
    document.getElementById('tableForm').addEventListener('submit', function(e) {
      const submitter = e.submitter;
      if (submitter && submitter.value === 'save_new') {
        e.preventDefault();
        saveTable(e, true);
      }
    });
  });

  async function loadData() {
    console.log('=== Starting loadData ===');
    console.log('Fetching from:', API_BASE + '?action=list');
    
    // Show loading state
    const loadingState = document.getElementById('loadingState');
    const zonesContainer = document.getElementById('zonesContainer');
    const emptyState = document.getElementById('emptyState');
    
    if (loadingState) loadingState.style.display = 'block';
    if (zonesContainer) zonesContainer.style.display = 'none';
    if (emptyState) emptyState.style.display = 'none';
    
    try {
      const url = API_BASE + '?action=list';
      console.log('Fetching URL:', url);
      
      const response = await fetch(url);
      console.log('Response status:', response.status);
      console.log('Response OK:', response.ok);
      console.log('Response headers:', [...response.headers.entries()]);
      
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      
      const text = await response.text();
      console.log('Raw response text:', text);
      
      if (!text) {
        throw new Error('Empty response from server');
      }
      
      let result;
      try {
        result = JSON.parse(text);
        console.log('Parsed JSON result:', result);
      } catch (parseError) {
        console.error('JSON Parse Error:', parseError);
        console.error('Invalid JSON received:', text.substring(0, 500));
        showToast('Server returned invalid response format', 'error');
        return;
      }
      
      if (result.ok && result.data) {
        console.log('Data received successfully:', result.data);
        allTables = result.data;
        console.log('Tables count:', allTables.length);
        
        organizeByZones();
        renderZones();
        updateStats();
        updateFilterOptions();
      } else {
        console.error('API returned error:', result);
        showToast(result.error || 'Failed to load data', 'error');
      }
    } catch (error) {
      console.error('=== LoadData Error ===');
      console.error('Error type:', error.constructor.name);
      console.error('Error message:', error.message);
      console.error('Error stack:', error.stack);
      showToast('Failed to load data: ' + error.message, 'error');
    } finally {
      // Hide loading state
      if (loadingState) loadingState.style.display = 'none';
      if (zonesContainer) zonesContainer.style.display = 'flex';
    }
  }

  function organizeByZones() {
    console.log('Organizing tables by zones...');
    zones = {};
    
    allTables.forEach(table => {
      const zone = table.section || 'main';
      if (!zones[zone]) {
        zones[zone] = { name: zone, tables: [] };
      }
      zones[zone].tables.push(table);
    });
    
    console.log('Organized zones:', zones);
    console.log('Zone count:', Object.keys(zones).length);
  }

  function renderZones() {
    console.log('Rendering zones...');
    const container = document.getElementById('zonesContainer');
    const emptyState = document.getElementById('emptyState');
    
    if (!container) {
      console.error('zonesContainer element not found!');
      return;
    }
    
    // Apply filters
    let zonesToRender = Object.entries(zones);
    
    if (currentFilter.type === 'zone' && currentFilter.value) {
      zonesToRender = zonesToRender.filter(([name]) => name === currentFilter.value);
    } else if (currentFilter.type === 'table' && currentFilter.value) {
      const searchTerm = currentFilter.value.toLowerCase();
      zonesToRender = zonesToRender.map(([name, zone]) => {
        const filteredTables = zone.tables.filter(t => 
          t.table_number.toLowerCase().includes(searchTerm)
        );
        return filteredTables.length > 0 ? [name, { ...zone, tables: filteredTables }] : null;
      }).filter(Boolean);
    }

    if (zonesToRender.length === 0 && Object.keys(zones).length === 0) {
      console.log('No zones found, showing empty state');
      container.innerHTML = '';
      container.style.display = 'none';
      if (emptyState) emptyState.style.display = 'block';
      return;
    }

    if (emptyState) emptyState.style.display = 'none';
    container.style.display = 'flex';
    
    if (zonesToRender.length === 0) {
      container.innerHTML = '<div class="empty-state">No matching tables found</div>';
      return;
    }

    console.log('Rendering', zonesToRender.length, 'zones');
    
    container.innerHTML = zonesToRender.map(([zoneName, zone]) => {
      const totalSeats = zone.tables.reduce((sum, t) => sum + parseInt(t.seats || 0), 0);
      const isCollapsed = collapsedZones.has(zoneName);
      
      return `
        <div class="zone-section">
          <div class="zone-header ${isCollapsed ? 'collapsed' : ''}" onclick="toggleZone('${escapeHtml(zoneName)}')">
            <div class="zone-info">
              <div class="zone-name">${escapeHtml(zoneName)}</div>
              <div class="zone-stats">
                <span class="zone-stat">${zone.tables.length} tables</span>
                <span class="zone-stat">${totalSeats} seats</span>
              </div>
            </div>
            <div class="zone-actions" onclick="event.stopPropagation()">
              <button class="btn small" onclick="showTableModalForZone('${escapeHtml(zoneName)}')">+ Add Table</button>
              <button class="btn small" onclick="editZone('${escapeHtml(zoneName)}')">Edit</button>
              <button class="btn small btn-danger" onclick="deleteZone('${escapeHtml(zoneName)}')">Delete</button>
              <span class="toggle-icon">▼</span>
            </div>
          </div>
          <div class="zone-body ${isCollapsed ? 'collapsed' : ''}">
            ${zone.tables.length > 0 ? `
              <div class="tables-grid">
                ${zone.tables.map(table => `
                  <div class="table-card" onclick="editTable(${table.id})">
                    <button class="delete-btn" onclick="event.stopPropagation(); deleteTable(${table.id})">×</button>
                    <div class="table-number">${escapeHtml(table.table_number)}</div>
                    <div class="table-seats">${table.seats} seats</div>
                  </div>
                `).join('')}
              </div>
            ` : '<div class="empty-zone">No tables in this zone yet</div>'}
          </div>
        </div>
      `;
    }).join('');
  }

  function toggleZone(zoneName) {
    if (collapsedZones.has(zoneName)) {
      collapsedZones.delete(zoneName);
    } else {
      collapsedZones.add(zoneName);
    }
    renderZones();
  }

  function updateStats() {
    const totalZones = Object.keys(zones).length;
    const totalTables = allTables.length;
    const totalSeats = allTables.reduce((sum, t) => sum + parseInt(t.seats || 0), 0);
    
    document.getElementById('totalZones').textContent = totalZones;
    document.getElementById('totalTables').textContent = totalTables;
    document.getElementById('totalSeats').textContent = totalSeats;
  }

  function updateFilterOptions() {
    const zoneSelect = document.getElementById('zoneSelect');
    zoneSelect.innerHTML = '<option value="">All Zones</option>';
    Object.keys(zones).forEach(zone => {
      zoneSelect.innerHTML += `<option value="${escapeHtml(zone)}">${escapeHtml(zone)}</option>`;
    });
    
    // Update table modal zone dropdown
    const tableZoneSelect = document.getElementById('tableZone');
    tableZoneSelect.innerHTML = '<option value="">Select Zone</option>';
    
    // Add existing zones
    Object.keys(zones).forEach(zone => {
      tableZoneSelect.innerHTML += `<option value="${escapeHtml(zone)}">${escapeHtml(zone)}</option>`;
    });
    
    // Add option to create new zone
    tableZoneSelect.innerHTML += '<option value="__new__">+ Create New Zone</option>';
  }

  function updateFilter() {
    const filterType = document.getElementById('filterType').value;
    const filterInput = document.getElementById('filterInput');
    const zoneSelect = document.getElementById('zoneSelect');
    
    currentFilter.type = filterType;
    
    if (filterType === 'all') {
      filterInput.style.display = 'none';
      zoneSelect.style.display = 'none';
      currentFilter.value = '';
    } else if (filterType === 'zone') {
      filterInput.style.display = 'none';
      zoneSelect.style.display = 'block';
      currentFilter.value = zoneSelect.value;
    } else if (filterType === 'table') {
      filterInput.style.display = 'block';
      zoneSelect.style.display = 'none';
      filterInput.placeholder = 'Enter table number...';
      currentFilter.value = filterInput.value;
    }
    
    applyFilter();
  }

  function applyFilter() {
    if (currentFilter.type === 'zone') {
      currentFilter.value = document.getElementById('zoneSelect').value;
    } else if (currentFilter.type === 'table') {
      currentFilter.value = document.getElementById('filterInput').value;
    }
    renderZones();
  }

  // Zone Management
  function showZoneModal() {
    document.getElementById('zoneModalTitle').textContent = 'Create Zone';
    document.getElementById('zoneId').value = '';
    document.getElementById('oldZoneName').value = '';
    document.getElementById('zoneName').value = '';
    document.getElementById('zoneModal').classList.add('show');
  }

  function editZone(zoneName) {
    document.getElementById('zoneModalTitle').textContent = 'Edit Zone';
    document.getElementById('zoneId').value = 'edit';
    document.getElementById('oldZoneName').value = zoneName;
    document.getElementById('zoneName').value = zoneName;
    document.getElementById('zoneModal').classList.add('show');
  }

  async function saveZone(event) {
    event.preventDefault();
    const isEdit = document.getElementById('zoneId').value === 'edit';
    const oldName = document.getElementById('oldZoneName').value;
    const newName = document.getElementById('zoneName').value.trim();
    
    if (!newName) {
      showToast('Zone name is required', 'warning');
      return;
    }

    if (isEdit && oldName !== newName) {
      // Update all tables in this zone
      const tablesToUpdate = zones[oldName]?.tables || [];
      for (const table of tablesToUpdate) {
        await updateTableZone(table.id, newName);
      }
      showToast('Zone updated successfully', 'success');
    } else if (!isEdit) {
      // Create empty zone
      if (zones[newName]) {
        showToast('Zone already exists', 'warning');
        return;
      }
      showToast('Zone created. Add tables to populate it.', 'success');
    }

    closeModal('zoneModal');
    await loadData();
  }

  async function deleteZone(zoneName) {
    const zone = zones[zoneName];
    if (!zone) return;
    
    if (zone.tables.length > 0) {
      if (!confirm(`Delete zone "${zoneName}" and all ${zone.tables.length} tables?`)) return;
      
      for (const table of zone.tables) {
        await deleteTable(table.id, false);
      }
    } else {
      if (!confirm(`Delete empty zone "${zoneName}"?`)) return;
    }
    
    await loadData();
    showToast('Zone deleted successfully', 'success');
  }

  // Table Management
  function showTableModal() {
    document.getElementById('tableModalTitle').textContent = 'Add Table';
    document.getElementById('tableId').value = '';
    document.getElementById('tableNumber').value = '';
    document.getElementById('tableSeats').value = '4';
    updateFilterOptions(); // Refresh zone dropdown
    document.getElementById('tableModal').classList.add('show');
  }

  function showTableModalForZone(zone) {
    showTableModal();
    document.getElementById('tableZone').value = zone;
  }

  async function saveTable(event, addAnother = false) {
    if (event) event.preventDefault();
    
    let zone = document.getElementById('tableZone').value;
    const number = document.getElementById('tableNumber').value.trim();
    const seats = parseInt(document.getElementById('tableSeats').value);
    
    // Handle new zone creation
    if (zone === '__new__') {
      const newZoneName = prompt('Enter new zone name:');
      if (!newZoneName || !newZoneName.trim()) {
        showToast('Zone name is required', 'warning');
        return;
      }
      zone = newZoneName.trim();
    }
    
    if (!zone) {
      showToast('Please select a zone', 'warning');
      return;
    }
    
    if (!number) {
      showToast('Table number is required', 'warning');
      return;
    }
    
    console.log('Saving table:', { zone, number, seats });
    
    try {
      const response = await fetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'create',
          table_number: number,
          section: zone,
          seats: seats
        })
      });
      
      const text = await response.text();
      console.log('Save response:', text);
      
      let result;
      try {
        result = JSON.parse(text);
      } catch (e) {
        console.error('Failed to parse response:', text);
        showToast('Server returned invalid response', 'error');
        return;
      }
      
      if (result.ok) {
        showToast('Table added successfully', 'success');
        
        if (addAnother) {
          // Clear only the table number field for quick entry
          document.getElementById('tableNumber').value = '';
          document.getElementById('tableNumber').focus();
        } else {
          closeModal('tableModal');
        }
        
        await loadData();
      } else {
        showToast(result.error || 'Failed to add table', 'error');
      }
    } catch (error) {
      console.error('Save table error:', error);
      showToast('Failed to add table: ' + error.message, 'error');
    }
  }

  function editTable(tableId) {
    const table = allTables.find(t => t.id === tableId);
    if (!table) return;
    
    const newSeats = prompt(`Update seats for table ${table.table_number}:`, table.seats);
    if (newSeats && !isNaN(newSeats) && newSeats > 0) {
      updateTable(tableId, { seats: parseInt(newSeats) });
    }
  }

  async function updateTable(tableId, updates) {
    try {
      const table = allTables.find(t => t.id === tableId);
      const response = await fetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'update',
          id: tableId,
          table_number: table.table_number,
          section: table.section,
          seats: updates.seats || table.seats
        })
      });
      
      const result = await response.json();
      if (result.ok) {
        await loadData();
        showToast('Table updated', 'success');
      } else {
        showToast(result.error || 'Failed to update table', 'error');
      }
    } catch (error) {
      console.error('Update table error:', error);
      showToast('Failed to update table', 'error');
    }
  }

  async function updateTableZone(tableId, newZone) {
    const table = allTables.find(t => t.id === tableId);
    if (!table) return;
    
    try {
      await fetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'update',
          id: tableId,
          table_number: table.table_number,
          section: newZone,
          seats: table.seats
        })
      });
    } catch (error) {
      console.error('Failed to update table zone:', error);
    }
  }

  async function deleteTable(tableId, reload = true) {
    if (!confirm('Delete this table?')) return;
    
    console.log('Deleting table:', tableId);
    
    try {
      const response = await fetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: tableId })
      });
      
      const result = await response.json();
      if (result.ok) {
        if (reload) {
          await loadData();
          showToast('Table deleted', 'success');
        }
      } else {
        showToast(result.error || 'Failed to delete table', 'error');
      }
    } catch (error) {
      console.error('Delete table error:', error);
      showToast('Failed to delete table', 'error');
    }
  }

  // UI Helpers
  function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
  }

  function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = message;
    container.appendChild(toast);
    
    setTimeout(() => {
      toast.style.opacity = '0';
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  // Close modals on escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal.show').forEach(modal => {
        modal.classList.remove('show');
      });
    }
  });
</script>

</body>
</html>