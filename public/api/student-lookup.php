<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json');

if (!Auth::check() || !in_array(Auth::role(), ['HOD', 'Coordinator', 'Principal', 'Dean'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden']);
    exit;
}

$regNum = trim($_GET['reg_number'] ?? '');
if (!$regNum) {
    echo json_encode(['ok' => false, 'message' => 'reg_number required']);
    exit;
}

$db   = Database::connection();
$stmt = $db->prepare(
    "SELECT u.user_id, u.full_name, u.reg_number, u.email, u.photo_path,
            u.status, u.intake, u.session_type, u.year_of_study,
            d.department_name, d.department_code
     FROM users u
     JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN departments d ON d.department_id = u.department_id
     WHERE r.role_name = 'Student' AND u.reg_number = :rn"
);
$stmt->execute(['rn' => $regNum]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo json_encode(['ok' => false, 'message' => "No student found with registration number: {$regNum}"]);
    exit;
}

if ($student['status'] !== 'Active') {
    echo json_encode(['ok' => false, 'message' => "Student account is {$student['status']}. Cannot enroll."]);
    exit;
}

$photoUrl = $student['photo_path']
    ? APP_URL . '/' . $student['photo_path']
    : 'https://ui-avatars.com/api/?name=' . urlencode($student['full_name']) . '&background=1E2A52&color=fff&size=80';

echo json_encode([
    'ok'      => true,
    'student' => [
        'user_id'         => (int) $student['user_id'],
        'full_name'       => $student['full_name'],
        'reg_number'      => $student['reg_number'],
        'email'           => $student['email'],
        'department'      => $student['department_name'] ?? '—',
        'department_code' => $student['department_code'] ?? '',
        'session_type'    => $student['session_type'] ?? '—',
        'intake'          => $student['intake'] ?? '—',
        'year_of_study'   => $student['year_of_study'] ?? '—',
        'photo_url'       => $photoUrl,
    ],
]);
