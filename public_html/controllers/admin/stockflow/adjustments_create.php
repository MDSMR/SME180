<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/adjustments_create.php
// AJAX endpoint to create manual stock adjustments
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

stockflow_require_permission('stockflow.adjustments.create');

$branchId = (int)($_POST['branch_id'] ?? 0);
$adjustmentItems = $_POST['adjustment_items'] ?? [];
$reason = trim((string)($_POST['reason'] ?? ''));

if ($branchId <= 0) {
  stockflow_json_response(false, null, 'Invalid branch ID');
}

if (!is_array($adjustmentItems) || empty($adjustmentItems)) {
  stockflow_json_response(false, null, 'No adjustment items specified');
}

if (mb_strlen($reason) > 500) {
  stockflow_json_response(false, null, 'Reason cannot exceed 500 characters');
}

if (empty($reason)) {
  stockflow_json_response(false, null, 'Reason for adjustment is required');
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Verify branch exists and belongs to tenant
  $st = $pdo->prepare("
    SELECT id, name, branch_type
    FROM branches
    WHERE id = :id AND tenant_id = :tenant AND is_active = 1
  ");
  $st->execute([':id' => $branchId, ':tenant' => $tenantId]);
  $branch = $st->fetch(PDO::FETCH_ASSOC);

  if (!$branch) {
    stockflow_json_response(false, null, 'Branch not found or inactive');
  }

  // Branch access check
  if (!stockflow_has_permission('stockflow.view_all_branches')) {
    $userBranches = stockflow_get_user_branches();
    $userBranchIds = array_map(fn($b) => (int)$b['id'], $userBranches);
    
    if (!in_array($branchId, $userBranchIds, true)) {
      stockflow_json_response(false, null, 'Access denied to this branch');
    }
  }

  // Validate adjustment items
  $validatedItems = [];
  $productIds = [];

  foreach ($adjustmentItems as $item) {
    $productId = (int)($item['product_id'] ?? 0);
    $adjustmentQty = (float)($item['adjustment_quantity'] ?? 0);
    $adjustmentType = trim((string)($item['adjustment_type'] ?? '')); // 'increase', 'decrease', or 'set_to'
    $newQuantity = isset($item['new_quantity']) ? (float)$item['new_quantity'] : null;
    
    if ($productId <= 0) {
      continue;
    }

    if (!in_array($adjustmentType, ['increase', 'decrease', 'set_to'], true)) {
      stockflow_json_response(false, null, 'Invalid adjustment type. Use: increase, decrease, or set_to');
    }

    if ($adjustmentType === 'set_to' && $newQuantity === null) {
      stockflow_json_response(false, null, 'New quantity required for set_to adjustment type');
    }

    if ($adjustmentType !== 'set_to' && $adjustmentQty === 0) {
      stockflow_json_response(false, null, 'Adjustment quantity cannot be zero for increase/decrease');
    }

    if ($adjustmentType === 'decrease' && $adjustmentQty < 0) {
      stockflow_json_response(false, null, 'Use positive values for decrease adjustments');
    }

    if (in_array($productId, $productIds, true)) {
      stockflow_json_response(false, null, 'Duplicate product detected in adjustment items');
    }
    
    $productIds[] = $productId;
    $validatedItems[] = [
      'product_id' => $productId,
      'adjustment_type' => $adjustmentType,
      'adjustment_quantity' => $adjustmentQty,
      'new_quantity' => $newQuantity
    ];
  }

  if (empty($validatedItems)) {
    stockflow_json_response(false, null, 'No valid adjustment items found');
  }

  // Verify all products exist and are inventory tracked
  $placeholders = implode(',', array_fill(0, count($productIds), '?'));
  $st = $pdo->prepare("
    SELECT id, name_en, inventory_unit, standard_cost, is_inventory_tracked
    FROM products 
    WHERE id IN ($placeholders) AND tenant_id = ? AND is_active = 1
    ORDER BY name_en
  ");
  $st->execute([...$productIds, $tenantId]);
  $products = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if (count($products) !== count($productIds)) {
    stockflow_json_response(false, null, 'One or more products not found or inactive');
  }

  // Create product lookup map
  $productMap = [];
  foreach ($products as $product) {
    if (!$product['is_inventory_tracked']) {
      stockflow_json_response(false, null, 'Product "' . $product['name_en'] . '" is not inventory tracked');
    }
    $productMap[(int)$product['id']] = $product;
  }

  // Get current stock levels for all products
  $st = $pdo->prepare("
    SELECT product_id, current_stock
    FROM stockflow_stock_levels
    WHERE tenant_id = :tenant AND branch_id = :branch AND product_id IN ($placeholders)
  ");
  $st->execute([':tenant' => $tenantId, ':branch' => $branchId, ...$productIds]);
  $currentStocks = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

  $pdo->beginTransaction();
  try {
    $processedAdjustments = [];
    $totalValueImpact = 0;

    // Process each adjustment
    foreach ($validatedItems as $item) {
      $productId = $item['product_id'];
      $product = $productMap[$productId];
      $currentStock = (float)($currentStocks[$productId] ?? 0);
      
      // Calculate the actual quantity change
      $quantityChange = 0;
      $newStock = 0;
      
      switch ($item['adjustment_type']) {
        case 'increase':
          $quantityChange = abs($item['adjustment_quantity']);
          $newStock = $currentStock + $quantityChange;
          break;
          
        case 'decrease':
          $quantityChange = -abs($item['adjustment_quantity']);
          $newStock = max(0, $currentStock + $quantityChange); // Prevent negative stock
          $quantityChange = $newStock - $currentStock; // Recalculate actual change
          break;
          
        case 'set_to':
          $newStock = max(0, $item['new_quantity']);
          $quantityChange = $newStock - $currentStock;
          break;
      }

      if ($quantityChange == 0) {
        continue; // No change needed
      }

      // Update stock level and create movement record
      stockflow_update_stock_level(
        $pdo,
        $tenantId,
        $branchId,
        $productId,
        $quantityChange,
        'adjustment',
        null,
        'adjustment',
        $userId
      );

      // Calculate value impact
      $unitCost = (float)$product['standard_cost'];
      $valueImpact = $quantityChange * $unitCost;
      $totalValueImpact += $valueImpact;

      $processedAdjustments[] = [
        'product_name' => $product['name_en'],
        'product_unit' => $product['inventory_unit'],
        'adjustment_type' => $item['adjustment_type'],
        'previous_stock' => $currentStock,
        'quantity_change' => $quantityChange,
        'new_stock' => $newStock,
        'unit_cost' => $unitCost,
        'value_impact' => $valueImpact
      ];

      // Also log in stock movements with reason
      $pdo->prepare("
        UPDATE stockflow_stock_movements 
        SET notes = :reason
        WHERE tenant_id = :tenant 
          AND branch_id = :branch 
          AND product_id = :product
          AND movement_type = 'adjustment'
          AND created_by_user_id = :user
        ORDER BY id DESC 
        LIMIT 1
      ")->execute([
        ':reason' => $reason,
        ':tenant' => $tenantId,
        ':branch' => $branchId,
        ':product' => $productId,
        ':user' => $userId
      ]);
    }

    if (empty($processedAdjustments)) {
      $pdo->rollBack();
      stockflow_json_response(false, null, 'No adjustments were processed (no changes needed)');
    }

    $pdo->commit();

    stockflow_json_response(true, [
      'message' => sprintf(
        'Stock adjustment completed for %d items at %s',
        count($processedAdjustments),
        $branch['name']
      ),
      'branch_name' => $branch['name'],
      'reason' => $reason,
      'processed_adjustments' => $processedAdjustments,
      'total_items_adjusted' => count($processedAdjustments),
      'total_value_impact' => round($totalValueImpact, 2),
      'created_at' => date('Y-m-d H:i:s'),
      'summary' => [
        'positive_adjustments' => count(array_filter($processedAdjustments, fn($a) => $a['quantity_change'] > 0)),
        'negative_adjustments' => count(array_filter($processedAdjustments, fn($a) => $a['quantity_change'] < 0)),
        'net_value_change' => round($totalValueImpact, 2)
      ]
    ]);

  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

} catch (Throwable $e) {
  error_log('Stock adjustment error: ' . $e->getMessage() . ' | Branch: ' . $branchId . ' | User: ' . $userId);
  stockflow_json_response(false, null, 'Failed to create stock adjustment: ' . $e->getMessage());
}