<?php
declare(strict_types=1);
/* ---------- Debug (optional) ---------- */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* ---------- Bootstrap /config/db.php (robust search) ---------- */
if (!function_exists('db')) {
  $__BOOTSTRAP_OK = false;
  $__docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
  $__candidates = [
    __DIR__ . '/../../config/db.php',         // /controllers/admin -> /config
    dirname(__DIR__, 2) . '/config/db.php',   // extra safety
    ($__docroot ? $__docroot . '/config/db.php' : ''),
    ($__docroot ? $__docroot . '/public_html/config/db.php' : ''),
  ];
  foreach ($__candidates as $__cand) {
    if ($__cand && is_file($__cand)) { require_once $__cand; $__BOOTSTRAP_OK = true; break; }
  }
  if (!$__BOOTSTRAP_OK) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Configuration file not found: /config/db.php';
    exit;
  }
}

/* ---------- Start backend session ---------- */
if (function_exists('use_backend_session')) {
  use_backend_session();
} else {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}

/* ---------- Auth ---------- */
if (!function_exists('auth_require_login')) {
  $__docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
  $__auth_candidates = [
    __DIR__ . '/../../middleware/auth_login.php',     // /controllers/admin -> /middleware
    dirname(__DIR__, 2) . '/middleware/auth_login.php',
    ($__docroot ? $__docroot . '/middleware/auth_login.php' : ''),
    ($__docroot ? $__docroot . '/public_html/middleware/auth_login.php' : ''),
  ];
  foreach ($__auth_candidates as $__a) {
    if ($__a && is_file($__a)) { require_once $__a; break; }
  }
}
if (function_exists('auth_require_login')) { auth_require_login(); }
?>
<?php
/**
 * /public_html/controllers/admin/modifiers_save.php
 * Handle modifier (variation group) creation and updates with values
 */
declare(strict_types=1);

// Bootstrap and session
require_once __DIR__ . '/../../config/db.php';
use_backend_session();

// Auth check
$user = $_SESSION['user'] ?? null;
if (!$user) { 
    header('Location: /views/auth/login.php'); 
    exit; 
}
$tenantId = (int)($user['tenant_id'] ?? 0);

// CSRF validation
$csrf = $_POST['csrf'] ?? '';
if ($csrf !== ($_SESSION['csrf_mod'] ?? '')) {
    $_SESSION['flash'] = 'Invalid CSRF token. Please try again.';
    header('Location: /views/admin/modifiers.php');
    exit;
}

// Determine if UPDATE or CREATE
$groupId = (int)($_POST['id'] ?? 0);
$isUpdate = $groupId > 0;

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $is_active = (int)($_POST['is_active'] ?? 1);
    $pos_visible = (int)($_POST['pos_visible'] ?? 1);
    
    // Validate
    if (empty($name)) {
        throw new RuntimeException('Modifier name is required');
    }
    
    // Ensure pos_visible column exists
    try {
        $pdo->exec("ALTER TABLE variation_groups ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");
    } catch (Exception $e) {
        // Column might already exist
    }
    
    if ($isUpdate) {
        // Verify ownership
        $stmt = $pdo->prepare("SELECT id FROM variation_groups WHERE id = :id AND tenant_id = :t");
        $stmt->execute([':id' => $groupId, ':t' => $tenantId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Modifier not found or access denied');
        }
        
        // Update group
        $stmt = $pdo->prepare("
            UPDATE variation_groups 
            SET name = :name,
                is_active = :is_active,
                pos_visible = :pos_visible,
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        $stmt->execute([
            ':id' => $groupId,
            ':tenant_id' => $tenantId,
            ':name' => $name,
            ':is_active' => $is_active,
            ':pos_visible' => $pos_visible
        ]);
        
    } else {
        // Create new group
        // Get max sort order
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM variation_groups WHERE tenant_id = :t");
        $stmt->execute([':t' => $tenantId]);
        $sort_order = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            INSERT INTO variation_groups (tenant_id, name, is_active, pos_visible, sort_order, created_at, updated_at)
            VALUES (:tenant_id, :name, :is_active, :pos_visible, :sort_order, NOW(), NOW())
        ");
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':name' => $name,
            ':is_active' => $is_active,
            ':pos_visible' => $pos_visible,
            ':sort_order' => $sort_order
        ]);
        
        $groupId = $pdo->lastInsertId();
    }
    
    // Handle values
    $value_ids = $_POST['value_id'] ?? [];
    $value_en = $_POST['value_en'] ?? [];
    $value_ar = $_POST['value_ar'] ?? [];
    $price_delta = $_POST['price_delta'] ?? [];
    
    // Track existing value IDs for deletion
    $existingValueIds = [];
    if ($isUpdate) {
        $stmt = $pdo->prepare("SELECT id FROM variation_values WHERE group_id = :g");
        $stmt->execute([':g' => $groupId]);
        $existingValueIds = array_column($stmt->fetchAll(), 'id');
    }
    
    $processedIds = [];
    
    // Process each value
    for ($i = 0; $i < count($value_en); $i++) {
        $vid = !empty($value_ids[$i]) ? (int)$value_ids[$i] : 0;
        $ven = trim($value_en[$i] ?? '');
        $var = trim($value_ar[$i] ?? '');
        $pdelta = (float)($price_delta[$i] ?? 0);
        
        if (empty($ven)) continue; // Skip empty values
        
        if ($vid > 0) {
            // Update existing value
            $stmt = $pdo->prepare("
                UPDATE variation_values 
                SET value_en = :ven,
                    value_ar = :var,
                    price_delta = :pdelta,
                    updated_at = NOW()
                WHERE id = :id AND group_id = :gid
            ");
            $stmt->execute([
                ':id' => $vid,
                ':gid' => $groupId,
                ':ven' => $ven,
                ':var' => $var,
                ':pdelta' => $pdelta
            ]);
            $processedIds[] = $vid;
        } else {
            // Insert new value
            $stmt = $pdo->prepare("
                INSERT INTO variation_values (group_id, value_en, value_ar, price_delta, is_active, pos_visible, sort_order, created_at, updated_at)
                VALUES (:gid, :ven, :var, :pdelta, 1, 1, :sort, NOW(), NOW())
            ");
            $stmt->execute([
                ':gid' => $groupId,
                ':ven' => $ven,
                ':var' => $var,
                ':pdelta' => $pdelta,
                ':sort' => $i
            ]);
            $processedIds[] = $pdo->lastInsertId();
        }
    }
    
    // Delete removed values
    $toDelete = array_diff($existingValueIds, $processedIds);
    if (!empty($toDelete)) {
        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $stmt = $pdo->prepare("DELETE FROM variation_values WHERE id IN ($placeholders) AND group_id = ?");
        $params = array_merge($toDelete, [$groupId]);
        $stmt->execute($params);
    }
    
    $pdo->commit();
    
    $_SESSION['flash'] = $isUpdate ? 'Modifier updated successfully!' : 'Modifier created successfully!';
    header('Location: /views/admin/modifiers.php');
    exit;
    
} catch (Throwable $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    
    $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    
    if ($isUpdate) {
        header('Location: /views/admin/modifier_edit.php?id=' . $groupId);
    } else {
        header('Location: /views/admin/modifier_new.php');
    }
    exit;
}