<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}
csrf_verify();

$db = Database::connection();
Semester::enforceAcademicWrite($db);
$me = Auth::user();
$sessionId     = (int) $_POST['session_id'];
$studentUserId = (int) $_POST['user_id'];
$method        = ($_POST['method'] ?? 'manual') === 'qr' ? 'QR' : 'Manual';

$sessStmt = $db->prepare(
    "SELECT cs.*, l.user_id AS lecturer_user_id, m.module_title, m.module_id,
            m.start_date, m.end_date
     FROM class_sessions cs
     JOIN modules m ON m.module_id = cs.module_id
     JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     WHERE cs.session_id = :id"
);
$sessStmt->execute(['id' => $sessionId]);
$session = $sessStmt->fetch();

if (!$session || (int) $session['lecturer_user_id'] !== (int) $me['user_id']) {
    echo json_encode(['ok' => false, 'message' => 'Class session not found, or it is not yours.']);
    exit;
}
if ($rangeError = ClassAttendance::moduleDateRangeError($session, (string) $session['session_date'])) {
    echo json_encode(['ok' => false, 'message' => $rangeError]);
    exit;
}
if ($session['status'] !== 'Open') {
    echo json_encode(['ok' => false, 'message' => 'This class session has already ended.']);
    exit;
}
if (!ClassAttendance::isLecturerAttendanceOpen((string) $session['start_time'], (string) $session['end_time'])) {
    $db->prepare("UPDATE class_sessions SET status='Closed' WHERE session_id=:id")->execute(['id' => $sessionId]);
    echo json_encode(['ok' => false, 'message' => 'This session\'s attendance window has closed.']);
    exit;
}

$enrolled = $db->prepare('SELECT 1 FROM module_enrollments WHERE module_id = :m AND user_id = :u');
$enrolled->execute(['m' => $session['module_id'], 'u' => $studentUserId]);
if (!$enrolled->fetch()) {
    echo json_encode(['ok' => false, 'message' => 'This student is not registered for this module.']);
    exit;
}

$existing = $db->prepare(
    "SELECT attendance_id, verification_method FROM class_attendance_logs
     WHERE session_id = :s AND user_id = :u AND attendance_type = 'Sign In'"
);
$existing->execute(['s' => $sessionId, 'u' => $studentUserId]);
$existingRow = $existing->fetch();
if ($existingRow && in_array((string) $existingRow['verification_method'], ['QR', 'Manual'], true)) {
    echo json_encode(['ok' => false, 'message' => 'This student is already marked for this session.']);
    exit;
}

$status = $method === 'Manual' ? 'Present' : ClassAttendance::statusFor($session['start_time']);
if ($method !== 'Manual' && $status === 'Absent') {
    $status = 'Late';
}

try {
    if ($existingRow) {
        $db->prepare(
            "UPDATE class_attendance_logs SET status=:status, verification_method=:method, confirmed_by=:confirmed_by, checkin_time=NOW()
             WHERE session_id=:s AND user_id=:u AND attendance_type='Sign In'
               AND verification_method NOT IN ('QR','Manual')"
        )->execute(['status' => $status, 'method' => $method, 'confirmed_by' => Auth::id(), 's' => $sessionId, 'u' => $studentUserId]);
    } else {
        $db->prepare(
            'INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method, confirmed_by)
             VALUES (:s, :u, \'Sign In\', :status, :method, :confirmed_by)'
        )->execute(['s' => $sessionId, 'u' => $studentUserId, 'status' => $status, 'method' => $method, 'confirmed_by' => Auth::id()]);
    }

    if ($method === 'Manual') {
        $db->prepare(
            "UPDATE class_attendance_logs
             SET checkin_time = :signin_time
             WHERE session_id = :s AND user_id = :u AND attendance_type = 'Sign In'"
        )->execute(['signin_time' => $session['start_time'], 's' => $sessionId, 'u' => $studentUserId]);

        $db->prepare(
            "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method, confirmed_by, checkin_time)
             VALUES (:s, :u, 'Sign Out', 'Present', 'Manual', :confirmed_by, :signout_time)
             ON DUPLICATE KEY UPDATE status = VALUES(status), verification_method = VALUES(verification_method),
                 confirmed_by = VALUES(confirmed_by), checkin_time = VALUES(checkin_time)"
        )->execute([
            's' => $sessionId,
            'u' => $studentUserId,
            'confirmed_by' => Auth::id(),
            'signout_time' => $session['end_time'],
        ]);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo json_encode(['ok' => false, 'message' => 'This student is already marked for this session.']);
        exit;
    }
    throw $e;
}

AuditLog::record(Auth::id(), 'CLASS_ATTENDANCE_STAFF_CONFIRMED', 'class_sessions', $sessionId, "student_user_id=$studentUserId;status=$status");

$studentStmt = $db->prepare('SELECT * FROM users WHERE user_id = :id');
$studentStmt->execute(['id' => $studentUserId]);
$student = $studentStmt->fetch();

NotificationCenter::notify($studentUserId, 'Class attendance recorded', 'You were marked ' . $status . ' for "' . $session['module_title'] . '".', 'Attendance');

// ── Absence warning system ────────────────────────────────────────────────
if ($status === 'Absent' && $student) {
    $moduleStmt = $db->prepare('SELECT * FROM modules WHERE module_id = :id');
    $moduleStmt->execute(['id' => $session['module_id']]);
    $module = $moduleStmt->fetch();
    if ($module) {
        $absStmt = $db->prepare(
            "SELECT COUNT(DISTINCT cs.session_id)
             FROM class_sessions cs
             JOIN class_attendance_logs cal ON cal.session_id = cs.session_id
                 AND cal.user_id = :uid AND cal.attendance_type = 'Sign In' AND cal.status = 'Absent'
                 AND cal.verification_method IN ('QR','Manual')
             WHERE cs.module_id = :mid"
        );
        $absStmt->execute(['uid' => $studentUserId, 'mid' => $session['module_id']]);
        $absences = (int) $absStmt->fetchColumn();

        if ($absences === 2) {
            NotificationCenter::notify(
                $studentUserId,
                'Attendance Warning / ' . $module['module_title'],
                'You have missed 2 sessions of "' . $module['module_title'] . '". Missing a third session may affect your CAT/Exam eligibility. Please contact your Head Of Department if you have a valid reason.',
                'Attendance'
            );
        } elseif ($absences >= 3) {
            NotificationCenter::notify(
                $studentUserId,
                'Attendance Alert / ' . $module['module_title'],
                'You have missed ' . $absences . ' sessions of "' . $module['module_title'] . '". You may be marked ineligible for CAT/Exam. Contact your Head Of Department immediately.',
                'Attendance'
            );
            if (!empty($student['email'])) {
                Mailer::send($student['email'], 'Attendance Warning / ' . $module['module_title'], 'announcement_notification', [
                    'full_name'    => $student['full_name'],
                    'announcement' => [
                        'title'    => 'Attendance Warning / ' . $module['module_title'],
                        'category' => 'Academic',
                        'message'  => 'You have missed ' . $absences . ' sessions of "' . $module['module_title'] . '". You may be marked ineligible for the CAT/Exam. Please contact your Head of Department immediately.',
                    ],
                ], (int) $studentUserId);
            }
        }
    }
}

echo json_encode(['ok' => true, 'message' => $student['full_name'] . ' marked ' . $status . '.']);
