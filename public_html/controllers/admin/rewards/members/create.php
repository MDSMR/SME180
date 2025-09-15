<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

declare(strict_types=1);
@ini_set('display_errors','0');

require_once __DIR__ . '/../../../../config/db.php';
use_backend_session();
$db = db();

function back_to_members(){ header('Location: /views/admin/rewards/common/members.php'); exit; }

$user = $_SESSION['user'] ?? null;
if (!$user) back_to_members();
$tenantId=(int)$user['tenant_id'];

$name  = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone']?? ''));
$email = trim((string)($_POST['email']?? ''));
$tier  = trim((string)($_POST['tier'] ?? 'Bronze'));

if ($name==='' || $phone===''){ back_to_members(); }

try{
  $hasStatus = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='status'")->fetchColumn()>0;
  $hasActive = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='is_active'")->fetchColumn()>0;
  $hasTier   = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='tier'")->fetchColumn()>0;

  $cols = "tenant_id,name,phone,email,created_at";
  $vals = ":t,:n,:p,:e,NOW()";
  if ($hasStatus){ $cols .= ",status";   $vals .= ",'active'"; }
  if ($hasActive){ $cols .= ",is_active";$vals .= ",1"; }
  if ($hasTier){   $cols .= ",tier";     $vals .= ",:tier"; }

  $sql="INSERT INTO customers ($cols) VALUES ($vals)";
  $st=$db->prepare($sql);
  $st->execute([':t'=>$tenantId, ':n'=>$name, ':p'=>$phone, ':e'=>$email, ':tier'=>$tier]);

} catch(Throwable $e){ /* swallow; keep UX smooth */ }

back_to_members();