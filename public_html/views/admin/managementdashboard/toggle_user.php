<?php
require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../lib/logger.php';
// CSRF protection for POST forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = req_post('csrf_token');
    if (!csrf_check($csrf)) { log_error('CSRF token invalid'); http_response_code(400); echo json_encode(['success'=>false,'error'=>'CSRF token invalid']); exit; }
}
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = req_post('id') ?? null;

    if ($id) {
        // Fetch current status
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if ($user) {
            $newStatus = ($user['status'] === 'active') ? 'disabled' : 'active';

            // Update status
            $update = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $update->execute([$newStatus, $id]);

            // Optional: log the change
            // $log = $pdo->prepare("INSERT INTO user_audit (user_id, action, changed_by) VALUES (?, ?, ?)");
            // $log->execute([$id, "Status changed to $newStatus", $_SESSION['user_id']]);
        }
    }
}

header('Location: index.php');
exit;