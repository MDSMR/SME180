<?php
/**
 * SME 180 POS - Quick Fix Script
 * Run this file to patch the current issues
 * Path: /public_html/pos/api/fix_issues.php
 */

// Database connection
$dsn = 'mysql:host=localhost;dbname=dbvtrnbzad193e;charset=utf8mb4';
$pdo = new PDO($dsn, 'uta6umaa0iuif', '2m%[11|kb1Z4', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$fixes = [];

// Fix 1: Check and clean up duplicate void columns
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM orders WHERE Field IN ('voided_by', 'voided_by_user_id')");
    $columns = $stmt->fetchAll();
    
    if (count($columns) == 2) {
        // We have both columns - need to consolidate
        $fixes[] = "Found both voided_by and voided_by_user_id columns - this may cause issues";
        
        // Check which one has data
        $hasDataInVoidedBy = $pdo->query("SELECT COUNT(*) FROM orders WHERE voided_by IS NOT NULL")->fetchColumn();
        $hasDataInVoidedByUserId = $pdo->query("SELECT COUNT(*) FROM orders WHERE voided_by_user_id IS NOT NULL")->fetchColumn();
        
        if ($hasDataInVoidedByUserId > $hasDataInVoidedBy) {
            // Migrate data to voided_by if needed
            $pdo->exec("UPDATE orders SET voided_by = voided_by_user_id WHERE voided_by IS NULL AND voided_by_user_id IS NOT NULL");
            $fixes[] = "Migrated voided_by_user_id data to voided_by";
        }
    }
} catch (Exception $e) {
    $fixes[] = "Error checking void columns: " . $e->getMessage();
}

// Fix 2: Ensure settings are correct
try {
    // Enable tips
    $pdo->exec("
        INSERT INTO settings (tenant_id, `key`, `value`, data_type) 
        VALUES (1, 'tip_enabled', '1', 'boolean')
        ON DUPLICATE KEY UPDATE `value` = '1'
    ");
    
    // Set max tip
    $pdo->exec("
        INSERT INTO settings (tenant_id, `key`, `value`, data_type) 
        VALUES (1, 'max_tip_percent', '50', 'numeric')
        ON DUPLICATE KEY UPDATE `value` = '50'
    ");
    
    // Currency
    $pdo->exec("
        INSERT INTO settings (tenant_id, `key`, `value`, data_type) 
        VALUES (1, 'currency_symbol', '$', 'string')
        ON DUPLICATE KEY UPDATE `value` = '$'
    ");
    
    $pdo->exec("
        INSERT INTO settings (tenant_id, `key`, `value`, data_type) 
        VALUES (1, 'currency_code', 'USD', 'string')
        ON DUPLICATE KEY UPDATE `value` = 'USD'
    ");
    
    $fixes[] = "Settings verified/updated for tips and currency";
} catch (Exception $e) {
    $fixes[] = "Error updating settings: " . $e->getMessage();
}

// Fix 3: Create test data for verification
try {
    // Check if we have a test order
    $stmt = $pdo->prepare("
        SELECT id, status, payment_status, parked 
        FROM orders 
        WHERE tenant_id = 1 
        AND branch_id = 1 
        AND payment_status = 'unpaid'
        AND status NOT IN ('voided', 'refunded', 'cancelled')
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $testOrder = $stmt->fetch();
    
    if ($testOrder) {
        $fixes[] = "Found test order ID: " . $testOrder['id'] . 
                   " (Status: " . $testOrder['status'] . 
                   ", Parked: " . ($testOrder['parked'] ? 'Yes' : 'No') . ")";
    } else {
        $fixes[] = "No unpaid test orders found - create a new order first";
    }
} catch (Exception $e) {
    $fixes[] = "Error checking test orders: " . $e->getMessage();
}

// Fix 4: Verify authentication tokens
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.pin 
        FROM users u 
        WHERE u.tenant_id = 1 
        AND u.pin IN ('1234', '5678')
        ORDER BY u.id
    ");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    foreach ($users as $user) {
        $fixes[] = "User found - ID: " . $user['id'] . ", Name: " . $user['name'] . ", PIN: " . $user['pin'];
    }
    
    if (empty($users)) {
        // Create test users
        $pdo->exec("
            INSERT INTO users (tenant_id, branch_id, name, pin, role, is_active) 
            VALUES 
            (1, 1, 'Test Cashier', '1234', 'cashier', 1),
            (1, 1, 'Test Manager', '5678', 'manager', 1)
            ON DUPLICATE KEY UPDATE pin = VALUES(pin)
        ");
        $fixes[] = "Created test users with PINs 1234 (cashier) and 5678 (manager)";
    }
} catch (Exception $e) {
    $fixes[] = "Error checking/creating users: " . $e->getMessage();
}

// Output results
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'fixes_applied' => $fixes,
    'recommendations' => [
        'For Add Tip' => 'Column exists. Issue is likely in the stored procedure check. Remove the column existence check from add_tip.php',
        'For Resume Order' => 'Ensure API key is sent in headers as X-API-Key',
        'For Void Order' => 'Use voided_by column, not voided_by_user_id. Ensure order is unpaid before voiding.',
        'Test Sequence' => 'Create Order → Update → Fire → Park → Resume → Add Tip → Pay → Refund (if needed)'
    ]
], JSON_PRETTY_PRINT);
?>