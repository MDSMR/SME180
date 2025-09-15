<?php
// public_html/views/admin/settings/users.php
// Setup → Users & Roles (User Management • Roles & Permissions)
declare(strict_types=1);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { 
    @ini_set('display_errors','1'); 
    @ini_set('display_startup_errors','1'); 
    error_reporting(E_ALL); 
}

/* ---------- Bootstrap /config/db.php (robust search) ---------- */
$bootstrap_warning=''; 
$bootstrap_ok=false; 
$bootstrap_found='';
$bootstrap_tried=[];

$tryList = [
  __DIR__ . '/../../../config/db.php',
  __DIR__ . '/../../config/db.php',
];
foreach ($tryList as $p) { 
    if (!in_array($p,$bootstrap_tried,true)) $bootstrap_tried[]=$p; 
}

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
      require_once $cand;
      if (function_exists('use_backend_session')) { 
          $bootstrap_ok=true; 
          $bootstrap_found=$cand; 
          break; 
      }
      else { 
          $bootstrap_warning = 'Missing function use_backend_session() in config/db.php'; 
      }
    } catch (Throwable $e) { 
        $bootstrap_warning = 'Bootstrap error: '.$e->getMessage(); 
    }
  }
}
if (!$bootstrap_ok && $bootstrap_warning==='') { 
    $bootstrap_warning = 'Configuration file not found: /config/db.php'; 
}

/* ---------- Session & Auth ---------- */
if ($bootstrap_ok) {
  try { 
      use_backend_session(); 
  } catch(Throwable $e) { 
      $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: '.$e->getMessage()); 
  }
}
$user = $_SESSION['user'] ?? null;
if (!$user) { 
    header('Location: /views/auth/login.php'); 
    exit; 
}

/* ---------- Database Connection ---------- */
// The db.php file has already been included via require_once at the top
// It provides the db() function to get a PDO connection
$pdo = null;
$tenant_id = $_SESSION['tenant_id'] ?? 1;

// Try to get the PDO connection using the db() function from db.php
if (function_exists('db')) {
    try {
        $pdo = db();
    } catch (Exception $e) {
        $db_error = "Database connection failed: " . $e->getMessage();
    }
}

// If db() function doesn't exist, check for other common patterns
if (!$pdo) {
    // Check for common PDO variable names
    if (isset($GLOBALS['pdo'])) {
        $pdo = $GLOBALS['pdo'];
    } elseif (isset($GLOBALS['db'])) {
        $pdo = $GLOBALS['db'];
    } elseif (isset($db)) {
        $pdo = $db;
    } elseif (isset($conn)) {
        $pdo = $conn;
    }
}

/* ---------- Helpers ---------- */
function h($s){ 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

// Helper to determine if a role is POS-based
function isPosRole($role_key) {
    return strpos($role_key, 'pos_') === 0;
}

// Helper to determine if role should have admin access
function hasAdminAccess($role_key) {
    return in_array($role_key, ['admin', 'manager']);
}

/* ---------- Load Data from Database ---------- */
$db_error = '';
$branches = [];
$users = [];
$roles = [];

if ($pdo) {
    // Load branches
    try {
        $stmt = $pdo->prepare("SELECT id, name, address, is_active FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$tenant_id]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $db_error .= "Error loading branches: " . $e->getMessage() . "<br>";
    }
    
    // Load users with their roles
    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.name,
                u.username,
                u.email,
                u.role_key,
                r.name as role_name,
                CASE WHEN u.password_hash IS NOT NULL AND u.password_hash != '' THEN 1 ELSE 0 END as has_password,
                CASE WHEN u.pass_code IS NOT NULL AND u.pass_code != '' THEN 1 ELSE 0 END as has_pin,
                CASE WHEN u.disabled_at IS NULL THEN 'active' ELSE 'inactive' END as status,
                u.created_at,
                u.updated_at as last_login,
                COALESCE(
                    (SELECT ub.branch_id FROM user_branches ub WHERE ub.user_id = u.id LIMIT 1),
                    1
                ) as branch_id
            FROM users u
            LEFT JOIN roles r ON u.role_key = r.role_key
            WHERE u.tenant_id = ?
            ORDER BY u.name
        ");
        $stmt->execute([$tenant_id]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $db_error .= "Error loading users: " . $e->getMessage() . "<br>";
    }
    
    // Load roles with user counts
    try {
        $stmt = $pdo->prepare("
            SELECT 
                r.id,
                r.role_key as 'key',
                r.name,
                (SELECT COUNT(*) FROM users u WHERE u.role_key = r.role_key AND u.tenant_id = ?) as users_count
            FROM roles r
            ORDER BY r.id
        ");
        $stmt->execute([$tenant_id]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add authentication requirements and descriptions
        foreach($roles as &$role) {
            $role['requires_password'] = in_array($role['key'], ['admin', 'manager']);
            $role['requires_pin'] = true; // All roles need PIN for POS
            
            // Add descriptions
            switch($role['key']) {
                case 'admin':
                    $role['description'] = 'Full system access - can access both admin panel and POS';
                    break;
                case 'manager':
                    $role['description'] = 'Management access - can access both admin panel and POS';
                    break;
                case 'pos_manager':
                    $role['description'] = 'POS management for assigned branches';
                    break;
                case 'pos_headwaiter':
                    $role['description'] = 'Head waiter operations for single branch';
                    break;
                case 'pos_waiter':
                    $role['description'] = 'Waiter operations for single branch';
                    break;
                case 'pos_cashier':
                    $role['description'] = 'Cashier operations for single branch';
                    break;
                default:
                    $role['description'] = $role['name'];
            }
        }
    } catch (Exception $e) {
        $db_error .= "Error loading roles: " . $e->getMessage() . "<br>";
    }
} else {
    $db_error = "Database connection not available. Please check configuration.";
}

// Show error if no data loaded
if (empty($branches) && empty($users) && empty($roles)) {
    if (!$db_error) {
        $db_error = "No data available. Database may be empty or not properly connected.";
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Users &amp; Roles · Smorll POS</title>
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
.stat-card.primary{background:var(--primary-50);border-color:var(--primary)}.stat-card.primary .stat-number{color:var(--primary)}
.stat-card.success{background:var(--success-50);border-color:var(--success)}.stat-card.success .stat-number{color:var(--success)}
.stat-card.danger{background:#fef2f2;border-color:var(--danger)}.stat-card.danger .stat-number{color:var(--danger)}
.stat-card.info{background:#f0f9ff;border-color:var(--info)}.stat-card.info .stat-number{color:var(--info)}

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

.scrollable-table{max-height:400px;overflow-y:auto}
.scrollable-table::-webkit-scrollbar{width:6px}
.scrollable-table::-webkit-scrollbar-track{background:var(--slate-100);border-radius:3px}
.scrollable-table::-webkit-scrollbar-thumb{background:var(--slate-300);border-radius:3px}
.scrollable-table::-webkit-scrollbar-thumb:hover{background:var(--slate-400)}

.user-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--info));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:12px;margin-right:8px}
.user-avatar.pos{background:linear-gradient(135deg,var(--success),#10b981)}

.badge{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:600}
.badge-success{background:var(--success-50);color:#065f46}
.badge-warning{background:var(--warning-50);color:#92400e}
.badge-danger{background:#fef2f2;color:#dc2626}
.badge-gray{background:var(--slate-100);color:var(--slate-600)}
.badge-info{background:#f0f9ff;color:#0369a1}
.badge-purple{background:#f3f4ff;color:#5b21b6}

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
.modal-content{background:#fff;border-radius:16px;max-width:600px;width:100%;max-height:90vh;overflow-y:auto}
.modal-header{padding:20px;border-bottom:1px solid var(--slate-200);display:flex;align-items:center;justify-content:space-between;background:var(--slate-50)}
.modal-header h3{font-size:16px;font-weight:600;margin:0}
.modal-body{padding:20px}
.modal-footer{padding:20px;border-top:1px solid var(--slate-200);display:flex;justify-content:flex-end;gap:8px;background:var(--slate-50)}
.toast{position:fixed;top:20px;right:20px;background:var(--success);color:#fff;padding:12px 20px;border-radius:8px;font-weight:600;z-index:1001;display:flex;align-items:center;gap:8px;font-size:13px}
.toast.error{background:var(--danger)}

.pin-input{letter-spacing:8px;font-size:16px;font-weight:600;text-align:center}
.auth-info{background:var(--slate-50);border:1px solid var(--slate-200);border-radius:8px;padding:12px;margin-bottom:16px;font-size:12px}
.auth-info strong{color:var(--slate-900)}

.search-box{position:relative;margin-bottom:20px}
.search-input{width:100%;padding:12px 16px 12px 44px;border:2px solid var(--slate-200);border-radius:8px;font-size:14px;background:#fff}
.search-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--slate-400);width:18px;height:18px}

@media (max-width:1024px){.container{padding:16px}.grid-2{grid-template-columns:1fr}.form-row{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:768px){.header-content{flex-direction:column;align-items:stretch;gap:12px}.save-btn{justify-content:center}.table-container{overflow-x:auto}.action-buttons{flex-direction:column}}
</style>
</head>
<body>

<?php
  /* Shared top navigation */
  $active = 'settings_users';  // This specific value for users settings
  $nav_candidates = [
    __DIR__ . '/../partials/admin_nav.php',
    dirname(__DIR__, 2) . '/partials/admin_nav.php',
    dirname(__DIR__, 1) . '/partials/admin_nav.php'
  ];
  $nav_included=false; $nav_used='';
  foreach ($nav_candidates as $cand) {
    if (is_file($cand)) { $nav_used=$cand; include $cand; $nav_included=true; break; }
  }
  if (!$nav_included) {
    echo '<div style="background:#0f172a;color:#fff;padding:12px 20px;font-weight:600">Smorll – Admin</div>';
  }
?>

<div class="header">
  <div class="container">
    <div class="header-content">
      <h1>Users &amp; Roles</h1>
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
  
  <?php if ($db_error): ?>
    <div class="notice" style="background:#fef2f2;border-color:#ef4444;color:#dc2626;">
      <strong>Database Error:</strong> <?= $db_error ?>
    </div>
  <?php endif; ?>

  <?php if (empty($users) && empty($branches) && empty($roles)): ?>
    <div class="notice" style="background:#f0f9ff;border-color:#0ea5e9;color:#0369a1;">
      <strong>No Data:</strong> No users, branches, or roles found in the database. 
      <?php if (!$pdo): ?>
        Database connection is not available. Please check your configuration.
      <?php else: ?>
        Please ensure your database is properly set up with initial data.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Overview Stats -->
  <div class="card grid-full">
    <div class="card-body">
      <div class="stats-grid">
        <div class="stat-card primary">
          <div class="stat-number"><?= count($users) ?></div>
          <div class="stat-label">Total Users</div>
        </div>
        <div class="stat-card success">
          <div class="stat-number"><?= count(array_filter($users, fn($u) => $u['status'] === 'active')) ?></div>
          <div class="stat-label">Active Users</div>
        </div>
        <div class="stat-card info">
          <div class="stat-number"><?= count(array_filter($users, fn($u) => isPosRole($u['role_key']))) ?></div>
          <div class="stat-label">POS Only</div>
        </div>
        <div class="stat-card">
          <div class="stat-number"><?= count(array_filter($users, fn($u) => hasAdminAccess($u['role_key']))) ?></div>
          <div class="stat-label">Admin Access</div>
        </div>
      </div>
    </div>
  </div>

  <!-- User Management -->
  <div class="card grid-full">
    <div class="card-header card-header-flex">
      <div>
        <h2>User Management</h2>
        <p>Manage user accounts and access credentials</p>
      </div>
      <button class="btn btn-success" onclick="openUserModal()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add User
      </button>
    </div>
    <div class="card-body">
      <!-- Search and Filter -->
      <div class="form-row" style="margin-bottom:20px">
        <div class="form-group">
          <label class="form-label">Search Users</label>
          <div class="search-box">
            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <circle cx="11" cy="11" r="8"></circle>
              <path d="m21 21-4.35-4.35"></path>
            </svg>
            <input type="text" class="search-input" placeholder="Search by name, username, email, or role..." id="userSearch" oninput="filterUsers()">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Filter by Branch</label>
          <select id="branchFilter" class="form-select" onchange="filterUsers()">
            <option value="">All Branches</option>
            <?php foreach($branches as $branch): ?>
              <option value="<?= (int)$branch['id'] ?>"><?= h($branch['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="table-container scrollable-table">
        <table class="table">
          <thead>
            <tr>
              <th>User</th>
              <th>Role</th>
              <th>Authentication</th>
              <th>Branch</th>
              <th>Status</th>
              <th>Last Login</th>
              <th style="width:100px">Actions</th>
            </tr>
          </thead>
          <tbody id="usersTable">
            <?php foreach ($users as $user): 
              $hasAdmin = hasAdminAccess($user['role_key']);
              $branch = array_filter($branches, fn($b) => $b['id'] === ($user['branch_id'] ?? 1));
              $branchName = $branch ? array_values($branch)[0]['name'] : 'Unassigned';
            ?>
              <tr data-id="<?= (int)$user['id'] ?>" 
                  data-search="<?= h(strtolower($user['name'] . ' ' . $user['username'] . ' ' . ($user['email'] ?? '') . ' ' . $user['role_name'])) ?>" 
                  data-branch="<?= (int)($user['branch_id'] ?? 0) ?>">
                <td>
                  <div style="display:flex;align-items:center;">
                    <div class="user-avatar <?= isPosRole($user['role_key']) ? 'pos' : '' ?>"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
                    <div>
                      <div style="font-weight:600;font-size:13px;"><?= h($user['name']) ?></div>
                      <div style="color:var(--slate-500);font-size:11px;">
                        @<?= h($user['username']) ?>
                        <?php if ($user['email']): ?> · <?= h($user['email']) ?><?php endif; ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td><span style="font-weight:500;"><?= h($user['role_name']) ?></span></td>
                <td>
                  <div style="display:flex;gap:4px;">
                    <?php if ($user['has_password']): ?>
                      <span class="badge badge-purple" title="Admin panel access">Admin</span>
                    <?php endif; ?>
                    <?php if ($user['has_pin']): ?>
                      <span class="badge badge-success" title="POS terminal access">POS</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="font-size:12px;"><?= h($branchName) ?></td>
                <td>
                  <?php
                  $statusMap = ['active'=>'success','inactive'=>'gray','suspended'=>'danger'];
                  $statusClass = $statusMap[$user['status']] ?? 'gray';
                  echo '<span class="badge badge-'.$statusClass.'">'.ucfirst($user['status']).'</span>';
                  ?>
                </td>
                <td style="color:var(--slate-600);font-size:12px;"><?= date('M j, g:i A', strtotime($user['last_login'])) ?></td>
                <td>
                  <div class="action-buttons">
                    <button class="btn btn-sm" onclick="editUser(<?= (int)$user['id'] ?>)">Edit</button>
                    <button class="btn btn-sm btn-danger" onclick="toggleUserStatus(<?= (int)$user['id'] ?>)">
                      <?= $user['status']==='active'?'Disable':'Enable' ?>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Roles Overview -->
  <div class="card grid-full">
    <div class="card-header">
      <h2>System Roles</h2>
      <p>Available roles and their authentication requirements</p>
    </div>
    <div class="card-body">
      <div class="table-container">
        <table class="table">
          <thead>
            <tr>
              <th>Role</th>
              <th>Description</th>
              <th>Authentication Required</th>
              <th>Active Users</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($roles as $role): ?>
              <tr>
                <td>
                  <div style="font-weight:600;font-size:13px;"><?= h($role['name']) ?></div>
                  <div style="color:var(--slate-500);font-size:11px;">Key: <?= h($role['key']) ?></div>
                </td>
                <td style="font-size:12px;color:var(--slate-600);"><?= h($role['description']) ?></td>
                <td>
                  <div style="display:flex;gap:4px;">
                    <?php if ($role['requires_password']): ?>
                      <span class="badge badge-purple">Admin Panel</span>
                    <?php endif; ?>
                    <?php if ($role['requires_pin']): ?>
                      <span class="badge badge-success">POS Terminal</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td><span class="badge badge-gray"><?= (int)$role['users_count'] ?> users</span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($DEBUG): ?>
    <div style="margin:20px 0;background:#fff;border:1px dashed var(--slate-300);border-radius:12px;padding:12px 16px;font-family:monospace;font-size:12px">
      <div><strong>Bootstrap:</strong> <?= $bootstrap_ok ? 'OK' : 'WARN' ?><?= $bootstrap_warning ? (' – '.h($bootstrap_warning)) : '' ?></div>
      <div><strong>Found:</strong> <code><?= h($bootstrap_found ?: '-') ?></code></div>
      <div><strong>User:</strong> <code><?= h((string)($user['id'] ?? '')) ?></code></div>
      <div><strong>Nav included:</strong> <?= $nav_included ? 'yes' : 'no' ?> · <strong>Nav used:</strong> <code><?= h($nav_used ?: '-') ?></code></div>
      <div style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--slate-300);">
        <div><strong>PDO Connection:</strong> <?= $pdo ? 'Connected' : 'Not Connected' ?></div>
        <div><strong>Tenant ID:</strong> <?= $tenant_id ?></div>
        <div><strong>DB Constants:</strong> 
          DB_HOST=<?= defined('DB_HOST') ? 'defined' : 'not defined' ?>, 
          DB_NAME=<?= defined('DB_NAME') ? 'defined' : 'not defined' ?>, 
          DB_USER=<?= defined('DB_USER') ? 'defined' : 'not defined' ?>,
          DB_PASSWORD=<?= defined('DB_PASSWORD') ? 'defined' : 'not defined' ?>,
          DB_PASS=<?= defined('DB_PASS') ? 'defined' : 'not defined' ?>
        </div>
        <div><strong>Branches loaded:</strong> <?= count($branches) ?></div>
        <div><strong>Users loaded:</strong> <?= count($users) ?></div>
        <div><strong>Roles loaded:</strong> <?= count($roles) ?></div>
        <?php if ($db_error): ?>
          <div style="color:red;"><strong>DB Error:</strong> <?= h($db_error) ?></div>
        <?php endif; ?>
        <?php 
        // Check for global variables
        $globals_check = [];
        if (isset($GLOBALS['pdo'])) $globals_check[] = '$GLOBALS[pdo]';
        if (isset($GLOBALS['db'])) $globals_check[] = '$GLOBALS[db]';
        if (isset($GLOBALS['conn'])) $globals_check[] = '$GLOBALS[conn]';
        if (isset($GLOBALS['connection'])) $globals_check[] = '$GLOBALS[connection]';
        if (isset($GLOBALS['dbh'])) $globals_check[] = '$GLOBALS[dbh]';
        ?>
        <div><strong>Global DB vars found:</strong> <?= empty($globals_check) ? 'none' : implode(', ', $globals_check) ?></div>
        <div><strong>Functions available:</strong> 
          get_db_connection=<?= function_exists('get_db_connection') ? 'yes' : 'no' ?>,
          use_backend_session=<?= function_exists('use_backend_session') ? 'yes' : 'no' ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
var isDirty = false;
function markDirty(){ isDirty = true; }

/* ---------- Available Data ---------- */
var availableRoles = <?= json_encode($roles) ?>;
var availableBranches = <?= json_encode($branches) ?>;

/* ---------- Helpers ---------- */
function escapeHtml(t){ var d=document.createElement('div'); d.textContent=t||''; return d.innerHTML; }
function closeModal(){ var m=document.querySelector('.modal-overlay'); if (m) m.remove(); }
function showToast(msg, err){ var t=document.createElement('div'); t.className='toast'+(err?' error':''); t.textContent=msg; document.body.appendChild(t); setTimeout(()=>t.remove(),3000); }

/* ---------- Search functionality ---------- */
function filterUsers() {
  const search = document.getElementById('userSearch').value.toLowerCase();
  const branchFilter = document.getElementById('branchFilter').value;
  const rows = document.querySelectorAll('#usersTable tr');
  
  rows.forEach(row => {
    const searchData = row.getAttribute('data-search') || '';
    const userBranch = row.getAttribute('data-branch') || '';
    
    const matchesSearch = searchData.includes(search);
    const matchesBranch = !branchFilter || userBranch === branchFilter;
    
    row.style.display = (matchesSearch && matchesBranch) ? '' : 'none';
  });
}

/* ---------- User Management ---------- */
function openUserModal(data) {
  var isEdit = !!data;
  var user = data || { 
    id:0, 
    name:'', 
    username:'', 
    email:'', 
    role_key:'pos_waiter',
    status:'active', 
    branch_id:1
  };
  
  var modal=document.createElement('div');
  modal.className='modal-overlay';
  
  // Branch options
  var branchOptions = '';
  availableBranches.forEach(function(branch) {
    var selected = (user.branch_id === branch.id) ? ' selected' : '';
    branchOptions += '<option value="'+branch.id+'"'+selected+'>'+escapeHtml(branch.name)+'</option>';
  });
  
  // Role options
  var roleOptions = '';
  availableRoles.forEach(function(role) {
    var selected = (user.role_key === role.key) ? ' selected' : '';
    roleOptions += '<option value="'+role.key+'"'+selected+'>'+escapeHtml(role.name)+'</option>';
  });
  
  modal.innerHTML =
    '<div class="modal-content" style="max-width:600px;">'+
      '<div class="modal-header"><h3>'+(isEdit?'Edit User':'Add New User')+'</h3><button class="btn btn-sm" onclick="closeModal()">×</button></div>'+
      '<div class="modal-body">'+
        '<div class="form-row">'+
          '<div class="form-group"><label class="form-label">Full Name *</label>'+
            '<input id="modal_user_name" class="form-input" value="'+escapeHtml(user.name)+'" required></div>'+
          '<div class="form-group"><label class="form-label">Username *</label>'+
            '<input id="modal_user_username" class="form-input" value="'+escapeHtml(user.username)+'" required>'+
            '<div class="form-help">Used for both admin and POS login</div></div>'+
        '</div>'+
        '<div class="form-row">'+
          '<div class="form-group"><label class="form-label">Role *</label>'+
            '<select id="modal_user_role" class="form-select" onchange="onRoleChange()">'+roleOptions+'</select></div>'+
          '<div class="form-group"><label class="form-label">Branch *</label>'+
            '<select id="modal_branch" class="form-select">'+branchOptions+'</select></div>'+
        '</div>'+
        '<div class="form-row">'+
          '<div class="form-group"><label class="form-label">Email Address</label>'+
            '<input id="modal_user_email" class="form-input" type="email" value="'+escapeHtml(user.email||'')+'">'+
            '<div class="form-help">Required for admin/manager roles</div></div>'+
          '<div class="form-group"><label class="form-label">Status</label>'+
            '<select id="modal_user_status" class="form-select">'+
            '<option value="active"'+(user.status==='active'?' selected':'')+'>Active</option>'+
            '<option value="inactive"'+(user.status==='inactive'?' selected':'')+'>Inactive</option>'+
            '<option value="suspended"'+(user.status==='suspended'?' selected':'')+'>Suspended</option>'+
          '</select></div>'+
        '</div>'+
        '<div class="auth-info" id="authInfo"></div>'+
        (!isEdit ? 
          '<div id="authFields">'+
            '<div class="form-row" id="passwordRow" style="display:none;">'+
              '<div class="form-group"><label class="form-label">Admin Password *</label>'+
                '<input id="modal_user_password" class="form-input" type="password">'+
                '<div class="form-help">Minimum 8 characters for admin panel access</div></div>'+
            '</div>'+
            '<div class="form-row" id="pinRow" style="display:none;">'+
              '<div class="form-group"><label class="form-label">POS PIN Code *</label>'+
                '<input id="modal_user_pin" class="form-input pin-input" type="text" maxlength="6" pattern="[0-9]*" placeholder="0000">'+
                '<div class="form-help">4-6 digit PIN for POS terminal access</div></div>'+
            '</div>'+
          '</div>'
        : 
          '<div style="margin-top:16px;">'+
            '<button class="btn" onclick="resetPassword('+user.id+')">Reset Admin Password</button> '+
            '<button class="btn" onclick="resetPin('+user.id+')">Reset POS PIN</button>'+
          '</div>')+
      '</div>'+
      '<div class="modal-footer"><button class="btn" onclick="closeModal()">Cancel</button>'+
      '<button class="btn btn-primary" onclick="saveUser('+(user.id||0)+')">'+(isEdit?'Update':'Create')+' User</button></div>'+
    '</div>';
  document.body.appendChild(modal);
  modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });
  
  // Initialize role-based visibility
  if (!isEdit) onRoleChange();
}

function onRoleChange() {
  var roleKey = document.getElementById('modal_user_role').value;
  var selectedRole = availableRoles.find(r => r.key === roleKey);
  
  if (!selectedRole) return;
  
  // Update auth info
  var authInfo = document.getElementById('authInfo');
  if (authInfo) {
    var info = '<strong>Authentication Requirements:</strong> ';
    var auths = [];
    if (selectedRole.requires_password) auths.push('Admin Panel Password');
    if (selectedRole.requires_pin) auths.push('POS Terminal PIN');
    info += auths.join(' + ');
    authInfo.innerHTML = info;
  }
  
  // Toggle auth fields visibility
  var passwordRow = document.getElementById('passwordRow');
  var pinRow = document.getElementById('pinRow');
  
  if (passwordRow) passwordRow.style.display = selectedRole.requires_password ? 'grid' : 'none';
  if (pinRow) pinRow.style.display = selectedRole.requires_pin ? 'grid' : 'none';
}

function editUser(id) {
  // Find user from PHP data
  var user = null;
  var tbody = document.getElementById('usersTable');
  var tr = tbody.querySelector('tr[data-id="'+id+'"]');
  
  if (tr) {
    var cells = tr.children;
    var nameDiv = cells[0].querySelector('div:nth-child(2) div:first-child');
    var usernameDiv = cells[0].querySelector('div:nth-child(2) div:last-child');
    var roleSpan = cells[1].querySelector('span');
    var statusBadge = cells[4].querySelector('.badge');
    var branchId = parseInt(tr.getAttribute('data-branch') || '1');
    
    // Extract data from row
    var name = nameDiv ? nameDiv.textContent.trim() : '';
    var usernameEmail = usernameDiv ? usernameDiv.textContent.trim() : '';
    
    // Parse username and email
    var parts = usernameEmail.split(' · ');
    var username = parts[0] ? parts[0].replace('@', '') : '';
    var email = parts[1] || '';
    
    // Find role key from available roles
    var roleName = roleSpan ? roleSpan.textContent.trim() : '';
    var roleData = availableRoles.find(r => r.name === roleName);
    var roleKey = roleData ? roleData.key : 'pos_waiter';
    
    user = {
      id: id,
      name: name,
      username: username,
      email: email,
      role_key: roleKey,
      status: statusBadge ? statusBadge.textContent.toLowerCase() : 'active',
      branch_id: branchId
    };
    
    openUserModal(user);
  }
}

function saveUser(id) {
  var name = document.getElementById('modal_user_name').value.trim();
  var username = document.getElementById('modal_user_username').value.trim();
  var email = document.getElementById('modal_user_email') ? document.getElementById('modal_user_email').value.trim() : '';
  var roleKey = document.getElementById('modal_user_role').value;
  var branchId = parseInt(document.getElementById('modal_branch').value);
  var status = document.getElementById('modal_user_status').value;
  
  // Validation
  if (!name || !username) {
    showToast('Please fill all required fields.', true); 
    return;
  }
  
  var selectedRole = availableRoles.find(r => r.key === roleKey);
  
  // Check authentication fields for new users
  if (id === 0) {
    if (selectedRole.requires_password) {
      var password = document.getElementById('modal_user_password');
      if (!password || !password.value || password.value.length < 8) {
        showToast('Please enter an admin password (minimum 8 characters).', true);
        return;
      }
      if (!email) {
        showToast('Email is required for admin/manager roles.', true);
        return;
      }
    }
    
    if (selectedRole.requires_pin) {
      var pin = document.getElementById('modal_user_pin');
      if (!pin || !pin.value || pin.value.length < 4) {
        showToast('Please enter a POS PIN (4-6 digits).', true);
        return;
      }
    }
  }
  
  // Email validation for admin users
  if (selectedRole.requires_password && email && !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
    showToast('Please enter a valid email address.', true);
    return;
  }
  
  // Get branch name
  var branch = availableBranches.find(b => b.id === branchId);
  var branchName = branch ? branch.name : 'Unassigned';
  
  // Update or create row
  var tbody = document.getElementById('usersTable');
  if (id > 0) {
    var tr = document.querySelector('#usersTable tr[data-id="'+id+'"]');
    if (tr) updateUserRow(tr, id, name, username, email, roleKey, selectedRole.name, status, branchId, branchName, selectedRole);
  } else {
    var newId = Date.now();
    var tr = document.createElement('tr');
    tr.setAttribute('data-id', newId);
    updateUserRow(tr, newId, name, username, email, roleKey, selectedRole.name, status, branchId, branchName, selectedRole);
    tbody.appendChild(tr);
  }
  
  markDirty();
  closeModal();
  showToast(id > 0 ? 'User updated successfully' : 'User created successfully');
}

function updateUserRow(tr, id, name, username, email, roleKey, roleName, status, branchId, branchName, role) {
  var isPOS = roleKey.startsWith('pos_');
  var statusMap = {active:'success', inactive:'gray', suspended:'danger'};
  var statusClass = statusMap[status] || 'gray';
  var initial = name.charAt(0).toUpperCase();
  
  tr.setAttribute('data-search', (name+' '+username+' '+(email||'')+' '+roleName).toLowerCase());
  tr.setAttribute('data-branch', branchId);
  
  var authBadges = '';
  if (role.requires_password) authBadges += '<span class="badge badge-purple" title="Admin panel access">Admin</span> ';
  if (role.requires_pin) authBadges += '<span class="badge badge-success" title="POS terminal access">POS</span>';
  
  tr.innerHTML =
    '<td><div style="display:flex;align-items:center;">'+
      '<div class="user-avatar '+(isPOS?'pos':'')+'">'+initial+'</div>'+
      '<div><div style="font-weight:600;font-size:13px;">'+escapeHtml(name)+'</div>'+
      '<div style="color:var(--slate-500);font-size:11px;">@'+escapeHtml(username)+
      (email?' · '+escapeHtml(email):'')+'</div></div></div></td>'+
    '<td><span style="font-weight:500;">'+escapeHtml(roleName)+'</span></td>'+
    '<td><div style="display:flex;gap:4px;">'+authBadges+'</div></td>'+
    '<td style="font-size:12px;">'+escapeHtml(branchName)+'</td>'+
    '<td><span class="badge badge-'+statusClass+'">'+status.charAt(0).toUpperCase()+status.slice(1)+'</span></td>'+
    '<td style="color:var(--slate-600);font-size:12px;">Just now</td>'+
    '<td><div class="action-buttons">'+
      '<button class="btn btn-sm" onclick="editUser('+id+')">Edit</button>'+
      '<button class="btn btn-sm btn-danger" onclick="toggleUserStatus('+id+')">'+(status==='active'?'Disable':'Enable')+'</button>'+
    '</div></td>';
}

function resetPassword(id) {
  if (!confirm('Reset admin password for this user? They will receive an email to set a new password.')) return;
  showToast('Password reset email sent successfully');
  markDirty();
}

function resetPin(id) {
  if (!confirm('Reset POS PIN for this user? They will need to set a new PIN on next POS login.')) return;
  showToast('PIN reset successfully. New temporary PIN: 0000');
  markDirty();
}

function toggleUserStatus(id) {
  var tr = document.querySelector('#usersTable tr[data-id="'+id+'"]');
  if (!tr) return;
  
  var statusBadge = tr.querySelector('td:nth-child(5) .badge');
  var btn = tr.querySelector('.action-buttons .btn-danger');
  var currentStatus = statusBadge.textContent.toLowerCase();
  
  if (!confirm(currentStatus === 'active' ? 'Disable this user account?' : 'Enable this user account?')) return;
  
  var newStatus = currentStatus === 'active' ? 'inactive' : 'active';
  var newClass = newStatus === 'active' ? 'badge-success' : 'badge-gray';
  var newText = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
  var newBtnText = newStatus === 'active' ? 'Disable' : 'Enable';
  
  statusBadge.className = 'badge ' + newClass;
  statusBadge.textContent = newText;
  btn.textContent = newBtnText;
  
  markDirty();
  showToast('User status updated successfully');
}

/* ---------- Save all (stub) ---------- */
function saveAll() {
  var btn = document.getElementById('saveButton');
  btn.disabled = true;
  btn.textContent = 'Saving...';
  
  // In production, this would send data to server
  setTimeout(function() {
    btn.disabled = false;
    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px">'+
      '<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"></path>'+
      '<polyline points="17,21 17,13 7,13 7,21"></polyline><polyline points="7,3 7,8 15,8"></polyline></svg> Save Changes';
    isDirty = false;
    showToast('User settings saved successfully!');
  }, 1200);
}

/* Mark dirty on inputs */
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('input, select').forEach(function(el) {
    el.addEventListener('input', markDirty);
    el.addEventListener('change', markDirty);
  });
});

/* Leave warning */
window.addEventListener('beforeunload', function(e) {
  if (!isDirty) return;
  e.preventDefault();
  e.returnValue = 'You have unsaved changes';
});
</script>
</body>
</html>