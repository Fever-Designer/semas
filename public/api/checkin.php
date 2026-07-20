<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json');

if (!Auth::check() || Auth::role() !== 'Student') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'You must be logged in as a student to check in.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($input['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Invalid session token. Please refresh and try again.']);
    exit;
}

$eventId = (int) ($input['event_id'] ?? 0);
$token = (string) ($input['token'] ?? '');

if (!$eventId || $token === '') {
    echo json_encode(['ok' => false, 'message' => 'Missing scan data. Please try scanning again.']);
    exit;
}

$db = Database::connection();
EventLifecycle::sync($db);
$stmt = $db->prepare('SELECT * FROM events WHERE event_id = :id');
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

if (!$event) {
    echo json_encode(['ok' => false, 'message' => 'Event not found.']);
    exit;
}

if ($event['status'] !== 'Ongoing') {
    echo json_encode(['ok' => false, 'message' => 'Attendance can only be marked while this event is ongoing.']);
    exit;
}

$userId = Auth::id();
$registration = $db->prepare(
    "SELECT registration_id FROM event_registrations
     WHERE event_id = :e AND user_id = :u AND status = 'Registered'"
);
$registration->execute(['e' => $eventId, 'u' => $userId]);
if (!$registration->fetchColumn()) {
    echo json_encode(['ok' => false, 'message' => 'You are not registered for this event.']);
    exit;
}

// 1. Verify the HMAC-signed, encrypted QR payload (anti-forgery + anti-tamper).
$verify = QrService::verifyPayload($token, $event['qr_secret']);
if (!$verify['ok'] || (int) $verify['data']['event_id'] !== $eventId) {
    echo json_encode(['ok' => false, 'message' => $verify['error'] ?? 'Invalid QR code.']);
    exit;
}

// 2. Anti-duplicate / the UNIQUE(event_id, user_id) constraint is the real guarantee;
//    we also pre-check so we can return a friendly message instead of a raw SQL error.
$existing = $db->prepare('SELECT attendance_id FROM attendance_logs WHERE event_id = :e AND user_id = :u');
$existing->execute(['e' => $eventId, 'u' => $userId]);
if ($existing->fetch()) {
    echo json_encode(['ok' => false, 'message' => 'You have already checked in to this event.']);
    exit;
}

try {
    $db->prepare(
        'INSERT INTO attendance_logs (event_id, user_id, verification_method, device_info)
         VALUES (:e, :u, :method, :device)'
    )->execute([
        'e' => $eventId, 'u' => $userId, 'method' => 'QR',
        'device' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') { // unique constraint violation race condition
        echo json_encode(['ok' => false, 'message' => 'You have already checked in to this event.']);
        exit;
    }
    throw $e;
}

AuditLog::record($userId, 'ATTENDANCE_RECORDED', 'events', $eventId);

$userStmt = $db->prepare('SELECT * FROM users WHERE user_id = :id');
$userStmt->execute(['id' => $userId]);
$user = $userStmt->fetch();
Mailer::sendAttendanceConfirmation($user, $event, date('Y-m-d H:i:s'));
NotificationCenter::notify($userId, 'Attendance confirmed', 'You checked in to ' . $event['title'] . ' at ' . date('H:i') . '.', 'Attendance');
if ($user['sms_opt_in'] && $user['phone_number']) {
    Sms::send($user['phone_number'], 'SEMAS: attendance confirmed for ' . $event['title'] . ' at ' . date('H:i'), $userId);
}

echo json_encode(['ok' => true, 'message' => 'You have been checked in to ' . $event['title'] . '.']);
