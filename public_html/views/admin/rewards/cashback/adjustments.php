<?php
// File: /public_html/views/admin/rewards/cashback/adjustments.php
// COMPLETE FIXED VERSION - Cashback Adjustments Page
declare(strict_types=1);

/* Bootstrap */
$bootstrap_warning = ''; 
$bootstrap_ok = false;
$bootstrap_path = dirname(__DIR__, 4) . '/config/db.php';

if (!is_file($bootstrap_path)) { 
    $bootstrap_warning = 'Configuration file not found: /config/db.php'; 
} else {
    $prev = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
    try {
        require_once $bootstrap_path;
        if (function_exists('db') && function_exists('use_backend_session')) { 
            $bootstrap_ok = true; 
            use_backend_session(); 
        } else { 
            $bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).'; 
        }
    } catch (Throwable $e) { 
        $bootstrap_warning = 'Bootstrap error: '.$e->getMessage(); 
    } finally { 
        if ($prev) set_error_handler($prev); 
    }
}

if (!$bootstrap_ok) { 
    echo "<h1>Cashback — Adjustments</h1><div style='color:red;'>".htmlspecialchars($bootstrap_warning)."</div>"; 
    exit; 
}

/* Auth */
$user = $_SESSION['user'] ?? null; 
if(!$user){ 
    header('Location:/views/auth/login.php'); 
    exit; 
}
$tenantId = (int)$user['tenant_id']; 
$userId = (int)$user['id'];

/* DB */
try { 
    $db = db(); 
} catch (Throwable $e) { 
    http_response_code(500); 
    echo 'DB error'; 
    exit; 
}

/* Helpers */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* CSRF Token */
if (empty($_SESSION['csrf_rewards_cashback'])) {
    $_SESSION['csrf_rewards_cashback'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_rewards_cashback'];

/* Handle POST adjustments */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        // Verify CSRF
        if (($_POST['csrf'] ?? '') !== $csrf) {
            throw new Exception('Invalid CSRF token');
        }
        
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $direction = $_POST['direction'] ?? 'credit';
        $amount = (float)($_POST['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($customerId <= 0) {
            throw new Exception('Invalid customer ID');
        }
        
        if ($amount <= 0) {
            throw new Exception('Amount must be greater than 0');
        }
        
        if (empty($reason)) {
            throw new Exception('Reason is required');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Get current customer balance
            $stmt = $db->prepare("
                SELECT points_balance 
                FROM customers 
                WHERE id = :id AND tenant_id = :t 
                FOR UPDATE
            ");
            $stmt->execute([':id' => $customerId, ':t' => $tenantId]);
            $currentBalance = (float)($stmt->fetchColumn() ?: 0);
            
            // Calculate new balance and ledger amount
            if ($direction === 'credit') {
                $newBalance = $currentBalance + $amount;
                $ledgerAmount = $amount;
            } else {
                if ($amount > $currentBalance) {
                    throw new Exception('Insufficient balance for debit');
                }
                $newBalance = $currentBalance - $amount;
                $ledgerAmount = -$amount;
            }
            
            // Update customer balance
            $stmt = $db->prepare("
                UPDATE customers 
                SET points_balance = :balance, updated_at = NOW()
                WHERE id = :id AND tenant_id = :t
            ");
            $stmt->execute([
                ':balance' => $newBalance,
                ':id' => $customerId,
                ':t' => $tenantId
            ]);
            
            // Insert into loyalty_ledger
            $fullReason = $reason;
            if ($notes) {
                $fullReason .= ' - ' . $notes;
            }
            
            $stmt = $db->prepare("
                INSERT INTO loyalty_ledger 
                (tenant_id, customer_id, type, points_delta, reason, user_id, created_at)
                VALUES (:t, :cid, 'adjust', :delta, :reason, :uid, NOW())
            ");
            $stmt->execute([
                ':t' => $tenantId,
                ':cid' => $customerId,
                ':delta' => $ledgerAmount,
                ':reason' => $fullReason,
                ':uid' => $userId
            ]);
            
            // Update loyalty_accounts if exists
            $stmt = $db->prepare("
                INSERT INTO loyalty_accounts 
                (tenant_id, customer_id, points_balance, lifetime_points, last_activity_at, created_at, updated_at)
                VALUES (:t, :cid, :balance, :lifetime, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    points_balance = :balance,
                    lifetime_points = CASE 
                        WHEN :is_credit = 1 THEN lifetime_points + :amt
                        ELSE lifetime_points
                    END,
                    last_activity_at = NOW(),
                    updated_at = NOW()
            ");
            $stmt->execute([
                ':t' => $tenantId,
                ':cid' => $customerId,
                ':balance' => $newBalance,
                ':lifetime' => $direction === 'credit' ? $amount : 0,
                ':is_credit' => $direction === 'credit' ? 1 : 0,
                ':amt' => $amount
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => $direction === 'credit' 
                    ? "Credited $amount points successfully" 
                    : "Debited $amount points successfully"
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

/* Recent adjustments list */
$recent = [];
try {
    $sql = "
        SELECT l.id, l.created_at, l.points_delta as cash_delta, l.reason,
               c.id AS customer_id, c.name AS customer_name,
               u.id AS user_id, u.name AS user_name
        FROM loyalty_ledger l
        LEFT JOIN customers c ON c.id = l.customer_id AND c.tenant_id = l.tenant_id
        LEFT JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
        WHERE l.tenant_id = :t AND l.type = 'adjust'
        ORDER BY l.id DESC
        LIMIT 50
    ";
    $st = $db->prepare($sql);
    $st->execute([':t'=>$tenantId]);
    $recent = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $recent = [];
}

$page_title = "Rewards · Cashback · Adjustments";
include dirname(__DIR__, 3) . '/partials/admin_header.php';

function cashback_tabs(string $active): void { 
    $b='/views/admin/rewards/cashback';
    $t=[
        'overview'=>['Overview',"$b/overview.php"],
        'rules'=>['Rules',"$b/rules.php"],
        'ledger'=>['Ledger',"$b/ledger.php"],
        'wallets'=>['Wallets',"$b/wallets.php"],
        'adjust'=>['Adjustments',"$b/adjustments.php"],
        'reports'=>['Reports',"$b/reports.php"]
    ];
    echo '<ul class="nav nav-tabs mb-3">'; 
    foreach($t as $k=>[$l,$h]){ 
        $a=$k===$active?'active':''; 
        echo "<li class='nav-item'><a class='nav-link $a' href='$h'>$l</a></li>"; 
    } 
    echo '</ul>';
}
?>
<div class="container mt-4">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="/views/admin/rewards/index.php">Rewards</a></li>
      <li class="breadcrumb-item"><a href="/views/admin/rewards/cashback/overview.php">Cashback</a></li>
      <li class="breadcrumb-item active" aria-current="page">Adjustments</li>
    </ol>
  </nav>

  <h1 class="mb-2">Cashback · Adjustments</h1>
  <p class="text-muted">Manual wallet adjustments with audit trail.</p>

  <?php cashback_tabs('adjust'); ?>

  <div class="row">
    <div class="col-lg-5">
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h5 class="card-title">New Adjustment</h5>
          <form id="adjustmentForm" class="row g-3">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="create_adjustment">
            
            <div class="col-12">
              <label class="form-label">Member ID</label>
              <input type="number" name="customer_id" class="form-control" placeholder="Enter member ID" required>
            </div>
            
            <div class="col-md-6">
              <label class="form-label">Type</label>
              <select name="direction" class="form-select">
                <option value="credit">Credit (+)</option>
                <option value="debit">Debit (−)</option>
              </select>
            </div>
            
            <div class="col-md-6">
              <label class="form-label">Amount</label>
              <input type="number" step="0.01" name="amount" class="form-control" min="0.01" required>
            </div>
            
            <div class="col-12">
              <label class="form-label">Reason</label>
              <input type="text" name="reason" class="form-control" required 
                     placeholder="e.g., Customer service compensation">
            </div>
            
            <div class="col-12">
              <label class="form-label">Notes (optional)</label>
              <textarea name="notes" class="form-control" rows="3" 
                        placeholder="Additional details..."></textarea>
            </div>
            
            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Submit</button>
              <button type="reset" class="btn btn-outline-secondary">Clear</button>
            </div>
          </form>
          <p class="text-muted mt-2 mb-0">
            Note: This posts an <code>adjust</code> entry into <code>loyalty_ledger</code>.
          </p>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Recent Adjustments</h5>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Member</th>
                  <th class="text-end">Amount</th>
                  <th>Reason</th>
                  <th>By</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$recent): ?>
                  <tr><td colspan="5">No adjustments yet.</td></tr>
                <?php else: foreach ($recent as $r): ?>
                  <tr>
                    <td><?= h(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
                    <td><?= h($r['customer_name'] ?: ('ID #'.(int)$r['customer_id'])) ?></td>
                    <td class="text-end">
                      <?php if ($r['cash_delta'] >= 0): ?>
                        <span class="text-success">+<?= number_format(abs((float)$r['cash_delta']), 2) ?></span>
                      <?php else: ?>
                        <span class="text-danger">-<?= number_format(abs((float)$r['cash_delta']), 2) ?></span>
                      <?php endif; ?>
                    </td>
                    <td><?= h($r['reason'] ?? '') ?></td>
                    <td><?= h($r['user_name'] ?: ('#'.(int)$r['user_id'])) ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('adjustmentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Processing...';
    
    fetch('', {
        method: 'POST',
        body: new FormData(form)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            form.reset();
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Network error: ' + error.message);
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    });
});
</script>

<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>