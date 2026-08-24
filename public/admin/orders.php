<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if ($order && $order['status'] === 'pending_utr_verification') {
        if ($action === 'approve') {
            $now = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . (int) $order['tenure_months'] . ' months', strtotime($now)));
            $pdo->prepare('UPDATE orders SET status = "approved", approved_at = ?, approved_by = ?, expires_at = ? WHERE id = ?')
                ->execute([$now, current_admin_id(), $expiresAt, $orderId]);
            flash('notice', "Order #$orderId approved (service valid until " . date('d M Y', strtotime($expiresAt)) . "). API key will be issued in " . TOKEN_DELAY_MINUTES . " minutes (via cron or on the client's next dashboard visit).");
        } elseif ($action === 'reject') {
            $pdo->prepare('UPDATE orders SET status = "rejected" WHERE id = ?')->execute([$orderId]);
            flash('notice', "Order #$orderId rejected.");
        }
    }
    header('Location: ' . APP_URL . '/admin/orders');
    exit;
}

$orders = $pdo->query(
    "SELECT o.*, p.name AS product_name, u.name AS client_name, u.email AS client_email
     FROM orders o
     JOIN products p ON p.id = o.product_id
     JOIN users u ON u.id = o.user_id
     ORDER BY (o.status = 'pending_utr_verification') DESC, o.created_at DESC"
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Orders — Admin</title><link rel="stylesheet" href="/assets/css/theme.css"></head>
<body>
<header class="topbar">
    <div class="brand"><?= e(APP_BRAND_NAME) ?> Admin</div>
    <nav><a href="/admin/orders">Orders</a> · <a href="/admin/renewals">Renewals</a> · <a href="/admin/pricing">Pricing</a> · <a href="/admin/settings">Settings</a> · <a href="/admin/logout">Log out</a></nav>
</header>
<main class="container">
<h1>Orders</h1>
<?php if ($n = flash('notice')): ?><div class="alert alert-success"><?= e($n) ?></div><?php endif; ?>
<table class="data-table">
<thead><tr><th>#</th><th>Client</th><th>Product</th><th>Tenure</th><th>Amount</th><th>UTR</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ($orders as $o): ?>
<tr>
    <td><?= (int) $o['id'] ?></td>
    <td><?= e($o['client_name']) ?><br><small><?= e($o['client_email']) ?></small></td>
    <td><?= e($o['product_name']) ?></td>
    <td><?= (int) $o['tenure_months'] ?> mo</td>
    <td>₹<?= number_format((float) $o['final_amount'], 2) ?></td>
    <td><?= e($o['utr_number'] ?? '-') ?></td>
    <td><span class="badge badge-<?= e($o['status']) ?>"><?= e(str_replace('_', ' ', $o['status'])) ?></span></td>
    <td>
        <?php if ($o['status'] === 'pending_utr_verification'): ?>
        <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
            <button type="submit" name="action" value="approve" class="btn btn-primary btn-sm">Approve</button>
            <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm">Reject</button>
        </form>
        <?php elseif ($o['status'] === 'approved'): ?>
            <a href="/admin/credentials?order_id=<?= (int) $o['id'] ?>">Set credentials</a>
            · <a href="/admin/renewals">Renewal</a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</main>
</body>
</html>
