<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/stockflow/engine/apply_order_deductions.php
// Auto-deduct inventory when orders are closed
declare(strict_types=1);

/**
 * Apply stock deductions for completed order
 * Called from orders_change_status.php when order transitions to 'closed'
 */
function stockflow_apply_on_order_close(PDO $pdo, int $tenantId, int $orderId, int $userId): array {
  $notes = [];
  
  try {
    // Get order details
    $orderSt = $pdo->prepare("SELECT branch_id, status FROM orders WHERE id = :id AND tenant_id = :t LIMIT 1");
    $orderSt->execute([':id' => $orderId, ':t' => $tenantId]);
    $order = $orderSt->fetch();
    
    if (!$order) {
      return ['notes' => ['Order not found']];
    }
    
    $branchId = (int)$order['branch_id'];
    
    // Get order items with inventory-tracked products
    $itemsSt = $pdo->prepare("
      SELECT oi.product_id, oi.quantity, p.name_en, p.inventory_unit
      FROM order_items oi
      JOIN products p ON p.id = oi.product_id
      WHERE oi.order_id = :oid 
        AND p.tenant_id = :t
        AND p.is_inventory_tracked = 1
        AND p.is_active = 1
    ");
    $itemsSt->execute([':oid' => $orderId, ':t' => $tenantId]);
    $items = $itemsSt->fetchAll();
    
    if (!$items) {
      return ['notes' => ['No inventory-tracked items found']];
    }
    
    $deductedItems = 0;
    $warnings = [];
    
    // Process each item
    foreach ($items as $item) {
      $productId = (int)$item['product_id'];
      $quantity = (float)$item['quantity'];
      $productName = (string)$item['name_en'];
      
      if ($quantity <= 0) continue;
      
      try {
        // Check current stock
        $stockSt = $pdo->prepare("
          SELECT current_stock 
          FROM stockflow_stock_levels 
          WHERE tenant_id = :t AND branch_id = :b AND product_id = :p
        ");
        $stockSt->execute([':t' => $tenantId, ':b' => $branchId, ':p' => $productId]);
        $currentStock = (float)($stockSt->fetchColumn() ?: 0);
        
        // Deduct stock (negative quantity for sale_out)
        $deductionAmount = -$quantity;
        $newStock = $currentStock + $deductionAmount;
        
        // Update stock level
        $pdo->prepare("
          INSERT INTO stockflow_stock_levels (tenant_id, branch_id, product_id, current_stock, last_movement_at)
          VALUES (:t, :b, :p, :ns, NOW())
          ON DUPLICATE KEY UPDATE 
            current_stock = :ns2,
            last_movement_at = NOW()
        ")->execute([':t' => $tenantId, ':b' => $branchId, ':p' => $productId, ':ns' => $newStock, ':ns2' => $newStock]);
        
        // Log movement
        $pdo->prepare("
          INSERT INTO stockflow_stock_movements 
          (tenant_id, branch_id, product_id, movement_type, quantity, quantity_before, quantity_after, reference_type, reference_id, created_by_user_id)
          VALUES (:t, :b, :p, 'sale_out', :q, :qb, :qa, 'order', :ri, :cb)
        ")->execute([
          ':t' => $tenantId, ':b' => $branchId, ':p' => $productId,
          ':q' => $deductionAmount, ':qb' => $currentStock, ':qa' => $newStock,
          ':ri' => $orderId, ':cb' => $userId
        ]);
        
        $deductedItems++;
        
        // Warn about low/negative stock
        if ($newStock <= 0) {
          $warnings[] = "{$productName} is now out of stock";
        } elseif ($newStock < 5) { // Simple low stock threshold
          $warnings[] = "{$productName} is running low ({$newStock} remaining)";
        }
        
      } catch (Throwable $e) {
        $warnings[] = "Failed to deduct {$productName}: " . $e->getMessage();
        continue;
      }
    }
    
    // Build summary
    if ($deductedItems > 0) {
      $notes[] = "Deducted {$deductedItems} items";
    }
    
    if ($warnings) {
      $notes = array_merge($notes, $warnings);
    }
    
    return ['notes' => $notes];
    
  } catch (Throwable $e) {
    error_log('[stockflow] Auto-deduction error: ' . $e->getMessage());
    return ['notes' => ['Stock deduction failed: ' . $e->getMessage()]];
  }
}

/**
 * Get low stock alerts for dashboard
 */
function stockflow_get_low_stock_alerts(PDO $pdo, int $tenantId, int $limit = 10): array {
  try {
    $st = $pdo->prepare("
      SELECT 
        sl.current_stock,
        p.name_en as product_name,
        b.name as branch_name,
        COALESCE(rl.reorder_level, 5) as reorder_level
      FROM stockflow_stock_levels sl
      JOIN products p ON p.id = sl.product_id
      JOIN branches b ON b.id = sl.branch_id
      LEFT JOIN stockflow_reorder_levels rl ON (rl.tenant_id = sl.tenant_id AND rl.branch_id = sl.branch_id AND rl.product_id = sl.product_id AND rl.is_active = 1)
      WHERE sl.tenant_id = :t
        AND p.is_inventory_tracked = 1
        AND p.is_active = 1
        AND sl.current_stock <= COALESCE(rl.reorder_level, 5)
      ORDER BY sl.current_stock ASC
      LIMIT :limit
    ");
    $st->execute([':t' => $tenantId, ':limit' => $limit]);
    return $st->fetchAll() ?: [];
  } catch (Throwable $e) {
    return [];
  }
}