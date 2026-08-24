<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/crypto.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();
$pdo = get_pdo();

$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$stmt = $pdo->prepare('SELECT o.*, p.name AS product_name FROM orders o JOIN products p ON p.id = o.product_id WHERE o.id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    die('Order not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $hostname = trim($_POST['hostname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $secret = trim($_POST['secret'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $encrypted = $secret !== '' ? encrypt_secret($secret) : null;

    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'INSERT INTO order_credentials (order_id, hostname, username, secret_encrypted, notes)
           VALUES (?, ?, ?, ?, ?)
           ON CONFLICT(order_id) DO UPDATE SET hostname = excluded.hostname, username = excluded.username,
               secret_encrypted = COALESCE(excluded.secret_encrypted, order_credentials.secret_encrypted),
               notes = excluded.notes'
        : 'INSERT INTO order_credentials (order_id, hostname, username, secret_encrypted, notes)
           VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE hostname = VALUES(hostname), username = VALUES(username),
               secret_encrypted = COALESCE(VALUES(secret_encrypted), secret_encrypted), notes = VALUES(notes)';

    $pdo->prepare($sql)->execute([$orderId, $hostname, $username, $encrypted, $notes]);

    flash('notice', 'Credentials saved for order #' . $orderId);
    header('Location: ' . APP_URL . '/admin/orders');
    exit;
}

$credStmt = $pdo->prepare('SELECT * FROM order_credentials WHERE order_id = ?');
$credStmt->execute([$orderId]);
$cred = $credStmt->fetch();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Credentials — Admin</title><link rel="stylesheet" href="/assets/css/theme.css"></head>
<body>
<main class="container">
<h1>Provisioned credentials — Order #<?= (int) $orderId ?> (<?= e($order['product_name']) ?>)</h1>
<p class="muted">These are only ever released to a whitelisted developer email after OTP verification (see the API key flow) — never shown here in plaintext once saved.</p>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="order_id" value="<?= (int) $orderId ?>">
    <label>Hostname / server <input type="text" name="hostname" value="<?= e($cred['hostname'] ?? '') ?>"></label>
    <label>Username <input type="text" name="username" value="<?= e($cred['username'] ?? '') ?>"></label>
    <label>Password / secret <input type="password" name="secret" placeholder="<?= $cred ? 'Leave blank to keep existing' : '' ?>"></label>
    <label>Notes <textarea name="notes"><?= e($cred['notes'] ?? '') ?></textarea></label>
    <button type="submit" class="btn btn-primary">Save</button>
</form>
</main>
</body>
</html>
