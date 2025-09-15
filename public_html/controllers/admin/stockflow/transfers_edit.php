<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/transfers_edit.php
// AJAX endpoint to update transfer details and manage items
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

stockflow_require_permission('stockflow.transfers.edit');

$transferId = (int)($_POST['transfer_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));

if ($transferId <= 0) {
  stockflow_json_response(false, null, 'Invalid transfer ID');
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Verify transfer exists and belongs to tenant
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

  // Check if transfer can be edited (only pending transfers)
  if ($transfer['status'] !== 'pending') {
    stockflow_json_response(false, null, 'Only pending transfers can be edited');
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

  switch ($action) {
    case 'update_details':
      $notes = trim((string)($_POST['notes'] ?? ''));
      if (mb_strlen($notes) > 500) {
        stockflow_json_response(false, null, 'Notes cannot exceed 500 characters');
      }

      $pdo->prepare("
        UPDATE stockflow_transfers 
        SET notes = :notes, updated_at = NOW()
        WHERE id = :id AND tenant_id = :tenant
      ")->execute([
        ':notes' => ($notes !== '' ? $notes : null),
        ':id' => $transferId,
        ':tenant' => $tenantId
      ]);

      stockflow_json_response(true, [
        'message' => 'Transfer details updated successfully'
      ]);
      break;

    case 'add_item':
      $productId = (int)($_POST['product_id'] ?? 0);
      $quantity = (float)($_POST['quantity'] ?? 0);

      if ($productId <= 0 || $quantity <= 0) {
        stockflow_json_response(false, null, 'Invalid product or quantity');
      }

      // Verify product exists and is inventory tracked
      $st = $pdo->prepare("
        SELECT id, name_en, inventory_unit, standard_cost
        FROM products 
        WHERE id = :id AND tenant_id = :tenant AND is_active = 1 AND is_inventory_tracked = 1
      ");
      $st->execute([':id' => $productId, ':tenant' => $tenantId]);
      $product = $st->fetch(PDO::FETCH_ASSOC);

      if (!$product) {
        stockflow_json_response(false, null, 'Product not found or not inventory tracked');
      }

      // Check if item already exists in transfer
      $st = $pdo->prepare("
        SELECT id, quantity_requested 
        FROM stockflow_transfer_items 
        WHERE transfer_id = :tid AND product_id = :pid
      ");
      $st->execute([':tid' => $transferId, ':pid' => $productId]);
      $existingItem = $st->fetch(PDO::FETCH_ASSOC);

      $pdo->beginTransaction();
      try {
        if ($existingItem) {
          // Update existing item quantity
          $newQuantity = (float)$existingItem['quantity_requested'] + $quantity;
          $pdo->prepare("
            UPDATE stockflow_transfer_items 
            SET quantity_requested = :qty, updated_at = NOW()
            WHERE id = :id
          ")->execute([':qty' => $newQuantity, ':id' => $existingItem['id']]);
        } else {
          // Add new item
          $pdo->prepare("
            INSERT INTO stockflow_transfer_items 
            (transfer_id, product_id, product_name, quantity_requested, unit_cost)
            VALUES (:tid, :pid, :pname, :qty, :cost)
          ")->execute([
            ':tid' => $transferId,
            ':pid' => $productId,
            ':pname' => $product['name_en'],
            ':qty' => $quantity,
            ':cost' => $product['standard_cost']
          ]);
        }

        // Update transfer total_items count
        $pdo->prepare("
          UPDATE stockflow_transfers 
          SET total_items = (
            SELECT COUNT(*) FROM stockflow_transfer_items WHERE transfer_id = :tid
          ), updated_at = NOW()
          WHERE id = :tid
        ")->execute([':tid' => $transferId]);

        $pdo->commit();
        stockflow_json_response(true, [
          'message' => 'Item added to transfer successfully'
        ]);
      } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
      }
      break;

    case 'remove_item':
      $itemId = (int)($_POST['item_id'] ?? 0);
      
      if ($itemId <= 0) {
        stockflow_json_response(false, null, 'Invalid item ID');
      }

      $pdo->beginTransaction();
      try {
        // Remove item
        $st = $pdo->prepare("
          DELETE FROM stockflow_transfer_items 
          WHERE id = :id AND transfer_id = :tid
        ");
        $st->execute([':id' => $itemId, ':tid' => $transferId]);

        if ($st->rowCount() === 0) {
          $pdo->rollBack();
          stockflow_json_response(false, null, 'Item not found in transfer');
        }

        // Update transfer total_items count
        $pdo->prepare("
          UPDATE stockflow_transfers 
          SET total_items = (
            SELECT COUNT(*) FROM stockflow_transfer_items WHERE transfer_id = :tid
          ), updated_at = NOW()
          WHERE id = :tid
        ")->execute([':tid' => $transferId]);

        $pdo->commit();
        stockflow_json_response(true, [
          'message' => 'Item removed from transfer successfully'
        ]);
      } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
      }
      break;

    case 'update_item_quantity':
      $itemId = (int)($_POST['item_id'] ?? 0);
      $newQuantity = (float)($_POST['quantity'] ?? 0);

      if ($itemId <= 0 || $newQuantity <= 0) {
        stockflow_json_response(false, null, 'Invalid item ID or quantity');
      }

      $st = $pdo->prepare("
        UPDATE stockflow_transfer_items 
        SET quantity_requested = :qty, updated_at = NOW()
        WHERE id = :id AND transfer_id = :tid
      ");
      $st->execute([':qty' => $newQuantity, ':id' => $itemId, ':tid' => $transferId]);

      if ($st->rowCount() === 0) {
        stockflow_json_response(false, null, 'Item not found in transfer');
      }

      stockflow_json_response(true, [
        'message' => 'Item quantity updated successfully'
      ]);
      break;

    case 'cancel_transfer':
      if ($transfer['status'] !== 'pending') {
        stockflow_json_response(false, null, 'Only pending transfers can be cancelled');
      }

      $reason = trim((string)($_POST['reason'] ?? ''));
      if (mb_strlen($reason) > 255) {
        stockflow_json_response(false, null, 'Cancellation reason cannot exceed 255 characters');
      }

      $pdo->prepare("
        UPDATE stockflow_transfers 
        SET status = 'cancelled',
            cancelled_at = NOW(),
            cancelled_by_user_id = :user_id,
            cancellation_reason = :reason,
            updated_at = NOW()
        WHERE id = :id AND tenant_id = :tenant
      ")->execute([
        ':user_id' => $userId,
        ':reason' => ($reason !== '' ? $reason : null),
        ':id' => $transferId,
        ':tenant' => $tenantId
      ]);

      stockflow_json_response(true, [
        'message' => 'Transfer cancelled successfully'
      ]);
      break;

    default:
      stockflow_json_response(false, null, 'Invalid action specified');
  }

} catch (Throwable $e) {
  error_log('Transfer edit error: ' . $e->getMessage() . ' | Transfer: ' . $transferId . ' | User: ' . $userId);
  stockflow_json_response(false, null, 'Failed to update transfer: ' . $e->getMessage());
}