<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();
$pdo = get_pdo();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['add_product'])) {
        $name = trim($_POST['new_name'] ?? '');
        $category = $_POST['new_category'] ?? '';
        $price = (float) ($_POST['new_price'] ?? 0);
        $description = trim($_POST['new_description'] ?? '');

        $validCategories = ['hosting', 'vps', 'domain', 'ssl'];
        if ($name === '' || strlen($name) > 100) {
            $errors[] = 'Service name is required (max 100 characters).';
        }
        if (!in_array($category, $validCategories, true)) {
            $errors[] = 'Please choose a valid category.';
        }
        if ($price < 0) {
            $errors[] = 'Price cannot be negative.';
        }

        if (!$errors) {
            $pdo->prepare(
                'INSERT INTO products (name, category, base_price_12mo, description, is_active) VALUES (?, ?, ?, ?, 1)'
            )->execute([$name, $category, $price, $description]);
            flash('notice', 'New service "' . $name . '" added.');
            header('Location: ' . APP_URL . '/admin/pricing');
            exit;
        }
    } elseif (isset($_POST['update_products'])) {
        $validCategories = ['hosting', 'vps', 'domain', 'ssl'];
        $names = $_POST['name'] ?? [];
        $categories = $_POST['category'] ?? [];
        $prices = $_POST['price'] ?? [];
        $actives = $_POST['active'] ?? []; // checkbox: only present ids are active

        foreach ($names as $productId => $name) {
            $productId = (int) $productId;
            $name = trim($name);
            $category = $categories[$productId] ?? '';
            $price = (float) ($prices[$productId] ?? 0);
            $isActive = isset($actives[$productId]) ? 1 : 0;

            if ($name === '' || !in_array($category, $validCategories, true) || $price < 0) {
                continue; // skip invalid rows rather than fail the whole batch
            }

            $pdo->prepare(
                'UPDATE products SET name = ?, category = ?, base_price_12mo = ?, is_active = ? WHERE id = ?'
            )->execute([$name, $category, $price, $isActive, $productId]);
        }
        flash('notice', 'Services updated.');
        header('Location: ' . APP_URL . '/admin/pricing');
        exit;
    } elseif (isset($_POST['delete_product'])) {
        $productId = (int) $_POST['delete_product'];
        // Products referenced by existing orders are kept for order history —
        // just deactivate instead of a hard delete when that's the case.
        $inUse = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE product_id = ?');
        $inUse->execute([$productId]);
        if ((int) $inUse->fetchColumn() > 0) {
            $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$productId]);
            flash('notice', 'That service has past orders, so it was deactivated instead of deleted (keeps order history intact).');
        } else {
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
            flash('notice', 'Service deleted.');
        }
        header('Location: ' . APP_URL . '/admin/pricing');
        exit;
    }
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
<h1>Services &amp; Pricing</h1>
<p class="muted">12-month base price — 24-month applies a flat ₹500 discount, 36-month a flat ₹1000 discount, automatically.</p>

<?php if ($n = flash('notice')): ?><div class="alert alert-success"><?= e($n) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<section>
    <h2>Existing services</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="update_products" value="1">
        <table class="data-table">
        <thead><tr><th>Name</th><th>Category</th><th>Base price (12mo)</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><input type="text" name="name[<?= (int) $p['id'] ?>]" value="<?= e($p['name']) ?>" required maxlength="100"></td>
                <td>
                    <select name="category[<?= (int) $p['id'] ?>]">
                        <?php foreach (['hosting' => 'Hosting', 'vps' => 'VPS', 'domain' => 'Domain', 'ssl' => 'SSL'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $p['category'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>₹ <input type="number" step="0.01" min="0" name="price[<?= (int) $p['id'] ?>]" value="<?= (float) $p['base_price_12mo'] ?>"></td>
                <td><input type="checkbox" name="active[<?= (int) $p['id'] ?>]" <?= $p['is_active'] ? 'checked' : '' ?>></td>
                <td></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="5">No services yet — add one below.</td></tr><?php endif; ?>
        </tbody>
        </table>
        <button type="submit" class="btn btn-primary">Save changes</button>
    </form>

    <?php if ($products): ?>
    <details style="margin-top:12px">
        <summary class="muted" style="cursor:pointer">Delete a service</summary>
        <form method="post" style="margin-top:10px" onsubmit="return confirm('Delete this service? Services with past orders are deactivated instead.');">
            <?= csrf_field() ?>
            <label>Service to delete
                <select name="delete_product">
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['category']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
    </details>
    <?php endif; ?>
</section>

<section>
    <h2>Add a new service</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="add_product" value="1">
        <label>Service name
            <input type="text" name="new_name" required maxlength="100" placeholder="e.g. Premium Cloud Hosting">
        </label>
        <label>Category
            <select name="new_category">
                <option value="hosting">Hosting</option>
                <option value="vps">VPS</option>
                <option value="domain">Domain</option>
                <option value="ssl">SSL</option>
            </select>
        </label>
        <label>Base price (12 months, ₹)
            <input type="number" step="0.01" min="0" name="new_price" required>
        </label>
        <label>Description (shown to clients)
            <textarea name="new_description" maxlength="500" placeholder="e.g. 5 websites, 50GB SSD, free SSL, daily backups"></textarea>
        </label>
        <button type="submit" class="btn btn-secondary">Add service</button>
    </form>
</section>
</main>
</body>
</html>
