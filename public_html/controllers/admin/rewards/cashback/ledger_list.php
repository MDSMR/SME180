<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// /controllers/admin/rewards/cashback/ledger_list.php
// Returns a small HTML snippet (table) for the slide-over "Ledger" pane
declare(strict_types=1);

/* Bootstrap db + session */
$bootstrap_path = __DIR__ . '/../../../../config/db.php';
if (!is_file($bootstrap_path)) {
  $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  if ($docRoot) { $alt = $docRoot . '/config/db.php'; if (is_file($alt)) $bootstrap_path = $alt; }
}
if (!is_file($bootstrap_path)) { http_response_code(500); echo 'db bootstrap missing'; exit; }

require_once $bootstrap_path; // db(), use_backend_session()
use_backend_session();

$user = $_SESSION['user'] ?? null;
if (!$user) { http_response_code(403); echo 'Auth required'; exit; }
$tenantId = (int)($user['tenant_id'] ?? 0);

$customerId = (int)($_GET['customer_id'] ?? 0);
$programId  = (int)($_GET['program_id'] ?? 0);

if ($customerId <= 0 || $programId <= 0) {
  http_response_code(400); echo '<div class="notice">Missing customer or program.</div>'; exit;
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
  $db = db();
  $sql = "SELECT id, created_at, direction, amount, order_id, user_id, note
          FROM loyalty_ledgers
          WHERE tenant_id = :t AND program_type='cashback'
            AND program_id = :pid AND customer_id = :cid
          ORDER BY id DESC
          LIMIT 60";
  $st = $db->prepare($sql);
  $st->execute([':t'=>$tenantId, ':pid'=>$programId, ':cid'=>$customerId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if (!$rows) {
    echo '<div class="notice">No ledger entries found.</div>';
    exit;
  }

  echo '<table style="width:100%;border-collapse:separate;border-spacing:0 6px">';
  echo '<thead><tr><th>Date/Time</th><th>Direction</th><th style="text-align:right">Amount</th><th>Order</th><th>User</th><th>Note</th></tr></thead>';
  echo '<tbody>';
  foreach ($rows as $r) {
    $amt = number_format((float)$r['amount'], 2, '.', '');
    $dir = (string)($r['direction'] ?? '');
    echo '<tr style="background:#fff;border:1px solid #e5e7eb;border-radius:10px">';
    echo '<td>'.h($r['created_at']).'</td>';
    echo '<td>'.h($dir).'</td>';
    echo '<td style="text-align:right">'.h($amt).'</td>';
    echo '<td>'.($r['order_id'] ? ('#'.(int)$r['order_id']) : '—').'</td>';
    echo '<td>'.($r['user_id'] ? ('#'.(int)$r['user_id']) : '—').'</td>';
    echo '<td>'.h($r['note'] ?? '').'</td>';
    echo '</tr>';
  }
  echo '</tbody></table>';

} catch (Throwable $e) {
  http_response_code(500);
  echo '<div class="notice">Ledger load error: '.h($e->getMessage()).'</div>';
}