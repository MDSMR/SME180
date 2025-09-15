<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

declare(strict_types=1);

@ini_set('display_errors','0');
header_remove('X-Powered-By');

function json_out(int $code, array $payload): void {
  if (!headers_sent()) {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Bootstrap ---------- */
$ok=false;$warn='';
$paths=[__DIR__.'/../../../config/db.php',__DIR__.'/../../config/db.php'];
$cursor=__DIR__;
for($i=0;$i<6;$i++){ $cursor=dirname($cursor); if($cursor===''||$cursor==='/'||$cursor==='\\')break; $maybe=$cursor.'/config/db.php'; if(!in_array($maybe,$paths,true))$paths[]=$maybe; }
foreach($paths as $p){ if(is_file($p)){ try{ require_once $p; if(function_exists('use_backend_session')){$ok=true;break;} }catch(Throwable $e){ $warn='Bootstrap error: '.$e->getMessage(); } } }
if(!$ok) json_out(500,['ok'=>false,'error'=>$warn ?: 'config/db.php not found or invalid']);

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(405,['ok'=>false,'error'=>'Method Not Allowed']);
try{ use_backend_session(); }catch(Throwable $e){ json_out(500,['ok'=>false,'error'=>'Session bootstrap error: '.$e->getMessage()]); }
$user=$_SESSION['user']??null;
if(!$user) json_out(401,['ok'=>false,'error'=>'Not authenticated']);
if(function_exists('csrf_verify')){ try{ csrf_verify(); }catch(Throwable $e){ json_out(419,['ok'=>false,'error'=>'CSRF verification failed']); } }

function tenant_id(): ?int {
  if(function_exists('current_tenant_id')){ try{ $t=current_tenant_id(); if($t!==null) return (int)$t; }catch(Throwable $e){} }
  if(isset($_SESSION['tenant_id'])) return (int)$_SESSION['tenant_id'];
  if(isset($_SESSION['user']['tenant_id'])) return (int)$_SESSION['user']['tenant_id'];
  if(isset($_SESSION['user']['tenant']['id'])) return (int)$_SESSION['user']['tenant']['id'];
  return null;
}
$tid=tenant_id();
if($tid===null) json_out(400,['ok'=>false,'error'=>'Tenant not resolved from session']);

$raw=file_get_contents('php://input') ?: '';
parse_str($raw, $form); // allow form-encoded or JSON
if(isset($form['id'])) { $id=(int)$form['id']; }
else {
  $j = json_decode($raw, true);
  $id = isset($j['id']) ? (int)$j['id'] : 0;
}
if($id<=0) json_out(400,['ok'=>false,'error'=>'Missing or invalid id']);

function get_pdo(): ?PDO {
  if(function_exists('db')){ try{ $pdo=db(); if($pdo instanceof PDO) return $pdo; }catch(Throwable $e){} }
  if(function_exists('pdo')){ try{ $pdo=pdo(); if($pdo instanceof PDO) return $pdo; }catch(Throwable $e){} }
  if(!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  return null;
}
$pdo=get_pdo();
if(!($pdo instanceof PDO)) json_out(500,['ok'=>false,'error'=>'No database connection']);

try{
  $driver=strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
  $sql = $driver==='pgsql'
    ? 'DELETE FROM branches WHERE "id"=:id AND "tenant_id"=:tid'
    : 'DELETE FROM `branches` WHERE `id`=:id AND `tenant_id`=:tid';
  $st=$pdo->prepare($sql);
  $st->execute([':id'=>$id,':tid'=>$tid]);
  $rc=(int)$st->rowCount();
  if($rc===0){
    json_out(404,['ok'=>false,'error'=>'Branch not found']);
  }
  json_out(200,['ok'=>true,'deleted'=>1,'_meta'=>['tenant_id'=>$tid,'id'=>$id]]);
}catch(Throwable $e){
  json_out(500,['ok'=>false,'error'=>'DB delete failure: '.$e->getMessage()]);
}