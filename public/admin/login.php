<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

$pdo = get_pdo();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (rate_limit_hit('admin_login', 6, 600)) {
        $errors[] = 'Too many attempts. Please wait and try again.';
    } else {
        $email = trim(strtolower($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $stmt = $pdo->prepare('SELECT id, name, password_hash FROM admin_users WHERE email = ?');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            $errors[] = 'Invalid credentials.';
        } else {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            header('Location: ' . APP_URL . '/admin/orders');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Admin Login — <?= e(APP_BRAND_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/theme.css"></head>
<body>
<div class="auth-card">
    <h1><?= e(APP_BRAND_NAME) ?> Admin</h1>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Email <input type="email" name="email" required></label>
        <label>Password <input type="password" name="password" required></label>
        <button type="submit" class="btn btn-primary">Log in</button>
    </form>
</div>
</body>
</html>
