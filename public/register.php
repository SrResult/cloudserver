<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../config/db.php';

$pdo = get_pdo();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (rate_limit_hit('register', 5, 600)) {
        $errors[] = 'Too many attempts. Please try again in a few minutes.';
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($name === '' || strlen($name) > 100) {
        $errors[] = 'Please enter a valid name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id, is_verified FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        if ($existing && $existing['is_verified']) {
            $errors[] = 'An account with this email already exists. Try logging in.';
        } else {
            if ($existing) {
                // Unverified stub from a previous attempt — update it rather than duplicate.
                $pdo->prepare('UPDATE users SET name = ?, password_hash = ? WHERE id = ?')
                    ->execute([$name, password_hash($password, PASSWORD_DEFAULT), $existing['id']]);
            } else {
                $pdo->prepare('INSERT INTO users (name, email, password_hash, is_verified) VALUES (?, ?, ?, 0)')
                    ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            }

            issue_otp($pdo, $email, 'register', $name);
            $_SESSION['pending_verification_email'] = $email;
            header('Location: ' . APP_URL . '/verify-otp');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create Account — <?= e(APP_BRAND_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body class="auth-body">
<div class="auth-shell">
    <aside class="auth-brand">
        <div class="auth-brand-top">
            <div class="auth-brand-name"><?= e(APP_BRAND_NAME) ?></div>
            <div class="auth-brand-tag">Create your account and get hosting, domains &amp; SSL live in minutes.</div>
        </div>
        <div class="auth-brand-features">
            <div class="item"><span class="icon">📩</span> Fast email OTP verification</div>
            <div class="item"><span class="icon">🛡️</span> 30-day money-back guarantee</div>
            <div class="item"><span class="icon">🎧</span> 24/7 support</div>
        </div>
    </aside>
    <main class="auth-formside">
        <div class="auth-3dcard">
            <div class="auth-mobile-brand"><?= e(APP_BRAND_NAME) ?></div>
            <h1>Create account</h1>
            <p class="auth-sub">Sign up to start ordering services</p>

            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><?= e($err) ?></div>
            <?php endforeach; ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>
                <label>Full name
                    <input type="text" name="name" required maxlength="100" autofocus value="<?= e($_POST['name'] ?? '') ?>">
                </label>
                <label>Email
                    <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
                </label>
                <label>Password
                    <input type="password" name="password" required minlength="8">
                </label>
                <button type="submit" class="auth-submit">Create account</button>
            </form>
            <p class="auth-switch">Already have an account? <a href="/login">Log in</a></p>
        </div>
    </main>
</div>
</body>
</html>
