<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/transfers_receive.php
// AJAX endpoint to receive shipped transfers
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

stockflow_require_permission('stockflow.transfers.receive');

$transferId = (int)($_POST['transfer_id'] ?? 0);
$receivedItems = $_POST['received_items'] ?? [];

if ($transferId <= 0) {
  stockflow_json_response(false, null, 'Invalid transfer ID');
}

if (!is_array($receivedItems)) {
  stockflow_json_response(false, null, 'Invalid received items data');
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Get transfer details
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

  if ($transfer['status'] !== 'shipped') {
    stockflow_json_response(false, null, 'Only shipped transfers can be received');
  }

  // Branch access check - user must have access to receiving branch
  if (!stockflow_has_permission('stockflow.view_all_branches')) {
    $userBranches = stockflow_get_user_branches();
    $userBranchIds = array_map(fn($b) => (int)$b['id'], $userBranches);
    
    if (!in_array((int)$transfer['to_branch_id'], $userBranchIds, true)) {
      stockflow_json_response(false, null, 'Access denied: You cannot receive at this branch');
    }
  }

  // Get shipped items
  $st = $pdo->prepare("
    SELECT ti.*, p.inventory_unit
    FROM stockflow_transfer_items ti
    JOIN products p ON p.id = ti.product_id
    WHERE ti.transfer_id = :tid
    ORDER BY ti.id
  ");
  $st->execute([':tid' => $transferId]);
  $shippedItems = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if (empty($shippedItems)) {
    stockflow_json_response(false, null, 'No shipped items found for this transfer');
  }

  // Validate received items data
  $validatedReceivedItems = [];
  $totalReceivedItems = 0;

  foreach ($shippedItems as $shippedItem) {
    $itemId = (int)$shippedItem['id'];
    $shippedQty = (float)$shippedItem['quantity_shipped'];
    
    // Find received quantity for this item
    $receivedQty = 0;
    foreach ($receivedItems as $receivedItem) {
      if ((int)$receivedItem['item_id'] === $itemId) {
        $receivedQty = max(0, (float)$receivedItem['received_quantity']);
        break;
      }
    }

    // Cannot receive more than shipped
    if ($receivedQty > $shippedQty) {
      stockflow_json_response(false, null, sprintf(
        'Cannot receive %.2f %s for %s - only %.2f %s was shipped',
        $receivedQty,
        $shippedItem['inventory_unit'],
        $shippedItem['product_name'],
        $shippedQty,
        $shippedItem['inventory_unit']
      ));
    }

    $validatedReceivedItems[] = [
      'item_id' => $itemId,
      'product_id' => (int)$shippedItem['product_id'],
      'product_name' => $shippedItem['product_name'],
      'shipped_qty' => $shippedQty,
      'received_qty' => $receivedQty,
      'unit_cost' => (float)$shippedItem['unit_cost']
    ];

    if ($receivedQty > 0) {
      $totalReceivedItems++;
    }
  }

  if ($totalReceivedItems === 0) {
    stockflow_json_response(false, null, 'No items marked as received');
  }

  $pdo->beginTransaction();
  try {
    $receivedItemsData = [];

    // Process each received item
    foreach ($validatedReceivedItems as $item) {
      if ($item['received_qty'] > 0) {
        // Update stock level for destination branch
        stockflow_update_stock_level(
          $pdo,
          $tenantId,
          (int)$transfer['to_branch_id'],
          $item['product_id'],
          $item['received_qty'], // Positive for incoming
          'transfer_in',
          $transferId,
          'transfer',
          $userId
        );

        // Update transfer item with received quantity
        $pdo->prepare("
          UPDATE stockflow_transfer_items 
          SET quantity_received = :qty_received,
              updated_at = NOW()
          WHERE id = :id
        ")->execute([
          ':qty_received' => $item['received_qty'],
          ':id' => $item['item_id']
        ]);

        $receivedItemsData[] = [
          'product_name' => $item['product_name'],
          'received_qty' => $item['received_qty'],
          'shipped_qty' => $item['shipped_qty']
        ];
      }
    }

    // Update transfer status to received
    $pdo->prepare("
      UPDATE stockflow_transfers 
      SET status = 'received',
          received_at = NOW(),
          received_by_user_id = :user_id,
          updated_at = NOW()
      WHERE id = :id AND tenant_id = :tenant
    ")->execute([
      ':user_id' => $userId,
      ':id' => $transferId,
      ':tenant' => $tenantId
    ]);

    $pdo->commit();

    // Calculate totals for summary
    $totalShippedItems = count(array_filter($validatedReceivedItems, fn($i) => $i['shipped_qty'] > 0));
    $fullyReceivedItems = count(array_filter($validatedReceivedItems, fn($i) => $i['received_qty'] === $i['shipped_qty'] && $i['received_qty'] > 0));
    $partiallyReceivedItems = count(array_filter($validatedReceivedItems, fn($i) => $i['received_qty'] > 0 && $i['received_qty'] < $i['shipped_qty']));

    $summary = [];
    if ($fullyReceivedItems === $totalShippedItems && $partiallyReceivedItems === 0) {
      $summary['type'] = 'complete';
      $summary['message'] = 'All items received in full';
    } elseif ($totalReceivedItems > 0) {
      $summary['type'] = 'partial';
      $summary['message'] = sprintf(
        'Partial receipt: %d fully received, %d partially received out of %d shipped items',
        $fullyReceivedItems,
        $partiallyReceivedItems,
        $totalShippedItems
      );
    }

    stockflow_json_response(true, [
      'message' => sprintf(
        'Transfer %s received successfully - %d items processed',
        $transfer['transfer_number'],
        $totalReceivedItems
      ),
      'transfer_id' => $transferId,
      'transfer_number' => $transfer['transfer_number'],
      'status' => 'received',
      'from_branch' => $transfer['from_branch_name'],
      'to_branch' => $transfer['to_branch_name'],
      'received_at' => date('Y-m-d H:i:s'),
      'summary' => $summary,
      'received_items' => $receivedItemsData,
      'stats' => [
        'total_shipped_items' => $totalShippedItems,
        'total_received_items' => $totalReceivedItems,
        'fully_received' => $fullyReceivedItems,
        'partially_received' => $partiallyReceivedItems
      ]
    ]);

  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

} catch (Throwable $e) {
  error_log('Receive transfer error: ' . $e->getMessage() . ' | Transfer: ' . $transferId . ' | User: ' . $userId);
  stockflow_json_response(false, null, 'Failed to receive transfer: ' . $e->getMessage());
}