<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();
$pdo = get_pdo();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_renewal') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $months = (int) ($_POST['months'] ?? 12);
        $dueDate = trim($_POST['due_date'] ?? '');

        if ($amount <= 0) {
            $errors[] = 'Enter a valid renewal amount.';
        } elseif (!in_array($months, [1, 3, 6, 12, 24, 36], true)) {
            $errors[] = 'Enter a valid number of months.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $errors[] = 'Enter a valid due date.';
        } else {
            $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'approved'");
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();

            if (!$order) {
                $errors[] = 'Order not found or not yet approved.';
            } else {
                $activeStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM renewals WHERE order_id = ? AND status IN ('pending','utr_submitted')"
                );
                $activeStmt->execute([$orderId]);
                if ((int) $activeStmt->fetchColumn() > 0) {
                    $errors[] = 'This order already has an active renewal invoice.';
                } else {
                    $pdo->prepare(
                        'INSERT INTO renewals (order_id, amount, months, due_date, status) VALUES (?, ?, ?, ?, "pending")'
                    )->execute([$orderId, $amount, $months, $dueDate]);
                    flash('notice', 'Renewal invoice created — the client will see it on their dashboard.');
                }
            }
        }
    } elseif ($action === 'cancel_renewal') {
        $renewalId = (int) ($_POST['renewal_id'] ?? 0);
        $pdo->prepare("DELETE FROM renewals WHERE id = ? AND status = 'pending'")->execute([$renewalId]);
        flash('notice', 'Renewal invoice cancelled.');
    } elseif ($action === 'approve_renewal' || $action === 'reject_renewal') {
        $renewalId = (int) ($_POST['renewal_id'] ?? 0);
        $rStmt = $pdo->prepare("SELECT * FROM renewals WHERE id = ? AND status = 'utr_submitted'");
        $rStmt->execute([$renewalId]);
        $renewal = $rStmt->fetch();

        if ($renewal) {
            if ($action === 'approve_renewal') {
                $now = date('Y-m-d H:i:s');
                $pdo->prepare(
                    'UPDATE renewals SET status = "approved", approved_at = ?, approved_by = ? WHERE id = ?'
                )->execute([$now, current_admin_id(), $renewalId]);

                // Extend the order's service period from whichever is later: its current
                // expiry, or today (covers an already-lapsed order being renewed late).
                $orderStmt = $pdo->prepare('SELECT expires_at FROM orders WHERE id = ?');
                $orderStmt->execute([$renewal['order_id']]);
                $currentExpiry = $orderStmt->fetchColumn();
                $base = ($currentExpiry && strtotime($currentExpiry) > time()) ? strtotime($currentExpiry) : time();
                $newExpiry = date('Y-m-d H:i:s', strtotime('+' . (int) $renewal['months'] . ' months', $base));

                $pdo->prepare('UPDATE orders SET expires_at = ? WHERE id = ?')
                    ->execute([$newExpiry, $renewal['order_id']]);

                flash('notice', "Renewal #$renewalId approved — service extended to " . date('d M Y', strtotime($newExpiry)) . '.');
            } else {
                $pdo->prepare('UPDATE renewals SET status = "rejected" WHERE id = ?')->execute([$renewalId]);
                flash('notice', "Renewal #$renewalId rejected.");
            }
        }
    }

    header('Location: ' . APP_URL . '/admin/renewals');
    exit;
}

// Approved orders with client/product info + their latest renewal (if any)
$orders = $pdo->query(
    "SELECT o.*, p.name AS product_name, u.name AS client_name, u.email AS client_email
     FROM orders o
     JOIN products p ON p.id = o.product_id
     JOIN users u ON u.id = o.user_id
     WHERE o.status = 'approved'
     ORDER BY o.expires_at IS NULL, o.expires_at ASC"
)->fetchAll();

$renewalsByOrder = [];
$allRenewals = $pdo->query(
    "SELECT r.*, o.user_id, p.name AS product_name, u.name AS client_name, u.email AS client_email
     FROM renewals r
     JOIN orders o ON o.id = r.order_id
     JOIN products p ON p.id = o.product_id
     JOIN users u ON u.id = o.user_id
     ORDER BY r.created_at DESC"
)->fetchAll();
foreach ($allRenewals as $r) {
    $renewalsByOrder[$r['order_id']][] = $r;
}

$pendingApproval = array_filter($allRenewals, fn($r) => $r['status'] === 'utr_submitted');
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Renewals — Admin</title><link rel="stylesheet" href="/assets/css/theme.css"></head>
<body>
<header class="topbar">
    <div class="brand"><?= e(APP_BRAND_NAME) ?> Admin</div>
    <nav><a href="/admin/orders">Orders</a> · <a href="/admin/renewals">Renewals</a> · <a href="/admin/pricing">Pricing</a> · <a href="/admin/settings">Settings</a> · <a href="/admin/logout">Log out</a></nav>
</header>
<main class="container">
<h1>Renewals</h1>
<p class="muted">Set how much a client should pay to renew a service, and approve their payment once submitted.</p>

<?php if ($n = flash('notice')): ?><div class="alert alert-success"><?= e($n) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($pendingApproval): ?>
<section>
    <h2>Awaiting your approval (<?= count($pendingApproval) ?>)</h2>
    <table class="data-table">
    <thead><tr><th>Client</th><th>Service</th><th>Amount</th><th>UTR</th><th>Submitted</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($pendingApproval as $r): ?>
        <tr>
            <td><?= e($r['client_name']) ?><br><small><?= e($r['client_email']) ?></small></td>
            <td><?= e($r['product_name']) ?></td>
            <td>₹<?= number_format((float) $r['amount'], 2) ?></td>
            <td><?= e($r['utr_number'] ?? '-') ?></td>
            <td><?= e($r['utr_submitted_at'] ?? '-') ?></td>
            <td>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="renewal_id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" name="action" value="approve_renewal" class="btn btn-primary btn-sm">Approve</button>
                    <button type="submit" name="action" value="reject_renewal" class="btn btn-danger btn-sm">Reject</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
</section>
<?php endif; ?>

<section>
    <h2>Active services</h2>
    <table class="data-table">
    <thead><tr><th>Client</th><th>Service</th><th>Service expires</th><th>Renewal status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
        <?php
            $latest = $renewalsByOrder[$o['id']][0] ?? null;
            $hasActive = $latest && in_array($latest['status'], ['pending', 'utr_submitted'], true);
            $expiresAt = $o['expires_at'] ?? null;
            $isOverdue = $expiresAt && strtotime($expiresAt) < time();
        ?>
        <tr>
            <td><?= e($o['client_name']) ?><br><small><?= e($o['client_email']) ?></small></td>
            <td><?= e($o['product_name']) ?></td>
            <td>
                <?php if ($expiresAt): ?>
                    <?= e(date('d M Y', strtotime($expiresAt))) ?>
                    <?php if ($isOverdue): ?><span class="badge badge-rejected">overdue</span><?php endif; ?>
                <?php else: ?>
                    <span class="muted">not set</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($latest): ?>
                    <span class="badge badge-<?= $latest['status'] === 'utr_submitted' ? 'pending_utr_verification' : ($latest['status'] === 'approved' ? 'approved' : ($latest['status'] === 'rejected' ? 'rejected' : 'awaiting_payment')) ?>">
                        <?= e(str_replace('_', ' ', $latest['status'])) ?>
                    </span>
                    · ₹<?= number_format((float) $latest['amount'], 2) ?>
                <?php else: ?>
                    <span class="muted">none</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($hasActive): ?>
                    <?php if ($latest['status'] === 'pending'): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Cancel this renewal invoice?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="renewal_id" value="<?= (int) $latest['id'] ?>">
                        <button type="submit" name="action" value="cancel_renewal" class="btn btn-secondary btn-sm">Cancel invoice</button>
                    </form>
                    <?php else: ?>
                        <span class="muted">awaiting client payment</span>
                    <?php endif; ?>
                <?php else: ?>
                    <details>
                        <summary class="btn btn-secondary btn-sm" style="display:inline-block;cursor:pointer">Set renewal charge</summary>
                        <form method="post" style="margin-top:10px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_renewal">
                            <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                            <label>Amount (₹) <input type="number" step="0.01" min="1" name="amount" required></label>
                            <label>Duration
                                <select name="months">
                                    <option value="1">1 month</option>
                                    <option value="3">3 months</option>
                                    <option value="6">6 months</option>
                                    <option value="12" selected>12 months</option>
                                    <option value="24">24 months</option>
                                    <option value="36">36 months</option>
                                </select>
                            </label>
                            <label>Due date <input type="date" name="due_date" value="<?= e($expiresAt ? date('Y-m-d', strtotime($expiresAt)) : date('Y-m-d')) ?>" required></label>
                            <button type="submit" class="btn btn-primary btn-sm">Create invoice</button>
                        </form>
                    </details>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="5">No approved orders yet.</td></tr><?php endif; ?>
    </tbody>
    </table>
</section>
</main>
</body>
</html>
