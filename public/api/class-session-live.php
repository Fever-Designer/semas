<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Lecturer']);
header('Content-Type: application/json');

$db = Database::connection();
$me = Auth::user();
$sessionId = (int) ($_GET['session_id'] ?? 0);

$sessStmt = $db->prepare(
    "SELECT cs.*, l.user_id AS lecturer_user_id
     FROM class_sessions cs JOIN modules m ON m.module_id = cs.module_id JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     WHERE cs.session_id = :id"
);
$sessStmt->execute(['id' => $sessionId]);
$session = $sessStmt->fetch();
if (!$session || (int) $session['lecturer_user_id'] !== (int) $me['user_id']) {
    echo json_encode(['ok' => false, 'message' => 'Not found.']);
    exit;
}

$stmt = $db->prepare(
    "SELECT cal.status, cal.checkin_time, u.full_name, u.reg_number
     FROM class_attendance_logs cal JOIN users u ON u.user_id = cal.user_id
     WHERE cal.session_id = :id AND cal.attendance_type = 'Sign In'
     ORDER BY (cal.status = 'Absent'), cal.checkin_time DESC"
);
$stmt->execute(['id' => $sessionId]);

echo json_encode(['ok' => true, 'roster' => $stmt->fetchAll()]);
