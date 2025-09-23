<?php
/**
 * SME 180 POS - Login Interface
 * Path: /public_html/pos/login.php
 * Version: 6.0.0 - Final Production with Search
 */
declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

// Start session and include configuration
require_once __DIR__ . '/../config/db.php';

// Simple session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if device is registered
$deviceRegistered = false;
$tenantId = null;
$branchId = null;
$tenantCode = null;

// Check cookies for device registration
if (isset($_COOKIE['pos_device_token']) && isset($_COOKIE['pos_tenant_id']) && isset($_COOKIE['pos_branch_id'])) {
    $deviceRegistered = true;
    $tenantId = (int)$_COOKIE['pos_tenant_id'];
    $branchId = (int)$_COOKIE['pos_branch_id'];
    $tenantCode = $_COOKIE['pos_tenant_code'] ?? '';
}

// If device not registered, redirect to device setup
if (!$deviceRegistered) {
    header('Location: /pos/device-setup.php');
    exit;
}

// Check if already logged in
if (isset($_SESSION['pos_user_id']) && $_SESSION['pos_user_id'] > 0) {
    header('Location: /pos/index.php');
    exit;
}

// Get database connection
try {
    $pdo = db();
} catch (Exception $e) {
    die('Database connection failed. Please contact support.');
}

// Get tenant info
$tenantName = 'Restaurant';
$currency = 'EGP';
try {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $tenantName = $result['name'];
    }
    
    // Get currency from settings
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE tenant_id = ? AND (branch_id = ? OR branch_id IS NULL) AND `key` = 'currency' LIMIT 1");
    $stmt->execute([$tenantId, $branchId]);
    $result = $stmt->fetchColumn();
    if ($result) {
        $currency = $result;
    }
} catch (Exception $e) {
    // Use defaults
}

// Role name mapping
$roleNames = [
    'admin' => 'Administrator',
    'pos_manager' => 'POS Manager', 
    'pos_cashier' => 'Cashier',
    'pos_waiter' => 'Waiter',
    'manager' => 'Manager',
    'cashier' => 'Cashier',
    'waiter' => 'Waiter',
    'staff' => 'Staff'
];

// Get users for this branch - using correct column names from your database
$users = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.username, u.name, u.role_key, u.pos_pin
        FROM users u
        WHERE u.tenant_id = ? 
        AND u.disabled_at IS NULL
        AND u.pos_pin IS NOT NULL
        AND u.pos_pin != ''
        ORDER BY 
            CASE WHEN u.role_key = 'admin' THEN 1
                 WHEN u.role_key = 'pos_manager' THEN 2
                 WHEN u.role_key = 'pos_cashier' THEN 3
                 WHEN u.role_key = 'pos_waiter' THEN 4
                 WHEN u.role_key = 'manager' THEN 5
                 WHEN u.role_key = 'cashier' THEN 6
                 WHEN u.role_key = 'waiter' THEN 7
                 ELSE 8 END,
            u.name, u.username
        LIMIT 50
    ");
    $stmt->execute([$tenantId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching users: " . $e->getMessage());
}

// Initialize variables
$error = '';
$success = '';

// Check for messages
if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    $success = 'You have been successfully logged out.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>POS Login - <?php echo htmlspecialchars($tenantName); ?></title>
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Inter', system-ui, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            overflow: hidden;
            position: relative;
        }
        
        /* Animated background particles */
        body::before, body::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            animation: float 20s infinite ease-in-out;
        }
        
        body::before {
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            top: -250px;
            right: -250px;
        }
        
        body::after {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.08) 0%, transparent 70%);
            bottom: -200px;
            left: -200px;
            animation-delay: 10s;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(30px, -30px) scale(1.1); }
        }
        
        /* Left sidebar with users */
        .sidebar {
            width: 320px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
            z-index: 10;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-header {
            padding: 28px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        
        .restaurant-name {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        
        .restaurant-code {
            font-size: 13px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        
        /* Search field */
        .search-container {
            padding: 16px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .search-input {
            width: 100%;
            padding: 10px 36px 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'%3E%3C/circle%3E%3Cpath d='m21 21-4.35-4.35'%3E%3C/path%3E%3C/svg%3E") no-repeat right 10px center;
            background-size: 18px;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-input::placeholder {
            color: #94a3b8;
        }
        
        .users-list {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 12px;
        }
        
        /* Custom scrollbar */
        .users-list::-webkit-scrollbar {
            width: 6px;
        }
        
        .users-list::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }
        
        .users-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        
        .users-list::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        .user-card {
            padding: 12px;
            margin-bottom: 8px;
            background: white;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            display: flex;
            align-items: center;
            gap: 12px;
            opacity: 0.95;
        }
        
        .user-card.hidden {
            display: none;
        }
        
        .user-card:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-color: #667eea;
            opacity: 1;
        }
        
        .user-card.selected {
            border-color: #667eea;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
            opacity: 1;
        }
        
        .user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: white;
            flex-shrink: 0;
        }
        
        .user-info {
            flex: 1;
            min-width: 0;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 14px;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .user-role {
            font-size: 12px;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Switch restaurant button container */
        .switch-container {
            padding: 16px;
            border-top: 1px solid #e2e8f0;
            background: white;
            text-align: center;
        }
        
        /* Main content area */
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 10;
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 32px 64px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 380px;
            padding: 32px 28px;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 24px;
        }
        
        .login-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .login-subtitle {
            font-size: 13px;
            color: #64748b;
        }
        
        .selected-user {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 16px rgba(102, 126, 234, 0.25);
        }
        
        .selected-user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
        }
        
        .selected-user-info {
            flex: 1;
        }
        
        .selected-user-name {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }
        
        .selected-user-role {
            font-size: 12px;
            opacity: 0.9;
        }
        
        /* PIN input */
        .pin-display {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-bottom: 24px;
        }
        
        .pin-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .pin-dot.filled {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transform: scale(1.3);
            box-shadow: 0 2px 6px rgba(102, 126, 234, 0.3);
        }
        
        /* Numeric keypad - bigger */
        .keypad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            max-width: 260px;
            margin: 0 auto;
        }
        
        .keypad-btn {
            aspect-ratio: 1.3;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 12px;
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 52px;
        }
        
        .keypad-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .keypad-btn:not(:disabled):hover {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .keypad-btn:not(:disabled):active {
            transform: translateY(0);
        }
        
        .keypad-btn.action {
            background: #f8fafc;
            color: #64748b;
            font-size: 16px;
        }
        
        .keypad-btn.action:not(:disabled):hover {
            background: #e2e8f0;
        }
        
        /* Processing indicator */
        .processing-indicator {
            grid-column: span 3;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            color: #667eea;
            font-size: 14px;
            font-weight: 600;
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-radius: 12px;
            margin-top: 6px;
        }
        
        .processing-spinner {
            width: 20px;
            height: 20px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        /* Footer actions */
        .footer-actions {
            margin-top: 24px;
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        
        .footer-link {
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            margin: 0 10px;
            transition: all 0.2s ease;
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
        }
        
        .footer-link:hover {
            color: #764ba2;
            background: rgba(102, 126, 234, 0.1);
        }
        
        .footer-link.switch-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 24px;
            display: inline-block;
            border-radius: 10px;
        }
        
        .footer-link.switch-btn:hover {
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            transform: translateY(-1px);
        }
        
        /* No user selected state */
        .no-user-prompt {
            text-align: center;
            padding: 28px 20px;
            color: #64748b;
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .no-user-prompt h3 {
            font-size: 16px;
            color: #475569;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .no-user-prompt p {
            font-size: 13px;
            color: #94a3b8;
        }
        
        /* Alert messages */
        .alert {
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        /* Loading spinner */
        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: inline-block;
            vertical-align: middle;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* No results */
        .no-results {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
            font-size: 14px;
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: -320px;
                height: 100vh;
                transition: left 0.3s ease;
                box-shadow: 2px 0 16px rgba(0, 0, 0, 0.1);
            }
            
            .sidebar.open {
                left: 0;
            }
            
            .mobile-menu-btn {
                position: fixed;
                top: 20px;
                left: 20px;
                z-index: 100;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: white;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                border: none;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
            }
            
            .login-container {
                padding: 28px 24px;
                max-width: 340px;
            }
            
            .keypad {
                max-width: 240px;
            }
            
            .keypad-btn {
                height: 48px;
                font-size: 18px;
            }
        }
        
        /* Hide mobile button on desktop */
        .mobile-menu-btn {
            display: none;
        }
        
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: flex;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 12h18M3 6h18M3 18h18" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
    
    <!-- Left sidebar with users -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="restaurant-name"><?php echo htmlspecialchars($tenantName); ?></div>
            <div class="restaurant-code">Code: <?php echo htmlspecialchars($tenantCode); ?></div>
        </div>
        
        <!-- Search field -->
        <div class="search-container">
            <input type="text" 
                   class="search-input" 
                   id="userSearch" 
                   placeholder="Search users..." 
                   autocomplete="off">
        </div>
        
        <div class="users-list" id="usersList">
            <?php if (empty($users)): ?>
                <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                    <div style="font-size: 48px; margin-bottom: 12px;">👥</div>
                    <div style="font-size: 14px; font-weight: 500;">No users available</div>
                    <div style="font-size: 12px; margin-top: 8px;">Please ensure users have PINs configured</div>
                </div>
            <?php else: ?>
                <?php 
                $colors = ['#667eea', '#764ba2', '#f59e0b', '#10b981', '#ef4444', '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6'];
                foreach ($users as $index => $user): 
                    $initials = strtoupper(substr($user['name'] ?? $user['username'], 0, 2));
                    $avatarColor = $colors[$index % count($colors)];
                    $roleName = $roleNames[$user['role_key']] ?? ucfirst(str_replace(['pos_', '_'], ['', ' '], $user['role_key']));
                ?>
                <div class="user-card" 
                     data-user-id="<?php echo $user['id']; ?>" 
                     data-user-name="<?php echo htmlspecialchars($user['name'] ?? $user['username']); ?>" 
                     data-user-role="<?php echo htmlspecialchars($roleName); ?>"
                     data-search-text="<?php echo strtolower(htmlspecialchars(($user['name'] ?? $user['username']) . ' ' . $roleName)); ?>">
                    <div class="user-avatar" style="background: <?php echo $avatarColor; ?>;">
                        <?php echo $initials; ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($user['name'] ?? $user['username']); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars($roleName); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="no-results" id="noResults" style="display: none;">
                No users found matching your search
            </div>
        </div>
        
        <div class="switch-container">
            <a href="/pos/device-setup.php?reset=1" class="footer-link switch-btn" 
               onclick="return confirm('This will reset the device and switch to another restaurant. Continue?')">
                Switch Restaurant
            </a>
        </div>
    </div>
    
    <!-- Main content area -->
    <div class="main-content">
        <div class="login-container">
            <div class="login-header">
                <h1 class="login-title">Enter PIN</h1>
                <p class="login-subtitle">Quick access to POS system</p>
            </div>
            
            <!-- Alert container -->
            <div id="alertContainer"></div>
            
            <!-- Selected user display or prompt -->
            <div id="selectedUserContainer">
                <div class="no-user-prompt">
                    <h3>Select a User to Continue</h3>
                    <p>Choose your account from the sidebar</p>
                </div>
            </div>
            
            <!-- PIN display -->
            <div class="pin-display" id="pinDisplay">
                <div class="pin-dot"></div>
                <div class="pin-dot"></div>
                <div class="pin-dot"></div>
                <div class="pin-dot"></div>
                <div class="pin-dot" style="display: none;"></div>
                <div class="pin-dot" style="display: none;"></div>
            </div>
            
            <!-- Numeric keypad - bigger -->
            <div class="keypad">
                <button type="button" class="keypad-btn" data-key="1" disabled>1</button>
                <button type="button" class="keypad-btn" data-key="2" disabled>2</button>
                <button type="button" class="keypad-btn" data-key="3" disabled>3</button>
                <button type="button" class="keypad-btn" data-key="4" disabled>4</button>
                <button type="button" class="keypad-btn" data-key="5" disabled>5</button>
                <button type="button" class="keypad-btn" data-key="6" disabled>6</button>
                <button type="button" class="keypad-btn" data-key="7" disabled>7</button>
                <button type="button" class="keypad-btn" data-key="8" disabled>8</button>
                <button type="button" class="keypad-btn" data-key="9" disabled>9</button>
                <button type="button" class="keypad-btn action" data-action="clear" disabled>CLR</button>
                <button type="button" class="keypad-btn" data-key="0" disabled>0</button>
                <button type="button" class="keypad-btn action" data-action="backspace" disabled>⌫</button>
                <!-- Processing indicator shown when PIN is being verified -->
                <div class="processing-indicator" id="processingIndicator" style="display: none;">
                    <div class="processing-spinner"></div>
                    <span>Verifying PIN...</span>
                </div>
            </div>
            
            <!-- Footer actions -->
            <div class="footer-actions">
                <a href="#" class="footer-link" onclick="togglePinLength(); return false;" id="pinToggle">6-Digit PIN</a>
                <a href="/views/auth/login.php" class="footer-link">Admin Portal</a>
            </div>
        </div>
    </div>
    
    <script>
    (function() {
        'use strict';
        
        // State
        let selectedUserId = null;
        let selectedUserName = null;
        let selectedUserRole = null;
        let pin = '';
        let pinLength = 4;
        let isProcessing = false;
        
        // DOM elements
        const userCards = document.querySelectorAll('.user-card');
        const selectedUserContainer = document.getElementById('selectedUserContainer');
        const pinDots = document.querySelectorAll('.pin-dot');
        const keypadBtns = document.querySelectorAll('.keypad-btn');
        const processingIndicator = document.getElementById('processingIndicator');
        const alertContainer = document.getElementById('alertContainer');
        const pinToggle = document.getElementById('pinToggle');
        const userSearch = document.getElementById('userSearch');
        const usersList = document.getElementById('usersList');
        const noResults = document.getElementById('noResults');
        
        // Search functionality
        userSearch.addEventListener('input', function() {
            const searchText = this.value.toLowerCase().trim();
            let hasVisibleUsers = false;
            
            userCards.forEach(card => {
                const searchData = card.dataset.searchText || '';
                if (searchData.includes(searchText)) {
                    card.classList.remove('hidden');
                    hasVisibleUsers = true;
                } else {
                    card.classList.add('hidden');
                }
            });
            
            // Show/hide no results message
            if (noResults) {
                noResults.style.display = hasVisibleUsers ? 'none' : 'block';
            }
        });
        
        // User selection
        function setupUserSelection() {
            const cards = document.querySelectorAll('.user-card');
            cards.forEach(card => {
                card.addEventListener('click', function() {
                    // Remove previous selection
                    cards.forEach(c => c.classList.remove('selected'));
                    
                    // Add selection
                    this.classList.add('selected');
                    
                    // Store user data
                    selectedUserId = this.dataset.userId;
                    selectedUserName = this.dataset.userName;
                    selectedUserRole = this.dataset.userRole;
                    
                    // Update UI
                    updateSelectedUserDisplay();
                    
                    // Enable keypad
                    enableKeypad(true);
                    
                    // Clear PIN
                    clearPin();
                    
                    // Close mobile sidebar
                    if (window.innerWidth <= 768) {
                        closeSidebar();
                    }
                });
            });
        }
        
        setupUserSelection();
        
        // Update selected user display
        function updateSelectedUserDisplay() {
            if (selectedUserId) {
                const initials = selectedUserName.substring(0, 2).toUpperCase();
                selectedUserContainer.innerHTML = `
                    <div class="selected-user">
                        <div class="selected-user-avatar">${initials}</div>
                        <div class="selected-user-info">
                            <div class="selected-user-name">${selectedUserName}</div>
                            <div class="selected-user-role">${selectedUserRole}</div>
                        </div>
                    </div>
                `;
            }
        }
        
        // Enable/disable keypad
        function enableKeypad(enable) {
            keypadBtns.forEach(btn => {
                if (!btn.classList.contains('submit')) {
                    btn.disabled = !enable;
                }
            });
        }
        
        // Keypad handling
        keypadBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                if (isProcessing || this.disabled) return;
                
                const key = this.dataset.key;
                const action = this.dataset.action;
                
                if (key) {
                    addDigit(key);
                } else if (action === 'clear') {
                    clearPin();
                } else if (action === 'backspace') {
                    removeDigit();
                }
            });
        });
        
        // Keyboard support
        document.addEventListener('keydown', function(e) {
            if (isProcessing || !selectedUserId) return;
            
            // Don't interfere with search input
            if (document.activeElement === userSearch) return;
            
            if (e.key >= '0' && e.key <= '9') {
                addDigit(e.key);
            } else if (e.key === 'Backspace') {
                removeDigit();
            } else if (e.key === 'Delete' || e.key === 'Escape') {
                clearPin();
            } else if (e.key === 'Enter' && pin.length === pinLength && selectedUserId) {
                submitLogin();
            }
        });
        
        // PIN functions
        function addDigit(digit) {
            if (pin.length < pinLength) {
                pin += digit;
                updatePinDisplay();
                
                // Auto-submit when complete
                if (pin.length === pinLength && selectedUserId) {
                    // Small delay for visual feedback
                    setTimeout(() => submitLogin(), 200);
                }
            }
        }
        
        function removeDigit() {
            if (pin.length > 0) {
                pin = pin.slice(0, -1);
                updatePinDisplay();
            }
        }
        
        function clearPin() {
            pin = '';
            updatePinDisplay();
        }
        
        function updatePinDisplay() {
            pinDots.forEach((dot, index) => {
                if (index < pinLength) {
                    dot.style.display = '';
                    if (index < pin.length) {
                        dot.classList.add('filled');
                    } else {
                        dot.classList.remove('filled');
                    }
                } else {
                    dot.style.display = 'none';
                }
            });
        }
        
        // Toggle PIN length
        window.togglePinLength = function() {
            pinLength = pinLength === 4 ? 6 : 4;
            pinToggle.textContent = pinLength === 4 ? '6-Digit PIN' : '4-Digit PIN';
            clearPin();
        };
        
        // Submit login
        async function submitLogin() {
            if (!selectedUserId || pin.length !== pinLength || isProcessing) return;
            
            isProcessing = true;
            
            // Disable keypad during processing
            enableKeypad(false);
            
            // Show processing indicator
            processingIndicator.style.display = 'flex';
            
            try {
                const response = await fetch('/pos/api/auth/pin_login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: parseInt(selectedUserId),
                        pin: pin,
                        device_token: getCookie('pos_device_token') || null,
                        tenant_id: <?php echo json_encode($tenantId); ?>,
                        branch_id: <?php echo json_encode($branchId); ?>
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('Login successful! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = result.data.redirect || '/pos/index.php';
                    }, 500);
                } else {
                    // Hide processing indicator
                    processingIndicator.style.display = 'none';
                    
                    // Re-enable keypad
                    enableKeypad(true);
                    
                    // Show error
                    showAlert(result.error || 'Invalid PIN. Please try again.', 'error');
                    clearPin();
                    isProcessing = false;
                }
            } catch (error) {
                console.error('Login error:', error);
                
                // Hide processing indicator
                processingIndicator.style.display = 'none';
                
                // Re-enable keypad
                enableKeypad(true);
                
                showAlert('Connection error. Please try again.', 'error');
                clearPin();
                isProcessing = false;
            }
        }
        
        // Show alert
        function showAlert(message, type = 'error') {
            const alertHtml = `
                <div class="alert alert-${type}">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        ${type === 'success' 
                            ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>'
                            : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>'
                        }
                    </svg>
                    ${message}
                </div>
            `;
            
            alertContainer.innerHTML = alertHtml;
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                alertContainer.innerHTML = '';
            }, 5000);
        }
        
        // Get cookie
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }
        
        // Mobile sidebar
        window.toggleSidebar = function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        };
        
        window.closeSidebar = function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.remove('open');
        };
        
        // Initialize
        updatePinDisplay();
        enableKeypad(false); // Start with keypad disabled
        
        // Show messages from PHP
        <?php if ($success): ?>
        showAlert('<?php echo addslashes($success); ?>', 'success');
        <?php endif; ?>
        
        <?php if ($error): ?>
        showAlert('<?php echo addslashes($error); ?>', 'error');
        <?php endif; ?>
    })();
    </script>
</body>
</html>