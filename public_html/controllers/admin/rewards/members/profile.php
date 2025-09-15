<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

declare(strict_types=1);

/* Bootstrap */
require_once __DIR__ . '/../../../../config/db.php';
use_backend_session();
$db = db();
header('Content-Type: text/html; charset=utf-8');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_exists(PDO $db, string $name): bool {
  try{ $st=$db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t"); $st->execute([':t'=>$name]); return (int)$st->fetchColumn() > 0; }
  catch(Throwable $e){ return false; }
}
function col_exists(PDO $db, string $table, string $col): bool {
  try{ $st=$db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c"); $st->execute([':t'=>$table, ':c'=>$col]); return (int)$st->fetchColumn() > 0; }
  catch(Throwable $e){ return false; }
}

$user = $_SESSION['user'] ?? null;
if (!$user){ http_response_code(403); echo '<div class="helper">Login required.</div>'; exit; }
$tenantId = (int)($user['tenant_id'] ?? 0);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id<=0){ echo '<div class="helper">Invalid member.</div>'; exit; }

$hasOrders = table_exists($db,'orders');
$o_has_closed  = $hasOrders && col_exists($db,'orders','closed_at');
$o_has_channel = $hasOrders && col_exists($db,'orders','sales_channel');

$c_has_class       = col_exists($db,'customers','classification');
$c_has_rewards_en  = col_exists($db,'customers','rewards_enrolled');
$c_has_member_no   = col_exists($db,'customers','rewards_member_no');
$c_has_disc_fk     = col_exists($db,'customers','discount_scheme_id');

$sql = "SELECT c.id, c.name, c.phone, c.email,
        ".($c_has_class ? "c.classification" : "'regular'")." AS classification,
        ".($c_has_rewards_en ? "COALESCE(c.rewards_enrolled,0)" : "0")." AS rewards_enrolled,
        ".($c_has_member_no  ? "c.rewards_member_no" : "NULL")." AS rewards_member_no,
        ".($c_has_disc_fk    ? "c.discount_scheme_id" : "NULL")." AS discount_scheme_id
        FROM customers c
        WHERE c.tenant_id=:t AND c.id=:id
        LIMIT 1";
$st = $db->prepare($sql);
$st->execute([':t'=>$tenantId, ':id'=>$id]);
$member = $st->fetch(PDO::FETCH_ASSOC);
if (!$member){ echo '<div class="helper">Member not found.</div>'; exit; }

$lastOrders = [];
if ($hasOrders){
  $sql2 = "SELECT id, total_amount, ".($o_has_channel?"sales_channel":"NULL AS sales_channel")." AS sales_channel,
           COALESCE(".($o_has_closed?'closed_at':'created_at').", created_at) AS ts
           FROM orders
           WHERE tenant_id=:t AND customer_id=:id
           ORDER BY ts DESC
           LIMIT 5";
  $st2 = $db->prepare($sql2);
  $st2->execute([':t'=>$tenantId, ':id'=>$id]);
  $lastOrders = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* Discount schemes list (optional) */
$schemes = [];
if (table_exists($db,'discount_schemes')){
  $s = $db->prepare("SELECT id, code, name FROM discount_schemes WHERE tenant_id=:t AND is_active=1 ORDER BY name ASC");
  $s->execute([':t'=>$tenantId]);
  $schemes = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$class = ucfirst($member['classification'] ?? 'regular');
$enrolled = (int)$member['rewards_enrolled']===1;
$en_text  = $enrolled ? 'Enrolled' : 'Not Enrolled';
$memno    = $member['rewards_member_no'] ? ' · #'.h($member['rewards_member_no']) : '';
$discSel  = $member['discount_scheme_id'] ?? null;

?>
<div>
  <h3 style="margin:4px 0 10px 0;font-size:18px;font-weight:800;"><?= h($member['name'] ?: ('ID #'.$member['id'])) ?></h3>

  <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
    <span style="background:#14b8a620;color:#0f766e;border:1px solid #14b8a640;padding:6px 10px;border-radius:999px;font-weight:700;">Classification: <?= h($class) ?></span>
    <span style="background:#10b98120;color:#065f46;border:1px solid #10b98140;padding:6px 10px;border-radius:999px;font-weight:700;">Rewards: <?= h($en_text) ?><?= $memno ?></span>
    <button class="btn small" data-rewards-toggle="<?= $enrolled ? 'unenroll' : 'enroll' ?>" data-member-id="<?= (int)$member['id'] ?>">
      <?= $enrolled ? 'Unenroll' : 'Enroll' ?> Rewards
    </button>
  </div>

  <div style="margin:8px 0;">
    <div style="font-weight:700">Contact</div>
    <div style="color:#374151">Mobile: <?= h($member['phone'] ?: '—') ?> · Email: <?= h($member['email'] ?: '—') ?></div>
  </div>

  <div style="margin:12px 0;">
    <div style="font-weight:700">Recent Orders</div>
     if (!$lastOrders): ?>
      <div class="helper">No orders yet.</div>
     else: ?>
      <ul style="list-style:none;padding:0;margin:8px 0;display:flex;flex-direction:column;gap:6px;">
         foreach($lastOrders as $o): ?>
          <li style="padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;">
            <strong>#<?= (int)$o['id'] ?></strong>
            · <?= h(date('Y-m-d H:i', strtotime((string)$o['ts']))) ?>
            · <?= h(ucfirst((string)($o['sales_channel'] ?? ''))) ?: '—' ?>
             if (isset($o['total_amount'])): ?> · <?= number_format((float)$o['total_amount'],2,'.','') ?> endif; ?>
          </li>
         endforeach; ?>
      </ul>
     endif; ?>
  </div>

  <div style="margin:12px 0;">
    <div style="font-weight:700;margin-bottom:6px;">General Discount</div>
     if (!$schemes): ?>
      <div class="helper">No active discount schemes yet.</div>
     else: ?>
      <div style="display:flex;gap:8px;align-items:center;">
        <select id="discountScheme" style="padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;">
          <option value="">— None —</option>
           foreach($schemes as $sc): ?>
            <option value="<?= (int)$sc['id'] ?>" <?= ((string)$discSel === (string)$sc['id'])?'selected':'' ?>>
              <?= h($sc['name'].' ('.$sc['code'].')') ?>
            </option>
           endforeach; ?>
        </select>
        <button class="btn small" data-discount-save data-member-id="<?= (int)$member['id'] ?>">Save</button>
      </div>
     endif; ?>
  </div>

  <div style="display:flex;gap:8px;margin-top:14px;">
    <a class="btn small" href="/views/admin/rewards/members/edit.php?id=<?= (int)$member['id'] ?>">Edit</a>
    <a class="btn small" href="/views/admin/rewards/common/members.php?mq=<?= urlencode((string)$member['id']) ?>">Open in list</a>
  </div>
</div>