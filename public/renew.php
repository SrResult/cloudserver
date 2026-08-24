<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

require_login();
$pdo = get_pdo();
$userId = current_user_id();
$errors = [];

$renewalId = (int) ($_GET['renewal_id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT r.*, o.user_id, o.tenure_months, p.name AS product_name
     FROM renewals r
     JOIN orders o ON o.id = r.order_id
     JOIN products p ON p.id = o.product_id
     WHERE r.id = ? AND o.user_id = ?'
);
$stmt->execute([$renewalId, $userId]);
$renewal = $stmt->fetch();

if (!$renewal) {
    http_response_code(404);
    die('Renewal invoice not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_utr'])) {
    require_csrf();
    $utr = trim($_POST['utr_number'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{6,30}$/', $utr)) {
        $errors[] = 'Please enter a valid UTR / transaction reference number.';
    } elseif ($renewal['status'] !== 'pending') {
        $errors[] = 'This renewal is not awaiting payment.';
    } else {
        $pdo->prepare(
            'UPDATE renewals SET utr_number = ?, utr_submitted_at = ?, status = "utr_submitted" WHERE id = ?'
        )->execute([$utr, date('Y-m-d H:i:s'), $renewal['id']]);
        flash('notice', 'Your renewal payment reference has been submitted. Our team will verify it shortly.');
        header('Location: ' . APP_URL . '/dashboard');
        exit;
    }
}

$settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('upi_id','qr_image_path')");
$settings = array_column($settingsStmt->fetchAll(), 'setting_value', 'setting_key');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Renew — <?= e(APP_BRAND_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/theme.css">
</head>
<body>
<header class="topbar">
    <div class="brand"><?= e(APP_BRAND_NAME) ?></div>
    <nav><a href="/dashboard">Dashboard</a> · <a href="/logout">Log out</a></nav>
</header>
<main class="container">
<h1>Renew <?= e($renewal['product_name']) ?></h1>
<p>Due date: <strong><?= e(date('d M Y', strtotime($renewal['due_date']))) ?></strong> · Covers <?= (int) $renewal['months'] ?> month(s)</p>
<p>Amount due: <strong>₹<?= number_format((float) $renewal['amount'], 2) ?></strong></p>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($renewal['status'] === 'pending'): ?>
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
    <p>Status: <span class="badge badge-<?= $renewal['status'] === 'utr_submitted' ? 'pending_utr_verification' : e($renewal['status']) ?>"><?= e(str_replace('_', ' ', $renewal['status'])) ?></span></p>
    <p><a href="/dashboard">Back to dashboard</a></p>
<?php endif; ?>
</main>
</body>
</html>
