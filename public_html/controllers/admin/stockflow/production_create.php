<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/production_create.php
// AJAX endpoint to create production entries (stock increases from manufacturing/preparation)
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

stockflow_require_permission('stockflow.production.create');

$branchId = (int)($_POST['branch_id'] ?? 0);
$productionItems = $_POST['production_items'] ?? [];
$notes = trim((string)($_POST['notes'] ?? ''));

if ($branchId <= 0) {
  stockflow_json_response(false, null, 'Invalid branch ID');
}

if (!is_array($productionItems) || empty($productionItems)) {
  stockflow_json_response(false, null, 'No production items specified');
}

if (mb_strlen($notes) > 500) {
  stockflow_json_response(false, null, 'Notes cannot exceed 500 characters');
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Verify branch exists and belongs to tenant
  $st = $pdo->prepare("
    SELECT id, name, branch_type, is_production_enabled
    FROM branches
    WHERE id = :id AND tenant_id = :tenant AND is_active = 1
  ");
  $st->execute([':id' => $branchId, ':tenant' => $tenantId]);
  $branch = $st->fetch(PDO::FETCH_ASSOC);

  if (!$branch) {
    stockflow_json_response(false, null, 'Branch not found or inactive');
  }

  if (!$branch['is_production_enabled']) {
    stockflow_json_response(false, null, 'Production is not enabled for this branch');
  }

  // Branch access check
  if (!stockflow_has_permission('stockflow.view_all_branches')) {
    $userBranches = stockflow_get_user_branches();
    $userBranchIds = array_map(fn($b) => (int)$b['id'], $userBranches);
    
    if (!in_array($branchId, $userBranchIds, true)) {
      stockflow_json_response(false, null, 'Access denied to this branch');
    }
  }

  // Validate production items
  $validatedItems = [];
  $productIds = [];

  foreach ($productionItems as $item) {
    $productId = (int)($item['product_id'] ?? 0);
    $quantity = (float)($item['quantity'] ?? 0);
    
    if ($productId <= 0 || $quantity <= 0) {
      continue;
    }
    
    if (in_array($productId, $productIds, true)) {
      stockflow_json_response(false, null, 'Duplicate product detected in production items');
    }
    
    $productIds[] = $productId;
    $validatedItems[] = [
      'product_id' => $productId,
      'quantity' => $quantity
    ];
  }

  if (empty($validatedItems)) {
    stockflow_json_response(false, null, 'No valid production items found');
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

  $pdo->beginTransaction();
  try {
    // Create production entry record (using transfers table with special type)
    $productionNumber = stockflow_next_transfer_number($pdo, $tenantId, 'PRD');
    
    $pdo->prepare("
      INSERT INTO stockflow_transfers (
        tenant_id, 
        transfer_number, 
        from_branch_id, 
        to_branch_id, 
        status, 
        transfer_type, 
        notes, 
        total_items,
        created_by_user_id,
        shipped_at,
        shipped_by_user_id,
        received_at,
        received_by_user_id,
        created_at
      ) VALUES (
        :tenant_id, 
        :number, 
        :branch_id, 
        :branch_id, 
        'received', 
        'production_transfer', 
        :notes, 
        :total_items,
        :user_id,
        NOW(),
        :user_id,
        NOW(),
        :user_id,
        NOW()
      )
    ")->execute([
      ':tenant_id' => $tenantId,
      ':number' => $productionNumber,
      ':branch_id' => $branchId,
      ':notes' => ($notes !== '' ? $notes : null),
      ':total_items' => count($validatedItems),
      ':user_id' => $userId
    ]);

    $productionId = (int)$pdo->lastInsertId();
    $processedItems = [];
    $totalValue = 0;

    // Process each production item
    foreach ($validatedItems as $item) {
      $product = $productMap[$item['product_id']];
      $quantity = $item['quantity'];
      $unitCost = (float)$product['standard_cost'];
      $totalCost = $quantity * $unitCost;
      $totalValue += $totalCost;

      // Add to transfer items for record keeping
      $pdo->prepare("
        INSERT INTO stockflow_transfer_items (
          transfer_id, 
          product_id, 
          product_name, 
          quantity_requested, 
          quantity_shipped, 
          quantity_received, 
          unit_cost, 
          total_cost,
          created_at,
          updated_at
        ) VALUES (
          :transfer_id, 
          :product_id, 
          :product_name, 
          :quantity, 
          :quantity, 
          :quantity, 
          :unit_cost, 
          :total_cost,
          NOW(),
          NOW()
        )
      ")->execute([
        ':transfer_id' => $productionId,
        ':product_id' => $item['product_id'],
        ':product_name' => $product['name_en'],
        ':quantity' => $quantity,
        ':unit_cost' => $unitCost,
        ':total_cost' => $totalCost
      ]);

      // Update stock levels
      stockflow_update_stock_level(
        $pdo,
        $tenantId,
        $branchId,
        $item['product_id'],
        $quantity,
        'production_in',
        $productionId,
        'production',
        $userId
      );

      $processedItems[] = [
        'product_name' => $product['name_en'],
        'quantity' => $quantity,
        'unit' => $product['inventory_unit'],
        'unit_cost' => $unitCost,
        'total_cost' => $totalCost
      ];
    }

    $pdo->commit();

    stockflow_json_response(true, [
      'message' => sprintf(
        'Production entry %s created successfully with %d items',
        $productionNumber,
        count($processedItems)
      ),
      'production_id' => $productionId,
      'production_number' => $productionNumber,
      'branch_name' => $branch['name'],
      'processed_items' => $processedItems,
      'total_items' => count($processedItems),
      'total_value' => round($totalValue, 2),
      'created_at' => date('Y-m-d H:i:s')
    ]);

  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

} catch (Throwable $e) {
  error_log('Production create error: ' . $e->getMessage() . ' | Branch: ' . $branchId . ' | User: ' . $userId);
  stockflow_json_response(false, null, 'Failed to create production entry: ' . $e->getMessage());
}