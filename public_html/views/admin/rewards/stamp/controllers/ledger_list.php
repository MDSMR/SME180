<?php
// /views/admin/rewards/stamp/controllers/ledger_list.php
// AJAX endpoint to load customer ledger for slide-over panel
declare(strict_types=1);

require_once __DIR__ . '/../_shared/common.php';

// Set JSON header for error responses
header('Content-Type: text/html; charset=utf-8');

if (!$bootstrap_ok) {
    echo '<div class="notice alert-error">Bootstrap failed: ' . h($bootstrap_warning) . '</div>';
    exit;
}

/* Validate parameters */
$customerId = (int)($_GET['customer_id'] ?? 0);
$programId = (int)($_GET['program_id'] ?? 0);

if ($customerId <= 0) {
    echo '<div class="notice alert-error">Invalid customer ID.</div>';
    exit;
}

if ($programId <= 0) {
    echo '<div class="notice alert-error">Invalid program ID.</div>';
    exit;
}

if (!($pdo instanceof PDO)) {
    echo '<div class="notice alert-error">Database connection failed.</div>';
    exit;
}

/* Load customer info */
$customer = null;
try {
    $stmt = $pdo->prepare("SELECT id, name, phone FROM customers WHERE tenant_id = ? AND id = ?");
    $stmt->execute([$tenantId, $customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo '<div class="notice alert-error">Error loading customer: ' . h($e->getMessage()) . '</div>';
    exit;
}

if (!$customer) {
    echo '<div class="notice alert-error">Customer not found.</div>';
    exit;
}

/* Load program info */
$program = null;
try {
    $stmt = $pdo->prepare("SELECT id, name, stamps_required FROM loyalty_programs 
                          WHERE tenant_id = ? AND id = ? AND type = 'stamp'");
    $stmt->execute([$tenantId, $programId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo '<div class="notice alert-error">Error loading program: ' . h($e->getMessage()) . '</div>';
    exit;
}

if (!$program) {
    echo '<div class="notice alert-error">Program not found.</div>';
    exit;
}

/* Load ledger entries */
$entries = [];
$currentBalance = 0;
try {
    // Get current balance
    $stmt = $pdo->prepare("SELECT SUM(CASE WHEN direction='redeem' THEN -amount ELSE amount END) as balance
                          FROM loyalty_ledgers
                          WHERE tenant_id = ? AND program_type = 'stamp' 
                            AND program_id = ? AND customer_id = ?");
    $stmt->execute([$tenantId, $programId, $customerId]);
    $balanceRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentBalance = (int)($balanceRow['balance'] ?? 0);
    
    // Get recent entries
    $stmt = $pdo->prepare("SELECT ll.id, ll.created_at, ll.direction, ll.amount, ll.order_id, 
                                  ll.user_id, ll.reason,
                                  u.name as user_name
                          FROM loyalty_ledgers ll
                          LEFT JOIN users u ON u.id = ll.user_id AND u.tenant_id = ll.tenant_id
                          WHERE ll.tenant_id = ? AND ll.program_type = 'stamp' 
                            AND ll.program_id = ? AND ll.customer_id = ?
                          ORDER BY ll.created_at DESC, ll.id DESC
                          LIMIT 25");
    $stmt->execute([$tenantId, $programId, $customerId]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo '<div class="notice alert-error">Error loading ledger: ' . h($e->getMessage()) . '</div>';
    exit;
}

/* Helper function to format direction */
function formatDirection(string $direction): string {
    return $direction === 'redeem' 
        ? '<span class="badge warn">Redeem</span>' 
        : '<span class="badge good">Earn</span>';
}

/* Helper function to format amount */
function formatAmount(string $direction, int $amount): string {
    $sign = $direction === 'redeem' ? '-' : '+';
    $class = $direction === 'redeem' ? 'color: var(--ms-red)' : 'color: var(--ms-green)';
    return "<span style=\"{$class}\">{$sign}{$amount}</span>";
}
?>

<div style="margin-bottom: 16px; padding: 12px; background: var(--ms-blue-lighter); border-radius: var(--ms-radius);">
  <div style="font-weight: 600; margin-bottom: 4px;">
    <?= h($customer['name'] ?: 'Customer #' . $customer['id']) ?>
  </div>
  <div style="font-size: 12px; color: var(--ms-gray-130);">
    Phone: <?= h($customer['phone'] ?: '—') ?> | 
    Current Balance: <strong><?= $currentBalance ?> stamps</strong> |
    Need: <?= max(0, (int)$program['stamps_required'] - $currentBalance) ?> more for reward
  </div>
</div>

<?php if (empty($entries)): ?>
  <div class="notice">No stamp activity found for this customer in this program.</div>
<?php else: ?>
  <div style="max-height: 400px; overflow-y: auto;">
    <table class="table" style="font-size: 12px;">
      <thead>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th style="text-align: right;">Amount</th>
          <th>Source</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $entry): ?>
          <tr>
            <td>
              <div><?= h(date('M j, Y', strtotime($entry['created_at']))) ?></div>
              <div style="font-size: 10px; color: var(--ms-gray-130);">
                <?= h(date('g:i A', strtotime($entry['created_at']))) ?>
              </div>
            </td>
            <td><?= formatDirection($entry['direction']) ?></td>
            <td style="text-align: right; font-weight: 600;">
              <?= formatAmount($entry['direction'], (int)$entry['amount']) ?>
            </td>
            <td>
              <?php if ($entry['order_id']): ?>
                <div>Order #<?= (int)$entry['order_id'] ?></div>
              <?php elseif ($entry['reason']): ?>
                <div>Adjustment</div>
                <div style="font-size: 10px; color: var(--ms-gray-130);">
                  <?= h($entry['reason']) ?>
                </div>
              <?php else: ?>
                <div>System</div>
              <?php endif; ?>
              
              <?php if ($entry['user_name']): ?>
                <div style="font-size: 10px; color: var(--ms-gray-130);">
                  by <?= h($entry['user_name']) ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  
  <div style="margin-top: 16px; padding: 12px; background: var(--ms-gray-10); border-radius: var(--ms-radius); text-align: center;">
    <div style="font-size: 12px; color: var(--ms-gray-130);">
      Showing last <?= count($entries) ?> entries
    </div>
  </div>
<?php endif; ?>