<?php
/**
 * /controllers/admin/stockflow/get_branch_products.php
 * AJAX endpoint to get products for a specific branch
 * UPDATED VERSION with enhanced debugging, error handling, and consistent JSON
 */

declare(strict_types=1);

// Always send JSON
header('Content-Type: application/json; charset=utf-8');

// Debug mode (?debug=1)
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
  error_reporting(E_ALL);
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
} else {
  error_reporting(0);
  ini_set('display_errors', '0');
}

// Log the request for debugging
error_log("get_branch_products.php called with POST data: " . json_encode($_POST));

try {
  // ---- Bootstrap: locate config/db.php ----
  $configCandidates = [
    __DIR__ . '/../../../config/db.php',                     // typical: controllers/... -> config/db.php
    dirname(__DIR__, 3) . '/config/db.php',                 // extra safety
    $_SERVER['DOCUMENT_ROOT'] . '/config/db.php',           // docroot/config/db.php
  ];

  $configPath = null;
  foreach ($configCandidates as $candidate) {
    if (is_file($candidate)) {
      $configPath = $candidate;
      break;
    }
  }
  if (!$configPath) {
    throw new RuntimeException('Configuration file not found. Tried: ' . implode(', ', $configCandidates));
  }
  require_once $configPath;

  // ---- Session & auth ----
  if (function_exists('use_backend_session')) {
    use_backend_session();
  } else {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  }

  $authCandidates = [
    __DIR__ . '/../../../middleware/auth_login.php',
    dirname(__DIR__, 3) . '/middleware/auth_login.php',
    $_SERVER['DOCUMENT_ROOT'] . '/middleware/auth_login.php',
  ];

  $authPath = null;
  foreach ($authCandidates as $candidate) {
    if (is_file($candidate)) { $authPath = $candidate; break; }
  }
  if (!$authPath) {
    throw new RuntimeException('Auth middleware not found. Tried: ' . implode(', ', $authCandidates));
  }
  require_once $authPath;
  auth_require_login();

  // ---- Method check ----
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Only POST requests allowed, received: ' . ($_SERVER['REQUEST_METHOD'] ?? 'unknown'));
  }

  // ---- Params ----
  $branchId = (int)($_POST['branch_id'] ?? 0);
  $tenantId = (int)($_POST['tenant_id'] ?? 0);
  if ($branchId <= 0 || $tenantId <= 0) {
    throw new RuntimeException("Missing required parameters. branch_id={$branchId}, tenant_id={$tenantId}");
  }

  // ---- User/tenant guard ----
  $user = $_SESSION['user'] ?? null;
  $userTenantId = (int)($user['tenant_id'] ?? 0);
  if (!$user || $userTenantId !== $tenantId) {
    error_log("Unauthorized access attempt. User: " . json_encode($user));
    throw new RuntimeException('Unauthorized access to tenant data');
  }

  // ---- DB ----
  if (!function_exists('db')) {
    throw new RuntimeException('Database connection function db() not available');
  }
  $pdo = db();
  if (!$pdo) {
    throw new RuntimeException('Failed to get database connection');
  }

  // ---- Verify branch ----
  $branchCheckStmt = $pdo->prepare("
    SELECT id, name, branch_type
    FROM branches
    WHERE id = :branch_id AND tenant_id = :tenant_id AND is_active = 1
  ");
  $branchCheckStmt->execute([
    ':branch_id' => $branchId,
    ':tenant_id' => $tenantId
  ]);
  $branch = $branchCheckStmt->fetch(PDO::FETCH_ASSOC);
  if (!$branch) {
    throw new RuntimeException("Branch not found or inactive");
  }

  // ---- Fetch products with stock for branch ----
  $stmt = $pdo->prepare("
    SELECT 
      p.id AS product_id,
      p.name_en AS product_name,
      p.inventory_unit,
      c.name_en AS category_name,
      COALESCE(sl.current_stock, 0) AS current_stock,
      COALESCE(sl.reserved_stock, 0) AS reserved_stock,
      (COALESCE(sl.current_stock, 0) - COALESCE(sl.reserved_stock, 0)) AS available_stock
    FROM products p
    JOIN product_branches pb ON pb.product_id = p.id
    LEFT JOIN product_categories pc ON pc.product_id = p.id
    LEFT JOIN categories c ON c.id = pc.category_id AND c.tenant_id = p.tenant_id
    LEFT JOIN stockflow_stock_levels sl ON sl.product_id = p.id 
         AND sl.branch_id = :branch_id AND sl.tenant_id = p.tenant_id
    WHERE p.tenant_id = :tenant_id
      AND p.is_active = 1
      AND p.is_inventory_tracked = 1
      AND pb.branch_id = :branch_id2
    GROUP BY p.id
    ORDER BY p.name_en
  ");
  $stmt->execute([
    ':branch_id'  => $branchId,
    ':branch_id2' => $branchId,
    ':tenant_id'  => $tenantId
  ]);
  $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($products as &$product) {
    $product['product_id']      = (int)$product['product_id'];
    $product['current_stock']   = (float)$product['current_stock'];
    $product['reserved_stock']  = (float)$product['reserved_stock'];
    $product['available_stock'] = (float)$product['available_stock'];
    $product['product_name']    = (string)$product['product_name'];
    $product['inventory_unit']  = (string)($product['inventory_unit'] ?: 'piece');
    $product['category_name']   = (string)($product['category_name'] ?: '');
  }
  unset($product);

  $response = [
    'success'     => true,
    'products'    => $products,
    'count'       => count($products),
    'branch_id'   => $branchId,
    'branch_name' => $branch['name'],
    'timestamp'   => date('Y-m-d H:i:s')
  ];

  if ($DEBUG) {
    $response['debug'] = [
      'tenant_id'     => $tenantId,
      'user_id'       => (int)($user['id'] ?? 0),
      'branch_type'   => $branch['branch_type'],
      'query_executed'=> true
    ];
  }

  echo json_encode($response);

} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode([
    'success'    => false,
    'error'      => $DEBUG ? ('Database error: ' . $e->getMessage()) : 'Database error occurred',
    'products'   => [],
    'error_code' => 'DB_ERROR'
  ]);
} catch (Throwable $e) {
  $msg = $e->getMessage();
  if (!$DEBUG) {
    // Redact deep paths in production
    $msg = preg_replace('/\/[^\/\s]+\/[^\/\s]+\/[^\/\s]+\//', '/.../', $msg);
  }
  http_response_code(500);
  echo json_encode([
    'success'    => false,
    'error'      => $msg,
    'products'   => [],
    'error_code' => 'GENERAL_ERROR'
  ]);
}