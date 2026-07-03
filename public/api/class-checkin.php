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

$sessionId = (int) ($input['session_id'] ?? 0);
$token = (string) ($input['token'] ?? '');

if (!$sessionId || $token === '') {
    echo json_encode(['ok' => false, 'message' => 'Missing scan data. Please try scanning again.']);
    exit;
}

$db = Database::connection();
$stmt = $db->prepare(
    "SELECT cs.*, m.module_title, m.department_id FROM class_sessions cs
     JOIN modules m ON m.module_id = cs.module_id WHERE cs.session_id = :id"
);
$stmt->execute(['id' => $sessionId]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['ok' => false, 'message' => 'Class session not found.']);
    exit;
}
if ($session['status'] !== 'Open') {
    echo json_encode(['ok' => false, 'message' => 'This class session has ended.']);
    exit;
}

$verify = QrService::verifySessionPayload($token, $session['qr_secret']);
if (!$verify['ok'] || (int) $verify['data']['session_id'] !== $sessionId) {
    echo json_encode(['ok' => false, 'message' => $verify['error'] ?? 'Invalid QR code.']);
    exit;
}

$userId = Auth::id();
$userStmt = $db->prepare('SELECT * FROM users WHERE user_id = :id');
$userStmt->execute(['id' => $userId]);
$user = $userStmt->fetch();

if ((int) $user['department_id'] !== (int) $session['department_id']) {
    echo json_encode(['ok' => false, 'message' => 'This class session is not for your department.']);
    exit;
}

$existing = $db->prepare('SELECT attendance_id FROM class_attendance_logs WHERE session_id = :s AND user_id = :u');
$existing->execute(['s' => $sessionId, 'u' => $userId]);
if ($existing->fetch()) {
    echo json_encode(['ok' => false, 'message' => 'You have already checked in to this class session.']);
    exit;
}

if (!ClassAttendance::canSelfScan($session['start_time'])) {
    echo json_encode(['ok' => false, 'message' => 'Scanning has closed for this session (more than 25 minutes have passed). Ask your lecturer to mark you manually if needed.']);
    exit;
}

$status = ClassAttendance::statusFor($session['start_time']);

try {
    $db->prepare(
        'INSERT INTO class_attendance_logs (session_id, user_id, status, verification_method)
         VALUES (:s, :u, :status, :method)'
    )->execute(['s' => $sessionId, 'u' => $userId, 'status' => $status, 'method' => 'QR']);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo json_encode(['ok' => false, 'message' => 'You have already checked in to this class session.']);
        exit;
    }
    throw $e;
}

AuditLog::record($userId, 'CLASS_ATTENDANCE_RECORDED', 'class_sessions', $sessionId, "status=$status");
NotificationCenter::notify($userId, 'Class attendance confirmed', 'You were marked ' . $status . ' for "' . $session['module_title'] . '" at ' . date('H:i') . '.', 'Attendance');

echo json_encode(['ok' => true, 'message' => 'You have been marked ' . $status . ' for ' . $session['module_title'] . '.']);
