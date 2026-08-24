<?php
declare(strict_types=1);

/**
 * Run this every minute via system cron:
 *   * * * * * /usr/bin/php /full/path/to/hosting-panel/cron/generate_tokens.php >> /full/path/to/hosting-panel/cron/cron.log 2>&1
 *
 * This is the SOURCE OF TRUTH for the "5 minutes after approval" token
 * generation rule — the dashboard also does a fallback check on load so a
 * client visiting right at the 5-minute mark sees it immediately, but a
 * client who never opens the dashboard still gets their token via this job.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/token_issuer.php';
require_once __DIR__ . '/../includes/mailer.php';

$pdo = get_pdo();

$cutoff = date('Y-m-d H:i:s', time() - TOKEN_DELAY_MINUTES * 60);

$stmt = $pdo->prepare(
    "SELECT o.id AS order_id, o.user_id, o.approved_at, u.name, u.email
     FROM orders o
     JOIN users u ON u.id = o.user_id
     LEFT JOIN api_tokens t ON t.order_id = o.id
     WHERE o.status = 'approved'
       AND t.id IS NULL
       AND o.approved_at <= ?"
);
$stmt->execute([$cutoff]);

$due = $stmt->fetchAll();

foreach ($due as $row) {
    $raw = issue_api_token_for_order($pdo, (int) $row['order_id'], (int) $row['user_id']);
    if ($raw !== null) {
        echo date('c') . " Issued token for order #{$row['order_id']}\n";

        $body = "<p>Hi " . e($row['name']) . ",</p>"
            . "<p>Your payment has been verified and your API key is ready. "
            . "It's also visible in your dashboard.</p>"
            . "<p><strong>Keep this safe — it will only be shown once.</strong></p>"
            . "<code>{$raw}</code>";
        send_mail($row['email'], $row['name'], APP_BRAND_NAME . ' — Your API key is ready', $body);
    }
}

if (!$due) {
    echo date('c') . " No orders due for token generation.\n";
}
