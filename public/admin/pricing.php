<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();
$pdo = get_pdo();
$errors = [];

$categoryOptions = ['hosting' => 'Hosting', 'vps' => 'VPS', 'domain' => 'Domain', 'ssl' => 'SSL', 'email' => 'Business Email', 'website' => 'Website Development'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['add_product'])) {
        $name = trim($_POST['new_name'] ?? '');
        $category = $_POST['new_category'] ?? '';
        $pricingType = ($_POST['new_pricing_type'] ?? 'tenure') === 'onetime' ? 'onetime' : 'tenure';
        $gstApplicable = isset($_POST['new_gst_applicable']) ? 1 : 0;
        $price = (float) ($_POST['new_price'] ?? 0);
        $description = trim($_POST['new_description'] ?? '');

        if ($name === '' || strlen($name) > 100) {
            $errors[] = 'Service name is required (max 100 characters).';
        }
        if ($category === '' || strlen($category) > 30) {
            $errors[] = 'Please choose or type a category.';
        }
        if ($price < 0) {
            $errors[] = 'Price cannot be negative.';
        }

        if (!$errors) {
            $pdo->prepare(
                'INSERT INTO products (name, category, pricing_type, gst_applicable, base_price_12mo, description, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
            )->execute([$name, $category, $pricingType, $gstApplicable, $price, $description]);
            flash('notice', 'New service "' . $name . '" added.');
            header('Location: ' . APP_URL . '/admin/pricing');
            exit;
        }
    } elseif (isset($_POST['update_products'])) {
        $names = $_POST['name'] ?? [];
        $categories = $_POST['category'] ?? [];
        $pricingTypes = $_POST['pricing_type'] ?? [];
        $prices = $_POST['price'] ?? [];
        $descriptions = $_POST['description'] ?? [];
        $actives = $_POST['active'] ?? []; // checkbox: only present ids are active
        $gsts = $_POST['gst_applicable'] ?? []; // checkbox: only present ids have GST on

        foreach ($names as $productId => $name) {
            $productId = (int) $productId;
            $name = trim($name);
            $category = trim($categories[$productId] ?? '');
            $pricingType = ($pricingTypes[$productId] ?? 'tenure') === 'onetime' ? 'onetime' : 'tenure';
            $price = (float) ($prices[$productId] ?? 0);
            $description = trim($descriptions[$productId] ?? '');
            $isActive = isset($actives[$productId]) ? 1 : 0;
            $gstApplicable = isset($gsts[$productId]) ? 1 : 0;

            if ($name === '' || $category === '' || $price < 0) {
                continue; // skip invalid rows rather than fail the whole batch
            }

            $pdo->prepare(
                'UPDATE products SET name = ?, category = ?, pricing_type = ?, gst_applicable = ?, base_price_12mo = ?, description = ?, is_active = ? WHERE id = ?'
            )->execute([$name, $category, $pricingType, $gstApplicable, $price, $description, $isActive, $productId]);
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
    } elseif (isset($_POST['add_coupon'])) {
        $code = strtoupper(trim($_POST['new_coupon_code'] ?? ''));
        $waivesGst = isset($_POST['new_coupon_waives_gst']) ? 1 : 0;
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,50}$/', $code)) {
            $errors[] = 'Coupon code must be 3-50 characters: letters, numbers, - or _ only.';
        } else {
            $dup = $pdo->prepare('SELECT id FROM coupons WHERE UPPER(code) = ?');
            $dup->execute([$code]);
            if ($dup->fetchColumn()) {
                $errors[] = 'A coupon with that code already exists.';
            } else {
                $pdo->prepare('INSERT INTO coupons (code, waives_gst, is_active) VALUES (?, ?, 1)')->execute([$code, $waivesGst]);
                flash('notice', 'Coupon "' . $code . '" created.');
                header('Location: ' . APP_URL . '/admin/pricing');
                exit;
            }
        }
    } elseif (isset($_POST['toggle_coupon'])) {
        $couponId = (int) $_POST['toggle_coupon'];
        $pdo->prepare('UPDATE coupons SET is_active = 1 - is_active WHERE id = ?')->execute([$couponId]);
        header('Location: ' . APP_URL . '/admin/pricing');
        exit;
    } elseif (isset($_POST['delete_coupon'])) {
        $couponId = (int) $_POST['delete_coupon'];
        $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([$couponId]);
        flash('notice', 'Coupon deleted.');
        header('Location: ' . APP_URL . '/admin/pricing');
        exit;
    } elseif (isset($_POST['save_gst'])) {
        $gstPercent = (float) ($_POST['gst_percent'] ?? 18);
        if ($gstPercent < 0 || $gstPercent > 100) {
            $errors[] = 'GST % must be between 0 and 100.';
        } else {
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'gst_percent'")->execute([(string) $gstPercent]);
            flash('notice', 'GST rate updated to ' . $gstPercent . '%.');
            header('Location: ' . APP_URL . '/admin/pricing');
            exit;
        }
    }
}

$products = $pdo->query('SELECT * FROM products ORDER BY category, name')->fetchAll();
$coupons = $pdo->query('SELECT * FROM coupons ORDER BY created_at DESC')->fetchAll();
$currentGst = gst_percent($pdo);
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Pricing — Admin</title><link rel="stylesheet" href="/assets/css/theme.css"></head>
<body>
<header class="topbar">
    <div class="brand"><?= e(APP_BRAND_NAME) ?> Admin</div>
    <nav><a href="/admin/orders">Orders</a> · <a href="/admin/renewals">Renewals</a> · <a href="/admin/pricing">Pricing</a> · <a href="/admin/settings">Settings</a> · <a href="/admin/logout">Log out</a></nav>
</header>
<main class="container">
<h1>Services &amp; Pricing</h1>
<p class="muted">"Tenure" services use 12/24/36-month pricing (24mo = ₹500 off, 36mo = ₹1000 off, automatically). "One-time" services (email plans, custom website builds) have a single fixed price. GST is added on top of the final price unless a GST-waiver coupon is applied at checkout.</p>

<?php if ($n = flash('notice')): ?><div class="alert alert-success"><?= e($n) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<section>
    <h2>GST rate</h2>
    <form method="post" style="display:flex;gap:10px;align-items:center">
        <?= csrf_field() ?>
        <input type="hidden" name="save_gst" value="1">
        <label>GST %
            <input type="number" step="0.01" min="0" max="100" name="gst_percent" value="<?= e((string) $currentGst) ?>" style="width:90px">
        </label>
        <button type="submit" class="btn btn-secondary">Save</button>
    </form>
</section>

<section>
    <h2>Existing services</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="update_products" value="1">
        <div style="overflow-x:auto">
        <table class="data-table">
        <thead><tr><th>Name</th><th>Category</th><th>Type</th><th>Price (₹)</th><th>GST</th><th>Description</th><th>Active</th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><input type="text" name="name[<?= (int) $p['id'] ?>]" value="<?= e($p['name']) ?>" required maxlength="100" style="min-width:180px"></td>
                <td>
                    <input type="text" list="category-list" name="category[<?= (int) $p['id'] ?>]" value="<?= e($p['category']) ?>" maxlength="30" style="width:110px">
                </td>
                <td>
                    <select name="pricing_type[<?= (int) $p['id'] ?>]">
                        <option value="tenure" <?= ($p['pricing_type'] ?? 'tenure') === 'tenure' ? 'selected' : '' ?>>Tenure (12/24/36mo)</option>
                        <option value="onetime" <?= ($p['pricing_type'] ?? 'tenure') === 'onetime' ? 'selected' : '' ?>>One-time</option>
                    </select>
                </td>
                <td>₹ <input type="number" step="0.01" min="0" name="price[<?= (int) $p['id'] ?>]" value="<?= (float) $p['base_price_12mo'] ?>" style="width:100px"></td>
                <td style="text-align:center"><input type="checkbox" name="gst_applicable[<?= (int) $p['id'] ?>]" <?= !isset($p['gst_applicable']) || (int) $p['gst_applicable'] === 1 ? 'checked' : '' ?>></td>
                <td><input type="text" name="description[<?= (int) $p['id'] ?>]" value="<?= e((string) $p['description']) ?>" maxlength="500" style="min-width:220px"></td>
                <td style="text-align:center"><input type="checkbox" name="active[<?= (int) $p['id'] ?>]" <?= $p['is_active'] ? 'checked' : '' ?>></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="7">No services yet — add one below.</td></tr><?php endif; ?>
        </tbody>
        </table>
        </div>
        <datalist id="category-list">
            <?php foreach ($categoryOptions as $val => $label): ?><option value="<?= e($val) ?>"><?php endforeach; ?>
        </datalist>
        <button type="submit" class="btn btn-primary" style="margin-top:10px">Save changes</button>
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
            <input type="text" list="category-list" name="new_category" required maxlength="30" placeholder="hosting / vps / domain / ssl / email / website / ...">
        </label>
        <label>Pricing type
            <select name="new_pricing_type">
                <option value="tenure">Tenure (12/24/36 months, flat discount)</option>
                <option value="onetime">One-time (single fixed price)</option>
            </select>
        </label>
        <label>Price (₹)
            <input type="number" step="0.01" min="0" name="new_price" required>
        </label>
        <label><input type="checkbox" name="new_gst_applicable" checked> 18% GST applies to this service</label>
        <label>Description (shown to clients)
            <textarea name="new_description" maxlength="500" placeholder="e.g. 5 websites, 50GB SSD, free SSL, daily backups"></textarea>
        </label>
        <button type="submit" class="btn btn-secondary">Add service</button>
    </form>
</section>

<section>
    <h2>Coupon codes</h2>
    <p class="muted">Right now a coupon can only waive GST on the order it's applied to at checkout. Customers type the code themselves on the checkout page.</p>
    <table class="data-table">
        <thead><tr><th>Code</th><th>Effect</th><th>Status</th><th>Used</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($coupons as $c): ?>
            <tr>
                <td><code><?= e($c['code']) ?></code></td>
                <td><?= $c['waives_gst'] ? 'Waives GST' : '—' ?></td>
                <td><span class="badge badge-<?= $c['is_active'] ? 'approved' : 'rejected' ?>"><?= $c['is_active'] ? 'Active' : 'Disabled' ?></span></td>
                <td><?= (int) $c['usage_count'] ?></td>
                <td>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <button type="submit" name="toggle_coupon" value="<?= (int) $c['id'] ?>" class="btn btn-secondary btn-sm"><?= $c['is_active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this coupon?');">
                        <?= csrf_field() ?>
                        <button type="submit" name="delete_coupon" value="<?= (int) $c['id'] ?>" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$coupons): ?><tr><td colspan="5">No coupons yet.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <form method="post" style="margin-top:12px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="add_coupon" value="1">
        <label>New coupon code
            <input type="text" name="new_coupon_code" maxlength="50" placeholder="e.g. NOGST18" style="text-transform:uppercase">
        </label>
        <label><input type="checkbox" name="new_coupon_waives_gst" checked> Waives GST</label>
        <button type="submit" class="btn btn-secondary">Create coupon</button>
    </form>
</section>
</main>
</body>
</html>
