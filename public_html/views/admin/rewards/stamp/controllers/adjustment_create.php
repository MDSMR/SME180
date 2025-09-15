<?php
// /views/admin/rewards/stamp/controllers/adjustment_create.php  
// AJAX endpoint to create customer stamp adjustments
declare(strict_types=1);

require_once __DIR__ . '/../_shared/common.php';

// Set JSON header for responses
header('Content-Type: application/json; charset=utf-8');

if (!$bootstrap_ok) {
    echo json_encode(['ok' => false, 'error' => 'Bootstrap failed: ' . $bootstrap_warning]);
    exit;
}

/* Only allow POST requests */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!($pdo instanceof PDO)) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}

/* Validate required parameters */
$customerId = (int)($_POST['customer_id'] ?? 0);
$programId = (int)($_POST['program_id'] ?? 0);
$adjType = $_POST['adj_type'] ?? '';
$amount = (int)($_POST['amount'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));

/* Validation */
if ($customerId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid customer ID']);
    exit;
}

if ($programId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid program ID']);
    exit;
}

if (!in_array($adjType, ['credit', 'debit'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid adjustment type']);
    exit;
}

if ($amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Amount must be greater than 0']);
    exit;
}

if ($reason === '') {
    echo json_encode(['ok' => false, 'error' => 'Reason is required']);
    exit;
}

try {
    /* Verify customer exists */
    $stmt = $pdo->prepare("SELECT id, name FROM customers WHERE tenant_id = ? AND id = ?");
    $stmt->execute([$tenantId, $customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        echo json_encode(['ok' => false, 'error' => 'Customer not found']);
        exit;
    }
    
    /* Verify program exists */
    $stmt = $pdo->prepare("SELECT id, name, stamps_required FROM loyalty_programs 
                          WHERE tenant_id = ? AND id = ? AND type = 'stamp'");
    $stmt->execute([$tenantId, $programId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$program) {
        echo json_encode(['ok' => false, 'error' => 'Program not found']);
        exit;
    }
    
    /* Get current balance for validation */
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction='redeem' THEN -amount ELSE amount END), 0) as balance
                          FROM loyalty_ledgers
                          WHERE tenant_id = ? AND program_type = 'stamp' 
                            AND program_id = ? AND customer_id = ?");
    $stmt->execute([$tenantId, $programId, $customerId]);
    $currentBalance = (int)($stmt->fetchColumn() ?: 0);
    
    /* Validate debit doesn't create negative balance */
    if ($adjType === 'debit' && ($currentBalance - $amount) < 0) {
        echo json_encode(['ok' => false, 'error' => 'Insufficient balance for debit adjustment']);
        exit;
    }
    
    /* Begin transaction */
    $pdo->beginTransaction();
    
    try {
        /* Insert ledger entry */
        $direction = $adjType === 'credit' ? 'earn' : 'redeem';
        $ledgerAmount = $amount; // Always positive in ledger, direction determines sign
        
        $stmt = $pdo->prepare("INSERT INTO loyalty_ledgers 
                              (tenant_id, program_type, program_id, customer_id, direction, amount, 
                               reason, user_id, created_at)
                              VALUES (?, 'stamp', ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $tenantId, 
            $programId, 
            $customerId, 
            $direction, 
            $ledgerAmount, 
            $reason,
            $userId
        ]);
        
        /* Calculate new balance */
        $newBalance = $adjType === 'credit' 
            ? $currentBalance + $amount 
            : $currentBalance - $amount;
        
        /* Check for auto-redemption if customer now has enough stamps */
        $autoRedemption = null;
        if ($adjType === 'credit' && $newBalance >= (int)$program['stamps_required']) {
            // Customer now has enough stamps for a reward
            $autoRedemption = [
                'eligible' => true,
                'stamps_required' => (int)$program['stamps_required'],
                'current_balance' => $newBalance,
                'possible_redemptions' => floor($newBalance / (int)$program['stamps_required'])
            ];
        }
        
        $pdo->commit();
        
        /* Success response */
        $response = [
            'ok' => true,
            'message' => 'Adjustment saved successfully',
            'customer_id' => $customerId,
            'program_id' => $programId,
            'adjustment_type' => $adjType,
            'amount' => $amount,
            'reason' => $reason,
            'previous_balance' => $currentBalance,
            'balance' => $newBalance,
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        if ($autoRedemption) {
            $response['auto_redemption'] = $autoRedemption;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Throwable $e) {
    error_log('Stamp adjustment error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}