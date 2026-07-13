<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal']);

$pageTitle = 'Module & Attendance Reports';
$activeNav = 'module-reports';
$db = Database::connection();

$search = trim($_GET['q'] ?? '');
$deptFilter = $_GET['department_id'] ?? '';

$currentWindow = ClassAttendance::currentWindow();
$defaultSession = '';
if ($currentWindow) {
    if (in_array($currentWindow['name'], ['WeekendMorning', 'WeekendAfternoon', 'UmugandaMorning', 'UmugandaAfternoon'], true)) {
        $defaultSession = 'Weekend';
    } elseif (in_array($currentWindow['name'], ['Day', 'Evening'], true)) {
        $defaultSession = $currentWindow['name'];
    }
}
$sessionFilter = $_GET['session'] ?? $defaultSession;
if (!in_array($sessionFilter, ['', 'Day', 'Evening', 'Weekend'], true)) {
    $sessionFilter = $defaultSession;
}

$where = ["m.status = 'Ongoing'"];
$params = [];
if ($search !== '') {
    $where[] = 'm.module_title LIKE :q';
    $params['q'] = "%$search%";
}
if ($deptFilter !== '') {
    $where[] = 'm.department_id = :dept';
    $params['dept'] = (int) $deptFilter;
}
if ($sessionFilter !== '') {
    $where[] = 'm.session_type = :session';
    $params['session'] = $sessionFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name, r.room_name,
        (SELECT COUNT(*) FROM module_enrollments e WHERE e.module_id = m.module_id) AS student_count,
        (SELECT COUNT(*) FROM class_sessions cs
            WHERE cs.module_id = m.module_id
              AND cs.session_date <= CURDATE()
              AND (m.cat_date IS NULL OR cs.session_date <> m.cat_date)
              AND (m.exam_date IS NULL OR cs.session_date <> m.exam_date)) AS sessions_held,
        (SELECT COUNT(*) FROM class_attendance_logs cal JOIN class_sessions cs2 ON cs2.session_id = cal.session_id
            WHERE cs2.module_id = m.module_id
              AND cs2.session_date <= CURDATE()
              AND (m.cat_date IS NULL OR cs2.session_date <> m.cat_date)
              AND (m.exam_date IS NULL OR cs2.session_date <> m.exam_date)
              AND cal.attendance_type = 'Sign In') AS total_signins,
        (SELECT COUNT(*) FROM class_attendance_logs cal JOIN class_sessions cs3 ON cs3.session_id = cal.session_id
            WHERE cs3.module_id = m.module_id
              AND cs3.session_date <= CURDATE()
              AND (m.cat_date IS NULL OR cs3.session_date <> m.cat_date)
              AND (m.exam_date IS NULL OR cs3.session_date <> m.exam_date)
              AND cal.attendance_type = 'Sign In'
              AND cal.status IN ('Present','Late')) AS attended_signins,
        (SELECT COUNT(*) FROM class_attendance_logs cal JOIN class_sessions cs4 ON cs4.session_id = cal.session_id
            WHERE cs4.module_id = m.module_id
              AND cs4.session_date <= CURDATE()
              AND (m.cat_date IS NULL OR cs4.session_date <> m.cat_date)
              AND (m.exam_date IS NULL OR cs4.session_date <> m.exam_date)
              AND cal.attendance_type = 'Sign In'
              AND cal.status = 'Present') AS present_count,
        (SELECT COUNT(*) FROM class_attendance_logs cal JOIN class_sessions cs5 ON cs5.session_id = cal.session_id
            WHERE cs5.module_id = m.module_id
              AND cs5.session_date <= CURDATE()
              AND (m.cat_date IS NULL OR cs5.session_date <> m.cat_date)
              AND (m.exam_date IS NULL OR cs5.session_date <> m.exam_date)
              AND cal.attendance_type = 'Sign In'
              AND cal.status = 'Late') AS late_count,
        (SELECT COUNT(*) FROM class_attendance_logs cal JOIN class_sessions cs6 ON cs6.session_id = cal.session_id
            WHERE cs6.module_id = m.module_id
              AND cs6.session_date <= CURDATE()
              AND (m.cat_date IS NULL OR cs6.session_date <> m.cat_date)
              AND (m.exam_date IS NULL OR cs6.session_date <> m.exam_date)
              AND cal.attendance_type = 'Sign In'
              AND cal.status = 'Absent') AS absent_count
     FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     LEFT JOIN rooms r ON r.room_id = m.room_id
     $whereSql
     ORDER BY d.department_name, m.module_title"
);
$stmt->execute($params);
$modules = $stmt->fetchAll();

$departments = $db->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll();

$overallTotal = 0;
$overallAttended = 0;
foreach ($modules as $m) {
    $overallTotal += (int) $m['total_signins'];
    $overallAttended += (int) $m['attended_signins'];
}
$overallRate = $overallTotal > 0 ? round($overallAttended / $overallTotal * 100) : 0;
$sessionLabel = $sessionFilter !== '' ? $sessionFilter : 'All Sessions';

// Local predictive attendance analysis. Keeping this calculation inside SEMAS
// avoids sending student records to a third-party AI service. The score blends
// the student's complete module history with the three most recent decisions.
$absenceRisks = [];
$lateRisks = [];
if ($modules) {
    $moduleIds = array_values(array_unique(array_map(static fn(array $module): int => (int) $module['module_id'], $modules)));
    $moduleIdSql = implode(',', $moduleIds);
    $historyStmt = $db->query(
        "SELECT m.module_id, m.module_title, d.department_name,
                u.user_id, u.full_name, u.reg_number, cs.session_date,
                CASE
                    WHEN MAX(CASE WHEN si.verification_method IN ('QR','Manual') AND so.verification_method IN ('QR','Manual') THEN 1 ELSE 0 END) = 1 THEN 'Present'
                    WHEN MAX(CASE WHEN (si.attendance_id IS NULL OR si.verification_method NOT IN ('QR','Manual')) AND so.verification_method IN ('QR','Manual') THEN 1 ELSE 0 END) = 1 THEN 'Late'
                    ELSE 'Absent'
                END AS attendance_decision
         FROM modules m
         JOIN module_enrollments me ON me.module_id = m.module_id
         JOIN users u ON u.user_id = me.user_id AND u.status = 'Active'
         LEFT JOIN departments d ON d.department_id = m.department_id
         JOIN class_sessions cs ON cs.module_id = m.module_id
             AND cs.session_date <= CURDATE()
             AND (m.cat_date IS NULL OR cs.session_date <> m.cat_date)
             AND (m.exam_date IS NULL OR cs.session_date <> m.exam_date)
         LEFT JOIN class_attendance_logs si ON si.session_id = cs.session_id
             AND si.user_id = u.user_id AND si.attendance_type = 'Sign In'
         LEFT JOIN class_attendance_logs so ON so.session_id = cs.session_id
             AND so.user_id = u.user_id AND so.attendance_type = 'Sign Out'
         LEFT JOIN holidays h ON h.holiday_date = cs.session_date AND h.holiday_type = 'Public Holiday'
         WHERE m.module_id IN ($moduleIdSql)
           AND (m.session_type = 'Weekend' OR h.holiday_id IS NULL)
         GROUP BY m.module_id, m.module_title, d.department_name,
                  u.user_id, u.full_name, u.reg_number, cs.session_date
         ORDER BY m.module_id, u.user_id, cs.session_date"
    );

    $studentHistories = [];
    foreach ($historyStmt->fetchAll() as $historyRow) {
        $historyKey = (int) $historyRow['module_id'] . ':' . (int) $historyRow['user_id'];
        if (!isset($studentHistories[$historyKey])) {
            $studentHistories[$historyKey] = [
                'module_id' => (int) $historyRow['module_id'],
                'module_title' => $historyRow['module_title'],
                'department_name' => $historyRow['department_name'],
                'user_id' => (int) $historyRow['user_id'],
                'full_name' => $historyRow['full_name'],
                'reg_number' => $historyRow['reg_number'],
                'decisions' => [],
            ];
        }
        $studentHistories[$historyKey]['decisions'][] = $historyRow['attendance_decision'];
    }

    // The module summary uses the same paired-action decisions as the risk
    // model, rather than treating a raw Sign In row as final attendance.
    $moduleDecisionTotals = [];
    foreach ($studentHistories as $history) {
        $mid = (int) $history['module_id'];
        $moduleDecisionTotals[$mid] ??= ['Present' => 0, 'Late' => 0, 'Absent' => 0];
        foreach ($history['decisions'] as $decision) {
            $moduleDecisionTotals[$mid][$decision]++;
        }
    }
    foreach ($modules as &$moduleRow) {
        $decisionCounts = $moduleDecisionTotals[(int) $moduleRow['module_id']] ?? ['Present' => 0, 'Late' => 0, 'Absent' => 0];
        $moduleRow['present_count'] = $decisionCounts['Present'];
        $moduleRow['late_count'] = $decisionCounts['Late'];
        $moduleRow['absent_count'] = $decisionCounts['Absent'];
        $moduleRow['total_signins'] = array_sum($decisionCounts);
        $effectiveAbsences = $decisionCounts['Absent'] + intdiv($decisionCounts['Late'], 2);
        $moduleRow['attended_signins'] = max(0, $moduleRow['total_signins'] - $effectiveAbsences);
    }
    unset($moduleRow);

    foreach ($studentHistories as $history) {
        $decisions = $history['decisions'];
        $totalDecisions = count($decisions);
        if ($totalDecisions < 3) {
            continue; // Insufficient history for a useful pattern prediction.
        }

        $absent = count(array_filter($decisions, static fn(string $decision): bool => $decision === 'Absent'));
        $late = count(array_filter($decisions, static fn(string $decision): bool => $decision === 'Late'));
        $attended = $totalDecisions - $absent;
        $recent = array_slice($decisions, -3);
        $recentAbsent = count(array_filter($recent, static fn(string $decision): bool => $decision === 'Absent'));
        $recentLate = count(array_filter($recent, static fn(string $decision): bool => $decision === 'Late'));

        $absenceScore = (int) round(min(0.99, (0.65 * ($absent / $totalDecisions)) + (0.35 * ($recentAbsent / count($recent)))) * 100);
        if ($absent >= 2 && $absenceScore >= 35) {
            $absenceRisks[] = $history + [
                'total' => $totalDecisions,
                'absent' => $absent,
                'recent_absent' => $recentAbsent,
                'score' => $absenceScore,
                'level' => $absenceScore >= 65 || $absent >= 3 ? 'High' : 'Watch',
            ];
        }

        $lateRate = $attended > 0 ? $late / $attended : 0;
        $lateScore = (int) round(min(0.99, (0.70 * $lateRate) + (0.30 * ($recentLate / count($recent)))) * 100);
        if ($late >= 2 && $lateScore >= 40) {
            $lateRisks[] = $history + [
                'total' => $totalDecisions,
                'attended' => $attended,
                'late' => $late,
                'recent_late' => $recentLate,
                'score' => $lateScore,
                'level' => $lateScore >= 70 ? 'Persistent' : 'Frequent',
            ];
        }
    }

    usort($absenceRisks, static fn(array $a, array $b): int => [$b['score'], $b['absent']] <=> [$a['score'], $a['absent']]);
    usort($lateRisks, static fn(array $a, array $b): int => [$b['score'], $b['late']] <=> [$a['score'], $a['late']]);
    $absenceRisks = array_slice($absenceRisks, 0, 20);
    $lateRisks = array_slice($lateRisks, 0, 20);
}

// Recalculate the cards after applying the paired Sign In/Sign Out rules.
$overallTotal = 0;
$overallAttended = 0;
foreach ($modules as $moduleRow) {
    $overallTotal += (int) $moduleRow['total_signins'];
    $overallAttended += (int) $moduleRow['attended_signins'];
}
$overallRate = $overallTotal > 0 ? round($overallAttended / $overallTotal * 100) : 0;

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Module &amp; Attendance Reports</h4>
<p class="text-muted small mb-3">
  Current session:
  <?= $currentWindow ? e(ClassAttendance::describeWindow($currentWindow)) : 'No active session window' ?>
</p>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Ongoing Modules / <?= e($sessionLabel) ?></div><div class="stat-value"><?= count($modules) ?></div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Attendance Decisions Recorded</div><div class="stat-value"><?= $overallTotal ?></div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Overall Attendance Rate</div><div class="stat-value"><?= $overallRate ?>%</div>
    <div class="progress mt-2" style="height:6px;"><div class="progress-bar" style="width:<?= $overallRate ?>%;background-color:var(--semas-gold);"></div></div>
  </div></div>
</div>

<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2">
    <div class="col-md-4"><input name="q" class="form-control form-control-sm" value="<?= e($search) ?>"></div>
    <div class="col-md-3">
      <select name="department_id" class="form-select form-select-sm">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= (string) $deptFilter === (string) $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="session" class="form-select form-select-sm">
        <option value="" <?= $sessionFilter === '' ? 'selected' : '' ?>>All Sessions</option>
        <option value="Day" <?= $sessionFilter === 'Day' ? 'selected' : '' ?>>Day</option>
        <option value="Evening" <?= $sessionFilter === 'Evening' ? 'selected' : '' ?>>Evening</option>
        <option value="Weekend" <?= $sessionFilter === 'Weekend' ? 'selected' : '' ?>>Weekend</option>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-sm btn-semas w-100"><i class="bi bi-search me-1"></i> Filter</button></div>
  </form>
</div>

<div class="semas-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <h6 class="display-font mb-1"><i class="bi bi-stars me-1" style="color:var(--semas-gold);"></i> AI Attendance Insights</h6>
      <div class="text-muted small">Predictive indicators from each student's module history and three most recent attendance decisions. At least three recorded sessions are required.</div>
    </div>
    <span class="badge bg-light text-dark border">Local &amp; explainable</span>
  </div>

  <div class="row g-3">
    <div class="col-xl-6">
      <div class="border rounded h-100 p-2">
        <div class="d-flex justify-content-between align-items-center px-1 mb-2">
          <strong><i class="bi bi-person-exclamation text-danger me-1"></i> Likely to Miss More Classes</strong>
          <span class="badge bg-danger"><?= count($absenceRisks) ?></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Student</th><th>Module</th><th>History</th><th>Risk</th></tr></thead>
            <tbody>
              <?php foreach ($absenceRisks as $risk): ?>
                <tr>
                  <td><strong><?= e($risk['full_name']) ?></strong><div class="text-muted" style="font-size:.72rem;"><?= e($risk['reg_number'] ?: 'No reg. number') ?></div></td>
                  <td><?= e($risk['module_title']) ?><div class="text-muted" style="font-size:.72rem;"><?= e($risk['department_name'] ?? '-') ?></div></td>
                  <td><span class="text-danger fw-semibold"><?= (int) $risk['absent'] ?> absent</span> / <?= (int) $risk['total'] ?><div class="text-muted" style="font-size:.72rem;"><?= (int) $risk['recent_absent'] ?> in the latest 3</div></td>
                  <td><span class="badge <?= $risk['level'] === 'High' ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= e($risk['level']) ?> · <?= (int) $risk['score'] ?>%</span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$absenceRisks): ?><tr><td colspan="4" class="text-muted text-center py-3">No repeated-absence risk pattern found for the selected modules.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-xl-6">
      <div class="border rounded h-100 p-2">
        <div class="d-flex justify-content-between align-items-center px-1 mb-2">
          <strong><i class="bi bi-clock-history text-warning me-1"></i> Frequently Late Students</strong>
          <span class="badge bg-warning text-dark"><?= count($lateRisks) ?></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Student</th><th>Module</th><th>History</th><th>Pattern</th></tr></thead>
            <tbody>
              <?php foreach ($lateRisks as $risk): ?>
                <tr>
                  <td><strong><?= e($risk['full_name']) ?></strong><div class="text-muted" style="font-size:.72rem;"><?= e($risk['reg_number'] ?: 'No reg. number') ?></div></td>
                  <td><?= e($risk['module_title']) ?><div class="text-muted" style="font-size:.72rem;"><?= e($risk['department_name'] ?? '-') ?></div></td>
                  <td><span class="fw-semibold" style="color:#856404;"><?= (int) $risk['late'] ?> late</span> / <?= (int) $risk['attended'] ?> attended<div class="text-muted" style="font-size:.72rem;"><?= (int) $risk['recent_late'] ?> in the latest 3</div></td>
                  <td><span class="badge <?= $risk['level'] === 'Persistent' ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= e($risk['level']) ?> · <?= (int) $risk['score'] ?>%</span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$lateRisks): ?><tr><td colspan="4" class="text-muted text-center py-3">No frequent-lateness pattern found for the selected modules.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="semas-card p-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Module</th><th>Department</th><th>Lecturer</th><th>Session</th><th>Room</th><th>Students</th><th>Sessions</th><th>P</th><th>L</th><th>A</th><th>Attendance</th></tr></thead>
      <tbody>
        <?php foreach ($modules as $m):
            $rate = (int) $m['total_signins'] > 0 ? round((int) $m['attended_signins'] / (int) $m['total_signins'] * 100) : null;
            $slotLabel = ($m['session_type'] === 'Weekend' && !empty($m['weekend_slot'])) ? 'Weekend / ' . $m['weekend_slot'] : $m['session_type'];
        ?>
          <tr>
            <td class="fw-semibold"><?= e($m['module_title']) ?></td>
            <td><?= e($m['department_name'] ?? '-') ?></td>
            <td><?= e($m['lecturer_name'] ?? '-') ?></td>
            <td><?= e($slotLabel ?: '-') ?></td>
            <td><?= e($m['room_name'] ?? '-') ?></td>
            <td><?= (int) $m['student_count'] ?></td>
            <td><?= (int) $m['sessions_held'] ?></td>
            <td><span style="color:#155724;"><?= (int) $m['present_count'] ?></span></td>
            <td><span style="color:#856404;"><?= (int) $m['late_count'] ?></span></td>
            <td><span style="color:#721c24;"><?= (int) $m['absent_count'] ?></span></td>
            <td><?= $rate === null ? '<span class="text-muted">No data</span>' : '<span class="badge ' . ($rate >= 75 ? 'badge-completed' : 'badge-urgent') . '">' . $rate . '%</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$modules): ?><tr><td colspan="11" class="text-muted small text-center py-3">No ongoing modules match your filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
