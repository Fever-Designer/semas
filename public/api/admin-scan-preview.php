<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Administrator', 'Dean', 'HOD']);
header('Content-Type: application/json');

$db = Database::connection();
$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$mode = $_GET['mode'] ?? $_POST['mode'] ?? '';

$evStmt = $db->prepare('SELECT * FROM events WHERE event_id = :id');
$evStmt->execute(['id' => $eventId]);
$event = $evStmt->fetch();
if (!$event) {
    echo json_encode(['ok' => false, 'message' => 'Event not found.']);
    exit;
}

function student_payload(PDO $db, array $event, int $userId): array
{
    $stmt = $db->prepare(
        "SELECT u.*, d.department_name, f.faculty_name FROM users u
         LEFT JOIN departments d ON d.department_id = u.department_id
         LEFT JOIN faculties f ON f.faculty_id = d.faculty_id
         JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = :id AND r.role_name = 'Student'"
    );
    $stmt->execute(['id' => $userId]);
    $student = $stmt->fetch();
    if (!$student) {
        return ['ok' => false, 'message' => 'Student not found.'];
    }

    $already = $db->prepare('SELECT attendance_id, checkin_time FROM attendance_logs WHERE event_id = :e AND user_id = :u');
    $already->execute(['e' => $event['event_id'], 'u' => $userId]);
    $existing = $already->fetch();

    return [
        'ok' => true,
        'student' => [
            'user_id'       => (int) $student['user_id'],
            'full_name'     => $student['full_name'],
            'reg_number'    => $student['reg_number'],
            'department'    => $student['department_name'],
            'faculty'       => $student['faculty_name'],
            'session_type'  => $student['session_type'],
            'photo_url'     => $student['photo_path'] ? APP_URL . '/' . $student['photo_path'] : 'https://ui-avatars.com/api/?name=' . urlencode($student['full_name']) . '&background=1E2A52&color=fff',
        ],
        'already_marked' => (bool) $existing,
        'checkin_time'   => $existing['checkin_time'] ?? null,
        'event_title'    => $event['title'],
    ];
}

if ($mode === 'qr') {
    // Decode the student's PERSONAL QR (built the same way as student/my-qr.php).
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
        echo json_encode(['ok' => false, 'message' => 'QR signature invalid — possible tampering.']);
        exit;
    }
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    $data = json_decode((string) $plain, true);
    if (!is_array($data) || empty($data['user_id']) || (int) $data['exp'] < time()) {
        echo json_encode(['ok' => false, 'message' => 'This QR code is invalid or has expired. Ask the student to reload their QR page.']);
        exit;
    }

    echo json_encode(student_payload($db, $event, (int) $data['user_id']));
    exit;
}

if ($mode === 'search') {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }
    $stmt = $db->prepare(
        "SELECT u.user_id, u.full_name, u.reg_number FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE r.role_name = 'Student' AND (u.full_name LIKE :q OR u.reg_number LIKE :q)
         LIMIT 10"
    );
    $stmt->execute(['q' => "%$q%"]);
    echo json_encode(['ok' => true, 'results' => $stmt->fetchAll()]);
    exit;
}

if ($mode === 'select') {
    echo json_encode(student_payload($db, $event, (int) ($_GET['user_id'] ?? 0)));
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown preview mode.']);
