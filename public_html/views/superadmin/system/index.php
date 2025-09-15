<?php
// /views/superadmin/system/index.php
declare(strict_types=1);

// Start output buffering to prevent header issues
ob_start();

// Only enable error reporting if debug parameter is present
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Include configuration
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

// Get admin name
$admin_name = $_SESSION['super_admin_name'] ?? 'Super Admin';

// Initialize database connection
try {
    $pdo = db();
    
    // Get system statistics
    $stats = [];
    
    // Database size
    $stmt = $pdo->query("
        SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as db_size_mb
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE()
    ");
    $stats['db_size'] = $stmt->fetchColumn() . ' MB';
    
    // Total tables
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $stats['total_tables'] = $stmt->fetchColumn();
    
    // Total tenants
    $stmt = $pdo->query("SELECT COUNT(*) FROM tenants WHERE is_deleted = 0");
    $stats['total_tenants'] = $stmt->fetchColumn();
    
    // Active tenants (logged in last 30 days)
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT t.id) 
        FROM tenants t
        JOIN users u ON t.id = u.tenant_id
        JOIN audit_logs a ON u.id = a.user_id
        WHERE t.is_deleted = 0 
        AND a.action = 'login'
        AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats['active_tenants'] = $stmt->fetchColumn();
    
    // Total users
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_deleted = 0");
    $stats['total_users'] = $stmt->fetchColumn();
    
    // Total branches
    $stmt = $pdo->query("SELECT COUNT(*) FROM branches WHERE is_deleted = 0");
    $stats['total_branches'] = $stmt->fetchColumn();
    
    // Recent audit logs count (24h)
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM audit_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stats['recent_logs'] = $stmt->fetchColumn();
    
    // Failed login attempts (24h)
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM audit_logs 
        WHERE action LIKE '%failed%' 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stats['failed_logins'] = $stmt->fetchColumn();
    
    // Active sessions (logged in last 30 minutes)
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT user_id) 
        FROM audit_logs 
        WHERE action = 'login' 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    $stats['active_sessions'] = $stmt->fetchColumn();
    
    // Get recent audit logs
    $stmt = $pdo->query("
        SELECT 
            a.*,
            u.username,
            u.name as user_name,
            t.name as tenant_name
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN tenants t ON a.tenant_id = t.id
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get tenant statistics by plan
    $stmt = $pdo->query("
        SELECT 
            p.name as plan_name,
            COUNT(t.id) as tenant_count
        FROM plans p
        LEFT JOIN tenants t ON p.id = t.plan_id AND t.is_deleted = 0
        GROUP BY p.id, p.name
        ORDER BY tenant_count DESC
    ");
    $plans_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Set default values if database queries fail
    $stats = [
        'db_size' => 'N/A',
        'total_tables' => 0,
        'total_tenants' => 0,
        'active_tenants' => 0,
        'total_users' => 0,
        'total_branches' => 0,
        'recent_logs' => 0,
        'failed_logins' => 0,
        'active_sessions' => 0
    ];
    $recent_logs = [];
    $plans_stats = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Management - SME 180</title>
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
        
        /* Header */
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
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--gray-200);
            transition: all 0.3s;
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
            background: var(--primary-gradient);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }
        
        .stat-card:hover::before {
            opacity: 1;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 1rem;
        }
        
        .stat-icon.database { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-icon.tenants { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .stat-icon.users { background: linear-gradient(135deg, #fa709a, #fee140); }
        .stat-icon.branches { background: linear-gradient(135deg, #30cfd0, #330867); }
        .stat-icon.sessions { background: linear-gradient(135deg, #a8edea, #fed6e3); }
        .stat-icon.logs { background: linear-gradient(135deg, #ffecd2, #fcb69f); }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
        }
        
        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .info-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }
        
        .info-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: var(--gray-50);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .info-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .info-body {
            padding: 1rem;
            max-height: 400px;
            overflow-y: auto;
        }
        
        /* Activity List */
        .activity-item {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 1rem;
            transition: background 0.2s;
        }
        
        .activity-item:hover {
            background: var(--gray-50);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-action {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }
        
        .activity-meta {
            font-size: 12px;
            color: var(--gray-500);
        }
        
        .activity-time {
            font-size: 12px;
            color: var(--gray-400);
            white-space: nowrap;
        }
        
        /* Plan Stats */
        .plan-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .plan-item:last-child {
            border-bottom: none;
        }
        
        .plan-name {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .plan-count {
            background: var(--primary-gradient);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
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
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray-500);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
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
    
    <!-- Main Container -->
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">System Overview</h1>
            <a href="/views/superadmin/system/audit_logs.php" class="btn btn-primary">
                View Audit Logs
            </a>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon database">💾</div>
                <div class="stat-value"><?= $stats['db_size'] ?></div>
                <div class="stat-label">Database Size</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon tenants">🏢</div>
                <div class="stat-value"><?= number_format($stats['total_tenants']) ?></div>
                <div class="stat-label">Total Tenants</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon users">👥</div>
                <div class="stat-value"><?= number_format($stats['total_users']) ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon branches">🏪</div>
                <div class="stat-value"><?= number_format($stats['total_branches']) ?></div>
                <div class="stat-label">Total Branches</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon sessions">🟢</div>
                <div class="stat-value"><?= number_format($stats['active_sessions']) ?></div>
                <div class="stat-label">Active Sessions</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon logs">📊</div>
                <div class="stat-value"><?= number_format($stats['recent_logs']) ?></div>
                <div class="stat-label">Logs (24h)</div>
            </div>
        </div>
        
        <!-- Info Cards -->
        <div class="info-grid">
            <!-- Recent Activity -->
            <div class="info-card">
                <div class="info-header">
                    <h3 class="info-title">Recent System Activity</h3>
                    <a href="/views/superadmin/system/audit_logs.php" class="btn btn-secondary">View All</a>
                </div>
                <div class="info-body">
                    <?php if (empty($recent_logs)): ?>
                        <div class="empty-state">
                            No recent activity recorded
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_logs as $log): ?>
                        <div class="activity-item">
                            <div class="activity-content">
                                <div class="activity-action">
                                    <?= htmlspecialchars($log['action']) ?>
                                </div>
                                <div class="activity-meta">
                                    <?php if ($log['user_name']): ?>
                                        <?= htmlspecialchars($log['user_name']) ?>
                                    <?php endif; ?>
                                    <?php if ($log['tenant_name']): ?>
                                        • <?= htmlspecialchars($log['tenant_name']) ?>
                                    <?php endif; ?>
                                    <?php if ($log['ip_address']): ?>
                                        • IP: <?= htmlspecialchars($log['ip_address']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="activity-time">
                                <?= date('H:i', strtotime($log['created_at'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Tenant Distribution by Plan -->
            <div class="info-card">
                <div class="info-header">
                    <h3 class="info-title">Tenants by Plan</h3>
                    <span style="font-size: 12px; color: var(--gray-500);">
                        <?= number_format($stats['active_tenants']) ?> active
                    </span>
                </div>
                <div class="info-body">
                    <?php if (empty($plans_stats)): ?>
                        <div class="empty-state">
                            No plan data available
                        </div>
                    <?php else: ?>
                        <?php foreach ($plans_stats as $plan): ?>
                        <div class="plan-item">
                            <span class="plan-name"><?= htmlspecialchars($plan['plan_name']) ?></span>
                            <span class="plan-count"><?= number_format($plan['tenant_count']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- System Information -->
        <div class="info-card">
            <div class="info-header">
                <h3 class="info-title">System Information</h3>
            </div>
            <div class="info-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; padding: 1rem;">
                    <div>
                        <div style="font-size: 12px; color: var(--gray-500); margin-bottom: 0.25rem;">Database Tables</div>
                        <div style="font-weight: 600; color: var(--gray-900);"><?= number_format($stats['total_tables']) ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--gray-500); margin-bottom: 0.25rem;">Failed Logins (24h)</div>
                        <div style="font-weight: 600; color: <?= $stats['failed_logins'] > 10 ? 'var(--danger)' : 'var(--gray-900)' ?>;">
                            <?= number_format($stats['failed_logins']) ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--gray-500); margin-bottom: 0.25rem;">PHP Version</div>
                        <div style="font-weight: 600; color: var(--gray-900);"><?= PHP_VERSION ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--gray-500); margin-bottom: 0.25rem;">Server Time</div>
                        <div style="font-weight: 600; color: var(--gray-900);"><?= date('Y-m-d H:i:s') ?></div>
                    </div>
                </div>
            </div>
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
        
        // Auto-refresh page every 60 seconds for real-time monitoring
        setTimeout(function() {
            location.reload();
        }, 60000);
    </script>
</body>
</html>