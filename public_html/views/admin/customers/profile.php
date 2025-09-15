<?php
// /public_html/views/admin/customers/profile.php
declare(strict_types=1);

/* ---------- Bootstrap ---------- */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { 
    @ini_set('display_errors','1'); 
    @ini_set('display_startup_errors','1'); 
    error_reporting(E_ALL); 
} else { 
    @ini_set('display_errors','0'); 
}

$bootstrap_ok = false; 
$bootstrap_msg = '';

try {
    $configPath = dirname(__DIR__, 3) . '/config/db.php';
    if (!is_file($configPath)) throw new RuntimeException('Configuration file not found: /config/db.php');
    require_once $configPath;

    if (function_exists('use_backend_session')) { 
        use_backend_session(); 
    } else { 
        if (session_status() !== PHP_SESSION_ACTIVE) session_start(); 
    }

    $authPath = dirname(__DIR__, 3) . '/middleware/auth_login.php';
    if (!is_file($authPath)) throw new RuntimeException('Auth middleware not found');
    require_once $authPath;
    auth_require_login();

    if (!function_exists('db')) throw new RuntimeException('db() not available from config.php');
    $bootstrap_ok = true;
} catch (Throwable $e) { 
    $bootstrap_msg = $e->getMessage(); 
}

/* ---------- Session / Tenant ---------- */
$user = $_SESSION['user'] ?? null;
if (!$user && $bootstrap_ok) { 
    header('Location: /views/auth/login.php'); 
    exit; 
}
$tenantId = (int)($user['tenant_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);
$userRole = $user['role_key'] ?? '';

/* ---------- CSRF Token ---------- */
if (empty($_SESSION['csrf_customer_profile'])) { 
    $_SESSION['csrf_customer_profile'] = bin2hex(random_bytes(32)); 
}
$csrf = $_SESSION['csrf_customer_profile'];

/* ---------- Customer ID ---------- */
$customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$customerId && $bootstrap_ok) {
    header('Location: /views/admin/customers/index.php');
    exit;
}

/* ---------- Helper Functions ---------- */
function h($s): string { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate(?string $dt): string {
    if (!$dt) return '-';
    $t = strtotime($dt);
    if ($t <= 0) return '-';
    return date('d M Y', $t);
}

function formatDateTime(?string $dt): string {
    if (!$dt) return '-';
    $t = strtotime($dt);
    if ($t <= 0) return '-';
    return date('d M Y H:i', $t);
}

/* ---------- Handle AJAX Requests ---------- */
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    try {
        $pdo = db();
        
        switch($action) {
            case 'load':
                $tab = $_GET['tab'] ?? 'orders';
                
                // Load customer with discount scheme info
                $stmt = $pdo->prepare("
                    SELECT c.*, ds.name as discount_scheme_name 
                    FROM customers c
                    LEFT JOIN discount_schemes ds ON ds.id = c.discount_scheme_id AND ds.tenant_id = c.tenant_id
                    WHERE c.id = :id AND c.tenant_id = :t
                ");
                $stmt->execute([':id' => $customerId, ':t' => $tenantId]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$customer) {
                    throw new Exception('Customer not found');
                }
                
                // Always load KPIs for stats cards
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) AS total_orders,
                        COALESCE(SUM(total_amount), 0) AS lifetime_value,
                        COALESCE(AVG(total_amount), 0) AS avg_order_value,
                        MAX(created_at) AS last_order_date,
                        MIN(created_at) AS first_order_date
                    FROM orders 
                    WHERE tenant_id = :t 
                        AND customer_id = :cid 
                        AND is_deleted = 0
                        AND is_voided = 0
                ");
                $stmt->execute([':t' => $tenantId, ':cid' => $customerId]);
                $kpis = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Calculate days since last order
                if ($kpis['last_order_date']) {
                    $lastDate = strtotime($kpis['last_order_date']);
                    $kpis['days_since_last'] = floor((time() - $lastDate) / 86400);
                } else {
                    $kpis['days_since_last'] = null;
                }
                
                // Order type breakdown
                $stmt = $pdo->prepare("
                    SELECT order_type, COUNT(*) as count
                    FROM orders
                    WHERE tenant_id = :t AND customer_id = :cid AND is_deleted = 0
                    GROUP BY order_type
                ");
                $stmt->execute([':t' => $tenantId, ':cid' => $customerId]);
                $orderTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $data = [
                    'customer' => $customer,
                    'kpis' => $kpis,
                    'order_types' => $orderTypes
                ];
                
                // Load tab-specific data
                switch($tab) {
                    case 'orders':
                        $page = max(1, (int)($_GET['page'] ?? 1));
                        $limit = 20;
                        $offset = ($page - 1) * $limit;
                        
                        // Count total
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) 
                            FROM orders 
                            WHERE tenant_id = :t AND customer_id = :cid AND is_deleted = 0
                        ");
                        $stmt->execute([':t' => $tenantId, ':cid' => $customerId]);
                        $total = $stmt->fetchColumn();
                        
                        // Get orders with calculated items count
                        $stmt = $pdo->prepare("
                            SELECT o.id, o.created_at, o.order_type, o.status, o.payment_status, 
                                   o.total_amount, o.branch_id,
                                   (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as items_count,
                                   b.name as branch_name
                            FROM orders o
                            LEFT JOIN branches b ON b.id = o.branch_id
                            WHERE o.tenant_id = :t AND o.customer_id = :cid AND o.is_deleted = 0
                            ORDER BY o.id DESC
                            LIMIT :limit OFFSET :offset
                        ");
                        $stmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
                        $stmt->bindValue(':cid', $customerId, PDO::PARAM_INT);
                        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                        $stmt->execute();
                        
                        $data['orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $data['pagination'] = [
                            'total' => $total,
                            'page' => $page,
                            'pages' => ceil($total / $limit)
                        ];
                        break;
                        
                    case 'notes':
                        try {
                            $stmt = $pdo->prepare("
                                SELECT cn.*, u.name as created_by_name
                                FROM customer_notes cn
                                LEFT JOIN users u ON u.id = cn.created_by
                                WHERE cn.tenant_id = :t AND cn.customer_id = :cid
                                ORDER BY cn.id DESC
                            ");
                            $stmt->execute([':t' => $tenantId, ':cid' => $customerId]);
                            $data['notes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {
                            $data['notes'] = [];
                        }
                        break;
                }
                
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'update_info':
                if (($_POST['csrf'] ?? '') !== $csrf) {
                    throw new Exception('Invalid CSRF token');
                }
                
                $name = trim($_POST['name'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $classification = $_POST['classification'] ?? 'regular';
                $discount_scheme_id = $_POST['discount_scheme_id'] ?? null;
                
                if (empty($name)) {
                    throw new Exception('Name is required');
                }
                
                if (empty($phone)) {
                    throw new Exception('Phone is required');
                }
                
                // Clean phone
                $phone = preg_replace('/[^0-9]/', '', $phone);
                
                // Check for duplicate phone
                $stmt = $pdo->prepare("
                    SELECT id FROM customers 
                    WHERE tenant_id = :t AND phone = :p AND id != :id
                ");
                $stmt->execute([':t' => $tenantId, ':p' => $phone, ':id' => $customerId]);
                if ($stmt->fetch()) {
                    throw new Exception('Another customer already has this phone number');
                }
                
                // Update
                $stmt = $pdo->prepare("
                    UPDATE customers 
                    SET name = :name, phone = :phone, email = :email, 
                        classification = :classification, discount_scheme_id = :scheme,
                        updated_at = NOW()
                    WHERE id = :id AND tenant_id = :t
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':phone' => $phone,
                    ':email' => $email,
                    ':classification' => $classification,
                    ':scheme' => $discount_scheme_id ?: null,
                    ':id' => $customerId,
                    ':t' => $tenantId
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Customer information updated']);
                break;
                
            case 'add_note':
                if (($_POST['csrf'] ?? '') !== $csrf) {
                    throw new Exception('Invalid CSRF token');
                }
                
                $note = trim($_POST['note'] ?? '');
                
                if (empty($note)) {
                    throw new Exception('Note cannot be empty');
                }
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO customer_notes 
                        (tenant_id, customer_id, note, created_by, created_at)
                        VALUES (:t, :cid, :note, :uid, NOW())
                    ");
                    $stmt->execute([
                        ':t' => $tenantId,
                        ':cid' => $customerId,
                        ':note' => $note,
                        ':uid' => $userId
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'Note added successfully']);
                } catch (Exception $e) {
                    // Create table if not exists
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS customer_notes (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            tenant_id INT NOT NULL,
                            customer_id INT NOT NULL,
                            note TEXT,
                            created_by INT,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            INDEX idx_customer (tenant_id, customer_id)
                        )
                    ");
                    
                    // Try again
                    $stmt = $pdo->prepare("
                        INSERT INTO customer_notes 
                        (tenant_id, customer_id, note, created_by, created_at)
                        VALUES (:t, :cid, :note, :uid, NOW())
                    ");
                    $stmt->execute([
                        ':t' => $tenantId,
                        ':cid' => $customerId,
                        ':note' => $note,
                        ':uid' => $userId
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'Note added successfully']);
                }
                break;
                
            case 'delete_note':
                if (($_POST['csrf'] ?? '') !== $csrf) {
                    throw new Exception('Invalid CSRF token');
                }
                
                $noteId = (int)($_POST['note_id'] ?? 0);
                
                // Check permission
                if (!in_array($userRole, ['admin', 'manager'])) {
                    throw new Exception('Insufficient permissions');
                }
                
                $stmt = $pdo->prepare("
                    DELETE FROM customer_notes 
                    WHERE id = :id AND tenant_id = :t AND customer_id = :cid
                ");
                $stmt->execute([
                    ':id' => $noteId,
                    ':t' => $tenantId,
                    ':cid' => $customerId
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Note deleted']);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$active = 'customers_view';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Profile · Smorll POS</title>
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
            --ms-green-darker: #0e5e0e;
            
            --ms-red: #d13438;
            --ms-red-light: #fdf2f2;
            --ms-red-darker: #a80000;
            
            --ms-yellow: #ffb900;
            --ms-yellow-light: #fff4ce;
            
            --ms-purple: #5c2d91;
            --ms-purple-light: #f3e8ff;
            
            --ms-teal: #008272;
            --ms-teal-light: #ccfbf1;
            
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

        /* Container */
        .container {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .profile-header {
            background: white;
            border-radius: var(--ms-radius-lg);
            padding: 24px;
            box-shadow: var(--ms-shadow-2);
            margin-bottom: 20px;
        }

        .profile-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            flex-wrap: wrap;
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            font-size: 28px;
            font-weight: 600;
            color: var(--ms-gray-160);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .profile-meta {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            color: var(--ms-gray-110);
            font-size: 13px;
        }

        .profile-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Badges */
        .badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge.regular {
            background: var(--ms-gray-20);
            color: var(--ms-gray-130);
        }

        .badge.vip {
            background: var(--ms-purple-light);
            color: var(--ms-purple);
        }

        .badge.corporate {
            background: var(--ms-teal-light);
            color: var(--ms-teal);
        }

        .badge.blocked {
            background: var(--ms-red-light);
            color: var(--ms-red);
        }

        .badge.enrolled {
            background: var(--ms-green-light);
            color: var(--ms-green);
        }

        /* Compact Stats Cards with Colors */
        .stats-row {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
            overflow-x: auto;
        }

        .stat-card-compact {
            background: white;
            padding: 16px 20px;
            border-radius: var(--ms-radius-lg);
            box-shadow: var(--ms-shadow-2);
            flex: 1;
            min-width: 140px;
            border-left: 3px solid;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card-compact:hover {
            transform: translateY(-2px);
            box-shadow: var(--ms-shadow-3);
        }

        .stat-card-compact.orders {
            border-left-color: var(--ms-blue);
        }

        .stat-card-compact.orders .stat-value-compact {
            color: var(--ms-blue);
        }

        .stat-card-compact.lifetime {
            border-left-color: var(--ms-green);
        }

        .stat-card-compact.lifetime .stat-value-compact {
            color: var(--ms-green);
        }

        .stat-card-compact.average {
            border-left-color: var(--ms-purple);
        }

        .stat-card-compact.average .stat-value-compact {
            color: var(--ms-purple);
        }

        .stat-card-compact.days {
            border-left-color: var(--ms-yellow);
        }

        .stat-card-compact.days .stat-value-compact {
            color: var(--ms-yellow);
        }

        .stat-value-compact {
            font-size: 22px;
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .stat-label-compact {
            font-size: 11px;
            font-weight: 600;
            color: var(--ms-gray-110);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .stat-subtitle-compact {
            font-size: 10px;
            color: var(--ms-gray-110);
            margin-top: 2px;
        }

        /* Tabs - Simplified */
        .tabs {
            display: flex;
            gap: 2px;
            background: var(--ms-gray-20);
            padding: 2px;
            border-radius: var(--ms-radius-lg);
            margin-bottom: 24px;
            max-width: 400px;
        }

        .tab {
            flex: 1;
            padding: 10px 20px;
            background: transparent;
            border: none;
            border-radius: var(--ms-radius);
            font-size: 14px;
            font-weight: 500;
            color: var(--ms-gray-130);
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
            text-align: center;
        }

        .tab:hover {
            background: var(--ms-gray-30);
        }

        .tab.active {
            background: white;
            color: var(--ms-gray-160);
            box-shadow: var(--ms-shadow-1);
        }

        /* Card */
        .card {
            background: white;
            border-radius: var(--ms-radius-lg);
            box-shadow: var(--ms-shadow-2);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--ms-gray-30);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--ms-gray-160);
        }

        .card-body {
            padding: 24px;
        }

        .card-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--ms-gray-30);
            background: var(--ms-gray-10);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: var(--ms-radius);
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.1s ease;
            border: 1px solid transparent;
            background: var(--ms-gray-20);
            color: var(--ms-gray-160);
            justify-content: center;
            font-family: inherit;
            white-space: nowrap;
        }

        .btn:hover {
            background: var(--ms-gray-30);
        }

        .btn.primary {
            background: var(--ms-blue);
            color: white;
            border-color: var(--ms-blue);
        }

        .btn.primary:hover {
            background: var(--ms-blue-hover);
            border-color: var(--ms-blue-hover);
        }

        .btn.success {
            background: var(--ms-green);
            color: white;
            border-color: var(--ms-green);
        }

        .btn.success:hover {
            background: var(--ms-green-darker);
            border-color: var(--ms-green-darker);
        }

        .btn.danger {
            background: white;
            color: var(--ms-red);
            border: 1px solid var(--ms-gray-30);
        }

        .btn.danger:hover {
            background: var(--ms-red-light);
            border-color: var(--ms-red);
        }

        .btn.small {
            padding: 6px 12px;
            font-size: 13px;
        }

        /* Table */
        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: var(--ms-gray-20);
            padding: 12px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: var(--ms-gray-130);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--ms-gray-30);
        }

        .table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--ms-gray-20);
            font-size: 14px;
        }

        .table tbody tr:hover {
            background: var(--ms-gray-10);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Forms */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--ms-gray-130);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-input,
        .form-select,
        .form-textarea {
            padding: 10px 12px;
            border: 1px solid var(--ms-gray-60);
            border-radius: var(--ms-radius);
            font-size: 14px;
            background: white;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-input:hover,
        .form-select:hover,
        .form-textarea:hover {
            border-color: var(--ms-gray-110);
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--ms-blue);
            box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.25);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--ms-gray-110);
        }

        .empty-state h3 {
            font-size: 16px;
            margin-bottom: 8px;
            color: var(--ms-gray-130);
        }

        /* Loading */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            color: var(--ms-gray-110);
        }

        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--ms-gray-40);
            border-top-color: var(--ms-blue);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 8px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            animation: fadeIn 0.2s ease;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: var(--ms-radius-lg);
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow: hidden;
            animation: slideIn 0.3s ease;
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
            overflow-y: auto;
            max-height: calc(90vh - 140px);
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--ms-gray-30);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .toast.success {
            border-left-color: var(--ms-green);
        }

        .toast.error {
            border-left-color: var(--ms-red);
        }

        /* Note */
        .note-item {
            background: var(--ms-gray-10);
            padding: 12px 16px;
            border-radius: var(--ms-radius);
            margin-bottom: 12px;
        }

        .note-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .note-meta {
            font-size: 12px;
            color: var(--ms-gray-110);
        }

        .note-content {
            color: var(--ms-gray-160);
            white-space: pre-wrap;
        }

        /* Status indicators */
        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }

        .status-indicator.success {
            background: var(--ms-green);
        }

        .status-indicator.warning {
            background: var(--ms-yellow);
        }

        .status-indicator.danger {
            background: var(--ms-red);
        }

        /* Order Type Breakdown */
        .order-types {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
            font-size: 11px;
        }

        .order-type-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .order-type-count {
            font-weight: 600;
            color: var(--ms-gray-160);
        }

        .order-type-label {
            color: var(--ms-gray-110);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }

            .profile-name {
                font-size: 22px;
            }

            .tabs {
                max-width: 100%;
            }

            .tab {
                padding: 8px 16px;
                font-size: 13px;
            }

            .stats-row {
                flex-wrap: wrap;
            }

            .stat-card-compact {
                min-width: calc(50% - 8px);
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .table {
                font-size: 12px;
            }

            .table th, .table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <?php
    try {
        require __DIR__ . '/../../partials/admin_nav.php';
    } catch (Throwable $e) {
        echo "<div style='padding: 16px; background: var(--ms-red-light); color: var(--ms-red);'>Navigation error: " . h($e->getMessage()) . "</div>";
    }
    ?>

    <div class="container">
        <div class="toast-container" id="toastContainer"></div>

        <!-- Profile Header -->
        <div class="profile-header" id="profileHeader">
            <div class="loading">
                <span class="spinner"></span> Loading customer profile...
            </div>
        </div>

        <!-- Compact Stats Cards with Colors -->
        <div class="stats-row" id="statsRow" style="display: none;">
            <div class="stat-card-compact orders">
                <div class="stat-value-compact" id="statOrders">-</div>
                <div class="stat-label-compact">Total Orders</div>
                <div class="stat-subtitle-compact" id="statOrdersSince">-</div>
            </div>
            <div class="stat-card-compact lifetime">
                <div class="stat-value-compact" id="statLifetime">-</div>
                <div class="stat-label-compact">Lifetime Value</div>
                <div class="order-types" id="orderTypesBreakdown"></div>
            </div>
            <div class="stat-card-compact average">
                <div class="stat-value-compact" id="statAverage">-</div>
                <div class="stat-label-compact">Average Order</div>
            </div>
            <div class="stat-card-compact days">
                <div class="stat-value-compact" id="statDaysSince">-</div>
                <div class="stat-label-compact">Days Since Last</div>
                <div class="stat-subtitle-compact" id="statLastDate">-</div>
            </div>
        </div>

        <!-- Simplified Tabs -->
        <div class="tabs">
            <button class="tab active" data-tab="orders" onclick="switchTab('orders')">Orders</button>
            <button class="tab" data-tab="notes" onclick="switchTab('notes')">Notes</button>
        </div>

        <!-- Tab Content -->
        <div id="tabContent">
            <div class="loading">
                <span class="spinner"></span> Loading...
            </div>
        </div>
    </div>

    <!-- Edit Info Modal -->
    <div id="editInfoModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Customer Information</h3>
                <button class="modal-close" onclick="closeModal('editInfoModal')">&times;</button>
            </div>
            <form id="editInfoForm" onsubmit="saveCustomerInfo(event)">
                <div class="modal-body">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    
                    <div class="form-row">
                        <div class="form-group full">
                            <label class="form-label">Name</label>
                            <input type="text" id="editName" name="name" class="form-input" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" id="editPhone" name="phone" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" id="editEmail" name="email" class="form-input">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Classification</label>
                            <select id="editClassification" name="classification" class="form-select">
                                <option value="regular">Regular</option>
                                <option value="vip">VIP</option>
                                <option value="corporate">Corporate</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Discount Scheme</label>
                            <select id="editDiscountScheme" name="discount_scheme_id" class="form-select">
                                <option value="">No Discount</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('editInfoModal')">Cancel</button>
                    <button type="submit" class="btn primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const customerId = <?= (int)$customerId ?>;
        let currentTab = 'orders';  // Default to orders tab
        let customerData = null;
        let currentKpis = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadCustomerData();
        });

        // Load customer data
        function loadCustomerData() {
            fetch(`?id=${customerId}&action=load&tab=${currentTab}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    customerData = data.data.customer;
                    currentKpis = data.data.kpis;
                    renderHeader();
                    renderStatsCards(data.data);
                    renderTabContent(data.data);
                } else {
                    showToast(data.error || 'Error loading customer', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error loading customer data', 'error');
            });
        }

        // Render header
        function renderHeader() {
            const header = document.getElementById('profileHeader');
            
            const classification = customerData.classification || 'regular';
            
            // Build badges
            let badgesHtml = `<span class="badge ${classification}">${classification.toUpperCase()}</span>`;
            
            if (customerData.rewards_enrolled == 1) {
                badgesHtml += ` <span class="badge enrolled">REWARDS MEMBER</span>`;
            }
            
            if (customerData.discount_scheme_name) {
                badgesHtml += ` <span class="badge" style="background: var(--ms-yellow-light); color: var(--ms-yellow);">${escapeHtml(customerData.discount_scheme_name)}</span>`;
            }
            
            header.innerHTML = `
                <div class="profile-top">
                    <div class="profile-info">
                        <div class="profile-name">
                            ${escapeHtml(customerData.name)}
                            <span style="font-size: 14px; color: var(--ms-gray-110);">#${customerData.id}</span>
                        </div>
                        <div class="profile-meta">
                            <span>📱 ${escapeHtml(customerData.phone || '-')}</span>
                            <span>Created: ${formatDate(customerData.created_at)}</span>
                            ${customerData.rewards_member_no ? `<span>Member: ****${customerData.rewards_member_no.slice(-4)}</span>` : ''}
                        </div>
                        <div class="badges">
                            ${badgesHtml}
                        </div>
                    </div>
                    <div class="profile-actions">
                        <button class="btn" onclick="showEditInfo()">Edit Info</button>
                        <a href="/views/admin/customers/rewards.php?id=${customerId}" class="btn success">View Rewards</a>
                        <a href="/views/admin/customers/index.php" class="btn">Back to List</a>
                    </div>
                </div>
            `;
        }

        // Render stats cards
        function renderStatsCards(data) {
            const kpis = data.kpis || {};
            const orderTypes = data.order_types || [];
            
            // Show stats row
            document.getElementById('statsRow').style.display = 'flex';
            
            // Update values
            document.getElementById('statOrders').textContent = kpis.total_orders || 0;
            document.getElementById('statOrdersSince').textContent = kpis.first_order_date ? `Since ${formatDate(kpis.first_order_date)}` : '';
            
            document.getElementById('statLifetime').textContent = formatNumber(kpis.lifetime_value || 0);
            
            // Order types breakdown
            if (orderTypes.length > 0) {
                let orderTypesHtml = '';
                orderTypes.forEach(type => {
                    orderTypesHtml += `
                        <div class="order-type-item">
                            <span class="order-type-count">${type.count}</span>
                            <span class="order-type-label">${escapeHtml(type.order_type || 'Unknown')}</span>
                        </div>
                    `;
                });
                document.getElementById('orderTypesBreakdown').innerHTML = orderTypesHtml;
            }
            
            document.getElementById('statAverage').textContent = formatNumber(kpis.avg_order_value || 0);
            
            document.getElementById('statDaysSince').textContent = kpis.days_since_last !== null ? kpis.days_since_last : '-';
            document.getElementById('statLastDate').textContent = kpis.last_order_date ? formatDate(kpis.last_order_date) : '';
        }

        // Switch tab
        function switchTab(tab) {
            currentTab = tab;
            
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(t => {
                t.classList.toggle('active', t.dataset.tab === tab);
            });
            
            // Show loading
            document.getElementById('tabContent').innerHTML = '<div class="loading"><span class="spinner"></span> Loading...</div>';
            
            // Load tab data
            fetch(`?id=${customerId}&action=load&tab=${tab}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update stats cards if KPIs are included
                    if (data.data.kpis) {
                        currentKpis = data.data.kpis;
                        renderStatsCards(data.data);
                    }
                    renderTabContent(data.data);
                }
            });
        }

        // Render tab content
        function renderTabContent(data) {
            const container = document.getElementById('tabContent');
            
            switch(currentTab) {
                case 'orders':
                    renderOrders(container, data);
                    break;
                case 'notes':
                    renderNotes(container, data);
                    break;
            }
        }

        // Render orders tab
        function renderOrders(container, data) {
            const orders = data.orders || [];
            const pagination = data.pagination || {};
            
            container.innerHTML = `
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Order History</h3>
                        <span style="color: var(--ms-gray-110);">Total: ${pagination.total || 0} orders</span>
                    </div>
                    <div class="card-body">
                        ${renderOrdersTable(orders)}
                    </div>
                    ${pagination.pages > 1 ? `
                        <div class="card-footer">
                            <div style="display: flex; justify-content: center; gap: 8px;">
                                ${pagination.page > 1 ? `<button class="btn small" onclick="loadOrdersPage(${pagination.page - 1})">Previous</button>` : ''}
                                <span style="padding: 8px;">Page ${pagination.page} of ${pagination.pages}</span>
                                ${pagination.page < pagination.pages ? `<button class="btn small" onclick="loadOrdersPage(${pagination.page + 1})">Next</button>` : ''}
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
        }

        // Render notes tab
        function renderNotes(container, data) {
            const notes = data.notes || [];
            
            container.innerHTML = `
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Customer Notes</h3>
                    </div>
                    <div class="card-body">
                        <div style="margin-bottom: 24px;">
                            <form onsubmit="addNote(event)">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <div class="form-group">
                                    <label class="form-label">Add Note</label>
                                    <textarea name="note" class="form-textarea" required placeholder="Enter note about this customer..."></textarea>
                                </div>
                                <div style="display: flex; justify-content: flex-end; margin-top: 12px;">
                                    <button type="submit" class="btn primary">Add Note</button>
                                </div>
                            </form>
                        </div>
                        
                        <div>
                            ${notes.length > 0 ? notes.map(note => `
                                <div class="note-item">
                                    <div class="note-header">
                                        <div class="note-meta">
                                            ${escapeHtml(note.created_by_name || 'User #' + note.created_by)} · ${formatDateTime(note.created_at)}
                                        </div>
                                        ${['admin', 'manager'].includes('<?= h($userRole) ?>') ? `
                                            <button class="btn small danger" onclick="deleteNote(${note.id})">Delete</button>
                                        ` : ''}
                                    </div>
                                    <div class="note-content">${escapeHtml(note.note)}</div>
                                </div>
                            `).join('') : '<div class="empty-state"><h3>No notes yet</h3><p>Add notes to keep track of important customer information</p></div>'}
                        </div>
                    </div>
                </div>
            `;
        }

        // Render orders table
        function renderOrdersTable(orders) {
            if (orders.length === 0) {
                return '<div class="empty-state"><h3>No orders found</h3></div>';
            }
            
            return `
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Items</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${orders.map(order => `
                            <tr>
                                <td>#${order.id}</td>
                                <td>${formatDate(order.created_at)}</td>
                                <td>${escapeHtml(order.branch_name || 'Branch ' + order.branch_id)}</td>
                                <td>${order.items_count || 0}</td>
                                <td>${escapeHtml(order.order_type || '-')}</td>
                                <td>
                                    <span class="status-indicator ${order.status === 'closed' ? 'success' : 'warning'}"></span>
                                    ${escapeHtml(order.status || '-')}
                                </td>
                                <td style="text-align: right; font-weight: 600;">
                                    ${formatNumber(order.total_amount || 0)}
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }

        // Load orders page
        function loadOrdersPage(page) {
            fetch(`?id=${customerId}&action=load&tab=orders&page=${page}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderTabContent(data.data);
                }
            });
        }

        // Show edit info modal
        function showEditInfo() {
            document.getElementById('editName').value = customerData.name || '';
            document.getElementById('editPhone').value = customerData.phone || '';
            document.getElementById('editEmail').value = customerData.email || '';
            document.getElementById('editClassification').value = customerData.classification || 'regular';
            
            // Load discount schemes
            const schemeSelect = document.getElementById('editDiscountScheme');
            schemeSelect.innerHTML = '<option value="">No Discount</option>';
            if (customerData.discount_scheme_id) {
                schemeSelect.innerHTML += `<option value="${customerData.discount_scheme_id}" selected>${escapeHtml(customerData.discount_scheme_name || 'Scheme')}</option>`;
            }
            
            document.getElementById('editInfoModal').classList.add('active');
        }

        // Save customer info
        function saveCustomerInfo(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('action', 'update_info');
            
            fetch(`?id=${customerId}`, {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('editInfoModal');
                    loadCustomerData();
                } else {
                    showToast(data.error || 'Error updating customer', 'error');
                }
            });
        }

        // Add note
        function addNote(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('action', 'add_note');
            
            const submitBtn = event.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Adding...';
            submitBtn.disabled = true;
            
            fetch(`?id=${customerId}`, {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    event.target.reset();
                    switchTab('notes');
                } else {
                    showToast(data.error || 'Error adding note', 'error');
                }
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        }

        // Delete note
        function deleteNote(noteId) {
            if (!confirm('Delete this note? This action cannot be undone.')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete_note');
            formData.append('note_id', noteId);
            formData.append('csrf', '<?= h($csrf) ?>');
            
            fetch(`?id=${customerId}`, {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    switchTab('notes');
                } else {
                    showToast(data.error || 'Error deleting note', 'error');
                }
            });
        }

        // Close modal
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Format date
        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            const options = { day: 'numeric', month: 'short', year: 'numeric' };
            return date.toLocaleDateString('en-US', options);
        }

        // Format date time
        function formatDateTime(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            const options = { 
                day: 'numeric', 
                month: 'short', 
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            return date.toLocaleDateString('en-US', options);
        }

        // Format number (no currency symbol)
        function formatNumber(num) {
            return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        // Escape HTML
        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Toast notifications
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<span>${escapeHtml(message)}</span>`;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
            
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                showEditInfo();
            }
        });
    </script>

    <?php
    try {
        require __DIR__ . '/../../partials/admin_nav_close.php';
    } catch (Throwable $e) {
        // Silently fail if nav close not found
    }
    ?>
</body>
</html>