<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * Simple symmetric encryption for storing hosting credentials at rest.
 * Key comes from .env (APP_ENCRYPTION_KEY) — generate one with:
 *   php -r "echo bin2hex(random_bytes(32));"
 * and put it in .env. Never hardcode it, never commit it.
 */
function encryption_key(): string
{
    $hex = $_ENV['APP_ENCRYPTION_KEY'] ?? '';
    if (strlen($hex) !== 64) {
        throw new RuntimeException('APP_ENCRYPTION_KEY missing or invalid in .env (need 64 hex chars / 32 bytes).');
    }
    return hex2bin($hex);
}

function encrypt_secret(string $plaintext): string
{
    $key = encryption_key();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $encoded): string
{
    $key = encryption_key();
    $raw = base64_decode($encoded);
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('Failed to decrypt secret — wrong key or corrupted data.');
    }
    return $plain;
}
