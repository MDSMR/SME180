<?php 
// public_html/views/admin/settings/printers.php
// Setup → Printers & Hardware (Printer Configuration • Category Assignments)
declare(strict_types=1);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* ---------- Bootstrap /config/db.php (robust search) ---------- */
$bootstrap_warning=''; $bootstrap_ok=false; $bootstrap_found='';
$bootstrap_tried=[];

$tryList = [
  __DIR__ . '/../../../config/db.php', // /views/admin/settings -> /config/db.php
  __DIR__ . '/../../config/db.php',
];
foreach ($tryList as $p) { if (!in_array($p,$bootstrap_tried,true)) $bootstrap_tried[]=$p; }

$cursor = __DIR__;
for ($i=0;$i<6;$i++){
  $cursor = dirname($cursor);
  if ($cursor==='' || $cursor==='/' || $cursor==='\\') break;
  $maybe = $cursor . '/config/db.php';
  if (!in_array($maybe,$bootstrap_tried,true)) $bootstrap_tried[]=$maybe;
}

foreach ($bootstrap_tried as $cand) {
  if (is_file($cand)) {
    try {
      require_once $cand; // expect use_backend_session()
      if (function_exists('use_backend_session')) { $bootstrap_ok=true; $bootstrap_found=$cand; break; }
      else { $bootstrap_warning = 'Missing function use_backend_session() in config/db.php'; }
    } catch (Throwable $e) { $bootstrap_warning = 'Bootstrap error: '.$e->getMessage(); }
  }
}
if (!$bootstrap_ok && $bootstrap_warning==='') { $bootstrap_warning = 'Configuration file not found: /config/db.php'; }

/* ---------- Session & Auth ---------- */
if ($bootstrap_ok) {
  try { use_backend_session(); } catch(Throwable $e) { $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: '.$e->getMessage()); }
}
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

/* ---------- Initialize PDO Connection ---------- */
$pdo = null;
if ($bootstrap_ok && function_exists('db')) {
  try {
    $pdo = db();
  } catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    $bootstrap_warning .= ' Database connection failed.';
  }
}

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- Load Real Data from Database (Your Schema) ---------- */
$debug_info = []; // For debugging

// Load branches from database
$branches = [];
if ($pdo) {
  try {
    $tenantId = (int)$user['tenant_id']; 
    $debug_info[] = "Loading branches for tenant: " . $tenantId;
    
    $stmt = $pdo->prepare("
      SELECT id, name 
      FROM branches 
      WHERE tenant_id = :tenant_id AND is_active = 1 
      ORDER BY name ASC
    ");
    $stmt->execute([':tenant_id' => $tenantId]);
    $branchesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($branchesData as $row) {
      $branches[] = [
        'id' => (int)$row['id'],
        'name' => $row['name']
      ];
    }
    
    $debug_info[] = "Loaded " . count($branches) . " branches from database";
    
  } catch (Exception $e) {
    $debug_info[] = "Error loading branches: " . $e->getMessage();
    error_log("Error loading branches: " . $e->getMessage());
    // Fallback branches
    $branches = [
      ['id'=>1,'name'=>'Main Branch'],
      ['id'=>2,'name'=>'Secondary Branch'],
    ];
  }
} else {
  // Fallback branches when no database connection
  $branches = [
    ['id'=>1,'name'=>'Sample Branch'],
  ];
}

// Load printers from database using your actual schema
$printers = [];
$debug_info[] = "Loading printers from database using custom schema...";

if ($pdo) {
  try {
    $tenantId = (int)$user['tenant_id']; 
    $debug_info[] = "Tenant ID for printers: " . $tenantId;
    
    // Check what columns exist for debugging
    $stmt = $pdo->prepare("SHOW COLUMNS FROM printers");
    $stmt->execute();
    $actualColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $debug_info[] = "Available columns: " . implode(', ', $actualColumns);
    
    // Build query based on your actual schema
    $selectFields = [
      'p.id',
      'p.name',
      'p.type',
      'p.connection_type',
      'p.connection_string',
      'p.model',
      'p.station',
      'p.branch_id',
      'p.brand',
      'p.paper_size',
      'p.status',
      'p.last_ping'
    ];
    
    $selectSQL = implode(', ', $selectFields);
    
    // Fetch all active printers for this tenant with branch information
    $stmt = $pdo->prepare("
      SELECT 
        $selectSQL,
        COALESCE(b.name, 'No Branch') as branch_name
      FROM printers p
      LEFT JOIN branches b ON p.branch_id = b.id AND b.tenant_id = ?
      WHERE p.tenant_id = ? AND p.is_active = 1
      ORDER BY p.id ASC
    ");
    $stmt->execute([$tenantId, $tenantId]);
    $printersData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug_info[] = "Found " . count($printersData) . " printers in database";
    
    // Convert to the expected format with schema mapping
    foreach ($printersData as $row) {
      // Parse connection_string to get IP and port
      $ip_address = '';
      $port = '9100';
      
      if (!empty($row['connection_string']) && $row['connection_type'] === 'ip') {
        // Try to parse IP:PORT format
        if (strpos($row['connection_string'], ':') !== false) {
          $parts = explode(':', $row['connection_string']);
          $ip_address = $parts[0];
          $port = $parts[1] ?? '9100';
        } else {
          $ip_address = $row['connection_string'];
        }
      }
      
      // Map your connection_type 'ip' to 'ethernet' for display
      $connection_type = $row['connection_type'];
      if ($connection_type === 'ip') {
        $connection_type = 'ethernet';
      }
      
      $printers[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'type' => $row['type'],
        'brand' => $row['brand'] ?? '',
        'model' => $row['model'] ?? '',
        'connection_type' => $connection_type,
        'ip_address' => $ip_address,
        'port' => $port,
        'paper_size' => $row['paper_size'] ?? '80mm',
        'branch_id' => (int)$row['branch_id'],
        'branch_name' => $row['branch_name'],
        'status' => $row['status'] ?? 'offline',
        'last_ping' => $row['last_ping'],
        'station' => $row['station'] ?? '' // Your custom field
      ];
    }
    
    $debug_info[] = "Processed " . count($printers) . " printers successfully";
    
  } catch (Exception $e) {
    $debug_info[] = "Database error loading printers: " . $e->getMessage();
    error_log("Error loading printers: " . $e->getMessage());
    
    // Fall back to sample data if database fails
    $printers = [
      ['id'=>1,'name'=>'Main Receipt Printer','type'=>'receipt','brand'=>'','model'=>'','connection_type'=>'ethernet','ip_address'=>'192.168.1.100','port'=>'9100','paper_size'=>'80mm','branch_id'=>1,'branch_name'=>'Fallback Branch','status'=>'offline','last_ping'=>null,'station'=>''],
      ['id'=>2,'name'=>'Kitchen Printer','type'=>'kitchen','brand'=>'','model'=>'','connection_type'=>'ethernet','ip_address'=>'192.168.1.101','port'=>'9100','paper_size'=>'80mm','branch_id'=>1,'branch_name'=>'Fallback Branch','status'=>'offline','last_ping'=>null,'station'=>''],
    ];
    $debug_info[] = "Using fallback sample data (" . count($printers) . " printers)";
  }
} else {
  // No database connection - use sample data
  $debug_info[] = "No database connection - using sample data";
  $printers = [
    ['id'=>1,'name'=>'Sample Receipt Printer','type'=>'receipt','brand'=>'','model'=>'','connection_type'=>'ethernet','ip_address'=>'192.168.1.100','port'=>'9100','paper_size'=>'80mm','branch_id'=>1,'branch_name'=>'Sample Branch','status'=>'offline','last_ping'=>null,'station'=>''],
    ['id'=>2,'name'=>'Sample Kitchen Printer','type'=>'kitchen','brand'=>'','model'=>'','connection_type'=>'ethernet','ip_address'=>'192.168.1.101','port'=>'9100','paper_size'=>'80mm','branch_id'=>1,'branch_name'=>'Sample Branch','status'=>'offline','last_ping'=>null,'station'=>''],
  ];
}

// Get categories from database with their printer assignments
$categories = [];

if ($pdo) {
  try {
    $tenantId = (int)$user['tenant_id']; 
    $debug_info[] = "Tenant ID: " . $tenantId;
    
    // Fetch all active POS-visible categories for this tenant
    $stmt = $pdo->prepare("
      SELECT id, name_en, name_ar, parent_id
      FROM categories 
      WHERE tenant_id = :tenant_id AND is_active = 1 AND pos_visible = 1 
      ORDER BY sort_order ASC, name_en ASC
    ");
    $stmt->execute([':tenant_id' => $tenantId]);
    $categoriesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $debug_info[] = "Found " . count($categoriesData) . " categories in database";
    
    // For each category, get its printer assignments
    foreach ($categoriesData as $cat) {
      $debug_info[] = "Processing category: " . ($cat['name_en'] ?: $cat['name_ar']);
      
      // Use English name, fallback to Arabic if English is empty
      $categoryName = !empty($cat['name_en']) ? $cat['name_en'] : $cat['name_ar'];
      
      // Add parent category prefix if this is a subcategory
      if ($cat['parent_id']) {
        $parentStmt = $pdo->prepare("SELECT name_en, name_ar FROM categories WHERE id = :parent_id");
        $parentStmt->execute([':parent_id' => $cat['parent_id']]);
        $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
        if ($parent) {
          $parentName = !empty($parent['name_en']) ? $parent['name_en'] : $parent['name_ar'];
          $categoryName = $parentName . ' → ' . $categoryName;
        }
      }
      
      $assignmentStmt = $pdo->prepare("
        SELECT printer_id 
        FROM category_printer_assignments 
        WHERE category_id = :category_id AND tenant_id = :tenant_id
      ");
      $assignmentStmt->execute([
        ':category_id' => $cat['id'],
        ':tenant_id' => $tenantId
      ]);
      $assignments = $assignmentStmt->fetchAll(PDO::FETCH_COLUMN);
      
      $categories[] = [
        'id' => (int)$cat['id'],
        'name' => $categoryName,
        'printer_assignments' => array_map('intval', $assignments)
      ];
    }
    
    $debug_info[] = "Final categories count: " . count($categories);
    
  } catch (Exception $e) {
    $debug_info[] = "Database error: " . $e->getMessage();
    error_log("Error fetching categories: " . $e->getMessage());
  }
}

// If no database connection or no categories found, use sample data
if (empty($categories)) {
  $debug_info[] = "Using sample data because categories array is empty";
  $categories = [
    ['id'=>1,'name'=>'Appetizers','printer_assignments'=>[]],
    ['id'=>2,'name'=>'Main Courses','printer_assignments'=>[]],
    ['id'=>3,'name'=>'Beverages','printer_assignments'=>[]],
    ['id'=>4,'name'=>'Desserts','printer_assignments'=>[]],
    ['id'=>5,'name'=>'Hot Drinks','printer_assignments'=>[]],
    ['id'=>6,'name'=>'Cold Drinks','printer_assignments'=>[]],
  ];
}

$printer_types = [
  'receipt' => 'Receipt Printer',
  'kitchen' => 'Kitchen Printer', 
  'bar' => 'Bar Printer',
  'display' => 'Kitchen Display',
  'label' => 'Label Printer'
];

$connection_types = [
  'ethernet' => 'Ethernet (IP)',
  'usb' => 'USB Connection',
  'bluetooth' => 'Bluetooth',
  'wifi' => 'WiFi Network'
];

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Printers &amp; Hardware · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --primary:#3b82f6; --primary-50:#eff6ff; --success:#10b981; --success-50:#ecfdf5;
  --danger:#ef4444; --warning:#f59e0b; --warning-50:#fffbeb; --info:#0ea5e9;
  --slate-50:#f8fafc; --slate-100:#f1f5f9; --slate-200:#e2e8f0;
  --slate-300:#cbd5e1; --slate-500:#64748b; --slate-600:#475569; --slate-900:#0f172a;
  --white:#ffffff; --shadow-sm:0 1px 3px 0 rgb(0 0 0 / .1);
  --shadow-md:0 4px 6px -1px rgb(0 0 0 / .1); --shadow-lg:0 10px 15px -3px rgb(0 0 0 / .1);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:linear-gradient(135deg,#fafbfc,#f1f5f9);color:var(--slate-900);line-height:1.5;font-size:14px;min-height:100vh}
.container{max-width:1200px;margin:0 auto;padding:20px}
.header{position:sticky;top:0;z-index:50;background:rgba(255,255,255,.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--slate-200);margin:0 -20px 20px;padding:16px 20px}
.header-content{display:flex;align-items:center;justify-content:space-between;gap:20px}
.header h1{font-size:20px;font-weight:700;margin:0}
.save-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 20px;background:var(--primary);color:#fff;border:none;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer;transition:all .2s}
.save-btn:hover{background:#2563eb;transform:translateY(-1px)}

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

.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin-bottom:20px}
.stat-card{background:var(--slate-50);border:1px solid var(--slate-200);border-radius:12px;padding:16px;text-align:center}
.stat-number{font-size:24px;font-weight:700;color:var(--slate-900);margin-bottom:4px}
.stat-label{font-size:12px;color:var(--slate-600);font-weight:500}
.stat-card.success{background:var(--success-50);border-color:var(--success)}.stat-card.success .stat-number{color:var(--success)}
.stat-card.danger{background:#fef2f2;border-color:var(--danger)}.stat-card.danger .stat-number{color:var(--danger)}

.form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin-bottom:16px}
.form-group{display:flex;flex-direction:column}
.form-label{font-weight:600;font-size:13px;margin-bottom:6px}
.form-input,.form-select{width:100%;padding:12px;border:2px solid var(--slate-200);border-radius:8px;font-size:13px;background:#fff;transition:all .2s;outline:none}
.form-input:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-50)}
.form-help{color:var(--slate-500);font-size:11px;margin-top:3px}

.table-container{background:#fff;border-radius:12px;overflow:hidden;border:1px solid var(--slate-200)}
.table{width:100%;border-collapse:collapse;font-size:13px}
.table thead{position:sticky;top:0;z-index:10}
.table th{background:var(--slate-100);color:var(--slate-600);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em;padding:16px 12px;border-bottom:2px solid var(--slate-200);text-align:left}
.table td{padding:12px;border-bottom:1px solid var(--slate-200);vertical-align:middle}
.table tbody tr:hover{background:var(--slate-50)}
.table tbody tr:last-child td{border-bottom:none}

.scrollable-table{max-height:350px;overflow-y:auto}
.scrollable-table::-webkit-scrollbar{width:6px}
.scrollable-table::-webkit-scrollbar-track{background:var(--slate-100);border-radius:3px}
.scrollable-table::-webkit-scrollbar-thumb{background:var(--slate-300);border-radius:3px}
.scrollable-table::-webkit-scrollbar-thumb:hover{background:var(--slate-400)}

.status-indicator{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:500}
.status-dot{width:8px;height:8px;border-radius:50%}
.status-online .status-dot{background:var(--success)}
.status-offline .status-dot{background:var(--danger)}
.status-warning .status-dot{background:var(--warning)}

.badge{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:600}
.badge-success{background:var(--success-50);color:#065f46}
.badge-warning{background:var(--warning-50);color:#92400e}
.badge-danger{background:#fef2f2;color:#dc2626}
.badge-gray{background:var(--slate-100);color:var(--slate-600)}
.badge-primary{background:var(--primary-50);color:var(--primary)}

.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:2px solid var(--slate-200);background:#fff;border-radius:6px;font-weight:600;font-size:11px;cursor:pointer;transition:all .2s;color:var(--slate-900);text-decoration:none}
.btn:hover{background:var(--slate-50);border-color:var(--slate-300)}
.btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn-primary:hover{background:#2563eb;color:#fff}
.btn-success{background:var(--success);color:#fff;border-color:var(--success)}
.btn-success:hover{background:#059669;color:#fff}
.btn-danger{background:var(--danger);color:#fff;border-color:var(--danger)}
.btn-danger:hover{background:#dc2626;color:#fff}
.btn-warning{background:var(--warning);color:#fff;border-color:var(--warning)}
.btn-warning:hover{background:#d97706;color:#fff}
.btn-sm{padding:4px 8px;font-size:11px}
.action-buttons{display:flex;gap:6px;justify-content:flex-end}

.notice{background:#fffbeb;border:1px solid #fcd34d;color:#92400e;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px}

.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.7);display:flex;align-items:center;justify-content:center;z-index:1000;padding:20px}
.modal-content{background:#fff;border-radius:16px;max-width:600px;width:100%;max-height:90vh;overflow-y:auto}
.modal-header{padding:20px;border-bottom:1px solid var(--slate-200);display:flex;align-items:center;justify-content:space-between;background:var(--slate-50)}
.modal-header h3{font-size:16px;font-weight:600;margin:0}
.modal-body{padding:20px}
.modal-footer{padding:20px;border-top:1px solid var(--slate-200);display:flex;justify-content:flex-end;gap:8px;background:var(--slate-50)}
.toast{position:fixed;top:20px;right:20px;background:var(--success);color:#fff;padding:12px 20px;border-radius:8px;font-weight:600;z-index:1001;display:flex;align-items:center;gap:8px;font-size:13px}
.toast.error{background:var(--danger)}

.category-assignments{border:1px solid var(--slate-200);border-radius:8px;padding:12px;max-height:200px;overflow-y:auto}
.category-item{display:flex;align-items:center;justify-content:space-between;padding:8px;margin-bottom:4px;border-radius:6px;background:var(--slate-50)}
.category-item:last-child{margin-bottom:0}
.printer-select{width:200px;padding:6px;border:1px solid var(--slate-200);border-radius:4px;font-size:12px}

@media (max-width:1024px){.container{padding:16px}.grid-2{grid-template-columns:1fr}.form-row{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:768px){.header-content{flex-direction:column;align-items:stretch;gap:12px}.save-btn{justify-content:center}.table-container{overflow-x:auto}.action-buttons{flex-direction:column}}
</style>
</head>
<body>

<?php
  /* Shared top navigation */
  $active = 'settings_hardware';  // This specific value for hardware settings
  $nav_candidates = [
    __DIR__ . '/../partials/admin_nav.php',          // /views/admin/partials/admin_nav.php
    dirname(__DIR__, 2) . '/partials/admin_nav.php', // /views/partials/admin_nav.php  ✅
    dirname(__DIR__, 1) . '/partials/admin_nav.php'
  ];
  $nav_included=false; $nav_used='';
  foreach ($nav_candidates as $cand) {
    if (is_file($cand)) { $nav_used=$cand; include $cand; $nav_included=true; break; }
  }
  if (!$nav_included) {
    echo '<div style="background:#0f172a;color:#fff;padding:12px 20px;font-weight:600">Smorll — Admin</div>';
  }
?>

<div class="header">
  <div class="container">
    <div class="header-content">
      <h1>Printers &amp; Hardware</h1>
      <button class="save-btn" onclick="saveAll()" id="saveButton">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px">
          <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"></path>
          <polyline points="17,21 17,13 7,13 7,21"></polyline>
          <polyline points="7,3 7,8 15,8"></polyline>
        </svg>
        Save Changes
      </button>
    </div>
  </div>
</div>

<div class="container">
  <?php if ($bootstrap_warning): ?>
    <div class="notice"><strong>Configuration notice:</strong> <?= h($bootstrap_warning) ?><?= $bootstrap_found ? ' (found: <code>'.h($bootstrap_found).'</code>)' : '' ?></div>
  <?php endif; ?>

  <!-- Hardware Overview -->
  <div class="card grid-full">
    <div class="card-body">
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-number"><?= count($printers) ?></div>
          <div class="stat-label">Total Devices</div>
        </div>
        <div class="stat-card success">
          <div class="stat-number"><?= count(array_filter($printers, function($p){ return isset($p['status']) && $p['status'] === 'online'; })) ?></div>
          <div class="stat-label">Online</div>
        </div>
        <div class="stat-card danger">
          <div class="stat-number"><?= count(array_filter($printers, function($p){ return isset($p['status']) && $p['status'] === 'offline'; })) ?></div>
          <div class="stat-label">Offline</div>
        </div>
        <div class="stat-card">
          <div class="stat-number"><?= count($branches) ?></div>
          <div class="stat-label">Branches</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Printer Management -->
  <div class="card grid-full">
    <div class="card-header card-header-flex">
      <div>
        <h2>Printer Management</h2>
        <p>Configure and monitor printing devices</p>
      </div>
      <button class="btn btn-success" onclick="openPrinterModal()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Printer
      </button>
    </div>
    <div class="card-body">
      <div class="table-container scrollable-table">
        <table class="table">
          <thead><tr><th>Device</th><th>Type</th><th>Connection</th><th>Branch</th><th>Status</th><th style="width:160px">Actions</th></tr></thead>
          <tbody id="printersTable">
            <?php foreach ($printers as $printer): ?>
              <tr data-id="<?= (int)$printer['id'] ?>">
                <td>
                  <div>
                    <div style="font-weight:600;font-size:13px;"><?= h($printer['name']) ?></div>
                    <div style="color:var(--slate-500);font-size:11px;">
                      <?= h($printer['brand']) ?><?= $printer['brand'] && $printer['model'] ? ' ' : '' ?><?= h($printer['model']) ?>
                      <?php if ($printer['station']): ?>
                        <span style="color:var(--info);">• Station: <?= h($printer['station']) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td><span class="badge badge-primary"><?= h($printer_types[$printer['type']] ?? ucfirst($printer['type'])) ?></span></td>
                <td>
                  <div style="font-size:11px;color:var(--slate-600);">
                    <?php if ($printer['connection_type'] === 'ethernet' && $printer['ip_address']): ?>
                      <div><strong>IP:</strong> <?= h($printer['ip_address']) ?></div>
                      <div><strong>Port:</strong> <?= h($printer['port']) ?></div>
                    <?php else: ?>
                      <div><?= ucfirst($printer['connection_type']) ?></div>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="color:var(--slate-600);font-size:12px;"><?= h($printer['branch_name']) ?></td>
                <td>
                  <div class="status-indicator status-<?= $printer['status'] ?>">
                    <div class="status-dot"></div>
                    <span><?= ucfirst($printer['status']) ?></span>
                  </div>
                  <div style="color:var(--slate-500);font-size:10px;margin-top:2px;">
                    <?= $printer['last_ping'] ? date('M j, g:i A', strtotime($printer['last_ping'])) : 'Never' ?>
                  </div>
                </td>
                <td><div class="action-buttons">
                  <button class="btn btn-sm btn-warning" onclick="testPrint(<?= (int)$printer['id'] ?>)">Test</button>
                  <button class="btn btn-sm" onclick="editPrinter(<?= (int)$printer['id'] ?>)">Edit</button>
                  <button class="btn btn-sm btn-danger" onclick="removePrinter(<?= (int)$printer['id'] ?>)">Delete</button>
                </div></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Category Assignments -->
  <div class="card grid-full">
    <div class="card-header card-header-flex">
      <div>
        <h2>Category Assignments</h2>
        <p>Configure which printers handle different menu categories</p>
      </div>
      <button class="btn btn-primary" onclick="refreshAssignments()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="23 4 23 10 17 10"></polyline>
          <polyline points="1 20 1 14 7 14"></polyline>
          <path d="m3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
        </svg>
        Refresh
      </button>
    </div>
    <div class="card-body">
      <div class="category-assignments" id="categoryAssignments">
        <?php foreach ($categories as $category): ?>
          <div class="category-item" data-id="<?= (int)$category['id'] ?>">
            <div>
              <strong><?= h($category['name']) ?></strong>
              <div style="font-size:11px;color:var(--slate-500);">Menu category</div>
            </div>
            <select class="printer-select" onchange="updateCategoryAssignment(<?= (int)$category['id'] ?>, this.value)">
              <option value="">No Printer Assigned</option>
              <?php foreach ($printers as $printer): ?>
                <option value="<?= (int)$printer['id'] ?>" <?= in_array($printer['id'], $category['printer_assignments']) ? 'selected' : '' ?>>
                  <?= h($printer['name']) ?> (<?= h($printer['branch_name']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
      </div>
      
      <div style="margin-top:16px;padding:12px;background:var(--slate-50);border-radius:8px;border:1px solid var(--slate-200);">
        <div style="font-weight:600;font-size:13px;margin-bottom:4px;">Assignment Rules</div>
        <div style="font-size:11px;color:var(--slate-600);line-height:1.4;">
          • Receipt items print to receipt printers automatically<br>
          • Kitchen categories should be assigned to kitchen printers<br>
          • Bar/beverage items work best with bar printers<br>
          • Unassigned categories print to default receipt printer
        </div>
      </div>
    </div>
  </div>

  <?php if ($DEBUG): ?>
    <div style="margin:20px 0;background:#fff;border:1px dashed var(--slate-300);border-radius:12px;padding:12px 16px;font-family:monospace;font-size:12px">
      <div><strong>Bootstrap:</strong> <?= $bootstrap_ok ? 'OK' : 'WARN' ?><?= $bootstrap_warning ? (' — '.h($bootstrap_warning)) : '' ?></div>
      <div><strong>Found:</strong> <code><?= h($bootstrap_found ?: '-') ?></code></div>
      <div><strong>User:</strong> <code><?= h((string)($user['id'] ?? '')) ?></code></div>
      <div><strong>Nav included:</strong> <?= $nav_included ? 'yes' : 'no' ?> · <strong>Nav used:</strong> <code><?= h($nav_used ?: '-') ?></code></div>
      <div><strong>Debug Info:</strong></div>
      <?php foreach($debug_info as $info): ?>
        <div style="margin-left:10px;">• <?= h($info) ?></div>
      <?php endforeach; ?>
      <div><strong>Printers loaded:</strong> <?= count($printers) ?></div>
      <div><strong>Categories loaded:</strong> <?= count($categories) ?></div>
    </div>
  <?php endif; ?>
</div>

<script>
var isDirty = false;
function markDirty(){ isDirty = true; }

/* ---------- Helpers ---------- */
function escapeHtml(t){ var d=document.createElement('div'); d.textContent=t||''; return d.innerHTML; }
function closeModal(){ var m=document.querySelector('.modal-overlay'); if (m) m.remove(); }
function showToast(msg, err){ var t=document.createElement('div'); t.className='toast'+(err?' error':''); t.textContent=msg; document.body.appendChild(t); setTimeout(()=>t.remove(),3000); }

/* ---------- API Helper Functions (HARDENED) ---------- */
async function apiCall(url, method = 'GET', data = null) {
  const options = {
    method: method,
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    credentials: 'same-origin'
  };
  if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
    options.body = JSON.stringify(data);
  }

  let response;
  try {
    response = await fetch(url, options);
  } catch (networkErr) {
    console.error('Network error calling', url, networkErr);
    throw new Error('Network error – check connectivity or CORS');
  }

  // If Content-Type is JSON, parse as JSON; else read as text and throw helpful error
  const ct = (response.headers.get('content-type') || '').toLowerCase();
  let payload = null;

  if (ct.includes('application/json')) {
    try {
      payload = await response.json();
    } catch (parseErr) {
      console.error('JSON parse error:', parseErr);
      throw new Error('Server returned invalid JSON');
    }
  } else {
    const text = await response.text();
    // Common scenarios: empty body, HTML login/500 page etc.
    const snippet = text ? text.substring(0, 300) : '(empty response)';
    // If not ok, include status; if ok but not JSON, surface snippet so you can see the underlying HTML/notice
    const base = `Non-JSON response (${response.status} ${response.statusText})`;
    console.warn(base, 'Body preview:', snippet);
    throw new Error(`${base}. Preview: ${snippet}`);
  }

  if (!response.ok) {
    const msg = (payload && (payload.error || payload.message)) ? (payload.error || payload.message) : `HTTP ${response.status}`;
    throw new Error(msg);
  }

  return payload;
}

/* ---------- Printer Management Functions ---------- */
function openPrinterModal(data) {
  var isEdit = !!data;
  var printer = data || { id:0, name:'', type:'receipt', brand:'', model:'', connection_type:'ethernet', ip_address:'', port:'9100', paper_size:'80mm', branch_id:1, station:'' };
  
  var modal=document.createElement('div');
  modal.className='modal-overlay';
  modal.innerHTML =
    '<div class="modal-content" style="max-width:600px;">'+
      '<div class="modal-header"><h3>'+(isEdit?'Edit Printer':'Add New Printer')+'</h3><button class="btn btn-sm" onclick="closeModal()">×</button></div>'+
      '<div class="modal-body">'+
        '<div class="form-row">'+
          '<div class="form-group"><label class="form-label">Printer Name *</label><input id="modal_printer_name" class="form-input" value="'+escapeHtml(printer.name)+'" placeholder="Kitchen Printer 1" required></div>'+
          '<div class="form-group"><label class="form-label">Type</label><select id="modal_printer_type" class="form-select">'+
            '<option value="receipt"'+(printer.type==='receipt'?' selected':'')+'>Receipt Printer</option>'+
            '<option value="kitchen"'+(printer.type==='kitchen'?' selected':'')+'>Kitchen Printer</option>'+
            '<option value="bar"'+(printer.type==='bar'?' selected':'')+'>Bar Printer</option>'+
            '<option value="display"'+(printer.type==='display'?' selected':'')+'>Kitchen Display</option>'+
            '<option value="label"'+(printer.type==='label'?' selected':'')+'>Label Printer</option>'+
          '</select></div>'+
        '</div>'+
        '<div class="form-row">'+
          '<div class="form-group"><label class="form-label">Brand</label><input id="modal_printer_brand" class="form-input" value="'+escapeHtml(printer.brand)+'" placeholder="Epson"></div>'+
          '<div class="form-group"><label class="form-label">Model</label><input id="modal_printer_model" class="form-input" value="'+escapeHtml(printer.model)+'" placeholder="TM-T20III"></div>'+
        '</div>'+
        '<div class="form-row">'+
          '<div class="form-group"><label class="form-label">Station</label><input id="modal_printer_station" class="form-input" value="'+escapeHtml(printer.station||'')+'" placeholder="Station identifier"></div>'+
          '<div class="form-group"><label class="form-label">Branch</label><select id="modal_printer_branch" class="form-select">'+
            '<?php foreach($branches as $branch) echo "<option value=\"".(int)$branch["id"]."\">".h($branch["name"])."</option>"; ?>'+
          '</select></div>'+
        '</div>'+
        '<div class="form-row">'+
          '<div class="form-group"><label class="form-label">Connection Type</label><select id="modal_connection_type" class="form-select" onchange="toggleConnectionFields()">'+
            '<option value="ethernet"'+(printer.connection_type==='ethernet'?' selected':'')+'>Ethernet (IP)</option>'+
            '<option value="usb"'+(printer.connection_type==='usb'?' selected':'')+'>USB Connection</option>'+
            '<option value="wifi"'+(printer.connection_type==='wifi'?' selected':'')+'>WiFi Network</option>'+
            '<option value="bluetooth"'+(printer.connection_type==='bluetooth'?' selected':'')+'>Bluetooth</option>'+
          '</select></div>'+
          '<div class="form-group"><label class="form-label">Paper Size</label><select id="modal_paper_size" class="form-select">'+
            '<option value="80mm"'+(printer.paper_size==='80mm'?' selected':'')+'>80mm (Standard)</option>'+
            '<option value="58mm"'+(printer.paper_size==='58mm'?' selected':'')+'>58mm (Compact)</option>'+
            '<option value=""'+(printer.paper_size===''?' selected':'')+'>Not Applicable</option>'+
          '</select></div>'+
        '</div>'+
        '<div class="form-row" id="networkFields" style="display:'+(printer.connection_type==='ethernet'||printer.connection_type==='wifi'?'grid':'none')+'">'+
          '<div class="form-group"><label class="form-label">IP Address</label><input id="modal_ip_address" class="form-input" value="'+escapeHtml(printer.ip_address)+'" placeholder="192.168.1.100"></div>'+
          '<div class="form-group"><label class="form-label">Port</label><input id="modal_port" class="form-input" value="'+escapeHtml(printer.port)+'" placeholder="9100"></div>'+
        '</div>'+
      '</div>'+
      '<div class="modal-footer"><button class="btn" onclick="closeModal()">Cancel</button>'+
      '<button class="btn btn-primary" onclick="savePrinter('+(printer.id||0)+')">'+(isEdit?'Update':'Add')+' Printer</button></div>'+
    '</div>';
  
  document.body.appendChild(modal);
  modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });
  
  // Set branch selection
  if (isEdit && printer.branch_id) {
    document.getElementById('modal_printer_branch').value = printer.branch_id;
  }
}

function toggleConnectionFields() {
  var connectionType = document.getElementById('modal_connection_type').value;
  var networkFields = document.getElementById('networkFields');
  networkFields.style.display = (connectionType === 'ethernet' || connectionType === 'wifi') ? 'grid' : 'none';
}

async function editPrinter(id) {
  try {
    const result = await apiCall(`/api/printers.php?action=get&id=${id}`);
    if (result && result.success) {
      openPrinterModal(result.printer);
    } else {
      showToast('Failed to load printer details', true);
    }
  } catch (error) {
    showToast('Error loading printer: ' + error.message, true);
  }
}

async function savePrinter(id) {
  const formData = {
    name: document.getElementById('modal_printer_name').value.trim(),
    type: document.getElementById('modal_printer_type').value,
    brand: document.getElementById('modal_printer_brand').value.trim(),
    model: document.getElementById('modal_printer_model').value.trim(),
    station: document.getElementById('modal_printer_station').value.trim(),
    connection_type: document.getElementById('modal_connection_type').value,
    branch_id: parseInt(document.getElementById('modal_printer_branch').value),
    ip_address: document.getElementById('modal_ip_address').value.trim(),
    port: document.getElementById('modal_port').value.trim(),
    paper_size: document.getElementById('modal_paper_size').value
  };
  
  if (!formData.name) {
    showToast('Please provide printer name.', true);
    return;
  }
  
  try {
    let result;
    if (id > 0) {
      // Update existing printer
      formData.id = id;
      result = await apiCall('/api/printers.php', 'POST', {
        action: 'update',
        ...formData
      });
    } else {
      // Create new printer
      result = await apiCall('/api/printers.php', 'POST', {
        action: 'create',
        ...formData
      });
    }
    
    if (result && result.success) {
      closeModal();
      showToast(result.message);
      // Reload the printer list
      await loadPrinters();
      markDirty();
    } else {
      showToast('Failed to save printer: ' + (result && (result.error || result.message) || 'Unknown error'), true);
    }
  } catch (error) {
    showToast('Error saving printer: ' + error.message, true);
  }
}

async function removePrinter(id) {
  if (!confirm('Remove this printer? This will affect printing for assigned categories.')) {
    return;
  }
  
  try {
    const result = await apiCall('/api/printers.php', 'POST', {
      action: 'delete',
      id: id
    });
    
    if (result && result.success) {
      showToast(result.message);
      // Remove the row from table
      const tr = document.querySelector(`#printersTable tr[data-id="${id}"]`);
      if (tr) tr.remove();
      markDirty();
      // Update stats
      updateStatsFromTable();
    } else {
      showToast('Failed to remove printer: ' + (result && (result.error || result.message) || 'Unknown error'), true);
    }
  } catch (error) {
    showToast('Error removing printer: ' + error.message, true);
  }
}

async function testPrint(id) {
  try {
    const result = await apiCall('/api/printers.php', 'POST', {
      action: 'test_print',
      id: id
    });
    
    if (result && result.success) {
      showToast(`Test print sent to ${result.printer_name}`);
      // Update printer status in the UI
      updatePrinterStatus(id, 'online');
    } else {
      showToast('Test print failed: ' + (result && (result.error || result.message) || 'Unknown error'), true);
    }
  } catch (error) {
    showToast('Error sending test print: ' + error.message, true);
  }
}

async function loadPrinters() {
  try {
    const result = await apiCall('/api/printers.php?action=list');
    
    if (result && result.success) {
      const tbody = document.getElementById('printersTable');
      tbody.innerHTML = ''; // Clear existing rows
      
      const typeLabels = {
        receipt: 'Receipt Printer',
        kitchen: 'Kitchen Printer',
        bar: 'Bar Printer',
        display: 'Kitchen Display',
        label: 'Label Printer'
      };
      
      (result.printers || []).forEach(printer => {
        const tr = document.createElement('tr');
        tr.setAttribute('data-id', printer.id);
        
        const connectionDisplay = printer.connection_type === 'ethernet' && printer.ip_address ?
          `<div><strong>IP:</strong> ${escapeHtml(printer.ip_address)}</div><div><strong>Port:</strong> ${escapeHtml(printer.port)}</div>` :
          `<div>${printer.connection_type.charAt(0).toUpperCase() + printer.connection_type.slice(1)}</div>`;
        
        const statusClass = printer.status === 'online' ? 'status-online' : 
                           printer.status === 'error' ? 'status-warning' : 'status-offline';
        
        const lastPing = printer.last_ping ? 
          new Date(printer.last_ping).toLocaleDateString('en-US', {
            month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
          }) : 'Never';
        
        var brandModel = '';
        if (printer.brand || printer.model) {
          brandModel = escapeHtml((printer.brand || '') + ' ' + (printer.model || '')).trim();
        }
        if (printer.station) {
          brandModel += (brandModel ? '<br>' : '') + '<span style="color:var(--info);">• Station: ' + escapeHtml(printer.station) + '</span>';
        }
        
        tr.innerHTML = `
          <td>
            <div>
              <div style="font-weight:600;font-size:13px;">${escapeHtml(printer.name)}</div>
              <div style="color:var(--slate-500);font-size:11px;">${brandModel}</div>
            </div>
          </td>
          <td><span class="badge badge-primary">${typeLabels[printer.type] || printer.type}</span></td>
          <td><div style="font-size:11px;color:var(--slate-600);">${connectionDisplay}</div></td>
          <td style="color:var(--slate-600);font-size:12px;">${escapeHtml(printer.branch_name || 'No Branch')}</td>
          <td>
            <div class="status-indicator ${statusClass}">
              <div class="status-dot"></div>
              <span>${printer.status.charAt(0).toUpperCase() + printer.status.slice(1)}</span>
            </div>
            <div style="color:var(--slate-500);font-size:10px;margin-top:2px;">${lastPing}</div>
          </td>
          <td>
            <div class="action-buttons">
              <button class="btn btn-sm btn-warning" onclick="testPrint(${printer.id})">Test</button>
              <button class="btn btn-sm" onclick="editPrinter(${printer.id})">Edit</button>
              <button class="btn btn-sm btn-danger" onclick="removePrinter(${printer.id})">Delete</button>
            </div>
          </td>
        `;
        
        tbody.appendChild(tr);
      });
      
      // Update stats
      updatePrinterStats(result.printers || []);
      
    } else {
      showToast('Failed to load printers: ' + (result && (result.error || result.message) || 'Unknown error'), true);
    }
  } catch (error) {
    console.error('Error loading printers:', error);
    showToast('Error loading printers: ' + error.message, true);
  }
}

function updatePrinterStats(printers) {
  const total = printers.length;
  const online = printers.filter(p => p.status === 'online').length;
  const offline = printers.filter(p => p.status === 'offline').length;
  
  // Update stat cards
  const statCards = document.querySelectorAll('.stat-card');
  if (statCards[0]) statCards[0].querySelector('.stat-number').textContent = total;
  if (statCards[1]) statCards[1].querySelector('.stat-number').textContent = online;
  if (statCards[2]) statCards[2].querySelector('.stat-number').textContent = offline;
}

function updateStatsFromTable() {
  const rows = document.querySelectorAll('#printersTable tr');
  const total = rows.length;
  let online = 0, offline = 0;
  
  rows.forEach(function(row){
    const statusEl = row.querySelector('.status-indicator');
    if (statusEl) {
      if (statusEl.classList.contains('status-online')) online++;
      else if (statusEl.classList.contains('status-offline')) offline++;
    }
  });
  
  // Update stat cards
  const statCards = document.querySelectorAll('.stat-card');
  if (statCards[0]) statCards[0].querySelector('.stat-number').textContent = total;
  if (statCards[1]) statCards[1].querySelector('.stat-number').textContent = online;
  if (statCards[2]) statCards[2].querySelector('.stat-number').textContent = offline;
}

function updatePrinterStatus(printerId, status) {
  const tr = document.querySelector(`#printersTable tr[data-id="${printerId}"]`);
  if (tr) {
    const statusIndicator = tr.querySelector('.status-indicator');
    const statusSpan = statusIndicator.querySelector('span');
    
    // Remove old status classes
    statusIndicator.classList.remove('status-online', 'status-offline', 'status-warning');
    
    // Add new status
    const statusClass = status === 'online' ? 'status-online' : 
                       status === 'error' ? 'status-warning' : 'status-offline';
    statusIndicator.classList.add(statusClass);
    statusSpan.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    
    // Update timestamp
    const timeDiv = tr.querySelector('[style*="font-size:10px"]');
    if (timeDiv) {
      timeDiv.textContent = 'Just now';
    }
    
    // Update stats
    updateStatsFromTable();
  }
}

/* ---------- Auto-refresh printer status ---------- */
async function pingAllPrinters() {
  try {
    const result = await apiCall('/api/printers.php', 'POST', {
      action: 'ping_all'
    });
    
    if (result && result.success && result.results) {
      result.results.forEach(function(printer){
        updatePrinterStatus(printer.id, printer.status);
      });
    }
  } catch (error) {
    console.error('Error pinging printers:', error);
  }
}

/* ---------- Category Assignment Management ---------- */
function updateCategoryAssignment(categoryId, printerId) {
  // Send AJAX request to update database
  fetch('/api/category-printer-assignments.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    credentials: 'same-origin',
    body: JSON.stringify({
      action: 'update',
      category_id: categoryId,
      printer_id: printerId || null
    })
  })
  .then(async (response) => {
    const ct = (response.headers.get('content-type') || '').toLowerCase();
    let data = null;
    if (ct.includes('application/json')) data = await response.json(); else throw new Error('Non-JSON response when updating assignment');
    if (response.ok && data.success) {
      markDirty();
      showToast('Category assignment updated');
    } else {
      throw new Error(data && (data.error || data.message) || `HTTP ${response.status}`);
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showToast('Network/API error updating assignment: ' + error.message, true);
  });
}

/* ---------- Enhanced refresh function ---------- */
async function refreshAssignments() {
  showToast('Refreshing printer status...');
  await pingAllPrinters();
  await loadPrinters();
  showToast('Printer status refreshed');
}

/* ---------- Save all (stub) ---------- */
function saveAll(){
  var btn = document.getElementById('saveButton');
  btn.disabled = true;
  btn.textContent = 'Saving...';
  
  setTimeout(function(){
    btn.disabled = false;
    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"></path><polyline points="17,21 17,13 7,13 7,21"></polyline><polyline points="7,3 7,8 15,8"></polyline></svg> Save Changes';
    isDirty = false;
    showToast('Printer settings saved successfully!');
  }, 1200);
}

/* ---------- Initialize when page loads ---------- */
document.addEventListener('DOMContentLoaded', function() {
  // Load printers on page load if we have an API
  if (window.fetch) {
    loadPrinters();
  }
  
  // Auto-refresh printer status every 5 minutes
  setInterval(pingAllPrinters, 5 * 60 * 1000);
  
  // Mark dirty on inputs
  document.querySelectorAll('input, select').forEach(function(el) {
    el.addEventListener('input', markDirty);
    el.addEventListener('change', markDirty);
  });
});

/* Leave warning */
window.addEventListener('beforeunload', function(e){
  if(!isDirty) return;
  e.preventDefault();
  e.returnValue = 'You have unsaved changes';
});
</script>
</body>
</html>