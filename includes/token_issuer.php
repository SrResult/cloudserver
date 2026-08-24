<?php
declare(strict_types=1);

/**
 * Generates a raw API token, stores only its hash, and returns the raw value
 * (caller is responsible for showing/emailing it — it cannot be recovered later).
 * Idempotent: if a token already exists for this order, does nothing and returns null.
 */
function issue_api_token_for_order(PDO $pdo, int $orderId, int $userId): ?string
{
    $check = $pdo->prepare('SELECT id FROM api_tokens WHERE order_id = ?');
    $check->execute([$orderId]);
    if ($check->fetch()) {
        return null; // already issued
    }

    $raw = 'hp_' . bin2hex(random_bytes(24)); // hp_ prefix = "hosting panel"
    $hash = hash('sha256', $raw);
    $prefix = substr($raw, 0, 9);

    $stmt = $pdo->prepare(
        'INSERT INTO api_tokens (order_id, user_id, token_hash, token_prefix, generated_at) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$orderId, $userId, $hash, $prefix, date('Y-m-d H:i:s')]);

    return $raw;
}
