<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

require_login();
$pdo = get_pdo();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim(strtolower($_POST['email'] ?? ''));
    $label = trim($_POST['label'] ?? '');

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT OR IGNORE INTO developer_emails (user_id, email, label) VALUES (?, ?, ?)'
            : 'INSERT IGNORE INTO developer_emails (user_id, email, label) VALUES (?, ?, ?)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $email, $label ?: null]);
        flash('notice', 'Developer email added.');
    } else {
        flash('notice', 'That did not look like a valid email address.');
    }
}

header('Location: ' . APP_URL . '/dashboard');
exit;
