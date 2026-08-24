<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../config/db.php';

$pdo = get_pdo();
$pending = $_SESSION['pending_dev_access'] ?? null;
if (!$pending) {
    header('Location: ' . APP_URL . '/api-access');
    exit;
}

$errors = [];
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (rate_limit_hit('api_access_verify', 8, 600)) {
        $errors[] = 'Too many attempts. Please try again later.';
    } else {
        $otp = trim($_POST['otp'] ?? '');
        $result = verify_otp($pdo, $pending['email'], 'developer_access', $otp);

        if ($result !== 'ok') {
            $errors[] = match ($result) {
                'expired' => 'Code expired. Go back and request a new one.',
                'too_many_attempts' => 'Too many incorrect attempts. Go back and request a new one.',
                default => 'Incorrect code. Please try again.',
            };
        } else {
            $stmt = $pdo->prepare(
                'SELECT t.*, o.id AS order_id, o.product_id, p.name AS product_name, u.name AS client_name
                 FROM api_tokens t
                 JOIN orders o ON o.id = t.order_id
                 JOIN products p ON p.id = o.product_id
                 JOIN users u ON u.id = t.user_id
                 WHERE t.id = ?'
            );
            $stmt->execute([$pending['token_id']]);
            $token = $stmt->fetch();

            $credStmt = $pdo->prepare('SELECT * FROM order_credentials WHERE order_id = ?');
            $credStmt->execute([$token['order_id']]);
            $cred = $credStmt->fetch();

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $log = $pdo->prepare(
                'INSERT INTO token_usage_log (api_token_id, developer_email, otp_verified, ip_address) VALUES (?, ?, 1, ?)'
            );
            $log->execute([$token['id'], $pending['email'], $ip]);
            $logId = (int) $pdo->lastInsertId();

            if (!$cred) {
                $errors[] = 'This service has not been provisioned yet by the account admin. Please try again later or contact support.';
            } else {
                $secret = $cred['secret_encrypted'] ? decrypt_secret($cred['secret_encrypted']) : '(not set)';
                $body = "<p>Access credentials for <strong>" . e($token['product_name']) . "</strong> "
                    . "(client: " . e($token['client_name']) . "):</p>"
                    . "<ul>"
                    . "<li>Host: " . e($cred['hostname'] ?? '-') . "</li>"
                    . "<li>Username: " . e($cred['username'] ?? '-') . "</li>"
                    . "<li>Password/Secret: " . e($secret) . "</li>"
                    . "</ul>"
                    . "<p>These credentials were requested via API key and verified against a developer email whitelisted by the client. Do not share them further.</p>";

                $ok = send_mail($pending['email'], $pending['email'], APP_BRAND_NAME . ' — Access Credentials', $body);
                $pdo->prepare('UPDATE token_usage_log SET credentials_sent = ? WHERE id = ?')->execute([$ok ? 1 : 0, $logId]);

                $sent = $ok;
                if (!$ok) {
                    $errors[] = 'Verified, but we failed to send the email. Please try again or contact support.';
                }
            }

            unset($_SESSION['pending_dev_access']);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Verify — <?= e(APP_BRAND_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/theme.css"></head>
<body>
<main class="container">
<h1>Verify code</h1>
<?php if (preg_match('/^\d{6}$/', $_ENV['APP_DEBUG_FIXED_OTP'] ?? '')): ?>
    <div class="alert alert-success">Dev mode: OTP is fixed to <?= e($_ENV['APP_DEBUG_FIXED_OTP']) ?> (set in .env — remove before going live).</div>
<?php endif; ?>
<?php if ($sent): ?>
    <div class="alert alert-success">Verified. Credentials have been emailed to <?= e($pending['email']) ?>.</div>
<?php else: ?>
    <p>Enter the code sent to <strong><?= e($pending['email']) ?></strong>.</p>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="text" name="otp" inputmode="numeric" maxlength="6" required autofocus>
        <button type="submit" class="btn btn-primary">Verify</button>
    </form>
<?php endif; ?>
</main>
</body>
</html>
