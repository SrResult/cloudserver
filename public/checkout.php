<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

require_login();
$pdo = get_pdo();
$userId = current_user_id();
$errors = [];

$order = null;
$product = null;

if (isset($_GET['order_id'])) {
    $stmt = $pdo->prepare(
        'SELECT o.*, p.name AS product_name FROM orders o JOIN products p ON p.id = o.product_id
         WHERE o.id = ? AND o.user_id = ?'
    );
    $stmt->execute([(int) $_GET['order_id'], $userId]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        die('Order not found.');
    }
} elseif (isset($_GET['product_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) $_GET['product_id']]);
    $product = $stmt->fetch();
    if (!$product) {
        http_response_code(404);
        die('Product not found.');
    }
} else {
    header('Location: ' . APP_URL . '/dashboard');
    exit;
}

// Step 1: create the order (tenure chosen) -> Step 2: show QR + UTR form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['create_order']) && $product) {
        $isOnetime = ($product['pricing_type'] ?? 'tenure') === 'onetime';
        $tenure = $isOnetime ? 0 : (int) ($_POST['tenure_months'] ?? 0);
        if (!$isOnetime && !in_array($tenure, [12, 24, 36], true)) {
            $errors[] = 'Invalid tenure selected.';
        } else {
            $couponCode = trim($_POST['coupon_code'] ?? '');
            // Server-side pricing is authoritative — never trust a client-submitted amount.
            $pricing = calculate_order_price($pdo, $product, $tenure, $couponCode !== '' ? $couponCode : null);
            $stmt = $pdo->prepare(
                'INSERT INTO orders (user_id, product_id, tenure_months, base_amount, discount_amount, gst_amount, gst_waived, coupon_code, final_amount, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "awaiting_payment")'
            );
            $stmt->execute([
                $userId, $product['id'], $pricing['tenure_months'],
                $pricing['base_amount'], $pricing['discount_amount'],
                $pricing['gst_amount'], $pricing['gst_waived'], $pricing['coupon_code'],
                $pricing['final_amount'],
            ]);
            $orderId = (int) $pdo->lastInsertId();
            header('Location: ' . APP_URL . '/checkout?order_id=' . $orderId);
            exit;
        }
    } elseif (isset($_POST['submit_utr']) && $order) {
        $utr = trim($_POST['utr_number'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{6,30}$/', $utr)) {
            $errors[] = 'Please enter a valid UTR / transaction reference number.';
        } elseif ($order['status'] !== 'awaiting_payment') {
            $errors[] = 'This order is not awaiting payment.';
        } else {
            $pdo->prepare(
                'UPDATE orders SET utr_number = ?, utr_submitted_at = ?, status = "pending_utr_verification" WHERE id = ?'
            )->execute([$utr, date('Y-m-d H:i:s'), $order['id']]);
            flash('notice', 'Your UTR has been submitted. Our team will verify it shortly.');
            header('Location: ' . APP_URL . '/dashboard');
            exit;
        }
    }
}

$settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('upi_id','qr_image_path')");
$settings = array_column($settingsStmt->fetchAll(), 'setting_value', 'setting_key');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Checkout — <?= e(APP_BRAND_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/theme.css">
</head>
<body>
<main class="container">
<h1><?= e(APP_BRAND_NAME) ?> Checkout</h1>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<?php $isOnetime = $product && ($product['pricing_type'] ?? 'tenure') === 'onetime'; ?>
<?php if ($product && !$isOnetime): ?>
    <h2><?= e($product['name']) ?></h2>
    <form method="post" id="pricing-form">
        <?= csrf_field() ?>
        <input type="hidden" name="create_order" value="1">
        <div class="tenure-options" data-base-price="<?= (float) $product['base_price_12mo'] ?>">
            <label><input type="radio" name="tenure_months" value="12" checked> 12 months</label>
            <label><input type="radio" name="tenure_months" value="24"> 24 months (₹500 off)</label>
            <label><input type="radio" name="tenure_months" value="36"> 36 months (₹1000 off)</label>
        </div>
        <div class="price-summary">
            <p>Base: ₹<span id="base-amount">0.00</span></p>
            <p>Discount: -₹<span id="discount-amount">0.00</span></p>
            <p><strong>Subtotal: ₹<span id="final-amount">0.00</span></strong></p>
            <p class="muted">18% GST is added at the next step (waived automatically if you enter a valid coupon code).</p>
        </div>
        <label>Coupon code (optional)
            <input type="text" name="coupon_code" placeholder="e.g. NOGST18">
        </label>
        <button type="submit" class="btn btn-primary">Continue to payment</button>
    </form>

    <script src="/assets/js/pricing.js"></script>

<?php elseif ($product && $isOnetime): ?>
    <h2><?= e($product['name']) ?></h2>
    <p class="muted"><?= e($product['description']) ?></p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="create_order" value="1">
        <div class="price-summary">
            <p><strong>Price: ₹<?= number_format((float) $product['base_price_12mo'], 2) ?></strong></p>
            <p class="muted">18% GST is added at the next step (waived automatically if you enter a valid coupon code).</p>
        </div>
        <label>Coupon code (optional)
            <input type="text" name="coupon_code" placeholder="e.g. NOGST18">
        </label>
        <button type="submit" class="btn btn-primary">Continue to payment</button>
    </form>

<?php elseif ($order): ?>
    <h2>Pay for <?= e($order['product_name']) ?><?= (int) $order['tenure_months'] > 0 ? ' (' . (int) $order['tenure_months'] . ' months)' : '' ?></h2>
    <div class="price-summary">
        <p>Base: ₹<?= number_format((float) $order['base_amount'], 2) ?></p>
        <?php if ((float) $order['discount_amount'] > 0): ?><p>Discount: -₹<?= number_format((float) $order['discount_amount'], 2) ?></p><?php endif; ?>
        <?php if (!empty($order['gst_waived'])): ?>
            <p>GST: <span class="badge badge-approved">Waived<?= $order['coupon_code'] ? ' (coupon ' . e($order['coupon_code']) . ')' : '' ?></span></p>
        <?php else: ?>
            <p>GST (18%): ₹<?= number_format((float) $order['gst_amount'], 2) ?></p>
        <?php endif; ?>
        <p><strong>Amount due: ₹<?= number_format((float) $order['final_amount'], 2) ?></strong></p>
    </div>

    <?php if ($order['status'] === 'awaiting_payment'): ?>
        <div class="payment-box">
            <img src="<?= e($settings['qr_image_path'] ?? '/assets/img/payment-qr-placeholder.png') ?>" alt="Payment QR" width="220">
            <p>UPI ID: <strong><?= e($settings['upi_id'] ?? 'set-in-admin-settings') ?></strong></p>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="submit_utr" value="1">
            <label>UTR / Transaction Reference Number
                <input type="text" name="utr_number" required pattern="[A-Za-z0-9]{6,30}" placeholder="e.g. 123456789012">
            </label>
            <button type="submit" class="btn btn-primary">Submit payment reference</button>
        </form>
    <?php else: ?>
        <p>Status: <span class="badge badge-<?= e($order['status']) ?>"><?= e(str_replace('_', ' ', $order['status'])) ?></span></p>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
