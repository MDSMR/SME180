<?php
// /views/superadmin/system/audit_logs.php
declare(strict_types=1);

// Start output buffering
ob_start();

// Error handling
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

require_once dirname(__DIR__, 3) . '/config/db.php';

// Check if user is super admin
if (function_exists('use_backend_session')) {
    use_backend_session();
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'super_admin') {
    header('Location: /views/auth/login.php');
    exit;
}

$admin_name = $_SESSION['super_admin_name'] ?? 'Super Admin';
$pdo = db();

// Get filter parameters
$filter_tenant = $_GET['tenant_id'] ?? '';
$filter_user = $_GET['user_id'] ?? '';
$filter_action = $_GET['action'] ?? '';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build query with filters
$where_conditions = ['1=1'];
$params = [];

if ($filter_tenant) {
    $where_conditions[] = 'a.tenant_id = :tenant_id';
    $params[':tenant_id'] = $filter_tenant;
}

if ($filter_user) {
    $where_conditions[] = 'a.user_id = :user_id';
    $params[':user_id'] = $filter_user;
}

if ($filter_action) {
    $where_conditions[] = 'a.action LIKE :action';
    $params[':action'] = '%' . $filter_action . '%';
}

if ($filter_date_from) {
    $where_conditions[] = 'DATE(a.created_at) >= :date_from';
    $params[':date_from'] = $filter_date_from;
}

if ($filter_date_to) {
    $where_conditions[] = 'DATE(a.created_at) <= :date_to';
    $params[':date_to'] = $filter_date_to;
}

$where_clause = implode(' AND ', $where_conditions);

// Count total records
$count_sql = "SELECT COUNT(*) FROM audit_logs a WHERE $where_clause";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get audit logs with details
$sql = "
    SELECT 
        a.*,
        u.username,
        u.name as user_name,
        t.name as tenant_name,
        b.name as branch_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN tenants t ON a.tenant_id = t.id
    LEFT JOIN branches b ON a.branch_id = b.id
    WHERE $where_clause
    ORDER BY a.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique tenants for filter
$stmt = $pdo->query("SELECT id, name FROM tenants ORDER BY name");
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique actions for filter
$stmt = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action");
$actions = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Function to format the details
function formatDetails($details_json) {
    if (!$details_json) return '-';
    $details = json_decode($details_json, true);
    if (!$details) return '-';
    
    $output = [];
    foreach ($details as $key => $value) {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        }
        $output[] = "<strong>" . htmlspecialchars($key) . ":</strong> " . htmlspecialchars($value);
    }
    return implode('<br>', $output);
}

// Function to get action icon
function getActionIcon($action) {
    $icons = [
        'login' => '🔓',
        'logout' => '🔒',
        'failed_login' => '⚠️',
        'create' => '➕',
        'update' => '✏️',
        'delete' => '🗑️',
        'order' => '🛒',
        'payment' => '💳',
        'user' => '👤',
        'settings' => '⚙️',
        'product' => '📦',
        'customer' => '👥',
        'inventory' => '📊',
        'report' => '📈'
    ];
    
    foreach ($icons as $key => $icon) {
        if (stripos($action, $key) !== false) {
            return $icon;
        }
    }
    return '📝';
}

// Handle export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Timestamp', 'Tenant', 'User', 'Action', 'IP Address', 'Branch']);
    
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['created_at'],
            $log['tenant_name'] ?? 'Tenant #' . $log['tenant_id'],
            $log['user_name'] ?? 'User #' . $log['user_id'],
            $log['action'],
            $log['ip_address'] ?? '-',
            $log['branch_name'] ?? '-'
        ]);
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - SME 180</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --primary-color: #667eea;
            --primary-dark: #5a67d8;
            --secondary-color: #764ba2;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --info: #3B82F6;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
        }
        
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Inter', Roboto, sans-serif;
            background: linear-gradient(to bottom, #f0f2f5, #ffffff);
            min-height: 100vh;
            color: var(--gray-700);
        }
        
        /* Header - Matching index.php */
        .header {
            background: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .logo-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
        }
        
        .logo-text {
            font-size: 24px;
            font-weight: 700;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-dropdown {
            position: relative;
        }
        
        .user-button {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.5rem 1rem;
            background: transparent;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .user-button:hover {
            background: var(--gray-50);
            border-color: var(--primary-color);
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        
        .user-info {
            text-align: left;
        }
        
        .user-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
        }
        
        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 0.5rem;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            min-width: 200px;
            display: none;
            z-index: 1000;
        }
        
        .dropdown-menu.show {
            display: block;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.75rem 1rem;
            color: var(--gray-700);
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .dropdown-item:hover {
            background: var(--gray-50);
        }
        
        /* Navigation */
        .nav-wrapper {
            background: white;
            border-bottom: 1px solid var(--gray-200);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0 2rem;
        }
        
        .nav-content {
            display: flex;
            gap: 2rem;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 1rem 1.5rem;
            color: var(--gray-600);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            position: relative;
        }
        
        .nav-link:hover {
            color: var(--primary-color);
            background: var(--gray-50);
        }
        
        .nav-link.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: linear-gradient(to bottom, rgba(102, 126, 234, 0.05), transparent);
        }
        
        .nav-icon {
            width: 18px;
            height: 18px;
        }
        
        /* Container */
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid var(--gray-200);
        }
        
        .filter-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 0.5rem;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            font-size: 14px;
            background: white;
            transition: all 0.2s;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* Stats */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid var(--gray-200);
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        
        /* Table */
        .table-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: hidden;
            border: 1px solid var(--gray-200);
        }
        
        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--gray-50);
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .table-info {
            font-size: 14px;
            color: var(--gray-600);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: var(--gray-50);
            padding: 0.75rem;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--gray-200);
        }
        
        td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-100);
            font-size: 14px;
            vertical-align: top;
        }
        
        tbody tr:hover {
            background: var(--gray-50);
        }
        
        /* Action Badge */
        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .action-badge.login {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .action-badge.failed {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .action-badge.update {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .action-badge.delete {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        /* Details */
        .details-box {
            background: var(--gray-50);
            padding: 0.5rem;
            border-radius: 6px;
            font-size: 12px;
            max-width: 400px;
        }
        
        .details-box strong {
            color: var(--gray-700);
        }
        
        /* Button */
        .btn {
            padding: 0.625rem 1.25rem;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: white;
            color: var(--gray-600);
            border: 1px solid var(--gray-200);
        }
        
        .btn-secondary:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1.5rem;
        }
        
        .page-link {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: 6px;
            color: var(--gray-700);
            text-decoration: none;
            transition: all 0.2s;
            font-size: 14px;
            font-weight: 500;
        }
        
        .page-link:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .page-link.active {
            background: var(--primary-gradient);
            color: white;
            border-color: var(--primary-color);
        }
        
        .page-link:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* User info */
        .user-info-cell {
            display: flex;
            flex-direction: column;
            gap: 0.125rem;
        }
        
        .user-name {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .user-username {
            font-size: 12px;
            color: var(--gray-500);
        }
        
        /* Timestamp */
        .timestamp {
            display: flex;
            flex-direction: column;
            gap: 0.125rem;
        }
        
        .timestamp-date {
            font-weight: 500;
            color: var(--gray-900);
        }
        
        .timestamp-time {
            font-size: 12px;
            color: var(--gray-500);
        }
        
        /* IP Address */
        .ip-address {
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 12px;
            background: var(--gray-100);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            color: var(--gray-700);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray-500);
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .empty-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }
        
        .empty-text {
            font-size: 14px;
            color: var(--gray-500);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <div class="logo-wrapper">
                    <svg class="logo-icon" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="gradOuter" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#8B5CF6"/>
                                <stop offset="50%" stop-color="#6366F1"/>
                                <stop offset="100%" stop-color="#3B82F6"/>
                            </linearGradient>
                            <radialGradient id="gradOrb" cx="30%" cy="30%" r="70%">
                                <stop offset="0%" stop-color="#8B5CF6"/>
                                <stop offset="100%" stop-color="#06B6D4"/>
                            </radialGradient>
                        </defs>
                        <circle cx="100" cy="100" r="75" fill="none" stroke="url(#gradOuter)" stroke-width="8" stroke-linecap="round" stroke-dasharray="235 235" transform="rotate(-45 100 100)"/>
                        <circle cx="100" cy="100" r="52" fill="none" stroke="#06B6D4" stroke-width="8" stroke-linecap="round" stroke-dasharray="163 163" transform="rotate(135 100 100)"/>
                        <circle cx="100" cy="100" r="30" fill="url(#gradOrb)"/>
                    </svg>
                    <div class="logo-text">SME 180</div>
                </div>
            </div>
            
            <div class="header-right">
                <div class="user-dropdown">
                    <button class="user-button" onclick="toggleDropdown()">
                        <div class="user-avatar">
                            <?= strtoupper(substr($admin_name, 0, 1)) ?>
                        </div>
                        <div class="user-info">
                            <span class="user-name"><?= htmlspecialchars($admin_name) ?></span>
                        </div>
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="/views/auth/logout.php" class="dropdown-item">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Navigation -->
    <nav class="nav-wrapper">
        <div class="nav-container">
            <div class="nav-content">
                <a href="/views/superadmin/dashboard.php" class="nav-link">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    Dashboard
                </a>
                <a href="/views/superadmin/tenants/index.php" class="nav-link">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    Tenants
                </a>
                <a href="/views/superadmin/plans/index.php" class="nav-link">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    Plans
                </a>
                <a href="/views/superadmin/system/index.php" class="nav-link active">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    System
                </a>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Audit Logs</h1>
            <button class="btn btn-primary" onclick="exportLogs()">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Export Logs
            </button>
        </div>
        
        <!-- Filter Card -->
        <div class="filter-card">
            <div class="filter-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                </svg>
                Filter Logs
            </div>
            <form method="GET">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Tenant</label>
                        <select name="tenant_id">
                            <option value="">All Tenants</option>
                            <?php foreach ($tenants as $tenant): ?>
                            <option value="<?= $tenant['id'] ?>" <?= $filter_tenant == $tenant['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tenant['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Action</label>
                        <select name="action">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $action): ?>
                            <option value="<?= htmlspecialchars($action) ?>" <?= $filter_action == $action ? 'selected' : '' ?>>
                                <?= htmlspecialchars($action) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($filter_date_from) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($filter_date_to) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label>User ID</label>
                        <input type="text" name="user_id" placeholder="User ID" value="<?= htmlspecialchars($filter_user) ?>">
                    </div>
                </div>
                <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="?" class="btn btn-secondary">Clear Filters</a>
                </div>
            </form>
        </div>
        
        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-value"><?= number_format($total_records) ?></div>
                <div class="stat-label">Total Records</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_unique(array_column($logs, 'tenant_id'))) ?></div>
                <div class="stat-label">Active Tenants</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_unique(array_column($logs, 'user_id'))) ?></div>
                <div class="stat-label">Active Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_unique(array_column($logs, 'action'))) ?></div>
                <div class="stat-label">Action Types</div>
            </div>
        </div>
        
        <!-- Logs Table -->
        <div class="table-card">
            <div class="table-header">
                <h3 class="table-title">Audit Log Details</h3>
                <span class="table-info">
                    Showing <?= number_format($offset + 1) ?> - <?= number_format(min($offset + $per_page, $total_records)) ?> of <?= number_format($total_records) ?> records
                </span>
            </div>
            
            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📋</div>
                    <div class="empty-title">No Audit Logs Found</div>
                    <div class="empty-text">No audit logs match your current filters. Try adjusting your filter criteria.</div>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th width="150">Timestamp</th>
                        <th>Tenant</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>IP Address</th>
                        <th>Branch</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <div class="timestamp">
                                <span class="timestamp-date"><?= date('M d, Y', strtotime($log['created_at'])) ?></span>
                                <span class="timestamp-time"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                            </div>
                        </td>
                        <td>
                            <?= htmlspecialchars($log['tenant_name'] ?? 'Tenant #' . $log['tenant_id']) ?>
                        </td>
                        <td>
                            <div class="user-info-cell">
                                <span class="user-name"><?= htmlspecialchars($log['user_name'] ?? 'User #' . $log['user_id']) ?></span>
                                <?php if ($log['username']): ?>
                                <span class="user-username">@<?= htmlspecialchars($log['username']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="action-badge <?= strpos($log['action'], 'failed') !== false ? 'failed' : strtolower(explode('_', $log['action'])[0]) ?>">
                                <?= getActionIcon($log['action']) ?> <?= htmlspecialchars($log['action']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="details-box">
                                <?= formatDetails($log['details']) ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($log['ip_address']): ?>
                            <span class="ip-address"><?= htmlspecialchars($log['ip_address']) ?></span>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($log['branch_name'] ?? '-') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-link">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                    <span style="padding: 0.5rem;">...</span>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-link">Next</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function toggleDropdown() {
            document.getElementById('userDropdown').classList.toggle('show');
        }
        
        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.matches('.user-button') && !event.target.closest('.user-button')) {
                var dropdown = document.getElementById('userDropdown');
                if (dropdown && dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }
        }
        
        function exportLogs() {
            const params = new URLSearchParams(window.location.search);
            params.append('export', 'csv');
            window.location.href = '?' + params.toString();
        }
    </script>
</body>
</html>