<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json');

if (!Auth::check() || !in_array(Auth::role(), ['Lecturer','HOD','Coordinator'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorised.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}
csrf_verify();

$db     = Database::connection();
$me     = Auth::user();
$action = $_POST['action'] ?? '';

// Ensure dynamic-QR columns exist
foreach (['qr_token VARCHAR(128) NULL', 'qr_token_expires_at DATETIME NULL'] as $colDef) {
    try { $db->exec("ALTER TABLE class_sessions ADD COLUMN $colDef"); }
    catch (PDOException $ce) { if (($ce->errorInfo[1] ?? 0) !== 1060) throw $ce; }
}

function liveqr_rotate(PDO $db, int $sessionId): array
{
    $token = bin2hex(random_bytes(20));
    $db->prepare(
        "UPDATE class_sessions
         SET qr_token = :t, qr_token_expires_at = DATE_ADD(NOW(), INTERVAL 60 SECOND)
         WHERE session_id = :sid"
    )->execute(['t' => $token, 'sid' => $sessionId]);
    $row = $db->prepare("SELECT qr_token, qr_token_expires_at FROM class_sessions WHERE session_id = :sid");
    $row->execute(['sid' => $sessionId]);
    return $row->fetch() ?: ['qr_token' => $token, 'qr_token_expires_at' => date('Y-m-d H:i:s', time() + 60)];
}

function liveqr_payload(int $moduleId, int $sessionId, string $token): array
{
    $raw = "SEMAS:{$moduleId}:{$sessionId}:{$token}";
    return [
        'raw' => $raw,
        'url' => public_url('/student/attendance.php?module_id=' . $moduleId . '&d=' . rawurlencode($raw)),
    ];
}

// ── open_session ──────────────────────────────────────────────────────────
if ($action === 'open_session') {
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    if (!$moduleId) { echo json_encode(['ok' => false, 'message' => 'module_id required.']); exit; }

    $modStmt = $db->prepare(
        "SELECT m.module_id, m.module_title, m.session_type, m.weekend_slot, r.room_name
         FROM modules m
         LEFT JOIN rooms r ON r.room_id = m.room_id
         LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
         WHERE m.module_id = :mid AND m.status = 'Ongoing'
           AND (lt.user_id = :uid OR :role IN ('HOD','Coordinator'))"
    );
    $modStmt->execute(['mid' => $moduleId, 'uid' => $me['user_id'], 'role' => Auth::role()]);
    $module = $modStmt->fetch();
    if (!$module) {
        echo json_encode(['ok' => false, 'message' => 'Module not found or not assigned to you.']);
        exit;
    }

    $window = ClassAttendance::currentWindow();
    $today  = ClassAttendance::now()->format('Y-m-d');
    $slot   = $module['weekend_slot'] ?? '';
    $st     = $module['session_type'] ?? '';

    // The QR "Sign" screen only goes live during the module's actual scan
    // window / lecturers can manage Announcements/Assignments/Attendance
    // records anytime, but students must not be able to scan outside the
    // scheduled session.
    $matchesWindow = false;
    if ($window) {
        if (!$st) {
            $matchesWindow = true;
        } elseif ($st === 'Day' && $window['name'] === 'Day') {
            $matchesWindow = true;
        } elseif ($st === 'Evening' && $window['name'] === 'Evening') {
            $matchesWindow = true;
        } elseif ($st === 'Weekend') {
            if ($slot === 'Morning')        $matchesWindow = in_array($window['name'], ['WeekendMorning', 'UmugandaMorning'], true);
            elseif ($slot === 'Afternoon')  $matchesWindow = in_array($window['name'], ['WeekendAfternoon', 'UmugandaAfternoon'], true);
            else                            $matchesWindow = in_array($window['name'], ['WeekendMorning', 'WeekendAfternoon', 'UmugandaMorning', 'UmugandaAfternoon'], true);
        }
    }
    if (!$matchesWindow) {
        echo json_encode([
            'ok'      => false,
            'message' => $window
                ? 'This module\'s session does not match the currently active window (' . ClassAttendance::describeWindow($window) . ').'
                : 'There is no active scan window right now. The QR sign-in screen is only available during the scheduled session.',
        ]);
        exit;
    }

    $windowName = $window['name'];

    $findStmt = $db->prepare(
        "SELECT * FROM class_sessions
         WHERE module_id = :mid AND session_date = :d AND window_name = :win
         ORDER BY session_id DESC LIMIT 1"
    );
    $findStmt->execute(['mid' => $moduleId, 'd' => $today, 'win' => $windowName]);
    $session = $findStmt->fetch();

    if (!$session) {
        try {
            $db->prepare(
                "INSERT INTO class_sessions (module_id, session_date, window_name, start_time, end_time, status, created_by)
                 VALUES (:mid,:d,:win,:st,:en,'Open',:uid)"
            )->execute([
                'mid' => $moduleId,
                'd' => $today,
                'win' => $windowName,
                'st' => $window['start']->format('Y-m-d H:i:s'),
                'en' => $window['end']->format('Y-m-d H:i:s'),
                'uid' => $me['user_id'],
            ]);
            $newSid = (int) $db->lastInsertId();
            $db->prepare(
                "INSERT IGNORE INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method)
                 SELECT :sid, e.user_id, 'Sign In', 'Absent', 'Auto' FROM module_enrollments e WHERE e.module_id = :mid"
            )->execute(['sid' => $newSid, 'mid' => $moduleId]);
        } catch (PDOException $e) { /* race / session created concurrently */ }
        $findStmt->execute(['mid' => $moduleId, 'd' => $today, 'win' => $windowName]);
        $session = $findStmt->fetch();
    }

    if (!$session) { echo json_encode(['ok' => false, 'message' => 'Could not open session.']); exit; }

    $sessionId = (int) $session['session_id'];
    $tokenRow  = $session;
    if (empty($tokenRow['qr_token']) || empty($tokenRow['qr_token_expires_at']) ||
        strtotime((string) $tokenRow['qr_token_expires_at']) - time() < 5) {
        $tokenRow = liveqr_rotate($db, $sessionId);
    }
    $expiresIn = max(0, strtotime((string) $tokenRow['qr_token_expires_at']) - time());
    $payload = liveqr_payload($moduleId, $sessionId, (string) $tokenRow['qr_token']);

    echo json_encode([
        'ok'         => true,
        'session_id' => $sessionId,
        'module_id'  => $moduleId,
        'module'     => $module['module_title'],
        'room'       => $module['room_name'] ?? '',
        'token'      => $tokenRow['qr_token'],
        'expires_in' => $expiresIn,
        'qr_data'    => $payload['url'],
        'qr_raw'     => $payload['raw'],
    ]);
    exit;
}

// ── refresh ───────────────────────────────────────────────────────────────
if ($action === 'refresh') {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    if (!$sessionId) { echo json_encode(['ok' => false, 'message' => 'session_id required.']); exit; }

    $chk = $db->prepare(
        "SELECT cs.session_id, cs.module_id FROM class_sessions cs
         JOIN modules m ON m.module_id = cs.module_id
         LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
         WHERE cs.session_id = :sid AND (lt.user_id = :uid OR :role IN ('HOD','Coordinator'))"
    );
    $chk->execute(['sid' => $sessionId, 'uid' => $me['user_id'], 'role' => Auth::role()]);
    $row = $chk->fetch();
    if (!$row) { echo json_encode(['ok' => false, 'message' => 'Not authorised.']); exit; }

    $tokenRow  = liveqr_rotate($db, $sessionId);
    $expiresIn = max(0, strtotime((string) $tokenRow['qr_token_expires_at']) - time());
    $modId     = (int) $row['module_id'];
    $payload   = liveqr_payload($modId, $sessionId, (string) $tokenRow['qr_token']);

    echo json_encode([
        'ok'         => true,
        'token'      => $tokenRow['qr_token'],
        'expires_in' => $expiresIn,
        'qr_data'    => $payload['url'],
        'qr_raw'     => $payload['raw'],
    ]);
    exit;
}

// ── status ────────────────────────────────────────────────────────────────
if ($action === 'status') {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    if (!$sessionId) { echo json_encode(['ok' => false, 'message' => 'session_id required.']); exit; }

    $midRow = $db->prepare("SELECT module_id FROM class_sessions WHERE session_id = :sid");
    $midRow->execute(['sid' => $sessionId]);
    $modId = (int) ($midRow->fetchColumn() ?: 0);

    $roster = $db->prepare(
        "SELECT u.full_name, u.reg_number,
                si.status AS signin_status, si.verification_method,
                si.checkin_time AS signin_time,
                so.checkin_time AS signout_time
         FROM module_enrollments e
         JOIN users u ON u.user_id = e.user_id
         LEFT JOIN class_attendance_logs si ON si.session_id=:sid  AND si.user_id=e.user_id AND si.attendance_type='Sign In'
         LEFT JOIN class_attendance_logs so ON so.session_id=:sid2 AND so.user_id=e.user_id AND so.attendance_type='Sign Out'
         WHERE e.module_id = :mid
         ORDER BY u.full_name"
    );
    $roster->execute(['sid' => $sessionId, 'sid2' => $sessionId, 'mid' => $modId]);
    $students = $roster->fetchAll();

    $present = 0; $late = 0; $absent = 0;
    $list = [];
    foreach ($students as $s) {
        $vm  = $s['verification_method'] ?? 'Auto';
        $st  = $s['signin_status'] ?? 'Absent';
        if ($vm === 'Auto')           { $disp = 'A'; $absent++; }
        elseif ($st === 'Present')    { $disp = 'P'; $present++; }
        elseif ($st === 'Late')       { $disp = 'L'; $late++; }
        else                          { $disp = 'A'; $absent++; }
        $list[] = [
            'name'     => $s['full_name'],
            'reg'      => $s['reg_number'],
            'status'   => $disp,
            'in_time'  => $s['signin_time']  ? date('H:i', strtotime($s['signin_time']))  : null,
            'out_time' => $s['signout_time'] ? date('H:i', strtotime($s['signout_time'])) : null,
        ];
    }

    echo json_encode(['ok'=>true,'present'=>$present,'late'=>$late,'absent'=>$absent,'total'=>count($students),'roster'=>$list]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
