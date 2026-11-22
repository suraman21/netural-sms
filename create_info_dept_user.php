<?php
/**
 * Migration: Create info_dept user account for Info department login.
 * 
 * Run this on the server via CLI: php /path/to/create_info_dept_user.php
 * Or deploy and execute through the web.
 */

require_once __DIR__ . '/admin/config.php';

$username = 'info_dept';
$role = 'info_dept';
$fullName = 'Info Department';
$email = 'info_dept@' . (getenv('SCHOOL_DOMAIN') ?: 'arkeonet.edu.et');
$password = 'InfoDept@2026'; // Secure temporary password — user should change on first login

try {
    // Check if user already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
    $stmt->execute([':username' => $username]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo "User '$username' already exists (id={$existing['id']}).\n";
        exit(0);
    }

    // Create the user
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, full_name, role, password_hash, is_active, member_id)
        VALUES (:username, :email, :full_name, :role, :password_hash, :is_active, :member_id)
    ");

    $stmt->execute([
        ':username'      => $username,
        ':email'         => $email,
        ':full_name'     => $fullName,
        ':role'          => $role,
        ':password_hash' => $passwordHash,
        ':is_active'     => 1,
        ':member_id'     => null,
    ]);

    $newUserId = $pdo->lastInsertId();
    echo "User '$username' created successfully (id=$newUserId, role=$role, active=1).\n";
    echo "Temporary password: $password\n";
    echo "Please instruct the user to change the password on first login.\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
