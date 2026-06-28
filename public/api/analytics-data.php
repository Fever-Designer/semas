<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Administrator', 'Dean', 'HOD', 'Lecturer']);
header('Content-Type: application/json');

$db = Database::connection();
$me = Auth::user();
$role = Auth::role();
$out = ['ok' => true, 'role' => $role, 'generated_at' => date('H:i:s')];

if ($role === 'Administrator') {
    // Administrator's scope is USER MANAGEMENT + SYSTEM CONFIG ONLY — no academic
    // or event/attendance data here by design (see README increment on HOD centralization).
    $stmt = $db->query("SELECT r.role_name, COUNT(*) AS c FROM users u JOIN roles r ON r.role_id = u.role_id GROUP BY r.role_name");
    $out['users_by_role'] = $stmt->fetchAll();

    $stmt = $db->query("SELECT status, COUNT(*) AS c FROM users GROUP BY status");
    $out['users_by_status'] = $stmt->fetchAll();

    $stmt = $db->query(
        "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM users
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at) ORDER BY d"
    );
    $out['signups_trend'] = $stmt->fetchAll();

} elseif ($role === 'Dean') {
    $stmt = $db->query(
        "SELECT status, COUNT(*) AS c FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Student' GROUP BY status"
    );
    $out['student_status'] = $stmt->fetchAll();

    $stmt = $db->query(
        "SELECT DATE(checkin_time) AS d, COUNT(*) AS c FROM attendance_logs
         WHERE checkin_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(checkin_time) ORDER BY d"
    );
    $out['event_attendance_trend'] = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT COUNT(*) FROM announcements WHERE posted_by = :uid AND posted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute(['uid' => $me['user_id']]);
    $out['my_announcements_30d'] = (int) $stmt->fetchColumn();

} elseif ($role === 'HOD') {
    // HOD is the central academic authority across EVERY department now — no
    // department_id filter on any of these (see README increment on HOD centralization).
    $stmt = $db->query("SELECT status, COUNT(*) AS c FROM modules GROUP BY status");
    $out['modules_by_status'] = $stmt->fetchAll();

    $stmt = $db->query(
        "SELECT d.department_name, COUNT(m.module_id) AS c FROM departments d
         LEFT JOIN modules m ON m.department_id = d.department_id GROUP BY d.department_id ORDER BY c DESC LIMIT 10"
    );
    $out['modules_by_department'] = $stmt->fetchAll();

    $stmt = $db->query("SELECT status, COUNT(*) AS c FROM class_attendance_logs WHERE attendance_type = 'Sign In' GROUP BY status");
    $out['class_attendance_status'] = $stmt->fetchAll();

    $stmt = $db->query(
        "SELECT status, COUNT(*) AS c FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Student' GROUP BY status"
    );
    $out['student_status'] = $stmt->fetchAll();

} elseif ($role === 'Lecturer') {
    $lecStmt = $db->prepare('SELECT lecturer_id FROM lecturers WHERE user_id = :uid');
    $lecStmt->execute(['uid' => $me['user_id']]);
    $lecturerId = (int) $lecStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT cal.status, COUNT(*) AS c FROM class_attendance_logs cal
         JOIN class_sessions cs ON cs.session_id = cal.session_id
         JOIN modules m ON m.module_id = cs.module_id
         WHERE m.lecturer_id = :lec AND cal.attendance_type = 'Sign In' GROUP BY cal.status"
    );
    $stmt->execute(['lec' => $lecturerId]);
    $out['class_attendance_status'] = $stmt->fetchAll();

    $stmt = $db->prepare(
        "SELECT m.module_title, COUNT(cs.session_id) AS sessions FROM modules m
         LEFT JOIN class_sessions cs ON cs.module_id = m.module_id
         WHERE m.lecturer_id = :lec GROUP BY m.module_id ORDER BY sessions DESC"
    );
    $stmt->execute(['lec' => $lecturerId]);
    $out['sessions_by_module'] = $stmt->fetchAll();

    $stmt = $db->prepare(
        "SELECT DATE(cs.start_time) AS d, COUNT(*) AS c FROM class_sessions cs
         JOIN modules m ON m.module_id = cs.module_id
         WHERE m.lecturer_id = :lec AND cs.start_time >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
         GROUP BY DATE(cs.start_time) ORDER BY d"
    );
    $stmt->execute(['lec' => $lecturerId]);
    $out['sessions_trend'] = $stmt->fetchAll();
}

echo json_encode($out);
