<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

// Requires PHPMailer installed via Composer:
//   composer require phpmailer/phpmailer
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Sends an email via SMTP using PHPMailer. Returns true on success.
 * Throws no exceptions to callers by default; check the return value.
 */
function send_mail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    if (!class_exists(PHPMailer::class)) {
        error_log('PHPMailer not installed. Run: composer require phpmailer/phpmailer');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'] ?? '';
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'] ?? '';
        $mail->Password = $_ENV['SMTP_PASS'] ?? '';
        $mail->SMTPSecure = $_ENV['SMTP_ENCRYPTION'] ?? 'tls';
        $mail->Port = (int) ($_ENV['SMTP_PORT'] ?? 587);

        $mail->setFrom($_ENV['SMTP_USER'] ?? 'no-reply@example.com', $_ENV['SMTP_FROM_NAME'] ?? APP_BRAND_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Mail send failed: ' . $mail->ErrorInfo);
        return false;
    }
}

function generate_otp(): string
{
    // Local testing convenience: if APP_DEBUG_FIXED_OTP is set in .env (e.g. "123456"),
    // every OTP issued becomes that value so you don't need SMTP working yet.
    // NEVER set this in a real/production .env — it makes every OTP guessable.
    $fixed = $_ENV['APP_DEBUG_FIXED_OTP'] ?? '';
    if (preg_match('/^\d{6}$/', $fixed)) {
        return $fixed;
    }

    // 6-digit numeric OTP, cryptographically random
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Creates and emails an OTP for a given purpose ('register', 'developer_access', 'login_2fa').
 * Stores only the hash in the DB. Returns true if the email was sent.
 */
function issue_otp(PDO $pdo, string $email, string $purpose, string $recipientName = ''): bool
{
    $otp = generate_otp();
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = (new DateTime("+" . OTP_EXPIRY_MINUTES . " minutes"))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO otp_codes (email, purpose, otp_hash, max_attempts, expires_at) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$email, $purpose, $otpHash, OTP_MAX_ATTEMPTS, $expiresAt]);

    $subject = APP_BRAND_NAME . ' — Your verification code';
    $body = "<p>Hi " . e($recipientName ?: $email) . ",</p>"
        . "<p>Your verification code is:</p>"
        . "<h2 style='letter-spacing:4px'>{$otp}</h2>"
        . "<p>This code expires in " . OTP_EXPIRY_MINUTES . " minutes. If you did not request this, you can ignore this email.</p>";

    return send_mail($email, $recipientName ?: $email, $subject, $body);
}

/**
 * Verifies the most recent unconsumed OTP for email+purpose against user input.
 * Returns 'ok' | 'expired' | 'invalid' | 'too_many_attempts' | 'not_found'.
 */
function verify_otp(PDO $pdo, string $email, string $purpose, string $inputOtp): string
{
    $stmt = $pdo->prepare(
        'SELECT * FROM otp_codes WHERE email = ? AND purpose = ? AND consumed = 0 ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$email, $purpose]);
    $row = $stmt->fetch();

    if (!$row) {
        return 'not_found';
    }
    if ($row['attempts'] >= $row['max_attempts']) {
        return 'too_many_attempts';
    }
    if (strtotime($row['expires_at']) < time()) {
        return 'expired';
    }

    $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);

    if (!password_verify($inputOtp, $row['otp_hash'])) {
        return 'invalid';
    }

    $pdo->prepare('UPDATE otp_codes SET consumed = 1 WHERE id = ?')->execute([$row['id']]);
    return 'ok';
}
