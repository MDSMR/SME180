<?php if ($name === 'tables' && isset($test['existing_count'])): ?>
                    <br>Total tables in database: <strong><?= $test['existing_count'] ?></strong>
                <?php endif; ?>
                
                <?php if ($name === 'columns'): ?>
                    <!-- Always show the details, not just issues -->
                    <?php if ($test['status'] !== 'PASS'): ?>
                        <!-- The issues are already embedded in the message HTML -->
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($name === 'table_patterns' && !empty($test['patterns'])): ?>
                    <div style="margin-top: 10px;">
                        <?php foreach ($test['patterns'] as $pattern => $tables): ?>
                            <div style="margin-bottom: 10px;">
                                <strong><?= htmlspecialchars($pattern) ?>:</strong>
                                <div style="margin-left: 20px; font-size: 13px; color: #4a5568;">
                                    <?= htmlspecialchars(implode(', ', array_slice($tables, 0, 5))) ?>
                                    <?php if (count($tables) > 5): ?>
                                        <em>... and <?= count($tables) - 5 ?> more</em>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?><?php
/**
 * SME 180 POS - Complete Device Setup Test
 * File: /public_html/test_device_setup.php
 * 
 * Comprehensive test for all Phase 3 components
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/db.php';

// Check if this is an API request FIRST (before any output)
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// Debug: Log what we received
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST received. Raw input: " . substr($rawInput, 0, 100));
    error_log("Decoded input: " . print_r($input, true));
}

// Handle JSON API request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($input) && isset($input['test_api'])) {
    header('Content-Type: application/json');
    
    try {
        $pdo = db();
        
        $tenantCode = strtoupper($input['tenant_code'] ?? 'REST001');
        $username = $input['username'] ?? 'admin';
        $pin = $input['pin'] ?? '1234';
        
        // Get tenant
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE tenant_code = ?");
        $stmt->execute([$tenantCode]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tenant) {
            throw new Exception("Tenant not found: $tenantCode");
        }
        
        // Get user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE tenant_id = ? AND username = ?");
        $stmt->execute([$tenant['id'], $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("User not found: $username");
        }
        
        if (!password_verify($pin, $user['pos_pin'])) {
            throw new Exception("Invalid PIN");
        }
        
        // Get branches
        $stmt = $pdo->prepare("
            SELECT b.* FROM branches b
            JOIN user_branches ub ON b.id = ub.branch_id
            WHERE ub.user_id = ? AND b.tenant_id = ? AND b.is_active = 1
            ORDER BY b.name
        ");
        $stmt->execute([$user['id'], $tenant['id']]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($branches)) {
            throw new Exception("No branches linked to user");
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'tenant' => [
                    'id' => $tenant['id'],
                    'name' => $tenant['name'],
                    'code' => $tenant['tenant_code']
                ],
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'username' => $user['username']
                ],
                'branches' => $branches,
                'device' => [
                    'token' => bin2hex(random_bytes(32))
                ]
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

// Alternative: Handle form POST request (non-JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_api'])) {
    header('Content-Type: application/json');
    
    try {
        $pdo = db();
        
        $tenantCode = strtoupper($_POST['tenant_code'] ?? 'REST001');
        $username = $_POST['username'] ?? 'admin';
        $pin = $_POST['pin'] ?? '1234';
        
        // Get tenant
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE tenant_code = ?");
        $stmt->execute([$tenantCode]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tenant) {
            throw new Exception("Tenant not found: $tenantCode");
        }
        
        // Get user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE tenant_id = ? AND username = ?");
        $stmt->execute([$tenant['id'], $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("User not found: $username");
        }
        
        if (!password_verify($pin, $user['pos_pin'])) {
            throw new Exception("Invalid PIN");
        }
        
        // Get branches
        $stmt = $pdo->prepare("
            SELECT b.* FROM branches b
            JOIN user_branches ub ON b.id = ub.branch_id
            WHERE ub.user_id = ? AND b.tenant_id = ? AND b.is_active = 1
            ORDER BY b.name
        ");
        $stmt->execute([$user['id'], $tenant['id']]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($branches)) {
            throw new Exception("No branches linked to user");
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'tenant' => [
                    'id' => $tenant['id'],
                    'name' => $tenant['name'],
                    'code' => $tenant['tenant_code']
                ],
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'username' => $user['username']
                ],
                'branches' => $branches,
                'device' => [
                    'token' => bin2hex(random_bytes(32))
                ]
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

// Regular page load - run tests
$tests = [];

// TEST 1: Database Connection
try {
    $pdo = db();
    if ($pdo) {
        $tests['db_connection'] = ['status' => 'PASS', 'message' => 'Database connected successfully'];
    } else {
        $tests['db_connection'] = ['status' => 'FAIL', 'message' => 'Database connection returned null'];
    }
} catch (Exception $e) {
    $tests['db_connection'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
}

// TEST 2: Check Tenant
try {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE tenant_code = 'REST001'");
    $stmt->execute();
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($tenant) {
        $tests['tenant'] = [
            'status' => 'PASS', 
            'message' => "Tenant found: {$tenant['name']} (ID: {$tenant['id']})"
        ];
    } else {
        $tests['tenant'] = ['status' => 'FAIL', 'message' => 'No tenant with code REST001'];
    }
} catch (Exception $e) {
    $tests['tenant'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
}

// TEST 3: Check User
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = 'admin'");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $tests['user'] = [
            'status' => 'PASS', 
            'message' => "User found: {$user['name']} (ID: {$user['id']})",
            'has_pin' => !empty($user['pos_pin']) ? 'YES' : 'NO'
        ];
    } else {
        $tests['user'] = ['status' => 'FAIL', 'message' => 'No user with username admin'];
    }
} catch (Exception $e) {
    $tests['user'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
}

// TEST 4: PIN Verification
if (isset($user) && $user) {
    $test_pin = '1234';
    $verify = password_verify($test_pin, $user['pos_pin']);
    
    $tests['pin_verify'] = [
        'status' => $verify ? 'PASS' : 'FAIL',
        'message' => $verify ? 'PIN 1234 verified successfully' : 'PIN 1234 verification failed',
        'hash_length' => strlen($user['pos_pin'])
    ];
}

// TEST 5: Check Branches
try {
    $stmt = $pdo->prepare("
        SELECT b.*, ub.user_id 
        FROM branches b 
        LEFT JOIN user_branches ub ON b.id = ub.branch_id AND ub.user_id = 1
        WHERE b.tenant_id = 1
    ");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $linked_branches = array_filter($branches, function($b) { return $b['user_id'] !== null; });
    
    $tests['branches'] = [
        'status' => count($linked_branches) > 0 ? 'PASS' : 'FAIL',
        'message' => "Total branches: " . count($branches) . ", Linked to admin: " . count($linked_branches),
        'list' => $branches
    ];
} catch (Exception $e) {
    $tests['branches'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
}

// TEST 6: Check Tables
$existing_tables = [];
try {
    // Updated to match actual table names in your database
    $required_tables = [
        // Core tables
        'tenants', 'users', 'branches', 'user_branches', 'settings',
        // Product tables
        'categories', 'products', 'product_categories', 'product_branches',
        // Modifier/variation tables (your naming)
        'variation_groups', 'variation_values', 'product_variation_groups',
        // POS tables
        'pos_device_registry', 'pos_stations', 'cash_sessions',
        // Order tables
        'orders', 'order_items', 'order_item_variations', 'order_logs',
        // Payment tables
        'order_payments', 'payment_methods', 'cash_movements'
    ];
    
    // Get all existing tables
    $stmt = $pdo->query("SHOW TABLES");
    $existing_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $missing_tables = [];
    $found_tables = [];
    foreach ($required_tables as $table) {
        if (in_array($table, $existing_tables)) {
            $found_tables[] = $table;
        } else {
            $missing_tables[] = $table;
        }
    }
    
    $tests['tables'] = [
        'status' => empty($missing_tables) ? 'PASS' : (count($missing_tables) < 5 ? 'WARNING' : 'FAIL'),
        'message' => empty($missing_tables) 
            ? "All " . count($required_tables) . " required tables exist" 
            : "Found " . count($found_tables) . "/" . count($required_tables) . " tables. Missing: " . implode(', ', $missing_tables),
        'total' => count($required_tables),
        'found' => count($found_tables),
        'missing' => count($missing_tables),
        'existing_count' => count($existing_tables)
    ];
} catch (Exception $e) {
    $tests['tables'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
}

// TEST 7: Check Critical Columns
try {
    $critical_checks = [
        'users' => ['pos_pin', 'tenant_id'],
        'branches' => ['tenant_id', 'is_active', 'type'],
        'settings' => ['tenant_id', 'branch_id', 'key', 'value'],
        'pos_device_registry' => ['device_token', 'device_fingerprint', 'tenant_id', 'branch_id']
    ];
    
    $column_issues = [];
    $column_details = [];
    
    foreach ($critical_checks as $table => $columns) {
        // Check if table exists first
        if (!in_array($table, $existing_tables)) {
            $column_issues[$table] = "Table doesn't exist";
            continue;
        }
        
        // Get columns for this table with more details
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
            $table_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $existing_columns = array_column($table_columns, 'Field');
            
            // Store column details for debugging
            $column_details[$table] = $existing_columns;
            
            $missing_columns = array_diff($columns, $existing_columns);
            if (!empty($missing_columns)) {
                // Check for possible alternative names
                $alternatives = [];
                if ($table === 'branches' && in_array('branch_type', $existing_columns) && in_array('type', $missing_columns)) {
                    $alternatives[] = "'type' might be named 'branch_type'";
                    $missing_columns = array_diff($missing_columns, ['type']);
                }
                if ($table === 'settings') {
                    if (in_array('setting_key', $existing_columns) && in_array('key', $missing_columns)) {
                        $alternatives[] = "'key' might be named 'setting_key'";
                        $missing_columns = array_diff($missing_columns, ['key']);
                    }
                    if (in_array('setting_value', $existing_columns) && in_array('value', $missing_columns)) {
                        $alternatives[] = "'value' might be named 'setting_value'";
                        $missing_columns = array_diff($missing_columns, ['value']);
                    }
                }
                
                if (!empty($missing_columns)) {
                    $message = "Missing: " . implode(', ', $missing_columns);
                    if (!empty($alternatives)) {
                        $message .= " (Note: " . implode('; ', $alternatives) . ")";
                    }
                    $column_issues[$table] = $message;
                } elseif (!empty($alternatives)) {
                    $column_issues[$table] = "Alternative names found: " . implode('; ', $alternatives);
                }
            }
        } catch (Exception $e) {
            $column_issues[$table] = "Error checking columns: " . $e->getMessage();
        }
    }
    
    $tests['columns'] = [
        'status' => empty($column_issues) ? 'PASS' : (count($column_issues) <= 1 ? 'WARNING' : 'FAIL'),
        'message' => empty($column_issues) 
            ? "All critical columns exist in checked tables" 
            : "Issues found in " . count($column_issues) . " table(s)",
        'issues' => $column_issues,
        'details' => $column_details
    ];
} catch (Exception $e) {
    $tests['columns'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
}

// TEST 8: Table Name Pattern Analysis
try {
    $patterns = [
        'pos_' => 'POS System Tables',
        'order_' => 'Order Management Tables',
        'variation_' => 'Product Variation Tables',
        'product_' => 'Product Related Tables',
        'payment_' => 'Payment Tables',
        'cash_' => 'Cash Management Tables'
    ];
    
    $pattern_results = [];
    foreach ($patterns as $prefix => $description) {
        $matching = array_filter($existing_tables, function($table) use ($prefix) {
            return strpos($table, $prefix) === 0;
        });
        if (count($matching) > 0) {
            $pattern_results[$description] = $matching;
        }
    }
    
    $tests['table_patterns'] = [
        'status' => 'INFO',
        'message' => "Found " . count($existing_tables) . " total tables organized by patterns",
        'patterns' => $pattern_results
    ];
} catch (Exception $e) {
    $tests['table_patterns'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Device Setup Test - SME 180 POS</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1a202c;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
            margin-bottom: 30px;
        }
        h2 {
            color: #2d3748;
            margin-top: 0;
        }
        .test-section {
            background: #f7fafc;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .test-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .test-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-pass {
            background: #48bb78;
            color: white;
        }
        .badge-fail {
            background: #f56565;
            color: white;
        }
        .badge-warning {
            background: #ed8936;
            color: white;
        }
        .badge-info {
            background: #4299e1;
            color: white;
        }
        .test-details {
            color: #4a5568;
            font-size: 14px;
            line-height: 1.6;
        }
        .branch-list {
            background: white;
            border-radius: 6px;
            padding: 10px;
            margin-top: 10px;
        }
        .branch-item {
            padding: 8px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }
        .branch-item:last-child {
            border-bottom: none;
        }
        .form-section {
            background: #edf2f7;
            border-radius: 8px;
            padding: 25px;
            margin-top: 30px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
        }
        .form-input {
            width: 100%;
            padding: 10px;
            border: 2px solid #cbd5e0;
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.3s;
            box-sizing: border-box;
        }
        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover:not(:disabled) {
            background: #5a67d8;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-success {
            background: #48bb78;
            color: white;
        }
        .btn-success:hover:not(:disabled) {
            background: #38a169;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 187, 120, 0.4);
        }
        .btn-warning {
            background: #ed8936;
            color: white;
        }
        .btn-warning:hover:not(:disabled) {
            background: #dd6b20;
        }
        .response-box {
            background: #2d3748;
            color: #68d391;
            padding: 15px;
            border-radius: 6px;
            margin-top: 20px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }
        .response-box::-webkit-scrollbar {
            width: 8px;
        }
        .response-box::-webkit-scrollbar-track {
            background: #1a202c;
        }
        .response-box::-webkit-scrollbar-thumb {
            background: #4a5568;
            border-radius: 4px;
        }
        .error { color: #fc8181; }
        .success { color: #68d391; }
        .warning { color: #f6e05e; }
        .info { color: #63b3ed; }
        code {
            background: #edf2f7;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        .status-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .status-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .status-card.pass {
            border-left: 4px solid #48bb78;
        }
        .status-card.fail {
            border-left: 4px solid #f56565;
        }
        .status-card.warning {
            border-left: 4px solid #ed8936;
        }
        .status-count {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .status-label {
            font-size: 14px;
            color: #718096;
        }
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 SME 180 Device Setup Test Suite</h1>
        
        <?php
        // Calculate summary
        $passed = count(array_filter($tests, function($t) { return $t['status'] === 'PASS'; }));
        $failed = count(array_filter($tests, function($t) { return $t['status'] === 'FAIL'; }));
        $warning = count(array_filter($tests, function($t) { return $t['status'] === 'WARNING'; }));
        $info = count(array_filter($tests, function($t) { return $t['status'] === 'INFO'; }));
        $total = count($tests) - $info; // Don't count INFO tests in total
        ?>
        
        <div class="status-summary">
            <div class="status-card pass">
                <div class="status-count"><?= $passed ?></div>
                <div class="status-label">Tests Passed</div>
            </div>
            <?php if ($warning > 0): ?>
            <div class="status-card warning">
                <div class="status-count"><?= $warning ?></div>
                <div class="status-label">Warnings</div>
            </div>
            <?php endif; ?>
            <div class="status-card fail">
                <div class="status-count"><?= $failed ?></div>
                <div class="status-label">Tests Failed</div>
            </div>
            <div class="status-card">
                <div class="status-count"><?= $total ?></div>
                <div class="status-label">Total Tests</div>
            </div>
            <div class="status-card">
                <div class="status-count" style="color: <?= $failed === 0 ? '#48bb78' : '#f56565' ?>">
                    <?= $failed === 0 ? '✓' : '✗' ?>
                </div>
                <div class="status-label">Overall Status</div>
            </div>
        </div>
        
        <!-- Test Results -->
        <?php foreach ($tests as $name => $test): ?>
        <div class="test-section">
            <div class="test-header">
                <div class="test-title">
                    <?php
                    $titles = [
                        'db_connection' => '1. Database Connection',
                        'tenant' => '2. Tenant Check (REST001)',
                        'user' => '3. User Check (admin)',
                        'pin_verify' => '4. PIN Verification (1234)',
                        'branches' => '5. Branch Configuration',
                        'tables' => '6. Database Tables',
                        'columns' => '7. Critical Columns Check',
                        'table_patterns' => '8. Table Pattern Analysis'
                    ];
                    echo $titles[$name] ?? ucfirst(str_replace('_', ' ', $name));
                    ?>
                </div>
                <span class="badge badge-<?= strtolower($test['status']) ?>">
                    <?= $test['status'] ?>
                </span>
            </div>
            <div class="test-details">
                <?= $test['message'] ?>
                
                <?php if ($name === 'user' && isset($test['has_pin'])): ?>
                    <br>Has PIN: <strong><?= $test['has_pin'] ?></strong>
                <?php endif; ?>
                
                <?php if ($name === 'pin_verify' && isset($test['hash_length'])): ?>
                    <br>Hash length: <strong><?= $test['hash_length'] ?> characters</strong>
                <?php endif; ?>
                
                <?php if ($name === 'tables' && isset($test['existing_count'])): ?>
                    <br>Total tables in database: <strong><?= $test['existing_count'] ?></strong>
                <?php endif; ?>
                
                <?php if ($name === 'branches' && !empty($test['list'])): ?>
                    <div class="branch-list">
                        <?php foreach ($test['list'] as $branch): ?>
                            <div class="branch-item">
                                <span>
                                    <strong><?= htmlspecialchars($branch['name']) ?></strong> 
                                    (ID: <?= $branch['id'] ?>)
                                </span>
                                <span>
                                    <?php if ($branch['user_id']): ?>
                                        <span class="badge badge-pass">Linked</span>
                                    <?php else: ?>
                                        <span class="badge badge-fail">Not Linked</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- API Test Form -->
        <div class="form-section">
            <h2>🔧 Test Device Setup API</h2>
            
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Restaurant Code</label>
                    <input type="text" id="tenant_code" class="form-input" value="REST001" />
                </div>
                
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" id="username" class="form-input" value="admin" />
                </div>
                
                <div class="form-group">
                    <label class="form-label">PIN</label>
                    <input type="password" id="pin" class="form-input" value="1234" maxlength="6" />
                </div>
            </div>
            
            <div class="btn-group">
                <button onclick="testValidation()" class="btn btn-primary" id="btnValidate">
                    Test Validation API
                </button>
                
                <button onclick="testDirectAPI()" class="btn btn-success" id="btnDirect">
                    Test Direct API Call
                </button>
                
                <button onclick="clearResponse()" class="btn btn-warning">
                    Clear Results
                </button>
                
                <?php if (!isset($_GET['debug'])): ?>
                <a href="?debug=1" class="btn" style="background: #9ca3af; color: white;">
                    Enable Debug Mode
                </a>
                <?php else: ?>
                <a href="?" class="btn" style="background: #9ca3af; color: white;">
                    Disable Debug Mode
                </a>
                <?php endif; ?>
            </div>
            
            <div id="response" class="response-box" style="display: none;"></div>
        </div>
    </div>
    
    <script>
        async function testValidation() {
            const btn = document.getElementById('btnValidate');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading"></span> Testing...';
            
            const responseBox = document.getElementById('response');
            responseBox.style.display = 'block';
            responseBox.innerHTML = '<span class="info">Testing validation API...</span>\n\n';
            
            const tenantCode = document.getElementById('tenant_code').value;
            const username = document.getElementById('username').value;
            const pin = document.getElementById('pin').value;
            
            const data = {
                tenant_code: tenantCode,
                username: username,
                pin: pin,
                device_fingerprint: 'test-fingerprint-' + Date.now()
            };
            
            responseBox.innerHTML += '<span class="info">Sending data:</span>\n' + JSON.stringify(data, null, 2) + '\n\n';
            
            try {
                const response = await fetch('/pos/api/device/validate_setup.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                const text = await response.text();
                let result;
                
                try {
                    result = JSON.parse(text);
                } catch (e) {
                    responseBox.innerHTML += '<span class="error">ERROR: Response is not JSON</span>\n';
                    responseBox.innerHTML += '<span class="warning">Raw response:</span>\n' + text;
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    return;
                }
                
                responseBox.innerHTML += '<span class="info">Response status:</span> ' + response.status + '\n\n';
                responseBox.innerHTML += '<span class="info">Response data:</span>\n' + JSON.stringify(result, null, 2);
                
                if (result.success) {
                    responseBox.innerHTML = '<span class="success">✅ SUCCESS!</span>\n\n' + responseBox.innerHTML;
                } else {
                    responseBox.innerHTML = '<span class="error">❌ FAILED: ' + (result.error || 'Unknown error') + '</span>\n\n' + responseBox.innerHTML;
                }
                
            } catch (error) {
                responseBox.innerHTML += '\n<span class="error">Network error: ' + error.message + '</span>';
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }
        
        async function testDirectAPI() {
            const btn = document.getElementById('btnDirect');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading"></span> Testing...';
            
            const responseBox = document.getElementById('response');
            responseBox.style.display = 'block';
            responseBox.innerHTML = '<span class="info">Testing direct API call...</span>\n\n';
            
            const data = {
                tenant_code: document.getElementById('tenant_code').value,
                username: document.getElementById('username').value,
                pin: document.getElementById('pin').value
            };
            
            responseBox.innerHTML += '<span class="info">Sending data:</span>\n' + JSON.stringify(data, null, 2) + '\n\n';
            
            try {
                // Use the correct path for the test API
                const response = await fetch('/pos/api/test_device.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                const text = await response.text();
                let result;
                
                try {
                    result = JSON.parse(text);
                    
                    if (result.success) {
                        responseBox.innerHTML += '<span class="success">✅ TEST DEVICE API PASSED!</span>\n\n';
                        responseBox.innerHTML += '<span class="info">Message:</span> ' + (result.message || 'Success') + '\n';
                        responseBox.innerHTML += '<span class="info">Tenant:</span> ' + (result.data?.tenant?.name || 'Unknown') + '\n';
                        responseBox.innerHTML += '<span class="info">User:</span> ' + (result.data?.user?.name || 'Unknown') + '\n';
                        responseBox.innerHTML += '<span class="info">Branches:</span> ' + (result.data?.branches?.length || 0) + ' found\n\n';
                        responseBox.innerHTML += '<span class="info">Full Response:</span>\n' + JSON.stringify(result, null, 2);
                    } else {
                        responseBox.innerHTML += '<span class="error">❌ TEST FAILED</span>\n\n';
                        responseBox.innerHTML += '<span class="error">Error:</span> ' + (result.error || 'Unknown error') + '\n';
                        if (result.debug) {
                            responseBox.innerHTML += '<span class="warning">Debug Info:</span>\n' + JSON.stringify(result.debug, null, 2) + '\n';
                        }
                        responseBox.innerHTML += '\n<span class="info">Full Response:</span>\n' + JSON.stringify(result, null, 2);
                    }
                } catch (e) {
                    responseBox.innerHTML += '<span class="error">ERROR: Response is not valid JSON</span>\n';
                    responseBox.innerHTML += '<span class="warning">HTTP Status:</span> ' + response.status + '\n';
                    responseBox.innerHTML += '<span class="warning">Raw response (first 500 chars):</span>\n' + text.substring(0, 500);
                    if (text.length > 500) {
                        responseBox.innerHTML += '\n... (truncated)';
                    }
                }
                
            } catch (error) {
                responseBox.innerHTML += '\n<span class="error">Network Error: ' + error.message + '</span>';
                responseBox.innerHTML += '\n<span class="info">Make sure /pos/api/test_device.php exists and is accessible.</span>';
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }
        
        function clearResponse() {
            const responseBox = document.getElementById('response');
            responseBox.style.display = 'none';
            responseBox.innerHTML = '';
        }
        
        // Auto-uppercase restaurant code
        document.getElementById('tenant_code').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
        
        // Show/hide password
        document.getElementById('pin').addEventListener('dblclick', function() {
            this.type = this.type === 'password' ? 'text' : 'password';
        });
    </script>
</body>
</html>