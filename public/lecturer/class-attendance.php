<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();
Module::autoCompleteExpired();

$pageTitle = 'Class Attendance';
$activeNav = 'class-attendance';
$db        = Database::connection();
$me        = Auth::user();
$today     = date('Y-m-d');

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

// Only modules whose session (Day / Evening / Weekend Morning / Weekend
// Afternoon, incl. Umuganda override hours) matches the CURRENT live
// window — a lecturer shouldn't be picking a module to take attendance for
// outside its actual class time.
$nowDt        = ClassAttendance::now();
$window       = ClassAttendance::currentWindow();
$holidayToday = ClassAttendance::holidayToday();

$visibleModules = [];
foreach ($allModules as $am) {
    $matchesWindow = false;
    if ($window) {
        $st   = $am['session_type'] ?? '';
        $slot = $am['weekend_slot'] ?? '';
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
    if ($matchesWindow) {
        $visibleModules[] = $am;
    }
}

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
    $sessions = array_values(array_filter($allSess, function ($s) use ($excludeDates) {
        return !in_array($s['session_date'], $excludeDates, true);
    }));

    foreach ($db->query("SELECT holiday_date FROM holidays")->fetchAll() as $h) {
        $holidayMap[$h['holiday_date']] = true;
    }

    $stuStmt = $db->prepare(
        "SELECT u.user_id, u.full_name, u.reg_number, u.phone_number
         FROM users u JOIN module_enrollments e ON e.user_id = u.user_id
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
            $isAuto = ($log['verification_method'] === 'Auto');
            $attMap[$sid][$uid]['is_auto'] = $isAuto;
            if (!$isAuto) {
                $attMap[$sid][$uid]['in_time'] = $log['checkin_time'] ? date('H:i', strtotime((string) $log['checkin_time'])) : null;
            }
        } else {
            $attMap[$sid][$uid]['out_time'] = $log['checkin_time'] ? date('H:i', strtotime((string) $log['checkin_time'])) : null;
        }
    }
}

function lec_att_status(?array $e, string $date, string $today): string
{
    if (!$e || $e['is_auto'])                                return $date <= $today ? 'A' : '';
    if ($e['in_status'] === 'Present' && $e['out_time'])     return 'P';
    if ($e['in_status'] === 'Late'    && $e['out_time'])     return 'L';
    return 'A';
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

<!-- Module selector — only modules whose session matches the current window -->
<div class="semas-card p-3 mb-3">
  <?php if (!$visibleModules): ?>
    <p class="text-muted small mb-0">None of your modules are scheduled for the current session window.</p>
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
    <button type="button" class="btn btn-sm btn-outline-dark"
            data-bs-toggle="modal" data-bs-target="#addSessionModal">
      <i class="bi bi-calendar-plus me-1"></i> Add Session Date
    </button>
  </div>
</div>

<?php if (!$students): ?>
  <div class="semas-card p-4 text-center text-muted small">No students enrolled yet.</div>
<?php elseif (!$sessions): ?>
  <div class="semas-card p-4 text-center text-muted small">
    No sessions recorded yet. Students scan the QR code to open the first session,
    or use <strong>Add Session Date</strong> above.
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
function openMark(sid, uid, mid, name) {
    document.getElementById('markSid').value  = sid;
    document.getElementById('markUid').value  = uid;
    document.getElementById('markName').textContent = name;
    new bootstrap.Modal(document.getElementById('markModal')).show();
}
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
