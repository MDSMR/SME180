<?php
/**
 * /controllers/admin/stockflow/manage_transfer.php
 * Unified controller for transfer management operations with workflow settings support
 * Handles create, update, ship, receive, cancel, and item management
 */

declare(strict_types=1);

/* Bootstrap */
require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$action = trim((string)($_POST['action'] ?? ''));

if (empty($action)) {
    stockflow_json_response(false, null, 'Action parameter required');
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    switch ($action) {
        case 'create_transfer':
            handleCreateTransfer($pdo);
            break;

        case 'update_transfer':
            handleUpdateTransfer($pdo);
            break;

        case 'ship_transfer':
            handleShipTransfer($pdo);
            break;

        case 'receive_transfer':
            handleReceiveTransfer($pdo);
            break;

        case 'cancel_transfer':
            handleCancelTransfer($pdo);
            break;

        case 'remove_item':
            handleRemoveItem($pdo);
            break;

        default:
            stockflow_json_response(false, null, 'Invalid action: ' . $action);
    }

} catch (Throwable $e) {
    $userId = $GLOBALS['userId'] ?? 0;
    error_log('Transfer management error: ' . $e->getMessage() . ' | Action: ' . $action . ' | User: ' . $userId);
    stockflow_json_response(false, null, 'Operation failed: ' . $e->getMessage());
}

/**
 * Create new transfer with workflow mode support
 */
function handleCreateTransfer(PDO $pdo): void {
    global $tenantId, $userId;

    stockflow_require_permission('stockflow.transfers.create');

    $fromBranchId   = (int)($_POST['from_branch_id'] ?? 0);
    $toBranchId     = (int)($_POST['to_branch_id'] ?? 0);
    $notes          = trim((string)($_POST['notes'] ?? ''));
    $scheduledDate  = trim((string)($_POST['scheduled_date'] ?? ''));
    $transferItems  = json_decode((string)($_POST['transfer_items'] ?? '[]'), true);
    $shipOnCreate   = (bool)($_POST['ship_on_create'] ?? false); // New parameter

    // Basic validation
    if ($fromBranchId <= 0 || $toBranchId <= 0) {
        stockflow_json_response(false, null, 'Both source and destination branches must be selected');
    }
    if ($fromBranchId === $toBranchId) {
        stockflow_json_response(false, null, 'Source and destination branches cannot be the same');
    }
    if (!is_array($transferItems) || empty($transferItems)) {
        stockflow_json_response(false, null, 'At least one item must be added to the transfer');
    }
    if (mb_strlen($notes) > 500) {
        stockflow_json_response(false, null, 'Notes cannot exceed 500 characters');
    }

    // Get workflow mode
    $workflowMode = stockflow_get_workflow_mode();
    
    // Validate ship-on-create request
    if ($shipOnCreate && !stockflow_validate_transfer_action('ship_on_create', ['id' => 0], $userId)) {
        stockflow_json_response(false, null, 'Ship on create is not allowed with current settings');
    }

    // Validate scheduled date
    $scheduledDateTime = null;
    if ($scheduledDate) {
        $ts = strtotime($scheduledDate . ' 00:00:00');
        if ($ts === false) {
            stockflow_json_response(false, null, 'Invalid scheduled date format');
        }
        $scheduledDateTime = date('Y-m-d H:i:s', $ts);
    }

    // Verify branches exist and belong to tenant
    $stmt = $pdo->prepare("
        SELECT id, name, branch_type 
        FROM branches 
        WHERE tenant_id = :tenant AND is_active = 1 AND id IN (:fb, :tb)
    ");
    $stmt->execute([':tenant' => $tenantId, ':fb' => $fromBranchId, ':tb' => $toBranchId]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($branches) !== 2) {
        stockflow_json_response(false, null, 'Invalid branch selection');
    }

    // Branch access validation
    if (!stockflow_has_permission('stockflow.view_all_branches')) {
        $userBranches = stockflow_get_user_branches();
        $userBranchIds = array_map(fn($b) => (int)$b['id'], $userBranches);

        if (!in_array($fromBranchId, $userBranchIds, true) || !in_array($toBranchId, $userBranchIds, true)) {
            stockflow_json_response(false, null, 'Access denied to selected branches');
        }
    }

    // Validate transfer items and check stock availability
    $validatedItems = [];
    $totalValue = 0;

    foreach ($transferItems as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $quantity = (float)($item['quantity_requested'] ?? $item['quantity'] ?? 0);

        if ($productId <= 0 || $quantity <= 0) {
            continue;
        }

        // Get product details
        $stmt = $pdo->prepare("
            SELECT name_en, inventory_unit, standard_cost, is_inventory_tracked
            FROM products 
            WHERE id = :id AND tenant_id = :tenant AND is_active = 1
        ");
        $stmt->execute([':id' => $productId, ':tenant' => $tenantId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product || !$product['is_inventory_tracked']) {
            stockflow_json_response(false, null, 'Invalid or non-inventory tracked product: ID ' . $productId);
        }

        // Check stock availability at source branch (use available = current - reserved)
        $stmt = $pdo->prepare("
            SELECT current_stock, reserved_stock
            FROM stockflow_stock_levels
            WHERE tenant_id = :tenant AND branch_id = :branch AND product_id = :product
        ");
        $stmt->execute([':tenant' => $tenantId, ':branch' => $fromBranchId, ':product' => $productId]);
        $stockLevel = $stmt->fetch(PDO::FETCH_ASSOC);

        $currentStock  = $stockLevel ? (float)$stockLevel['current_stock']  : 0.0;
        $reservedStock = $stockLevel ? (float)$stockLevel['reserved_stock'] : 0.0;
        $availableStock = $currentStock - $reservedStock;

        if ($availableStock < $quantity) {
            stockflow_json_response(false, null, sprintf(
                'Insufficient stock for %s. Available: %.2f, Requested: %.2f',
                $product['name_en'],
                $availableStock,
                $quantity
            ));
        }

        $unitCost = (float)$product['standard_cost'];
        $itemCost = $quantity * $unitCost;
        $totalValue += $itemCost;

        $validatedItems[] = [
            'product_id' => $productId,
            'product_name' => $product['name_en'],
            'quantity_requested' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $itemCost
        ];
    }

    if (empty($validatedItems)) {
        stockflow_json_response(false, null, 'No valid items found for transfer');
    }

    // Generate transfer number BEFORE transaction
    $transferNumber = stockflow_next_transfer_number($pdo, $tenantId, 'TRF');

    // Start transaction
    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    try {
        if ($workflowMode === 'one_step') {
            // ONE-STEP MODE: Create and complete immediately
            $stmt = $pdo->prepare("
                INSERT INTO stockflow_transfers (
                    tenant_id, transfer_number, from_branch_id, to_branch_id,
                    status, transfer_type, notes, scheduled_date, total_items,
                    created_by_user_id, shipped_by_user_id, received_by_user_id,
                    shipped_at, received_at, created_at
                ) VALUES (
                    :tenant, :number, :from_branch, :to_branch,
                    'received', 'inter_branch_transfer', :notes, :scheduled_date, :total_items,
                    :user, :user, :user,
                    NOW(), NOW(), NOW()
                )
            ");
            $stmt->execute([
                ':tenant' => $tenantId,
                ':number' => $transferNumber,
                ':from_branch' => $fromBranchId,
                ':to_branch' => $toBranchId,
                ':notes' => $notes ?: null,
                ':scheduled_date' => $scheduledDateTime,
                ':total_items' => count($validatedItems),
                ':user' => $userId
            ]);

            $newTransferId = (int)$pdo->lastInsertId();

            // Add transfer items and move stock immediately
            $ins = $pdo->prepare("
                INSERT INTO stockflow_transfer_items (
                    transfer_id, product_id, product_name,
                    quantity_requested, quantity_shipped, quantity_received,
                    unit_cost, total_cost
                ) VALUES (
                    :transfer_id, :product_id, :product_name,
                    :quantity, :quantity, :quantity,
                    :unit_cost, :total_cost
                )
            ");

            foreach ($validatedItems as $it) {
                $ins->execute([
                    ':transfer_id' => $newTransferId,
                    ':product_id' => $it['product_id'],
                    ':product_name' => $it['product_name'],
                    ':quantity' => $it['quantity_requested'],
                    ':unit_cost' => $it['unit_cost'],
                    ':total_cost' => $it['total_cost']
                ]);

                // Move stock out of source branch
                stockflow_update_stock_level(
                    $pdo, $tenantId, $fromBranchId, $it['product_id'],
                    -$it['quantity_requested'], 'transfer_out', $newTransferId, 'transfer', $userId
                );

                // Move stock into destination branch
                stockflow_update_stock_level(
                    $pdo, $tenantId, $toBranchId, $it['product_id'],
                    $it['quantity_requested'], 'transfer_in', $newTransferId, 'transfer', $userId
                );
            }

            if ($startedTx) $pdo->commit();

            stockflow_json_response(true, [
                'transfer_id' => $newTransferId,
                'transfer_number' => $transferNumber,
                'message' => 'Transfer completed successfully (one-step mode)',
                'status' => 'received',
                'workflow_mode' => 'one_step',
                'total_items' => count($validatedItems),
                'total_value' => round($totalValue, 2)
            ]);

        } else {
            // TWO-STEP MODE: Create as pending, optionally ship immediately
            $initialStatus = ($shipOnCreate) ? 'shipped' : 'pending';
            
            $stmt = $pdo->prepare("
                INSERT INTO stockflow_transfers (
                    tenant_id, transfer_number, from_branch_id, to_branch_id,
                    status, transfer_type, notes, scheduled_date, total_items,
                    created_by_user_id, shipped_by_user_id, shipped_at, created_at
                ) VALUES (
                    :tenant, :number, :from_branch, :to_branch,
                    :status, 'inter_branch_transfer', :notes, :scheduled_date, :total_items,
                    :user, :shipped_by, :shipped_at, NOW()
                )
            ");
            $stmt->execute([
                ':tenant' => $tenantId,
                ':number' => $transferNumber,
                ':from_branch' => $fromBranchId,
                ':to_branch' => $toBranchId,
                ':status' => $initialStatus,
                ':notes' => $notes ?: null,
                ':scheduled_date' => $scheduledDateTime,
                ':total_items' => count($validatedItems),
                ':user' => $userId,
                ':shipped_by' => $shipOnCreate ? $userId : null,
                ':shipped_at' => $shipOnCreate ? date('Y-m-d H:i:s') : null
            ]);

            $newTransferId = (int)$pdo->lastInsertId();

            // Add transfer items
            $ins = $pdo->prepare("
                INSERT INTO stockflow_transfer_items (
                    transfer_id, product_id, product_name,
                    quantity_requested, quantity_shipped, unit_cost, total_cost
                ) VALUES (
                    :transfer_id, :product_id, :product_name,
                    :quantity, :shipped_qty, :unit_cost, :total_cost
                )
            ");

            foreach ($validatedItems as $it) {
                $shippedQty = $shipOnCreate ? $it['quantity_requested'] : 0;
                
                $ins->execute([
                    ':transfer_id' => $newTransferId,
                    ':product_id' => $it['product_id'],
                    ':product_name' => $it['product_name'],
                    ':quantity' => $it['quantity_requested'],
                    ':shipped_qty' => $shippedQty,
                    ':unit_cost' => $it['unit_cost'],
                    ':total_cost' => $it['total_cost']
                ]);

                if ($shipOnCreate) {
                    // Ship immediately: move stock out of source branch
                    stockflow_update_stock_level(
                        $pdo, $tenantId, $fromBranchId, $it['product_id'],
                        -$it['quantity_requested'], 'transfer_out', $newTransferId, 'transfer', $userId
                    );
                }
            }

            // Handle stock reservation for pending transfers
            if (!$shipOnCreate && stockflow_reserve_on_pending()) {
                stockflow_handle_stock_reservation($pdo, $newTransferId, 'reserve');
            }

            if ($startedTx) $pdo->commit();

            $message = $shipOnCreate ? 
                'Transfer created and shipped successfully' : 
                'Transfer created successfully with PENDING status';

            stockflow_json_response(true, [
                'transfer_id' => $newTransferId,
                'transfer_number' => $transferNumber,
                'message' => $message,
                'status' => $initialStatus,
                'workflow_mode' => 'two_step',
                'shipped_on_create' => $shipOnCreate,
                'total_items' => count($validatedItems),
                'total_value' => round($totalValue, 2)
            ]);
        }

    } catch (Throwable $e) {
        if ($startedTx) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Update existing transfer
 */
function handleUpdateTransfer(PDO $pdo): void {
    global $tenantId, $userId;

    stockflow_require_permission('stockflow.transfers.edit');

    $transferId    = (int)($_POST['transfer_id'] ?? 0);
    $notes         = trim((string)($_POST['notes'] ?? ''));
    $scheduledDate = trim((string)($_POST['scheduled_date'] ?? ''));
    $transferItems = json_decode((string)($_POST['transfer_items'] ?? '[]'), true);

    if ($transferId <= 0) {
        stockflow_json_response(false, null, 'Invalid transfer ID');
    }

    // Get current transfer
    $stmt = $pdo->prepare("
        SELECT * FROM stockflow_transfers 
        WHERE id = :id AND tenant_id = :tenant
    ");
    $stmt->execute([':id' => $transferId, ':tenant' => $tenantId]);
    $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transfer) {
        stockflow_json_response(false, null, 'Transfer not found');
    }

    if ($transfer['status'] !== 'pending') {
        stockflow_json_response(false, null, 'Only pending transfers can be updated');
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

    // Validate scheduled date
    $scheduledDateTime = null;
    if ($scheduledDate) {
        $ts = strtotime($scheduledDate . ' 00:00:00');
        if ($ts === false) {
            stockflow_json_response(false, null, 'Invalid scheduled date format');
        }
        $scheduledDateTime = date('Y-m-d H:i:s', $ts);
    }

    // Start transaction
    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    try {
        // Release any existing stock reservations if items are changing
        if (is_array($transferItems) && !empty($transferItems) && stockflow_reserve_on_pending()) {
            stockflow_handle_stock_reservation($pdo, $transferId, 'release');
        }

        // Update transfer details
        $stmt = $pdo->prepare("
            UPDATE stockflow_transfers 
            SET notes = :notes, scheduled_date = :scheduled_date, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant
        ");
        $stmt->execute([
            ':notes' => $notes ?: null,
            ':scheduled_date' => $scheduledDateTime,
            ':id' => $transferId,
            ':tenant' => $tenantId
        ]);

        // Update items if provided
        if (is_array($transferItems) && !empty($transferItems)) {
            // Remove existing items
            $stmt = $pdo->prepare("DELETE FROM stockflow_transfer_items WHERE transfer_id = :id");
            $stmt->execute([':id' => $transferId]);

            // Add new items
            $validatedCount = 0;
            $ins = $pdo->prepare("
                INSERT INTO stockflow_transfer_items (
                    transfer_id, product_id, product_name,
                    quantity_requested, unit_cost, total_cost
                ) VALUES (
                    :transfer_id, :product_id, :product_name,
                    :quantity, :unit_cost, :total_cost
                )
            ");

            foreach ($transferItems as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $quantity  = (float)($item['quantity_requested'] ?? $item['quantity'] ?? 0);
                if ($productId <= 0 || $quantity <= 0) continue;

                $stmt = $pdo->prepare("
                    SELECT name_en, standard_cost, is_inventory_tracked
                    FROM products 
                    WHERE id = :id AND tenant_id = :tenant AND is_active = 1
                ");
                $stmt->execute([':id' => $productId, ':tenant' => $tenantId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($product && $product['is_inventory_tracked']) {
                    $unitCost = (float)$product['standard_cost'];
                    $ins->execute([
                        ':transfer_id'  => $transferId,
                        ':product_id'   => $productId,
                        ':product_name' => $product['name_en'],
                        ':quantity'     => $quantity,
                        ':unit_cost'    => $unitCost,
                        ':total_cost'   => $unitCost * $quantity
                    ]);
                    $validatedCount++;
                }
            }

            // Update total items count
            $stmt = $pdo->prepare("
                UPDATE stockflow_transfers 
                SET total_items = :count 
                WHERE id = :id
            ");
            $stmt->execute([':count' => $validatedCount, ':id' => $transferId]);

            // Reserve stock for updated items if enabled
            if ($validatedCount > 0 && stockflow_reserve_on_pending()) {
                stockflow_handle_stock_reservation($pdo, $transferId, 'reserve');
            }
        }

        if ($startedTx) $pdo->commit();

        stockflow_json_response(true, [
            'message' => 'Transfer updated successfully'
        ]);

    } catch (Throwable $e) {
        if ($startedTx) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Ship pending transfer with workflow validation
 */
function handleShipTransfer(PDO $pdo): void {
    global $tenantId, $userId;

    stockflow_require_permission('stockflow.transfers.ship');

    $transferId = (int)($_POST['transfer_id'] ?? 0);
    if ($transferId <= 0) {
        stockflow_json_response(false, null, 'Invalid transfer ID');
    }

    // Get transfer with items
    $stmt = $pdo->prepare("
        SELECT t.*, fb.name as from_branch_name, tb.name as to_branch_name
        FROM stockflow_transfers t
        LEFT JOIN branches fb ON fb.id = t.from_branch_id AND fb.tenant_id = t.tenant_id
        LEFT JOIN branches tb ON tb.id = t.to_branch_id   AND tb.tenant_id = t.tenant_id
        WHERE t.id = :id AND t.tenant_id = :tenant
    ");
    $stmt->execute([':id' => $transferId, ':tenant' => $tenantId]);
    $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transfer) {
        stockflow_json_response(false, null, 'Transfer not found');
    }
    if ($transfer['status'] !== 'pending') {
        stockflow_json_response(false, null, 'Only pending transfers can be shipped');
    }

    // Validate workflow permissions including separation of duties
    if (!stockflow_validate_transfer_action('ship', $transfer, $userId)) {
        stockflow_json_response(false, null, 'Access denied: Cannot ship this transfer due to workflow restrictions or separation of duties policy');
    }

    // Branch access check
    if (!stockflow_has_permission('stockflow.view_all_branches')) {
        $userBranches = stockflow_get_user_branches();
        $userBranchIds = array_map(fn($b) => (int)$b['id'], $userBranches);

        if (!in_array((int)$transfer['from_branch_id'], $userBranchIds, true)) {
            stockflow_json_response(false, null, 'Access denied: Cannot ship from this branch');
        }
    }

    // Get transfer items
    $stmt = $pdo->prepare("
        SELECT ti.*, p.inventory_unit
        FROM stockflow_transfer_items ti
        JOIN products p ON p.id = ti.product_id
        WHERE ti.transfer_id = :id
    ");
    $stmt->execute([':id' => $transferId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($items)) {
        stockflow_json_response(false, null, 'Cannot ship transfer with no items');
    }

    // Start transaction
    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    try {
        // Release any reserved stock first
        if (stockflow_reserve_on_pending()) {
            stockflow_handle_stock_reservation($pdo, $transferId, 'release');
        }

        $stockShortages = [];
        $shippedItems = [];

        // Check stock availability (available = current - reserved)
        foreach ($items as $item) {
            $productId   = (int)$item['product_id'];
            $requestedQty= (float)$item['quantity_requested'];

            $stmt = $pdo->prepare("
                SELECT current_stock, reserved_stock
                FROM stockflow_stock_levels
                WHERE tenant_id = :tenant AND branch_id = :branch AND product_id = :product
            ");
            $stmt->execute([
                ':tenant' => $tenantId,
                ':branch' => $transfer['from_branch_id'],
                ':product' => $productId
            ]);
            $stockLevel = $stmt->fetch(PDO::FETCH_ASSOC);

            $availableQty = ($stockLevel ? (float)$stockLevel['current_stock'] : 0.0) -
                            ($stockLevel ? (float)$stockLevel['reserved_stock'] : 0.0);

            if ($availableQty < $requestedQty) {
                $stockShortages[] = [
                    'product_name' => $item['product_name'],
                    'requested'    => $requestedQty,
                    'available'    => $availableQty,
                    'unit'         => $item['inventory_unit']
                ];
                continue;
            }

            $shippedItems[] = [
                'item_id'    => (int)$item['id'],
                'product_id' => $productId,
                'shipped_qty'=> $requestedQty
            ];
        }

        if (!empty($stockShortages)) {
            if ($startedTx) $pdo->rollBack();
            $shortageMsg = "Stock shortages prevent shipping:\n";
            foreach ($stockShortages as $s) {
                $shortageMsg .= sprintf("- %s: need %.2f %s, have %.2f %s\n",
                    $s['product_name'], $s['requested'], $s['unit'], $s['available'], $s['unit']);
            }
            stockflow_json_response(false, null, trim($shortageMsg));
        }

        // Update stock levels and items
        $upd = $pdo->prepare("
            UPDATE stockflow_transfer_items 
            SET quantity_shipped = :qty, updated_at = NOW()
            WHERE id = :id
        ");

        foreach ($shippedItems as $si) {
            stockflow_update_stock_level(
                $pdo,
                $tenantId,
                (int)$transfer['from_branch_id'],
                $si['product_id'],
                -$si['shipped_qty'],
                'transfer_out',
                $transferId,
                'transfer',
                $userId
            );
            $upd->execute([
                ':qty' => $si['shipped_qty'],
                ':id'  => $si['item_id']
            ]);
        }

        // Update transfer status
        $stmt = $pdo->prepare("
            UPDATE stockflow_transfers 
            SET status = 'shipped', shipped_at = NOW(), shipped_by_user_id = :user, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':user' => $userId, ':id' => $transferId]);

        if ($startedTx) $pdo->commit();

        stockflow_json_response(true, [
            'message' => sprintf('Transfer %s shipped successfully', $transfer['transfer_number']),
            'shipped_items' => count($shippedItems)
        ]);

    } catch (Throwable $e) {
        if ($startedTx) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Receive shipped transfer with workflow validation
 */
function handleReceiveTransfer(PDO $pdo): void {
    global $tenantId, $userId;

    stockflow_require_permission('stockflow.transfers.receive');

    $transferId = (int)($_POST['transfer_id'] ?? 0);

    if ($transferId <= 0) {
        stockflow_json_response(false, null, 'Invalid transfer ID');
    }

    // Get transfer
    $stmt = $pdo->prepare("
        SELECT * FROM stockflow_transfers 
        WHERE id = :id AND tenant_id = :tenant
    ");
    $stmt->execute([':id' => $transferId, ':tenant' => $tenantId]);
    $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transfer || $transfer['status'] !== 'shipped') {
        stockflow_json_response(false, null, 'Transfer not found or not shipped');
    }

    // Validate workflow permissions including separation of duties
    if (!stockflow_validate_transfer_action('receive', $transfer, $userId)) {
        stockflow_json_response(false, null, 'Access denied: Cannot receive this transfer due to workflow restrictions or separation of duties policy');
    }

    // Branch access check - user must have access to receiving branch
    if (!stockflow_has_permission('stockflow.view_all_branches')) {
        $userBranches = stockflow_get_user_branches();
        $userBranchIds = array_map(fn($b) => (int)$b['id'], $userBranches);

        if (!in_array((int)$transfer['to_branch_id'], $userBranchIds, true)) {
            stockflow_json_response(false, null, 'Access denied: Cannot receive at this branch');
        }
    }

    // Get shipped items
    $stmt = $pdo->prepare("
        SELECT * FROM stockflow_transfer_items 
        WHERE transfer_id = :id AND quantity_shipped > 0
    ");
    $stmt->execute([':id' => $transferId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Start transaction
    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    try {
        $receivedItems = 0;

        // Update stock for each received item
        $upd = $pdo->prepare("
            UPDATE stockflow_transfer_items 
            SET quantity_received = :qty, updated_at = NOW()
            WHERE id = :id
        ");

        foreach ($items as $item) {
            $receivedQty = (float)$item['quantity_shipped'];

            stockflow_update_stock_level(
                $pdo,
                $tenantId,
                (int)$transfer['to_branch_id'],
                (int)$item['product_id'],
                $receivedQty,
                'transfer_in',
                $transferId,
                'transfer',
                $userId
            );

            $upd->execute([
                ':qty' => $receivedQty,
                ':id'  => (int)$item['id']
            ]);

            $receivedItems++;
        }

        // Update transfer status
        $stmt = $pdo->prepare("
            UPDATE stockflow_transfers 
            SET status = 'received', received_at = NOW(), received_by_user_id = :user, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':user' => $userId, ':id' => $transferId]);

        if ($startedTx) $pdo->commit();

        stockflow_json_response(true, [
            'message' => sprintf('Transfer %s received successfully', $transfer['transfer_number']),
            'received_items' => $receivedItems
        ]);

    } catch (Throwable $e) {
        if ($startedTx) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Cancel pending transfer
 */
function handleCancelTransfer(PDO $pdo): void {
    global $tenantId, $userId;

    stockflow_require_permission('stockflow.transfers.cancel');

    $transferId = (int)($_POST['transfer_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));

    if ($transferId <= 0) {
        stockflow_json_response(false, null, 'Invalid transfer ID');
    }

    if (empty($reason)) {
        stockflow_json_response(false, null, 'Cancellation reason is required');
    }

    // Get transfer
    $stmt = $pdo->prepare("
        SELECT * FROM stockflow_transfers 
        WHERE id = :id AND tenant_id = :tenant
    ");
    $stmt->execute([':id' => $transferId, ':tenant' => $tenantId]);
    $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transfer) {
        stockflow_json_response(false, null, 'Transfer not found');
    }

    if ($transfer['status'] !== 'pending') {
        stockflow_json_response(false, null, 'Only pending transfers can be cancelled');
    }

    // Start transaction to handle stock reservation release
    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    try {
        // Release any reserved stock
        if (stockflow_reserve_on_pending()) {
            stockflow_handle_stock_reservation($pdo, $transferId, 'release');
        }

        // Update transfer status
        $stmt = $pdo->prepare("
            UPDATE stockflow_transfers 
            SET status = 'cancelled', cancelled_at = NOW(), cancelled_by_user_id = :user, 
                cancellation_reason = :reason, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':user' => $userId,
            ':reason' => $reason,
            ':id' => $transferId
        ]);

        if ($startedTx) $pdo->commit();

        stockflow_json_response(true, [
            'message' => sprintf('Transfer %s cancelled successfully', $transfer['transfer_number'])
        ]);

    } catch (Throwable $e) {
        if ($startedTx) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Remove item from transfer
 */
function handleRemoveItem(PDO $pdo): void {
    global $tenantId, $userId;

    stockflow_require_permission('stockflow.transfers.edit');

    $itemId = (int)($_POST['item_id'] ?? 0);

    if ($itemId <= 0) {
        stockflow_json_response(false, null, 'Invalid item ID');
    }

    // Get item and transfer info
    $stmt = $pdo->prepare("
        SELECT ti.*, t.status, t.transfer_number
        FROM stockflow_transfer_items ti
        JOIN stockflow_transfers t ON t.id = ti.transfer_id
        WHERE ti.id = :id AND t.tenant_id = :tenant
    ");
    $stmt->execute([':id' => $itemId, ':tenant' => $tenantId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        stockflow_json_response(false, null, 'Item not found');
    }

    if ($item['status'] !== 'pending') {
        stockflow_json_response(false, null, 'Cannot remove items from non-pending transfers');
    }

    // Start transaction to handle stock reservation
    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    try {
        // Release stock reservation for this item if enabled
        if (stockflow_reserve_on_pending()) {
            $pdo->prepare("
                UPDATE stockflow_stock_levels sl
                JOIN stockflow_transfers t ON t.from_branch_id = sl.branch_id
                SET sl.reserved_stock = GREATEST(0, sl.reserved_stock - :qty)
                WHERE t.id = :transfer_id 
                AND t.tenant_id = :tenant
                AND sl.tenant_id = :tenant  
                AND sl.product_id = :product_id
            ")->execute([
                ':qty' => (float)$item['quantity_requested'],
                ':transfer_id' => $item['transfer_id'],
                ':tenant' => $tenantId,
                ':product_id' => (int)$item['product_id']
            ]);
        }

        // Remove item
        $stmt = $pdo->prepare("DELETE FROM stockflow_transfer_items WHERE id = :id");
        $stmt->execute([':id' => $itemId]);

        if ($stmt->rowCount() === 0) {
            if ($startedTx) $pdo->rollBack();
            stockflow_json_response(false, null, 'Item not found in transfer');
        }

        // Update transfer item count
        $stmt = $pdo->prepare("
            UPDATE stockflow_transfers 
            SET total_items = (SELECT COUNT(*) FROM stockflow_transfer_items WHERE transfer_id = :id),
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $item['transfer_id']]);

        if ($startedTx) $pdo->commit();

        stockflow_json_response(true, [
            'message' => 'Item removed successfully'
        ]);

    } catch (Throwable $e) {
        if ($startedTx) $pdo->rollBack();
        throw $e;
    }
}
?>