<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

require_login();
$pdo = get_pdo();
$userId = current_user_id();

$stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

$products = $pdo->query('SELECT * FROM products WHERE is_active = 1 ORDER BY category, name')->fetchAll();

$ordersStmt = $pdo->prepare(
    'SELECT o.*, p.name AS product_name
     FROM orders o JOIN products p ON p.id = o.product_id
     WHERE o.user_id = ? ORDER BY o.created_at DESC'
);
$ordersStmt->execute([$userId]);
$orders = $ordersStmt->fetchAll();

// UX fallback: if any approved order has crossed the delay and has no token yet,
// generate it right now too (the cron in cron/generate_tokens.php is the source of truth;
// this just avoids a client staring at "processing" until the next cron tick).
foreach ($orders as $order) {
    if ($order['status'] === 'approved') {
        $tokenCheck = $pdo->prepare('SELECT id FROM api_tokens WHERE order_id = ?');
        $tokenCheck->execute([$order['id']]);
        if (!$tokenCheck->fetch()) {
            $dueAt = strtotime($order['approved_at']) + TOKEN_DELAY_MINUTES * 60;
            if (time() >= $dueAt) {
                require_once __DIR__ . '/../includes/token_issuer.php';
                $rawToken = issue_api_token_for_order($pdo, (int) $order['id'], (int) $order['user_id']);
                if ($rawToken !== null) {
                    $_SESSION['newly_issued_token'] = $rawToken;
                }
            }
        }
    }
}

$tokensStmt = $pdo->prepare('SELECT * FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC');
$tokensStmt->execute([$userId]);
$tokens = $tokensStmt->fetchAll();

$devEmailsStmt = $pdo->prepare('SELECT * FROM developer_emails WHERE user_id = ? ORDER BY created_at DESC');
$devEmailsStmt->execute([$userId]);
$devEmails = $devEmailsStmt->fetchAll();

$renewalsStmt = $pdo->prepare(
    "SELECT r.*, o.tenure_months, p.name AS product_name
     FROM renewals r
     JOIN orders o ON o.id = r.order_id
     JOIN products p ON p.id = o.product_id
     WHERE o.user_id = ? AND r.status != 'rejected'
     ORDER BY r.due_date ASC"
);
$renewalsStmt->execute([$userId]);
$renewals = $renewalsStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Dashboard — <?= e(APP_BRAND_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/theme.css">
</head>
<body>
<header class="topbar">
    <div class="brand"><?= e(APP_BRAND_NAME) ?></div>
    <nav><span>Hi, <?= e($user['name']) ?></span> · <a href="/logout">Log out</a></nav>
</header>

<main class="container">
    <?php if (!empty($_SESSION['newly_issued_token'])): ?>
        <div class="alert alert-success">
            Your new API key (shown once — copy it now): <code><?= e($_SESSION['newly_issued_token']) ?></code>
        </div>
        <?php unset($_SESSION['newly_issued_token']); ?>
    <?php endif; ?>
    <?php if ($n = flash('notice')): ?><div class="alert alert-success"><?= e($n) ?></div><?php endif; ?>
    <section>
        <h2>Order a service</h2>
        <div class="product-grid">
            <?php foreach ($products as $p): ?>
                <div class="product-card">
                    <h3><?= e($p['name']) ?></h3>
                    <p class="muted"><?= e($p['category']) ?></p>
                    <p><?= e($p['description']) ?></p>
                    <a class="btn btn-primary" href="/checkout?product_id=<?= (int) $p['id'] ?>">
                        Order from ₹<?= number_format((float) $p['base_price_12mo'], 2) ?>/yr
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section>
        <h2>Your orders</h2>
        <table class="data-table">
            <thead><tr><th>Product</th><th>Tenure</th><th>Amount</th><th>Status</th><th>Service expires</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?= e($o['product_name']) ?></td>
                    <td><?= (int) $o['tenure_months'] ?> mo</td>
                    <td>₹<?= number_format((float) $o['final_amount'], 2) ?></td>
                    <td><span class="badge badge-<?= e($o['status']) ?>"><?= e(str_replace('_', ' ', $o['status'])) ?></span></td>
                    <td>
                        <?php if (!empty($o['expires_at'])): ?>
                            <?= e(date('d M Y', strtotime($o['expires_at']))) ?>
                            <?php if (strtotime($o['expires_at']) < time()): ?><span class="badge badge-rejected">expired</span><?php endif; ?>
                        <?php else: ?>
                            <span class="muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($o['status'] === 'awaiting_payment'): ?>
                            <a href="/checkout?order_id=<?= (int) $o['id'] ?>">Pay now</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?><tr><td colspan="6">No orders yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php if ($renewals): ?>
    <section>
        <h2>Renewals</h2>
        <p class="muted">Payment due, upcoming due date, and renewal status for each of your services.</p>
        <table class="data-table">
            <thead><tr><th>Service</th><th>Amount</th><th>Due date</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($renewals as $r): ?>
                <?php $overdue = strtotime($r['due_date']) < time() && $r['status'] === 'pending'; ?>
                <tr>
                    <td><?= e($r['product_name']) ?></td>
                    <td>₹<?= number_format((float) $r['amount'], 2) ?></td>
                    <td>
                        <?= e(date('d M Y', strtotime($r['due_date']))) ?>
                        <?php if ($overdue): ?><span class="badge badge-rejected">overdue</span><?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= $r['status'] === 'utr_submitted' ? 'pending_utr_verification' : ($r['status'] === 'approved' ? 'approved' : 'awaiting_payment') ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                            <a class="btn btn-primary btn-sm" href="/renew?renewal_id=<?= (int) $r['id'] ?>">Pay renewal</a>
                        <?php elseif ($r['status'] === 'approved'): ?>
                            <span class="muted">Paid</span>
                        <?php else: ?>
                            <span class="muted">Verifying payment</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <section>
        <h2>API Keys</h2>
        <table class="data-table">
            <thead><tr><th>Key (prefix)</th><th>Status</th><th>Generated</th></tr></thead>
            <tbody>
            <?php foreach ($tokens as $t): ?>
                <tr>
                    <td><code><?= e($t['token_prefix']) ?>...</code></td>
                    <td><?= $t['is_active'] ? 'Active' : 'Revoked' ?></td>
                    <td><?= e($t['generated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tokens): ?><tr><td colspan="3">No API keys yet — approved orders get one automatically 5 minutes after payment verification.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section>
        <h2>Whitelisted developer emails</h2>
        <p class="muted">Only these emails can request your hosting credentials via an API key + OTP.</p>
        <form method="post" action="/developer-emails">
            <?= csrf_field() ?>
            <input type="email" name="email" placeholder="developer@example.com" required>
            <input type="text" name="label" placeholder="Label (optional)">
            <button type="submit" class="btn btn-secondary">Add</button>
        </form>
        <ul>
            <?php foreach ($devEmails as $d): ?>
                <li><?= e($d['email']) ?> <?= $d['label'] ? '— ' . e($d['label']) : '' ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</main>
</body>
</html>
