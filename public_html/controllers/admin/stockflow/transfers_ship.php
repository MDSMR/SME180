<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/transfers_ship.php
// AJAX endpoint to ship pending transfers
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

stockflow_require_permission('stockflow.transfers.ship');

$transferId = (int)($_POST['transfer_id'] ?? 0);

if ($transferId <= 0) {
  stockflow_json_response(false, null, 'Invalid transfer ID');
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Get transfer details with items
  $st = $pdo->prepare("
    SELECT t.*, fb.name as from_branch_name, tb.name as to_branch_name
    FROM stockflow_transfers t
    LEFT JOIN branches fb ON fb.id = t.from_branch_id AND fb.tenant_id = t.tenant_id
    LEFT JOIN branches tb ON tb.id = t.to_branch_id AND tb.tenant_id = t.tenant_id
    WHERE t.id = :id AND t.tenant_id = :tenant
  ");
  $st->execute([':id' => $transferId, ':tenant' => $tenantId]);
  $transfer = $st->fetch(PDO::FETCH_ASSOC);

  if (!$transfer) {
    stockflow_json_response(false, null, 'Transfer not found');
  }

  if ($transfer['status'] !== 'pending') {
    stockflow_json_response(false, null, 'Only pending transfers can be shipped');
  }

  // Branch access check
  if (!stockflow_has_permission('stockflow.view_all_branches')) {
    $userBranches = stockflow_get_user_branches();
    $userBranchIds = array_map(fn($b) => (int)$b['id'], $userBranches);
    
    if (!in_array((int)$transfer['from_branch_id'], $userBranchIds, true)) {
      stockflow_json_response(false, null, 'Access denied: You cannot ship from this branch');
    }
  }

  // Get transfer items
  $st = $pdo->prepare("
    SELECT ti.*, p.inventory_unit
    FROM stockflow_transfer_items ti
    JOIN products p ON p.id = ti.product_id
    WHERE ti.transfer_id = :tid
    ORDER BY ti.id
  ");
  $st->execute([':tid' => $transferId]);
  $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if (empty($items)) {
    stockflow_json_response(false, null, 'Cannot ship transfer with no items');
  }

  $pdo->beginTransaction();
  try {
    $stockShortages = [];
    $shippedItems = [];

    // Check stock availability and prepare movements
    foreach ($items as $item) {
      $productId = (int)$item['product_id'];
      $requestedQty = (float)$item['quantity_requested'];

      // Get current stock level
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
      $availableQty = max(0, $currentStock);

      if ($availableQty < $requestedQty) {
        $stockShortages[] = [
          'product_name' => $item['product_name'],
          'requested' => $requestedQty,
          'available' => $availableQty,
          'unit' => $item['inventory_unit']
        ];
        continue;
      }

      // Determine shipped quantity (could be less than requested if partial shipping allowed)
      $shippedQty = min($requestedQty, $availableQty);
      $shippedItems[] = [
        'item_id' => $item['id'],
        'product_id' => $productId,
        'product_name' => $item['product_name'],
        'requested_qty' => $requestedQty,
        'shipped_qty' => $shippedQty,
        'unit_cost' => (float)$item['unit_cost']
      ];
    }

    // If any critical shortages, reject shipping (or implement partial shipping logic)
    if (!empty($stockShortages)) {
      $pdo->rollBack();
      
      $shortageMsg = "Stock shortages prevent shipping:\n";
      foreach ($stockShortages as $shortage) {
        $shortageMsg .= sprintf("- %s: need %.2f %s, have %.2f %s\n",
          $shortage['product_name'],
          $shortage['requested'],
          $shortage['unit'],
          $shortage['available'],
          $shortage['unit']
        );
      }
      
      stockflow_json_response(false, null, trim($shortageMsg));
    }

    // Update stock levels and create movements
    foreach ($shippedItems as $shippedItem) {
      // Update stock level for source branch
      stockflow_update_stock_level(
        $pdo,
        $tenantId,
        (int)$transfer['from_branch_id'],
        $shippedItem['product_id'],
        -$shippedItem['shipped_qty'], // Negative for outgoing
        'transfer_out',
        $transferId,
        'transfer',
        $userId
      );

      // Update transfer item with shipped quantity
      $pdo->prepare("
        UPDATE stockflow_transfer_items 
        SET quantity_shipped = :qty_shipped,
            total_cost = :cost * :qty_shipped,
            updated_at = NOW()
        WHERE id = :id
      ")->execute([
        ':qty_shipped' => $shippedItem['shipped_qty'],
        ':cost' => $shippedItem['unit_cost'],
        ':id' => $shippedItem['item_id']
      ]);
    }

    // Update transfer status to shipped
    $pdo->prepare("
      UPDATE stockflow_transfers 
      SET status = 'shipped',
          shipped_at = NOW(),
          shipped_by_user_id = :user_id,
          updated_at = NOW()
      WHERE id = :id AND tenant_id = :tenant
    ")->execute([
      ':user_id' => $userId,
      ':id' => $transferId,
      ':tenant' => $tenantId
    ]);

    $pdo->commit();

    stockflow_json_response(true, [
      'message' => sprintf(
        'Transfer %s shipped successfully with %d items',
        $transfer['transfer_number'],
        count($shippedItems)
      ),
      'transfer_id' => $transferId,
      'transfer_number' => $transfer['transfer_number'],
      'status' => 'shipped',
      'shipped_items' => count($shippedItems),
      'from_branch' => $transfer['from_branch_name'],
      'to_branch' => $transfer['to_branch_name'],
      'shipped_at' => date('Y-m-d H:i:s')
    ]);

  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

} catch (Throwable $e) {
  error_log('Ship transfer error: ' . $e->getMessage() . ' | Transfer: ' . $transferId . ' | User: ' . $userId);
  stockflow_json_response(false, null, 'Failed to ship transfer: ' . $e->getMessage());
}