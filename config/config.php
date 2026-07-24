<?php
/**
 * config/config.php
 * Loads settings from a .env file (never commit .env to version control)
 * and exposes them as PHP constants. Copy .env.example to .env and fill
 * in your real credentials before running the app.
 */

declare(strict_types=1);

function semas_load_env(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || substr($line, 0, 1) === '#') {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2) + [1 => '']);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

semas_load_env(__DIR__ . '/../.env');

function env(string $key, $default = null)
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

// ---------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int) env('DB_PORT', 3306));
define('DB_NAME', env('DB_NAME', 'semas'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------
// App
// ---------------------------------------------------------------------
define('APP_NAME', 'SEMAS');
define('APP_URL', env('APP_URL', 'https://localhost/semas/public'));
define('APP_ENV', env('APP_ENV', 'local'));               // local | production
define('APP_KEY', env('APP_KEY', ''));                     // 32+ random bytes, used for HMAC fallback
define(
    'LOGIN_OTP_ENABLED',
    filter_var(env('LOGIN_OTP_ENABLED', APP_ENV === 'production' ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN)
);
define('SESSION_IDLE_TIMEOUT_SECONDS', (int) env('SESSION_IDLE_TIMEOUT_SECONDS', 1800));
define('SESSION_ABSOLUTE_TIMEOUT_SECONDS', (int) env('SESSION_ABSOLUTE_TIMEOUT_SECONDS', 43200));

// ---------------------------------------------------------------------
// Mail (PHPMailer) / pick ONE provider profile in .env: gmail | outlook | university
// ---------------------------------------------------------------------
define('MAIL_HOST', env('MAIL_HOST', 'smtp.gmail.com'));
define('MAIL_PORT', (int) env('MAIL_PORT', 587));
define('MAIL_ENCRYPTION', env('MAIL_ENCRYPTION', 'tls'));   // tls | ssl
define('MAIL_USERNAME', env('MAIL_USERNAME', ''));
define('MAIL_PASSWORD', env('MAIL_PASSWORD', ''));          // Gmail: use an App Password, not your login password
define('MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'noreply@uok.ac.rw'));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'UNIVERSITY / SEMAS'));

// ---------------------------------------------------------------------
// SMS / pick ONE provider in .env: vonage | africastalking
// ---------------------------------------------------------------------
define('SMS_PROVIDER', env('SMS_PROVIDER', 'vonage'));
// Twilio (twilio.com) credentials
define('TWILIO_SID',           env('TWILIO_SID',           ''));
define('TWILIO_TOKEN',         env('TWILIO_TOKEN',         ''));
define('TWILIO_FROM_NUMBER',   env('TWILIO_FROM_NUMBER',   ''));
define('TWILIO_WHATSAPP_FROM', env('TWILIO_WHATSAPP_FROM', ''));   // e.g. whatsapp:+14155238886
// Vonage (nexmo.com) credentials / shared by SMS and WhatsApp
define('VONAGE_API_KEY',       env('VONAGE_API_KEY',       ''));
define('VONAGE_API_SECRET',    env('VONAGE_API_SECRET',    ''));
define('VONAGE_FROM',          env('VONAGE_FROM',          ''));   // SMS: number or alphanumeric sender ID
define('VONAGE_WHATSAPP_FROM', env('VONAGE_WHATSAPP_FROM', ''));   // WhatsApp Business number (E.164 digits, no +)
// Africa's Talking credentials (fallback provider)
define('AT_USERNAME',  env('AT_USERNAME',  ''));
define('AT_API_KEY',   env('AT_API_KEY',   ''));
define('AT_SHORTCODE', env('AT_SHORTCODE', 'SEMAS'));

// ---------------------------------------------------------------------
// Security / OTP / GPS defaults (overridable per-row in system_settings table)
// ---------------------------------------------------------------------
define('OTP_LENGTH', 6);
define('OTP_DEFAULT_EXPIRY_MINUTES', 5);
define('OTP_MAX_ATTEMPTS', 5);
define('VERIFY_LINK_EXPIRY_HOURS', 24);
define('RESET_LINK_EXPIRY_MINUTES', 30);
define('DEFAULT_CAMPUS_LAT', -1.953600);
define('DEFAULT_CAMPUS_LNG', 30.094700);
define('DEFAULT_CAMPUS_RADIUS_M', 300);

error_reporting(APP_ENV === 'local' ? E_ALL : 0);
ini_set('display_errors', APP_ENV === 'local' ? '1' : '0');
date_default_timezone_set('Africa/Kigali');

if (APP_ENV === 'production') {
    if (strlen(APP_KEY) < 32) {
        throw new RuntimeException('Production requires an APP_KEY of at least 32 characters.');
    }
    if (!str_starts_with(strtolower(APP_URL), 'https://')) {
        throw new RuntimeException('Production requires an HTTPS APP_URL.');
    }
    if (!LOGIN_OTP_ENABLED) {
        throw new RuntimeException('Production requires LOGIN_OTP_ENABLED=true.');
    }
}
