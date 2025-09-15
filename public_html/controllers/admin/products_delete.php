<?php
/**
 * /public_html/controllers/admin/products_delete.php
 * Handle product deletion with all relationships
 */
declare(strict_types=1);

// Bootstrap and session
require_once __DIR__ . '/../../config/db.php';
use_backend_session();

// Auth check
$user = $_SESSION['user'] ?? null;
if (!$user) { 
    header('Location: /views/auth/login.php'); 
    exit; 
}
$tenantId = (int)($user['tenant_id'] ?? 0);

// Get product ID
$productId = (int)($_GET['id'] ?? 0);
if ($productId <= 0) {
    $_SESSION['flash'] = 'Invalid product ID';
    header('Location: /views/admin/products.php');
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Verify product belongs to tenant
    $stmt = $pdo->prepare("
        SELECT id, name_en, name_ar, image_path 
        FROM products 
        WHERE id = :id AND tenant_id = :t
    ");
    $stmt->execute([':id' => $productId, ':t' => $tenantId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        throw new RuntimeException('Product not found or access denied');
    }
    
    // Delete image file if exists
    if (!empty($product['image_path'])) {
        $imagePath = __DIR__ . '/../..' . $product['image_path'];
        if (file_exists($imagePath)) {
            @unlink($imagePath);
        }
    }
    
    // Delete all relationships in proper order
    
    // 1. Delete from order_items (if any)
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'order_items'");
        if ($stmt->fetchColumn()) {
            // Check if product is used in orders
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = :p");
            $stmt->execute([':p' => $productId]);
            if ($stmt->fetchColumn() > 0) {
                // Product is in orders - soft delete instead
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET is_active = 0, pos_visible = 0 
                    WHERE id = :id AND tenant_id = :t
                ");
                $stmt->execute([':id' => $productId, ':t' => $tenantId]);
                
                $pdo->commit();
                
                $productName = $product['name_en'] ?: $product['name_ar'] ?: 'Product #' . $productId;
                $_SESSION['flash'] = "Product '{$productName}' has been deactivated (has order history).";
                header('Location: /views/admin/products.php');
                exit;
            }
        }
    } catch (Exception $e) {
        // Table might not exist, continue
    }
    
    // 2. Delete from product_categories
    try {
        $pdo->exec("DELETE FROM product_categories WHERE product_id = $productId");
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // 3. Delete from product_branches
    try {
        $pdo->exec("DELETE FROM product_branches WHERE product_id = $productId");
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // 4. Delete from product_modifiers
    try {
        $pdo->exec("DELETE FROM product_modifiers WHERE product_id = $productId");
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // 5. Delete from product_variation_groups
    try {
        $pdo->exec("DELETE FROM product_variation_groups WHERE product_id = $productId");
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // 6. Delete from product_branch_availability
    try {
        $pdo->exec("DELETE FROM product_branch_availability WHERE product_id = $productId");
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // 7. Delete from stockflow_stock_levels
    try {
        $pdo->exec("DELETE FROM stockflow_stock_levels WHERE product_id = $productId");
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // 8. Delete from stockflow_reorder_levels
    try {
        $pdo->exec("DELETE FROM stockflow_reorder_levels WHERE product_id = $productId");
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // Finally, delete the product itself
    $stmt = $pdo->prepare("
        DELETE FROM products 
        WHERE id = :id AND tenant_id = :tenant_id
    ");
    $stmt->execute([':id' => $productId, ':tenant_id' => $tenantId]);
    
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Product could not be deleted');
    }
    
    $pdo->commit();
    
    $productName = $product['name_en'] ?: $product['name_ar'] ?: 'Product #' . $productId;
    $_SESSION['flash'] = "Product '{$productName}' has been deleted successfully.";
    header('Location: /views/admin/products.php');
    exit;
    
} catch (Throwable $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    
    $_SESSION['flash'] = 'Error deleting product: ' . $e->getMessage();
    header('Location: /views/admin/products.php');
    exit;
}