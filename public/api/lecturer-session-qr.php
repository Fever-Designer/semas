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
Semester::enforceAcademicWrite($db);
$me     = Auth::user();
$action = $_POST['action'] ?? '';

// Ensure dynamic-QR columns exist
ClassAttendance::ensureManualControlColumns($db);
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

function liveqr_owned_module(PDO $db, int $moduleId, array $me): ?array
{
    $stmt = $db->prepare(
        "SELECT m.*, r.room_name, lt.user_id AS lecturer_user_id
         FROM modules m
         LEFT JOIN rooms r ON r.room_id = m.room_id
         LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
         WHERE m.module_id = :mid AND m.status = 'Ongoing'
           AND (lt.user_id = :uid OR :role IN ('HOD','Coordinator'))"
    );
    $stmt->execute(['mid' => $moduleId, 'uid' => $me['user_id'], 'role' => Auth::role()]);
    return $stmt->fetch() ?: null;
}

/** @return array{name:string,start:string,end:string} */
function liveqr_demo_window(array $module, string $today): array
{
    $type = (string) ($module['session_type'] ?? '');
    $slot = (string) ($module['weekend_slot'] ?? '');
    if ($type === 'Evening') return ['name' => 'Evening', 'start' => $today . ' 18:00:00', 'end' => $today . ' 20:00:00'];
    if ($type === 'Weekend' && $slot === 'Afternoon') return ['name' => 'WeekendAfternoon', 'start' => $today . ' 14:30:00', 'end' => $today . ' 20:30:00'];
    if ($type === 'Weekend') return ['name' => 'WeekendMorning', 'start' => $today . ' 08:30:00', 'end' => $today . ' 14:00:00'];
    return ['name' => 'Day', 'start' => $today . ' 08:00:00', 'end' => $today . ' 11:30:00'];
}

function liveqr_demo_session(PDO $db, int $moduleId, string $today): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM class_sessions
         WHERE module_id = :mid AND session_date = :today AND demo_controlled = 1
         ORDER BY session_id DESC LIMIT 1'
    );
    $stmt->execute(['mid' => $moduleId, 'today' => $today]);
    return $stmt->fetch() ?: null;
}

function liveqr_demo_response(PDO $db, array $module, ?array $session): array
{
    if (!$session) {
        return ['ok' => true, 'session_id' => null, 'phase' => 'Inactive', 'status' => 'Not Started'];
    }
    $response = [
        'ok' => true,
        'session_id' => (int) $session['session_id'],
        'module_id' => (int) $module['module_id'],
        'module' => $module['module_title'],
        'room' => $module['room_name'] ?? '',
        'phase' => (string) $session['attendance_phase'],
        'status' => (string) $session['status'],
    ];
    return $response;
}

// ── lecturer-controlled demo workflow ───────────────────────────────────
if (in_array($action, ['get_state', 'start_phase', 'close_phase'], true)) {
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $module = liveqr_owned_module($db, $moduleId, $me);
    if (!$module) {
        echo json_encode(['ok' => false, 'message' => 'Module not found or not assigned to you.']);
        exit;
    }
    $today = ClassAttendance::now()->format('Y-m-d');
    if ($rangeError = ClassAttendance::moduleDateRangeError($module, $today)) {
        echo json_encode(['ok' => false, 'message' => $rangeError]);
        exit;
    }
    $session = liveqr_demo_session($db, $moduleId, $today);

    if ($action === 'get_state') {
        echo json_encode(liveqr_demo_response($db, $module, $session));
        exit;
    }

    $phase = (string) ($_POST['phase'] ?? '');
    if (!in_array($phase, ['SignIn', 'SignOut'], true)) {
        echo json_encode(['ok' => false, 'message' => 'Invalid attendance phase.']);
        exit;
    }

    if ($action === 'start_phase') {
        if ($session && $session['status'] === 'Closed') {
            echo json_encode(['ok' => false, 'message' => 'Today\'s attendance is completed and cannot be reopened.']);
            exit;
        }
        if ($session && in_array($session['attendance_phase'], ['SignIn', 'SignOut'], true)) {
            echo json_encode(['ok' => false, 'message' => 'Close the active ' . ($session['attendance_phase'] === 'SignIn' ? 'Sign In' : 'Sign Out') . ' phase first.']);
            exit;
        }
        if ($phase === 'SignOut' && !$session) {
            echo json_encode(['ok' => false, 'message' => 'Start and close Sign In before starting Sign Out.']);
            exit;
        }
        if ($phase === 'SignIn' && $session) {
            echo json_encode(['ok' => false, 'message' => 'Sign In was already started today and cannot be reopened. Continue with Sign Out.']);
            exit;
        }

        if (!$session) {
            $window = liveqr_demo_window($module, $today);
            // Reuse an existing open session for today so the module can have
            // only one attendance lifecycle per day.
            $existingStmt = $db->prepare(
                'SELECT * FROM class_sessions
                 WHERE module_id = :mid AND session_date = :today
                 ORDER BY session_id DESC LIMIT 1'
            );
            $existingStmt->execute(['mid' => $moduleId, 'today' => $today]);
            $existingToday = $existingStmt->fetch();
            if ($existingToday) {
                if ($existingToday['status'] === 'Closed') {
                    echo json_encode(['ok' => false, 'message' => 'Today\'s attendance is already completed and cannot be reopened.']);
                    exit;
                }
                $sessionId = (int) $existingToday['session_id'];
                $db->prepare(
                    "UPDATE class_sessions
                     SET status = 'Open', attendance_phase = :phase, demo_controlled = 1,
                         phase_started_at = NOW(), phase_closed_at = NULL,
                         qr_token = NULL, qr_token_expires_at = NULL
                     WHERE session_id = :sid"
                )->execute(['phase' => $phase, 'sid' => $sessionId]);
            } else {
                $db->prepare(
                    "INSERT INTO class_sessions
                        (module_id, session_date, window_name, start_time, end_time, qr_secret, status,
                         attendance_phase, demo_controlled, phase_started_at, created_by)
                     VALUES (:mid, :today, :window, NOW(), NOW(), :secret, 'Open', :phase, 1, NOW(), :uid)"
                )->execute([
                    'mid' => $moduleId,
                    'today' => $today,
                    'window' => $window['name'],
                    'secret' => QrService::generateSecret(),
                    'phase' => $phase,
                    'uid' => $me['user_id'],
                ]);
                $sessionId = (int) $db->lastInsertId();
            }
            $db->prepare(
                "INSERT IGNORE INTO class_attendance_logs
                    (session_id, user_id, attendance_type, status, verification_method)
                 SELECT :sid, e.user_id, 'Sign In', 'Absent', 'Auto'
                 FROM module_enrollments e WHERE e.module_id = :mid"
            )->execute(['sid' => $sessionId, 'mid' => $moduleId]);
        } else {
            $db->prepare(
                "UPDATE class_sessions
                 SET status = 'Open', attendance_phase = :phase, phase_started_at = NOW(),
                     phase_closed_at = NULL, qr_token = NULL, qr_token_expires_at = NULL,
                     end_time = IF(:phase2 = 'SignOut', NOW(), end_time)
                 WHERE session_id = :sid"
            )->execute(['phase' => $phase, 'phase2' => $phase, 'sid' => $session['session_id']]);
            $sessionId = (int) $session['session_id'];
        }
        AuditLog::record(Auth::id(), 'ATTENDANCE_PHASE_START_' . strtoupper($phase), 'class_sessions', $sessionId);
        $session = liveqr_demo_session($db, $moduleId, $today);
        echo json_encode(liveqr_demo_response($db, $module, $session));
        exit;
    }

    if (!$session || $session['attendance_phase'] !== $phase) {
        echo json_encode(['ok' => false, 'message' => 'That attendance phase is not active.']);
        exit;
    }
    $finalClose = $phase === 'SignOut';
    $db->prepare(
        "UPDATE class_sessions
         SET attendance_phase = 'Inactive', status = :status, phase_closed_at = NOW(),
             qr_token = NULL, qr_token_expires_at = NULL,
             end_time = IF(:is_final = 1, NOW(), end_time)
         WHERE session_id = :sid"
    )->execute([
        'status' => $finalClose ? 'Closed' : 'Open',
        'is_final' => $finalClose ? 1 : 0,
        'sid' => $session['session_id'],
    ]);
    AuditLog::record(Auth::id(), 'ATTENDANCE_PHASE_CLOSE_' . strtoupper($phase), 'class_sessions', (int) $session['session_id']);
    if ($finalClose) {
        AttendanceWarning::processClosedModule($db, $moduleId, (int) $session['session_id']);
    }
    $session = liveqr_demo_session($db, $moduleId, $today);
    echo json_encode(liveqr_demo_response($db, $module, $session));
    exit;
}

// ── open_session ──────────────────────────────────────────────────────────
if ($action === 'manual_mark') {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    $studentId = (int) ($_POST['user_id'] ?? 0);
    $sessionStmt = $db->prepare(
        "SELECT cs.*, m.module_title
         FROM class_sessions cs
         JOIN modules m ON m.module_id = cs.module_id
         LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
         WHERE cs.session_id = :sid
           AND (lt.user_id = :uid OR :role IN ('HOD','Coordinator'))"
    );
    $sessionStmt->execute(['sid' => $sessionId, 'uid' => $me['user_id'], 'role' => Auth::role()]);
    $session = $sessionStmt->fetch();
    if (!$session || !$studentId) {
        echo json_encode(['ok' => false, 'message' => 'Session or student not found.']);
        exit;
    }
    if ((int) $session['demo_controlled'] !== 1 || $session['status'] !== 'Open'
        || !in_array($session['attendance_phase'], ['SignIn', 'SignOut'], true)) {
        echo json_encode(['ok' => false, 'message' => 'Manual attendance is available only during the active phase.']);
        exit;
    }
    $enrolled = $db->prepare('SELECT 1 FROM module_enrollments WHERE module_id = :mid AND user_id = :uid');
    $enrolled->execute(['mid' => $session['module_id'], 'uid' => $studentId]);
    if (!$enrolled->fetchColumn()) {
        echo json_encode(['ok' => false, 'message' => 'This student is not registered for the module.']);
        exit;
    }

    if ($session['attendance_phase'] === 'SignIn') {
        $existing = $db->prepare(
            "SELECT attendance_id, verification_method FROM class_attendance_logs
             WHERE session_id = :sid AND user_id = :uid AND attendance_type = 'Sign In'"
        );
        $existing->execute(['sid' => $sessionId, 'uid' => $studentId]);
        $row = $existing->fetch();
        if ($row && in_array($row['verification_method'], ['QR', 'Manual'], true)) {
            echo json_encode(['ok' => false, 'message' => 'This student is already signed in.']);
            exit;
        }
        if ($row) {
            $db->prepare(
                "UPDATE class_attendance_logs
                 SET status = 'Present', verification_method = 'Manual', confirmed_by = :by, checkin_time = NOW()
                 WHERE attendance_id = :id"
            )->execute(['by' => Auth::id(), 'id' => $row['attendance_id']]);
        } else {
            $db->prepare(
                "INSERT INTO class_attendance_logs
                    (session_id, user_id, attendance_type, status, verification_method, confirmed_by, checkin_time)
                 VALUES (:sid, :uid, 'Sign In', 'Present', 'Manual', :by, NOW())"
            )->execute(['sid' => $sessionId, 'uid' => $studentId, 'by' => Auth::id()]);
        }
        $attendanceType = 'Sign In';
        $message = 'Student manually signed in.';
    } else {
        $signedIn = $db->prepare(
            "SELECT 1 FROM class_attendance_logs
             WHERE session_id = :sid AND user_id = :uid AND attendance_type = 'Sign In'
               AND verification_method IN ('QR','Manual')"
        );
        $signedIn->execute(['sid' => $sessionId, 'uid' => $studentId]);
        $hasRealSignIn = (bool) $signedIn->fetchColumn();
        $signOutStatus = $hasRealSignIn ? 'Present' : 'Late';
        try {
            $db->prepare(
                "INSERT INTO class_attendance_logs
                    (session_id, user_id, attendance_type, status, verification_method, confirmed_by, checkin_time)
                 VALUES (:sid, :uid, 'Sign Out', :status, 'Manual', :by, NOW())"
            )->execute(['sid' => $sessionId, 'uid' => $studentId, 'status' => $signOutStatus, 'by' => Auth::id()]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                echo json_encode(['ok' => false, 'message' => 'This student is already signed out.']);
                exit;
            }
            throw $e;
        }
        $attendanceType = 'Sign Out';
        $message = $hasRealSignIn
            ? 'Student manually signed out and is Present.'
            : 'Student manually signed out without Sign In and is Late.';
    }

    AuditLog::record(Auth::id(), 'CLASS_ATTENDANCE_MANUAL_' . strtoupper(str_replace(' ', '_', $attendanceType)), 'class_sessions', $sessionId, 'student_user_id=' . $studentId);
    NotificationCenter::notify($studentId, 'Attendance recorded', $message . ' Module: ' . $session['module_title'] . '.', 'Attendance');
    echo json_encode(['ok' => true, 'message' => $message]);
    exit;
}

if ($action === 'open_session') {
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    if (!$moduleId) { echo json_encode(['ok' => false, 'message' => 'module_id required.']); exit; }

    $modStmt = $db->prepare(
        "SELECT m.module_id, m.module_title, m.session_type, m.weekend_slot,
                m.start_date, m.end_date, m.exam_date, r.room_name
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
    if ($rangeError = ClassAttendance::moduleDateRangeError($module, $today)) {
        echo json_encode(['ok' => false, 'message' => $rangeError]);
        exit;
    }
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
        'qr_data_uri'=> SimpleQr::pngDataUri($payload['url'], 5, 3),
    ]);
    exit;
}

// ── refresh ───────────────────────────────────────────────────────────────
if ($action === 'refresh') {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    if (!$sessionId) { echo json_encode(['ok' => false, 'message' => 'session_id required.']); exit; }

    $chk = $db->prepare(
        "SELECT cs.session_id, cs.module_id, cs.session_date, cs.status,
                cs.attendance_phase, cs.demo_controlled, m.start_date, m.end_date, m.exam_date
         FROM class_sessions cs
         JOIN modules m ON m.module_id = cs.module_id
         LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
         WHERE cs.session_id = :sid AND (lt.user_id = :uid OR :role IN ('HOD','Coordinator'))"
    );
    $chk->execute(['sid' => $sessionId, 'uid' => $me['user_id'], 'role' => Auth::role()]);
    $row = $chk->fetch();
    if (!$row) { echo json_encode(['ok' => false, 'message' => 'Not authorised.']); exit; }
    if ($rangeError = ClassAttendance::moduleDateRangeError($row)) {
        echo json_encode(['ok' => false, 'message' => $rangeError]);
        exit;
    }
    if ((int) $row['demo_controlled'] === 1
        && ($row['status'] !== 'Open' || !in_array($row['attendance_phase'], ['SignIn', 'SignOut'], true))) {
        echo json_encode(['ok' => false, 'message' => 'This attendance phase is closed.']);
        exit;
    }

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
        'qr_data_uri'=> SimpleQr::pngDataUri($payload['url'], 5, 3),
    ]);
    exit;
}

// ── status ────────────────────────────────────────────────────────────────
if ($action === 'status') {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    if (!$sessionId) { echo json_encode(['ok' => false, 'message' => 'session_id required.']); exit; }

    $midRow = $db->prepare(
        "SELECT cs.module_id, cs.attendance_phase, cs.status
         FROM class_sessions cs
         JOIN modules m ON m.module_id = cs.module_id
         LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
         WHERE cs.session_id = :sid
           AND (lt.user_id = :uid OR :role IN ('HOD','Coordinator'))"
    );
    $midRow->execute(['sid' => $sessionId, 'uid' => $me['user_id'], 'role' => Auth::role()]);
    $sessionState = $midRow->fetch();
    if (!$sessionState) {
        echo json_encode(['ok' => false, 'message' => 'Not authorised.']);
        exit;
    }
    $modId = (int) ($sessionState['module_id'] ?? 0);

    $roster = $db->prepare(
        "SELECT u.user_id, u.full_name, u.reg_number,
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
        $hasRecordedSignIn = in_array($vm, ['QR', 'Manual'], true);
        $hasRecordedSignOut = !empty($s['signout_time']);
        if ($hasRecordedSignIn && $hasRecordedSignOut) { $disp = 'P'; $present++; }
        elseif (!$hasRecordedSignIn && $hasRecordedSignOut) { $disp = 'L'; $late++; }
        else { $disp = 'A'; $absent++; }
        $list[] = [
            'user_id'  => (int) $s['user_id'],
            'name'     => $s['full_name'],
            'reg'      => $s['reg_number'],
            'status'   => $disp,
            'in_time'  => $hasRecordedSignIn && $s['signin_time'] ? date('H:i', strtotime($s['signin_time'])) : null,
            'out_time' => $s['signout_time'] ? date('H:i', strtotime($s['signout_time'])) : null,
        ];
    }

    echo json_encode([
        'ok'=>true,'present'=>$present,'late'=>$late,'absent'=>$absent,'total'=>count($students),'roster'=>$list,
        'phase'=>$sessionState['attendance_phase'] ?? 'Inactive','status'=>$sessionState['status'] ?? 'Closed',
    ]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
