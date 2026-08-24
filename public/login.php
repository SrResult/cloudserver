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
<title>Log In — <?= e(APP_BRAND_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/theme.css">
</head>
<body>
<div class="auth-card">
    <h1><?= e(APP_BRAND_NAME) ?></h1>
    <h2>Log in</h2>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><?= e($err) ?></div>
    <?php endforeach; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Email <input type="email" name="email" required></label>
        <label>Password <input type="password" name="password" required></label>
        <button type="submit" class="btn btn-primary">Log in</button>
    </form>
    <p>New here? <a href="/register">Create an account</a></p>
</div>
</body>
</html>
