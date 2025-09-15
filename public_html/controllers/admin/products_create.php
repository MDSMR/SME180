<?php
/**
 * /public_html/controllers/admin/products_create.php
 * Handle new product creation
 */
declare(strict_types=1);

// Start session and include required files
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// Check authentication
$user = $_SESSION['user'] ?? null;
if (!$user) { 
  header('Location: /views/auth/login.php'); 
  exit; 
}
$tenantId = (int)($user['tenant_id'] ?? 0);

// Ensure this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /views/admin/catalog/products_new.php');
  exit;
}

try {
  $pdo = db();
  $pdo->beginTransaction();
  
  // Prepare product data
  $name_en = trim($_POST['name_en'] ?? '');
  $name_ar = trim($_POST['name_ar'] ?? '');
  $sku = trim($_POST['sku'] ?? '') ?: null;
  $barcode = trim($_POST['barcode'] ?? '') ?: null;
  $description = trim($_POST['description'] ?? '') ?: null;
  $price = (float)($_POST['price'] ?? 0);
  $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : null;
  $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
  $min_stock = (int)($_POST['min_stock'] ?? 0);
  $is_active = isset($_POST['is_active']) ? 1 : 0;
  $pos_visible = isset($_POST['pos_visible']) ? 1 : 0;
  
  // Validate required fields
  if (empty($name_en)) {
    throw new RuntimeException('Product name (English) is required');
  }
  
  // Check if columns exist and build dynamic query
  $columns = ['tenant_id', 'name_en', 'name_ar', 'price', 'is_active', 'pos_visible'];
  $values = [':tenant_id', ':name_en', ':name_ar', ':price', ':is_active', ':pos_visible'];
  $params = [
    ':tenant_id' => $tenantId,
    ':name_en' => $name_en,
    ':name_ar' => $name_ar,
    ':price' => $price,
    ':is_active' => $is_active,
    ':pos_visible' => $pos_visible
  ];
  
  // Check for optional columns and add if they exist
  $checkColumns = [
    'sku' => $sku,
    'barcode' => $barcode,
    'description' => $description,
    'cost' => $cost,
    'stock_quantity' => $stock_quantity,
    'min_stock' => $min_stock
  ];
  
  // Test for column existence
  $stmt = $pdo->query("SHOW COLUMNS FROM products");
  $existingColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
  
  foreach ($checkColumns as $col => $value) {
    if (in_array($col, $existingColumns) && $value !== null) {
      $columns[] = $col;
      $values[] = ':' . $col;
      $params[':' . $col] = $value;
    }
  }
  
  // Add timestamps if columns exist
  if (in_array('created_at', $existingColumns)) {
    $columns[] = 'created_at';
    $values[] = 'NOW()';
  }
  if (in_array('updated_at', $existingColumns)) {
    $columns[] = 'updated_at';
    $values[] = 'NOW()';
  }
  
  // Build and execute INSERT query
  $sql = "INSERT INTO products (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";
  $stmt = $pdo->prepare($sql);
  
  // Remove NOW() from params as it's a function not a parameter
  foreach ($params as $key => $value) {
    if ($value === 'NOW()') {
      unset($params[$key]);
    }
  }
  
  $stmt->execute($params);
  $productId = $pdo->lastInsertId();
  
  // Add categories if product_categories table exists
  if (!empty($_POST['categories']) && is_array($_POST['categories'])) {
    try {
      // Check if product_categories table exists
      $stmt = $pdo->query("SHOW TABLES LIKE 'product_categories'");
      if ($stmt->fetchColumn()) {
        $stmt = $pdo->prepare("
          INSERT INTO product_categories (product_id, category_id) 
          VALUES (:product_id, :category_id)
          ON DUPLICATE KEY UPDATE category_id = VALUES(category_id)
        ");
        foreach ($_POST['categories'] as $catId) {
          if (is_numeric($catId) && $catId > 0) {
            $stmt->execute([
              ':product_id' => $productId, 
              ':category_id' => (int)$catId
            ]);
          }
        }
      }
    } catch (Exception $e) {
      // Silently continue if junction table doesn't exist
    }
  }
  
  // Add branches if product_branches table exists
  if (!empty($_POST['branches']) && is_array($_POST['branches'])) {
    try {
      // Check if product_branches table exists
      $stmt = $pdo->query("SHOW TABLES LIKE 'product_branches'");
      if ($stmt->fetchColumn()) {
        $stmt = $pdo->prepare("
          INSERT INTO product_branches (product_id, branch_id) 
          VALUES (:product_id, :branch_id)
          ON DUPLICATE KEY UPDATE branch_id = VALUES(branch_id)
        ");
        foreach ($_POST['branches'] as $branchId) {
          if (is_numeric($branchId) && $branchId > 0) {
            $stmt->execute([
              ':product_id' => $productId, 
              ':branch_id' => (int)$branchId
            ]);
          }
        }
      }
    } catch (Exception $e) {
      // Silently continue if junction table doesn't exist
    }
  }
  
  $pdo->commit();
  
  $_SESSION['flash'] = 'Product created successfully!';
  
  // Check action type - save and continue adding or return to list
  if (($_POST['action'] ?? '') === 'save_and_new') {
    header('Location: /views/admin/catalog/products_new.php');
  } else {
    header('Location: /views/admin/catalog/products.php');
  }
  exit;
  
} catch (Throwable $e) {
  if (isset($pdo)) {
    $pdo->rollBack();
  }
  $_SESSION['flash'] = 'Error creating product: ' . $e->getMessage();
  header('Location: /views/admin/catalog/products_new.php');
  exit;
}