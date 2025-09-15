<?php
declare(strict_types=1);
/**
 * /public_html/views/admin/catalog/categories.php
 * Single Page Application for Categories Management - Modern Design
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
if (empty($_SESSION['csrf_categories'])) { 
    $_SESSION['csrf_categories'] = bin2hex(random_bytes(32)); 
}
$csrf = $_SESSION['csrf_categories'];

// Handle AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    try {
        $pdo = db();
        
        switch($action) {
            case 'list':
                $q = trim($_GET['q'] ?? '');
                $status = $_GET['status'] ?? 'all';
                $vis = $_GET['vis'] ?? 'all';
                
                $where = ["tenant_id = :t"];
                $params = [':t' => $tenantId];
                
                if ($q !== '') {
                    $where[] = "(name_en LIKE :q OR name_ar LIKE :q)";
                    $params[':q'] = "%{$q}%";
                }
                if ($status === 'active') $where[] = "is_active = 1";
                if ($status === 'inactive') $where[] = "is_active = 0";
                if ($vis === 'visible') $where[] = "pos_visible = 1";
                if ($vis === 'hidden') $where[] = "pos_visible = 0";
                
                $sql = "SELECT * FROM categories WHERE " . implode(' AND ', $where) . " ORDER BY sort_order, name_en LIMIT 200";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
                
            case 'get':
                $id = (int)$_GET['id'];
                $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id AND tenant_id = :t");
                $stmt->execute([':id' => $id, ':t' => $tenantId]);
                $cat = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $cat]);
                break;
                
            case 'save':
                if (($_POST['csrf'] ?? '') !== $csrf) {
                    throw new Exception('Invalid CSRF token');
                }
                
                $id = (int)($_POST['id'] ?? 0);
                $name_en = trim($_POST['name_en'] ?? '');
                $name_ar = trim($_POST['name_ar'] ?? '');
                $is_active = (int)($_POST['is_active'] ?? 1);
                $pos_visible = (int)($_POST['pos_visible'] ?? 1);
                $sort_order = (int)($_POST['sort_order'] ?? 999);
                
                if ($id > 0) {
                    // Update
                    $stmt = $pdo->prepare("
                        UPDATE categories 
                        SET name_en = :name_en, name_ar = :name_ar, 
                            is_active = :is_active, pos_visible = :pos_visible,
                            sort_order = :sort_order
                        WHERE id = :id AND tenant_id = :t
                    ");
                    $stmt->execute([
                        ':name_en' => $name_en,
                        ':name_ar' => $name_ar,
                        ':is_active' => $is_active,
                        ':pos_visible' => $pos_visible,
                        ':sort_order' => $sort_order,
                        ':id' => $id,
                        ':t' => $tenantId
                    ]);
                    $message = 'Category updated successfully';
                } else {
                    // Create
                    $stmt = $pdo->prepare("
                        INSERT INTO categories (tenant_id, name_en, name_ar, is_active, pos_visible, sort_order) 
                        VALUES (:t, :name_en, :name_ar, :is_active, :pos_visible, :sort_order)
                    ");
                    $stmt->execute([
                        ':t' => $tenantId,
                        ':name_en' => $name_en,
                        ':name_ar' => $name_ar,
                        ':is_active' => $is_active,
                        ':pos_visible' => $pos_visible,
                        ':sort_order' => $sort_order
                    ]);
                    $id = $pdo->lastInsertId();
                    $message = 'Category created successfully';
                }
                
                echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);
                break;
                
            case 'delete':
                $id = (int)$_POST['id'];
                
                // Check for products
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_categories WHERE category_id = :id");
                $stmt->execute([':id' => $id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Cannot delete category with products');
                }
                
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id = :id AND tenant_id = :t");
                $stmt->execute([':id' => $id, ':t' => $tenantId]);
                
                echo json_encode(['success' => true, 'message' => 'Category deleted successfully']);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$active = 'categories';  // This specific value for categories subpage
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories Management · Smorll POS</title>
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
            --ms-green-darker: #0e5e0e;
            
            --ms-red: #d13438;
            --ms-red-light: #fdf2f2;
            --ms-red-darker: #a80000;
            
            --ms-yellow: #ffb900;
            --ms-yellow-light: #fff4ce;
            
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

        /* Filters Bar - Modern style */
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

        /* Category name styling */
        .category-name {
            font-weight: 600;
            color: var(--ms-gray-160);
            margin-bottom: 2px;
        }

        .category-name-ar {
            font-size: 12px;
            color: var(--ms-gray-110);
            direction: rtl;
        }

        /* Status badges */
        .text-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .text-badge.active {
            color: var(--ms-green);
        }

        .text-badge.inactive {
            color: var(--ms-gray-110);
        }

        .text-badge.visible {
            color: var(--ms-blue);
        }

        .text-badge.hidden {
            color: var(--ms-yellow);
        }

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

        .form-footer {
            padding: 20px 24px;
            background: var(--ms-gray-20);
            border-top: 1px solid var(--ms-gray-30);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
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

        .toast.success {
            border-left-color: var(--ms-green);
        }

        .toast.error {
            border-left-color: var(--ms-red);
        }

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
            .filters-bar {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .filter-actions {
                width: 100%;
                margin-left: 0;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-footer {
                flex-direction: column-reverse;
                gap: 12px;
            }
            
            .form-footer .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php
    $active = 'categories';  // This specific value for categories subpage
    try {
        require __DIR__ . '/../../partials/admin_nav.php';
    } catch (Throwable $e) {
        echo "<div class='alert error'>Navigation error: " . h($e->getMessage()) . "</div>";
    }
    ?>
    
    <div class="container">
        <div class="toast-container" id="toastContainer"></div>
        
        <!-- List View -->
        <div id="listView" class="view active">
            <div class="h1">Categories</div>
            <p class="sub">Manage your product categories and organization</p>

            <!-- Filters -->
            <div class="filters-bar">
                <div class="filter-group search-group">
                    <label>Search</label>
                    <input type="text" id="searchInput" placeholder="Search categories..." onkeyup="debounceSearch()">
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

            <!-- Categories Table -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Categories List</h2>
                    <button class="btn primary" onclick="showAddForm()">+ Add Category</button>
                </div>
                
                <div id="categoriesTable"></div>
            </div>
        </div>
        
        <!-- Add/Edit Form View -->
        <div id="formView" class="view">
            <div class="h1" id="formTitle">Add Category</div>
            <p class="sub">Fill in the category details</p>

            <div class="card">
                <form id="categoryForm" onsubmit="saveCategory(event)">
                    <input type="hidden" id="categoryId" value="">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" id="sortOrder" name="sort_order" value="999">
                    
                    <div class="form-section">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    Name (English) <span class="required">*</span>
                                </label>
                                <input type="text" id="nameEn" name="name_en" class="form-input" required placeholder="Enter category name">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Name (Arabic)</label>
                                <input type="text" id="nameAr" name="name_ar" class="form-input" dir="rtl" placeholder="اسم الفئة">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select id="isActive" name="is_active" class="form-select">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">POS Visibility</label>
                                <select id="posVisible" name="pos_visible" class="form-select">
                                    <option value="1">Visible</option>
                                    <option value="0">Hidden</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-footer">
                        <button type="button" class="btn" onclick="showListView()">Cancel</button>
                        <button type="submit" class="btn primary">
                            <span id="submitBtnText">Create Category</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        let currentView = 'list';
        let categories = [];
        let searchTimer = null;
        let filterTimer = null;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadCategories();
        });
        
        // View Management
        function showListView() {
            document.getElementById('listView').classList.add('active');
            document.getElementById('formView').classList.remove('active');
            currentView = 'list';
            loadCategories();
        }
        
        function showAddForm() {
            document.getElementById('formTitle').textContent = 'Add Category';
            document.getElementById('submitBtnText').textContent = 'Create Category';
            document.getElementById('categoryForm').reset();
            document.getElementById('categoryId').value = '';
            document.getElementById('sortOrder').value = '999';
            document.getElementById('isActive').value = '1';
            document.getElementById('posVisible').value = '1';
            
            document.getElementById('listView').classList.remove('active');
            document.getElementById('formView').classList.add('active');
            currentView = 'form';
            
            setTimeout(() => {
                document.getElementById('nameEn').focus();
            }, 100);
        }
        
        function showEditForm(id) {
            document.getElementById('formTitle').textContent = 'Edit Category';
            document.getElementById('submitBtnText').textContent = 'Update Category';
            
            fetch(`?action=get&id=${id}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    const cat = data.data;
                    document.getElementById('categoryId').value = cat.id;
                    document.getElementById('nameEn').value = cat.name_en || '';
                    document.getElementById('nameAr').value = cat.name_ar || '';
                    document.getElementById('sortOrder').value = cat.sort_order || 999;
                    document.getElementById('isActive').value = cat.is_active ?? 1;
                    document.getElementById('posVisible').value = cat.pos_visible ?? 1;
                    
                    document.getElementById('listView').classList.remove('active');
                    document.getElementById('formView').classList.add('active');
                    currentView = 'form';
                } else {
                    showToast('Error loading category', 'error');
                }
            });
        }
        
        // Debounced search
        function debounceSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                loadCategories();
            }, 600);
        }
        
        // Debounced filter apply
        function applyFilters() {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(() => {
                loadCategories();
            }, 400);
        }
        
        // Clear filters
        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('visibilityFilter').value = 'all';
            loadCategories();
        }

        // Check if filters are active
        function updateClearFiltersVisibility() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const visibility = document.getElementById('visibilityFilter').value;
            const hasFilters = search !== '' || status !== 'all' || visibility !== 'all';
            
            document.getElementById('clearFiltersBtn').style.display = hasFilters ? 'block' : 'none';
        }
        
        // Load Categories
        function loadCategories() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const visibility = document.getElementById('visibilityFilter').value;
            
            updateClearFiltersVisibility();
            
            const params = new URLSearchParams({
                action: 'list',
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
                    categories = data.data;
                    renderTable();
                }
            });
        }
        
        // Render Table
        function renderTable() {
            const container = document.getElementById('categoriesTable');
            
            if (categories.length === 0) {
                const hasFilters = document.getElementById('searchInput').value !== '' || 
                                 document.getElementById('statusFilter').value !== 'all' || 
                                 document.getElementById('visibilityFilter').value !== 'all';
                
                container.innerHTML = `
                    <div class="empty-state">
                        <h3>No categories found</h3>
                        <p>${hasFilters ? 'No categories match the selected filters.' : 'Start by adding your first category.'}</p>
                        ${!hasFilters ? '<br><button class="btn primary" onclick="showAddForm()">+ Add Your First Category</button>' : ''}
                    </div>
                `;
                return;
            }
            
            let html = `
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th style="max-width: 300px;">Name</th>
                            <th style="width: 100px;">Status</th>
                            <th style="width: 100px;">Visibility</th>
                            <th style="width: 160px; white-space: nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            categories.forEach(cat => {
                html += `
                    <tr>
                        <td style="color: var(--ms-gray-110); font-size: 12px;">#${cat.id}</td>
                        <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis;">
                            <div class="category-name" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(cat.name_en || 'Untitled')}</div>
                            ${cat.name_ar ? `<div class="category-name-ar" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(cat.name_ar)}</div>` : ''}
                        </td>
                        <td>
                            <span class="text-badge ${cat.is_active == 1 ? 'active' : 'inactive'}">
                                ${cat.is_active == 1 ? 'ACTIVE' : 'INACTIVE'}
                            </span>
                        </td>
                        <td>
                            <span class="text-badge ${cat.pos_visible == 1 ? 'visible' : 'hidden'}">
                                ${cat.pos_visible == 1 ? 'VISIBLE' : 'HIDDEN'}
                            </span>
                        </td>
                        <td style="white-space: nowrap;">
                            <button class="btn small" onclick="showEditForm(${cat.id})">Edit</button>
                            <button class="btn small danger" onclick="deleteCategory(${cat.id}, '${escapeHtml((cat.name_en || 'Category')).replace(/'/g, "\\'")}')">Delete</button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
        
        // Save Category
        function saveCategory(event) {
            event.preventDefault();
            
            const submitBtn = event.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner"></span>Saving...';
            submitBtn.disabled = true;
            
            const formData = new FormData(document.getElementById('categoryForm'));
            formData.append('action', 'save');
            formData.append('id', document.getElementById('categoryId').value);
            
            fetch('', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    showListView();
                } else {
                    showToast(data.error || 'Error saving category', 'error');
                }
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        // Delete Category
        function deleteCategory(id, name) {
            if (!confirm(`Delete category "${name}"?\n\nThis action cannot be undone.`)) return;
            
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
                    loadCategories();
                } else {
                    showToast(data.error || 'Error deleting category', 'error');
                }
            });
        }
        
        // Toast Notifications
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
    require __DIR__ . '/../../partials/admin_nav_close.php';
    ?>
</body>
</html>