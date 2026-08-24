<?php
declare(strict_types=1);

/**
 * Loads .env into $_ENV / getenv() without any external dependency,
 * then exposes a small typed config array.
 */
function load_env(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

load_env(__DIR__ . '/../.env');

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

define('APP_URL', rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/'));
define('APP_BRAND_NAME', $_ENV['APP_BRAND_NAME'] ?? 'YourBrand Cloud');

define('OTP_EXPIRY_MINUTES', (int) ($_ENV['OTP_EXPIRY_MINUTES'] ?? 10));
define('OTP_MAX_ATTEMPTS', (int) ($_ENV['OTP_MAX_ATTEMPTS'] ?? 3));
define('TOKEN_DELAY_MINUTES', (int) ($_ENV['TOKEN_DELAY_MINUTES'] ?? 5));

// Session hardening
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
if (!empty($_SERVER['HTTPS'])) {
    ini_set('session.cookie_secure', '1');
}
