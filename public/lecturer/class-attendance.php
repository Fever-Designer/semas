<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();
Module::autoCompleteExpired();

$pageTitle = 'Class Attendance';
$activeNav = 'class-attendance';
$db        = Database::connection();
$me        = Auth::user();
$today     = ClassAttendance::now()->format('Y-m-d');

/** Which exam_type cutoff window (per Eligibility::generate()'s own cutoff
 *  rules) a session date falls under, or null if it's outside both. */
function attendance_window_for_date(string $sessionDate, ?string $catDate, ?string $examDate): ?string
{
    if ($catDate && $sessionDate < $catDate) return 'CAT';
    if ($catDate && $examDate && $sessionDate > $catDate && $sessionDate < $examDate) return 'Exam';
    return null;
}

function lecturer_module_matches_window(array $module, ?array $window): bool
{
    if (!$window) {
        return false;
    }
    $sessionType = $module['session_type'] ?? '';
    $slot = $module['weekend_slot'] ?? '';
    if ($sessionType === 'Day') {
        return $window['name'] === 'Day';
    }
    if ($sessionType === 'Evening') {
        return $window['name'] === 'Evening';
    }
    if ($sessionType === 'Weekend') {
        if ($slot === 'Morning') {
            return in_array($window['name'], ['WeekendMorning', 'UmugandaMorning'], true);
        }
        if ($slot === 'Afternoon') {
            return in_array($window['name'], ['WeekendAfternoon', 'UmugandaAfternoon'], true);
        }
        return in_array($window['name'], ['WeekendMorning', 'WeekendAfternoon', 'UmugandaMorning', 'UmugandaAfternoon'], true);
    }
    return false;
}

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action   = $_POST['action']    ?? '';
    $moduleId = (int) ($_POST['module_id'] ?? 0);

    if ($action === 'manual_mark') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $userId    = (int) ($_POST['user_id']    ?? 0);
        $mark      = $_POST['mark_status'] ?? '';
        if (!in_array($mark, ['Present', 'Late', 'Absent'], true) || !$sessionId || !$userId) {
            flash('error', 'Invalid mark request.');
            redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
        }

        // Locked if a Pending/Approved submission covers this session's date.
        $sessDateRow = $db->prepare(
            "SELECT cs.session_date, m.cat_date, m.exam_date FROM class_sessions cs
             JOIN modules m ON m.module_id = cs.module_id WHERE cs.session_id = :sid"
        );
        $sessDateRow->execute(['sid' => $sessionId]);
        $sdRow = $sessDateRow->fetch();
        if ($sdRow) {
            $coveringType = attendance_window_for_date($sdRow['session_date'], $sdRow['cat_date'], $sdRow['exam_date']);
            if ($coveringType) {
                $lockCheck = $db->prepare(
                    "SELECT status FROM module_attendance_submissions
                     WHERE module_id = :mid AND exam_type = :type AND status IN ('Pending','Approved')"
                );
                $lockCheck->execute(['mid' => $moduleId, 'type' => $coveringType]);
                if ($lockCheck->fetch()) {
                    flash('error', "This session's attendance has been submitted for $coveringType and is locked. Ask the HOD/Coordinator to reject the submission if a correction is needed.");
                    redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
                }
            }
        }

        // Lecturers can only manually mark during the live class window
        $sessInfo = $db->prepare("SELECT start_time, end_time FROM class_sessions WHERE session_id = :sid");
        $sessInfo->execute(['sid' => $sessionId]);
        $sessData = $sessInfo->fetch();
        $signInTime  = null;
        $signOutTime = null;
        if ($sessData && $sessData['start_time'] && $sessData['end_time']) {
            $tz      = new DateTimeZone('Africa/Kigali');
            $nowDt   = new DateTime('now', $tz);
            $startDt = new DateTime($sessData['start_time'], $tz);
            $endDt   = new DateTime($sessData['end_time'], $tz);
            if ($nowDt < $startDt || $nowDt > $endDt) {
                flash('error', 'Manual marking is only allowed during the class session ('
                    . $startDt->format('H:i') . ' – ' . $endDt->format('H:i') . ').');
                redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
            }
            $signInTime  = $sessData['start_time'];
            $signOutTime = $sessData['end_time'];
        }
        $db->prepare("DELETE FROM class_attendance_logs WHERE session_id = :sid AND user_id = :uid")
           ->execute(['sid' => $sessionId, 'uid' => $userId]);
        if ($mark === 'Absent') {
            $db->prepare(
                "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method)
                 VALUES (:sid, :uid, 'Sign In', 'Absent', 'Auto')"
            )->execute(['sid' => $sessionId, 'uid' => $userId]);
        } else {
            // Sign-in at class start time, sign-out at class end time
            $db->prepare(
                "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method, checkin_time)
                 VALUES (:sid, :uid, 'Sign In', :st, 'Manual', :cin)"
            )->execute(['sid' => $sessionId, 'uid' => $userId, 'st' => $mark, 'cin' => $signInTime]);
            $db->prepare(
                "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method, checkin_time)
                 VALUES (:sid, :uid, 'Sign Out', 'Present', 'Manual', :cout)"
            )->execute(['sid' => $sessionId, 'uid' => $userId, 'cout' => $signOutTime]);
        }
        AuditLog::record(Auth::id(), 'MANUAL_MARK_ATTENDANCE', 'class_sessions', $sessionId, "user={$userId};mark={$mark}");
        flash('success', 'Attendance updated.');
        redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
    }

    if ($action === 'manual_auto_mark') {
        $regNum = trim($_POST['reg_number'] ?? '');
        $confirmedUserId = (int) ($_POST['confirmed_user_id'] ?? 0);
        if (!$moduleId || $regNum === '') {
            flash('error', 'Registration number is required.');
            redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
        }
        if (!$confirmedUserId) {
            flash('error', 'Please search and confirm the student profile before recording attendance.');
            redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
        }

        $modStmt = $db->prepare(
            "SELECT m.*, lt.user_id AS lecturer_user_id
             FROM modules m
             JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
             WHERE m.module_id = :mid AND m.status = 'Ongoing'"
        );
        $modStmt->execute(['mid' => $moduleId]);
        $modRow = $modStmt->fetch();
        if (!$modRow || (int) $modRow['lecturer_user_id'] !== (int) $me['user_id']) {
            flash('error', 'Module not found or not assigned to you.');
            redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
        }

        $window = ClassAttendance::currentWindow();
        if (!$window) {
            flash('error', 'Manual attendance is only available during an active class session.');
            redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
        }

        if (!lecturer_module_matches_window($modRow, $window)) {
            flash('error', 'This module does not match the active session window.');
            redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
        }

        $stuStmt = $db->prepare(
            "SELECT u.user_id, u.full_name
             FROM users u
             JOIN module_enrollments e ON e.user_id = u.user_id AND e.module_id = :mid
             WHERE u.user_id = :uid AND u.reg_number = :reg AND u.status = 'Active'"
        );
        $stuStmt->execute(['mid' => $moduleId, 'uid' => $confirmedUserId, 'reg' => $regNum]);
        $student = $stuStmt->fetch();
        if (!$student) {
            flash('error', 'The confirmed student profile does not match an active enrolled student.');
            redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
        }

        $todayForAttendance = ClassAttendance::now()->format('Y-m-d');
        $find = $db->prepare(
            'SELECT * FROM class_sessions
             WHERE module_id = :mid AND session_date = :today AND window_name = :win
             ORDER BY session_id DESC LIMIT 1'
        );
        $find->execute(['mid' => $moduleId, 'today' => $todayForAttendance, 'win' => $window['name']]);
        $session = $find->fetch();
        if (!$session) {
            try {
                $db->prepare(
                    'INSERT INTO class_sessions (module_id, session_date, window_name, start_time, end_time, qr_secret, status, created_by)
                     VALUES (:mid, :today, :win, :start, :end, :secret, "Open", :uid)'
                )->execute([
                    'mid' => $moduleId,
                    'today' => $todayForAttendance,
                    'win' => $window['name'],
                    'start' => $window['start']->format('Y-m-d H:i:s'),
                    'end' => $window['end']->format('Y-m-d H:i:s'),
                    'secret' => QrService::generateSecret(),
                    'uid' => $me['user_id'],
                ]);
                $newSid = (int) $db->lastInsertId();
                $db->prepare(
                    "INSERT IGNORE INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method)
                     SELECT :sid, e.user_id, 'Sign In', 'Absent', 'Auto'
                     FROM module_enrollments e WHERE e.module_id = :mid"
                )->execute(['sid' => $newSid, 'mid' => $moduleId]);
            } catch (PDOException $e) {
                // Session may have been opened by a QR scan at the same moment.
            }
            $find->execute(['mid' => $moduleId, 'today' => $todayForAttendance, 'win' => $window['name']]);
            $session = $find->fetch();
        }

        if (!$session || $session['status'] !== 'Open') {
            flash('error', 'No open class session is available for manual attendance.');
            redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
        }

        $uid = (int) $student['user_id'];
        $signedOut = $db->prepare("SELECT 1 FROM class_attendance_logs WHERE session_id = :sid AND user_id = :uid AND attendance_type = 'Sign Out'");
        $signedOut->execute(['sid' => $session['session_id'], 'uid' => $uid]);
        if ($signedOut->fetchColumn()) {
            flash('error', 'Attendance has already been recorded.');
            redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
        }

        $realSignIn = $db->prepare("SELECT 1 FROM class_attendance_logs WHERE session_id = :sid AND user_id = :uid AND attendance_type = 'Sign In' AND verification_method IN ('QR','Manual')");
        $realSignIn->execute(['sid' => $session['session_id'], 'uid' => $uid]);
        if ($realSignIn->fetchColumn()) {
            flash('error', 'Attendance has already been recorded.');
            redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
        }

        $status = 'Present';

        $manualSignInUpdate = $db->prepare(
            "UPDATE class_attendance_logs
             SET status = :status, verification_method = 'Manual', confirmed_by = :by, checkin_time = :signin_time
             WHERE session_id = :sid AND user_id = :uid AND attendance_type = 'Sign In'
               AND verification_method NOT IN ('QR','Manual')"
        );
        $manualSignInUpdate->execute([
            'status' => $status,
            'by' => $me['user_id'],
            'signin_time' => $session['start_time'],
            'sid' => $session['session_id'],
            'uid' => $uid,
        ]);
        if ($manualSignInUpdate->rowCount() === 0) {
            $exists = $db->prepare("SELECT 1 FROM class_attendance_logs WHERE session_id = :sid AND user_id = :uid AND attendance_type = 'Sign In'");
            $exists->execute(['sid' => $session['session_id'], 'uid' => $uid]);
            if (!$exists->fetchColumn()) {
                $db->prepare(
                    "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method, confirmed_by, checkin_time)
                     VALUES (:sid, :uid, 'Sign In', :status, 'Manual', :by, :signin_time)"
                )->execute([
                    'sid' => $session['session_id'],
                    'uid' => $uid,
                    'status' => $status,
                    'by' => $me['user_id'],
                    'signin_time' => $session['start_time'],
                ]);
            }
        }

        $db->prepare(
            "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method, confirmed_by, checkin_time)
             VALUES (:sid, :uid, 'Sign Out', 'Present', 'Manual', :by, :signout_time)
             ON DUPLICATE KEY UPDATE status = VALUES(status), verification_method = VALUES(verification_method),
                 confirmed_by = VALUES(confirmed_by), checkin_time = VALUES(checkin_time)"
        )->execute([
            'sid' => $session['session_id'],
            'uid' => $uid,
            'by' => $me['user_id'],
            'signout_time' => $session['end_time'],
        ]);

        AuditLog::record(Auth::id(), 'MANUAL_SIGNIN_ATTENDANCE', 'class_sessions', (int) $session['session_id'], "user={$uid};status={$status}");
        flash('success', $student['full_name'] . ' signed manually as Present with class start/end times.');
        redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
    }

    if ($action === 'create_session') {
        $sessDate   = trim($_POST['session_date'] ?? '');
        $windowName = trim($_POST['window_name']  ?? '');
        if (!$sessDate || !$windowName || !$moduleId) {
            flash('error', 'Date and window are required.');
            redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
        }
        $defaultTimes = [
            'Day' => ['08:00:00','13:00:00'], 'Evening' => ['17:00:00','21:00:00'],
            'WeekendMorning' => ['08:00:00','13:00:00'], 'WeekendAfternoon' => ['13:00:00','17:00:00'],
            'UmugandaMorning' => ['08:00:00','13:00:00'], 'UmugandaAfternoon' => ['13:00:00','17:00:00'],
        ];
        [$defStart, $defEnd] = $defaultTimes[$windowName] ?? ['08:00:00','17:00:00'];
        try {
            $db->prepare(
                "INSERT INTO class_sessions (module_id, session_date, window_name, start_time, end_time, status, created_by)
                 VALUES (:mid, :date, :win, :st, :en, 'Open', :uid)"
            )->execute([
                'mid' => $moduleId, 'date' => $sessDate, 'win' => $windowName,
                'st' => $sessDate . ' ' . $defStart, 'en' => $sessDate . ' ' . $defEnd,
                'uid' => $me['user_id'],
            ]);
            $newSid = (int) $db->lastInsertId();
            $db->prepare(
                "INSERT IGNORE INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method)
                 SELECT :sid, e.user_id, 'Sign In', 'Absent', 'Auto'
                 FROM module_enrollments e WHERE e.module_id = :mid"
            )->execute(['sid' => $newSid, 'mid' => $moduleId]);
            AuditLog::record(Auth::id(), 'CREATE_SESSION_MANUAL', 'class_sessions', $newSid);
            flash('success', 'Session added. Students pre-marked Absent — update individually as needed.');
        } catch (PDOException $e) {
            flash('error', 'Session already exists for this date/window, or an error occurred.');
        }
        redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
    }

    redirect('/lecturer/class-attendance.php');
}

// ── Lecturer's modules ─────────────────────────────────────────────────────
$allModules = $db->prepare(
    "SELECT m.module_id, m.module_title, m.session_type, m.weekend_slot, m.status,
            m.start_date, m.end_date, m.cat_date, m.exam_date,
            d.department_name, COALESCE(lt.title,'') AS lecturer_title,
            u.full_name AS lecturer_name, r.room_name
     FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers lt  ON lt.lecturer_id  = m.lecturer_id
     LEFT JOIN users u       ON u.user_id        = lt.user_id
     LEFT JOIN rooms r       ON r.room_id        = m.room_id
     WHERE lt.user_id = :uid AND m.status = 'Ongoing'
     ORDER BY m.module_title"
);
$allModules->execute(['uid' => $me['user_id']]);
$allModules = $allModules->fetchAll();

// A lecturer can open the attendance register / take manual actions for any
// of their Ongoing modules at any time — only the live QR "Sign" screen
// (live-session.php / api/lecturer-session-qr.php) is restricted to the
// module's actual scan window.
$nowDt        = ClassAttendance::now();
$window       = ClassAttendance::currentWindow();
$holidayToday = ClassAttendance::holidayToday();
$visibleModules = array_values(array_filter($allModules, function ($module) use ($window) {
    return lecturer_module_matches_window($module, $window);
}));

$moduleId = (int) ($_GET['module_id'] ?? 0);
$module   = null;
foreach ($visibleModules as $am) {
    if ((int) $am['module_id'] === $moduleId) { $module = $am; break; }
}

// ── Attendance data ────────────────────────────────────────────────────────
$sessions   = [];
$students   = [];
$attMap     = [];
$holidayMap = [];

if ($module) {
    $excludeDates = array_values(array_filter([$module['cat_date'], $module['exam_date']]));

    $sessStmt = $db->prepare(
        "SELECT session_id, session_date, window_name, start_time, end_time, status
         FROM class_sessions WHERE module_id = :mid ORDER BY session_date ASC, start_time ASC"
    );
    $sessStmt->execute(['mid' => $moduleId]);
    $allSess = $sessStmt->fetchAll();
    $sessions = array_values(array_filter($allSess, function ($s) use ($excludeDates, $today, $module) {
        return $s['session_date'] <= $today
            && !in_array($s['session_date'], $excludeDates, true)
            && lecturer_module_matches_window($module, ['name' => (string) $s['window_name']]);
    }));

    foreach ($db->query("SELECT holiday_date FROM holidays")->fetchAll() as $h) {
        $holidayMap[$h['holiday_date']] = true;
    }

    $stuStmt = $db->prepare(
        "SELECT u.user_id, u.full_name, u.reg_number, u.phone_number, u.photo_path, d.department_name
         FROM users u JOIN module_enrollments e ON e.user_id = u.user_id
         LEFT JOIN departments d ON d.department_id = u.department_id
         WHERE e.module_id = :mid AND u.status = 'Active' ORDER BY u.full_name"
    );
    $stuStmt->execute(['mid' => $moduleId]);
    $students = $stuStmt->fetchAll();

    $logStmt = $db->prepare(
        "SELECT cal.user_id, cal.session_id, cal.attendance_type, cal.status,
                cal.verification_method, cal.checkin_time
         FROM class_attendance_logs cal
         JOIN class_sessions cs ON cs.session_id = cal.session_id
         WHERE cs.module_id = :mid"
    );
    $logStmt->execute(['mid' => $moduleId]);
    foreach ($logStmt->fetchAll() as $log) {
        $sid = (int) $log['session_id'];
        $uid = (int) $log['user_id'];
        if (!isset($attMap[$sid][$uid])) {
            $attMap[$sid][$uid] = ['in_time' => null, 'in_status' => null, 'out_time' => null, 'is_auto' => true];
        }
        if ($log['attendance_type'] === 'Sign In') {
            $attMap[$sid][$uid]['in_status'] = $log['status'];
            $isAuto = !in_array((string) $log['verification_method'], ['QR', 'Manual'], true);
            $attMap[$sid][$uid]['is_auto'] = $isAuto;
            if (!$isAuto) {
                $attMap[$sid][$uid]['in_time'] = $log['checkin_time'] ? date('H:i', strtotime((string) $log['checkin_time'])) : null;
            }
        } else {
            $attMap[$sid][$uid]['out_time'] = $log['checkin_time'] ? date('H:i', strtotime((string) $log['checkin_time'])) : null;
        }
    }
}

$manualRoster = [];
foreach ($students as $stu) {
    $uid = (int) $stu['user_id'];
    $already = false;
    $recordedLabel = '';
    foreach ($sessions as $s) {
        if ($s['session_date'] !== $today || !$window || $s['window_name'] !== $window['name']) {
            continue;
        }
        $entry = $attMap[(int) $s['session_id']][$uid] ?? null;
        if ($entry && !$entry['is_auto']) {
            $already = true;
            $recordedLabel = trim(($entry['in_status'] ?? 'Recorded') . ' ' . ($entry['in_time'] ?? ''));
            break;
        }
    }
    $manualRoster[] = [
        'user_id' => $uid,
        'full_name' => $stu['full_name'],
        'reg_number' => $stu['reg_number'],
        'phone_number' => $stu['phone_number'],
        'department' => $stu['department_name'] ?? '',
        'photo_url' => !empty($stu['photo_path'])
            ? APP_URL . '/' . $stu['photo_path']
            : 'https://ui-avatars.com/api/?name=' . urlencode($stu['full_name']) . '&background=1E2A52&color=fff',
        'already_recorded' => $already,
        'recorded_label' => $recordedLabel,
    ];
}

function lec_att_status(?array $e, string $date, string $today): string
{
    if (!$e || $e['is_auto'])                                return $date <= $today ? 'A' : '';
    if ($e['in_status'] === 'Present' && $e['out_time'])     return 'P';
    if ($e['in_status'] === 'Late'    && $e['out_time'])     return 'L';
    return 'A';
}

// ── Module-wide "All Numbers" summary ───────────────────────────────────────
// Distinguishes WHY a student counts as Absent on a given session — missed
// signing in entirely vs. signed in but never signed out — instead of just
// the lumped 'A' shown in each grid cell.
$summary = ['missed_signin' => 0, 'no_signout' => 0, 'present' => 0, 'late' => 0, 'students_at_risk' => 0];
foreach ($students as $stu) {
    $uid = (int) $stu['user_id'];
    $studentAbsences = 0;
    foreach ($sessions as $s) {
        if (isset($holidayMap[$s['session_date']]) || $s['session_date'] > $today) continue;
        $entry = $attMap[(int) $s['session_id']][$uid] ?? null;
        if (!$entry || $entry['is_auto']) {
            $summary['missed_signin']++;
            $studentAbsences++;
        } elseif (empty($entry['out_time'])) {
            $summary['no_signout']++;
            $studentAbsences++;
        } elseif ($entry['in_status'] === 'Present') {
            $summary['present']++;
        } elseif ($entry['in_status'] === 'Late') {
            $summary['late']++;
        }
    }
    if ($studentAbsences >= 3) $summary['students_at_risk']++;
}

// ── CAT/Exam schedules + submission status for the selected module ─────────
$examSubmissions = [];
if ($module) {
    $schedRows = $db->prepare(
        "SELECT cs.exam_type, cs.scheduled_date,
                sub.status AS sub_status, sub.submitted_at, sub.decision_note,
                su.full_name AS submitted_by_name
         FROM cat_exam_schedules cs
         LEFT JOIN module_attendance_submissions sub ON sub.module_id = cs.module_id AND sub.exam_type = cs.exam_type
         LEFT JOIN users su ON su.user_id = sub.submitted_by
         WHERE cs.module_id = :mid"
    );
    $schedRows->execute(['mid' => $moduleId]);
    foreach ($schedRows->fetchAll() as $row) {
        $examSubmissions[$row['exam_type']] = $row;
    }
}

require __DIR__ . '/../partials/layout_top.php';
?>

<h4 class="display-font mb-3">Class Attendance Register</h4>

<!-- Local hour / day / date + active session status -->
<div class="alert <?= $window ? 'alert-success' : 'alert-secondary' ?> small mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <?php if ($window): ?>
      <i class="bi bi-broadcast me-1"></i> Active session: <strong><?= e(ClassAttendance::describeWindow($window)) ?></strong>
    <?php elseif ($holidayToday && $holidayToday['holiday_type'] === 'Public Holiday'): ?>
      <i class="bi bi-info-circle me-1"></i> Today is a <strong>Public Holiday</strong> — no attendance scanning today.
    <?php else: ?>
      <i class="bi bi-clock-history me-1"></i> No active class session window right now.
    <?php endif; ?>
  </div>
  <div class="text-end">
    <div class="text-muted small"><i class="bi bi-calendar-event me-1"></i><?= e($nowDt->format('l, d F Y')) ?></div>
    <div id="liveClock" class="display-font fw-bold" style="font-size:1.9rem;line-height:1.1;letter-spacing:.03em;"
         data-h="<?= (int) $nowDt->format('H') ?>"
         data-m="<?= (int) $nowDt->format('i') ?>"
         data-s="<?= (int) $nowDt->format('s') ?>">
      <?= e($nowDt->format('H:i:s')) ?>
    </div>
  </div>
</div>
<script>
(function () {
  var el = document.getElementById('liveClock');
  if (!el) return;
  var h = parseInt(el.dataset.h, 10), m = parseInt(el.dataset.m, 10), s = parseInt(el.dataset.s, 10);
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  setInterval(function () {
    s++;
    if (s >= 60) { s = 0; m++; }
    if (m >= 60) { m = 0; h++; }
    if (h >= 24) { h = 0; }
    el.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
  }, 1000);
})();
</script>

<!-- Module selector -->
<div class="semas-card p-3 mb-3">
  <?php if (!$visibleModules): ?>
    <p class="text-muted small mb-0">
      <?= $window
          ? 'No assigned module matches the current class session window.'
          : 'No active class session window right now.' ?>
    </p>
  <?php else: ?>
  <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
    <label class="form-label small fw-semibold mb-0 text-nowrap">My Module:</label>
    <select name="module_id" class="form-select form-select-sm flex-grow-1" style="max-width:420px;"
            onchange="this.form.submit()">
      <option value="">— Choose a module —</option>
      <?php foreach ($visibleModules as $am): ?>
        <option value="<?= (int) $am['module_id'] ?>"
                <?= (int) $am['module_id'] === $moduleId ? 'selected' : '' ?>>
          <?= e($am['module_title']) ?> &mdash; <?= e($am['session_type']) ?> [<?= e($am['status']) ?>]
        </option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>

<?php if (!$module): ?>
  <div class="semas-card p-5 text-center text-muted">
    <i class="bi bi-calendar3" style="font-size:2.5rem;opacity:.35;"></i>
    <p class="mt-3 mb-0">Select one of your modules above to view its attendance register.</p>
  </div>
<?php else: ?>

<?php
  $lTitle   = $module['lecturer_title'] ?? '';
  $lName    = $module['lecturer_name']  ?? 'TBA';
  $lecLabel = $lTitle ? strtoupper(rtrim((string) $lTitle, '.')) . '. ' . $lName : $lName;
  $slot     = $module['weekend_slot'] ?? '';
  $sessLabel = ($module['session_type'] === 'Weekend' && $slot) ? "Weekend – {$slot}" : $module['session_type'];
?>

<div class="semas-card p-3 mb-3">
  <div class="row g-2" style="font-size:.85rem;">
    <div class="col-sm-4">
      <span class="text-muted small">Module</span><br>
      <strong><?= e($module['module_title']) ?></strong>
    </div>
    <div class="col-sm-4">
      <span class="text-muted small">Lecturer &amp; Session</span><br>
      <strong><?= e($lecLabel) ?></strong> &nbsp;
      <span class="text-muted small"><?= e($sessLabel) ?></span>
    </div>
    <div class="col-sm-2">
      <span class="text-muted small">Room</span><br>
      <strong><?= e($module['room_name'] ?? '—') ?></strong>
    </div>
    <div class="col-sm-2">
      <span class="text-muted small">Period</span><br>
      <strong><?= e(date('d M Y', strtotime($module['start_date']))) ?></strong>
      <span class="text-muted">→</span>
      <strong><?= e(date('d M Y', strtotime($module['end_date']))) ?></strong>
    </div>
  </div>
  <div class="mt-2 d-flex gap-2 align-items-center flex-wrap">
    <span class="badge <?= $module['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?>">
      <?= e($module['status']) ?>
    </span>
    <span class="text-muted small"><?= count($students) ?> students &nbsp;·&nbsp; <?= count($sessions) ?> sessions</span>
    <a href="<?= APP_URL ?>/lecturer/attendance-sheet-print.php?module_id=<?= $moduleId ?>" target="_blank" class="btn btn-sm btn-outline-dark ms-auto">
      <i class="bi bi-printer me-1"></i> Print Attendance Sheet
    </a>
    <a href="<?= APP_URL ?>/lecturer/attendance-sheet-excel.php?module_id=<?= $moduleId ?>" class="btn btn-sm btn-outline-dark">
      <i class="bi bi-file-earmark-excel me-1"></i> Excel
    </a>
    <button type="button" class="btn btn-sm btn-semas-gold"
            data-bs-toggle="modal" data-bs-target="#manualAttendanceModal">
      <i class="bi bi-person-check me-1"></i> Manual Attendance
    </button>
  </div>
</div>

<!-- All Numbers — module-wide summary -->
<div class="semas-card p-3 mb-3">
  <p class="text-uppercase text-muted small fw-semibold mb-2" style="letter-spacing:.05em;">All Numbers</p>
  <div class="row g-2 text-center" style="font-size:.8rem;">
    <div class="col-6 col-md-3">
      <div class="p-2 rounded" style="background:#f8d7da;">
        <div class="fw-bold fs-5" style="color:#721c24;"><?= $summary['missed_signin'] ?></div>
        <div class="text-muted">Missed Sign In</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="p-2 rounded" style="background:#f8d7da;">
        <div class="fw-bold fs-5" style="color:#721c24;"><?= $summary['no_signout'] ?></div>
        <div class="text-muted">Signed In, Never Signed Out</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="p-2 rounded" style="background:#d4edda;">
        <div class="fw-bold fs-5" style="color:#155724;"><?= $summary['present'] + $summary['late'] ?></div>
        <div class="text-muted">Present / Late (complete)</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="p-2 rounded" style="background:#fff3cd;">
        <div class="fw-bold fs-5" style="color:#856404;"><?= $summary['students_at_risk'] ?></div>
        <div class="text-muted">Students with ≥3 Absences</div>
      </div>
    </div>
  </div>
</div>

<!-- CAT/Exam attendance submission -->
<?php if (false && $examSubmissions): ?>
<div class="semas-card p-3 mb-3">
  <p class="text-uppercase text-muted small fw-semibold mb-2" style="letter-spacing:.05em;">CAT / Exam Attendance Submission</p>
  <?php foreach ($examSubmissions as $examType => $row): ?>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 py-2 <?= $examType === 'CAT' ? 'border-bottom' : '' ?>">
      <div>
        <strong><?= e($examType) ?></strong>
        <span class="text-muted small">— scheduled <?= e(date('d M Y', strtotime($row['scheduled_date']))) ?></span>
        <?php if ($row['sub_status'] === 'Pending'): ?>
          <span class="badge badge-urgent ms-1">Submitted — Pending HOD Review</span>
          <span class="text-muted small d-block">By <?= e($row['submitted_by_name'] ?? '—') ?> on <?= e(date('d M Y, h:i A', strtotime($row['submitted_at']))) ?></span>
        <?php elseif ($row['sub_status'] === 'Approved'): ?>
          <span class="badge badge-completed ms-1"><i class="bi bi-lock-fill me-1"></i>Approved &amp; Locked</span>
        <?php elseif ($row['sub_status'] === 'Rejected'): ?>
          <span class="badge bg-secondary ms-1">Rejected — please correct and resubmit</span>
          <?php if ($row['decision_note']): ?><span class="text-muted small d-block">Reason: <?= e($row['decision_note']) ?></span><?php endif; ?>
        <?php else: ?>
          <span class="badge bg-secondary ms-1">Not submitted yet</span>
        <?php endif; ?>
      </div>
      <?php if ($row['sub_status'] !== 'Pending' && $row['sub_status'] !== 'Approved'): ?>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="submit_attendance">
          <input type="hidden" name="module_id" value="<?= $moduleId ?>">
          <input type="hidden" name="exam_type" value="<?= e($examType) ?>">
          <button class="btn btn-sm btn-semas-gold"><i class="bi bi-send me-1"></i> Submit Module Attendance for <?= e($examType) ?></button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$students): ?>
  <div class="semas-card p-4 text-center text-muted small">No students enrolled yet.</div>
<?php elseif (!$sessions): ?>
  <div class="semas-card p-4 text-center text-muted small">
    No sessions recorded yet. Open the live QR session or use <strong>Manual Attendance</strong> during the active class window.
  </div>
<?php else: ?>

<div class="d-flex gap-3 mb-2 flex-wrap" style="font-size:.75rem;">
  <span><span class="px-2 rounded fw-bold" style="background:#d4edda;color:#155724;">P ✓</span> Present</span>
  <span><span class="px-2 rounded fw-bold" style="background:#fff3cd;color:#856404;">L</span> Late</span>
  <span><span class="px-2 rounded fw-bold" style="background:#f8d7da;color:#721c24;">A</span> Absent / No sign-out</span>
  <span><span class="px-2 rounded fw-bold" style="background:#fff3cd;color:#856404;">H</span> Holiday</span>
  <span class="ms-auto text-muted">Eligibility: ≥ 75%</span>
</div>

<div class="semas-card p-0 mb-3">
  <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
    <table class="table table-bordered table-sm mb-0 align-middle" style="white-space:nowrap;font-size:.77rem;">
      <thead>
        <tr class="table-dark" style="font-size:.71rem;">
          <th class="text-center" style="min-width:30px;">#</th>
          <th style="min-width:95px;">Reg Number</th>
          <th style="min-width:170px;position:sticky;left:0;z-index:3;background:#212529;">Student Name</th>
          <th style="min-width:105px;">Phone Number</th>
          <?php foreach ($sessions as $s):
            $isHol   = isset($holidayMap[$s['session_date']]);
            $isToday = ($s['session_date'] === $today);
            $thStyle = $isHol ? 'background:#fff3cd;color:#856404;' : ($isToday ? 'background:#1a4a8a;' : '');
          ?>
            <th class="text-center" style="min-width:62px;vertical-align:middle;<?= $thStyle ?>">
              <div><?= date('d M', strtotime($s['session_date'])) ?></div>
              <div style="font-weight:400;opacity:.8;"><?= date('D', strtotime($s['session_date'])) ?></div>
              <?php if ($isHol): ?>
                <div style="font-size:.58rem;color:#856404;">HoL</div>
              <?php elseif (in_array($s['window_name'], ['WeekendMorning','UmugandaMorning'], true)): ?>
                <div style="font-size:.58rem;opacity:.7;">Morn</div>
              <?php elseif (in_array($s['window_name'], ['WeekendAfternoon','UmugandaAfternoon'], true)): ?>
                <div style="font-size:.58rem;opacity:.7;">Aftn</div>
              <?php endif; ?>
            </th>
          <?php endforeach; ?>
          <th class="text-center" style="min-width:36px;background:#d4edda;color:#155724;">P</th>
          <th class="text-center" style="min-width:36px;background:#fff3cd;color:#856404;">L</th>
          <th class="text-center" style="min-width:36px;background:#f8d7da;color:#721c24;">A</th>
          <th class="text-center" style="min-width:40px;">Tot</th>
          <th class="text-center" style="min-width:72px;">Attend %</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $idx => $stu):
          $uid  = (int) $stu['user_id'];
          $pCnt = 0; $lCnt = 0; $aCnt = 0;
          foreach ($sessions as $s) {
              if (isset($holidayMap[$s['session_date']]) || $s['session_date'] > $today) continue;
              $fs = lec_att_status($attMap[(int)$s['session_id']][$uid] ?? null, $s['session_date'], $today);
              if ($fs === 'P') $pCnt++;
              elseif ($fs === 'L') $lCnt++;
              elseif ($fs === 'A') $aCnt++;
          }
          $total    = $pCnt + $lCnt + $aCnt;
          $pct      = $total > 0 ? round(($pCnt + $lCnt) / $total * 100, 1) : 0;
          $eligible = $pct >= 75;
        ?>
        <tr>
          <td class="text-center text-muted"><?= $idx + 1 ?></td>
          <td style="color:#666;"><?= e($stu['reg_number'] ?? '—') ?></td>
          <td style="position:sticky;left:0;z-index:1;background:#fff;font-weight:600;min-width:170px;">
            <?= e($stu['full_name']) ?>
          </td>
          <td style="color:#666;"><?= e($stu['phone_number'] ?? '—') ?></td>
          <?php foreach ($sessions as $s):
            $sid   = (int) $s['session_id'];
            $isHol = isset($holidayMap[$s['session_date']]);
            $entry = $attMap[$sid][$uid] ?? null;
            $fs    = lec_att_status($entry, $s['session_date'], $today);
          ?>
          <?php if ($isHol): ?>
            <td class="text-center fw-bold" style="background:#fff3cd;color:#856404;">H</td>
          <?php elseif ($fs === ''): ?>
            <td class="text-center" style="color:#ddd;">—</td>
          <?php elseif (!$entry || $entry['is_auto']): ?>
            <td class="text-center" style="background:#f8d7da;padding:3px 2px;">
              <div class="fw-bold" style="color:#721c24;">A</div>
              <button type="button" class="btn btn-link p-0" style="font-size:.58rem;color:#aaa;line-height:1;"
                      onclick="openMark(<?= $sid ?>,<?= $uid ?>,<?= $moduleId ?>,'<?= e(addslashes($stu['full_name'])) ?>')">
                <i class="bi bi-pencil-fill"></i>
              </button>
            </td>
          <?php else: ?>
            <?php
              $hasOut = !empty($entry['out_time']);
              $inTime = $entry['in_time'] ?? '?';
              if ($fs === 'P')     { $bg = '#d4edda'; $fc = '#155724'; $sym = '✓'; }
              elseif ($fs === 'L') { $bg = '#fff3cd'; $fc = '#856404'; $sym = 'L'; }
              else                 { $bg = '#f8d7da'; $fc = '#721c24'; $sym = 'A'; }
            ?>
            <td style="background:<?= $bg ?>;color:<?= $fc ?>;text-align:center;line-height:1.4;padding:3px 3px;">
              <div style="font-size:.67rem;"><?= e($inTime) ?></div>
              <div style="font-size:.67rem;">
                <?= $hasOut ? e($entry['out_time']) : '<span style="color:#dc3545;font-size:.6rem;">No Out</span>' ?>
              </div>
              <div style="font-weight:700;font-size:.73rem;"><?= $sym ?></div>
              <button type="button" class="btn btn-link p-0"
                      style="font-size:.55rem;color:<?= $fc ?>;opacity:.7;line-height:1;"
                      onclick="openMark(<?= $sid ?>,<?= $uid ?>,<?= $moduleId ?>,'<?= e(addslashes($stu['full_name'])) ?>')">
                <i class="bi bi-pencil-fill"></i>
              </button>
            </td>
          <?php endif; ?>
          <?php endforeach; ?>
          <td class="text-center fw-bold" style="background:#d4edda;color:#155724;"><?= $pCnt ?></td>
          <td class="text-center fw-bold" style="background:#fff3cd;color:#856404;"><?= $lCnt ?></td>
          <td class="text-center fw-bold" style="background:#f8d7da;color:#721c24;"><?= $aCnt ?></td>
          <td class="text-center fw-semibold"><?= $total ?></td>
          <td class="text-center fw-bold">
            <span style="color:<?= $eligible ? '#155724' : '#721c24' ?>;"><?= number_format($pct, 1) ?>%</span>
            <?php if ($total > 0): ?>
              <i class="bi <?= $eligible ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"
                 style="font-size:.65rem;vertical-align:middle;"
                 title="<?= $eligible ? 'Eligible' : 'Below 75% / not eligible' ?>"></i>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; // sessions + students ?>

<!-- Add Session Modal -->
<?php if (false): ?>
<div class="modal fade" id="addSessionModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_session">
        <input type="hidden" name="module_id" value="<?= $moduleId ?>">
        <div class="modal-header py-2">
          <h6 class="modal-title display-font small">Add Session Date</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body py-2">
          <p class="text-muted small mb-3">Create a session for a class that was held but had no QR scans.</p>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Date <span class="text-danger">*</span></label>
            <input type="date" name="session_date" class="form-control form-control-sm" required
                   max="<?= $today ?>"
                   min="<?= e($module['start_date'] ?? $today) ?>">
          </div>
          <div>
            <label class="form-label small fw-semibold">Session Window <span class="text-danger">*</span></label>
            <select name="window_name" class="form-select form-select-sm" required>
              <?php
                $st    = $module['session_type'] ?? '';
                $wslot = $module['weekend_slot'] ?? '';
                if ($st === 'Day')     echo '<option value="Day">Day</option>';
                if ($st === 'Evening') echo '<option value="Evening">Evening</option>';
                if ($st === 'Weekend') {
                    if ($wslot !== 'Afternoon') echo '<option value="WeekendMorning">Weekend Morning</option>';
                    if ($wslot !== 'Morning')   echo '<option value="WeekendAfternoon">Weekend Afternoon</option>';
                }
              ?>
            </select>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-semas-gold btn-sm"><i class="bi bi-plus-circle me-1"></i> Add Session</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Manual Attendance Modal -->
<div class="modal fade" id="manualAttendanceModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" id="manualAttendanceForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="manual_auto_mark">
        <input type="hidden" name="module_id" value="<?= $moduleId ?>">
        <input type="hidden" name="confirmed_user_id" id="manualConfirmedUserId">
        <div class="modal-header">
          <h6 class="modal-title display-font">Manual Attendance</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label small fw-semibold">Registration Number <span class="text-danger">*</span></label>
          <div class="input-group">
            <input name="reg_number" id="manualRegNumber" class="form-control" autocomplete="off" required>
            <button class="btn btn-outline-dark" type="button" id="manualLookupBtn">
              <i class="bi bi-search me-1"></i> Search
            </button>
          </div>
          <div id="manualFeedback" class="alert alert-danger small py-2 px-3 mt-2 mb-0" style="display:none;"></div>
          <div id="manualPreview" class="border rounded p-3 mt-3" style="display:none;">
            <div class="d-flex gap-3 align-items-start">
              <img id="manualPhoto" src="" alt=""
                   onerror="this.onerror=null;this.src='https://ui-avatars.com/api/?name=Student&background=1E2A52&color=fff';"
                   style="width:82px;height:82px;border-radius:50%;object-fit:cover;border:3px solid var(--semas-gold);flex-shrink:0;">
              <div class="small">
                <div class="fw-semibold fs-6" id="manualName"></div>
                <div class="text-muted" id="manualReg"></div>
                <div class="text-muted" id="manualDept"></div>
                <div class="mt-2" id="manualStatus"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-semas-gold btn-sm" id="manualConfirmBtn" style="display:none;">
            <i class="bi bi-person-check me-1"></i> Confirm
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Manual Mark Modal -->
<div class="modal fade" id="markModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action"    value="manual_mark">
        <input type="hidden" name="module_id" value="<?= $moduleId ?>">
        <input type="hidden" name="session_id" id="markSid">
        <input type="hidden" name="user_id"    id="markUid">
        <div class="modal-header py-2">
          <h6 class="modal-title display-font small">Mark Attendance</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body py-3">
          <p class="small mb-3">Student: <strong id="markName"></strong></p>
          <div class="d-grid gap-2">
            <button type="submit" name="mark_status" value="Present" class="btn btn-sm btn-success">
              <i class="bi bi-check-circle me-1"></i> Mark Present
            </button>
            <button type="submit" name="mark_status" value="Late" class="btn btn-sm btn-warning">
              <i class="bi bi-clock-history me-1"></i> Mark Late
            </button>
            <button type="submit" name="mark_status" value="Absent" class="btn btn-sm btn-outline-danger">
              <i class="bi bi-x-circle me-1"></i> Mark Absent
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; // $module ?>

<script>
const manualRoster = <?= json_encode($manualRoster, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

function openMark(sid, uid, mid, name) {
    document.getElementById('markSid').value  = sid;
    document.getElementById('markUid').value  = uid;
    document.getElementById('markName').textContent = name;
    new bootstrap.Modal(document.getElementById('markModal')).show();
}

function manualSetFeedback(message) {
    const feedback = document.getElementById('manualFeedback');
    feedback.textContent = message || '';
    feedback.style.display = message ? '' : 'none';
}

function manualResetPreview() {
    document.getElementById('manualPreview').style.display = 'none';
    document.getElementById('manualConfirmBtn').style.display = 'none';
    document.getElementById('manualConfirmedUserId').value = '';
    manualSetFeedback('');
}

function manualLookupStudent() {
    const input = document.getElementById('manualRegNumber');
    const reg = input.value.trim().toLowerCase();
    manualResetPreview();
    if (!reg) {
        manualSetFeedback('Enter a registration number.');
        return;
    }
    const student = manualRoster.find(function (s) {
        return String(s.reg_number || '').toLowerCase() === reg;
    });
    if (!student) {
        manualSetFeedback('No active enrolled student found with that registration number.');
        return;
    }

    document.getElementById('manualConfirmedUserId').value = student.user_id;
    document.getElementById('manualPhoto').src = student.photo_url || '';
    document.getElementById('manualName').textContent = student.full_name || '-';
    document.getElementById('manualReg').textContent = 'Reg. No: ' + (student.reg_number || '-');
    document.getElementById('manualDept').textContent = student.department || '';
    document.getElementById('manualPreview').style.display = '';

    if (student.already_recorded) {
        document.getElementById('manualStatus').innerHTML =
            '<span class="badge bg-danger">Already scanned</span>' +
            (student.recorded_label ? ' <span class="text-muted">' + escapeHtml(student.recorded_label) + '</span>' : '');
        document.getElementById('manualConfirmBtn').style.display = 'none';
    } else {
        document.getElementById('manualStatus').innerHTML = '<span class="badge badge-completed">Ready to confirm</span>';
        document.getElementById('manualConfirmBtn').style.display = '';
    }
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (ch) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[ch];
    });
}

document.getElementById('manualLookupBtn')?.addEventListener('click', manualLookupStudent);
document.getElementById('manualRegNumber')?.addEventListener('input', manualResetPreview);
document.getElementById('manualRegNumber')?.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        manualLookupStudent();
    }
});
document.getElementById('manualAttendanceForm')?.addEventListener('submit', function (event) {
    if (!document.getElementById('manualConfirmedUserId').value) {
        event.preventDefault();
        manualSetFeedback('Search and confirm the student profile first.');
    }
});
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
