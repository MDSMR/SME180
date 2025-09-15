<?php
declare(strict_types=1);

/**
 * Table Setup Controller
 * Path: /public_html/controllers/admin/tables/setup.php
 *
 * GET  ?action=list|sections
 * POST JSON action=create|update|delete|bulk_create
 * Always returns JSON; schema-aware on optional columns.
 */

header('Content-Type: application/json; charset=utf-8');
function respond(bool $ok, $data = null, ?string $error = null, int $code = 200): void {
  http_response_code($code);
  echo json_encode(['ok'=>$ok,'data'=>$data,'error'=>$error], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  require_once dirname(__DIR__, 3) . '/config/db.php';
  require_once dirname(__DIR__, 3) . '/middleware/auth_login.php';
  require_once __DIR__ . '/permissions.php';

  auth_require_login();
  if (function_exists('use_backend_session')) { use_backend_session(); }
  if (!function_exists('db')) respond(false, null, 'Bootstrap error: db() missing', 500);

  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
  $user = $_SESSION['user'] ?? null;
  if (!$user) respond(false, null, 'Unauthorized', 401);

  $tenantId = (int)($user['tenant_id'] ?? 0);
  if ($tenantId <= 0) respond(false, null, 'Invalid tenant', 403);

  $roleKey = getUserRole();

  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $action = (string)($_GET['action'] ?? '');

  if ($method === 'GET') {
    if ($action === '' || $action === 'list')    respond(true, list_tables($pdo, $tenantId));
    if ($action === 'sections')                  respond(true, list_sections($pdo, $tenantId));
    respond(false, null, 'Unknown action', 400);
  }

  // POST
  $raw = file_get_contents('php://input') ?: '';
  $in  = json_decode($raw, true);
  if (!is_array($in)) respond(false, null, 'Invalid JSON', 400);

  $pAction = (string)($in['action'] ?? '');
  if ($pAction === 'create')      { ensureTablePermission($pdo,$tenantId,$roleKey,'create'); respond(...create_table($pdo,$tenantId,$in)); }
  if ($pAction === 'update')      { ensureTablePermission($pdo,$tenantId,$roleKey,'update'); respond(...update_table($pdo,$tenantId,$in)); }
  if ($pAction === 'delete')      { ensureTablePermission($pdo,$tenantId,$roleKey,'delete'); respond(...delete_table($pdo,$tenantId,$in)); }
  if ($pAction === 'bulk_create') { ensureTablePermission($pdo,$tenantId,$roleKey,'bulk_create'); respond(...bulk_create($pdo,$tenantId,$in)); }

  respond(false, null, 'Unknown POST action', 400);
} catch (Throwable $e) {
  respond(false, null, 'Server error: ' . $e->getMessage(), 200);
}

/* ========================= DB helpers ========================= */
function table_exists(PDO $pdo, string $t): bool { try { $pdo->query("SELECT 1 FROM `{$t}` LIMIT 1"); return true; } catch (Throwable) { return false; } }
function column_exists(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
  $q->execute([':t'=>$t, ':c'=>$c]); return (bool)$q->fetchColumn();
}

/* ========================= Actions ========================= */
function list_tables(PDO $pdo, int $tenantId): array {
  if (!table_exists($pdo,'dining_tables')) return [];
  $hasAssigned  = column_exists($pdo,'dining_tables','assigned_waiter_id');
  $hasCleaning  = column_exists($pdo,'dining_tables','needs_cleaning');
  $hasNotes     = column_exists($pdo,'dining_tables','notes');
  $hasCreatedAt = column_exists($pdo,'dining_tables','created_at');
  $hasUpdatedAt = column_exists($pdo,'dining_tables','updated_at');
  $hasBranchId  = column_exists($pdo,'dining_tables','branch_id');

  $cols = ["t.id","t.table_number","t.section","t.seats"];
  if ($hasAssigned)  $cols[]="t.assigned_waiter_id";
  if ($hasCleaning)  $cols[]="t.needs_cleaning";
  if ($hasNotes)     $cols[]="t.notes";
  if ($hasCreatedAt) $cols[]="t.created_at";
  if ($hasUpdatedAt) $cols[]="t.updated_at";
  if ($hasBranchId)  $cols[]="t.branch_id";
  $cols[] = $hasAssigned ? "COALESCE(u.name,u.email) AS waiter_name" : "NULL AS waiter_name";

  $join = $hasAssigned ? "LEFT JOIN users u ON u.id = t.assigned_waiter_id AND u.tenant_id = t.tenant_id" : "";

  $sql = "SELECT ".implode(", ",$cols)." FROM dining_tables t {$join} WHERE t.tenant_id = :t ORDER BY t.section, t.table_number";
  $st  = $pdo->prepare($sql);
  $st->execute([':t'=>$tenantId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['seats'] = (int)$r['seats'];
    if (!array_key_exists('assigned_waiter_id',$r)) $r['assigned_waiter_id'] = null;
    if (!array_key_exists('needs_cleaning',$r))     $r['needs_cleaning'] = false; else $r['needs_cleaning'] = (bool)$r['needs_cleaning'];
    if (!array_key_exists('notes',$r))              $r['notes'] = null;
    if (!array_key_exists('waiter_name',$r))        $r['waiter_name'] = null;
  }
  unset($r);
  return $rows;
}

function list_sections(PDO $pdo, int $tenantId): array {
  if (!table_exists($pdo,'dining_tables')) return [];
  $st = $pdo->prepare("SELECT section, COUNT(*) AS table_count FROM dining_tables WHERE tenant_id = :t GROUP BY section ORDER BY section");
  $st->execute([':t'=>$tenantId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function create_table(PDO $pdo, int $tenantId, array $p): array {
  if (!table_exists($pdo,'dining_tables')) return [false, null, "Table 'dining_tables' does not exist"];

  $number = trim((string)($p['table_number'] ?? ''));
  $section= trim((string)($p['section'] ?? 'main'));
  $seats  = (int)($p['seats'] ?? 4);
  $branch = (int)($p['branch_id'] ?? 1);

  if ($number === '') return [false, null, 'Table number is required'];
  if ($seats < 1 || $seats > 50) return [false, null, 'Seats must be between 1 and 50'];

  $chk = $pdo->prepare("SELECT COUNT(*) FROM dining_tables WHERE tenant_id = :t AND table_number = :n AND section = :s");
  $chk->execute([':t'=>$tenantId, ':n'=>$number, ':s'=>$section]);
  if ((int)$chk->fetchColumn() > 0) return [false, null, 'Table number already exists in this section'];

  $cols=['tenant_id','table_number','section','seats'];
  $vals=[':t',':n',':s',':seats'];
  $bind=[':t'=>$tenantId, ':n'=>$number, ':s'=>$section, ':seats'=>$seats];

  if (column_exists($pdo,'dining_tables','branch_id')) { $cols[]='branch_id'; $vals[]=':b'; $bind[':b']=$branch; }
  if (column_exists($pdo,'dining_tables','needs_cleaning')) { $cols[]='needs_cleaning'; $vals[]='0'; }
  if (column_exists($pdo,'dining_tables','status')) { $cols[]='status'; $vals[]=':st'; $bind[':st']='available'; }

  $sql = "INSERT INTO dining_tables (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $ins = $pdo->prepare($sql);
  $ins->execute($bind);
  return [true, ['id'=>(int)$pdo->lastInsertId(),'message'=>'Table created successfully'], null];
}

function update_table(PDO $pdo, int $tenantId, array $p): array {
  if (!table_exists($pdo,'dining_tables')) return [false, null, "Table 'dining_tables' does not exist"];

  $id     = (int)($p['id'] ?? 0);
  $number = trim((string)($p['table_number'] ?? ''));
  $section= trim((string)($p['section'] ?? 'main'));
  $seats  = (int)($p['seats'] ?? 4);

  if ($id <= 0) return [false, null, 'Table ID is required'];
  if ($number === '') return [false, null, 'Table number is required'];
  if ($seats < 1 || $seats > 50) return [false, null, 'Seats must be between 1 and 50'];

  $chk = $pdo->prepare("SELECT COUNT(*) FROM dining_tables WHERE tenant_id=:t AND table_number=:n AND section=:s AND id<>:id");
  $chk->execute([':t'=>$tenantId, ':n'=>$number, ':s'=>$section, ':id'=>$id]);
  if ((int)$chk->fetchColumn() > 0) return [false, null, 'Table number already exists in this section'];

  $sql = "UPDATE dining_tables SET table_number=:n, section=:s, seats=:seats, updated_at = NOW() WHERE id=:id AND tenant_id=:t";
  $st  = $pdo->prepare($sql);
  $st->execute([':n'=>$number, ':s'=>$section, ':seats'=>$seats, ':id'=>$id, ':t'=>$tenantId]);

  if ($st->rowCount() === 0) return [false, null, 'Table not found or no changes made'];
  return [true, ['message'=>'Table updated successfully'], null];
}

function delete_table(PDO $pdo, int $tenantId, array $p): array {
  if (!table_exists($pdo,'dining_tables')) return [false, null, "Table 'dining_tables' does not exist"];
  $id = (int)($p['id'] ?? 0);
  if ($id <= 0) return [false, null, 'Table ID is required'];

  $hasOrders = table_exists($pdo,'orders');
  if ($hasOrders) {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE table_id = :id AND tenant_id = :t AND status NOT IN ('closed','voided','refunded','cancelled')");
    $chk->execute([':id'=>$id, ':t'=>$tenantId]);
    if ((int)$chk->fetchColumn() > 0) return [false, null, 'Cannot delete table with active orders'];
  }

  $del = $pdo->prepare("DELETE FROM dining_tables WHERE id = :id AND tenant_id = :t");
  $del->execute([':id'=>$id, ':t'=>$tenantId]);
  if ($del->rowCount() === 0) return [false, null, 'Table not found'];

  return [true, ['message'=>'Table deleted successfully'], null];
}

function bulk_create(PDO $pdo, int $tenantId, array $p): array {
  if (!table_exists($pdo,'dining_tables')) return [false, null, "Table 'dining_tables' does not exist"];

  $section = trim((string)($p['section'] ?? 'main'));
  $prefix  = (string)($p['prefix'] ?? '');
  $start   = max(1, (int)($p['start_number'] ?? 1));
  $count   = max(1, min(50, (int)($p['count'] ?? 1)));
  $seats   = max(1, (int)($p['seats'] ?? 4));
  $branch  = (int)($p['branch_id'] ?? 1);

  $cols=['tenant_id','table_number','section','seats'];
  $vals=[':t',':n',':s',':seats'];
  if (column_exists($pdo,'dining_tables','branch_id')) { $cols[]='branch_id'; $vals[]=':b'; }
  if (column_exists($pdo,'dining_tables','needs_cleaning')) { $cols[]='needs_cleaning'; $vals[]='0'; }
  if (column_exists($pdo,'dining_tables','status')) { $cols[]='status'; $vals[]=':st'; }

  $sql = "INSERT INTO dining_tables (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $ins = $pdo->prepare($sql);
  $chk = $pdo->prepare("SELECT COUNT(*) FROM dining_tables WHERE tenant_id=:t AND table_number=:n AND section=:s");

  $created = []; $skipped = 0;
  $pdo->beginTransaction();
  try {
    for ($i=0; $i<$count; $i++) {
      $name = $prefix . (string)($start + $i);
      $chk->execute([':t'=>$tenantId, ':n'=>$name, ':s'=>$section]);
      if ((int)$chk->fetchColumn() > 0) { $skipped++; continue; }

      $bind=[':t'=>$tenantId, ':n'=>$name, ':s'=>$section, ':seats'=>$seats];
      if (column_exists($pdo,'dining_tables','branch_id')) $bind[':b'] = $branch;
      if (column_exists($pdo,'dining_tables','status'))    $bind[':st'] = 'available';

      $ins->execute($bind);
      $created[] = $name;
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    return [false, null, 'Bulk create failed'];
  }

  $msg = sprintf('%d tables created%s', count($created), $skipped ? ", $skipped skipped" : '');
  return [true, ['created'=>$created,'message'=>$msg], null];
}