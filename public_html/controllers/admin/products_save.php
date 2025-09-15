<?php
/**
 * /public_html/controllers/admin/products_save.php
 * Handles both CREATE and UPDATE for products with variations
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

// CSRF validation
$csrf = $_POST['csrf'] ?? '';
if ($csrf !== ($_SESSION['csrf_products'] ?? '')) {
    $_SESSION['flash'] = 'Invalid CSRF token. Please try again.';
    header('Location: /views/admin/catalog/products.php');
    exit;
}

// Determine if UPDATE or CREATE
$productId = (int)($_POST['id'] ?? 0);
$isUpdate = $productId > 0;

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Prepare data
    $name_en = trim($_POST['name_en'] ?? '');
    $name_ar = trim($_POST['name_ar'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $description_ar = trim($_POST['description_ar'] ?? '');
    $is_open_price = isset($_POST['is_open_price']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $pos_visible = isset($_POST['pos_visible']) ? 1 : 0;
    $is_inventory_tracked = isset($_POST['is_inventory_tracked']) ? 1 : 0;
    $inventory_unit = trim($_POST['inventory_unit'] ?? 'piece');
    
    // Price handling - if open price, set to 0
    $price = $is_open_price ? 0 : (float)($_POST['price'] ?? 0);
    $standard_cost = $is_open_price ? 0 : (float)($_POST['standard_cost'] ?? 0);
    
    // Optional fields
    $weight_kg = !empty($_POST['weight_kg']) ? (float)$_POST['weight_kg'] : null;
    $calories = !empty($_POST['calories']) ? (int)$_POST['calories'] : null;
    $prep_time_min = !empty($_POST['prep_time_min']) ? (int)$_POST['prep_time_min'] : null;
    
    // Validate required fields
    if (empty($name_en)) {
        throw new RuntimeException('Product name (English) is required');
    }
    
    // Handle image upload
    $image_path = null;
    if (!empty($_FILES['image']['name'])) {
        $uploadDir = __DIR__ . '/../../uploads/products/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            throw new RuntimeException('Invalid image format. Use JPG, PNG, or WebP.');
        }
        
        if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Image size exceeds 5MB limit.');
        }
        
        $filename = uniqid('prod_') . '.' . $ext;
        $fullPath = $uploadDir . $filename;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $fullPath)) {
            $image_path = '/uploads/products/' . $filename;
            
            // Delete old image if updating
            if ($isUpdate) {
                $stmt = $pdo->prepare("SELECT image_path FROM products WHERE id = :id AND tenant_id = :t");
                $stmt->execute([':id' => $productId, ':t' => $tenantId]);
                $oldImage = $stmt->fetchColumn();
                if ($oldImage) {
                    $oldPath = __DIR__ . '/../..' . $oldImage;
                    if (file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }
            }
        }
    }
    
    if ($isUpdate) {
        // UPDATE existing product
        $sql = "UPDATE products SET 
                name_en = :name_en,
                name_ar = :name_ar,
                description = :description,
                description_ar = :description_ar,
                price = :price,
                standard_cost = :standard_cost,
                is_open_price = :is_open_price,
                weight_kg = :weight_kg,
                calories = :calories,
                prep_time_min = :prep_time_min,
                is_active = :is_active,
                pos_visible = :pos_visible,
                is_inventory_tracked = :is_inventory_tracked,
                inventory_unit = :inventory_unit,
                updated_at = NOW()";
        
        // Only update image if new one uploaded
        if ($image_path) {
            $sql .= ", image_path = :image_path";
        }
        
        $sql .= " WHERE id = :id AND tenant_id = :tenant_id";
        
        $params = [
            ':id' => $productId,
            ':tenant_id' => $tenantId,
            ':name_en' => $name_en,
            ':name_ar' => $name_ar,
            ':description' => $description,
            ':description_ar' => $description_ar,
            ':price' => $price,
            ':standard_cost' => $standard_cost,
            ':is_open_price' => $is_open_price,
            ':weight_kg' => $weight_kg,
            ':calories' => $calories,
            ':prep_time_min' => $prep_time_min,
            ':is_active' => $is_active,
            ':pos_visible' => $pos_visible,
            ':is_inventory_tracked' => $is_inventory_tracked,
            ':inventory_unit' => $inventory_unit
        ];
        
        if ($image_path) {
            $params[':image_path'] = $image_path;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
    } else {
        // CREATE new product
        $sql = "INSERT INTO products (
                    tenant_id, name_en, name_ar, description, description_ar,
                    price, standard_cost, is_open_price,
                    weight_kg, calories, prep_time_min,
                    is_active, pos_visible, image_path,
                    is_inventory_tracked, inventory_unit,
                    created_at, updated_at
                ) VALUES (
                    :tenant_id, :name_en, :name_ar, :description, :description_ar,
                    :price, :standard_cost, :is_open_price,
                    :weight_kg, :calories, :prep_time_min,
                    :is_active, :pos_visible, :image_path,
                    :is_inventory_tracked, :inventory_unit,
                    NOW(), NOW()
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':name_en' => $name_en,
            ':name_ar' => $name_ar,
            ':description' => $description,
            ':description_ar' => $description_ar,
            ':price' => $price,
            ':standard_cost' => $standard_cost,
            ':is_open_price' => $is_open_price,
            ':weight_kg' => $weight_kg,
            ':calories' => $calories,
            ':prep_time_min' => $prep_time_min,
            ':is_active' => $is_active,
            ':pos_visible' => $pos_visible,
            ':image_path' => $image_path,
            ':is_inventory_tracked' => $is_inventory_tracked,
            ':inventory_unit' => $inventory_unit
        ]);
        
        $productId = $pdo->lastInsertId();
    }
    
    // Handle Categories (product_categories table)
    $pdo->exec("DELETE FROM product_categories WHERE product_id = $productId");
    if (!empty($_POST['categories']) && is_array($_POST['categories'])) {
        $stmt = $pdo->prepare("INSERT INTO product_categories (product_id, category_id) VALUES (:p, :c)");
        foreach ($_POST['categories'] as $catId) {
            if (is_numeric($catId) && $catId > 0) {
                $stmt->execute([':p' => $productId, ':c' => (int)$catId]);
            }
        }
    }
    
    // Handle Branches (product_branches table)
    $pdo->exec("DELETE FROM product_branches WHERE product_id = $productId");
    if (!empty($_POST['branches']) && is_array($_POST['branches'])) {
        $stmt = $pdo->prepare("INSERT INTO product_branches (product_id, branch_id) VALUES (:p, :b)");
        foreach ($_POST['branches'] as $branchId) {
            if (is_numeric($branchId) && $branchId > 0) {
                $stmt->execute([':p' => $productId, ':b' => (int)$branchId]);
            }
        }
    }
    
    // Handle Modifiers/Variations (product_variation_groups table)
    $pdo->exec("DELETE FROM product_variation_groups WHERE product_id = $productId");
    
    $mod_groups = $_POST['mod_groups'] ?? [];
    $mod_values = $_POST['mod_values'] ?? [];
    
    if (is_array($mod_groups) && is_array($mod_values) && count($mod_groups) === count($mod_values)) {
        // Create a mapping of groups to their selected values
        $groupValueMap = [];
        for ($i = 0; $i < count($mod_groups); $i++) {
            $groupId = (int)$mod_groups[$i];
            $valueId = (int)$mod_values[$i];
            if ($groupId > 0 && $valueId > 0) {
                if (!isset($groupValueMap[$groupId])) {
                    $groupValueMap[$groupId] = [];
                }
                $groupValueMap[$groupId][] = $valueId;
            }
        }
        
        // Insert unique group associations with sort order
        $stmt = $pdo->prepare("
            INSERT INTO product_variation_groups (product_id, group_id, sort_order) 
            VALUES (:p, :g, :s)
        ");
        
        $sortOrder = 0;
        foreach ($groupValueMap as $groupId => $values) {
            // Insert once per group (the junction table only tracks which groups are available for the product)
            $stmt->execute([
                ':p' => $productId, 
                ':g' => $groupId, 
                ':s' => $sortOrder++
            ]);
        }
    }
    
    $pdo->commit();
    
    // Set success message
    if ($isUpdate) {
        $_SESSION['flash'] = 'Product updated successfully!';
        // Redirect to product edit page to show the update
        header('Location: /views/admin/catalog/product_edit.php?id=' . $productId . '&success=1');
    } else {
        $_SESSION['flash'] = 'Product created successfully!';
        // Check for save_and_new action
        if (($_POST['action'] ?? '') === 'save_and_new') {
            header('Location: /views/admin/catalog/products_new.php');
        } else {
            header('Location: /views/admin/catalog/products.php');
        }
    }
    exit;
    
} catch (Throwable $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    
    $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    
    if ($isUpdate) {
        header('Location: /views/admin/catalog/product_edit.php?id=' . $productId);
    } else {
        header('Location: /views/admin/catalog/products_new.php');
    }
    exit;
}