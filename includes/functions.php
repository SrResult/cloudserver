<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!verify_csrf($token)) {
        http_response_code(419);
        die('Invalid or expired form submission (CSRF check failed). Please refresh and try again.');
    }
}

// ---------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------
function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $msg = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $msg;
}

// ---------------------------------------------------------------
// Simple rate limiting stored in the session (fine for a single-box local panel;
// swap for a DB/Redis-backed limiter if you ever go multi-instance).
// ---------------------------------------------------------------
function rate_limit_hit(string $bucket, int $maxAttempts, int $windowSeconds): bool
{
    $now = time();
    $entry = $_SESSION['rate_limits'][$bucket] ?? ['count' => 0, 'reset_at' => $now + $windowSeconds];

    if ($now > $entry['reset_at']) {
        $entry = ['count' => 0, 'reset_at' => $now + $windowSeconds];
    }

    $entry['count']++;
    $_SESSION['rate_limits'][$bucket] = $entry;

    return $entry['count'] > $maxAttempts;
}

// ---------------------------------------------------------------
// Auth helpers
// ---------------------------------------------------------------
function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function require_login(): void
{
    if (!current_user_id()) {
        header('Location: ' . APP_URL . '/login');
        exit;
    }
}

function current_admin_id(): ?int
{
    return $_SESSION['admin_id'] ?? null;
}

function require_admin(): void
{
    if (!current_admin_id()) {
        header('Location: ' . APP_URL . '/admin/login');
        exit;
    }
}

// ---------------------------------------------------------------
// Money / pricing
// ---------------------------------------------------------------
function calculate_price(float $basePrice12mo, int $tenureMonths): array
{
    $discounts = [12 => 0.0, 24 => 500.0, 36 => 1000.0];
    if (!isset($discounts[$tenureMonths])) {
        throw new InvalidArgumentException('Invalid tenure');
    }

    // Base price scales with tenure (simple multiple of the 12-month price),
    // discount is then applied flat on top per the business rule.
    $multiplier = $tenureMonths / 12;
    $base = round($basePrice12mo * $multiplier, 2);
    $discount = $discounts[$tenureMonths];
    $final = max(0, $base - $discount);

    return [
        'base_amount' => $base,
        'discount_amount' => $discount,
        'final_amount' => $final,
    ];
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
