<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json');

if (!Auth::check() || Auth::role() !== 'Student') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'You must be logged in as a student.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}
csrf_verify();

$db       = Database::connection();
Semester::enforceAcademicWrite($db);
$me       = Auth::user();
$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$deviceId = trim($_POST['device_id'] ?? '') ?: null;

ClassAttendance::ensureManualControlColumns($db);

function attendance_security_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS attendance_devices (
            device_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            device_hash CHAR(64) NOT NULL,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reset_at DATETIME NULL,
            reset_by INT NULL,
            KEY idx_attendance_device_user (user_id),
            UNIQUE KEY uniq_attendance_device_hash (device_hash),
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (reset_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS attendance_security_logs (
            security_log_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            module_id INT NULL,
            session_id INT NULL,
            device_hash CHAR(64) NULL,
            ip_address VARCHAR(45) NULL,
            event_type VARCHAR(80) NOT NULL,
            message TEXT NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            distance_meters DECIMAL(8,2) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
            FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE SET NULL,
            FOREIGN KEY (session_id) REFERENCES class_sessions(session_id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );

    // A student may own several phones, but each phone/device hash can belong
    // to only one student. Upgrade installations that used one-device-per-user.
    $indexes = $db->query('SHOW INDEX FROM attendance_devices')->fetchAll();
    $hasUniqueUserIndex = false;
    $hasRegularUserIndex = false;
    foreach ($indexes as $index) {
        if ($index['Key_name'] === 'uniq_attendance_device_user') {
            $hasUniqueUserIndex = true;
        }
        if ($index['Key_name'] === 'idx_attendance_device_user') {
            $hasRegularUserIndex = true;
        }
    }
    if ($hasUniqueUserIndex) {
        if (!$hasRegularUserIndex) {
            $db->exec('ALTER TABLE attendance_devices ADD KEY idx_attendance_device_user (user_id)');
        }
        $db->exec('ALTER TABLE attendance_devices DROP INDEX uniq_attendance_device_user');
    }
}

function attendance_security_log(PDO $db, ?int $userId, ?int $moduleId, ?int $sessionId, ?string $deviceHash, string $ip, string $eventType, string $message): void
{
    $db->prepare(
        "INSERT INTO attendance_security_logs (user_id, module_id, session_id, device_hash, ip_address, event_type, message)
         VALUES (:uid, :mid, :sid, :dev, :ip, :type, :msg)"
    )->execute([
        'uid' => $userId,
        'mid' => $moduleId,
        'sid' => $sessionId,
        'dev' => $deviceHash,
        'ip' => $ip,
        'type' => $eventType,
        'msg' => $message,
    ]);
}

function attendance_device_hash(string $deviceId): string
{
    $secret = defined('APP_KEY') && APP_KEY !== '' ? APP_KEY : 'fallback-key-change-me';
    return hash_hmac('sha256', $deviceId, $secret);
}

attendance_security_schema($db);

if ($deviceId === null || strlen($deviceId) < 12) {
    attendance_security_log($db, (int) $me['user_id'], null, null, null, $ip, 'MISSING_DEVICE_ID', 'Attendance scan without a valid device id.');
    echo json_encode(['ok' => false, 'message' => 'This phone could not be identified. Reload the page and scan the classroom QR again.']);
    exit;
}
$deviceHash = attendance_device_hash($deviceId);

$deviceOwner = $db->prepare('SELECT user_id FROM attendance_devices WHERE device_hash = :hash LIMIT 1');
$deviceOwner->execute(['hash' => $deviceHash]);
$ownerUserId = $deviceOwner->fetchColumn();
if ($ownerUserId !== false && (int) $ownerUserId !== (int) $me['user_id']) {
    attendance_security_log($db, (int) $me['user_id'], null, null, $deviceHash, $ip, 'DEVICE_USED_BY_MULTIPLE_ACCOUNTS', 'Device already registered to another student.');
    echo json_encode(['ok' => false, 'message' => 'This phone is already assigned to another student and cannot be shared for attendance.']);
    exit;
}

if ($ownerUserId === false) {
    $db->prepare('INSERT IGNORE INTO attendance_devices (user_id, device_hash) VALUES (:uid, :hash)')
       ->execute(['uid' => $me['user_id'], 'hash' => $deviceHash]);

    // Re-check ownership in case two accounts attempted to claim a new phone
    // at the same instant; the unique device-hash key decides the winner.
    $deviceOwner->execute(['hash' => $deviceHash]);
    $ownerUserId = $deviceOwner->fetchColumn();
    if ($ownerUserId === false || (int) $ownerUserId !== (int) $me['user_id']) {
        attendance_security_log($db, (int) $me['user_id'], null, null, $deviceHash, $ip, 'DEVICE_CLAIM_CONFLICT', 'Phone was claimed by another student.');
        echo json_encode(['ok' => false, 'message' => 'This phone is already assigned to another student and cannot be shared for attendance.']);
        exit;
    }
}
$db->prepare('UPDATE attendance_devices SET last_seen_at = NOW() WHERE device_hash = :hash AND user_id = :uid')
   ->execute(['hash' => $deviceHash, 'uid' => $me['user_id']]);

// ── Detect QR format: dynamic "SEMAS:{module_id}:{session_id}:{token}" ────
$qrData       = trim($_POST['qr_data'] ?? '');
$forcedSessId = null;

if ($qrData !== '') {
    $parts = explode(':', $qrData);
    if (count($parts) !== 4 || $parts[0] !== 'SEMAS') {
        echo json_encode(['ok' => false, 'message' => 'Unrecognised QR format.']);
        exit;
    }
    [, $qrModId, $qrSessId, $qrToken] = $parts;
    $qrModId  = (int) $qrModId;
    $qrSessId = (int) $qrSessId;

    // Validate dynamic token
    $dynCheck = $db->prepare(
        "SELECT session_id FROM class_sessions
         WHERE session_id = :sid AND module_id = :mid
           AND qr_token = :tok AND qr_token_expires_at > NOW()"
    );
    $dynCheck->execute(['sid' => $qrSessId, 'mid' => $qrModId, 'tok' => $qrToken]);
    if (!$dynCheck->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'QR code has expired or is invalid. Wait for the next rotation on the lecturer\'s screen.']);
        exit;
    }
    // Pin the session; override module_id from POST
    $_POST['module_id'] = $qrModId;
    $forcedSessId       = $qrSessId;
}

$moduleId = (int) ($_POST['module_id'] ?? 0);
$qrToken  = trim($_POST['qr_token'] ?? '');   // legacy static QR path

// Manual check-in (no camera): neither a dynamic QR payload nor a scanned
// static module QR token was submitted.
$isManual = ($qrData === '' && $qrToken === '');
if ($isManual) {
    attendance_security_log($db, (int) $me['user_id'], null, null, $deviceHash, $ip, 'QR_REQUIRED', 'Attendance attempt without an official QR code.');
    echo json_encode(['ok' => false, 'message' => 'Attendance requires scanning the official class QR code.']);
    exit;
}

// Allow the student scanner to send a dynamic SEMAS payload through the
// legacy qr_token field, or a printed classroom QR URL directly.
if ($qrData === '' && $qrToken !== '') {
    if (preg_match('/^SEMAS:(\d+):(\d+):([0-9a-f]+)$/i', $qrToken, $m)) {
        $qrData = $qrToken;
        $qrToken = '';
        $qrModId = (int) $m[1];
        $qrSessId = (int) $m[2];
        $dynCheck = $db->prepare(
            "SELECT session_id FROM class_sessions
             WHERE session_id = :sid AND module_id = :mid
               AND qr_token = :tok AND qr_token_expires_at > NOW()"
        );
        $dynCheck->execute(['sid' => $qrSessId, 'mid' => $qrModId, 'tok' => $m[3]]);
        if (!$dynCheck->fetch()) {
            echo json_encode(['ok' => false, 'message' => 'QR code has expired or is invalid. Scan the currently active classroom QR.']);
            exit;
        }
        $_POST['module_id'] = $qrModId;
        $moduleId = $qrModId;
        $forcedSessId = $qrSessId;
    } elseif (preg_match('/^SM:(\d+):([A-Za-z0-9_-]+)$/', $qrToken, $m)) {
        $_POST['module_id'] = (int) $m[1];
        $moduleId = (int) $_POST['module_id'];
        $tokenBody = $m[2];
        $pad = strlen($tokenBody) % 4;
        if ($pad) {
            $tokenBody .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($tokenBody, '-_', '+/'), true);
        $qrToken = $decoded !== false ? bin2hex($decoded) : $m[2];
    } elseif (filter_var($qrToken, FILTER_VALIDATE_URL)) {
        $parsed = parse_url($qrToken);
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            if (!empty($params['module_id'])) {
                $_POST['module_id'] = (int) $params['module_id'];
                $moduleId = (int) $_POST['module_id'];
            }
            if (!empty($params['t'])) {
                $qrToken = $params['t'];
            } elseif (!empty($params['qr_token'])) {
                $qrToken = $params['qr_token'];
            }
        }
    }
}

// Legacy static module_qr_secret validation
if ($qrData === '' && $qrToken !== '') {
    $tokenCheck = $db->prepare('SELECT module_id FROM modules WHERE module_id = :id AND module_qr_secret = :t');
    $tokenCheck->execute(['id' => $moduleId, 't' => $qrToken]);
    if (!$tokenCheck->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'Invalid or expired QR code. Ask your Head Of Department to reprint the classroom QR.']);
        exit;
    }
}

$enrolled = $db->prepare(
    "SELECT m.* FROM modules m JOIN module_enrollments e ON e.module_id = m.module_id
     WHERE m.module_id = :id AND e.user_id = :uid AND m.status = 'Ongoing'"
);
$enrolled->execute(['id' => $moduleId, 'uid' => $me['user_id']]);
$module = $enrolled->fetch();

if (!$module) {
    attendance_security_log($db, (int) $me['user_id'], $moduleId ?: null, $forcedSessId, $deviceHash, $ip, 'UNREGISTERED_MODULE_SCAN', 'Student scanned for a module they are not registered in or that is not ongoing.');
    echo json_encode(['ok' => false, 'message' => 'You are not registered for this module, or it is not ongoing.']);
    exit;
}

// ── Date-bounds enforcement ───────────────────────────────────────────
// Attendance is valid only during the module period and ends before Exam day.
$today = ClassAttendance::now()->format('Y-m-d');
$holidayToday = ClassAttendance::holidayToday();
if ($holidayToday
    && ($holidayToday['holiday_type'] ?? '') === 'Public Holiday'
    && ($module['session_type'] ?? '') !== 'Weekend') {
    echo json_encode(['ok' => false, 'message' => 'Today is a Public Holiday. Attendance scanning is disabled and no attendance is required.']);
    exit;
}
if ($rangeError = ClassAttendance::moduleDateRangeError($module, $today)) {
    echo json_encode(['ok' => false, 'message' => $rangeError]);
    exit;
}

$window = ClassAttendance::currentWindow();
$now    = ClassAttendance::now();
$attendanceDate = $now->format('Y-m-d');

// ── Determine whether we're in Sign In window or Sign Out window ──────────
// Sign In  : session is active AND elapsed <= 20 minutes.
// Sign Out : session has ended AND within 10 minutes after end.
// Otherwise: block scan.

$sessionMatches = true;
if ($module['session_type'] === 'Day') {
    $sessionMatches = ($window && $window['name'] === 'Day');
} elseif ($module['session_type'] === 'Evening') {
    $sessionMatches = ($window && $window['name'] === 'Evening');
} elseif ($module['session_type'] === 'Weekend') {
    $slot = $module['weekend_slot'] ?? '';
    if ($slot === 'Morning')       $sessionMatches = ($window && in_array($window['name'], ['WeekendMorning', 'UmugandaMorning'], true));
    elseif ($slot === 'Afternoon') $sessionMatches = ($window && in_array($window['name'], ['WeekendAfternoon', 'UmugandaAfternoon'], true));
    else                           $sessionMatches = ($window && in_array($window['name'], ['WeekendMorning', 'WeekendAfternoon', 'UmugandaMorning', 'UmugandaAfternoon'], true));
} else {
    $sessionMatches = (bool) $window;
}

// ── Dynamic QR path: session already validated above, fetch directly ─────
$demoFind = $db->prepare(
    "SELECT * FROM class_sessions
     WHERE module_id = :mid AND session_date = :today
       AND status = 'Open' AND demo_controlled = 1
       AND attendance_phase IN ('SignIn','SignOut')
     ORDER BY session_id DESC LIMIT 1"
);
$demoFind->execute(['mid' => $moduleId, 'today' => $attendanceDate]);
$activeDemoSession = $demoFind->fetch() ?: null;

$allowSignOut = false;
if ($forcedSessId !== null) {
    $forcedFetch = $db->prepare('SELECT * FROM class_sessions WHERE session_id = :sid AND module_id = :mid');
    $forcedFetch->execute(['sid' => $forcedSessId, 'mid' => $moduleId]);
    $session = $forcedFetch->fetch();
    if (!$session) {
        echo json_encode(['ok' => false, 'message' => 'Session not found.']);
        exit;
    }
    if ($session['status'] !== 'Open') {
        echo json_encode(['ok' => false, 'message' => 'Attendance is closed for this session.']);
        exit;
    }
    if ((int) ($session['demo_controlled'] ?? 0) === 1
        && !in_array((string) ($session['attendance_phase'] ?? ''), ['SignIn', 'SignOut'], true)) {
        echo json_encode(['ok' => false, 'message' => 'The lecturer has closed this attendance phase.']);
        exit;
    }
    $sessionAlreadyExisted = true;
    $allowSignOut = (int) ($session['demo_controlled'] ?? 0) === 1
        ? ($session['attendance_phase'] === 'SignOut')
        : ClassAttendance::isStudentSignOutOpen((string) $session['end_time']);
} elseif ($activeDemoSession) {
    // Use the one permanent QR created for the module. Its secret stays the
    // same; the lecturer-controlled phase decides whether scanning is allowed.
    $session = $activeDemoSession;
    $sessionAlreadyExisted = true;
    $allowSignOut = $session['attendance_phase'] === 'SignOut';
} else {
    // ── Legacy / window-based path ────────────────────────────────────────
    $todayFind = $db->prepare(
        'SELECT * FROM class_sessions
         WHERE module_id = :mid AND session_date = :today
         ORDER BY start_time DESC, session_id DESC LIMIT 1'
    );
    $todayFind->execute(['mid' => $moduleId, 'today' => $attendanceDate]);
    $todaySession = $todayFind->fetch();

    if ($todaySession) {
        $allowSignOut = ClassAttendance::isStudentSignOutOpen((string) $todaySession['end_time']);
    }

    if (!$window && !$allowSignOut) {
        $holiday = ClassAttendance::holidayToday();
        $msg     = $holiday && $holiday['holiday_type'] === 'Public Holiday' && ($module['session_type'] ?? '') !== 'Weekend'
            ? 'Today is a public holiday / no attendance scanning is required.'
            : 'There is no active class session window right now.';
        echo json_encode(['ok' => false, 'message' => $msg]);
        exit;
    }

    if (!$sessionMatches && !$allowSignOut) {
        echo json_encode(['ok' => false, 'message' => 'This module\'s session (' . $module['session_type'] . ') does not match the currently active window (' . ($window ? ClassAttendance::describeWindow($window) : 'none') . ').']);
        exit;
    }

    $find       = $db->prepare(
        'SELECT * FROM class_sessions
         WHERE module_id = :mid AND session_date = :today AND window_name = :win
         ORDER BY session_id DESC LIMIT 1'
    );
    $windowName = $window ? $window['name'] : ($todaySession['window_name'] ?? null);

    if (!$windowName) {
        echo json_encode(['ok' => false, 'message' => 'Could not determine session window.']);
        exit;
    }

    $find->execute(['mid' => $moduleId, 'today' => $attendanceDate, 'win' => $windowName]);
    $session = $find->fetch();
    $sessionAlreadyExisted = (bool) $session;

    if (!$session && $window) {
        try {
            $db->prepare(
                'INSERT INTO class_sessions (module_id, session_date, window_name, start_time, end_time, qr_secret, created_by)
                 VALUES (:mid, :today, :win, :start, :end, :secret, :uid)'
            )->execute([
                'mid'    => $moduleId,
                'today'  => $attendanceDate,
                'win'    => $window['name'],
                'start'  => $window['start']->format('Y-m-d H:i:s'),
                'end'    => $window['end']->format('Y-m-d H:i:s'),
                'secret' => QrService::generateSecret(),
                'uid'    => $me['user_id'],
            ]);
            $find->execute(['mid' => $moduleId, 'today' => $attendanceDate, 'win' => $window['name']]);
            $session = $find->fetch();
        } catch (PDOException $e) {
            $find->execute(['mid' => $moduleId, 'today' => $attendanceDate, 'win' => $window['name']]);
            $session = $find->fetch();
        }
    }

    if (!$session) {
        echo json_encode(['ok' => false, 'message' => 'No session found. Has a class session been opened yet for this module today?']);
        exit;
    }
    if ($session['status'] !== 'Open') {
        echo json_encode(['ok' => false, 'message' => 'Attendance is closed for this session.']);
        exit;
    }
}

$isDemoSession = (int) ($session['demo_controlled'] ?? 0) === 1;
$manualPhase = (string) ($session['attendance_phase'] ?? 'Inactive');
if ($isDemoSession && !in_array($manualPhase, ['SignIn', 'SignOut'], true)) {
    echo json_encode(['ok' => false, 'message' => 'The lecturer has closed this attendance phase.']);
    exit;
}
// Pre-populate all enrolled students when session is first created
if (!$sessionAlreadyExisted) {
    $db->prepare(
        "INSERT IGNORE INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method)
         SELECT :sid, e.user_id, 'Sign In', 'Absent', 'Auto'
         FROM module_enrollments e WHERE e.module_id = :mid"
    )->execute(['sid' => $session['session_id'], 'mid' => $moduleId]);
}

// ── Decide Sign In vs Sign Out (and enforce 20-min Sign In cut-off) ──────
$signOutRow = $db->prepare("SELECT 1 FROM class_attendance_logs WHERE session_id = :s AND user_id = :u AND attendance_type = 'Sign Out'");
$signOutRow->execute(['s' => $session['session_id'], 'u' => $me['user_id']]);
if ($signOutRow->fetch()) {
    echo json_encode(['ok' => false, 'message' => 'You have already signed in and out for this session.']);
    exit;
}

$realSignInRow = $db->prepare("SELECT 1 FROM class_attendance_logs WHERE session_id = :s AND user_id = :u AND attendance_type = 'Sign In' AND verification_method IN ('QR','Manual')");
$realSignInRow->execute(['s' => $session['session_id'], 'u' => $me['user_id']]);
$hasRealSignIn = (bool) $realSignInRow->fetch();

$statusVal = ClassAttendance::statusFor($session['start_time']);

if (!$hasRealSignIn && $allowSignOut) {
    // The student reached class after the lecturer closed Sign In. Recording
    // Sign Out alone is the evidence used by SEMAS to decide Late.
    $type = 'Sign Out';
    $status = 'Late';
} elseif (!$hasRealSignIn) {
    // Attempting Sign In / must always be a QR scan, manual check-in is not allowed.
    if ($isManual) {
        echo json_encode(['ok' => false, 'message' => 'Sign In requires scanning the QR code. Manual check-in is only available for Sign Out, near the end of the session.']);
        exit;
    }
    if (!$isDemoSession && !ClassAttendance::canSelfSignIn((string) $session['start_time'])) {
        echo json_encode(['ok' => false, 'message' => 'The sign-in window has closed (more than 20 minutes since class started). You have been marked Absent for this session.']);
        exit;
    }
    $type   = 'Sign In';
    $status = $isDemoSession ? 'Present' : $statusVal;
} else {
    if ($isDemoSession && $manualPhase === 'SignIn') {
        echo json_encode(['ok' => false, 'message' => 'Your Sign In is already recorded. Wait for the lecturer to start Sign Out.']);
        exit;
    }
    // Student already signed in / this must be a Sign Out
    if (!$allowSignOut) {
        echo json_encode(['ok' => false, 'message' => 'Sign-out is only available from class end until 10 minutes afterward. Your sign-in has been recorded.']);
        exit;
    }
    $type   = 'Sign Out';
    $status = 'Present';

}

$sameWindowStmt = $db->prepare(
    "SELECT m.module_title
     FROM class_attendance_logs cal
     JOIN class_sessions cs ON cs.session_id = cal.session_id
     JOIN modules m ON m.module_id = cs.module_id
     WHERE cal.user_id = :uid
       AND cal.attendance_type = :type
       AND cal.verification_method IN ('QR','Manual')
       AND cs.session_date = :session_date
       AND cs.window_name = :window_name
       AND cs.module_id <> :module_id
     LIMIT 1"
);
$sameWindowStmt->execute([
    'uid' => $me['user_id'],
    'type' => $type,
    'session_date' => $session['session_date'],
    'window_name' => $session['window_name'],
    'module_id' => $moduleId,
]);
$sameWindowModule = $sameWindowStmt->fetchColumn();
if ($sameWindowModule) {
    attendance_security_log($db, (int) $me['user_id'], $moduleId, (int) $session['session_id'], $deviceHash, $ip, 'DUPLICATE_WINDOW_SCAN', 'Student attempted attendance for two modules in the same session window.');
    echo json_encode(['ok' => false, 'message' => 'You already recorded ' . $type . ' for another module in this same session window: ' . $sameWindowModule . '.']);
    exit;
}

// ── IP deduplication ─────────────────────────────────────────────────────
// ── Device deduplication / a persisted client-side token, stronger than
//    shared-WiFi IP alone (catches "friend scans for me" from a different
//    network) ────────────────────────────────────────────────────────────
if ($deviceId !== null) {
    $devAlready = $db->prepare('SELECT 1 FROM class_attendance_logs WHERE session_id = :s AND device_id = :d AND attendance_type = :type');
    $devAlready->execute(['s' => $session['session_id'], 'd' => $deviceId, 'type' => $type]);
    if ($devAlready->fetch()) {
        attendance_security_log($db, (int) $me['user_id'], $moduleId, (int) $session['session_id'], $deviceHash, $ip, 'DUPLICATE_DEVICE_SCAN', 'Duplicate attendance attempt from the same registered device.');
        echo json_encode(['ok' => false, 'message' => 'A ' . $type . ' has already been recorded from this device for this session. If this is a shared device, ask your lecturer to mark you manually.']);
        exit;
    }
}

$deviceParams = ['dev' => $deviceId];

// ── Record ───────────────────────────────────────────────────────────────
try {
    if ($type === 'Sign In') {
        $upd = $db->prepare(
            "UPDATE class_attendance_logs
             SET status=:status, verification_method='QR', ip_address=:ip, checkin_time=NOW(),
                 device_id=:dev
             WHERE session_id=:s AND user_id=:u AND attendance_type='Sign In' AND verification_method='Auto'"
        );
        $upd->execute($deviceParams + ['status' => $status, 'ip' => $ip, 's' => $session['session_id'], 'u' => $me['user_id']]);
        if ($upd->rowCount() === 0) {
            $db->prepare(
                "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method, ip_address, device_id)
                 VALUES (:s, :u, 'Sign In', :status, 'QR', :ip, :dev)"
            )->execute($deviceParams + ['s' => $session['session_id'], 'u' => $me['user_id'], 'status' => $status, 'ip' => $ip]);
        }
    } else {
        $db->prepare(
            "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method, ip_address, checkin_time, device_id)
             VALUES (:s, :u, 'Sign Out', :status, 'QR', :ip, NOW(), :dev)"
        )->execute($deviceParams + ['s' => $session['session_id'], 'u' => $me['user_id'], 'status' => $status, 'ip' => $ip]);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo json_encode(['ok' => false, 'message' => 'This ' . $type . ' could not be recorded / it may already exist. Ask your lecturer to mark you manually if needed.']);
        exit;
    }
    throw $e;
}

AuditLog::record(Auth::id(), 'CLASS_ATTENDANCE_SELF_' . ($type === 'Sign In' ? 'SIGNIN' : 'SIGNOUT'), 'class_sessions', (int) $session['session_id'], "status=$status;ip=$ip");

// ── Absence warning system (only runs after Sign In = Absent, or session auto-close) ──────
// We trigger it here when the student's scan results in Absent (late sign-in blocked).
// The lecturer-side also triggers it in class-scan-confirm.php.
if ($type === 'Sign In' && $status === 'Absent') {
    trigger_absence_warning($db, $me, $module, $session);
}

$label = $type === 'Sign In'
    ? "Checked in / marked <strong>$status</strong>."
    : ($status === 'Late' ? 'Signed out successfully / attendance marked <strong>Late</strong>.' : 'Signed out successfully.');
echo json_encode(['ok' => true, 'message' => $label, 'type' => $type, 'status' => $status]);

// ── Warning helper ────────────────────────────────────────────────────────
function trigger_absence_warning(PDO $db, array $student, array $module, array $session): void
{
    // Count total Absent records for this student in this module (closed sessions only)
    $stmt = $db->prepare(
        "SELECT COUNT(DISTINCT cs.session_id)
         FROM class_sessions cs
         JOIN class_attendance_logs cal ON cal.session_id = cs.session_id
             AND cal.user_id = :uid AND cal.attendance_type = 'Sign In' AND cal.status = 'Absent'
         WHERE cs.module_id = :mid AND cs.status = 'Closed'"
    );
    // The session isn't closed yet, but if status is Absent we count it too.
    // Simpler: count all Absent sign-in rows for this module regardless of session status.
    $stmt2 = $db->prepare(
        "SELECT COUNT(DISTINCT cs.session_id)
         FROM class_sessions cs
         JOIN class_attendance_logs cal ON cal.session_id = cs.session_id
             AND cal.user_id = :uid AND cal.attendance_type = 'Sign In' AND cal.status = 'Absent'
             AND cal.verification_method IN ('QR','Manual')
         WHERE cs.module_id = :mid"
    );
    $stmt2->execute(['uid' => $student['user_id'], 'mid' => $module['module_id']]);
    $absences = (int) $stmt2->fetchColumn();

    if ($absences === 2) {
        NotificationCenter::notify(
            $student['user_id'],
            'Attendance Warning / ' . $module['module_title'],
            'You have missed 2 sessions of "' . $module['module_title'] . '". Missing a third session may affect your CAT/Exam eligibility. Please contact your Head Of Department if you have a valid reason.',
            'Attendance'
        );
    } elseif ($absences >= 3) {
        NotificationCenter::notify(
            $student['user_id'],
            'Attendance Alert / ' . $module['module_title'],
            'You have missed ' . $absences . ' sessions of "' . $module['module_title'] . '". You may be marked ineligible for the CAT/Exam. Contact your Head Of Department immediately.',
            'Attendance'
        );
        // Send email + SMS for 3rd+ absence
        if (!empty($student['email'])) {
            $body = 'You have missed ' . $absences . ' attendance session(s) of "' . $module['module_title'] . '". Please contact your Head of Department immediately.';
            Mailer::send(
                $student['email'],
                'Attendance Warning / ' . $module['module_title'],
                'attendance_warning',
                [
                    'full_name' => $student['full_name'],
                    'module_title' => $module['module_title'],
                    'exam_type' => 'CAT/Exam',
                    'missed_days' => $absences,
                    'body' => $body,
                ],
                (int) $student['user_id']
            );
        }
        if (!empty($student['phone_number']) && ($student['sms_opt_in'] ?? 1)) {
            Sms::send(
                $student['phone_number'],
                'SEMAS Alert: You have missed ' . $absences . ' sessions of "' . $module['module_title'] . '". Contact your Head Of Department immediately.',
                $student['user_id']
            );
        }
    }
}
