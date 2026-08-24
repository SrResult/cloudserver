<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../config/db.php';

$pdo = get_pdo();
$email = $_SESSION['pending_verification_email'] ?? null;
if (!$email) {
    header('Location: ' . APP_URL . '/register');
    exit;
}

$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['resend'])) {
        if (rate_limit_hit('otp_resend_' . $email, 3, 300)) {
            $errors[] = 'Please wait a bit before requesting another code.';
        } else {
            issue_otp($pdo, $email, 'register');
            $notice = 'A new code has been sent to ' . $email . '.';
        }
    } else {
        if (rate_limit_hit('otp_verify_' . $email, 8, 600)) {
            $errors[] = 'Too many attempts. Please wait a few minutes.';
        } else {
            $otp = trim($_POST['otp'] ?? '');
            $result = verify_otp($pdo, $email, 'register', $otp);

            switch ($result) {
                case 'ok':
                    $pdo->prepare('UPDATE users SET is_verified = 1 WHERE email = ?')->execute([$email]);
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();

                    unset($_SESSION['pending_verification_email']);
                    $_SESSION['user_id'] = $user['id'];
                    header('Location: ' . APP_URL . '/dashboard');
                    exit;
                case 'expired':
                    $errors[] = 'That code has expired. Request a new one below.';
                    break;
                case 'too_many_attempts':
                    $errors[] = 'Too many incorrect attempts. Request a new code.';
                    break;
                case 'not_found':
                    $errors[] = 'No active code found. Request a new one.';
                    break;
                default:
                    $errors[] = 'Incorrect code. Please try again.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Verify Email — <?= e(APP_BRAND_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/theme.css">
</head>
<body>
<div class="auth-card">
    <h1><?= e(APP_BRAND_NAME) ?></h1>
    <h2>Verify your email</h2>
    <p>We sent a 6-digit code to <strong><?= e($email) ?></strong>.</p>
    <?php if (preg_match('/^\d{6}$/', $_ENV['APP_DEBUG_FIXED_OTP'] ?? '')): ?>
        <div class="alert alert-success">Dev mode: OTP is fixed to <?= e($_ENV['APP_DEBUG_FIXED_OTP']) ?> (set in .env — remove before going live).</div>
    <?php endif; ?>

    <?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post">
        <?= csrf_field() ?>
        <label>Verification code
            <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus>
        </label>
        <button type="submit" class="btn btn-primary">Verify</button>
    </form>
    <form method="post">
        <?= csrf_field() ?>
        <button type="submit" name="resend" value="1" class="btn btn-link">Resend code</button>
    </form>
</div>
</body>
</html>
