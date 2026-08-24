<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

$pdo = get_pdo();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (rate_limit_hit('login', 8, 600)) {
        $errors[] = 'Too many login attempts. Please try again later.';
    } else {
        $email = trim(strtolower($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare('SELECT id, name, password_hash, is_verified FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors[] = 'Invalid email or password.';
        } elseif (!$user['is_verified']) {
            $_SESSION['pending_verification_email'] = $email;
            header('Location: ' . APP_URL . '/verify-otp');
            exit;
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            header('Location: ' . APP_URL . '/dashboard');
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
<title>Log In — <?= e(APP_BRAND_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body class="auth-body">
<div class="auth-shell">
    <aside class="auth-brand">
        <div class="auth-brand-top">
            <div class="auth-brand-name"><?= e(APP_BRAND_NAME) ?></div>
            <div class="auth-brand-tag">Welcome back — manage your hosting, domains &amp; SSL in one place.</div>
        </div>
        <div class="auth-brand-features">
            <div class="item"><span class="icon">📊</span> Track every order &amp; renewal</div>
            <div class="item"><span class="icon">🔑</span> Instant API access after approval</div>
            <div class="item"><span class="icon">💳</span> Simple UPI-based payments</div>
        </div>
    </aside>
    <main class="auth-formside">
        <div class="auth-3dcard">
            <div class="auth-mobile-brand"><?= e(APP_BRAND_NAME) ?></div>
            <h1>Log in</h1>
            <p class="auth-sub">Enter your details to access your dashboard</p>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><?= e($err) ?></div>
            <?php endforeach; ?>
            <form method="post">
                <?= csrf_field() ?>
                <label>Email <input type="email" name="email" required autofocus></label>
                <label>Password <input type="password" name="password" required></label>
                <button type="submit" class="auth-submit">Log in</button>
            </form>
            <p class="auth-switch">New here? <a href="/register">Create an account</a></p>
        </div>
    </main>
</div>
</body>
</html>
