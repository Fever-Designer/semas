<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD', 'Coordinator']);
Module::autoCompleteExpired();

$pageTitle     = 'Class Attendance';
$activeNav     = 'class-attendance';
$db            = Database::connection();
$me            = Auth::user();
$today         = ClassAttendance::now()->format('Y-m-d');
$isCoordinator = Auth::role() === 'Coordinator';

function hod_module_matches_attendance_window(array $module, string $windowName): bool
{
    $sessionType = $module['session_type'] ?? '';
    $slot = $module['weekend_slot'] ?? '';
    if ($sessionType === 'Day') {
        return $windowName === 'Day';
    }
    if ($sessionType === 'Evening') {
        return $windowName === 'Evening';
    }
    if ($sessionType === 'Weekend') {
        if ($slot === 'Morning') {
            return in_array($windowName, ['WeekendMorning', 'UmugandaMorning'], true);
        }
        if ($slot === 'Afternoon') {
            return in_array($windowName, ['WeekendAfternoon', 'UmugandaAfternoon'], true);
        }
        return in_array($windowName, ['WeekendMorning', 'WeekendAfternoon', 'UmugandaMorning', 'UmugandaAfternoon'], true);
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
            redirect('/hod/class-attendance.php?module_id=' . $moduleId);
        }
        $db->prepare("DELETE FROM class_attendance_logs WHERE session_id = :sid AND user_id = :uid")
           ->execute(['sid' => $sessionId, 'uid' => $userId]);
        if ($mark === 'Absent') {
            $db->prepare(
                "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method)
                 VALUES (:sid, :uid, 'Sign In', 'Absent', 'Auto')"
            )->execute(['sid' => $sessionId, 'uid' => $userId]);
        } else {
            $db->prepare(
                "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method, checkin_time)
                 VALUES (:sid, :uid, 'Sign In', :st, 'Manual', NOW())"
            )->execute(['sid' => $sessionId, 'uid' => $userId, 'st' => $mark]);
            $db->prepare(
                "INSERT INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method)
                 VALUES (:sid, :uid, 'Sign Out', 'Present', 'Manual')"
            )->execute(['sid' => $sessionId, 'uid' => $userId]);
        }
        AuditLog::record(Auth::id(), 'MANUAL_MARK_ATTENDANCE', 'class_sessions', $sessionId, "user={$userId};mark={$mark}");
        flash('success', 'Attendance updated.');
        redirect('/hod/class-attendance.php?module_id=' . $moduleId);
    }

    if ($action === 'create_session') {
        $sessDate   = trim($_POST['session_date'] ?? '');
        $windowName = trim($_POST['window_name']  ?? '');
        if (!$sessDate || !$windowName || !$moduleId) {
            flash('error', 'Date and window are required.');
            redirect('/hod/class-attendance.php?module_id=' . $moduleId);
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
            flash('success', 'Session added. Students pre-marked Absent / update individually as needed.');
        } catch (PDOException $e) {
            flash('error', 'Session already exists for this date/window, or an error occurred.');
        }
        redirect('/hod/class-attendance.php?module_id=' . $moduleId);
    }

    redirect('/hod/class-attendance.php');
}

// ── Fetch module list ──────────────────────────────────────────────────────
$modSql = "SELECT m.module_id, m.module_title, m.session_type, m.weekend_slot, m.status,
                  m.start_date, m.end_date, m.cat_date, m.exam_date, m.module_qr_secret,
                  d.department_name, COALESCE(lt.title,'') AS lecturer_title,
                  u.full_name AS lecturer_name, r.room_name
           FROM modules m
           LEFT JOIN departments d ON d.department_id = m.department_id
           LEFT JOIN lecturers lt  ON lt.lecturer_id  = m.lecturer_id
           LEFT JOIN users u       ON u.user_id        = lt.user_id
           LEFT JOIN rooms r       ON r.room_id        = m.room_id";
if ($isCoordinator) {
    $modSql .= " WHERE m.session_type = 'Weekend'";
} else {
    $modSql .= "";
}
$modSql .= " ORDER BY m.module_title";
$allModules = $db->query($modSql)->fetchAll();

$currentWindow = ClassAttendance::currentWindow();
$defaultOverallSession = '';
if ($isCoordinator) {
    $defaultOverallSession = 'Weekend';
} elseif ($currentWindow) {
    if (in_array($currentWindow['name'], ['WeekendMorning', 'WeekendAfternoon', 'UmugandaMorning', 'UmugandaAfternoon'], true)) {
        $defaultOverallSession = 'Weekend';
    } elseif (in_array($currentWindow['name'], ['Day', 'Evening'], true)) {
        $defaultOverallSession = $currentWindow['name'];
    }
}
$overallSessionFilter = $_GET['session'] ?? $defaultOverallSession;
if (!in_array($overallSessionFilter, ['', 'Day', 'Evening', 'Weekend'], true)) {
    $overallSessionFilter = $defaultOverallSession;
}
if ($isCoordinator) {
    $overallSessionFilter = 'Weekend';
}

$moduleId = (int) ($_GET['module_id'] ?? 0);
$module   = null;
foreach ($allModules as $am) {
    if ((int) $am['module_id'] === $moduleId) { $module = $am; break; }
}
$moduleQrSecret = $module['module_qr_secret'] ?? null;

// ── Attendance register data ───────────────────────────────────────────────
$sessions   = [];
$students   = [];
$attMap     = [];   // [session_id][user_id] = ['in_time','in_status','out_time','is_auto']
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
            && hod_module_matches_attendance_window($module, (string) $s['window_name']);
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

function att_status(?array $e, string $date, string $today): string
{
    if (!$e || $e['is_auto'])                                return $date <= $today ? 'A' : '';
    if ($e['in_status'] === 'Present' && $e['out_time'])     return 'P';
    if ($e['in_status'] === 'Late'    && $e['out_time'])     return 'L';
    return 'A';
}

// ── Overall Attendance (cross-module, special-case detection) ──────────────
$viewMode       = 'overall';
$detailModuleId = (int) ($_GET['detail_module'] ?? 0);
$overallRows    = [];

if ($viewMode === 'overall') {
    $allHolidays = [];
    foreach ($db->query("SELECT holiday_date FROM holidays")->fetchAll() as $h) {
        $allHolidays[$h['holiday_date']] = true;
    }

    $overallModules = array_values(array_filter($allModules, function ($am) use ($overallSessionFilter) {
        return $overallSessionFilter === '' || ($am['session_type'] ?? '') === $overallSessionFilter;
    }));

    $moduleSummary = [];
    foreach ($overallModules as $am) {
        $amId         = (int) $am['module_id'];
        $excludeDates = array_values(array_filter([$am['cat_date'], $am['exam_date']]));

        $studentCountStmt = $db->prepare('SELECT COUNT(*) FROM module_enrollments WHERE module_id = :mid');
        $studentCountStmt->execute(['mid' => $amId]);
        $moduleSummary[$amId] = [
            'module_id' => $amId,
            'module' => $am['module_title'],
            'session_type' => $am['session_type'] ?? '',
            'weekend_slot' => $am['weekend_slot'] ?? '',
            'lecturer' => $am['lecturer_name'] ?? 'TBA',
            'room' => $am['room_name'] ?? '',
            'students' => (int) $studentCountStmt->fetchColumn(),
            'sessions' => 0,
            'p' => 0,
            'l' => 0,
            'a' => 0,
            'total' => 0,
            'special' => 0,
            'critical' => 0,
            'pct' => null,
        ];

        $sessStmt2 = $db->prepare(
            "SELECT session_id, session_date FROM class_sessions WHERE module_id = :mid ORDER BY session_date ASC"
        );
        $sessStmt2->execute(['mid' => $amId]);
        $amSessions = array_values(array_filter($sessStmt2->fetchAll(), function ($s) use ($excludeDates, $today) {
            return $s['session_date'] <= $today && !in_array($s['session_date'], $excludeDates, true);
        }));
        $moduleSummary[$amId]['sessions'] = count($amSessions);

        $stuStmt2 = $db->prepare(
            "SELECT u.user_id, u.full_name, u.reg_number
             FROM users u JOIN module_enrollments e ON e.user_id = u.user_id
             WHERE e.module_id = :mid AND u.status = 'Active' ORDER BY u.full_name"
        );
        $stuStmt2->execute(['mid' => $amId]);
        $amStudents = $stuStmt2->fetchAll();
        if (!$amSessions || !$amStudents) continue;

        $logStmt2 = $db->prepare(
            "SELECT cal.user_id, cal.session_id, cal.attendance_type, cal.status, cal.verification_method
             FROM class_attendance_logs cal JOIN class_sessions cs ON cs.session_id = cal.session_id
             WHERE cs.module_id = :mid"
        );
        $logStmt2->execute(['mid' => $amId]);
        $amMap = [];
        foreach ($logStmt2->fetchAll() as $log) {
            $sid = (int) $log['session_id'];
            $uid = (int) $log['user_id'];
            if (!isset($amMap[$sid][$uid])) {
                $amMap[$sid][$uid] = ['in_status' => null, 'out_time' => null, 'is_auto' => true];
            }
            if ($log['attendance_type'] === 'Sign In') {
                $amMap[$sid][$uid]['in_status'] = $log['status'];
                $amMap[$sid][$uid]['is_auto']   = !in_array((string) $log['verification_method'], ['QR', 'Manual'], true);
            } else {
                $amMap[$sid][$uid]['out_time'] = true;
            }
        }

        foreach ($amStudents as $stu) {
            $uid = (int) $stu['user_id'];
            $p = 0; $l = 0; $a = 0;
            foreach ($amSessions as $s) {
                if (isset($allHolidays[$s['session_date']]) || $s['session_date'] > $today) continue;
                $fs = att_status($amMap[(int) $s['session_id']][$uid] ?? null, $s['session_date'], $today);
                if ($fs === 'P') $p++;
                elseif ($fs === 'L') $l++;
                elseif ($fs === 'A') $a++;
            }
            $tot = $p + $l + $a;
            if ($tot === 0) continue;
            $overallRows[] = [
                'module_id' => $amId, 'module' => $am['module_title'],
                'name' => $stu['full_name'], 'reg' => $stu['reg_number'],
                'p' => $p, 'l' => $l, 'a' => $a, 'total' => $tot,
                'pct' => round(($p + $l) / $tot * 100, 1),
            ];
        }
    }
    usort($overallRows, function ($x, $y) { return $y['a'] <=> $x['a']; });

    if (!empty($_GET['special'])) {
        $overallRows = array_values(array_filter($overallRows, function ($r) { return $r['a'] >= 3; }));
    }

    // Group into per-module summaries / HoD/Coordinator view modules, not individual students.
    foreach ($overallRows as $r) {
        $mid = $r['module_id'];
        $moduleSummary[$mid]['p'] += $r['p'];
        $moduleSummary[$mid]['l'] += $r['l'];
        $moduleSummary[$mid]['a'] += $r['a'];
        $moduleSummary[$mid]['total'] += $r['total'];
        if ($r['a'] >= 4) { $moduleSummary[$mid]['critical']++; }
        elseif ($r['a'] >= 3) { $moduleSummary[$mid]['special']++; }
    }
    foreach ($moduleSummary as &$ms) {
        $ms['pct'] = $ms['total'] > 0 ? round(($ms['p'] + $ms['l']) / $ms['total'] * 100, 1) : null;
    }
    unset($ms);
    if (!empty($_GET['special'])) {
        $moduleSummary = array_filter($moduleSummary, function ($ms) {
            return ($ms['special'] + $ms['critical']) > 0;
        });
    }
    $moduleSummary = array_values($moduleSummary);
    usort($moduleSummary, function ($x, $y) { return strcmp($x['module'], $y['module']); });

    $detailRows = $detailModuleId ? array_values(array_filter($overallRows, function ($r) use ($detailModuleId) { return $r['module_id'] === $detailModuleId; })) : [];
}

require __DIR__ . '/../partials/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="display-font mb-0">Class Attendance Analytics</h4>
</div>

<?php if ($viewMode === 'overall'): ?>

<?php if ($detailModuleId): ?>
  <?php
    $detailModuleTitle = $detailRows[0]['module'] ?? '';
    if ($detailModuleTitle === '') {
        foreach ($moduleSummary as $ms) {
            if ((int) $ms['module_id'] === $detailModuleId) { $detailModuleTitle = $ms['module']; break; }
        }
    }
  ?>
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h6 class="display-font mb-0"><?= e($detailModuleTitle) ?> / Student Breakdown</h6>
    <a href="?view=overall&session=<?= e($overallSessionFilter) ?><?= !empty($_GET['special']) ? '&special=1' : '' ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-arrow-left me-1"></i> Back to Modules</a>
  </div>

  <?php if (!$detailRows): ?>
    <div class="semas-card p-4 text-center text-muted small">No attendance data recorded yet for this module.</div>
  <?php else: ?>
  <div class="semas-card p-0 mb-3">
    <div class="table-responsive">
      <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.8rem;">
        <thead class="table-dark">
          <tr>
            <th>Student</th><th>Reg No</th>
            <th class="text-center" style="background:#d4edda;color:#155724;">P</th>
            <th class="text-center" style="background:#fff3cd;color:#856404;">L</th>
            <th class="text-center" style="background:#f8d7da;color:#721c24;">A</th>
            <th class="text-center">Total</th>
            <th class="text-center">Attend %</th>
            <th class="text-center">Flag</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($detailRows as $r):
            $rowBg = $r['a'] >= 4 ? '#f8d7da' : ($r['a'] >= 3 ? '#fff3cd' : '');
          ?>
          <tr style="<?= $rowBg ? "background:$rowBg;" : '' ?>">
            <td class="fw-semibold"><?= e($r['name']) ?></td>
            <td style="color:#666;"><?= e($r['reg'] ?? '/') ?></td>
            <td class="text-center"><?= $r['p'] ?></td>
            <td class="text-center"><?= $r['l'] ?></td>
            <td class="text-center fw-bold"><?= $r['a'] ?></td>
            <td class="text-center"><?= $r['total'] ?></td>
            <td class="text-center fw-bold" style="color:<?= $r['pct'] >= 75 ? '#155724' : '#721c24' ?>;"><?= number_format($r['pct'], 1) ?>%</td>
            <td class="text-center">
              <?php if ($r['a'] >= 4): ?>
                <span class="badge bg-danger">⛔ Critical</span>
              <?php elseif ($r['a'] >= 3): ?>
                <span class="badge bg-warning text-dark">⚠ Special Case</span>
              <?php else: ?>
                <span class="text-muted">/</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>

<?php
  $modulesShown = count($moduleSummary);
  $overallTotal = array_sum(array_column($moduleSummary, 'total'));
  $overallAttended = array_sum(array_column($moduleSummary, 'p')) + array_sum(array_column($moduleSummary, 'l'));
  $overallRate = $overallTotal > 0 ? round($overallAttended / $overallTotal * 100) : 0;
  $sessionLabel = $overallSessionFilter !== '' ? $overallSessionFilter : 'All Sessions';
?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Ongoing Modules / <?= e($sessionLabel) ?></div><div class="stat-value"><?= $modulesShown ?></div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Total Sign-Ins Recorded</div><div class="stat-value"><?= $overallTotal ?></div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Overall Attendance Rate</div><div class="stat-value"><?= $overallRate ?>%</div>
    <div class="progress mt-2" style="height:6px;"><div class="progress-bar" style="width:<?= $overallRate ?>%;background-color:var(--semas-gold);"></div></div>
  </div></div>
</div>

<div class="semas-card p-3 mb-3">
  <form method="GET" class="row g-2 align-items-center">
    <input type="hidden" name="view" value="overall">
    <?php if (!empty($_GET['special'])): ?><input type="hidden" name="special" value="1"><?php endif; ?>
    <div class="col-md-5">
      <label class="form-label small mb-1">Session</label>
      <select name="session" class="form-select form-select-sm" onchange="this.form.submit()" <?= $isCoordinator ? 'disabled' : '' ?>>
        <option value="" <?= $overallSessionFilter === '' ? 'selected' : '' ?>>All Sessions</option>
        <option value="Day" <?= $overallSessionFilter === 'Day' ? 'selected' : '' ?>>Day</option>
        <option value="Evening" <?= $overallSessionFilter === 'Evening' ? 'selected' : '' ?>>Evening</option>
        <option value="Weekend" <?= $overallSessionFilter === 'Weekend' ? 'selected' : '' ?>>Weekend</option>
      </select>
      <?php if ($isCoordinator): ?><input type="hidden" name="session" value="Weekend"><?php endif; ?>
    </div>
    <div class="col-md-7 text-md-end">
      <span class="text-muted small">
        Current session: <?= $currentWindow ? e(ClassAttendance::describeWindow($currentWindow)) : 'No active session window' ?>
      </span>
    </div>
  </form>
</div>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div class="d-flex gap-3 flex-wrap" style="font-size:.75rem;">
    <span><span class="px-2 py-0 rounded fw-bold" style="background:#fff3cd;color:#856404;">⚠ 3+ absences</span> Special Case</span>
    <span><span class="px-2 py-0 rounded fw-bold" style="background:#f8d7da;color:#721c24;">⛔ 4+ absences</span> Critical</span>
  </div>
  <a href="?view=overall&session=<?= e($overallSessionFilter) ?><?= empty($_GET['special']) ? '&special=1' : '' ?>" class="btn btn-sm <?= !empty($_GET['special']) ? 'btn-semas-gold' : 'btn-outline-dark' ?>">
    <i class="bi bi-funnel me-1"></i> <?= !empty($_GET['special']) ? 'Showing Special Cases Only' : 'Show Special Cases Only' ?>
  </a>
</div>

<?php if (!$moduleSummary): ?>
  <div class="semas-card p-4 text-center text-muted small">No modules found for <?= e($sessionLabel) ?>.</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($moduleSummary as $ms): ?>
    <div class="col-md-6 col-lg-4">
      <div class="semas-card p-3 h-100 d-flex flex-column">
        <h6 class="fw-semibold mb-1"><?= e($ms['module']) ?></h6>
        <?php $slotLabel = ($ms['session_type'] === 'Weekend' && $ms['weekend_slot']) ? 'Weekend / ' . $ms['weekend_slot'] : $ms['session_type']; ?>
        <p class="text-muted small mb-2">
          <?= (int) $ms['students'] ?> registered student(s) &middot; <?= (int) $ms['sessions'] ?> session(s)<br>
          <?= e($slotLabel ?: 'Any session') ?><?= $ms['room'] ? ' &middot; ' . e($ms['room']) : '' ?>
        </p>
        <div class="d-flex gap-3 small mb-2">
          <span style="color:#155724;">P <?= $ms['p'] ?></span>
          <span style="color:#856404;">L <?= $ms['l'] ?></span>
          <span style="color:#721c24;">A <?= $ms['a'] ?></span>
        </div>
        <div class="mb-2">
          <?php if ($ms['pct'] === null): ?>
            <span class="text-muted small">No attendance data yet</span>
          <?php else: ?>
            <span class="fw-bold fs-5" style="color:<?= $ms['pct'] >= 75 ? '#155724' : '#721c24' ?>;"><?= number_format((float) $ms['pct'], 1) ?>%</span>
            <span class="text-muted small"> avg attendance</span>
          <?php endif; ?>
        </div>
        <div class="mb-2">
          <?php if ($ms['critical']): ?><span class="badge bg-danger me-1">⛔ <?= $ms['critical'] ?> Critical</span><?php endif; ?>
          <?php if ($ms['special']): ?><span class="badge bg-warning text-dark me-1">⚠ <?= $ms['special'] ?> Special Case</span><?php endif; ?>
          <?php if (!$ms['critical'] && !$ms['special']): ?><span class="text-muted small">No flagged students</span><?php endif; ?>
        </div>
        <a href="?view=overall&session=<?= e($overallSessionFilter) ?>&detail_module=<?= $ms['module_id'] ?><?= !empty($_GET['special']) ? '&special=1' : '' ?>" class="btn btn-sm btn-outline-dark mt-auto">
          <i class="bi bi-people me-1"></i> View More Details
        </a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; // detailModuleId ?>

<?php else: ?>

<!-- Module selector -->
<div class="semas-card p-3 mb-3">
  <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
    <input type="hidden" name="view" value="register">
    <label class="form-label small fw-semibold mb-0 text-nowrap">Select Module:</label>
    <select name="module_id" class="form-select form-select-sm flex-grow-1" style="max-width:440px;"
            onchange="this.form.submit()">
      <option value="">/ Choose a module /</option>
      <?php foreach ($allModules as $am): ?>
        <option value="<?= (int) $am['module_id'] ?>"
                <?= (int) $am['module_id'] === $moduleId ? 'selected' : '' ?>>
          <?= e($am['module_title']) ?> / <?= e($am['session_type']) ?> [<?= e($am['status']) ?>]
        </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$module): ?>
  <div class="semas-card p-5 text-center text-muted">
    <i class="bi bi-calendar3" style="font-size:2.5rem;opacity:.35;"></i>
    <p class="mt-3 mb-0">Select a module above to view its attendance register.</p>
  </div>
<?php else: ?>

<?php
  $lTitle   = $module['lecturer_title'] ?? '';
  $lName    = $module['lecturer_name']  ?? 'TBA';
  $lecLabel = $lTitle ? strtoupper(rtrim((string) $lTitle, '.')) . '. ' . $lName : $lName;
  $slot     = $module['weekend_slot'] ?? '';
  $sessLabel = ($module['session_type'] === 'Weekend' && $slot) ? "Weekend / {$slot}" : $module['session_type'];
?>

<!-- Module info header -->
<div class="semas-card p-3 mb-3">
  <div class="row g-2" style="font-size:.85rem;">
    <div class="col-sm-4">
      <span class="text-muted small">Module</span><br>
      <strong><?= e($module['module_title']) ?></strong>
    </div>
    <div class="col-sm-4">
      <span class="text-muted small">Lecturer</span><br>
      <strong><?= e($lecLabel) ?></strong> &nbsp;
      <span class="text-muted small"><?= e($sessLabel) ?></span>
    </div>
    <div class="col-sm-2">
      <span class="text-muted small">Room</span><br>
      <strong><?= e($module['room_name'] ?? '/') ?></strong>
    </div>
    <div class="col-sm-2">
      <span class="text-muted small">Period</span><br>
      <strong><?= e((string) date('d M Y', strtotime((string) ($module['start_date'] ?? '')))) ?></strong>
      <span class="text-muted">→</span>
      <strong><?= e((string) date('d M Y', strtotime((string) ($module['end_date'] ?? '')))) ?></strong>
    </div>
  </div>
  <div class="mt-2 d-flex gap-2 align-items-center flex-wrap">
    <span class="badge <?= $module['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?>">
      <?= e($module['status']) ?>
    </span>
    <span class="text-muted small"><?= count($students) ?> students &nbsp;·&nbsp; <?= count($sessions) ?> sessions</span>
    <a href="<?= APP_URL ?>/hod/attendance-sheet-print.php?module_id=<?= $moduleId ?>" target="_blank" class="btn btn-sm btn-outline-dark ms-auto">
      <i class="bi bi-printer me-1"></i> Print Attendance Sheet
    </a>
    <a href="<?= APP_URL ?>/hod/attendance-sheet-excel.php?module_id=<?= $moduleId ?>" class="btn btn-sm btn-outline-dark">
      <i class="bi bi-file-earmark-excel me-1"></i> Excel
    </a>
  </div>
</div>

<?php if (!$students): ?>
  <div class="semas-card p-4 text-center text-muted small">No students enrolled yet.</div>
<?php elseif (!$sessions): ?>
  <div class="semas-card p-4 text-center text-muted small">
    No sessions recorded yet. Attendance sessions open from live QR scans or active-class manual attendance.
  </div>
<?php else: ?>

<!-- Legend -->
<div class="d-flex gap-3 mb-2 flex-wrap" style="font-size:.75rem;">
  <span><span class="px-2 py-0 rounded fw-bold" style="background:#d4edda;color:#155724;">P ✓</span> Present</span>
  <span><span class="px-2 py-0 rounded fw-bold" style="background:#fff3cd;color:#856404;">L</span> Late</span>
  <span><span class="px-2 py-0 rounded fw-bold" style="background:#f8d7da;color:#721c24;">A</span> Absent / No sign-out</span>
  <span><span class="px-2 py-0 rounded fw-bold" style="background:#fff3cd;color:#856404;">H</span> Holiday</span>
  <span class="ms-auto text-muted">Eligibility: ≥ 75%</span>
</div>

<!-- Attendance register table -->
<div class="semas-card p-0 mb-3">
  <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
    <table class="table table-bordered table-sm mb-0 align-middle" style="white-space:nowrap;font-size:.77rem;">
      <thead>
        <tr class="table-dark" style="font-size:.71rem;">
          <th class="text-center" style="min-width:30px;">#</th>
          <th style="min-width:95px;">Reg No</th>
          <th style="min-width:170px;position:sticky;left:0;z-index:3;background:#212529;">Student Name</th>
          <th style="min-width:105px;">Phone Number</th>
          <?php foreach ($sessions as $s):
            $isHol   = isset($holidayMap[$s['session_date']]);
            $isToday = ($s['session_date'] === $today);
            $thStyle = $isHol    ? 'background:#fff3cd;color:#856404;'
                     : ($isToday ? 'background:#1a4a8a;' : '');
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
              $fs = att_status($attMap[(int)$s['session_id']][$uid] ?? null, $s['session_date'], $today);
              if ($fs === 'P') $pCnt++;
              elseif ($fs === 'L') $lCnt++;
              elseif ($fs === 'A') $aCnt++;
          }
          $total   = $pCnt + $lCnt + $aCnt;
          $pct     = $total > 0 ? round(($pCnt + $lCnt) / $total * 100, 1) : 0;
          $eligible = $pct >= 75;
        ?>
        <tr>
          <td class="text-center text-muted"><?= $idx + 1 ?></td>
          <td style="color:#666;"><?= e($stu['reg_number'] ?? '/') ?></td>
          <td style="position:sticky;left:0;z-index:1;background:#fff;font-weight:600;min-width:170px;">
            <?= e($stu['full_name']) ?>
          </td>
          <td style="color:#666;"><?= e($stu['phone_number'] ?? '/') ?></td>
          <?php foreach ($sessions as $s):
            $sid   = (int) $s['session_id'];
            $isHol = isset($holidayMap[$s['session_date']]);
            $entry = $attMap[$sid][$uid] ?? null;
            $fs    = att_status($entry, $s['session_date'], $today);
          ?>
          <?php if ($isHol): ?>
            <td class="text-center fw-bold" style="background:#fff3cd;color:#856404;">H</td>
          <?php elseif ($fs === ''): ?>
            <td class="text-center" style="color:#ddd;">/</td>
          <?php elseif (!$entry || $entry['is_auto']): ?>
            <td class="text-center" style="background:#f8d7da;padding:3px 2px;">
              <div class="fw-bold" style="color:#721c24;">A</div>
              <button type="button" class="btn btn-link p-0" style="font-size:.58rem;color:#aaa;line-height:1;"
                      onclick="openMark(<?= $sid ?>,<?= $uid ?>,<?= $moduleId ?>,'<?= e((string) addslashes($stu['full_name'])) ?>')">
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
                      onclick="openMark(<?= $sid ?>,<?= $uid ?>,<?= $moduleId ?>,'<?= e((string) addslashes($stu['full_name'])) ?>')">
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
                 title="<?= $eligible ? 'Eligible for exam' : 'Below eligibility threshold' ?>"></i>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

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

<?php endif; // !$module ?>

<?php endif; // viewMode ?>

<script>
function openMark(sid, uid, mid, name) {
    document.getElementById('markSid').value  = sid;
    document.getElementById('markUid').value  = uid;
    document.getElementById('markName').textContent = name;
    new bootstrap.Modal(document.getElementById('markModal')).show();
}
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
