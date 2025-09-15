<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/transfers_create.php
// AJAX endpoint to create a new stock transfer
declare(strict_types=1);

/* Bootstrap */
require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/* ---- Permission ---- */
stockflow_require_permission('stockflow.transfers.create');

/* ---- Input validation ---- */
$fromBranchId = (int)($_POST['from_branch_id'] ?? 0);
$toBranchId   = (int)($_POST['to_branch_id']   ?? 0);
$notes        = trim((string)($_POST['notes']   ?? ''));

// Basic validation
if ($fromBranchId <= 0 || $toBranchId <= 0) {
  stockflow_json_response(false, null, 'Both branches must be selected.');
}
if ($fromBranchId === $toBranchId) {
  stockflow_json_response(false, null, 'Cannot transfer to the same branch.');
}
if (mb_strlen($notes) > 500) {
  stockflow_json_response(false, null, 'Notes cannot exceed 500 characters.');
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  /* ---- Verify branches exist and belong to this tenant ---- */
  $st = $pdo->prepare("
    SELECT id, name, branch_type
    FROM branches
    WHERE tenant_id = :tenant
      AND is_active = 1
      AND id IN (:fb, :tb)
    ORDER BY id
  ");
  $st->execute([
    ':tenant' => $tenantId,
    ':fb'     => $fromBranchId,
    ':tb'     => $toBranchId
  ]);

  $branches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (count($branches) !== 2) {
    stockflow_json_response(false, null, 'Invalid branch selection. One or both branches do not exist or are inactive.');
  }

  // Map branches by ID for easy access
  $branchMap = [];
  foreach ($branches as $branch) {
    $branchMap[(int)$branch['id']] = $branch;
  }
  
  $fromBranch = $branchMap[$fromBranchId] ?? null;
  $toBranch   = $branchMap[$toBranchId] ?? null;
  
  if (!$fromBranch || !$toBranch) {
    stockflow_json_response(false, null, 'Branch validation failed.');
  }

  /* ---- Branch access validation ---- */
  // If user doesn't have view_all_branches permission, check if they have access to both branches
  if (!stockflow_has_permission('stockflow.view_all_branches')) {
    $userBranches = stockflow_get_user_branches();
    $userBranchIds = array_map(static fn($b) => (int)$b['id'], $userBranches);
    
    if (!in_array($fromBranchId, $userBranchIds, true) || !in_array($toBranchId, $userBranchIds, true)) {
      stockflow_json_response(false, null, 'You do not have access to one or both selected branches.');
    }
  }

  /* ---- Determine transfer type based on branch types ---- */
  $transferType = 'inter_branch_transfer'; // Default to inter-branch
  $fromType = strtolower(trim((string)$fromBranch['branch_type']));
  $toType   = strtolower(trim((string)$toBranch['branch_type']));

  // Determine transfer type based on your business logic
  if ($fromType === 'central_kitchen') {
    $transferType = 'production_transfer'; // From CK to store
  } elseif ($toType === 'central_kitchen') {
    $transferType = 'return_transfer'; // From store back to CK
  }
  // Otherwise remains 'inter_branch_transfer'

  /* ---- Create transfer record ---- */
  $pdo->beginTransaction();
  try {
    // Generate unique transfer number
    $transferNumber = stockflow_next_transfer_number($pdo, $tenantId, 'TRF');

    // Insert transfer record
    $insertSql = "
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
        created_at
      ) VALUES (
        :tenant_id, 
        :transfer_number, 
        :from_branch_id, 
        :to_branch_id, 
        'pending', 
        :transfer_type, 
        :notes, 
        0,
        :created_by_user_id, 
        NOW()
      )
    ";

    $st = $pdo->prepare($insertSql);
    $st->execute([
      ':tenant_id'         => $tenantId,
      ':transfer_number'   => $transferNumber,
      ':from_branch_id'    => $fromBranchId,
      ':to_branch_id'      => $toBranchId,
      ':transfer_type'     => $transferType,
      ':notes'             => ($notes !== '' ? $notes : null),
      ':created_by_user_id'=> $userId
    ]);

    $transferId = (int)$pdo->lastInsertId();
    
    if ($transferId <= 0) {
      throw new Exception('Failed to create transfer record');
    }

    $pdo->commit();

    stockflow_json_response(true, [
      'transfer_id'     => $transferId,
      'transfer_number' => $transferNumber,
      'status'          => 'pending',
      'transfer_type'   => $transferType,
      'from_branch'     => $fromBranch['name'],
      'to_branch'       => $toBranch['name'],
      'message'         => "Transfer {$transferNumber} created successfully. You can now add items to this transfer."
    ]);

  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

} catch (Throwable $e) {
  error_log('Transfer creation error: ' . $e->getMessage() . ' | User: ' . $userId . ' | Tenant: ' . $tenantId);
  stockflow_json_response(false, null, 'Failed to create transfer: ' . $e->getMessage());
}