<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../config/db.php';

// This page is used by a DEVELOPER (not the logged-in client) to redeem an
// API key. No client session is required or checked here on purpose.

$pdo = get_pdo();
$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (rate_limit_hit('api_access_request', 10, 600)) {
        $errors[] = 'Too many attempts. Please try again later.';
    } else {
        $rawKey = trim($_POST['api_key'] ?? '');
        $devEmail = trim(strtolower($_POST['developer_email'] ?? ''));

        if ($rawKey === '' || !str_starts_with($rawKey, 'hp_')) {
            $errors[] = 'Please enter a valid API key.';
        } elseif (!filter_var($devEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $hash = hash('sha256', $rawKey);
            $stmt = $pdo->prepare('SELECT * FROM api_tokens WHERE token_hash = ? AND is_active = 1');
            $stmt->execute([$hash]);
            $token = $stmt->fetch();

            // Deliberately generic error either way — don't reveal whether the
            // key exists or the email is whitelisted, to resist enumeration.
            $generic = 'We could not validate that key/email combination. If both are correct, contact the account owner to confirm the email is whitelisted.';

            if (!$token) {
                $errors[] = $generic;
            } else {
                $wl = $pdo->prepare('SELECT id FROM developer_emails WHERE user_id = ? AND email = ?');
                $wl->execute([$token['user_id'], $devEmail]);
                if (!$wl->fetch()) {
                    $errors[] = $generic;
                } else {
                    issue_otp($pdo, $devEmail, 'developer_access');
                    $_SESSION['pending_dev_access'] = [
                        'token_id' => $token['id'],
                        'email' => $devEmail,
                    ];
                    header('Location: ' . APP_URL . '/api-access-verify');
                    exit;
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Developer Access — <?= e(APP_BRAND_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/theme.css"></head>
<body>
<main class="container">
<h1>Redeem API Key</h1>
<p class="muted">Enter the API key you were given and your developer email. If your email has been whitelisted by the account owner, we'll send you a one-time code.</p>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
<form method="post">
    <?= csrf_field() ?>
    <label>API Key <input type="text" name="api_key" placeholder="hp_..." required></label>
    <label>Your developer email <input type="email" name="developer_email" required></label>
    <button type="submit" class="btn btn-primary">Send code</button>
</form>
</main>
</body>
</html>
