<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/transfer_details.php  
// Get single transfer details with items
declare(strict_types=1);

/* Bootstrap */
require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/* Permission check */
if (!stockflow_has_permission('stockflow.view')) {
  stockflow_json_response(false, null, 'Insufficient permissions');
}

$transferId = (int)($_GET['id'] ?? 0);
if ($transferId <= 0) {
  stockflow_json_response(false, null, 'Invalid transfer ID');
}

try {
  $pdo = db();
  
  // Get transfer details
  $st = $pdo->prepare("
    SELECT 
      t.*, 
      fb.name as from_branch_name, tb.name as to_branch_name,
      u1.name as created_by_name, u2.name as shipped_by_name, u3.name as received_by_name
    FROM stockflow_transfers t
    LEFT JOIN branches fb ON fb.id = t.from_branch_id
    LEFT JOIN branches tb ON tb.id = t.to_branch_id  
    LEFT JOIN users u1 ON u1.id = t.created_by_user_id
    LEFT JOIN users u2 ON u2.id = t.shipped_by_user_id
    LEFT JOIN users u3 ON u3.id = t.received_by_user_id
    WHERE t.id = :id AND t.tenant_id = :tenant
  ");
  $st->execute([':id' => $transferId, ':tenant' => $tenantId]);
  $transfer = $st->fetch();
  
  if (!$transfer) {
    stockflow_json_response(false, null, 'Transfer not found');
  }
  
  // Get transfer items
  $itemsSt = $pdo->prepare("
    SELECT ti.*, p.name_en as product_name, p.inventory_unit
    FROM stockflow_transfer_items ti
    JOIN products p ON p.id = ti.product_id
    WHERE ti.transfer_id = :id
    ORDER BY ti.id
  ");
  $itemsSt->execute([':id' => $transferId]);
  $items = $itemsSt->fetchAll() ?: [];
  
  $transfer['items'] = $items;
  
  stockflow_json_response(true, $transfer);
  
} catch (Throwable $e) {
  stockflow_json_response(false, null, 'Failed to fetch transfer details: ' . $e->getMessage());
}