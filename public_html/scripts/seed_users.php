<?php
declare(strict_types=1);
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php'; // $pdo = new PDO(...)

try {
    $pdo->beginTransaction();

    // Clear existing data
    $pdo->exec("DELETE FROM users");
    $pdo->exec("DELETE FROM roles");

    // Insert roles
    $pdo->exec("INSERT INTO roles (id, role_name) VALUES
        (1, 'Admin'),
        (2, 'Manager'),
        (3, 'Staff')");

    // Insert users with password_hash()
    $users = [
        ['admin',    'admin@example.com',    1, 'admin123'],
        ['manager1', 'manager1@example.com', 2, 'manager123'],
        ['staff1',   'staff1@example.com',   3, 'staff123'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, role_id, password_hash)
        VALUES (:username, :email, :role_id, :password_hash)
    ");

    foreach ($users as [$username, $email, $roleId, $plainPassword]) {
        $stmt->execute([
            ':username'      => $username,
            ':email'         => $email,
            ':role_id'       => $roleId,
            ':password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
        ]);
    }

    $pdo->commit();
    echo "✅ Users seeded with secure password hashes.";
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ Error: " . $e->getMessage();
}