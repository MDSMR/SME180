<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/transfer_items_remove.php
// AJAX endpoint to remove items from transfer
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

stockflow_require_permission('stockflow.transfers.edit');

$itemId = (int)($_POST['item_id'] ?? 0);
$transferId = (int)($_POST['transfer_id'] ?? 0);

if ($itemId <= 0) {
  stockflow_json_response(false, null, 'Invalid item ID');
}

if ($transferId <= 0) {
  stockflow_json_response(false, null, 'Invalid transfer ID');
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Verify transfer exists and can be edited
  $st = $pdo->prepare("
    SELECT id, status, from_branch_id, to_branch_id, transfer_number
    FROM stockflow_transfers
    WHERE id = :id AND tenant_id = :tenant
  ");
  $st->execute([':id' => $transferId, ':tenant' => $tenantId]);
  $transfer = $st->fetch(PDO::FETCH_ASSOC);

  if (!$transfer) {
    stockflow_json_response(false, null, 'Transfer not found');
  }

  if ($transfer['status'] !== 'pending') {
    stockflow_json_response(false, null, 'Cannot remove items from non-pending transfer');
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

  // Verify item exists in the transfer
  $st = $pdo->prepare("
    SELECT ti.id, ti.product_id, ti.product_name, ti.quantity_requested, p.inventory_unit
    FROM stockflow_transfer_items ti
    LEFT JOIN products p ON p.id = ti.product_id
    WHERE ti.id = :item_id AND ti.transfer_id = :transfer_id
  ");
  $st->execute([':item_id' => $itemId, ':transfer_id' => $transferId]);
  $item = $st->fetch(PDO::FETCH_ASSOC);

  if (!$item) {
    stockflow_json_response(false, null, 'Item not found in this transfer');
  }

  $pdo->beginTransaction();
  try {
    // Remove the item
    $deleteSt = $pdo->prepare("
      DELETE FROM stockflow_transfer_items 
      WHERE id = :id AND transfer_id = :tid
    ");
    $deleteSt->execute([':id' => $itemId, ':tid' => $transferId]);

    if ($deleteSt->rowCount() === 0) {
      $pdo->rollBack();
      stockflow_json_response(false, null, 'Failed to remove item from transfer');
    }

    // Update transfer total_items count
    $pdo->prepare("
      UPDATE stockflow_transfers 
      SET total_items = (
        SELECT COUNT(*) FROM stockflow_transfer_items WHERE transfer_id = :tid
      ), updated_at = NOW()
      WHERE id = :tid
    ")->execute([':tid' => $transferId]);

    // Get updated item count
    $st = $pdo->prepare("
      SELECT COUNT(*) as item_count 
      FROM stockflow_transfer_items 
      WHERE transfer_id = :tid
    ");
    $st->execute([':tid' => $transferId]);
    $itemCount = (int)$st->fetchColumn();

    $pdo->commit();

    stockflow_json_response(true, [
      'message' => sprintf(
        'Removed %s (%.2f %s) from transfer %s',
        $item['product_name'],
        (float)$item['quantity_requested'],
        $item['inventory_unit'] ?? 'units',
        $transfer['transfer_number']
      ),
      'removed_item' => [
        'id' => $itemId,
        'product_name' => $item['product_name'],
        'quantity' => (float)$item['quantity_requested'],
        'unit' => $item['inventory_unit'] ?? 'units'
      ],
      'remaining_items' => $itemCount
    ]);

  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

} catch (Throwable $e) {
  error_log('Remove transfer item error: ' . $e->getMessage() . ' | Item: ' . $itemId . ' | Transfer: ' . $transferId);
  stockflow_json_response(false, null, 'Failed to remove item from transfer: ' . $e->getMessage());
}