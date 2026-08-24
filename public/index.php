<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

if (current_user_id()) {
    header('Location: ' . APP_URL . '/dashboard');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= e(APP_BRAND_NAME) ?> — Hosting, Domains & SSL</title>
<link rel="stylesheet" href="/assets/css/theme.css">
</head>
<body>
<header class="topbar">
    <div class="brand"><?= e(APP_BRAND_NAME) ?></div>
    <nav><a href="/login">Log in</a> · <a href="/register">Sign up</a></nav>
</header>
<main class="container">
    <h1>Hosting, Domains, SSL & VPS — under one dashboard</h1>
    <p>Create an account to order services, pay via UPI, and get instant access once verified.</p>
    <a class="btn btn-primary" href="/register">Get started</a>
</main>
</body>
</html>
