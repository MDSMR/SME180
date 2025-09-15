<?php
// controllers/admin/stockflow/save_operation.php
declare(strict_types=1);

/* Bootstrap */
require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/* Permission check based on operation type */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    stockflow_json_response(false, null, 'Invalid request data');
}

$operationType = $input['type'] ?? '';

// Check permissions based on operation type
switch ($operationType) {
    case 'adjustment':
        if (!stockflow_has_permission('stockflow.adjustments.create')) {
            stockflow_json_response(false, null, 'Permission denied: cannot create adjustments');
        }
        break;
    case 'production':
        if (!stockflow_has_permission('stockflow.production.create')) {
            stockflow_json_response(false, null, 'Permission denied: cannot create production entries');
        }
        break;
    case 'transfer':
    case 'return':
        if (!stockflow_has_permission('stockflow.transfers.create')) {
            stockflow_json_response(false, null, 'Permission denied: cannot create transfers');
        }
        break;
    default:
        stockflow_json_response(false, null, 'Invalid operation type');
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    // Validate required fields
    $products = $input['products'] ?? [];
    if (empty($products)) {
        throw new Exception('No products selected');
    }

    $operationDate = $input['date'] ?? date('Y-m-d');
    $notes = $input['notes'] ?? '';
    $reference = $input['reference'] ?? '';

    if ($operationType === 'adjustment') {
        // Handle stock adjustment
        $branchId = (int)($input['branchId'] ?? 0);
        $reason = $input['reason'] ?? 'manual';

        if ($branchId <= 0) {
            throw new Exception('Invalid branch selected');
        }

        // Verify branch access
        $userBranches = stockflow_get_user_branches();
        $hasAccess = false;
        foreach ($userBranches as $ub) {
            if ((int)$ub['id'] === $branchId) {
                $hasAccess = true;
                break;
            }
        }
        if (!$hasAccess && !stockflow_has_permission('stockflow.view_all_branches')) {
            throw new Exception('No access to selected branch');
        }

        // Process each product adjustment
        foreach ($products as $item) {
            $productId = (int)($item['productId'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 0);

            if ($productId <= 0 || $quantity == 0) {
                continue;
            }

            // Get product details
            $st = $pdo->prepare("
                SELECT name_en, standard_cost 
                FROM products 
                WHERE id = :p AND tenant_id = :t AND is_active = 1
            ");
            $st->execute([':p' => $productId, ':t' => $tenantId]);
            $product = $st->fetch();

            if (!$product) {
                throw new Exception("Invalid product ID: {$productId}");
            }

            // Update stock level and create movement record
            $result = stockflow_update_stock_level(
                $pdo,
                $tenantId,
                $branchId,
                $productId,
                $quantity,
                'adjustment',
                null,
                'adjustment',
                $userId
            );

            // Log the adjustment details
            $adjustmentNotes = sprintf(
                "Adjustment - Reason: %s | Reference: %s | Notes: %s",
                $reason,
                $reference ?: 'N/A',
                $notes ?: 'N/A'
            );

            // Update the movement record with additional details
            $st = $pdo->prepare("
                UPDATE stockflow_stock_movements 
                SET notes = :n, unit_cost = :uc, total_cost = :tc 
                WHERE tenant_id = :t 
                  AND branch_id = :b 
                  AND product_id = :p 
                  AND created_by_user_id = :u
                ORDER BY id DESC 
                LIMIT 1
            ");
            $st->execute([
                ':n' => $adjustmentNotes,
                ':uc' => $product['standard_cost'],
                ':tc' => abs($quantity * (float)$product['standard_cost']),
                ':t' => $tenantId,
                ':b' => $branchId,
                ':p' => $productId,
                ':u' => $userId
            ]);
        }

        $pdo->commit();
        stockflow_json_response(true, [
            'message' => 'Stock adjustment completed successfully',
            'items_adjusted' => count($products)
        ]);

    } else {
        // Handle transfers (including production and returns)
        $fromBranchId = (int)($input['fromBranchId'] ?? 0);
        $toBranchId = (int)($input['toBranchId'] ?? 0);

        if ($fromBranchId <= 0 || $toBranchId <= 0) {
            throw new Exception('Invalid branch selection');
        }

        if ($fromBranchId === $toBranchId) {
            throw new Exception('Source and destination branches cannot be the same');
        }

        // Verify branch access
        $userBranches = stockflow_get_user_branches();
        $hasFromAccess = false;
        $hasToAccess = false;
        
        foreach ($userBranches as $ub) {
            if ((int)$ub['id'] === $fromBranchId) $hasFromAccess = true;
            if ((int)$ub['id'] === $toBranchId) $hasToAccess = true;
        }

        if (!stockflow_has_permission('stockflow.view_all_branches')) {
            if (!$hasFromAccess || !$hasToAccess) {
                throw new Exception('No access to one or more selected branches');
            }
        }

        // Determine transfer type
        $transferType = 'inter_branch_transfer';
        if ($operationType === 'production') {
            $transferType = 'production_transfer';
        } elseif ($operationType === 'return') {
            $transferType = 'return_transfer';
        }

        // Generate transfer number
        $seqType = ($operationType === 'production') ? 'PRD' : 
                   (($operationType === 'return') ? 'RTN' : 'TRF');
        $transferNumber = stockflow_next_transfer_number($pdo, $tenantId, $seqType);

        // Create transfer record
        $st = $pdo->prepare("
            INSERT INTO stockflow_transfers (
                tenant_id, transfer_number, from_branch_id, to_branch_id,
                status, transfer_type, notes, total_items, created_by_user_id,
                created_at
            ) VALUES (
                :t, :tn, :fb, :tb,
                'pending', :tt, :n, :ti, :u,
                :dt
            )
        ");
        
        $st->execute([
            ':t' => $tenantId,
            ':tn' => $transferNumber,
            ':fb' => $fromBranchId,
            ':tb' => $toBranchId,
            ':tt' => $transferType,
            ':n' => trim($reference . ' | ' . $notes),
            ':ti' => count($products),
            ':u' => $userId,
            ':dt' => $operationDate . ' ' . date('H:i:s')
        ]);

        $transferId = (int)$pdo->lastInsertId();

        // Add transfer items
        $totalItems = 0;
        $totalCost = 0.00;

        foreach ($products as $item) {
            $productId = (int)($item['productId'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            // Get product details
            $st = $pdo->prepare("
                SELECT name_en, standard_cost, inventory_unit 
                FROM products 
                WHERE id = :p AND tenant_id = :t AND is_active = 1
            ");
            $st->execute([':p' => $productId, ':t' => $tenantId]);
            $product = $st->fetch();

            if (!$product) {
                throw new Exception("Invalid product ID: {$productId}");
            }

            $itemCost = $quantity * (float)$product['standard_cost'];
            $totalCost += $itemCost;
            $totalItems++;

            // Insert transfer item
            $st = $pdo->prepare("
                INSERT INTO stockflow_transfer_items (
                    transfer_id, product_id, product_name,
                    quantity_requested, unit_cost, total_cost
                ) VALUES (
                    :ti, :pi, :pn,
                    :qr, :uc, :tc
                )
            ");

            $st->execute([
                ':ti' => $transferId,
                ':pi' => $productId,
                ':pn' => $product['name_en'],
                ':qr' => $quantity,
                ':uc' => $product['standard_cost'],
                ':tc' => $itemCost
            ]);

            // For production transfers, immediately update destination stock
            if ($operationType === 'production') {
                $result = stockflow_update_stock_level(
                    $pdo,
                    $tenantId,
                    $toBranchId,
                    $productId,
                    $quantity,
                    'production_in',
                    $transferId,
                    'transfer',
                    $userId
                );
            }
        }

        // Update transfer with actual item count
        $st = $pdo->prepare("
            UPDATE stockflow_transfers 
            SET total_items = :ti
            WHERE id = :id
        ");
        $st->execute([':ti' => $totalItems, ':id' => $transferId]);

        // Auto-complete production transfers
        if ($operationType === 'production') {
            $st = $pdo->prepare("
                UPDATE stockflow_transfers 
                SET status = 'received', 
                    received_by_user_id = :u,
                    received_at = NOW()
                WHERE id = :id
            ");
            $st->execute([':u' => $userId, ':id' => $transferId]);

            $message = sprintf(
                'Production entry %s created and completed successfully',
                $transferNumber
            );
        } else {
            $message = sprintf(
                '%s %s created successfully',
                ucfirst($operationType),
                $transferNumber
            );
        }

        $pdo->commit();
        stockflow_json_response(true, [
            'message' => $message,
            'transfer_id' => $transferId,
            'transfer_number' => $transferNumber,
            'total_items' => $totalItems,
            'total_value' => round($totalCost, 2)
        ]);
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log(sprintf(
        'Stockflow save operation error: %s | User: %d | Tenant: %d | Type: %s',
        $e->getMessage(),
        $userId,
        $tenantId,
        $operationType
    ));
    
    stockflow_json_response(false, null, 'Failed to save operation: ' . $e->getMessage());
}