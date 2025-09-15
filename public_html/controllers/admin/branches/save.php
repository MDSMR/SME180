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
try{ $body=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }catch(Throwable $e){ json_out(400,['ok'=>false,'error'=>'Invalid JSON']); }
if(!is_array($body)) json_out(400,['ok'=>false,'error'=>'Body must be JSON object']);

$id = isset($body['id']) && $body['id']!=='' ? (int)$body['id'] : null;
$name = trim((string)($body['name'] ?? ''));
$address = trim((string)($body['address'] ?? ''));
$phone = trim((string)($body['phone'] ?? ''));
$email = trim((string)($body['email'] ?? ''));
$timezone = trim((string)($body['timezone'] ?? 'Africa/Cairo'));
$is_active = isset($body['is_active']) ? (int)!!$body['is_active'] : 1;

$errors=[];
if($name==='') $errors['name']='Required';
if($address==='') $errors['address']='Required';
if($email!=='' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email']='Invalid email';
if($errors) json_out(422,['ok'=>false,'error'=>'Validation failed','fields'=>$errors]);

function get_pdo(): ?PDO {
  if(function_exists('db')){ try{ $pdo=db(); if($pdo instanceof PDO) return $pdo; }catch(Throwable $e){} }
  if(function_exists('pdo')){ try{ $pdo=pdo(); if($pdo instanceof PDO) return $pdo; }catch(Throwable $e){} }
  if(!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  return null;
}
$pdo=get_pdo();
if(!($pdo instanceof PDO)) json_out(500,['ok'=>false,'error'=>'No database connection']);

try{
  $pdo->beginTransaction();
  $driver=strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

  if($id===null){
    // INSERT
    if($driver==='pgsql'){
      $sql='INSERT INTO branches("tenant_id","name","address","phone","email","timezone","is_active") VALUES(:tid,:n,:a,:p,:e,:tz,:act) RETURNING "id"';
      $st=$pdo->prepare($sql);
      $st->execute([':tid'=>$tid,':n'=>$name,':a'=>$address,':p'=>$phone,':e'=>$email,':tz'=>$timezone,':act'=>$is_active]);
      $id=(int)$st->fetchColumn();
    }else{
      $sql='INSERT INTO `branches`(`tenant_id`,`name`,`address`,`phone`,`email`,`timezone`,`is_active`) VALUES(:tid,:n,:a,:p,:e,:tz,:act)';
      $st=$pdo->prepare($sql);
      $st->execute([':tid'=>$tid,':n'=>$name,':a'=>$address,':p'=>$phone,':e'=>$email,':tz'=>$timezone,':act'=>$is_active]);
      $id=(int)$pdo->lastInsertId();
    }
  } else {
    // UPDATE (tenant-scoped)
    if($driver==='pgsql'){
      $sql='UPDATE branches SET "name"=:n,"address"=:a,"phone"=:p,"email"=:e,"timezone"=:tz,"is_active"=:act WHERE "id"=:id AND "tenant_id"=:tid';
    }else{
      $sql='UPDATE `branches` SET `name`=:n,`address`=:a,`phone`=:p,`email`=:e,`timezone`=:tz,`is_active`=:act WHERE `id`=:id AND `tenant_id`=:tid';
    }
    $st=$pdo->prepare($sql);
    $st->execute([':n'=>$name,':a'=>$address,':p'=>$phone,':e'=>$email,':tz'=>$timezone,':act'=>$is_active,':id'=>$id,':tid'=>$tid]);
    if($st->rowCount()===0){
      // Either not found or no change; verify existence
      $check = $pdo->prepare($driver==='pgsql' ? 'SELECT 1 FROM branches WHERE "id"=:id AND "tenant_id"=:tid' : 'SELECT 1 FROM `branches` WHERE `id`=:id AND `tenant_id`=:tid');
      $check->execute([':id'=>$id,':tid'=>$tid]);
      if(!$check->fetchColumn()){ throw new RuntimeException('Branch not found for this tenant'); }
    }
  }

  // Return the saved row
  $sel = $pdo->prepare(
    $driver==='pgsql'
      ? 'SELECT "id","name","address","phone","email","timezone","is_active" FROM branches WHERE "id"=:id AND "tenant_id"=:tid'
      : 'SELECT `id`,`name`,`address`,`phone`,`email`,`timezone`,`is_active` FROM `branches` WHERE `id`=:id AND `tenant_id`=:tid'
  );
  $sel->execute([':id'=>$id,':tid'=>$tid]);
  $row=$sel->fetch(PDO::FETCH_ASSOC);
  if(!$row) throw new RuntimeException('Saved row not retrievable');

  $pdo->commit();
  $row['id']=(int)$row['id']; $row['is_active']=(int)$row['is_active'];
  json_out(200,['ok'=>true,'data'=>$row,'_meta'=>['tenant_id'=>$tid,'action'=>($body['id']===''||$body['id']===null?'insert':'update')]]);
}catch(Throwable $e){
  if($pdo->inTransaction()){ try{ $pdo->rollBack(); }catch(Throwable $e2){} }
  json_out(500,['ok'=>false,'error'=>'DB write failure: '.$e->getMessage()]);
}