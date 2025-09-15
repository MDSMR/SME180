<?php
declare(strict_types=1);

/**
 * controllers/admin/stockflow/_bootstrap.php
 * Shared bootstrap and helpers for Stockflow module
 * - Robustly loads /config/db.php
 * - Starts backend session
 * - Requires login and derives tenant/user context
 * - Transfer workflow settings helpers
 */

/* ---------- Robust /config/db.php loader ---------- */
$__sf_bootstrap_ok = false;
$__sf_bootstrap_warning = '';
$__tried = [];

// Primary candidate: three levels up from this file
$__candA = __DIR__ . '/../../../config/db.php'; $__tried[] = $__candA;

// Candidate via document root
$__docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($__docRoot !== '') {
  $__candB = $__docRoot . '/config/db.php';
  if (!in_array($__candB, $__tried, true)) $__tried[] = $__candB;
}

// Walk up to find /config/db.php just in case
$__cursor = __DIR__;
for ($i = 0; $i < 6; $i++) {
  $__cursor = dirname($__cursor);
  if ($__cursor === '/' || $__cursor === '.' || $__cursor === '') break;
  $__maybe = $__cursor . '/config/db.php';
  if (!in_array($__maybe, $__tried, true)) $__tried[] = $__maybe;
}

$__found = '';
foreach ($__tried as $__p) {
  if (is_file($__p)) { $__found = $__p; break; }
}

if ($__found === '') {
  http_response_code(500);
  exit('Configuration file not found: /config/db.php');
}

// Load and validate exported functions
$__prevHandler = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
try {
  require_once $__found; // must define db(), use_backend_session()
  if (!function_exists('db') || !function_exists('use_backend_session')) {
    $__sf_bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).';
  } else {
    $__sf_bootstrap_ok = true;
  }
} catch (Throwable $e) {
  $__sf_bootstrap_warning = 'Bootstrap error: ' . $e->getMessage();
} finally {
  if ($__prevHandler) { set_error_handler($__prevHandler); } else { restore_error_handler(); }
}

if (! $__sf_bootstrap_ok) {
  http_response_code(500);
  exit($__sf_bootstrap_warning !== '' ? $__sf_bootstrap_warning : 'Bootstrap failed.');
}

/* ---------- Start backend session ---------- */
try { use_backend_session(); }
catch (Throwable $e) { http_response_code(500); exit('Session bootstrap error: ' . $e->getMessage()); }

/* ---------- Require login (path is 3 levels up) ---------- */
require_once __DIR__ . '/../../../middleware/auth_login.php';
auth_require_login();

/* ---------- Auth / Tenant guard ---------- */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

$tenantId = (int)($user['tenant_id'] ?? 0);
$userId   = (int)($user['id'] ?? 0);
if ($tenantId <= 0) { http_response_code(403); exit('Invalid tenant context.'); }

/* ---------- Utilities ---------- */

/** Safe HTML escape */
function h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Check permission (tenant-aware with global fallback).
 * Order: tenant-specific, then global (tenant_id=0). Default deny.
 * Expects unique index: (tenant_id, role_key, permission_key)
 */
function stockflow_has_permission(string $permission_key): bool {
  global $user, $tenantId;
  static $cache = [];

  $rk = strtolower(trim((string)($user['role_key'] ?? '')));
  $pk = strtolower(trim($permission_key));
  $t  = (int)$tenantId;
  $cacheKey = $t . '|' . $rk . '|' . $pk;

  if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

  try {
    $pdo = db();

    // 1) Tenant-specific rule
    $sqlTenant = "
      SELECT is_allowed
      FROM pos_role_permissions
      WHERE tenant_id = :t
        AND LOWER(TRIM(role_key)) = :rk
        AND LOWER(TRIM(permission_key)) = :pk
      LIMIT 1
    ";
    $st = $pdo->prepare($sqlTenant);
    $st->execute([':t' => $t, ':rk' => $rk, ':pk' => $pk]);
    $val = $st->fetchColumn();
    if ($val !== false) return $cache[$cacheKey] = ((int)$val > 0);

    // 2) Global fallback (tenant_id = 0)
    $sqlGlobal = "
      SELECT is_allowed
      FROM pos_role_permissions
      WHERE tenant_id = 0
        AND LOWER(TRIM(role_key)) = :rk
        AND LOWER(TRIM(permission_key)) = :pk
      LIMIT 1
    ";
    $st = $pdo->prepare($sqlGlobal);
    $st->execute([':rk' => $rk, ':pk' => $pk]);
    $val = $st->fetchColumn();
    if ($val !== false) return $cache[$cacheKey] = ((int)$val > 0);

    return $cache[$cacheKey] = false;

  } catch (Throwable $e) {
    return $cache[$cacheKey] = false;
  }
}

/** Require permission or exit with 403 */
function stockflow_require_permission(string $permission_key): void {
  if (!stockflow_has_permission($permission_key)) {
    http_response_code(403);
    exit('Forbidden: Missing permission ' . $permission_key);
  }
}

/**
 * Generate next sequence number (type-based per tenant).
 * Table: stockflow_sequences (tenant_id, seq_type, last_no)
 */
function stockflow_next_transfer_number(PDO $pdo, int $tenantId, string $type): string {
  $pdo->beginTransaction();
  try {
    $pdo->prepare("
      INSERT INTO stockflow_sequences (tenant_id, seq_type, last_no)
      VALUES (:t,:ty,0)
      ON DUPLICATE KEY UPDATE last_no = last_no
    ")->execute([':t' => $tenantId, ':ty' => $type]);

    $pdo->prepare("
      UPDATE stockflow_sequences
      SET last_no = last_no + 1
      WHERE tenant_id = :t AND seq_type = :ty
    ")->execute([':t' => $tenantId, ':ty' => $type]);

    $st = $pdo->prepare("SELECT last_no FROM stockflow_sequences WHERE tenant_id = :t AND seq_type = :ty");
    $st->execute([':t' => $tenantId, ':ty' => $type]);
    $n = (int)$st->fetchColumn();
    $pdo->commit();

    return sprintf('%s-%06d', strtoupper($type), $n);
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

/**
 * Branches accessible to current user.
 * - If has 'stockflow.view_all_branches' => all branches
 * - Else limited by stockflow_user_branch_access (or CK branch id=1)
 */
function stockflow_get_user_branches(): array {
  global $user, $tenantId;

  try {
    $pdo = db();

    if (stockflow_has_permission('stockflow.view_all_branches')) {
      $st = $pdo->prepare("
        SELECT id, name, branch_type, is_production_enabled
        FROM branches
        WHERE tenant_id = :t AND is_active = 1
        ORDER BY name
      ");
      $st->execute([':t' => $tenantId]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $st = $pdo->prepare("
      SELECT DISTINCT b.id, b.name, b.branch_type, b.is_production_enabled
      FROM branches b
      LEFT JOIN stockflow_user_branch_access uba
        ON uba.branch_id = b.id
       AND uba.user_id  = :u
       AND uba.tenant_id = :t
       AND uba.is_active = 1
      WHERE b.tenant_id = :t
        AND b.is_active = 1
        AND (uba.id IS NOT NULL OR b.id = 1)
      ORDER BY b.name
    ");
    $st->execute([':t' => $tenantId, ':u' => (int)($user['id'] ?? 0)]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * Update stock level and log movement atomically.
 */
function stockflow_update_stock_level(
  PDO $pdo,
  int $tenantId,
  int $branchId,
  int $productId,
  float $quantityDelta,
  string $movementType,
  ?int $referenceId = null,
  string $referenceType = 'order',
  ?int $createdBy = null
): array {
  global $userId;
  $createdBy = $createdBy ?? $userId;

  // Current level (defaults to 0)
  $st = $pdo->prepare("
    SELECT current_stock
    FROM stockflow_stock_levels
    WHERE tenant_id = :t AND branch_id = :b AND product_id = :p
    LIMIT 1
  ");
  $st->execute([':t' => $tenantId, ':b' => $branchId, ':p' => $productId]);
  $currentStock = (float)($st->fetchColumn() ?: 0);
  $newStock     = $currentStock + $quantityDelta;

  // Upsert level
  $pdo->prepare("
    INSERT INTO stockflow_stock_levels (tenant_id, branch_id, product_id, current_stock, last_movement_at)
    VALUES (:t, :b, :p, :ns, NOW())
    ON DUPLICATE KEY UPDATE
      current_stock    = VALUES(current_stock),
      last_movement_at = NOW()
  ")->execute([
    ':t'  => $tenantId,
    ':b'  => $branchId,
    ':p'  => $productId,
    ':ns' => $newStock
  ]);

  // Log movement
  $pdo->prepare("
    INSERT INTO stockflow_stock_movements
      (tenant_id, branch_id, product_id, movement_type, quantity, quantity_before, quantity_after, reference_type, reference_id, created_by_user_id)
    VALUES
      (:t, :b, :p, :mt, :q, :qb, :qa, :rt, :ri, :cb)
  ")->execute([
    ':t'  => $tenantId,
    ':b'  => $branchId,
    ':p'  => $productId,
    ':mt' => $movementType,
    ':q'  => $quantityDelta,
    ':qb' => $currentStock,
    ':qa' => $newStock,
    ':rt' => $referenceType,
    ':ri' => $referenceId,
    ':cb' => $createdBy
  ]);

  return [
    'previous_stock'   => $currentStock,
    'new_stock'        => $newStock,
    'quantity_changed' => $quantityDelta
  ];
}

/** JSON response helper */
function stockflow_json_response(bool $ok, $data = null, ?string $error = null): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => $ok, 'data' => $data, 'error' => $error]);
  exit;
}

/* ---------- Transfer Workflow Settings Helpers ---------- */

/**
 * Get transfer workflow setting with fallback to default
 */
function stockflow_get_setting(string $key, $default = null) {
    global $tenantId;
    static $cache = [];
    
    $cacheKey = $tenantId . '|' . $key;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE tenant_id = :tenant AND `key` = :key LIMIT 1");
        $stmt->execute([':tenant' => $tenantId, ':key' => $key]);
        $value = $stmt->fetchColumn();
        
        if ($value !== false) {
            // Handle JSON values
            if (in_array($key, ['transfer_allow_ship_on_create', 'transfer_separation_of_duties', 'transfer_reserve_on_pending'])) {
                $cache[$cacheKey] = (bool)(int)$value;
            } else {
                $cache[$cacheKey] = $value;
            }
        } else {
            $cache[$cacheKey] = $default;
        }
        
        return $cache[$cacheKey];
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Get current transfer workflow mode
 */
function stockflow_get_workflow_mode(): string {
    return (string)stockflow_get_setting('transfer_workflow_mode', 'two_step');
}

/**
 * Check if one-step transfers are enabled
 */
function stockflow_is_one_step_mode(): bool {
    return stockflow_get_workflow_mode() === 'one_step';
}

/**
 * Check if ship-on-create is allowed (only relevant for two-step mode)
 */
function stockflow_allow_ship_on_create(): bool {
    return (bool)stockflow_get_setting('transfer_allow_ship_on_create', false);
}

/**
 * Check if separation of duties is enforced
 */
function stockflow_enforce_separation_of_duties(): bool {
    return (bool)stockflow_get_setting('transfer_separation_of_duties', false);
}

/**
 * Check if stock should be reserved on pending transfers
 */
function stockflow_reserve_on_pending(): bool {
    return (bool)stockflow_get_setting('transfer_reserve_on_pending', false);
}

/**
 * Check if user can perform both ship and receive on same transfer
 */
function stockflow_can_ship_and_receive(int $transferId, int $userId): bool {
    // If separation of duties is disabled, allow both actions
    if (!stockflow_enforce_separation_of_duties()) {
        return true;
    }
    
    try {
        $pdo = db();
        
        // Check if user already shipped or received this transfer
        $stmt = $pdo->prepare("
            SELECT shipped_by_user_id, received_by_user_id 
            FROM stockflow_transfers 
            WHERE id = :id AND tenant_id = :tenant
        ");
        $stmt->execute([':id' => $transferId, ':tenant' => $GLOBALS['tenantId']]);
        $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transfer) return false;
        
        $shippedBy = (int)($transfer['shipped_by_user_id'] ?? 0);
        $receivedBy = (int)($transfer['received_by_user_id'] ?? 0);
        
        // If user already did one action, they can't do the other
        if (($shippedBy > 0 && $shippedBy === $userId) || 
            ($receivedBy > 0 && $receivedBy === $userId)) {
            return false;
        }
        
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Validate transfer workflow permissions for specific action
 */
function stockflow_validate_transfer_action(string $action, array $transfer, int $userId): bool {
    switch ($action) {
        case 'ship':
            // Check basic permission
            if (!stockflow_has_permission('stockflow.transfers.ship')) {
                return false;
            }
            
            // Check separation of duties if enforced
            if (stockflow_enforce_separation_of_duties()) {
                return stockflow_can_ship_and_receive((int)$transfer['id'], $userId);
            }
            
            return true;
            
        case 'receive':
            // Check basic permission
            if (!stockflow_has_permission('stockflow.transfers.receive')) {
                return false;
            }
            
            // Check separation of duties if enforced
            if (stockflow_enforce_separation_of_duties()) {
                return stockflow_can_ship_and_receive((int)$transfer['id'], $userId);
            }
            
            return true;
            
        case 'ship_on_create':
            // Only allowed in two-step mode with setting enabled
            return !stockflow_is_one_step_mode() && 
                   stockflow_allow_ship_on_create() && 
                   stockflow_has_permission('stockflow.transfers.ship');
            
        default:
            return false;
    }
}

/**
 * Apply stock reservation logic based on settings
 */
function stockflow_handle_stock_reservation(PDO $pdo, int $transferId, string $action): void {
    if (!stockflow_reserve_on_pending()) {
        return; // Stock reservation disabled
    }
    
    global $tenantId;
    
    // Get transfer items
    $stmt = $pdo->prepare("
        SELECT ti.product_id, ti.quantity_requested, t.from_branch_id
        FROM stockflow_transfer_items ti
        JOIN stockflow_transfers t ON t.id = ti.transfer_id
        WHERE ti.transfer_id = :id AND t.tenant_id = :tenant
    ");
    $stmt->execute([':id' => $transferId, ':tenant' => $tenantId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        $productId = (int)$item['product_id'];
        $quantity = (float)$item['quantity_requested'];
        $branchId = (int)$item['from_branch_id'];
        
        if ($action === 'reserve') {
            // Increase reserved stock
            $pdo->prepare("
                INSERT INTO stockflow_stock_levels (tenant_id, branch_id, product_id, reserved_stock)
                VALUES (:tenant, :branch, :product, :qty)
                ON DUPLICATE KEY UPDATE reserved_stock = reserved_stock + VALUES(reserved_stock)
            ")->execute([
                ':tenant' => $tenantId,
                ':branch' => $branchId,
                ':product' => $productId,
                ':qty' => $quantity
            ]);
        } elseif ($action === 'release') {
            // Decrease reserved stock
            $pdo->prepare("
                UPDATE stockflow_stock_levels 
                SET reserved_stock = GREATEST(0, reserved_stock - :qty)
                WHERE tenant_id = :tenant AND branch_id = :branch AND product_id = :product
            ")->execute([
                ':qty' => $quantity,
                ':tenant' => $tenantId,
                ':branch' => $branchId,
                ':product' => $productId
            ]);
        }
    }
}