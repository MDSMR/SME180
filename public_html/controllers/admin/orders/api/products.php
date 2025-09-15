<?php
// /public_html/controllers/admin/orders/api/products.php
// API endpoint for loading products by branch with stock information and variations
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/config/db.php';
require_once dirname(__DIR__, 4) . '/middleware/auth_login.php';
require_once __DIR__ . '/../_helpers.php';

auth_require_login();
use_backend_session();

header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if (!$user) {
    error_response('Unauthorized', 401);
}

$tenantId = (int)$user['tenant_id'];

// Get parameters
$branchId = (int)($_POST['branch_id'] ?? $_GET['branch_id'] ?? 0);
$requestTenantId = (int)($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
$includeVariations = (bool)($_POST['include_variations'] ?? $_GET['include_variations'] ?? false);

// Validate parameters
if ($branchId <= 0) {
    error_response('Valid branch ID is required');
}

if ($requestTenantId !== $tenantId) {
    error_response('Tenant ID mismatch');
}

try {
    $pdo = db();
    
    // Verify branch belongs to tenant
    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = :id AND tenant_id = :t");
    $stmt->execute([':id' => $branchId, ':t' => $tenantId]);
    if (!$stmt->fetchColumn()) {
        error_response('Branch not found or access denied');
    }
    
    // Get products for this branch with stock information
    $stmt = $pdo->prepare("
        SELECT 
            p.id as product_id,
            p.name_en as product_name,
            p.name_ar as product_name_ar,
            p.price,
            p.standard_cost,
            p.is_open_price,
            p.is_inventory_tracked,
            p.inventory_unit,
            p.weight_kg,
            p.calories,
            p.prep_time_min,
            p.description,
            p.description_ar,
            
            -- Category information
            GROUP_CONCAT(DISTINCT c.name_en ORDER BY c.name_en SEPARATOR ', ') as category_name,
            GROUP_CONCAT(DISTINCT c.name_ar ORDER BY c.name_ar SEPARATOR ', ') as category_name_ar,
            GROUP_CONCAT(DISTINCT c.id ORDER BY c.id SEPARATOR ',') as category_ids,
            
            -- Stock information
            CASE 
                WHEN p.is_inventory_tracked = 1 THEN COALESCE(stock.current_stock, 0)
                ELSE 999999
            END as available_stock,
            
            CASE 
                WHEN p.is_inventory_tracked = 1 THEN COALESCE(stock.reserved_stock, 0)
                ELSE 0
            END as reserved_stock,
            
            -- Reorder level information
            COALESCE(reorder.reorder_level, 0) as reorder_level,
            COALESCE(reorder.max_stock_level, 0) as max_stock_level,
            
            -- Branch availability override
            COALESCE(availability.is_available, 1) as branch_available,
            availability.price_override as branch_price_override
            
        FROM products p
        
        -- Only products assigned to this branch
        INNER JOIN product_branches pb ON pb.product_id = p.id AND pb.branch_id = ?
        
        -- Category information
        LEFT JOIN product_categories pc ON pc.product_id = p.id
        LEFT JOIN categories c ON c.id = pc.category_id AND c.tenant_id = p.tenant_id AND c.is_active = 1
        
        -- Stock levels for this branch
        LEFT JOIN stockflow_stock_levels stock ON stock.product_id = p.id 
            AND stock.branch_id = ? AND stock.tenant_id = p.tenant_id
        
        -- Reorder levels
        LEFT JOIN stockflow_reorder_levels reorder ON reorder.product_id = p.id 
            AND reorder.branch_id = ? AND reorder.tenant_id = p.tenant_id AND reorder.is_active = 1
        
        -- Branch-specific availability overrides
        LEFT JOIN product_branch_availability availability ON availability.product_id = p.id AND availability.branch_id = ?
        
        WHERE p.tenant_id = ?
        AND p.is_active = 1
        AND p.pos_visible = 1
        AND (availability.is_available IS NULL OR availability.is_available = 1)
        
        GROUP BY p.id, p.name_en, p.name_ar, p.price, p.standard_cost, p.is_open_price, 
                 p.is_inventory_tracked, p.inventory_unit, p.weight_kg, p.calories, 
                 p.prep_time_min, p.description, p.description_ar, stock.current_stock, 
                 stock.reserved_stock, reorder.reorder_level, reorder.max_stock_level,
                 availability.is_available, availability.price_override
        
        ORDER BY 
            CASE WHEN category_name IS NOT NULL THEN category_name ELSE 'ZZZ_Uncategorized' END,
            p.name_en
    ");
    
    $stmt->execute([
        $branchId,    // pb.branch_id
        $branchId,    // stock.branch_id  
        $branchId,    // reorder.branch_id
        $branchId,    // availability.branch_id
        $tenantId     // p.tenant_id
    ]);
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process products
    foreach ($products as &$product) {
        // Convert numeric fields
        $product['product_id'] = (int)$product['product_id'];
        $product['price'] = (float)$product['price'];
        $product['standard_cost'] = (float)$product['standard_cost'];
        $product['available_stock'] = (float)$product['available_stock'];
        $product['reserved_stock'] = (float)$product['reserved_stock'];
        $product['reorder_level'] = (float)($product['reorder_level'] ?? 0);
        $product['max_stock_level'] = (float)($product['max_stock_level'] ?? 0);
        $product['weight_kg'] = (float)($product['weight_kg'] ?? 0);
        $product['calories'] = (int)($product['calories'] ?? 0);
        $product['prep_time_min'] = (int)($product['prep_time_min'] ?? 0);
        
        // Convert boolean fields
        $product['is_open_price'] = (bool)$product['is_open_price'];
        $product['is_inventory_tracked'] = (bool)$product['is_inventory_tracked'];
        $product['branch_available'] = $product['branch_available'] !== null ? (bool)$product['branch_available'] : true;
        
        // Handle branch price override
        if ($product['branch_price_override'] !== null && (float)$product['branch_price_override'] > 0) {
            $product['price'] = (float)$product['branch_price_override'];
            $product['has_price_override'] = true;
        } else {
            $product['has_price_override'] = false;
        }
        unset($product['branch_price_override']);
        
        // Set default inventory unit if empty
        if (empty($product['inventory_unit'])) {
            $product['inventory_unit'] = 'piece';
        }
        
        // Calculate stock status
        $product['stock_status'] = 'available';
        if ($product['is_inventory_tracked']) {
            if ($product['available_stock'] <= 0) {
                $product['stock_status'] = 'out_of_stock';
            } elseif ($product['reorder_level'] > 0 && $product['available_stock'] <= $product['reorder_level']) {
                $product['stock_status'] = 'low_stock';
            }
        }
        
        // Process category information
        if (!empty($product['category_ids'])) {
            $categoryIds = array_map('intval', explode(',', $product['category_ids']));
            $product['category_ids'] = $categoryIds;
            
            // Use first category as primary
            $categories = explode(', ', $product['category_name'] ?? '');
            $product['primary_category'] = $categories[0] ?? null;
        } else {
            $product['category_ids'] = [];
            $product['primary_category'] = null;
        }
        
        // Clean up display names
        $product['display_name'] = $product['product_name'];
        if (!empty($product['product_name_ar'])) {
            $product['display_name_ar'] = $product['product_name_ar'];
        }

        // Load variations/modifiers for this product
        $product['variations'] = [];
        if ($includeVariations) {
            $variationsStmt = $pdo->prepare("
                SELECT 
                    vg.id as group_id,
                    vg.name as group_name,
                    vg.is_required,
                    vg.min_select,
                    vg.max_select,
                    vv.id as value_id,
                    vv.value_en,
                    vv.value_ar,
                    vv.price_delta
                FROM product_variation_groups pvg
                INNER JOIN variation_groups vg ON vg.id = pvg.group_id
                LEFT JOIN variation_values vv ON vv.group_id = vg.id AND vv.is_active = 1
                WHERE pvg.product_id = :pid
                AND vg.tenant_id = :tid
                AND vg.is_active = 1
                AND vg.pos_visible = 1
                ORDER BY pvg.sort_order, vg.sort_order, vv.sort_order
            ");
            $variationsStmt->execute([':pid' => $product['product_id'], ':tid' => $tenantId]);
            $variations = $variationsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group variations by group
            $groups = [];
            foreach ($variations as $v) {
                if (!isset($groups[$v['group_id']])) {
                    $groups[$v['group_id']] = [
                        'id' => (int)$v['group_id'],
                        'name' => $v['group_name'],
                        'is_required' => (bool)$v['is_required'],
                        'min_select' => (int)$v['min_select'],
                        'max_select' => (int)$v['max_select'],
                        'values' => []
                    ];
                }
                if ($v['value_id']) {
                    $groups[$v['group_id']]['values'][] = [
                        'id' => (int)$v['value_id'],
                        'name_en' => $v['value_en'],
                        'name_ar' => $v['value_ar'],
                        'price_delta' => (float)$v['price_delta']
                    ];
                }
            }
            $product['variations'] = array_values($groups);
        }
    }
    
    // Get categories for filter dropdown
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.name_en, c.name_ar, c.sort_order
        FROM categories c
        INNER JOIN product_categories pc ON pc.category_id = c.id
        INNER JOIN products p ON p.id = pc.product_id
        INNER JOIN product_branches pb ON pb.product_id = p.id
        WHERE c.tenant_id = :tenant_id 
        AND c.is_active = 1
        AND p.is_active = 1
        AND p.pos_visible = 1
        AND pb.branch_id = :branch_id
        ORDER BY c.sort_order, c.name_en
    ");
    
    $stmt->execute([':tenant_id' => $tenantId, ':branch_id' => $branchId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process categories
    foreach ($categories as &$category) {
        $category['id'] = (int)$category['id'];
        $category['sort_order'] = (int)$category['sort_order'];
    }
    
    // Get branch information for context
    $stmt = $pdo->prepare("
        SELECT 
            id, name, display_name, branch_type, 
            is_production_enabled, timezone
        FROM branches 
        WHERE id = :id AND tenant_id = :t
    ");
    $stmt->execute([':id' => $branchId, ':t' => $tenantId]);
    $branchInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($branchInfo) {
        $branchInfo['id'] = (int)$branchInfo['id'];
        $branchInfo['is_production_enabled'] = (bool)$branchInfo['is_production_enabled'];
    }
    
    // Statistics
    $stats = [
        'total_products' => count($products),
        'tracked_products' => count(array_filter($products, fn($p) => $p['is_inventory_tracked'])),
        'out_of_stock' => count(array_filter($products, fn($p) => $p['stock_status'] === 'out_of_stock')),
        'low_stock' => count(array_filter($products, fn($p) => $p['stock_status'] === 'low_stock')),
        'categories' => count($categories),
        'products_with_variations' => count(array_filter($products, fn($p) => !empty($p['variations'])))
    ];
    
    success_response('Products loaded successfully', [
        'products' => $products,
        'categories' => $categories,
        'branch' => $branchInfo,
        'stats' => $stats,
        'loaded_at' => date('Y-m-d H:i:s'),
        'branch_id' => $branchId,
        'tenant_id' => $tenantId
    ]);
    
} catch (Throwable $e) {
    error_log('[products_api] Error loading products for branch ' . $branchId . ': ' . $e->getMessage());
    error_response('Failed to load products: ' . $e->getMessage(), 500);
}