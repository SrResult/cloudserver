<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    foreach ($_POST['price'] ?? [] as $productId => $price) {
        $price = (float) $price;
        if ($price >= 0) {
            $pdo->prepare('UPDATE products SET base_price_12mo = ? WHERE id = ?')
                ->execute([$price, (int) $productId]);
        }
    }
    flash('notice', 'Pricing updated.');
    header('Location: ' . APP_URL . '/admin/pricing');
    exit;
}

$products = $pdo->query('SELECT * FROM products ORDER BY category, name')->fetchAll();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Pricing — Admin</title><link rel="stylesheet" href="/assets/css/theme.css"></head>
<body>
<header class="topbar">
    <div class="brand"><?= e(APP_BRAND_NAME) ?> Admin</div>
    <nav><a href="/admin/orders">Orders</a> · <a href="/admin/pricing">Pricing</a> · <a href="/admin/settings">Settings</a> · <a href="/admin/logout">Log out</a></nav>
</header>
<main class="container">
<h1>Pricing (12-month base — 24/36mo apply the flat discount automatically)</h1>
<?php if ($n = flash('notice')): ?><div class="alert alert-success"><?= e($n) ?></div><?php endif; ?>
<form method="post">
    <?= csrf_field() ?>
    <table class="data-table">
    <thead><tr><th>Product</th><th>Category</th><th>Base price (12mo)</th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): ?>
        <tr>
            <td><?= e($p['name']) ?></td>
            <td><?= e($p['category']) ?></td>
            <td>₹ <input type="number" step="0.01" min="0" name="price[<?= (int) $p['id'] ?>]" value="<?= (float) $p['base_price_12mo'] ?>"></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    <button type="submit" class="btn btn-primary">Save prices</button>
</form>
</main>
</body>
</html>
