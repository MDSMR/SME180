<?php
// /mohamedk10.sg-host.com/public_html/views/admin/orders/view.php
// Read-only order view
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/middleware/auth_login.php';
require_once dirname(__DIR__, 3) . '/controllers/admin/orders/_helpers.php';

auth_require_login();
use_backend_session();

$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)($user['tenant_id'] ?? 0);

// Helper
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fix2($n){ return number_format((float)$n, 2, '.', ''); }
function dt($v){ return $v ? date('Y-m-d H:i', strtotime((string)$v)) : '—'; }

// Get order ID
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['flash'] = 'Order not specified.';
    header('Location: /views/admin/orders/index.php');
    exit;
}

// Load order
$order = null;
$items = [];
$discounts = [];
$db_msg = '';

try {
    $pdo = db();
    
    // Check access
    if (!ensure_tenant_access($pdo, $id, $tenantId)) {
        $_SESSION['flash'] = 'Order not found or access denied.';
        header('Location: /views/admin/orders/index.php');
        exit;
    }
    
    // Load order with related data
    $sql = "
        SELECT 
            o.*,
            b.name AS branch_name,
            a.name AS aggregator_name,
            dt.table_number,
            c.name AS customer_name_full,
            c.phone AS customer_phone,
            u.name AS created_by_name,
            vu.name AS voided_by_name,
            du.name AS deleted_by_name
        FROM orders o
        LEFT JOIN branches b ON b.id = o.branch_id
        LEFT JOIN aggregators a ON a.id = o.aggregator_id
        LEFT JOIN dining_tables dt ON dt.id = o.table_id
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN users u ON u.id = o.created_by_user_id
        LEFT JOIN users vu ON vu.id = o.voided_by_user_id
        LEFT JOIN users du ON du.id = o.deleted_by
        WHERE o.id = :id
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $_SESSION['flash'] = 'Order not found.';
        header('Location: /views/admin/orders/index.php');
        exit;
    }
    
    // Load order items
    $stmt = $pdo->prepare("
        SELECT oi.*, p.name_en AS product_name_en, p.name_ar AS product_name_ar
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = :id
        ORDER BY oi.id
    ");
    $stmt->execute([':id' => $id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Load item variations
    foreach ($items as &$item) {
        $stmt = $pdo->prepare("
            SELECT * FROM order_item_variations 
            WHERE order_item_id = :id
            ORDER BY id
        ");
        $stmt->execute([':id' => $item['id']]);
        $item['variations'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    // Load discounts
    $stmt = $pdo->prepare("
        SELECT od.*, dr.name AS rule_name, pc.code AS promo_code
        FROM order_discounts od
        LEFT JOIN discount_rules dr ON dr.id = od.discount_rule_id
        LEFT JOIN promo_codes pc ON pc.id = od.promo_code_id
        WHERE od.order_id = :id
        ORDER BY od.id
    ");
    $stmt->execute([':id' => $id]);
    $discounts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
} catch (Throwable $e) {
    $db_msg = $e->getMessage();
}

// Calculate derived amounts
$sub = (float)($order['subtotal_amount'] ?? 0);
$dis = (float)($order['discount_amount'] ?? 0);
$taxp = (float)($order['tax_percent'] ?? 0);
$servp = (float)($order['service_percent'] ?? 0);
$tax = (float)($order['tax_amount'] ?? 0);
$serv = (float)($order['service_amount'] ?? 0);
$comm = (float)($order['commission_total_amount'] ?? 0);
$tot = (float)($order['total_amount'] ?? 0);

// Get available status transitions
$availableTransitions = get_available_transitions($order['status']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$active = 'orders';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>View Order · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#f7f8fa; --card:#fff; --text:#111827; --muted:#6b7280; --primary:#2563eb; --border:#e5e7eb;
  --subtle:#f3f4f6; --ok:#059669; --warn:#d97706; --off:#991b1b;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto;color:var(--text)}
.container{max-width:1200px;margin:20px auto;padding:0 16px}
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
.h2{font-size:14px;font-weight:800;margin:8px 0 12px;color:#111827}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:900px){.grid{grid-template-columns:1fr}}
label{font-size:12px;color:var(--muted);display:block;margin-bottom:6px}
.value{border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:#fff;min-height:42px;display:flex;align-items:center}
.row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:10px 14px;cursor:pointer;text-decoration:none;color:#111827;line-height:1.1}
.btn:hover{filter:brightness(.98)}
.btn-primary{background:var(--primary);color:#fff;border-color:#2563eb}
.btn-danger{background:#dc2626;color:#fff;border-color:#dc2626}
.btn-warning{background:#f59e0b;color:#fff;border-color:#f59e0b}
.btn-sm{padding:6px 10px;font-size:12px}
.badge{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:1px 6px;font-size:11px;background:#f3f4f6;line-height:1.3}
.badge.ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.badge.warn{background:#fff7ed;border-color:#ffedd5;color:#7c2d12}
.badge.off{background:#fee2e2;border-color:#fecaca;color:#7f1d1d}
.small{color:var(--muted);font-size:12px}
.totals{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
@media (max-width:900px){.totals{grid-template-columns:1fr 1fr}}
.tot-cell{border:1px dashed var(--border);border-radius:10px;padding:10px;background:#fff}
.tot-cell strong{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
.tot-val{font-weight:800}
.kv{display:grid;grid-template-columns:180px 1fr;gap:8px;margin:6px 0}
.kv .k{color:var(--muted)}
.kv .v{font-weight:600}
.flash{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:10px;margin:10px 0}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
.table{width:100%;border-collapse:collapse}
.table th{font-size:12px;text-align:left;color:var(--muted);padding:8px;border-bottom:1px solid var(--border)}
.table td{padding:8px;border-bottom:1px solid var(--subtle)}
.actions-group{display:flex;gap:8px;flex-wrap:wrap}
.deleted-warning{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d;padding:10px;border-radius:10px;margin:10px 0}
</style>
</head>
<body>

<?php require __DIR__ . '/../../partials/admin_nav.php'; ?>

<div class="container">
  <?php if($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if($db_msg): ?><div class="small">DEBUG: <?= h($db_msg) ?></div><?php endif; ?>
  
  <?php if($order['is_deleted']): ?>
    <div class="deleted-warning">
      ⚠️ This order was deleted on <?= h(dt($order['deleted_at'])) ?> 
      <?php if($order['deleted_by_name']): ?>by <?= h($order['deleted_by_name']) ?><?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="section">
    <div class="row">
      <div class="h1">
        Order #<?= (int)$order['id'] ?>
        <?php
          $st = $order['status'];
          $cls = $st==='closed' ? 'ok' : ($st==='cancelled' || $st==='voided' ? 'off' : 'warn');
          echo ' <span class="badge '.$cls.'">'.h(ucfirst($st)).'</span>';
          
          $ps = $order['payment_status'];
          $pcls = $ps==='paid' ? 'ok' : ($ps==='voided' ? 'off' : 'warn');
          echo ' <span class="badge '.$pcls.'">'.h(ucfirst($ps)).'</span>';
        ?>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <span class="badge">Channel: <?= h($order['source_channel'] ?? 'pos') ?></span>
        <a class="btn btn-sm" href="/views/admin/orders/index.php">Back</a>
        <?php if(can_modify_order($order)): ?>
          <a class="btn btn-primary btn-sm" href="/views/admin/orders/edit.php?id=<?= (int)$order['id'] ?>">Edit</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="kv"><div class="k">Created</div><div class="v"><?= h(dt($order['created_at'])) ?> by <?= h($order['created_by_name'] ?? 'System') ?></div></div>
    <div class="kv"><div class="k">Updated</div><div class="v"><?= h(dt($order['updated_at'])) ?></div></div>
    <?php if($order['closed_at']): ?>
      <div class="kv"><div class="k">Closed</div><div class="v"><?= h(dt($order['closed_at'])) ?></div></div>
    <?php endif; ?>
    <?php if($order['voided_at']): ?>
      <div class="kv"><div class="k">Voided</div><div class="v"><?= h(dt($order['voided_at'])) ?> by <?= h($order['voided_by_name'] ?? 'System') ?></div></div>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="h2">Order Details</div>
    <div class="grid">
      <div><label>Branch</label><div class="value"><?= h($order['branch_name'] ?? '—') ?></div></div>
      <div><label>Order Type</label><div class="value">
        <?php
          $map = ['dine_in'=>'Dine in','takeaway'=>'Take Away','delivery'=>'Delivery'];
          echo h($map[$order['order_type'] ?? 'dine_in'] ?? 'Dine in');
        ?>
      </div></div>
    </div>
    <?php if($order['order_type'] === 'dine_in' && $order['table_id']): ?>
      <div class="grid">
        <div><label>Table</label><div class="value"><?= h($order['table_number'] ?? 'Table #'.$order['table_id']) ?></div></div>
        <div><label>Guest Count</label><div class="value"><?= (int)($order['guest_count'] ?? 0) ?></div></div>
      </div>
    <?php endif; ?>
    <?php if($order['order_type'] === 'delivery'): ?>
      <div class="grid">
        <div><label>Aggregator</label><div class="value"><?= h($order['aggregator_name'] ?? '—') ?></div></div>
        <div><label>External Reference</label><div class="value"><?= h($order['external_order_reference'] ?? '—') ?></div></div>
      </div>
    <?php endif; ?>
    <div class="grid">
      <div><label>Customer</label><div class="value">
        <?= h($order['customer_name'] ?? $order['customer_name_full'] ?? '—') ?>
        <?php if($order['customer_phone']): ?><br><small><?= h($order['customer_phone']) ?></small><?php endif; ?>
      </div></div>
      <div><label>Receipt Reference</label><div class="value"><?= h($order['receipt_reference'] ?? '—') ?></div></div>
    </div>
    <?php if($order['order_notes']): ?>
      <div><label>Notes</label><div class="value"><?= h($order['order_notes']) ?></div></div>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="h2">Items (<?= count($items) ?>)</div>
    <?php if(empty($items)): ?>
      <p class="small">No items in this order.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Product</th>
            <th>Variations</th>
            <th style="width:80px">Qty</th>
            <th style="width:100px">Unit Price</th>
            <th style="width:100px">Total</th>
            <th style="width:80px">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($items as $item): ?>
            <tr>
              <td><strong><?= h($item['product_name'] ?? $item['product_name_en'] ?? 'Product #'.$item['product_id']) ?></strong></td>
              <td>
                <?php if(!empty($item['variations'])): ?>
                  <?php foreach($item['variations'] as $v): ?>
                    <small><?= h($v['variation_group']) ?>: <?= h($v['variation_value']) ?>
                    <?php if($v['price_delta'] > 0): ?>(+<?= format_money($v['price_delta']) ?>)<?php endif; ?>
                    </small><br>
                  <?php endforeach; ?>
                <?php else: ?>
                  <small class="small">—</small>
                <?php endif; ?>
              </td>
              <td><?= (int)$item['quantity'] ?></td>
              <td><?= format_money($item['unit_price']) ?></td>
              <td><strong><?= format_money($item['line_subtotal']) ?></strong></td>
              <td>
                <?php
                  $state = $item['state'] ?? 'held';
                  $scls = ($state === 'ready' || $state === 'fired') ? 'ok' : 
                         ($state === 'voided' ? 'off' : 'warn');
                  echo '<span class="badge '.$scls.'">'.h(ucfirst($state)).'</span>';
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="h2">Amounts</div>
    <div class="totals">
      <div class="tot-cell"><strong>Subtotal</strong><span class="tot-val"><?= h(fix2($sub)) ?></span></div>
      <div class="tot-cell"><strong>− Discount</strong><span class="tot-val"><?= h(fix2($dis)) ?></span></div>
      <div class="tot-cell"><strong>+ Tax (<?= h(fix2($taxp)) ?>%)</strong><span class="tot-val"><?= h(fix2($tax)) ?></span></div>
      <div class="tot-cell"><strong>+ Service (<?= h(fix2($servp)) ?>%)</strong><span class="tot-val"><?= h(fix2($serv)) ?></span></div>
      <div class="tot-cell"><strong>+ Commission</strong><span class="tot-val"><?= h(fix2($comm)) ?></span></div>
    </div>
    <div class="kv" style="margin-top:10px">
      <div class="k">Total Amount</div>
      <div class="v" style="font-size:18px"><?= h(fix2($tot)) ?> <?= h(get_setting($pdo, $tenantId, 'currency', 'EGP')) ?></div>
    </div>
    
    <?php if(!empty($discounts)): ?>
      <div style="margin-top:20px">
        <strong>Applied Discounts:</strong>
        <ul style="margin:5px 0">
          <?php foreach($discounts as $d): ?>
            <li>
              <?= h($d['rule_name'] ?? 'Discount #'.$d['discount_rule_id']) ?>
              <?php if($d['promo_code']): ?>(Code: <?= h($d['promo_code']) ?>)<?php endif; ?>
              - <?= format_money($d['amount_applied']) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="h2">Payment Information</div>
    <div class="grid">
      <div><label>Payment Status</label><div class="value"><?= h(ucfirst($order['payment_status'] ?? 'unpaid')) ?></div></div>
      <div><label>Payment Method</label><div class="value"><?= h(ucfirst($order['payment_method'] ?? '—')) ?></div></div>
    </div>
    <?php if($order['session_id']): ?>
      <div class="grid">
        <div><label>POS Session</label><div class="value"><?= h($order['session_id']) ?></div></div>
        <div></div>
      </div>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="h2">Actions</div>
    <div class="actions-group">
      
      <?php if(can_modify_order($order)): ?>
        <!-- Status changes -->
        <?php foreach($availableTransitions as $newStatus): ?>
          <?php
            $btnClass = 'btn btn-sm';
            if(in_array($newStatus, ['closed', 'ready', 'served'])) $btnClass .= ' btn-primary';
            elseif(in_array($newStatus, ['cancelled', 'voided'])) $btnClass .= ' btn-danger';
          ?>
          <a class="<?= $btnClass ?>" 
             href="/controllers/admin/orders/order_status.php?id=<?= $id ?>&status=<?= $newStatus ?>&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
             onclick="return confirm('Change status to <?= ucfirst($newStatus) ?>?')">
            Mark <?= ucfirst($newStatus) ?>
          </a>
        <?php endforeach; ?>
        
        <!-- Recalculate -->
        <a class="btn btn-sm" href="/controllers/admin/orders/order_recalculate.php?id=<?= $id ?>&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>">
          Recalculate Totals
        </a>
        
        <!-- Edit -->
        <a class="btn btn-sm" href="/views/admin/orders/edit.php?id=<?= $id ?>">Edit Order</a>
      <?php endif; ?>
      
      <!-- Export -->
      <a class="btn btn-sm" href="/controllers/admin/orders/order_export.php?<?= http_build_query(['q' => $id, 'format' => 'csv']) ?>">
        Export CSV
      </a>
      
      <!-- Refund/Void (for paid/closed orders) -->
      <?php if($order['payment_status'] === 'paid' && in_array($order['status'], ['closed', 'served'])): ?>
        <a class="btn btn-sm btn-warning" 
           href="/controllers/admin/orders/order_refund.php?id=<?= $id ?>&mode=refund&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
           onclick="return confirm('Refund this order? This will revoke any rewards earned.')">
          Refund
        </a>
      <?php endif; ?>
      
      <?php if(!in_array($order['status'], ['voided', 'refunded']) && $order['payment_status'] !== 'paid'): ?>
        <a class="btn btn-sm btn-danger" 
           href="/controllers/admin/orders/order_refund.php?id=<?= $id ?>&mode=void&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
           onclick="return confirm('Void this order?')">
          Void
        </a>
      <?php endif; ?>
      
      <!-- Delete (soft delete) -->
      <?php if(!$order['is_deleted'] && !in_array($order['status'], ['closed', 'served']) && $order['payment_status'] !== 'paid'): ?>
        <a class="btn btn-sm btn-danger" 
           href="/controllers/admin/orders/order_delete.php?id=<?= $id ?>&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
           onclick="return confirm('Delete this order? This action cannot be undone.')">
          Delete
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>