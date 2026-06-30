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

// Sign Out must happen noticeably closer than the general campus radius
// (right by the classroom), unlike Sign In which uses the full campus radius.
const SCAN_OUT_RADIUS_METERS = 40;

$db       = Database::connection();
$me       = Auth::user();
$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$lat      = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float) $_POST['latitude']  : null;
$lng      = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float) $_POST['longitude'] : null;
$deviceId = trim($_POST['device_id'] ?? '') ?: null;

if ($lat === null || $lng === null) {
    echo json_encode(['ok' => false, 'message' => 'Location is required to record attendance. Please allow location access and try again.']);
    exit;
}

$gps = GpsService::withinCampus($lat, $lng);
if (!$gps['passed']) {
    AuditLog::record(Auth::id(), 'CLASS_ATTENDANCE_DENIED_GPS', 'class_sessions', null,
        "distance={$gps['distance_meters']}m radius={$gps['radius_meters']}m");
    echo json_encode([
        'ok' => false,
        'message' => "You appear to be {$gps['distance_meters']}m from campus (allowed radius: {$gps['radius_meters']}m). Move closer and try again.",
    ]);
    exit;
}

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

// Legacy static module_qr_secret validation
if ($qrData === '' && $qrToken !== '') {
    $tokenCheck = $db->prepare('SELECT module_id FROM modules WHERE module_id = :id AND module_qr_secret = :t');
    $tokenCheck->execute(['id' => $moduleId, 't' => $qrToken]);
    if (!$tokenCheck->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'Invalid or expired QR code. Ask your HOD to reprint the classroom QR.']);
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
    echo json_encode(['ok' => false, 'message' => 'You are not registered for this module, or it is not ongoing.']);
    exit;
}

// ── Date-bounds enforcement ───────────────────────────────────────────
// Attendance is only valid within the module's start_date to end_date.
$today = ClassAttendance::now()->format('Y-m-d');
if ($module['start_date'] && $today < $module['start_date']) {
    echo json_encode(['ok' => false, 'message' => 'This module has not started yet. Attendance begins on ' . date('d M Y', strtotime($module['start_date'])) . '.']);
    exit;
}
if ($module['end_date'] && $today > $module['end_date']) {
    echo json_encode(['ok' => false, 'message' => 'This module\'s attendance period ended on ' . date('d M Y', strtotime($module['end_date'])) . '.']);
    exit;
}

$window = ClassAttendance::currentWindow();
$now    = ClassAttendance::now();

// ── Determine whether we're in Sign In window or Sign Out window ──────────
// Sign In  : session is active AND elapsed < 20 minutes.
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
    $sessionAlreadyExisted = true;
    $allowSignOut          = true;  // dynamic QR is valid both for sign-in and sign-out
} else {
    // ── Legacy / window-based path ────────────────────────────────────────
    $todayFind = $db->prepare('SELECT * FROM class_sessions WHERE module_id = :mid AND session_date = CURDATE() ORDER BY start_time DESC LIMIT 1');
    $todayFind->execute(['mid' => $moduleId]);
    $todaySession = $todayFind->fetch();

    if ($todaySession) {
        $endDt    = new DateTime($todaySession['end_time'], new DateTimeZone('Africa/Kigali'));
        $afterEnd = $now->getTimestamp() - $endDt->getTimestamp();
        $allowSignOut = ($afterEnd >= 0 && $afterEnd <= 900);
    }

    if (!$window && !$allowSignOut) {
        $holiday = ClassAttendance::holidayToday();
        $msg     = $holiday && $holiday['holiday_type'] === 'Public Holiday'
            ? 'Today is a public holiday — no attendance scanning is required.'
            : 'There is no active class session window right now.';
        echo json_encode(['ok' => false, 'message' => $msg]);
        exit;
    }

    if (!$sessionMatches && !$allowSignOut) {
        echo json_encode(['ok' => false, 'message' => 'This module\'s session (' . $module['session_type'] . ') does not match the currently active window (' . ($window ? ClassAttendance::describeWindow($window) : 'none') . ').']);
        exit;
    }

    $find       = $db->prepare('SELECT * FROM class_sessions WHERE module_id = :mid AND session_date = CURDATE() AND window_name = :win');
    $windowName = $window ? $window['name'] : ($todaySession['window_name'] ?? null);

    if (!$windowName) {
        echo json_encode(['ok' => false, 'message' => 'Could not determine session window.']);
        exit;
    }

    $find->execute(['mid' => $moduleId, 'win' => $windowName]);
    $session = $find->fetch();
    $sessionAlreadyExisted = (bool) $session;

    if (!$session && $window) {
        try {
            $db->prepare(
                'INSERT INTO class_sessions (module_id, session_date, window_name, start_time, end_time, qr_secret, created_by)
                 VALUES (:mid, CURDATE(), :win, :start, :end, :secret, :uid)'
            )->execute([
                'mid'    => $moduleId,
                'win'    => $window['name'],
                'start'  => $window['start']->format('Y-m-d H:i:s'),
                'end'    => $window['end']->format('Y-m-d H:i:s'),
                'secret' => QrService::generateSecret(),
                'uid'    => $me['user_id'],
            ]);
            $find->execute(['mid' => $moduleId, 'win' => $window['name']]);
            $session = $find->fetch();
        } catch (PDOException $e) {
            $find->execute(['mid' => $moduleId, 'win' => $window['name']]);
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

$realSignInRow = $db->prepare("SELECT 1 FROM class_attendance_logs WHERE session_id = :s AND user_id = :u AND attendance_type = 'Sign In' AND verification_method != 'Auto'");
$realSignInRow->execute(['s' => $session['session_id'], 'u' => $me['user_id']]);
$hasRealSignIn = (bool) $realSignInRow->fetch();

$statusVal = ClassAttendance::statusFor($session['start_time']);

if (!$hasRealSignIn) {
    // Attempting Sign In — must always be a QR scan, manual check-in is not allowed.
    if ($isManual) {
        echo json_encode(['ok' => false, 'message' => 'Sign In requires scanning the QR code. Manual check-in is only available for Sign Out, near the end of the session.']);
        exit;
    }
    if ($statusVal === 'Absent') {
        echo json_encode(['ok' => false, 'message' => 'The sign-in window has closed (more than 20 minutes since class started). You have been marked Absent for this session.']);
        exit;
    }
    $type   = 'Sign In';
    $status = $statusVal;
} else {
    // Student already signed in — this must be a Sign Out
    if ($isManual) {
        // Manual sign-out is only allowed in the 40-to-20-minute window
        // before the session ends (QR scanning may be congested as
        // everyone rushes to leave).
        $sessEndDt    = new DateTime((string) $session['end_time'], new DateTimeZone('Africa/Kigali'));
        $minutesToEnd = ($sessEndDt->getTimestamp() - $now->getTimestamp()) / 60;
        if ($minutesToEnd > 40 || $minutesToEnd < 20) {
            echo json_encode(['ok' => false, 'message' => 'Manual sign-out is only available between 20 and 40 minutes before the session ends. Please scan the QR code instead.']);
            exit;
        }
    } elseif (!$allowSignOut && $window) {
        echo json_encode(['ok' => false, 'message' => 'Sign-out is only available within 15 minutes after the session ends. Your sign-in has been recorded.']);
        exit;
    }
    $type   = 'Sign Out';
    $status = 'Present';

    // Sign Out requires being noticeably closer than the general campus
    // radius — right by the classroom/QR, not just anywhere on campus.
    $scanOutGps = GpsService::withinCampus($lat, $lng, null, null, SCAN_OUT_RADIUS_METERS);
    if (!$scanOutGps['passed']) {
        AuditLog::record(Auth::id(), 'CLASS_ATTENDANCE_DENIED_GPS_SCANOUT', 'class_sessions', (int) $session['session_id'],
            "distance={$scanOutGps['distance_meters']}m radius={$scanOutGps['radius_meters']}m");
        echo json_encode([
            'ok'      => false,
            'message' => "Sign-out requires you to be closer to the classroom — you appear to be {$scanOutGps['distance_meters']}m away (allowed: {$scanOutGps['radius_meters']}m). Move closer and try again.",
        ]);
        exit;
    }
}

// ── IP deduplication ─────────────────────────────────────────────────────
$ipAlready = $db->prepare('SELECT 1 FROM class_attendance_logs WHERE session_id = :s AND ip_address = :ip AND attendance_type = :type');
$ipAlready->execute(['s' => $session['session_id'], 'ip' => $ip, 'type' => $type]);
if ($ipAlready->fetch()) {
    echo json_encode(['ok' => false, 'message' => 'A ' . $type . ' has already been recorded from this device/network for this session. If this is a shared lab computer, ask your lecturer to mark you manually.']);
    exit;
}

// ── Device deduplication — a persisted client-side token, stronger than
//    shared-WiFi IP alone (catches "friend scans for me" from a different
//    network) ────────────────────────────────────────────────────────────
if ($deviceId !== null) {
    $devAlready = $db->prepare('SELECT 1 FROM class_attendance_logs WHERE session_id = :s AND device_id = :d AND attendance_type = :type');
    $devAlready->execute(['s' => $session['session_id'], 'd' => $deviceId, 'type' => $type]);
    if ($devAlready->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'A ' . $type . ' has already been recorded from this device for this session. If this is a shared device, ask your lecturer to mark you manually.']);
        exit;
    }
}

$gpsParams = [
    'lat'  => $lat,
    'lng'  => $lng,
    'dist' => $gps['distance_meters'],
    'pass' => $gps['passed'] ? 1 : 0,
    'dev'  => $deviceId,
];

// ── Record ───────────────────────────────────────────────────────────────
try {
    if ($type === 'Sign In') {
        $upd = $db->prepare(
            "UPDATE class_attendance_logs
             SET status=:status, verification_method='QR', ip_address=:ip, checkin_time=NOW(),
                 latitude=:lat, longitude=:lng, distance_meters=:dist, gps_passed=:pass, device_id=:dev
             WHERE session_id=:s AND user_id=:u AND attendance_type='Sign In' AND verification_method='Auto'"
        );
        $upd->execute($gpsParams + ['status' => $status, 'ip' => $ip, 's' => $session['session_id'], 'u' => $me['user_id']]);
        if ($upd->rowCount() === 0) {
            $db->prepare(
                "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method, ip_address, latitude, longitude, distance_meters, gps_passed, device_id)
                 VALUES (:s, :u, 'Sign In', :status, 'QR', :ip, :lat, :lng, :dist, :pass, :dev)"
            )->execute($gpsParams + ['s' => $session['session_id'], 'u' => $me['user_id'], 'status' => $status, 'ip' => $ip]);
        }
    } else {
        $db->prepare(
            "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method, ip_address, checkin_time, latitude, longitude, distance_meters, gps_passed, device_id)
             VALUES (:s, :u, 'Sign Out', 'Present', :vm, :ip, NOW(), :lat, :lng, :dist, :pass, :dev)"
        )->execute($gpsParams + ['s' => $session['session_id'], 'u' => $me['user_id'], 'ip' => $ip, 'vm' => $isManual ? 'Manual' : 'QR']);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo json_encode(['ok' => false, 'message' => 'This ' . $type . ' could not be recorded — it may already exist. Ask your lecturer to mark you manually if needed.']);
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

$label = $type === 'Sign In' ? "Checked in / marked <strong>$status</strong>." : 'Signed out successfully.';
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
             AND cal.verification_method != 'Auto'
         WHERE cs.module_id = :mid"
    );
    $stmt2->execute(['uid' => $student['user_id'], 'mid' => $module['module_id']]);
    $absences = (int) $stmt2->fetchColumn();

    if ($absences === 2) {
        NotificationCenter::notify(
            $student['user_id'],
            'Attendance Warning / ' . $module['module_title'],
            'You have missed 2 sessions of "' . $module['module_title'] . '". Missing a third session may affect your CAT/Exam eligibility. Please contact your HOD if you have a valid reason.',
            'Attendance'
        );
    } elseif ($absences >= 3) {
        NotificationCenter::notify(
            $student['user_id'],
            'Attendance Alert / ' . $module['module_title'],
            'You have missed ' . $absences . ' sessions of "' . $module['module_title'] . '". You may be marked ineligible for the CAT/Exam. Contact your HOD immediately.',
            'Attendance'
        );
        // Send email + SMS for 3rd+ absence
        if (!empty($student['email'])) {
            Mailer::send(
                $student['email'],
                $student['full_name'],
                'Attendance Warning / ' . $module['module_title'],
                '<p>Dear ' . htmlspecialchars($student['full_name']) . ',</p>' .
                '<p>You have exceeded the permitted absences for <strong>' . htmlspecialchars($module['module_title']) . '</strong>. ' .
                'You have missed <strong>' . $absences . ' sessions</strong>.</p>' .
                '<p>Please contact your Head of Department immediately.</p>'
            );
        }
        if (!empty($student['phone_number']) && ($student['sms_opt_in'] ?? 1)) {
            Delivery::sendSms(
                $student['phone_number'],
                'SEMAS Alert: You have missed ' . $absences . ' sessions of "' . $module['module_title'] . '". Contact your HOD immediately.',
                $student['user_id']
            );
        }
    }
}
