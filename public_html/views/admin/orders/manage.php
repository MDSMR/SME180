<?php
/**
 * SME 180 POS - Order Management View
 * Path: /public_html/views/admin/orders/manage.php
 * 
 * Comprehensive order management interface with all Phase 2 features
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_middleware.php';
require_once __DIR__ . '/../includes/header.php';

// Check authentication
requireLogin();

$tenantId = $_SESSION['tenant_id'] ?? 0;
$branchId = $_SESSION['branch_id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role_key'] ?? '';

// Get order ID from request
$orderId = (int)($_GET['id'] ?? 0);

if (!$orderId) {
    header('Location: /views/admin/orders/index.php');
    exit;
}

try {
    $pdo = db();
    
    // Fetch order with all details
    $stmt = $pdo->prepare("
        SELECT o.*, 
               c.name as customer_name, c.phone as customer_phone,
               dt.table_number, dt.zone_id,
               u.name as created_by_name,
               b.name as branch_name,
               (SELECT SUM(amount) FROM order_payments WHERE order_id = o.id AND payment_type = 'payment' AND status = 'completed') as total_paid,
               (SELECT SUM(amount) FROM order_refunds WHERE order_id = o.id AND status = 'completed') as total_refunded
        FROM orders o
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN dining_tables dt ON dt.id = o.table_id
        LEFT JOIN users u ON u.id = o.created_by_user_id
        LEFT JOIN branches b ON b.id = o.branch_id
        WHERE o.id = :order_id 
        AND o.tenant_id = :tenant_id
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $_SESSION['error'] = 'Order not found';
        header('Location: /views/admin/orders/index.php');
        exit;
    }
    
    // Fetch order items with variations
    $stmt = $pdo->prepare("
        SELECT oi.*, p.category_id, pc.name as category_name
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE oi.order_id = :order_id
        ORDER BY oi.id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch item variations
    foreach ($items as &$item) {
        $stmt = $pdo->prepare("
            SELECT * FROM order_item_variations 
            WHERE order_item_id = :item_id
        ");
        $stmt->execute(['item_id' => $item['id']]);
        $item['variations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Fetch payment history
    $stmt = $pdo->prepare("
        SELECT op.*, u.name as processed_by_name
        FROM order_payments op
        LEFT JOIN users u ON u.id = op.processed_by
        WHERE op.order_id = :order_id
        ORDER BY op.created_at DESC
    ");
    $stmt->execute(['order_id' => $orderId]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch discounts applied
    $stmt = $pdo->prepare("
        SELECT da.*, u.name as applied_by_name
        FROM order_discounts_applied da
        LEFT JOIN users u ON u.id = da.applied_by
        WHERE da.order_id = :order_id
        ORDER BY da.applied_at DESC
    ");
    $stmt->execute(['order_id' => $orderId]);
    $discounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch event history
    $stmt = $pdo->prepare("
        SELECT e.*, u.name as created_by_name
        FROM order_item_events e
        LEFT JOIN users u ON u.id = e.created_by
        WHERE e.order_id = :order_id
        ORDER BY e.created_at DESC
        LIMIT 20
    ");
    $stmt->execute(['order_id' => $orderId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get available tables for transfer
    $availableTables = [];
    if ($order['order_type'] === 'dine_in') {
        $stmt = $pdo->prepare("
            SELECT id, table_number, zone_id 
            FROM dining_tables 
            WHERE tenant_id = :tenant_id 
            AND branch_id = :branch_id
            AND is_active = 1
            AND is_occupied = 0
            ORDER BY table_number
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId
        ]);
        $availableTables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get settings
    $stmt = $pdo->prepare("
        SELECT `key`, `value` FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` LIKE 'pos_%'
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    // Calculate status
    $canEdit = !in_array($order['status'], ['closed', 'voided', 'refunded']) && 
               $order['payment_status'] !== 'paid';
    $canPay = $order['payment_status'] === 'unpaid' && 
              !in_array($order['status'], ['voided', 'refunded']);
    $canVoid = $order['payment_status'] !== 'paid' && 
               !in_array($order['status'], ['voided', 'refunded']);
    $canRefund = $order['payment_status'] === 'paid' && 
                 $order['status'] !== 'refunded';
    $hasFiredItems = false;
    $hasPendingItems = false;
    
    foreach ($items as $item) {
        if ($item['fire_status'] === 'fired') $hasFiredItems = true;
        if ($item['fire_status'] === 'pending' && !$item['is_voided']) $hasPendingItems = true;
    }
    
} catch (Exception $e) {
    $_SESSION['error'] = 'Error loading order: ' . $e->getMessage();
    header('Location: /views/admin/orders/index.php');
    exit;
}

// Get currency symbol
$currencySymbol = $settings['currency_symbol'] ?? 'EGP';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Order #<?= htmlspecialchars($order['receipt_reference']) ?> - SME 180</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .order-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            display: inline-block;
        }
        .status-open { background: #e3f2fd; color: #1976d2; }
        .status-sent { background: #fff3e0; color: #f57c00; }
        .status-preparing { background: #fce4ec; color: #c2185b; }
        .status-ready { background: #e8f5e9; color: #388e3c; }
        .status-served { background: #f3e5f5; color: #7b1fa2; }
        .status-closed { background: #eceff1; color: #455a64; }
        .status-voided { background: #ffebee; color: #c62828; }
        .status-refunded { background: #fbe9e7; color: #d84315; }
        
        .payment-unpaid { background: #ffebee; color: #c62828; }
        .payment-partial { background: #fff3e0; color: #f57c00; }
        .payment-paid { background: #e8f5e9; color: #388e3c; }
        
        .item-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s;
        }
        .item-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .item-voided {
            opacity: 0.5;
            text-decoration: line-through;
        }
        .item-fired {
            background: #fff8e1;
        }
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        .timeline-event {
            border-left: 3px solid #667eea;
            padding-left: 1rem;
            margin-bottom: 1rem;
            position: relative;
        }
        .timeline-event::before {
            content: '';
            position: absolute;
            left: -7px;
            top: 5px;
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: #667eea;
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .totals-section {
            background: #f5f5f5;
            padding: 1.5rem;
            border-radius: 8px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .totals-row:last-child {
            border-bottom: none;
            font-size: 1.25rem;
            font-weight: bold;
            margin-top: 0.5rem;
            padding-top: 1rem;
            border-top: 2px solid #333;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Order Header -->
        <div class="order-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-0">
                        Order #<?= htmlspecialchars($order['receipt_reference']) ?>
                        <?php if ($order['table_id']): ?>
                            <span class="badge bg-light text-dark ms-2">
                                <i class="bi bi-geo-alt"></i> Table <?= htmlspecialchars($order['table_number']) ?>
                            </span>
                        <?php endif; ?>
                    </h1>
                    <p class="mb-0 mt-2">
                        <i class="bi bi-clock"></i> <?= date('F j, Y g:i A', strtotime($order['created_at'])) ?>
                        | <i class="bi bi-person"></i> <?= htmlspecialchars($order['created_by_name']) ?>
                        | <i class="bi bi-shop"></i> <?= htmlspecialchars($order['branch_name']) ?>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <span class="status-badge status-<?= $order['status'] ?> me-2">
                        <?= ucfirst($order['status']) ?>
                    </span>
                    <span class="status-badge payment-<?= $order['payment_status'] ?>">
                        Payment: <?= ucfirst($order['payment_status']) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <?php if ($canEdit || $canPay || $canVoid || $canRefund || $hasPendingItems): ?>
        <div class="action-buttons mb-4">
            <?php if ($hasPendingItems): ?>
                <button class="btn btn-warning" onclick="fireToKitchen('all')">
                    <i class="bi bi-fire"></i> Fire All to Kitchen
                </button>
            <?php endif; ?>
            
            <?php if ($canPay): ?>
                <button class="btn btn-success" onclick="processPayment()">
                    <i class="bi bi-credit-card"></i> Process Payment
                </button>
            <?php endif; ?>
            
            <?php if ($canEdit): ?>
                <button class="btn btn-primary" onclick="addItems()">
                    <i class="bi bi-plus-circle"></i> Add Items
                </button>
                <button class="btn btn-info" onclick="applyDiscount()">
                    <i class="bi bi-percent"></i> Apply Discount
                </button>
            <?php endif; ?>
            
            <?php if ($order['order_type'] === 'dine_in' && $canEdit): ?>
                <button class="btn btn-secondary" onclick="transferTable()">
                    <i class="bi bi-arrow-left-right"></i> Transfer Table
                </button>
            <?php endif; ?>
            
            <?php if ($canVoid): ?>
                <button class="btn btn-danger" onclick="voidOrder()">
                    <i class="bi bi-x-circle"></i> Void Order
                </button>
            <?php endif; ?>
            
            <?php if ($canRefund): ?>
                <button class="btn btn-warning" onclick="processRefund()">
                    <i class="bi bi-arrow-counterclockwise"></i> Refund
                </button>
            <?php endif; ?>
            
            <button class="btn btn-outline-primary" onclick="printReceipt()">
                <i class="bi bi-printer"></i> Print Receipt
            </button>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column: Items and Details -->
            <div class="col-md-8">
                <!-- Order Items -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Order Items</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($items as $item): ?>
                        <div class="item-card <?= $item['is_voided'] ? 'item-voided' : '' ?> <?= $item['fire_status'] === 'fired' ? 'item-fired' : '' ?>">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <h6 class="mb-1">
                                        <?= htmlspecialchars($item['product_name']) ?>
                                        <?php if ($item['is_voided']): ?>
                                            <span class="badge bg-danger ms-2">VOIDED</span>
                                        <?php endif; ?>
                                    </h6>
                                    <?php if (!empty($item['variations'])): ?>
                                        <small class="text-muted">
                                            <?php foreach ($item['variations'] as $var): ?>
                                                <span class="badge bg-light text-dark me-1">
                                                    <?= htmlspecialchars($var['variation_group']) ?>: <?= htmlspecialchars($var['variation_value']) ?>
                                                    <?php if ($var['price_delta'] > 0): ?>
                                                        (+<?= $currencySymbol ?><?= number_format($var['price_delta'], 2) ?>)
                                                    <?php endif; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php if ($item['notes']): ?>
                                        <div class="text-muted small mt-1">
                                            <i class="bi bi-chat-dots"></i> <?= htmlspecialchars($item['notes']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($item['kitchen_notes']): ?>
                                        <div class="text-warning small mt-1">
                                            <i class="bi bi-chef-hat"></i> Kitchen: <?= htmlspecialchars($item['kitchen_notes']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-2 text-center">
                                    <span class="badge bg-secondary">Qty: <?= $item['quantity'] ?></span>
                                    <div class="small text-muted mt-1">
                                        <?= $currencySymbol ?><?= number_format($item['unit_price'], 2) ?> each
                                    </div>
                                </div>
                                <div class="col-md-2 text-center">
                                    <?php
                                    $fireStatusColors = [
                                        'pending' => 'secondary',
                                        'fired' => 'warning',
                                        'preparing' => 'info',
                                        'ready' => 'success'
                                    ];
                                    $fireColor = $fireStatusColors[$item['fire_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $fireColor ?>">
                                        <?= ucfirst($item['fire_status']) ?>
                                    </span>
                                    <?php if ($item['fired_at']): ?>
                                        <div class="small text-muted mt-1">
                                            <?= date('g:i A', strtotime($item['fired_at'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-2 text-end">
                                    <strong><?= $currencySymbol ?><?= number_format($item['line_total'], 2) ?></strong>
                                    <?php if ($item['discount_amount'] > 0): ?>
                                        <div class="small text-success">
                                            -<?= $currencySymbol ?><?= number_format($item['discount_amount'], 2) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!$item['is_voided'] && $canEdit): ?>
                                        <div class="mt-2">
                                            <?php if ($item['fire_status'] === 'pending'): ?>
                                                <button class="btn btn-sm btn-warning" onclick="fireItem(<?= $item['id'] ?>)">
                                                    <i class="bi bi-fire"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-danger" onclick="voidItem(<?= $item['id'] ?>)">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($items)): ?>
                            <p class="text-muted text-center">No items in this order</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment History -->
                <?php if (!empty($payments)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Payment History</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date/Time</th>
                                        <th>Method</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Processed By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?= date('M j, g:i A', strtotime($payment['created_at'])) ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?= ucfirst($payment['payment_method']) ?></span>
                                        </td>
                                        <td><?= ucfirst($payment['payment_type']) ?></td>
                                        <td>
                                            <?= $currencySymbol ?><?= number_format($payment['amount'], 2) ?>
                                            <?php if ($payment['payment_type'] === 'refund'): ?>
                                                <i class="bi bi-arrow-return-left text-danger"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $payment['status'] === 'completed' ? 'success' : 'warning' ?>">
                                                <?= ucfirst($payment['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($payment['processed_by_name'] ?? 'System') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Event Timeline -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Order Timeline</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($events as $event): ?>
                        <div class="timeline-event">
                            <div class="d-flex justify-content-between">
                                <strong><?= ucfirst(str_replace('_', ' ', $event['event_type'])) ?></strong>
                                <small class="text-muted">
                                    <?= date('M j, g:i A', strtotime($event['created_at'])) ?>
                                </small>
                            </div>
                            <small class="text-muted">
                                by <?= htmlspecialchars($event['created_by_name'] ?? 'System') ?>
                            </small>
                            <?php 
                            $payload = json_decode($event['payload'], true);
                            if ($payload && !empty($payload)): 
                            ?>
                                <div class="small mt-1">
                                    <?php
                                    foreach ($payload as $key => $value) {
                                        if (!is_array($value) && !is_object($value)) {
                                            echo htmlspecialchars(ucfirst(str_replace('_', ' ', $key))) . ': ' . htmlspecialchars($value) . '<br>';
                                        }
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Totals and Customer Info -->
            <div class="col-md-4">
                <!-- Order Totals -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Order Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="totals-section">
                            <div class="totals-row">
                                <span>Subtotal:</span>
                                <span><?= $currencySymbol ?><?= number_format($order['subtotal_amount'], 2) ?></span>
                            </div>
                            <?php if ($order['discount_amount'] > 0): ?>
                            <div class="totals-row text-success">
                                <span>Discount:</span>
                                <span>-<?= $currencySymbol ?><?= number_format($order['discount_amount'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($order['service_charge_amount'] > 0): ?>
                            <div class="totals-row">
                                <span>Service Charge (<?= number_format($order['service_charge_percent'], 1) ?>%):</span>
                                <span><?= $currencySymbol ?><?= number_format($order['service_charge_amount'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($order['tax_amount'] > 0): ?>
                            <div class="totals-row">
                                <span>Tax (<?= number_format($order['tax_percent'], 1) ?>%):</span>
                                <span><?= $currencySymbol ?><?= number_format($order['tax_amount'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($order['tip_amount'] > 0): ?>
                            <div class="totals-row text-info">
                                <span>Tip:</span>
                                <span><?= $currencySymbol ?><?= number_format($order['tip_amount'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="totals-row">
                                <span>Total:</span>
                                <span><?= $currencySymbol ?><?= number_format($order['total_amount'], 2) ?></span>
                            </div>
                            <?php if ($order['total_paid'] > 0): ?>
                            <div class="totals-row text-success">
                                <span>Paid:</span>
                                <span><?= $currencySymbol ?><?= number_format($order['total_paid'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($order['total_refunded'] > 0): ?>
                            <div class="totals-row text-danger">
                                <span>Refunded:</span>
                                <span><?= $currencySymbol ?><?= number_format($order['total_refunded'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Customer Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Customer Information</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($order['customer_id']): ?>
                            <p class="mb-2">
                                <i class="bi bi-person"></i> 
                                <strong><?= htmlspecialchars($order['customer_name']) ?></strong>
                            </p>
                            <?php if ($order['customer_phone']): ?>
                            <p class="mb-2">
                                <i class="bi bi-telephone"></i> 
                                <?= htmlspecialchars($order['customer_phone']) ?>
                            </p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-muted">Walk-in Customer</p>
                        <?php endif; ?>
                        
                        <?php if ($order['guest_count'] > 0): ?>
                        <p class="mb-2">
                            <i class="bi bi-people"></i> 
                            Guests: <?= $order['guest_count'] ?>
                        </p>
                        <?php endif; ?>
                        
                        <?php if ($order['order_notes']): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            <?= htmlspecialchars($order['order_notes']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if ($canEdit && $order['tip_amount'] == 0): ?>
                            <button class="btn btn-outline-info" onclick="addTip()">
                                <i class="bi bi-cash-coin"></i> Add Tip
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($canEdit && $order['order_type'] === 'dine_in'): ?>
                            <button class="btn btn-outline-secondary" onclick="adjustServiceCharge()">
                                <i class="bi bi-percent"></i> Adjust Service Charge
                            </button>
                            <?php endif; ?>
                            
                            <button class="btn btn-outline-primary" onclick="viewReceipt()">
                                <i class="bi bi-receipt"></i> View Receipt
                            </button>
                            
                            <a href="/views/admin/orders/index.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Orders
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Payment Modal -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Process Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6>Amount Due</h6>
                            <h3><?= $currencySymbol ?><span id="amountDue"><?= number_format($order['total_amount'], 2) ?></span></h3>
                        </div>
                        <div class="col-md-6">
                            <h6>Add Tip</h6>
                            <div class="btn-group" role="group">
                                <button class="btn btn-outline-primary" onclick="setTip(10)">10%</button>
                                <button class="btn btn-outline-primary" onclick="setTip(15)">15%</button>
                                <button class="btn btn-outline-primary" onclick="setTip(20)">20%</button>
                                <button class="btn btn-outline-primary" onclick="setTip(0)">Custom</button>
                            </div>
                            <input type="number" class="form-control mt-2" id="tipAmount" placeholder="Tip amount">
                        </div>
                    </div>
                    
                    <h6>Payment Methods</h6>
                    <div id="paymentMethods">
                        <div class="payment-method-row mb-2">
                            <div class="row">
                                <div class="col-md-4">
                                    <select class="form-select payment-method">
                                        <option value="cash">Cash</option>
                                        <option value="card">Card</option>
                                        <option value="online">Online</option>
                                        <option value="wallet">Wallet</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <input type="number" class="form-control payment-amount" placeholder="Amount">
                                </div>
                                <div class="col-md-4">
                                    <input type="text" class="form-control payment-reference" placeholder="Reference (optional)">
                                </div>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-secondary" onclick="addPaymentMethod()">
                        <i class="bi bi-plus"></i> Add Payment Method
                    </button>
                    
                    <div class="mt-3">
                        <h6>Total to Pay: <?= $currencySymbol ?><span id="totalToPay">0.00</span></h6>
                        <h6>Change: <?= $currencySymbol ?><span id="changeAmount">0.00</span></h6>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="confirmPayment()">Process Payment</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const orderId = <?= $orderId ?>;
        const orderTotal = <?= $order['total_amount'] ?>;
        const currencySymbol = '<?= $currencySymbol ?>';
        
        // Fire to Kitchen
        function fireToKitchen(type) {
            Swal.fire({
                title: 'Fire to Kitchen?',
                text: 'Send items to kitchen for preparation',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Fire',
                confirmButtonColor: '#ff9800'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('/pos/api/order/fire.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            order_id: orderId,
                            fire_type: type
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Fired!', 'Items sent to kitchen', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            Swal.fire('Error', data.error, 'error');
                        }
                    });
                }
            });
        }
        
        // Process Payment
        function processPayment() {
            document.getElementById('paymentModal').classList.add('show');
            document.getElementById('paymentModal').style.display = 'block';
            calculatePaymentTotals();
        }
        
        function calculatePaymentTotals() {
            const tipAmount = parseFloat(document.getElementById('tipAmount').value) || 0;
            const totalDue = orderTotal + tipAmount;
            
            let totalPaid = 0;
            document.querySelectorAll('.payment-amount').forEach(input => {
                totalPaid += parseFloat(input.value) || 0;
            });
            
            const change = Math.max(0, totalPaid - totalDue);
            
            document.getElementById('totalToPay').textContent = totalDue.toFixed(2);
            document.getElementById('changeAmount').textContent = change.toFixed(2);
        }
        
        function confirmPayment() {
            const payments = [];
            document.querySelectorAll('.payment-method-row').forEach(row => {
                const method = row.querySelector('.payment-method').value;
                const amount = parseFloat(row.querySelector('.payment-amount').value);
                const reference = row.querySelector('.payment-reference').value;
                
                if (amount > 0) {
                    payments.push({method, amount, reference});
                }
            });
            
            const tipAmount = parseFloat(document.getElementById('tipAmount').value) || 0;
            
            fetch('/pos/api/order/pay.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    order_id: orderId,
                    payments: payments,
                    tip_amount: tipAmount
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success!', 'Payment processed successfully', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            });
        }
        
        // Void Order
        function voidOrder() {
            Swal.fire({
                title: 'Void Order?',
                input: 'text',
                inputLabel: 'Reason for voiding',
                inputPlaceholder: 'Enter reason...',
                showCancelButton: true,
                confirmButtonText: 'Void Order',
                confirmButtonColor: '#dc3545',
                inputValidator: (value) => {
                    if (!value) {
                        return 'Please enter a reason';
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('/pos/api/order/void_order.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            order_id: orderId,
                            reason: result.value
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.requires_approval) {
                            requestApprovalPin('void_order', result.value);
                        } else if (data.success) {
                            Swal.fire('Voided!', 'Order has been voided', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            Swal.fire('Error', data.error, 'error');
                        }
                    });
                }
            });
        }
        
        // Request Approval PIN
        function requestApprovalPin(action, reason) {
            Swal.fire({
                title: 'Manager Approval Required',
                input: 'password',
                inputLabel: 'Enter manager PIN',
                inputPlaceholder: 'PIN',
                showCancelButton: true,
                confirmButtonText: 'Approve',
                inputValidator: (value) => {
                    if (!value) {
                        return 'Please enter PIN';
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const endpoint = action === 'void_order' ? '/pos/api/order/void_order.php' : '/pos/api/order/void_item.php';
                    fetch(endpoint, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            order_id: orderId,
                            reason: reason,
                            approval_pin: result.value
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Approved!', 'Action completed successfully', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            Swal.fire('Error', data.error || 'Invalid PIN', 'error');
                        }
                    });
                }
            });
        }
        
        // Add more payment methods
        function addPaymentMethod() {
            const container = document.getElementById('paymentMethods');
            const newRow = document.querySelector('.payment-method-row').cloneNode(true);
            newRow.querySelectorAll('input').forEach(input => input.value = '');
            container.appendChild(newRow);
        }
        
        // Set tip percentage
        function setTip(percent) {
            if (percent === 0) {
                document.getElementById('tipAmount').focus();
            } else {
                const tipAmount = (orderTotal * percent / 100).toFixed(2);
                document.getElementById('tipAmount').value = tipAmount;
                calculatePaymentTotals();
            }
        }
        
        // Update totals on input change
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('payment-amount') || e.target.id === 'tipAmount') {
                calculatePaymentTotals();
            }
        });
    </script>
</body>
</html>