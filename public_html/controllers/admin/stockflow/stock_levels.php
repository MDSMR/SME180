<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/stock_levels.php
// Get current stock levels by branch
declare(strict_types=1);

/* Bootstrap */
require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/* Permission check */
if (!stockflow_has_permission('stockflow.view')) {
  stockflow_json_response(false, null, 'Insufficient permissions');
}

$branchId = (int)($_GET['branch_id'] ?? 0);
$productId = (int)($_GET['product_id'] ?? 0);
$lowStockOnly = isset($_GET['low_stock_only']) && $_GET['low_stock_only'] === '1';
$limit = min(500, max(10, (int)($_GET['limit'] ?? 100))); // Default 100, max 500

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Build WHERE conditions
  $where = ["sl.tenant_id = :tenant"];
  $args = [':tenant' => $tenantId];
  
  // Only show inventory-tracked active products
  $where[] = "p.is_inventory_tracked = 1";
  $where[] = "p.is_active = 1";
  
  if ($branchId > 0) {
    $where[] = "sl.branch_id = :branch";
    $args[':branch'] = $branchId;
  }
  
  if ($productId > 0) {
    $where[] = "sl.product_id = :product";
    $args[':product'] = $productId;
  }
  
  if ($lowStockOnly) {
    $where[] = "sl.current_stock <= COALESCE(rl.reorder_level, 5)";
  }

  // Branch access control for users without view_all_branches permission
  if (!stockflow_has_permission('stockflow.view_all_branches')) {
    $userBranches = stockflow_get_user_branches();
    $userBranchIds = array_map(static fn($b) => (int)$b['id'], $userBranches);
    
    if (!empty($userBranchIds)) {
      $branchPlaceholders = [];
      foreach ($userBranchIds as $i => $bid) {
        $key = ":ubranch{$i}";
        $branchPlaceholders[] = $key;
        $args[$key] = $bid;
      }
      $where[] = "sl.branch_id IN (" . implode(',', $branchPlaceholders) . ")";
    } else {
      // User has no branch access, return empty result
      stockflow_json_response(true, [
        'stock_levels' => [],
        'summary' => [
          'total_items' => 0,
          'out_of_stock' => 0,
          'low_stock' => 0,
          'normal_stock' => 0,
          'overstock' => 0,
          'total_value' => 0
        ]
      ]);
    }
  }
  
  $whereClause = implode(' AND ', $where);
  
  // Main query - corrected to use proper table names and relationships
  $sql = "
    SELECT 
      sl.current_stock, 
      sl.reserved_stock, 
      sl.last_movement_at,
      sl.created_at as stock_record_created,
      p.id as product_id, 
      p.name_en as product_name, 
      p.name_ar as product_name_ar,
      p.inventory_unit, 
      p.standard_cost,
      b.id as branch_id, 
      b.name as branch_name, 
      b.branch_type,
      COALESCE(rl.reorder_level, 5) as reorder_level,
      COALESCE(rl.max_stock_level, 0) as max_stock_level,
      rl.is_active as has_reorder_rule,
      CASE 
        WHEN sl.current_stock <= 0 THEN 'out_of_stock'
        WHEN sl.current_stock <= COALESCE(rl.reorder_level, 5) THEN 'low_stock'
        WHEN COALESCE(rl.max_stock_level, 0) > 0 AND sl.current_stock >= rl.max_stock_level THEN 'overstock'
        ELSE 'normal'
      END as stock_status
    FROM stockflow_stock_levels sl
    JOIN products p ON p.id = sl.product_id AND p.tenant_id = sl.tenant_id
    JOIN branches b ON b.id = sl.branch_id AND b.tenant_id = sl.tenant_id
    LEFT JOIN stockflow_reorder_levels rl ON (
      rl.tenant_id = sl.tenant_id 
      AND rl.branch_id = sl.branch_id 
      AND rl.product_id = sl.product_id 
      AND rl.is_active = 1
    )
    WHERE {$whereClause}
    ORDER BY b.name ASC, p.name_en ASC
    LIMIT :limit
  ";
  
  $st = $pdo->prepare($sql);
  
  // Bind all parameters
  foreach ($args as $key => $value) {
    $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $st->bindValue(':limit', $limit, PDO::PARAM_INT);
  
  $st->execute();
  $levels = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  
  // Calculate summary statistics
  $summary = [
    'total_items' => count($levels),
    'out_of_stock' => 0,
    'low_stock' => 0,
    'normal_stock' => 0,
    'overstock' => 0,
    'total_value' => 0.00,
    'total_reserved' => 0.00
  ];
  
  foreach ($levels as &$level) {
    // Count by status
    $status = $level['stock_status'];
    if (isset($summary[$status])) {
      $summary[$status]++;
    }
    
    // Calculate totals
    $currentStock = (float)($level['current_stock'] ?? 0);
    $standardCost = (float)($level['standard_cost'] ?? 0);
    $reservedStock = (float)($level['reserved_stock'] ?? 0);
    
    $summary['total_value'] += ($currentStock * $standardCost);
    $summary['total_reserved'] += $reservedStock;
    
    // Format dates for frontend consumption
    if ($level['last_movement_at']) {
      $ts = strtotime($level['last_movement_at']);
      $level['last_movement_formatted'] = $ts ? date('M j, Y H:i', $ts) : 'Invalid date';
    } else {
      $level['last_movement_formatted'] = 'Never';
    }
    
    if ($level['stock_record_created']) {
      $ts = strtotime($level['stock_record_created']);
      $level['stock_created_formatted'] = $ts ? date('M j, Y H:i', $ts) : 'Invalid date';
    } else {
      $level['stock_created_formatted'] = 'Unknown';
    }
    
    // Add calculated fields
    $level['available_stock'] = max(0, $currentStock - $reservedStock);
    $level['stock_value'] = $currentStock * $standardCost;
    $level['is_below_reorder'] = $currentStock <= (float)$level['reorder_level'];
    $level['is_out_of_stock'] = $currentStock <= 0;
  }
  
  // Round summary totals to 2 decimal places
  $summary['total_value'] = round($summary['total_value'], 2);
  $summary['total_reserved'] = round($summary['total_reserved'], 2);
  
  stockflow_json_response(true, [
    'stock_levels' => $levels,
    'summary' => $summary,
    'filters_applied' => [
      'branch_id' => $branchId,
      'product_id' => $productId,
      'low_stock_only' => $lowStockOnly,
      'limit' => $limit
    ]
  ]);
  
} catch (Throwable $e) {
  error_log('Stock levels fetch error: ' . $e->getMessage() . ' | User: ' . $userId . ' | Tenant: ' . $tenantId);
  stockflow_json_response(false, null, 'Failed to fetch stock levels: ' . $e->getMessage());
}