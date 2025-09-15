<?php
/**
 * /public_html/controllers/admin/products_update.php
 * Handle product updates
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
  header('Location: /views/admin/catalog/products.php');
  exit;
}

// Get and validate product ID
$productId = (int)($_POST['id'] ?? 0);
if ($productId <= 0) {
  $_SESSION['flash'] = 'Invalid product ID';
  header('Location: /views/admin/catalog/products.php');
  exit;
}

try {
  $pdo = db();
  $pdo->beginTransaction();
  
  // Verify product belongs to tenant
  $stmt = $pdo->prepare("SELECT id FROM products WHERE id = :id AND tenant_id = :t");
  $stmt->execute([':id' => $productId, ':t' => $tenantId]);
  if (!$stmt->fetchColumn()) {
    throw new RuntimeException('Product not found or access denied');
  }
  
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
  
  // Check which columns exist in the products table
  $stmt = $pdo->query("SHOW COLUMNS FROM products");
  $existingColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
  
  // Build UPDATE query dynamically based on existing columns
  $updates = [];
  $params = [':id' => $productId, ':tenant_id' => $tenantId];
  
  // Required columns
  $updates[] = "name_en = :name_en";
  $params[':name_en'] = $name_en;
  
  $updates[] = "name_ar = :name_ar";
  $params[':name_ar'] = $name_ar;
  
  $updates[] = "price = :price";
  $params[':price'] = $price;
  
  $updates[] = "is_active = :is_active";
  $params[':is_active'] = $is_active;
  
  $updates[] = "pos_visible = :pos_visible";
  $params[':pos_visible'] = $pos_visible;
  
  // Optional columns - only update if they exist
  $optionalFields = [
    'sku' => $sku,
    'barcode' => $barcode,
    'description' => $description,
    'cost' => $cost,
    'stock_quantity' => $stock_quantity,
    'min_stock' => $min_stock
  ];
  
  foreach ($optionalFields as $field => $value) {
    if (in_array($field, $existingColumns)) {
      $updates[] = "$field = :$field";
      $params[":$field"] = $value;
    }
  }
  
  // Add updated_at if column exists
  if (in_array('updated_at', $existingColumns)) {
    $updates[] = "updated_at = NOW()";
  }
  
  // Execute UPDATE query
  $sql = "UPDATE products SET " . implode(', ', $updates) . " WHERE id = :id AND tenant_id = :tenant_id";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  
  // Update categories (if junction table exists)
  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'product_categories'");
    if ($stmt->fetchColumn()) {
      // Delete existing categories
      $stmt = $pdo->prepare("DELETE FROM product_categories WHERE product_id = :product_id");
      $stmt->execute([':product_id' => $productId]);
      
      // Insert new categories
      if (!empty($_POST['categories']) && is_array($_POST['categories'])) {
        $stmt = $pdo->prepare("
          INSERT INTO product_categories (product_id, category_id) 
          VALUES (:product_id, :category_id)
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
    }
  } catch (Exception $e) {
    // Silently continue if junction table doesn't exist
  }
  
  // Update branches (if junction table exists)
  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'product_branches'");
    if ($stmt->fetchColumn()) {
      // Delete existing branches
      $stmt = $pdo->prepare("DELETE FROM product_branches WHERE product_id = :product_id");
      $stmt->execute([':product_id' => $productId]);
      
      // Insert new branches
      if (!empty($_POST['branches']) && is_array($_POST['branches'])) {
        $stmt = $pdo->prepare("
          INSERT INTO product_branches (product_id, branch_id) 
          VALUES (:product_id, :branch_id)
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
    }
  } catch (Exception $e) {
    // Silently continue if junction table doesn't exist
  }
  
  $pdo->commit();
  
  $_SESSION['flash'] = 'Product updated successfully!';
  header('Location: /views/admin/catalog/products.php');
  exit;
  
} catch (Throwable $e) {
  if (isset($pdo)) {
    $pdo->rollBack();
  }
  $_SESSION['flash'] = 'Error updating product: ' . $e->getMessage();
  header('Location: /views/admin/catalog/product_edit.php?id=' . $productId);
  exit;
}