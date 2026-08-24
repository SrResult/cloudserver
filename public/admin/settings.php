<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $upi = trim($_POST['upi_id'] ?? '');
    $brand = trim($_POST['brand_name'] ?? '');

    $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = "upi_id"')->execute([$upi]);
    $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = "brand_name"')->execute([$brand]);

    if (!empty($_FILES['qr_image']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['qr_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            $destDir = __DIR__ . '/../assets/img';
            if (!is_dir($destDir)) {
                mkdir($destDir, 0775, true);
            }
            $dest = $destDir . '/payment-qr.' . $ext;
            move_uploaded_file($_FILES['qr_image']['tmp_name'], $dest);
            $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = "qr_image_path"')
                ->execute(['/assets/img/payment-qr.' . $ext]);
        }
    }

    flash('notice', 'Settings updated.');
    header('Location: ' . APP_URL . '/admin/settings');
    exit;
}

$rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
$settings = array_column($rows, 'setting_value', 'setting_key');
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Settings — Admin</title><link rel="stylesheet" href="/assets/css/theme.css"></head>
<body>
<header class="topbar">
    <div class="brand"><?= e(APP_BRAND_NAME) ?> Admin</div>
    <nav><a href="/admin/orders">Orders</a> · <a href="/admin/pricing">Pricing</a> · <a href="/admin/settings">Settings</a> · <a href="/admin/logout">Log out</a></nav>
</header>
<main class="container">
<h1>Branding & Payment Settings</h1>
<?php if ($n = flash('notice')): ?><div class="alert alert-success"><?= e($n) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label>Brand name <input type="text" name="brand_name" value="<?= e($settings['brand_name'] ?? '') ?>"></label>
    <label>UPI ID <input type="text" name="upi_id" value="<?= e($settings['upi_id'] ?? '') ?>"></label>
    <label>Payment QR image <input type="file" name="qr_image" accept="image/png,image/jpeg"></label>
    <?php if (!empty($settings['qr_image_path'])): ?>
        <p>Current: <img src="<?= e($settings['qr_image_path']) ?>" width="120"></p>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Save</button>
</form>
<p class="muted">Note: <code>brand_name</code> here is stored for reference; update <code>APP_BRAND_NAME</code> in your <code>.env</code> to change what's shown across the site.</p>
</main>
</body>
</html>
