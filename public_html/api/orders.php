<?php
require_once __DIR__ . '/../config/api_guard.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
$pdo = get_pdo();

function json_input(){ $d=json_decode(file_get_contents('php://input'),true); return is_array($d)?$d:[]; }
function receipt(){ return 'R'.date('ymdHis').random_int(100,999); }
function coupon_code(){ return strtoupper(bin2hex(random_bytes(6))); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'POST required']); exit; }

    $in          = json_input();
    $order_type  = $in['order_type'] ?? 'dine_in';
    $guest_count = max(1, (int)($in['guest_count'] ?? 1));
    $customer_id = !empty($in['customer_id']) ? (int)$in['customer_id'] : null;
    $items       = $in['items'] ?? [];
    $redeem_code = trim((string)($in['redeem_coupon_code'] ?? ''));

    if (empty($items)) { echo json_encode(['success'=>false,'error'=>'no items']); exit; }

    // Load product prices
    $ids = array_map(fn($x)=> (int)$x['item_id'], $items);
    $ph  = implode(',', array_fill(0,count($ids),'?'));
    $pstmt = $pdo->prepare("SELECT id, name, price FROM products WHERE id IN ($ph)");
    $pstmt->execute($ids);
    $map = []; while ($r=$pstmt->fetch(PDO::FETCH_ASSOC)){ $map[(int)$r['id']]=$r; }

    $subtotal = 0.0;
    foreach ($items as $it) {
        $pid=(int)$it['item_id']; $qty=max(1,(int)$it['qty']);
        if (!isset($map[$pid])) { echo json_encode(['success'=>false,'error'=>"product $pid missing"]); exit; }
        $price=(float)$map[$pid]['price'];
        $subtotal += $price*$qty;
    }

    // Aggregator commission
    $aggregator_id = null; $commission_percent=0.00; $commission_amount=0.00;
    if (in_array($order_type,['talabat','elmenus','jahez'],true)) {
        $s=$pdo->prepare("SELECT id, commission_percent FROM aggregators WHERE slug=? LIMIT 1");
        $s->execute([$order_type]);
        if ($row=$s->fetch(PDO::FETCH_ASSOC)) {
            $aggregator_id=(int)$row['id'];
            $commission_percent=(float)$row['commission_percent'];
            $commission_amount=round($subtotal*($commission_percent/100),2);
        }
    }

    // Redeem coupon (optional)
    $redeem_amount=0.00; $redeemed_coupon_id=null;
    if ($redeem_code !== '') {
        $cs=$pdo->prepare("SELECT * FROM cashback_coupons WHERE code=? AND is_redeemed=0 LIMIT 1");
        $cs->execute([$redeem_code]);
        $cp=$cs->fetch(PDO::FETCH_ASSOC);
        if (!$cp) { echo json_encode(['success'=>false,'error'=>'invalid coupon']); exit; }
        if (!empty($cp['expires_at']) && strtotime($cp['expires_at']) < time()) {
            echo json_encode(['success'=>false,'error'=>'coupon expired']); exit;
        }
        $redeem_amount=(float)$cp['cashback_amount'];
        $redeemed_coupon_id=(int)$cp['id'];
    }

    $total = max(0, round($subtotal + $commission_amount - $redeem_amount, 2));

    // Transaction
    $pdo->beginTransaction();

    // Insert order
    $rcpt = receipt();
    $ins = $pdo->prepare("INSERT INTO orders (customer_id, order_type, guest_count, receipt_number, aggregator_id, commission_percent, commission_amount, payment_method, status, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'cash', 'completed', NOW())");
    $ins->execute([$customer_id,$order_type,$guest_count,$rcpt,$aggregator_id,$commission_percent,$commission_amount]);
    $order_id = (int)$pdo->lastInsertId();

    // Items
    $oi=$pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price) VALUES (?,?,?,?,?)");
    foreach ($items as $it){
        $pid=(int)$it['item_id']; $qty=max(1,(int)$it['qty']); $price=(float)$map[$pid]['price'];
        $oi->execute([$order_id,$pid,$qty,$price,$price*$qty]);
    }

    // Mark redeemed coupon
    if ($redeemed_coupon_id) {
        $upd=$pdo->prepare("UPDATE cashback_coupons SET is_redeemed=1, redeemed_at=NOW() WHERE id=?");
        $upd->execute([$redeemed_coupon_id]);
    }

    // Loyalty streak & next-visit coupon
    $next_code=null;
    if ($customer_id) {
        $ls=$pdo->prepare("SELECT streak, last_visit_at FROM customer_loyalty WHERE customer_id=? LIMIT 1");
        $ls->execute([$customer_id]);
        $streak=0; $last_at=null;
        if ($row=$ls->fetch(PDO::FETCH_ASSOC)) { $streak=(int)$row['streak']; $last_at=$row['last_visit_at']?strtotime($row['last_visit_at']):null; }
        $now=time();
        if ($last_at && ($now - $last_at) <= 15*24*3600) $streak=min($streak+1,99); else $streak=1;

        $up=$pdo->prepare("INSERT INTO customer_loyalty (customer_id, streak, last_visit_at)
                           VALUES (?, ?, NOW())
                           ON DUPLICATE KEY UPDATE streak=VALUES(streak), last_visit_at=VALUES(last_visit_at)");
        $up->execute([$customer_id,$streak]);

        $pct = ($streak===1?10:($streak===2?15:20));
        $cashback = round($subtotal*($pct/100),2);
        if ($cashback>0){
            $next_code = coupon_code();
            $exp = date('Y-m-d H:i:s', time()+30*24*3600);
            $cp=$pdo->prepare("INSERT INTO cashback_coupons (code, customer_id, order_id, visit_number, bill_amount, cashback_amount, is_redeemed, expires_at)
                               VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            $cp->execute([$next_code,$customer_id,$order_id,$streak,$subtotal,$cashback,$exp]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success'=>true,
        'order_id'=>$order_id,
        'receipt_number'=>$rcpt,
        'subtotal'=>$subtotal,
        'commission_amount'=>$commission_amount,
        'redeem_amount'=>$redeem_amount,
        'total'=>$total,
        'cashback_coupon_code'=>$next_code
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}