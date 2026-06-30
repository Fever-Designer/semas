<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Kigali');

require_once __DIR__ . '/../vendor/autoload.php';   // composer install required — see README
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/AuditLog.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Otp.php';
require_once __DIR__ . '/../includes/GpsService.php';
require_once __DIR__ . '/../includes/QrService.php';
require_once __DIR__ . '/../includes/Mailer.php';
require_once __DIR__ . '/../includes/Sms.php';
require_once __DIR__ . '/../includes/WhatsApp.php';
require_once __DIR__ . '/../includes/NotificationGenerator.php';
require_once __DIR__ . '/../includes/ReportQuery.php';
require_once __DIR__ . '/../includes/ReportScope.php';
require_once __DIR__ . '/../includes/NotificationCenter.php';
require_once __DIR__ . '/../includes/AudienceResolver.php';
require_once __DIR__ . '/../includes/Delivery.php';
require_once __DIR__ . '/../includes/Suggestion.php';
require_once __DIR__ . '/../includes/Announcement.php';
require_once __DIR__ . '/../includes/ClassAttendance.php';
require_once __DIR__ . '/../includes/Settings.php';
require_once __DIR__ . '/../includes/Module.php';
require_once __DIR__ . '/../includes/Eligibility.php';
require_once __DIR__ . '/../includes/IntakeHelper.php';
require_once __DIR__ . '/../includes/AttendanceSheet.php';

Auth::start();
Auth::enforceMustChangePassword();

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

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        // AJAX/API endpoints set Content-Type: application/json before calling
        // csrf_verify() — match that so fetch().then(r => r.json()) can parse
        // the failure instead of throwing a SyntaxError that the caller's
        // .catch() swallows silently (leaving the UI stuck, e.g. a "Looking
        // up student…" spinner that never resolves).
        $isJson = false;
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'application/json') !== false) {
                $isJson = true;
                break;
            }
        }
        if ($isJson) {
            die(json_encode(['ok' => false, 'message' => 'Session expired or invalid request token. Please refresh the page and try again.']));
        }
        die('Session expired or invalid request token. Please go back and try again.');
    }
}

function e($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    // Strip .php extension so PHP redirects produce clean URLs
    $path = preg_replace('/\.php(?=\?|#|$)/', '', $path);
    header('Location: ' . APP_URL . $path);
    exit;
}

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
