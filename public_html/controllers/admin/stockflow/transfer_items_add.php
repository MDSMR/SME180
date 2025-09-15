<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/transfer_items_add.php
// AJAX endpoint to add items to existing transfer
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

stockflow_require_permission('stockflow.transfers.edit');

$transferId = (int)($_POST['transfer_id'] ?? 0);
$productId = (int)($_POST['product_id'] ?? 0);
$quantity = (float)($_POST['quantity'] ?? 0);

if ($transferId <= 0) {
  stockflow_json_response(false, null, 'Invalid transfer ID');
}

if ($productId <= 0) {
  stockflow_json_response(false, null, 'Invalid product ID');
}

if ($quantity <= 0) {
  stockflow_json_response(false, null, 'Quantity must be greater than zero');
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Verify transfer exists and can be edited
  $st = $pdo->prepare("
    SELECT id, status, from_branch_id, to_branch_id
    FROM stockflow_transfers
    WHERE id = :id AND tenant_id = :tenant
  ");
  $st->execute([':id' => $transferId, ':tenant' => $tenantId]);
  $transfer = $st->fetch(PDO::FETCH_ASSOC);

  if (!$transfer) {
    stockflow_json_response(false, null, 'Transfer not found');
  }

  if ($transfer['status'] !== 'pending') {
    stockflow_json_response(false, null, 'Cannot add items to non-pending transfer');
  }

  // Branch access check
  if (!stockflow_has_permission('stockflow.view_all_branches')) {
    $userBranches = stockflow_get_user_branches();
    $userBranchIds = array_map(fn($b) => (int)$b['id'], $userBranches);
    
    if (!in_array((int)$transfer['from_branch_id'], $userBranchIds, true) && 
        !in_array((int)$transfer['to_branch_id'], $userBranchIds, true)) {
      stockflow_json_response(false, null, 'Access denied to this transfer');
    }
  }

  // Verify product exists and is inventory tracked
  $st = $pdo->prepare("
    SELECT id, name_en, name_ar, inventory_unit, standard_cost, is_inventory_tracked
    FROM products 
    WHERE id = :id AND tenant_id = :tenant AND is_active = 1
  ");
  $st->execute([':id' => $productId, ':tenant' => $tenantId]);
  $product = $st->fetch(PDO::FETCH_ASSOC);

  if (!$product) {
    stockflow_json_response(false, null, 'Product not found or inactive');
  }

  if (!$product['is_inventory_tracked']) {
    stockflow_json_response(false, null, 'Product is not inventory tracked');
  }

  // Check current stock level at source branch
  $st = $pdo->prepare("
    SELECT current_stock, reserved_stock
    FROM stockflow_stock_levels
    WHERE tenant_id = :tenant AND branch_id = :branch AND product_id = :product
  ");
  $st->execute([
    ':tenant' => $tenantId,
    ':branch' => $transfer['from_branch_id'],
    ':product' => $productId
  ]);
  $stockLevel = $st->fetch(PDO::FETCH_ASSOC);
  
  $currentStock = $stockLevel ? (float)$stockLevel['current_stock'] : 0;
  $reservedStock = $stockLevel ? (float)$stockLevel['reserved_stock'] : 0;
  $availableStock = $currentStock - $reservedStock;

  if ($availableStock < $quantity) {
    stockflow_json_response(false, null, sprintf(
      'Insufficient stock. Available: %.2f %s, Requested: %.2f %s',
      $availableStock,
      $product['inventory_unit'],
      $quantity,
      $product['inventory_unit']
    ));
  }

  $pdo->beginTransaction();
  try {
    // Check if item already exists in transfer
    $st = $pdo->prepare("
      SELECT id, quantity_requested 
      FROM stockflow_transfer_items 
      WHERE transfer_id = :tid AND product_id = :pid
    ");
    $st->execute([':tid' => $transferId, ':pid' => $productId]);
    $existingItem = $st->fetch(PDO::FETCH_ASSOC);

    if ($existingItem) {
      // Update existing item quantity
      $newQuantity = (float)$existingItem['quantity_requested'] + $quantity;
      
      // Check if new total quantity exceeds available stock
      if ($availableStock < $newQuantity) {
        $pdo->rollBack();
        stockflow_json_response(false, null, sprintf(
          'Total quantity would exceed available stock. Current request: %.2f, Additional: %.2f, Available: %.2f',
          (float)$existingItem['quantity_requested'],
          $quantity,
          $availableStock
        ));
      }
      
      $pdo->prepare("
        UPDATE stockflow_transfer_items 
        SET quantity_requested = :qty,
            total_cost = :cost * :qty,
            updated_at = NOW()
        WHERE id = :id
      ")->execute([
        ':qty' => $newQuantity,
        ':cost' => $product['standard_cost'],
        ':id' => $existingItem['id']
      ]);

      $message = 'Item quantity updated in transfer';
      $itemId = $existingItem['id'];
    } else {
      // Add new item
      $pdo->prepare("
        INSERT INTO stockflow_transfer_items 
        (transfer_id, product_id, product_name, quantity_requested, unit_cost, total_cost, created_at, updated_at)
        VALUES (:tid, :pid, :pname, :qty, :cost, :total, NOW(), NOW())
      ")->execute([
        ':tid' => $transferId,
        ':pid' => $productId,
        ':pname' => $product['name_en'],
        ':qty' => $quantity,
        ':cost' => $product['standard_cost'],
        ':total' => $quantity * (float)$product['standard_cost']
      ]);

      $itemId = (int)$pdo->lastInsertId();
      $message = 'Item added to transfer successfully';
    }

    // Update transfer total_items count and updated timestamp
    $pdo->prepare("
      UPDATE stockflow_transfers 
      SET total_items = (
        SELECT COUNT(*) FROM stockflow_transfer_items WHERE transfer_id = :tid
      ), updated_at = NOW()
      WHERE id = :tid
    ")->execute([':tid' => $transferId]);

    $pdo->commit();

    // Return updated item details
    $st = $pdo->prepare("
      SELECT ti.*, p.inventory_unit
      FROM stockflow_transfer_items ti
      JOIN products p ON p.id = ti.product_id
      WHERE ti.id = :id
    ");
    $st->execute([':id' => $itemId]);
    $itemDetails = $st->fetch(PDO::FETCH_ASSOC);

    stockflow_json_response(true, [
      'message' => $message,
      'item' => $itemDetails,
      'stock_info' => [
        'current_stock' => $currentStock,
        'available_stock' => $availableStock,
        'reserved_stock' => $reservedStock
      ]
    ]);

  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

} catch (Throwable $e) {
  error_log('Add transfer item error: ' . $e->getMessage() . ' | Transfer: ' . $transferId . ' | Product: ' . $productId);
  stockflow_json_response(false, null, 'Failed to add item to transfer: ' . $e->getMessage());
}