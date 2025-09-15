<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/transfers_list.php
// AJAX endpoint to list transfers with filtering & pagination
declare(strict_types=1);

/* Bootstrap */
require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

/* Permission check */
stockflow_require_permission('stockflow.view');

/* Filters */
$status   = strtolower(trim((string)($_GET['status'] ?? 'all')));
$branchId = (int)($_GET['branch_id'] ?? 0);
$limit    = min(100, max(1, (int)($_GET['limit']  ?? 20)));
$offset   = max(0, (int)($_GET['offset'] ?? 0));

$allowedStatuses = ['all', 'pending', 'shipped', 'received', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) { $status = 'all'; }

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Branch access restriction (if no view_all permission)
  $branchFilterIds = null;
  if (!stockflow_has_permission('stockflow.view_all_branches')) {
    $allowed = stockflow_get_user_branches();
    $branchFilterIds = array_map(static fn($r) => (int)$r['id'], $allowed);
    if (empty($branchFilterIds)) {
      stockflow_json_response(true, ['transfers' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset]);
    }
  }

  // WHERE builder
  $where = ["t.tenant_id = :tenant"];
  $args  = [':tenant' => $tenantId];

  if ($status !== 'all') {
    $where[] = "t.status = :status";
    $args[':status'] = $status;
  }

  if ($branchId > 0) {
    $where[] = "(t.from_branch_id = :branch OR t.to_branch_id = :branch)";
    $args[':branch'] = $branchId;
  }

  // Apply branch access restriction if present
  if (is_array($branchFilterIds)) {
    // Visible if either side of the transfer is in accessible branches
    // Build IN lists safely
    $inParams = [];
    foreach ($branchFilterIds as $i => $bid) {
      $k = ":b{$i}";
      $inParams[$k] = $bid;
    }
    if (!empty($inParams)) {
      $where[] = "(t.from_branch_id IN (" . implode(',', array_keys($inParams)) . ") OR t.to_branch_id IN (" . implode(',', array_keys($inParams)) . "))";
      $args = array_merge($args, $inParams);
    }
  }

  $whereClause = implode(' AND ', $where);

  // Total count
  $countSql = "SELECT COUNT(*) FROM stockflow_transfers t WHERE {$whereClause}";
  $countSt  = $pdo->prepare($countSql);
  foreach ($args as $k => $v) { $countSt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
  $countSt->execute();
  $total = (int)$countSt->fetchColumn();

  // Fetch list (tenant-guarded joins)
  $sql = "
    SELECT 
      t.id,
      t.transfer_number,
      t.status,
      t.transfer_type,
      -- compute items count to avoid relying on a nullable/absent column
      (SELECT COUNT(*) FROM stockflow_transfer_items ti WHERE ti.tenant_id = t.tenant_id AND ti.transfer_id = t.id) AS total_items,
      t.created_at,
      t.shipped_at,
      t.received_at,
      fb.name AS from_branch_name,
      tb.name AS to_branch_name,
      u.name  AS created_by_name
    FROM stockflow_transfers t
    LEFT JOIN branches fb ON fb.id = t.from_branch_id AND fb.tenant_id = t.tenant_id
    LEFT JOIN branches tb ON tb.id = t.to_branch_id AND tb.tenant_id = t.tenant_id
    LEFT JOIN users    u  ON u.id  = t.created_by_user_id
    WHERE {$whereClause}
    ORDER BY t.created_at DESC
    LIMIT :limit OFFSET :offset
  ";
  $st = $pdo->prepare($sql);
  foreach ($args as $k => $v) { $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
  $st->bindValue(':limit',  $limit,  PDO::PARAM_INT);
  $st->bindValue(':offset', $offset, PDO::PARAM_INT);

  $st->execute();
  $transfers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  stockflow_json_response(true, [
    'transfers' => $transfers,
    'total'     => $total,
    'limit'     => $limit,
    'offset'    => $offset
  ]);

} catch (Throwable $e) {
  stockflow_json_response(false, null, 'Failed to fetch transfers: ' . $e->getMessage());
}