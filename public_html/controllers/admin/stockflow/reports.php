<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/reports.php
// AJAX endpoint for stockflow reporting and analytics
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

stockflow_require_permission('stockflow.reports.view');

$reportType = trim((string)($_GET['type'] ?? ''));
$branchId = (int)($_GET['branch_id'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$limit = min(1000, max(10, (int)($_GET['limit'] ?? 100)));

// Validate date range
if ($dateFrom && !strtotime($dateFrom)) {
  stockflow_json_response(false, null, 'Invalid date_from format');
}
if ($dateTo && !strtotime($dateTo)) {
  stockflow_json_response(false, null, 'Invalid date_to format');
}

// Default to last 30 days if no dates specified
if (!$dateFrom) {
  $dateFrom = date('Y-m-d', strtotime('-30 days'));
}
if (!$dateTo) {
  $dateTo = date('Y-m-d');
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Branch access filtering
  $branchAccessWhere = '';
  $branchAccessParams = [];
  
  if (!stockflow_has_permission('stockflow.view_all_branches')) {
    $userBranches = stockflow_get_user_branches();
    $userBranchIds = array_map(fn($b) => (int)$b['id'], $userBranches);
    
    if (empty($userBranchIds)) {
      stockflow_json_response(true, []); // User has no branch access
    }
    
    $branchPlaceholders = implode(',', array_fill(0, count($userBranchIds), '?'));
    $branchAccessWhere = "AND branch_id IN ($branchPlaceholders)";
    $branchAccessParams = $userBranchIds;
  }

  switch ($reportType) {
    case 'stock_movements':
      $where = ["sm.tenant_id = ?"];
      $params = [$tenantId];

      if ($branchId > 0) {
        $where[] = "sm.branch_id = ?";
        $params[] = $branchId;
      } else {
        $where[] = substr($branchAccessWhere, 4); // Remove 'AND '
        $params = array_merge($params, $branchAccessParams);
      }

      if ($dateFrom) {
        $where[] = "DATE(sm.movement_date) >= ?";
        $params[] = $dateFrom;
      }
      if ($dateTo) {
        $where[] = "DATE(sm.movement_date) <= ?";
        $params[] = $dateTo;
      }

      $whereClause = implode(' AND ', $where);

      $sql = "
        SELECT 
          sm.id,
          sm.movement_type,
          sm.quantity,
          sm.quantity_before,
          sm.quantity_after,
          sm.unit_cost,
          sm.total_cost,
          sm.notes,
          sm.movement_date,
          p.name_en as product_name,
          p.inventory_unit,
          b.name as branch_name,
          u.name as created_by_name,
          CASE 
            WHEN sm.reference_type = 'transfer' THEN (
              SELECT transfer_number FROM stockflow_transfers 
              WHERE id = sm.reference_id AND tenant_id = sm.tenant_id
            )
            ELSE NULL
          END as reference_number
        FROM stockflow_stock_movements sm
        JOIN products p ON p.id = sm.product_id AND p.tenant_id = sm.tenant_id
        JOIN branches b ON b.id = sm.branch_id AND b.tenant_id = sm.tenant_id
        LEFT JOIN users u ON u.id = sm.created_by_user_id
        WHERE $whereClause
        ORDER BY sm.movement_date DESC, sm.id DESC
        LIMIT ?
      ";

      $st = $pdo->prepare($sql);
      $st->execute([...$params, $limit]);
      $movements = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      // Calculate summary statistics
      $totalIn = 0; $totalOut = 0; $totalValue = 0;
      $movementTypes = [];

      foreach ($movements as &$movement) {
        $qty = (float)$movement['quantity'];
        $cost = (float)$movement['total_cost'];
        
        if ($qty > 0) {
          $totalIn += $qty;
        } else {
          $totalOut += abs($qty);
        }
        
        $totalValue += abs($cost);
        
        $type = $movement['movement_type'];
        $movementTypes[$type] = ($movementTypes[$type] ?? 0) + 1;
        
        $movement['movement_date_formatted'] = date('M j, Y H:i', strtotime($movement['movement_date']));
      }

      stockflow_json_response(true, [
        'movements' => $movements,
        'summary' => [
          'total_movements' => count($movements),
          'total_quantity_in' => $totalIn,
          'total_quantity_out' => $totalOut,
          'total_value' => round($totalValue, 2),
          'movement_types' => $movementTypes
        ]
      ]);
      break;

    case 'transfer_summary':
      $where = ["t.tenant_id = ?"];
      $params = [$tenantId];

      if ($branchId > 0) {
        $where[] = "(t.from_branch_id = ? OR t.to_branch_id = ?)";
        $params = array_merge($params, [$branchId, $branchId]);
      }

      if ($dateFrom) {
        $where[] = "DATE(t.created_at) >= ?";
        $params[] = $dateFrom;
      }
      if ($dateTo) {
        $where[] = "DATE(t.created_at) <= ?";
        $params[] = $dateTo;
      }

      $whereClause = implode(' AND ', $where);

      $sql = "
        SELECT 
          t.status,
          t.transfer_type,
          COUNT(*) as transfer_count,
          AVG(t.total_items) as avg_items_per_transfer,
          MIN(t.created_at) as earliest_transfer,
          MAX(t.created_at) as latest_transfer
        FROM stockflow_transfers t
        WHERE $whereClause
        GROUP BY t.status, t.transfer_type
        ORDER BY t.status, t.transfer_type
      ";

      $st = $pdo->prepare($sql);
      $st->execute($params);
      $transferSummary = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      // Get detailed transfer list
      $detailSql = "
        SELECT 
          t.id, t.transfer_number, t.status, t.transfer_type, t.total_items,
          t.created_at, t.shipped_at, t.received_at,
          fb.name as from_branch_name,
          tb.name as to_branch_name,
          u1.name as created_by_name
        FROM stockflow_transfers t
        LEFT JOIN branches fb ON fb.id = t.from_branch_id AND fb.tenant_id = t.tenant_id
        LEFT JOIN branches tb ON tb.id = t.to_branch_id AND tb.tenant_id = t.tenant_id
        LEFT JOIN users u1 ON u1.id = t.created_by_user_id
        WHERE $whereClause
        ORDER BY t.created_at DESC
        LIMIT ?
      ";

      $detailSt = $pdo->prepare($detailSql);
      $detailSt->execute([...$params, $limit]);
      $transfers = $detailSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

      foreach ($transfers as &$transfer) {
        $transfer['created_at_formatted'] = date('M j, Y H:i', strtotime($transfer['created_at']));
        $transfer['shipped_at_formatted'] = $transfer['shipped_at'] ? date('M j, Y H:i', strtotime($transfer['shipped_at'])) : null;
        $transfer['received_at_formatted'] = $transfer['received_at'] ? date('M j, Y H:i', strtotime($transfer['received_at'])) : null;
      }

      stockflow_json_response(true, [
        'summary' => $transferSummary,
        'transfers' => $transfers
      ]);
      break;

    case 'low_stock_report':
      $where = ["sl.tenant_id = ?"];
      $params = [$tenantId];
      
      if ($branchId > 0) {
        $where[] = "sl.branch_id = ?";
        $params[] = $branchId;
      } else if ($branchAccessWhere) {
        $where[] = substr($branchAccessWhere, 4);
        $params = array_merge($params, $branchAccessParams);
      }

      $whereClause = implode(' AND ', $where);

      $sql = "
        SELECT 
          sl.current_stock,
          sl.reserved_stock,
          sl.last_movement_at,
          p.id as product_id,
          p.name_en as product_name,
          p.inventory_unit,
          p.standard_cost,
          b.id as branch_id,
          b.name as branch_name,
          COALESCE(rl.reorder_level, 5) as reorder_level,
          COALESCE(rl.max_stock_level, 0) as max_stock_level,
          CASE 
            WHEN sl.current_stock <= 0 THEN 'out_of_stock'
            WHEN sl.current_stock <= COALESCE(rl.reorder_level, 5) THEN 'low_stock'
            ELSE 'normal'
          END as stock_status,
          (sl.current_stock * p.standard_cost) as stock_value
        FROM stockflow_stock_levels sl
        JOIN products p ON p.id = sl.product_id AND p.tenant_id = sl.tenant_id AND p.is_active = 1 AND p.is_inventory_tracked = 1
        JOIN branches b ON b.id = sl.branch_id AND b.tenant_id = sl.tenant_id
        LEFT JOIN stockflow_reorder_levels rl ON (rl.tenant_id = sl.tenant_id AND rl.branch_id = sl.branch_id AND rl.product_id = sl.product_id AND rl.is_active = 1)
        WHERE $whereClause
          AND sl.current_stock <= COALESCE(rl.reorder_level, 5)
        ORDER BY sl.current_stock ASC, p.name_en ASC
        LIMIT ?
      ";

      $st = $pdo->prepare($sql);
      $st->execute([...$params, $limit]);
      $lowStockItems = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      $totalValue = 0;
      $outOfStock = 0;
      $lowStock = 0;

      foreach ($lowStockItems as &$item) {
        $totalValue += (float)$item['stock_value'];
        if ($item['stock_status'] === 'out_of_stock') {
          $outOfStock++;
        } else {
          $lowStock++;
        }
        
        $item['last_movement_formatted'] = $item['last_movement_at'] ? 
          date('M j, Y H:i', strtotime($item['last_movement_at'])) : 'Never';
      }

      stockflow_json_response(true, [
        'low_stock_items' => $lowStockItems,
        'summary' => [
          'total_items' => count($lowStockItems),
          'out_of_stock_count' => $outOfStock,
          'low_stock_count' => $lowStock,
          'total_value' => round($totalValue, 2)
        ]
      ]);
      break;

    case 'branch_performance':
      $sql = "
        SELECT 
          b.id, b.name, b.branch_type,
          COUNT(DISTINCT t_out.id) as transfers_sent,
          COUNT(DISTINCT t_in.id) as transfers_received,
          COUNT(DISTINCT sm.id) as total_movements,
          SUM(CASE WHEN sm.quantity > 0 THEN sm.quantity ELSE 0 END) as total_stock_in,
          SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as total_stock_out,
          COUNT(DISTINCT sl.product_id) as tracked_products,
          SUM(sl.current_stock * p.standard_cost) as current_stock_value
        FROM branches b
        LEFT JOIN stockflow_transfers t_out ON (t_out.from_branch_id = b.id AND t_out.tenant_id = b.tenant_id AND DATE(t_out.created_at) BETWEEN ? AND ?)
        LEFT JOIN stockflow_transfers t_in ON (t_in.to_branch_id = b.id AND t_in.tenant_id = b.tenant_id AND DATE(t_in.created_at) BETWEEN ? AND ?)
        LEFT JOIN stockflow_stock_movements sm ON (sm.branch_id = b.id AND sm.tenant_id = b.tenant_id AND DATE(sm.movement_date) BETWEEN ? AND ?)
        LEFT JOIN stockflow_stock_levels sl ON (sl.branch_id = b.id AND sl.tenant_id = b.tenant_id)
        LEFT JOIN products p ON (p.id = sl.product_id AND p.tenant_id = sl.tenant_id AND p.is_active = 1)
        WHERE b.tenant_id = ? AND b.is_active = 1
        " . ($branchId > 0 ? "AND b.id = ?" : ($branchAccessWhere ? "AND b.id IN (" . implode(',', array_fill(0, count($branchAccessParams), '?')) . ")" : "")) . "
        GROUP BY b.id, b.name, b.branch_type
        ORDER BY current_stock_value DESC
      ";

      $params = [$dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo, $tenantId];
      if ($branchId > 0) {
        $params[] = $branchId;
      } else if ($branchAccessWhere) {
        $params = array_merge($params, $branchAccessParams);
      }

      $st = $pdo->prepare($sql);
      $st->execute($params);
      $branchPerformance = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      stockflow_json_response(true, [
        'branch_performance' => $branchPerformance,
        'date_range' => ['from' => $dateFrom, 'to' => $dateTo]
      ]);
      break;

    default:
      stockflow_json_response(false, null, 'Invalid report type. Available: stock_movements, transfer_summary, low_stock_report, branch_performance');
  }

} catch (Throwable $e) {
  error_log('Reports error: ' . $e->getMessage() . ' | Type: ' . $reportType . ' | User: ' . $userId);
  stockflow_json_response(false, null, 'Failed to generate report: ' . $e->getMessage());
}