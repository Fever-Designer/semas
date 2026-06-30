<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();
header('Content-Type: application/json');

$db = Database::connection();
$me = Auth::user();
$sessionId = (int) ($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
$mode = $_GET['mode'] ?? $_POST['mode'] ?? '';

$sessStmt = $db->prepare(
    "SELECT cs.*, m.module_id, m.module_title, m.department_id, m.lecturer_id, l.user_id AS lecturer_user_id
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

/** Only students REGISTERED for this module are eligible for class attendance. */
function class_student_payload(PDO $db, array $session, int $userId): array
{
    $stmt = $db->prepare(
        "SELECT u.*, d.department_name FROM users u
         LEFT JOIN departments d ON d.department_id = u.department_id
         JOIN roles r ON r.role_id = u.role_id
         JOIN module_enrollments me ON me.user_id = u.user_id AND me.module_id = :mid
         WHERE u.user_id = :id AND r.role_name = 'Student'"
    );
    $stmt->execute(['mid' => $session['module_id'], 'id' => $userId]);
    $student = $stmt->fetch();
    if (!$student) {
        return ['ok' => false, 'message' => 'This student is not registered for "' . $session['module_title'] . '".'];
    }

    $already = $db->prepare(
        "SELECT attendance_id, checkin_time, status, verification_method FROM class_attendance_logs
         WHERE session_id = :s AND user_id = :u AND attendance_type = 'Sign In'"
    );
    $already->execute(['s' => $session['session_id'], 'u' => $userId]);
    $existing = $already->fetch();
    // Auto-populated Absent placeholders don't count as "already marked".
    $reallyMarked = $existing && $existing['verification_method'] !== 'Auto';

    return [
        'ok' => true,
        'student' => [
            'user_id'    => (int) $student['user_id'],
            'full_name'  => $student['full_name'],
            'reg_number' => $student['reg_number'],
            'department' => $student['department_name'],
            'photo_url'  => $student['photo_path'] ? APP_URL . '/' . $student['photo_path'] : 'https://ui-avatars.com/api/?name=' . urlencode($student['full_name']) . '&background=1E2A52&color=fff',
        ],
        'already_marked' => $reallyMarked,
        'status' => $reallyMarked ? $existing['status'] : null,
        'checkin_time' => $reallyMarked ? $existing['checkin_time'] : null,
    ];
}

if ($mode === 'search') {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }
    $stmt = $db->prepare(
        "SELECT u.user_id, u.full_name, u.reg_number FROM users u
         JOIN roles r ON r.role_id = u.role_id
         JOIN module_enrollments me ON me.user_id = u.user_id AND me.module_id = :mid
         WHERE r.role_name = 'Student' AND (u.full_name LIKE :q OR u.reg_number LIKE :q)
         LIMIT 10"
    );
    $stmt->execute(['mid' => $session['module_id'], 'q' => "%$q%"]);
    echo json_encode(['ok' => true, 'results' => $stmt->fetchAll()]);
    exit;
}

if ($mode === 'select') {
    echo json_encode(class_student_payload($db, $session, (int) ($_GET['user_id'] ?? 0)));
    exit;
}

if ($mode === 'qr') {
    // Lecturer scans the STUDENT's own personal QR (the same one shown on student/my-qr.php),
    // decoded with the global APP_KEY — this is "Method 2" for class attendance, mirroring how
    // admin/scan-student.php scans a student's personal QR for event attendance.
    $token = $_POST['token'] ?? $_GET['token'] ?? '';
    $key = hash('sha256', APP_KEY !== '' ? APP_KEY : 'fallback-key-change-me', true);
    $secret = APP_KEY !== '' ? APP_KEY : 'fallback-key-change-me';

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        echo json_encode(['ok' => false, 'message' => 'Invalid QR code.']);
        exit;
    }
    $b64url_decode = function (string $b64) {
        $b64 = strtr($b64, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) $b64 .= str_repeat('=', 4 - $pad);
        return base64_decode($b64, true);
    };
    [$ivB64, $cipherB64, $hmacB64] = $parts;
    $iv = $b64url_decode($ivB64);
    $cipher = $b64url_decode($cipherB64);
    $hmac = $b64url_decode($hmacB64);
    if ($iv === false || $cipher === false || $hmac === false) {
        echo json_encode(['ok' => false, 'message' => 'Malformed QR code.']);
        exit;
    }
    $expectedHmac = hash_hmac('sha256', $iv . $cipher, $secret, true);
    if (!hash_equals($expectedHmac, $hmac)) {
        echo json_encode(['ok' => false, 'message' => 'QR signature invalid / possible tampering.']);
        exit;
    }
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    $data = json_decode((string) $plain, true);
    if (!is_array($data) || empty($data['user_id']) || (int) $data['exp'] < time()) {
        echo json_encode(['ok' => false, 'message' => 'This QR code is invalid or has expired. Ask the student to reload their QR page.']);
        exit;
    }
    echo json_encode(class_student_payload($db, $session, (int) $data['user_id']));
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown preview mode.']);
