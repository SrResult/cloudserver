<?php
declare(strict_types=1);
// Run from the command line:
//   php scripts/make_admin.php "ankit" admin@example.com "ankit@ankit"

require_once __DIR__ . '/../config/db.php';

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/make_admin.php \"Name\" email@example.com password\n");
    exit(1);
}

[$_, $name, $email, $password] = $argv;

if (strlen($password) < 10) {
    fwrite(STDERR, "Use a stronger admin password (10+ characters).\n");
    exit(1);
}

$pdo = get_pdo();
$sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
    ? 'INSERT INTO admin_users (name, email, password_hash) VALUES (?, ?, ?)
       ON CONFLICT(email) DO UPDATE SET name = excluded.name, password_hash = excluded.password_hash'
    : 'INSERT INTO admin_users (name, email, password_hash) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash)';
$stmt = $pdo->prepare($sql);
$stmt->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);

echo "Admin user ready: $email\n";
