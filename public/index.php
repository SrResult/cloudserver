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
    <nav><a href="/login">Log in</a> · <a href="/register">Sign up</a> · <a href="/admin/login">Admin login</a></nav>
</header>
<main class="container">
    <h1>Hosting, Domains, SSL & VPS — under one dashboard</h1>
    <p>Create an account to order services, pay via UPI, and get instant access once verified.</p>
    <div class="cta-row">
        <a class="btn btn-primary" href="/register">Sign up (new client)</a>
        <a class="btn btn-secondary" href="/login">Log in</a>
    </div>
    <p class="muted" style="margin-top:24px">Are you the site admin? <a href="/admin/login">Log in to the admin panel</a>.</p>
</main>
</body>
</html>
