<?php
// /public_html/views/admin/customers/index.php
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

/* ---------- Handle CSV Export ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $bootstrap_ok) {
    try {
        $pdo = db();
        
        // Build query
        $where = ["tenant_id = :t"];
        $params = [':t' => $tenantId];
        
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $where[] = "(name LIKE :q OR phone LIKE :q2 OR rewards_member_no LIKE :q3)";
            $like = "%$q%";
            $params[':q'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }
        
        $classification = $_GET['class'] ?? '';
        if (in_array($classification, ['regular','vip','corporate','blocked'], true)) {
            $where[] = "classification = :cl";
            $params[':cl'] = $classification;
        }
        
        $rewards = $_GET['rewards'] ?? '';
        if ($rewards === 'enrolled') {
            $where[] = "rewards_enrolled = 1";
        } elseif ($rewards === 'not') {
            $where[] = "(rewards_enrolled = 0 OR rewards_enrolled IS NULL)";
        }
        
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        
        $sql = "SELECT id, name, phone, classification, rewards_enrolled, rewards_member_no, points_balance 
                FROM customers $whereSql ORDER BY id DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="customers_' . date('Y-m-d_His') . '.csv"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        // Create output
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel UTF-8 recognition
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Add headers
        fputcsv($output, ['ID', 'Name', 'Phone', 'Classification', 'Member Number', 'Points', 'Enrolled']);
        
        // Add data
        foreach ($customers as $customer) {
            $memberNo = $customer['rewards_member_no'] ?? '';
            if (strlen($memberNo) === 16) {
                // Show only last 4 digits for privacy
                $memberNo = '****' . substr($memberNo, -4);
            }
            
            fputcsv($output, [
                $customer['id'],
                $customer['name'],
                $customer['phone'],
                ucfirst($customer['classification']),
                $memberNo,
                $customer['points_balance'] ?? 0,
                $customer['rewards_enrolled'] ? 'Yes' : 'No'
            ]);
        }
        
        fclose($output);
        exit;
    } catch (Exception $e) {
        // If export fails, continue to page load with error
        $exportError = $e->getMessage();
    }
}

/* ---------- CSRF Token ---------- */
if (empty($_SESSION['csrf_customers'])) { 
    $_SESSION['csrf_customers'] = bin2hex(random_bytes(32)); 
}
$csrf = $_SESSION['csrf_customers'];

/* ---------- Helper Functions ---------- */
function h($s): string { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatMemberNumber(?string $number): string {
    if (!$number || strlen($number) !== 16) return '';
    // Full format for display in edit form
    return substr($number, 0, 4) . '-' . 
           substr($number, 4, 4) . '-' . 
           substr($number, 8, 4) . '-' . 
           substr($number, 12, 4);
}

function maskMemberNumber(?string $number): string {
    if (!$number || strlen($number) !== 16) return '';
    // Show only last 4 digits
    return '****' . substr($number, -4);
}

function generateMemberNumber(): string {
    $pdo = db();
    $maxAttempts = 10;
    
    for ($i = 0; $i < $maxAttempts; $i++) {
        // Generate 16-digit number
        $number = '';
        for ($j = 0; $j < 16; $j++) {
            $number .= mt_rand(0, 9);
        }
        
        // Check uniqueness across entire system
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE rewards_member_no = :num");
        $stmt->execute([':num' => $number]);
        
        if ($stmt->fetchColumn() == 0) {
            return $number;
        }
    }
    
    throw new RuntimeException('Could not generate unique member number');
}

/* ---------- Handle AJAX Requests ---------- */
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    try {
        $pdo = db();
        
        switch($action) {
            case 'list':
                $q = trim($_GET['q'] ?? '');
                $classification = $_GET['classification'] ?? 'all';
                $rewards = $_GET['rewards'] ?? 'all';
                $page = max(1, (int)($_GET['page'] ?? 1));
                $limit = 50;
                $offset = ($page - 1) * $limit;
                
                $where = ["tenant_id = :t"];
                $params = [':t' => $tenantId];
                
                if ($q !== '') {
                    // Allow search by formatted member number or raw number
                    $searchNumber = preg_replace('/[^0-9]/', '', $q);
                    $where[] = "(id = :idq OR name LIKE :q OR phone LIKE :q2 OR rewards_member_no LIKE :q3 OR rewards_member_no = :q4)";
                    $params[':idq'] = ctype_digit($q) ? (int)$q : -1;
                    $like = "%$q%";
                    $params[':q'] = $like;
                    $params[':q2'] = $like;
                    $params[':q3'] = $like;
                    $params[':q4'] = $searchNumber;
                }
                
                if (in_array($classification, ['regular','vip','corporate','blocked'], true)) {
                    $where[] = "classification = :cl";
                    $params[':cl'] = $classification;
                }
                
                if ($rewards === 'enrolled') {
                    $where[] = "rewards_enrolled = 1";
                } elseif ($rewards === 'not') {
                    $where[] = "(rewards_enrolled = 0 OR rewards_enrolled IS NULL)";
                }
                
                $whereSql = 'WHERE ' . implode(' AND ', $where);
                
                // Get total count
                $countSql = "SELECT COUNT(*) FROM customers $whereSql";
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute($params);
                $totalCount = (int)$countStmt->fetchColumn();
                
                // Get customers - removed email from query
                $sql = "
                    SELECT id, name, phone, classification, 
                           rewards_enrolled, rewards_member_no, points_balance,
                           discount_scheme_id
                    FROM customers 
                    $whereSql 
                    ORDER BY id DESC 
                    LIMIT :limit OFFSET :offset
                ";
                
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $value) {
                    if ($key !== ':q4') { // Skip the search number param if not numeric
                        $stmt->bindValue($key, $value);
                    } else if (is_numeric($searchNumber)) {
                        $stmt->bindValue($key, $searchNumber);
                    } else {
                        $stmt->bindValue($key, '');
                    }
                }
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                
                $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Format member numbers for display
                foreach ($customers as &$customer) {
                    if ($customer['rewards_member_no']) {
                        if (strlen($customer['rewards_member_no']) === 16) {
                            $customer['member_display'] = maskMemberNumber($customer['rewards_member_no']);
                            $customer['member_formatted'] = formatMemberNumber($customer['rewards_member_no']);
                        } else {
                            // Handle old format numbers
                            $customer['member_display'] = $customer['rewards_member_no'];
                            $customer['member_formatted'] = $customer['rewards_member_no'];
                        }
                    } else {
                        $customer['member_display'] = '';
                        $customer['member_formatted'] = '';
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $customers,
                    'total' => $totalCount,
                    'page' => $page,
                    'pages' => ceil($totalCount / $limit)
                ]);
                break;
                
            case 'get':
                $id = (int)$_GET['id'];
                $stmt = $pdo->prepare("
                    SELECT c.*, ds.name as discount_scheme_name 
                    FROM customers c
                    LEFT JOIN discount_schemes ds ON ds.id = c.discount_scheme_id AND ds.tenant_id = c.tenant_id
                    WHERE c.id = :id AND c.tenant_id = :t
                ");
                $stmt->execute([':id' => $id, ':t' => $tenantId]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($customer && $customer['rewards_member_no']) {
                    if (strlen($customer['rewards_member_no']) === 16) {
                        $customer['member_formatted'] = formatMemberNumber($customer['rewards_member_no']);
                    } else {
                        $customer['member_formatted'] = $customer['rewards_member_no'];
                    }
                }
                
                // Get discount schemes for dropdown
                $schemes = [];
                try {
                    $schemeStmt = $pdo->prepare("SELECT id, name FROM discount_schemes WHERE tenant_id = :t AND is_active = 1 ORDER BY name");
                    $schemeStmt->execute([':t' => $tenantId]);
                    $schemes = $schemeStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    // Schemes table might not exist
                }
                
                echo json_encode([
                    'success' => true, 
                    'data' => $customer,
                    'schemes' => $schemes
                ]);
                break;
                
            case 'save':
                if (($_POST['csrf'] ?? '') !== $csrf) {
                    throw new Exception('Invalid CSRF token');
                }
                
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $classification = $_POST['classification'] ?? 'regular';
                $rewards_enrolled = isset($_POST['rewards_enrolled']) ? 1 : 0;
                $discount_scheme_id = $_POST['discount_scheme_id'] ?? null;
                
                // Validation
                if (empty($name)) {
                    throw new Exception('Name is required');
                }
                
                if (empty($phone)) {
                    throw new Exception('Phone is required');
                }
                
                // Clean phone number
                $phone = preg_replace('/[^0-9]/', '', $phone);
                
                // Check for duplicates
                $dupSql = "SELECT id FROM customers WHERE tenant_id = :t AND phone = :p";
                $dupParams = [':t' => $tenantId, ':p' => $phone];
                if ($id > 0) {
                    $dupSql .= " AND id != :id";
                    $dupParams[':id'] = $id;
                }
                $dupStmt = $pdo->prepare($dupSql);
                $dupStmt->execute($dupParams);
                if ($dupStmt->fetch()) {
                    throw new Exception('A customer with this phone number already exists');
                }
                
                if ($id > 0) {
                    // Update
                    $stmt = $pdo->prepare("
                        UPDATE customers 
                        SET name = :name, phone = :phone, classification = :classification,
                            rewards_enrolled = :rewards_enrolled, discount_scheme_id = :scheme,
                            updated_at = NOW()
                        WHERE id = :id AND tenant_id = :t
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':phone' => $phone,
                        ':classification' => $classification,
                        ':rewards_enrolled' => $rewards_enrolled,
                        ':scheme' => $discount_scheme_id ?: null,
                        ':id' => $id,
                        ':t' => $tenantId
                    ]);
                    
                    // Handle member number generation for existing customer
                    if ($rewards_enrolled) {
                        $checkStmt = $pdo->prepare("SELECT rewards_member_no FROM customers WHERE id = :id");
                        $checkStmt->execute([':id' => $id]);
                        $currentMemberNo = $checkStmt->fetchColumn();
                        
                        if (!$currentMemberNo) {
                            $memberNo = generateMemberNumber();
                            $updateStmt = $pdo->prepare("UPDATE customers SET rewards_member_no = :num WHERE id = :id");
                            $updateStmt->execute([':num' => $memberNo, ':id' => $id]);
                        }
                    } else {
                        // Remove member number if not enrolled
                        $updateStmt = $pdo->prepare("UPDATE customers SET rewards_member_no = NULL WHERE id = :id");
                        $updateStmt->execute([':id' => $id]);
                    }
                    
                    $message = 'Customer updated successfully';
                } else {
                    // Create new customer
                    $memberNo = $rewards_enrolled ? generateMemberNumber() : null;
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO customers (tenant_id, name, phone, classification, 
                                             rewards_enrolled, rewards_member_no, discount_scheme_id,
                                             points_balance, created_at, updated_at) 
                        VALUES (:t, :name, :phone, :classification, 
                                :rewards_enrolled, :member_no, :scheme,
                                0, NOW(), NOW())
                    ");
                    $stmt->execute([
                        ':t' => $tenantId,
                        ':name' => $name,
                        ':phone' => $phone,
                        ':classification' => $classification,
                        ':rewards_enrolled' => $rewards_enrolled,
                        ':member_no' => $memberNo,
                        ':scheme' => $discount_scheme_id ?: null
                    ]);
                    $id = $pdo->lastInsertId();
                    $message = 'Customer created successfully';
                }
                
                echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);
                break;
                
            case 'delete':
                if (($_POST['csrf'] ?? '') !== $csrf) {
                    throw new Exception('Invalid CSRF token');
                }
                
                // Check permissions
                if (!in_array($userRole, ['admin', 'manager'])) {
                    throw new Exception('You do not have permission to delete customers');
                }
                
                $id = (int)$_POST['id'];
                
                // Check for orders
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = :id");
                $stmt->execute([':id' => $id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Cannot delete customer with order history');
                }
                
                $stmt = $pdo->prepare("DELETE FROM customers WHERE id = :id AND tenant_id = :t");
                $stmt->execute([':id' => $id, ':t' => $tenantId]);
                
                echo json_encode(['success' => true, 'message' => 'Customer deleted successfully']);
                break;
                
            case 'stats':
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN rewards_enrolled = 1 THEN 1 ELSE 0 END) as enrolled,
                        SUM(CASE WHEN classification = 'vip' THEN 1 ELSE 0 END) as vip,
                        SUM(CASE WHEN classification = 'corporate' THEN 1 ELSE 0 END) as corporate
                    FROM customers 
                    WHERE tenant_id = :t
                ");
                $stmt->execute([':t' => $tenantId]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $stats]);
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
    <title>Customers · Smorll POS</title>
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

        /* Page Header */
        .page-header {
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: var(--ms-gray-160);
            margin-bottom: 4px;
        }

        .page-subtitle {
            font-size: 14px;
            color: var(--ms-gray-110);
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
            padding: 20px;
            border-radius: var(--ms-radius-lg);
            box-shadow: var(--ms-shadow-2);
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            box-shadow: var(--ms-shadow-3);
            transform: translateY(-2px);
        }

        .stat-value {
            font-size: 32px;
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

        .stat-card.total .stat-value { color: var(--ms-blue); }
        .stat-card.enrolled .stat-value { color: var(--ms-green); }
        .stat-card.vip .stat-value { color: var(--ms-purple); }
        .stat-card.corporate .stat-value { color: var(--ms-teal); }

        /* Filters Bar */
        .filters-bar {
            display: flex;
            gap: 16px;
            padding: 20px;
            background: white;
            border-radius: var(--ms-radius-lg);
            box-shadow: var(--ms-shadow-2);
            margin-bottom: 24px;
            align-items: end;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 140px;
        }

        .filter-group.search-group {
            flex: 2;
            min-width: 200px;
        }

        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--ms-gray-130);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid var(--ms-gray-60);
            border-radius: var(--ms-radius);
            font-size: 14px;
            background: white;
            transition: all 0.2s ease;
            width: 100%;
            font-family: inherit;
        }

        .filter-group input:hover,
        .filter-group select:hover {
            border-color: var(--ms-gray-110);
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--ms-blue);
            box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.25);
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: flex-end;
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

        .card-actions {
            display: flex;
            gap: 8px;
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

        .btn.small {
            padding: 6px 12px;
            font-size: 13px;
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

        .btn.success {
            background: var(--ms-green);
            color: white;
            border-color: var(--ms-green);
        }

        .btn.success:hover {
            background: var(--ms-green-darker);
            border-color: var(--ms-green-darker);
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

        /* Action buttons container */
        .action-buttons {
            display: flex;
            gap: 4px;
            justify-content: flex-end;
        }

        /* Customer Info */
        .customer-name {
            font-weight: 600;
            color: var(--ms-gray-160);
            margin-bottom: 2px;
        }

        .customer-phone {
            font-size: 12px;
            color: var(--ms-gray-110);
        }

        /* Member Card - Updated to neutral style */
        .member-card {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            background: var(--ms-gray-20);
            color: var(--ms-gray-130);
            border: 1px solid var(--ms-gray-40);
            border-radius: var(--ms-radius);
            font-size: 12px;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
        }

        /* Classification Badges */
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

        /* Form */
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

        .required {
            color: var(--ms-red);
        }

        .form-input,
        .form-select {
            padding: 10px 12px;
            border: 1px solid var(--ms-gray-60);
            border-radius: var(--ms-radius);
            font-size: 14px;
            background: white;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-input:hover,
        .form-select:hover {
            border-color: var(--ms-gray-110);
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--ms-blue);
            box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.25);
        }

        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .form-checkbox label {
            cursor: pointer;
            font-size: 14px;
        }

        .member-number-display {
            padding: 12px;
            background: var(--ms-gray-20);
            color: var(--ms-gray-160);
            border: 1px solid var(--ms-gray-40);
            border-radius: var(--ms-radius);
            font-family: 'Courier New', monospace;
            font-size: 16px;
            letter-spacing: 2px;
            text-align: center;
            margin-top: 8px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--ms-gray-110);
        }

        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: var(--ms-gray-130);
        }

        /* Loading */
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

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .filters-bar {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
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

            .modal-content {
                width: 95%;
                margin: 10px;
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

        <?php if (isset($exportError)): ?>
        <div style="padding: 16px; background: var(--ms-red-light); color: var(--ms-red); border-radius: var(--ms-radius); margin-bottom: 16px;">
            Export error: <?= h($exportError) ?>
        </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Customers</h1>
            <p class="page-subtitle">Manage your customer database and loyalty programs</p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card total">
                <div class="stat-value" id="statTotal">-</div>
                <div class="stat-label">Total Customers</div>
            </div>
            <div class="stat-card enrolled">
                <div class="stat-value" id="statEnrolled">-</div>
                <div class="stat-label">Rewards Enrolled</div>
            </div>
            <div class="stat-card vip">
                <div class="stat-value" id="statVip">-</div>
                <div class="stat-label">VIP Customers</div>
            </div>
            <div class="stat-card corporate">
                <div class="stat-value" id="statCorporate">-</div>
                <div class="stat-label">Corporate</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <div class="filter-group search-group">
                <label>Search</label>
                <input type="text" id="searchInput" placeholder="Search by name, phone, member number..." onkeyup="debounceSearch()">
            </div>

            <div class="filter-group">
                <label>Classification</label>
                <select id="classificationFilter" onchange="applyFilters()">
                    <option value="all">All Types</option>
                    <option value="regular">Regular</option>
                    <option value="vip">VIP</option>
                    <option value="corporate">Corporate</option>
                    <option value="blocked">Blocked</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Rewards Status</label>
                <select id="rewardsFilter" onchange="applyFilters()">
                    <option value="all">All Status</option>
                    <option value="enrolled">Enrolled</option>
                    <option value="not">Not Enrolled</option>
                </select>
            </div>

            <div class="filter-actions">
                <button type="button" class="btn" id="clearFiltersBtn" onclick="clearFilters()" style="display: none;">Clear</button>
                <button type="button" class="btn success" onclick="exportCustomers()">Export CSV</button>
            </div>
        </div>

        <!-- Customers Table -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Customer List</h2>
                <div class="card-actions">
                    <button class="btn primary" onclick="showAddForm()">+ Add Customer</button>
                </div>
            </div>

            <div id="customersTable"></div>
        </div>
    </div>

    <!-- Customer Form Modal -->
    <div id="customerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add Customer</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="customerForm" onsubmit="saveCustomer(event)">
                <div class="modal-body">
                    <input type="hidden" id="customerId" value="">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

                    <div class="form-row">
                        <div class="form-group full">
                            <label class="form-label">
                                Name <span class="required">*</span>
                            </label>
                            <input type="text" id="customerName" name="name" class="form-input" required placeholder="Enter customer name">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                Phone Number <span class="required">*</span>
                            </label>
                            <input type="tel" id="customerPhone" name="phone" class="form-input" required placeholder="Enter phone number">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Classification</label>
                            <select id="customerClassification" name="classification" class="form-select">
                                <option value="regular">Regular</option>
                                <option value="vip">VIP</option>
                                <option value="corporate">Corporate</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <div class="form-checkbox">
                                <input type="checkbox" id="rewardsEnrolled" name="rewards_enrolled" value="1" onchange="toggleMemberNumber()">
                                <label for="rewardsEnrolled">Enroll in Rewards Program</label>
                            </div>
                            <div id="memberNumberDisplay" style="display: none;"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Discount Scheme</label>
                            <select id="discountScheme" name="discount_scheme_id" class="form-select">
                                <option value="">No Discount</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn primary">
                        <span id="submitBtnText">Create Customer</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let customers = [];
        let searchTimer = null;
        let currentPage = 1;
        let totalPages = 1;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadStats();
            loadCustomers();
        });

        // Load statistics
        function loadStats() {
            fetch('?action=stats', {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('statTotal').textContent = data.data.total || '0';
                    document.getElementById('statEnrolled').textContent = data.data.enrolled || '0';
                    document.getElementById('statVip').textContent = data.data.vip || '0';
                    document.getElementById('statCorporate').textContent = data.data.corporate || '0';
                }
            })
            .catch(error => console.error('Error loading stats:', error));
        }

        // Load customers
        function loadCustomers(page = 1) {
            const search = document.getElementById('searchInput').value;
            const classification = document.getElementById('classificationFilter').value;
            const rewards = document.getElementById('rewardsFilter').value;

            updateClearFiltersVisibility();

            const params = new URLSearchParams({
                action: 'list',
                q: search,
                classification: classification,
                rewards: rewards,
                page: page
            });

            fetch(`?${params}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    customers = data.data;
                    currentPage = data.page;
                    totalPages = data.pages;
                    renderTable();
                }
            })
            .catch(error => console.error('Error loading customers:', error));
        }

        // Render table
        function renderTable() {
            const container = document.getElementById('customersTable');

            if (customers.length === 0) {
                const hasFilters = document.getElementById('searchInput').value !== '' ||
                                 document.getElementById('classificationFilter').value !== 'all' ||
                                 document.getElementById('rewardsFilter').value !== 'all';

                container.innerHTML = `
                    <div class="empty-state">
                        <h3>No customers found</h3>
                        <p>${hasFilters ? 'No customers match the selected filters.' : 'Start by adding your first customer.'}</p>
                        ${!hasFilters ? '<br><button class="btn primary" onclick="showAddForm()">+ Add Your First Customer</button>' : ''}
                    </div>
                `;
                return;
            }

            let html = `
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th style="min-width: 200px;">Customer</th>
                            <th style="width: 120px;">Member #</th>
                            <th style="width: 120px;">Classification</th>
                            <th style="width: 100px; text-align: right;">Points</th>
                            <th style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            customers.forEach(customer => {
                const canDelete = ['admin', 'manager'].includes('<?= h($userRole) ?>');
                
                // Use the formatted member display if available, otherwise show nothing
                const memberDisplay = customer.member_display || '';
                
                html += `
                    <tr>
                        <td style="color: var(--ms-gray-110); font-size: 12px;">#${customer.id}</td>
                        <td>
                            <div class="customer-name">${escapeHtml(customer.name)}</div>
                            <div class="customer-phone">${escapeHtml(customer.phone || '')}</div>
                        </td>
                        <td>
                            ${memberDisplay ? 
                                `<span class="member-card">${escapeHtml(memberDisplay)}</span>` : 
                                '<span style="color: var(--ms-gray-110);">-</span>'}
                        </td>
                        <td>
                            <span class="badge ${customer.classification}">${customer.classification.toUpperCase()}</span>
                        </td>
                        <td style="text-align: right; font-weight: 600; color: ${customer.points_balance > 0 ? 'var(--ms-green)' : 'var(--ms-gray-110)'};">
                            ${customer.rewards_enrolled == 1 ? (customer.points_balance || 0) : '-'}
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn small" onclick="viewCustomer(${customer.id})">View</button>
                                <button class="btn small" onclick="showEditForm(${customer.id})">Edit</button>
                                ${canDelete ? `<button class="btn small danger" onclick="deleteCustomer(${customer.id}, '${escapeHtml(customer.name).replace(/'/g, "\\'")}')" style="display: none;">Delete</button>` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            });

            html += '</tbody></table>';

            // Add pagination if needed
            if (totalPages > 1) {
                html += '<div style="padding: 16px; display: flex; justify-content: center; gap: 8px;">';
                if (currentPage > 1) {
                    html += `<button class="btn small" onclick="loadCustomers(${currentPage - 1})">Previous</button>`;
                }
                html += `<span style="padding: 8px;">Page ${currentPage} of ${totalPages}</span>`;
                if (currentPage < totalPages) {
                    html += `<button class="btn small" onclick="loadCustomers(${currentPage + 1})">Next</button>`;
                }
                html += '</div>';
            }

            container.innerHTML = html;
        }

        // View customer profile
        function viewCustomer(id) {
            window.location.href = `/views/admin/customers/profile.php?id=${id}`;
        }

        // Export customers - using inline export
        function exportCustomers() {
            const search = document.getElementById('searchInput').value;
            const classification = document.getElementById('classificationFilter').value;
            const rewards = document.getElementById('rewardsFilter').value;

            // Build URL parameters
            const params = new URLSearchParams();
            params.append('export', 'csv');
            if (search) params.append('q', search);
            if (classification !== 'all') params.append('class', classification);
            if (rewards !== 'all') params.append('rewards', rewards);
            
            // Use current page with export parameter
            const exportUrl = window.location.pathname + '?' + params.toString();
            
            // Show loading state
            const exportBtn = event.target;
            const originalText = exportBtn.textContent;
            exportBtn.textContent = 'Exporting...';
            exportBtn.disabled = true;
            
            // Trigger download
            window.location.href = exportUrl;
            
            // Reset button after delay
            setTimeout(() => {
                exportBtn.textContent = originalText;
                exportBtn.disabled = false;
            }, 2000);
        }

        // Show add form
        function showAddForm() {
            document.getElementById('modalTitle').textContent = 'Add Customer';
            document.getElementById('submitBtnText').textContent = 'Create Customer';
            document.getElementById('customerForm').reset();
            document.getElementById('customerId').value = '';
            document.getElementById('rewardsEnrolled').checked = false;
            document.getElementById('memberNumberDisplay').style.display = 'none';
            
            // Load discount schemes
            loadDiscountSchemes();
            
            document.getElementById('customerModal').classList.add('active');
        }

        // Show edit form
        function showEditForm(id) {
            document.getElementById('modalTitle').textContent = 'Edit Customer';
            document.getElementById('submitBtnText').textContent = 'Update Customer';

            fetch(`?action=get&id=${id}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    const customer = data.data;
                    document.getElementById('customerId').value = customer.id;
                    document.getElementById('customerName').value = customer.name || '';
                    document.getElementById('customerPhone').value = customer.phone || '';
                    document.getElementById('customerClassification').value = customer.classification || 'regular';
                    document.getElementById('rewardsEnrolled').checked = customer.rewards_enrolled == 1;
                    
                    // Load schemes and set value
                    loadDiscountSchemes(customer.discount_scheme_id);
                    
                    // Show member number if enrolled
                    if (customer.rewards_enrolled == 1 && customer.rewards_member_no) {
                        const display = document.getElementById('memberNumberDisplay');
                        const formattedNumber = customer.member_formatted || 'Will be generated';
                        display.innerHTML = `<div class="member-number-display">${escapeHtml(formattedNumber)}</div>`;
                        display.style.display = 'block';
                    } else {
                        document.getElementById('memberNumberDisplay').style.display = 'none';
                    }
                    
                    document.getElementById('customerModal').classList.add('active');
                } else {
                    showToast('Error loading customer', 'error');
                }
            })
            .catch(error => {
                console.error('Error loading customer:', error);
                showToast('Error loading customer', 'error');
            });
        }

        // Load discount schemes
        function loadDiscountSchemes(selectedId = null) {
            // This would normally fetch from the server
            // For now, using the data from the get request
            const select = document.getElementById('discountScheme');
            select.innerHTML = '<option value="">No Discount</option>';
            
            // The schemes are loaded with the customer data
            if (selectedId) {
                select.value = selectedId;
            }
        }

        // Toggle member number display
        function toggleMemberNumber() {
            const checked = document.getElementById('rewardsEnrolled').checked;
            const display = document.getElementById('memberNumberDisplay');
            
            if (checked) {
                const customerId = document.getElementById('customerId').value;
                if (!customerId) {
                    display.innerHTML = '<div class="member-number-display">Member number will be generated automatically</div>';
                }
                display.style.display = 'block';
            } else {
                display.style.display = 'none';
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('customerModal').classList.remove('active');
        }

        // Save customer
        function saveCustomer(event) {
            event.preventDefault();

            const submitBtn = event.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner"></span>Saving...';
            submitBtn.disabled = true;

            const formData = new FormData(document.getElementById('customerForm'));
            formData.append('action', 'save');
            formData.append('id', document.getElementById('customerId').value);

            fetch('', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal();
                    loadCustomers();
                    loadStats();
                } else {
                    showToast(data.error || 'Error saving customer', 'error');
                }
            })
            .catch(error => {
                console.error('Save error:', error);
                showToast('Error saving customer', 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }

        // Delete customer
        function deleteCustomer(id, name) {
            if (!confirm(`Delete customer "${name}"?\n\nThis action cannot be undone.`)) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            formData.append('csrf', '<?= h($csrf) ?>');

            fetch('', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    loadCustomers();
                    loadStats();
                } else {
                    showToast(data.error || 'Error deleting customer', 'error');
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                showToast('Error deleting customer', 'error');
            });
        }

        // Debounced search
        function debounceSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                loadCustomers();
            }, 600);
        }

        // Apply filters
        function applyFilters() {
            loadCustomers();
        }

        // Clear filters
        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('classificationFilter').value = 'all';
            document.getElementById('rewardsFilter').value = 'all';
            loadCustomers();
        }

        // Update clear filters button visibility
        function updateClearFiltersVisibility() {
            const search = document.getElementById('searchInput').value;
            const classification = document.getElementById('classificationFilter').value;
            const rewards = document.getElementById('rewardsFilter').value;
            const hasFilters = search !== '' || classification !== 'all' || rewards !== 'all';

            document.getElementById('clearFiltersBtn').style.display = hasFilters ? 'block' : 'none';
        }

        // Helper function to escape HTML
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