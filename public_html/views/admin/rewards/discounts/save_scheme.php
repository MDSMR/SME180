<?php
// /views/admin/rewards/discounts/save_scheme.php
declare(strict_types=1);

require_once __DIR__ . '/_shared/common.php';

if (!$bootstrap_ok || !$pdo) {
    http_response_code(500);
    die('System error');
}

// Check auth
if (!isset($user) || !isset($tenantId)) {
    http_response_code(403);
    die('Unauthorized');
}

// Verify CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    die('Invalid CSRF token');
}

// Validate input
$errors = [];
$name = trim($_POST['name'] ?? '');
$code = strtoupper(trim($_POST['code'] ?? ''));
$type = $_POST['type'] ?? '';
$value = (float)($_POST['value'] ?? 0);
$is_active = (int)($_POST['is_active'] ?? 1);
$is_stackable = (int)($_POST['is_stackable'] ?? 0);

if (empty($name)) $errors[] = 'Name is required';
if (empty($code)) $errors[] = 'Code is required';
if (!in_array($type, ['percent', 'fixed'])) $errors[] = 'Invalid type';
if ($value <= 0) $errors[] = 'Value must be greater than 0';
if ($type === 'percent' && $value > 100) $errors[] = 'Percentage cannot exceed 100';

if (!empty($errors)) {
    $_SESSION['errors'] = $errors;
    header('Location: create_program.php');
    exit;
}

// Save to database
try {
    $stmt = $pdo->prepare("
        INSERT INTO discount_schemes (tenant_id, code, name, type, value, is_stackable, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $tenantId,
        $code,
        $name,
        $type,
        $value,
        $is_stackable,
        $is_active
    ]);
    
    $_SESSION['success'] = 'Discount program created successfully!';
    header('Location: index.php');
    
} catch (PDOException $e) {
    if ($e->getCode() == '23000') {
        $_SESSION['errors'] = ['Code already exists'];
    } else {
        $_SESSION['errors'] = ['Database error occurred'];
    }
    header('Location: create_program.php');
}
?>