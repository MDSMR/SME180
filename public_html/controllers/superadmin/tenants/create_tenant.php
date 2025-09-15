<?php
// /controllers/superadmin/tenants/create_tenant.php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/db.php';

// Check if user is super admin
use_backend_session();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'super_admin') {
    redirect('/views/auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/views/superadmin/tenants/create.php');
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Validate required fields
    $tenant_name = trim($_POST['tenant_name'] ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $admin_username = trim($_POST['admin_username'] ?? '');
    $branch_name = trim($_POST['branch_name'] ?? 'Main Branch');
    $subscription_plan = $_POST['subscription_plan'] ?? 'starter';
    
    if (empty($tenant_name) || empty($contact_email) || empty($admin_username)) {
        throw new Exception('Required fields are missing');
    }
    
    // Validate email
    if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address');
    }
    
    // Check if tenant name already exists
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE name = :name");
    $stmt->execute([':name' => $tenant_name]);
    if ($stmt->fetch()) {
        throw new Exception('A tenant with this name already exists');
    }
    
    // Set subscription limits based on plan
    $plans = [
        'starter' => ['branches' => 1, 'users' => 5, 'products' => 50],
        'professional' => ['branches' => 3, 'users' => 20, 'products' => 500],
        'enterprise' => ['branches' => 999, 'users' => 999, 'products' => 9999],
        'custom' => [
            'branches' => (int)($_POST['custom_max_branches'] ?? 1),
            'users' => (int)($_POST['custom_max_users'] ?? 10),
            'products' => (int)($_POST['custom_max_products'] ?? 100)
        ]
    ];
    
    $limits = $plans[$subscription_plan] ?? $plans['starter'];
    
    // Calculate subscription expiry
    $duration_days = (int)($_POST['subscription_duration'] ?? 365);
    $expires_at = $duration_days > 0 
        ? date('Y-m-d H:i:s', strtotime("+{$duration_days} days"))
        : null;
    
    // Create tenant
    $stmt = $pdo->prepare("
        INSERT INTO tenants (
            name, 
            is_active,
            subscription_plan,
            max_branches,
            max_users,
            max_products,
            subscription_expires_at,
            contact_name,
            contact_email,
            contact_phone,
            business_type,
            timezone,
            currency,
            created_by,
            created_at
        ) VALUES (
            :name, 
            1,
            :plan,
            :max_branches,
            :max_users,
            :max_products,
            :expires_at,
            :contact_name,
            :contact_email,
            :contact_phone,
            :business_type,
            :timezone,
            :currency,
            :created_by,
            NOW()
        )
    ");
    
    $stmt->execute([
        ':name' => $tenant_name,
        ':plan' => $subscription_plan,
        ':max_branches' => $limits['branches'],
        ':max_users' => $limits['users'],
        ':max_products' => $limits['products'],
        ':expires_at' => $expires_at,
        ':contact_name' => $contact_name,
        ':contact_email' => $contact_email,
        ':contact_phone' => $_POST['contact_phone'] ?? null,
        ':business_type' => $_POST['business_type'] ?? 'restaurant',
        ':timezone' => $_POST['timezone'] ?? 'Africa/Cairo',
        ':currency' => $_POST['currency'] ?? 'EGP',
        ':created_by' => $_SESSION['super_admin_id'] ?? null
    ]);
    
    $tenant_id = (int)$pdo->lastInsertId();
    
    // Store features
    $features = $_POST['features'] ?? [];
    if ($subscription_plan === 'enterprise') {
        $features = ['all'];
    }
    
    foreach ($features as $feature) {
        $stmt = $pdo->prepare("
            INSERT INTO tenant_settings (tenant_id, setting_key, setting_value)
            VALUES (:tenant_id, :key, :value)
        ");
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':key' => 'feature_' . $feature,
            ':value' => '1'
        ]);
    }
    
    // Create initial branch
    $stmt = $pdo->prepare("
        INSERT INTO branches (
            tenant_id,
            name,
            display_name,
            branch_type,
            is_production_enabled,
            timezone,
            is_active,
            created_at
        ) VALUES (
            :tenant_id,
            :name,
            :display_name,
            :type,
            :production,
            :timezone,
            1,
            NOW()
        )
    ");
    
    $branch_type = $_POST['branch_type'] ?? 'sales_branch';
    $stmt->execute([
        ':tenant_id' => $tenant_id,
        ':name' => $branch_name,
        ':display_name' => $branch_name,
        ':type' => $branch_type,
        ':production' => in_array($branch_type, ['central_kitchen', 'mixed']) ? 1 : 0,
        ':timezone' => $_POST['timezone'] ?? 'Africa/Cairo'
    ]);
    
    $branch_id = (int)$pdo->lastInsertId();
    
    // Generate temporary password
    $temp_password = 'Welcome' . rand(1000, 9999) . '!';
    $password_hash = password_hash($temp_password, PASSWORD_DEFAULT);
    
    // Create admin user
    $stmt = $pdo->prepare("
        INSERT INTO users (
            tenant_id,
            name,
            username,
            email,
            password_hash,
            role_key,
            force_password_change,
            created_at
        ) VALUES (
            :tenant_id,
            :name,
            :username,
            :email,
            :password,
            'admin',
            1,
            NOW()
        )
    ");
    
    $stmt->execute([
        ':tenant_id' => $tenant_id,
        ':name' => $contact_name,
        ':username' => $admin_username,
        ':email' => $contact_email,
        ':password' => $password_hash
    ]);
    
    $user_id = (int)$pdo->lastInsertId();
    
    // Link user to tenant
    $stmt = $pdo->prepare("
        INSERT INTO user_tenants (user_id, tenant_id, is_primary)
        VALUES (:user_id, :tenant_id, 1)
    ");
    $stmt->execute([
        ':user_id' => $user_id,
        ':tenant_id' => $tenant_id
    ]);
    
    // Link user to branch
    $stmt = $pdo->prepare("
        INSERT INTO user_branches (user_id, branch_id, tenant_id)
        VALUES (:user_id, :branch_id, :tenant_id)
    ");
    $stmt->execute([
        ':user_id' => $user_id,
        ':branch_id' => $branch_id,
        ':tenant_id' => $tenant_id
    ]);
    
    // Create default categories
    $default_categories = [
        ['name' => 'Food', 'sort_order' => 1],
        ['name' => 'Beverages', 'sort_order' => 2],
        ['name' => 'Desserts', 'sort_order' => 3]
    ];
    
    foreach ($default_categories as $category) {
        $stmt = $pdo->prepare("
            INSERT INTO categories (tenant_id, name_en, sort_order, is_active)
            VALUES (:tenant_id, :name, :sort, 1)
        ");
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':name' => $category['name'],
            ':sort' => $category['sort_order']
        ]);
    }
    
    // Create default payment methods
    $payment_methods = [
        ['key' => 'cash', 'name' => 'Cash', 'is_cash' => 1],
        ['key' => 'card', 'name' => 'Credit/Debit Card', 'is_cash' => 0],
        ['key' => 'wallet', 'name' => 'Digital Wallet', 'is_cash' => 0]
    ];
    
    foreach ($payment_methods as $method) {
        $stmt = $pdo->prepare("
            INSERT INTO payment_methods (
                tenant_id, payment_key, name_en, 
                is_cash, is_active, sort_order
            ) VALUES (
                :tenant_id, :key, :name,
                :is_cash, 1, :sort
            )
        ");
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':key' => $method['key'],
            ':name' => $method['name'],
            ':is_cash' => $method['is_cash'],
            ':sort' => array_search($method, $payment_methods) + 1
        ]);
    }
    
    // Log the creation
    $stmt = $pdo->prepare("
        INSERT INTO super_admin_logs (
            admin_id, action, entity_type, entity_id,
            details, created_at
        ) VALUES (
            :admin_id, 'create', 'tenant', :tenant_id,
            :details, NOW()
        )
    ");
    $stmt->execute([
        ':admin_id' => $_SESSION['super_admin_id'] ?? 0,
        ':tenant_id' => $tenant_id,
        ':details' => json_encode([
            'tenant_name' => $tenant_name,
            'plan' => $subscription_plan,
            'admin_user' => $admin_username
        ])
    ]);
    
    $pdo->commit();
    
    // Send email with credentials (if email service is configured)
    // TODO: Implement email sending
    
    // Store success message with credentials
    $_SESSION['success'] = "Tenant '{$tenant_name}' created successfully!<br>" .
                           "Admin Username: {$admin_username}<br>" .
                           "Temporary Password: {$temp_password}<br>" .
                           "Please save these credentials and share them with the tenant.";
    
    redirect('/views/superadmin/tenants/view.php?id=' . $tenant_id);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $_SESSION['error'] = 'Error creating tenant: ' . $e->getMessage();
    redirect('/views/superadmin/tenants/create.php');
}