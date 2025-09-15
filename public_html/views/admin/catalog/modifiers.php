<?php
declare(strict_types=1);
/**
 * /public_html/views/admin/catalog/modifiers.php
 * Single Page Application for Modifiers Management - Modern Design
 */

// Bootstrap
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';
auth_require_login();

$bootstrap_ok = false;
$bootstrap_msg = '';

try {
    use_backend_session();
    $bootstrap_ok = true;
} catch (Throwable $e) {
    $bootstrap_msg = $e->getMessage();
}

$user = $_SESSION['user'] ?? null;
if (!$user && $bootstrap_ok) { 
    header('Location: /views/auth/login.php'); 
    exit; 
}
$tenantId = (int)($user['tenant_id'] ?? 0);

// CSRF token
if (empty($_SESSION['csrf_modifiers'])) { 
    $_SESSION['csrf_modifiers'] = bin2hex(random_bytes(32)); 
}
$csrf = $_SESSION['csrf_modifiers'];

// Handle AJAX requests - [PHP code remains unchanged]
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    try {
        $pdo = db();
        
        switch($action) {
            case 'list_modifiers':
                $q = trim($_GET['q'] ?? '');
                $status = $_GET['status'] ?? 'all';
                $vis = $_GET['vis'] ?? 'all';
                
                $where = ["tenant_id = :t"];
                $params = [':t' => $tenantId];
                
                if ($q !== '') {
                    $where[] = "(name LIKE :q)";
                    $params[':q'] = "%{$q}%";
                }
                if ($status === 'active') $where[] = "is_active = 1";
                if ($status === 'inactive') $where[] = "is_active = 0";
                if ($vis === 'visible') $where[] = "pos_visible = 1";
                if ($vis === 'hidden') $where[] = "pos_visible = 0";
                
                $sql = "SELECT * FROM variation_groups WHERE " . implode(' AND ', $where) . " ORDER BY sort_order, name LIMIT 200";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
                
            case 'list_values':
                $group_id = (int)$_GET['group_id'];
                
                $stmt = $pdo->prepare("SELECT * FROM variation_groups WHERE id = :id AND tenant_id = :t");
                $stmt->execute([':id' => $group_id, ':t' => $tenantId]);
                $group = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $pdo->prepare("SELECT * FROM variation_values WHERE group_id = :gid ORDER BY sort_order, value_en");
                $stmt->execute([':gid' => $group_id]);
                $values = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'group' => $group, 'values' => $values]);
                break;
                
            case 'get_modifier':
                $id = (int)$_GET['id'];
                $stmt = $pdo->prepare("SELECT * FROM variation_groups WHERE id = :id AND tenant_id = :t");
                $stmt->execute([':id' => $id, ':t' => $tenantId]);
                $modifier = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $modifier]);
                break;
                
            case 'get_value':
                $id = (int)$_GET['id'];
                $stmt = $pdo->prepare("SELECT * FROM variation_values WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $value = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $value]);
                break;
                
            case 'save_modifier':
                if ($_POST['csrf'] !== $csrf) {
                    throw new Exception('Invalid CSRF token');
                }
                
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $is_required = (int)($_POST['is_required'] ?? 0);
                $min_select = (int)($_POST['min_select'] ?? 0);
                $max_select = (int)($_POST['max_select'] ?? 1);
                $is_active = (int)($_POST['is_active'] ?? 1);
                $pos_visible = (int)($_POST['pos_visible'] ?? 1);
                $sort_order = (int)($_POST['sort_order'] ?? 999);
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE variation_groups 
                        SET name = :name, is_required = :is_required, 
                            min_select = :min_select, max_select = :max_select,
                            is_active = :is_active, pos_visible = :pos_visible,
                            sort_order = :sort_order
                        WHERE id = :id AND tenant_id = :t
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':is_required' => $is_required,
                        ':min_select' => $min_select,
                        ':max_select' => $max_select,
                        ':is_active' => $is_active,
                        ':pos_visible' => $pos_visible,
                        ':sort_order' => $sort_order,
                        ':id' => $id,
                        ':t' => $tenantId
                    ]);
                    $message = 'Modifier updated successfully';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO variation_groups (tenant_id, name, is_required, min_select, max_select, is_active, pos_visible, sort_order) 
                        VALUES (:t, :name, :is_required, :min_select, :max_select, :is_active, :pos_visible, :sort_order)
                    ");
                    $stmt->execute([
                        ':t' => $tenantId,
                        ':name' => $name,
                        ':is_required' => $is_required,
                        ':min_select' => $min_select,
                        ':max_select' => $max_select,
                        ':is_active' => $is_active,
                        ':pos_visible' => $pos_visible,
                        ':sort_order' => $sort_order
                    ]);
                    $id = $pdo->lastInsertId();
                    $message = 'Modifier created successfully';
                }
                
                echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);
                break;
                
            case 'save_value':
                if ($_POST['csrf'] !== $csrf) {
                    throw new Exception('Invalid CSRF token');
                }
                
                $id = (int)($_POST['id'] ?? 0);
                $group_id = (int)($_POST['group_id'] ?? 0);
                $value_en = trim($_POST['value_en'] ?? '');
                $value_ar = trim($_POST['value_ar'] ?? '');
                $price_delta = (float)($_POST['price_delta'] ?? 0);
                $is_active = (int)($_POST['is_active'] ?? 1);
                $pos_visible = (int)($_POST['pos_visible'] ?? 1);
                $sort_order = (int)($_POST['sort_order'] ?? 999);
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE variation_values 
                        SET value_en = :value_en, value_ar = :value_ar, 
                            price_delta = :price_delta, is_active = :is_active,
                            pos_visible = :pos_visible, sort_order = :sort_order
                        WHERE id = :id AND group_id = :gid
                    ");
                    $stmt->execute([
                        ':value_en' => $value_en,
                        ':value_ar' => $value_ar,
                        ':price_delta' => $price_delta,
                        ':is_active' => $is_active,
                        ':pos_visible' => $pos_visible,
                        ':sort_order' => $sort_order,
                        ':id' => $id,
                        ':gid' => $group_id
                    ]);
                    $message = 'Value updated successfully';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO variation_values (group_id, value_en, value_ar, price_delta, is_active, pos_visible, sort_order) 
                        VALUES (:gid, :value_en, :value_ar, :price_delta, :is_active, :pos_visible, :sort_order)
                    ");
                    $stmt->execute([
                        ':gid' => $group_id,
                        ':value_en' => $value_en,
                        ':value_ar' => $value_ar,
                        ':price_delta' => $price_delta,
                        ':is_active' => $is_active,
                        ':pos_visible' => $pos_visible,
                        ':sort_order' => $sort_order
                    ]);
                    $id = $pdo->lastInsertId();
                    $message = 'Value added successfully';
                }
                
                echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);
                break;
                
            case 'delete_modifier':
                $id = (int)$_POST['id'];
                
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM variation_values WHERE group_id = :id");
                $stmt->execute([':id' => $id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Cannot delete modifier with values. Delete all values first.');
                }
                
                $stmt = $pdo->prepare("DELETE FROM variation_groups WHERE id = :id AND tenant_id = :t");
                $stmt->execute([':id' => $id, ':t' => $tenantId]);
                
                echo json_encode(['success' => true, 'message' => 'Modifier deleted successfully']);
                break;
                
            case 'delete_value':
                $id = (int)$_POST['id'];
                
                $stmt = $pdo->prepare("DELETE FROM variation_values WHERE id = :id");
                $stmt->execute([':id' => $id]);
                
                echo json_encode(['success' => true, 'message' => 'Value deleted successfully']);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$active = 'modifiers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifiers Management · Smorll POS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Modern color palette matching rewards system */
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
            
            --ms-red: #d13438;
            --ms-red-light: #fdf2f2;
            
            --ms-yellow: #ffb900;
            --ms-yellow-light: #fff4ce;
            
            --ms-shadow-1: 0 1px 2px rgba(0,0,0,0.05);
            --ms-shadow-2: 0 1.6px 3.6px 0 rgba(0,0,0,.132), 0 0.3px 0.9px 0 rgba(0,0,0,.108);
            --ms-shadow-3: 0 2px 8px rgba(0,0,0,0.092);
            
            --ms-radius: 4px;
            --ms-radius-lg: 8px;
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
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
        
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }
        }
        
        /* Views */
        .view {
            animation: fadeIn 0.3s ease;
            display: none;
        }
        
        .view.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Page Header */
        .h1 {
            font-size: 28px;
            font-weight: 600;
            color: var(--ms-gray-160);
            margin-bottom: 4px;
        }
        
        .sub {
            font-size: 14px;
            color: var(--ms-gray-110);
            margin-bottom: 24px;
        }
        
        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 16px 20px;
            background: var(--ms-gray-20);
            border-bottom: 1px solid var(--ms-gray-30);
            font-size: 13px;
            color: var(--ms-gray-110);
        }
        
        .breadcrumb a {
            color: var(--ms-blue);
            text-decoration: none;
            cursor: pointer;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
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
            margin-left: auto;
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
            background: #0e6b0e;
            border-color: #0e6b0e;
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
        
        /* Modifier name */
        .modifier-name {
            font-weight: 600;
            color: var(--ms-gray-160);
        }
        
        /* Status badges */
        .text-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .text-badge.active { color: var(--ms-green); }
        .text-badge.inactive { color: var(--ms-gray-110); }
        .text-badge.visible { color: var(--ms-blue); }
        .text-badge.hidden { color: var(--ms-yellow); }
        .text-badge.required { color: var(--ms-red); }
        .text-badge.optional { color: var(--ms-gray-110); }
        
        /* Form */
        .form-section {
            padding: 24px;
        }
        
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
            margin-top: 8px;
        }
        
        .form-checkbox input {
            width: 18px;
            height: 18px;
            accent-color: var(--ms-blue);
        }
        
        .form-checkbox label {
            font-size: 14px;
            font-weight: normal;
            text-transform: none;
            letter-spacing: normal;
            cursor: pointer;
        }
        
        .form-footer {
            padding: 20px 24px;
            background: var(--ms-gray-20);
            border-top: 1px solid var(--ms-gray-30);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }
        
        /* Values Grid */
        .values-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            padding: 20px;
        }
        
        .value-card {
            background: white;
            border: 1px solid var(--ms-gray-30);
            border-radius: var(--ms-radius-lg);
            padding: 16px;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .value-card:hover {
            box-shadow: var(--ms-shadow-2);
            border-color: var(--ms-blue);
        }
        
        .value-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--ms-gray-160);
            margin-bottom: 4px;
        }
        
        .value-arabic {
            color: var(--ms-gray-110);
            font-size: 12px;
            direction: rtl;
            margin-bottom: 12px;
        }
        
        .value-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid var(--ms-gray-20);
        }
        
        .value-price {
            color: var(--ms-green);
            font-weight: 600;
            font-size: 14px;
        }
        
        .value-actions {
            display: flex;
            gap: 8px;
        }
        
        /* Empty state */
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
        
        .toast.success { border-left-color: var(--ms-green); }
        .toast.error { border-left-color: var(--ms-red); }
        
        /* Loading spinner */
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
        
        /* Responsive */
        @media (max-width: 768px) {
            .filters-bar { flex-direction: column; }
            .filter-group { width: 100%; }
            .filter-actions { width: 100%; margin-left: 0; }
            .form-row { grid-template-columns: 1fr; }
            .form-footer { flex-direction: column-reverse; gap: 12px; }
            .form-footer .btn { width: 100%; }
            .values-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php
    $active = 'modifiers';
    require __DIR__ . '/../../partials/admin_nav.php';
    ?>
    
    <div class="container">
        <div class="toast-container" id="toastContainer"></div>
        
        <!-- Modifiers List View -->
        <div id="modifiersView" class="view active">
            <div class="h1">Modifiers</div>
            <p class="sub">Manage product modifiers and their values</p>
            
            <!-- Filters -->
            <div class="filters-bar">
                <div class="filter-group search-group">
                    <label>Search</label>
                    <input type="text" id="searchInput" placeholder="Search modifiers..." onkeyup="debounceSearch()">
                </div>
                
                <div class="filter-group">
                    <label>Status</label>
                    <select id="statusFilter" onchange="applyFilters()">
                        <option value="all">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Visibility</label>
                    <select id="visibilityFilter" onchange="applyFilters()">
                        <option value="all">All Visibility</option>
                        <option value="visible">Visible</option>
                        <option value="hidden">Hidden</option>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="button" class="btn" id="clearFiltersBtn" onclick="clearFilters()" style="display: none;">Clear Filters</button>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Modifiers List</h2>
                    <button class="btn primary" onclick="showAddModifierForm()">+ Add Modifier</button>
                </div>
                
                <div id="modifiersTable"></div>
            </div>
        </div>
        
        <!-- Modifier Form View -->
        <div id="modifierFormView" class="view">
            <div class="h1" id="modifierFormTitle">Add Modifier</div>
            <p class="sub">Define a new modifier group</p>
            
            <div class="card">
                <form id="modifierForm" onsubmit="saveModifier(event)">
                    <input type="hidden" id="modifierId" value="">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" id="modifierSortOrder" name="sort_order" value="999">
                    
                    <div class="form-section">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Name <span class="required">*</span></label>
                                <input type="text" id="modifierName" name="name" class="form-input" required placeholder="e.g., Size, Sauce">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Customer Required</label>
                                <select id="modifierRequired" name="is_required" class="form-select">
                                    <option value="0">Optional</option>
                                    <option value="1">Required</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Min Select</label>
                                <input type="number" id="modifierMinSelect" name="min_select" class="form-input" value="0" min="0">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Max Select</label>
                                <input type="number" id="modifierMaxSelect" name="max_select" class="form-input" value="1" min="1">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <div class="form-checkbox">
                                    <input type="checkbox" id="modifierActive" name="is_active" value="1" checked>
                                    <label for="modifierActive">Active</label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="form-checkbox">
                                    <input type="checkbox" id="modifierPosVisible" name="pos_visible" value="1" checked>
                                    <label for="modifierPosVisible">Visible in POS</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-footer">
                        <button type="button" class="btn" onclick="showModifiersView()">Cancel</button>
                        <button type="submit" class="btn primary">
                            <span id="modifierSubmitText">Create Modifier</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Values View -->
        <div id="valuesView" class="view">
            <div class="h1" id="valuesTitle">Modifier Values</div>
            <p class="sub" id="valuesSubtitle">Manage values for this modifier</p>
            
            <div class="card">
                <div class="breadcrumb">
                    <a onclick="showModifiersView()">Modifiers</a>
                    <span>›</span>
                    <span id="breadcrumbModifierName">Modifier</span>
                </div>
                
                <div class="card-header">
                    <h2 class="card-title">Values List</h2>
                    <button class="btn primary" onclick="showAddValueForm()">+ Add Value</button>
                </div>
                
                <div id="valuesContainer"></div>
            </div>
        </div>
        
        <!-- Value Form View -->
        <div id="valueFormView" class="view">
            <div class="h1" id="valueFormTitle">Add Value</div>
            <p class="sub">Add a new option to this modifier</p>
            
            <div class="card">
                <form id="valueForm" onsubmit="saveValue(event)">
                    <input type="hidden" id="valueId" value="">
                    <input type="hidden" id="valueGroupId" name="group_id" value="">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    
                    <div class="form-section">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Value (English) <span class="required">*</span></label>
                                <input type="text" id="valueEn" name="value_en" class="form-input" required placeholder="e.g., Small">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Value (Arabic)</label>
                                <input type="text" id="valueAr" name="value_ar" class="form-input" dir="rtl" placeholder="القيمة">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Price Delta</label>
                                <input type="number" id="valuePriceDelta" name="price_delta" class="form-input" value="0.00" step="0.01">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Sort Order</label>
                                <input type="number" id="valueSortOrder" name="sort_order" class="form-input" value="999" min="0">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <div class="form-checkbox">
                                    <input type="checkbox" id="valueActive" name="is_active" value="1" checked>
                                    <label for="valueActive">Active</label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="form-checkbox">
                                    <input type="checkbox" id="valuePosVisible" name="pos_visible" value="1" checked>
                                    <label for="valuePosVisible">Visible in POS</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-footer">
                        <button type="button" class="btn" onclick="showValuesView()">Cancel</button>
                        <button type="submit" class="btn primary">
                            <span id="valueSubmitText">Add Value</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        let currentModifier = null;
        let modifiers = [];
        let values = [];
        let searchTimer = null;
        let filterTimer = null;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadModifiers();
        });
        
        // Debounced search
        function debounceSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                loadModifiers();
            }, 600);
        }
        
        // Debounced filter
        function applyFilters() {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(() => {
                loadModifiers();
            }, 400);
        }
        
        // Clear filters
        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('visibilityFilter').value = 'all';
            loadModifiers();
        }
        
        function updateClearFiltersVisibility() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const visibility = document.getElementById('visibilityFilter').value;
            const hasFilters = search !== '' || status !== 'all' || visibility !== 'all';
            
            document.getElementById('clearFiltersBtn').style.display = hasFilters ? 'block' : 'none';
        }
        
        // View Management
        function showModifiersView() {
            document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
            document.getElementById('modifiersView').classList.add('active');
            loadModifiers();
        }
        
        function showModifierForm(modifier = null) {
            document.getElementById('modifierFormTitle').textContent = modifier ? 'Edit Modifier' : 'Add Modifier';
            document.getElementById('modifierSubmitText').textContent = modifier ? 'Update Modifier' : 'Create Modifier';
            
            if (modifier) {
                document.getElementById('modifierId').value = modifier.id;
                document.getElementById('modifierName').value = modifier.name;
                document.getElementById('modifierSortOrder').value = modifier.sort_order || 999;
                document.getElementById('modifierMinSelect').value = modifier.min_select || 0;
                document.getElementById('modifierMaxSelect').value = modifier.max_select || 1;
                document.getElementById('modifierRequired').value = modifier.is_required || 0;
                document.getElementById('modifierActive').checked = modifier.is_active == 1;
                document.getElementById('modifierPosVisible').checked = modifier.pos_visible == 1;
            } else {
                document.getElementById('modifierForm').reset();
                document.getElementById('modifierId').value = '';
                document.getElementById('modifierSortOrder').value = 999;
            }
            
            document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
            document.getElementById('modifierFormView').classList.add('active');
        }
        
        function showAddModifierForm() {
            showModifierForm(null);
        }
        
        function showEditModifierForm(id) {
            fetch(`?action=get_modifier&id=${id}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    showModifierForm(data.data);
                }
            });
        }
        
        function showValuesView(groupId = null) {
            if (groupId) currentModifier = groupId;
            if (!currentModifier) return;
            
            document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
            document.getElementById('valuesView').classList.add('active');
            loadValues(currentModifier);
        }
        
        function showValueForm(value = null) {
            document.getElementById('valueFormTitle').textContent = value ? 'Edit Value' : 'Add Value';
            document.getElementById('valueSubmitText').textContent = value ? 'Update Value' : 'Add Value';
            document.getElementById('valueGroupId').value = currentModifier;
            
            if (value) {
                document.getElementById('valueId').value = value.id;
                document.getElementById('valueEn').value = value.value_en;
                document.getElementById('valueAr').value = value.value_ar || '';
                document.getElementById('valuePriceDelta').value = value.price_delta || 0;
                document.getElementById('valueSortOrder').value = value.sort_order || 999;
                document.getElementById('valueActive').checked = value.is_active == 1;
                document.getElementById('valuePosVisible').checked = value.pos_visible == 1;
            } else {
                document.getElementById('valueForm').reset();
                document.getElementById('valueId').value = '';
                document.getElementById('valueGroupId').value = currentModifier;
            }
            
            document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
            document.getElementById('valueFormView').classList.add('active');
        }
        
        function showAddValueForm() {
            showValueForm(null);
        }
        
        function showEditValueForm(id) {
            fetch(`?action=get_value&id=${id}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    showValueForm(data.data);
                }
            });
        }
        
        // Load functions
        function loadModifiers() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const visibility = document.getElementById('visibilityFilter').value;
            
            updateClearFiltersVisibility();
            
            const params = new URLSearchParams({
                action: 'list_modifiers',
                q: search,
                status: status,
                vis: visibility
            });
            
            fetch(`?${params}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    modifiers = data.data;
                    renderModifiersTable();
                }
            });
        }
        
        function loadValues(groupId) {
            fetch(`?action=list_values&group_id=${groupId}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    values = data.values;
                    const group = data.group;
                    document.getElementById('valuesTitle').textContent = `${group.name} Values`;
                    document.getElementById('breadcrumbModifierName').textContent = group.name;
                    renderValues();
                }
            });
        }
        
        // Render functions
        function renderModifiersTable() {
            const container = document.getElementById('modifiersTable');
            
            if (modifiers.length === 0) {
                const hasFilters = document.getElementById('searchInput').value !== '' || 
                                 document.getElementById('statusFilter').value !== 'all' || 
                                 document.getElementById('visibilityFilter').value !== 'all';
                
                container.innerHTML = `
                    <div class="empty-state">
                        <h3>No modifiers found</h3>
                        <p>${hasFilters ? 'No modifiers match the selected filters.' : 'Start by adding your first modifier.'}</p>
                        ${!hasFilters ? '<br><button class="btn primary" onclick="showAddModifierForm()">+ Add Your First Modifier</button>' : ''}
                    </div>
                `;
                return;
            }
            
            let html = `
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th style="max-width: 250px;">Name</th>
                            <th style="width: 100px;">Required</th>
                            <th style="width: 80px;">Min-Max</th>
                            <th style="width: 80px;">Status</th>
                            <th style="width: 80px;">Visibility</th>
                            <th style="width: 180px; white-space: nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            modifiers.forEach(mod => {
                html += `
                    <tr>
                        <td style="color: var(--ms-gray-110); font-size: 12px;">#${mod.id}</td>
                        <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis;">
                            <span class="modifier-name" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block;">${escapeHtml(mod.name)}</span>
                        </td>
                        <td>
                            <span class="text-badge ${mod.is_required == 1 ? 'required' : 'optional'}">
                                ${mod.is_required == 1 ? 'REQUIRED' : 'OPTIONAL'}
                            </span>
                        </td>
                        <td style="text-align: center;">${mod.min_select}-${mod.max_select}</td>
                        <td>
                            <span class="text-badge ${mod.is_active == 1 ? 'active' : 'inactive'}">
                                ${mod.is_active == 1 ? 'ACTIVE' : 'INACTIVE'}
                            </span>
                        </td>
                        <td>
                            <span class="text-badge ${mod.pos_visible == 1 ? 'visible' : 'hidden'}">
                                ${mod.pos_visible == 1 ? 'VISIBLE' : 'HIDDEN'}
                            </span>
                        </td>
                        <td style="white-space: nowrap;">
                            <button class="btn small success" onclick="showValuesView(${mod.id})">Values</button>
                            <button class="btn small" onclick="showEditModifierForm(${mod.id})">Edit</button>
                            <button class="btn small danger" onclick="deleteModifier(${mod.id}, '${escapeHtml(mod.name).replace(/'/g, "\\'")}')">Delete</button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
        }
        
        function renderValues() {
            const container = document.getElementById('valuesContainer');
            
            if (values.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <h3>No values yet</h3>
                        <p>Add values that customers can choose from for this modifier</p>
                        <br>
                        <button class="btn primary" onclick="showAddValueForm()">+ Add Value</button>
                    </div>
                `;
                return;
            }
            
            let html = '<div class="values-grid">';
            
            values.forEach(val => {
                html += `
                    <div class="value-card">
                        <div class="value-name">${escapeHtml(val.value_en)}</div>
                        ${val.value_ar ? `<div class="value-arabic">${escapeHtml(val.value_ar)}</div>` : ''}
                        <div class="value-details">
                            <div>
                                <span class="text-badge ${val.is_active == 1 ? 'active' : 'inactive'}">
                                    ${val.is_active == 1 ? 'ACTIVE' : 'INACTIVE'}
                                </span>
                                ${val.pos_visible == 1 ? '<span class="text-badge visible" style="margin-left: 8px;">VISIBLE</span>' : ''}
                            </div>
                            <div class="value-price">+${parseFloat(val.price_delta).toFixed(2)}</div>
                        </div>
                        <div class="value-actions" style="margin-top: 12px;">
                            <button class="btn small" onclick="showEditValueForm(${val.id})">Edit</button>
                            <button class="btn small danger" onclick="deleteValue(${val.id})">Delete</button>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }
        
        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
        
        // Save functions
        function saveModifier(event) {
            event.preventDefault();
            
            const submitBtn = event.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner"></span>Saving...';
            submitBtn.disabled = true;
            
            const formData = new FormData(document.getElementById('modifierForm'));
            formData.append('action', 'save_modifier');
            formData.append('id', document.getElementById('modifierId').value);
            
            if (!document.getElementById('modifierActive').checked) {
                formData.append('is_active', '0');
            }
            if (!document.getElementById('modifierPosVisible').checked) {
                formData.append('pos_visible', '0');
            }
            
            fetch('', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    showModifiersView();
                } else {
                    showToast(data.error || 'Error saving modifier', 'error');
                }
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        function saveValue(event) {
            event.preventDefault();
            
            const submitBtn = event.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner"></span>Saving...';
            submitBtn.disabled = true;
            
            const formData = new FormData(document.getElementById('valueForm'));
            formData.append('action', 'save_value');
            formData.append('id', document.getElementById('valueId').value);
            
            if (!document.getElementById('valueActive').checked) {
                formData.append('is_active', '0');
            }
            if (!document.getElementById('valuePosVisible').checked) {
                formData.append('pos_visible', '0');
            }
            
            fetch('', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    showValuesView();
                } else {
                    showToast(data.error || 'Error saving value', 'error');
                }
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        // Delete functions
        function deleteModifier(id, name) {
            if (!confirm(`Delete modifier "${name}"?\n\nThis cannot be undone and will affect all products using this modifier.`)) return;
            
            const formData = new FormData();
            formData.append('action', 'delete_modifier');
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
                    loadModifiers();
                } else {
                    showToast(data.error || 'Error deleting modifier', 'error');
                }
            });
        }
        
        function deleteValue(id) {
            if (!confirm('Delete this value?\n\nThis action cannot be undone.')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete_value');
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
                    loadValues(currentModifier);
                } else {
                    showToast(data.error || 'Error deleting value', 'error');
                }
            });
        }
        
        // Toast
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
</body>
</html>