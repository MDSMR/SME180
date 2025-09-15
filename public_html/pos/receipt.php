<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/pos_auth.php';
pos_auth_require_login();

$id=(int)($_GET['id'] ?? 0);
if($id<=0){ http_response_code(400); exit('Missing order id'); }

$pdo=db();
$st=$pdo->prepare("SELECT id,tenant_id,total_amount,created_at FROM orders WHERE id=:id LIMIT 1");
$st->execute([':id'=>$id]);
$o=$st->fetch(PDO::FETCH_ASSOC);
if(!$o){ http_response_code(404); exit('Order not found'); }

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html><meta charset="utf-8"><title>Receipt #<?= (int)$o['id'] ?></title>
<style>body{font-family:system-ui;margin:20px}h1{margin:0 0 8px}</style>
<h1>Receipt #<?= (int)$o['id'] ?></h1>
<p>Date: <?= htmlspecialchars((string)$o['created_at']) ?></p>
<p>Total: <?= number_format((float)$o['total_amount'],2) ?></p>
<p>Thank you!</p>