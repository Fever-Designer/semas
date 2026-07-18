<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Dean']);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}
csrf_verify();

$db = Database::connection();
Semester::enforceAcademicWrite($db);
$eventId = (int) $_POST['event_id'];
$studentUserId = (int) $_POST['user_id'];
$method = $_POST['method'] === 'manual' ? 'Manual' : 'StaffScan';

$evStmt = $db->prepare('SELECT * FROM events WHERE event_id = :id');
$evStmt->execute(['id' => $eventId]);
$event = $evStmt->fetch();
if (!$event) {
    echo json_encode(['ok' => false, 'message' => 'Event not found.']);
    exit;
}

// Attendance window: only allow marking from 30 minutes before start to event end (defense in depth;
// admin can still override by editing event times if a genuine late arrival needs recording).
$windowStart = strtotime($event['event_date'] . ' ' . $event['start_time']) - 1800;
$windowEnd = strtotime($event['event_date'] . ' ' . $event['end_time']) + 1800;
if (time() < $windowStart || time() > $windowEnd) {
    echo json_encode(['ok' => false, 'message' => 'Outside the attendance window for this event (30 min before start to 30 min after end).']);
    exit;
}

$existing = $db->prepare('SELECT attendance_id FROM attendance_logs WHERE event_id = :e AND user_id = :u');
$existing->execute(['e' => $eventId, 'u' => $studentUserId]);
if ($existing->fetch()) {
    echo json_encode(['ok' => false, 'message' => 'This student is already marked present for this event.']);
    exit;
}

try {
    $db->prepare(
        'INSERT INTO attendance_logs (event_id, user_id, verification_method, confirmed_by)
         VALUES (:e, :u, :method, :confirmed_by)'
    )->execute(['e' => $eventId, 'u' => $studentUserId, 'method' => $method, 'confirmed_by' => Auth::id()]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo json_encode(['ok' => false, 'message' => 'This student is already marked present for this event.']);
        exit;
    }
    throw $e;
}

AuditLog::record(Auth::id(), 'ATTENDANCE_STAFF_CONFIRMED', 'events', $eventId, "student_user_id=$studentUserId;method=$method");

$studentStmt = $db->prepare('SELECT * FROM users WHERE user_id = :id');
$studentStmt->execute(['id' => $studentUserId]);
$student = $studentStmt->fetch();

NotificationCenter::notify($studentUserId, 'Attendance confirmed', 'Your attendance was confirmed for "' . $event['title'] . '".', 'Attendance');
Mailer::sendAttendanceConfirmation($student, $event, date('Y-m-d H:i:s'));

echo json_encode(['ok' => true, 'message' => 'Attendance recorded for ' . $student['full_name'] . '.']);
