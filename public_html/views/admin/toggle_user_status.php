<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth_check.php';
$pdo = get_pdo();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$enable  = isset($_GET['enable']);
$disable = isset($_GET['disable']);
if ($id <= 0 || (!$enable && !$disable)) { http_response_code(400); exit('Bad request'); }

if ($enable) {
    $stmt = $pdo->prepare("UPDATE users SET disabled_at = NULL, disabled_by = NULL WHERE id = ?");
    $stmt->execute([$id]);
} else {
    $by = $_SESSION['user_id'] ?? null;
    $stmt = $pdo->prepare("UPDATE users SET disabled_at = NOW(), disabled_by = ? WHERE id = ?");
    $stmt->execute([$by, $id]);
}

header('Location: /views/admin/user_management.php');
exit;