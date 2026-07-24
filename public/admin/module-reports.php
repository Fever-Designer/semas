<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal']);

$pageTitle = 'Module & Attendance Reports';
$activeNav = 'module-reports';
$db = Database::connection();
$activeSemester = Semester::active($db);

$search = trim($_GET['q'] ?? '');
$deptFilter = $_GET['department_id'] ?? '';
$reportMode = ($_GET['report'] ?? 'class') === 'assessment' ? 'assessment' : 'class';
$assessmentType = $_GET['assessment_type'] ?? 'CAT';
if (!in_array($assessmentType, ['CAT', 'Exam'], true)) {
    $assessmentType = 'CAT';
}
$selectedScheduleId = (int) ($_GET['schedule_id'] ?? 0);
$printTarget = $_GET['print'] ?? '';
if (!in_array($printTarget, ['', 'class-summary', 'assessment-summary', 'assessment-register'], true)) {
    $printTarget = '';
}
$universityName = 'UNIVERSITY';
$period = $_GET['period'] ?? 'monthly';
$anchorInput = $_GET['date'] ?? date('Y-m-d');
$anchor = DateTime::createFromFormat('!Y-m-d', $anchorInput) ?: new DateTime('today');
$today = new DateTime('today');
if ($anchor > $today) {
    $anchor = clone $today;
}
if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
    $period = 'monthly';
}
if ($period === 'daily') {
    $dateFrom = clone $anchor;
    $dateTo = clone $anchor;
} elseif ($period === 'weekly') {
    $dateFrom = (clone $anchor)->modify('monday this week');
    $dateTo = (clone $dateFrom)->modify('+6 days');
} else {
    $dateFrom = (clone $anchor)->modify('first day of this month');
    $dateTo = (clone $anchor)->modify('last day of this month');
}
if ($dateTo > $today) {
    $dateTo = clone $today;
}
$dateFromSql = $dateFrom->format('Y-m-d');
$dateToSql = $dateTo->format('Y-m-d');
$periodLabel = ucfirst($period) . ' / ' . $dateFrom->format('d M Y')
    . ($dateFromSql !== $dateToSql ? ' - ' . $dateTo->format('d M Y') : '');

$sessionFilter = $_GET['session'] ?? '';
if (!in_array($sessionFilter, ['', 'Day', 'Evening', 'Weekend'], true)) {
    $sessionFilter = '';
}

$staffNameForRole = static function (string $role, ?int $departmentId = null) use ($db): string {
    $sql = 'SELECT u.full_name
            FROM users u
            JOIN roles r ON r.role_id = u.role_id
            WHERE r.role_name = :role AND u.status = :status';
    $params = ['role' => $role, 'status' => 'Active'];
    if ($departmentId !== null) {
        $sql .= ' AND u.department_id = :department_id';
        $params['department_id'] = $departmentId;
    }
    $sql .= ' ORDER BY u.full_name LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (string) ($stmt->fetchColumn() ?: 'Not assigned');
};
$reportHodName = $staffNameForRole('HOD', $deptFilter !== '' ? (int) $deptFilter : null);
$reportRegistrarName = $staffNameForRole('Registrar');
$reportPrincipalName = $staffNameForRole('Principal');

$where = [$activeSemester ? 'm.semester_id = :active_semester_id' : '1=0'];
$params = [];
if ($activeSemester) {
    $params['active_semester_id'] = (int) $activeSemester['id'];
}
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
        0 AS sessions_held, 0 AS total_signins, 0 AS attended_signins,
        0 AS present_count, 0 AS late_count, 0 AS absent_count, 0 AS special_count
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

$assessmentWhere = [
    'ces.exam_type = :assessment_type',
    'ces.scheduled_date BETWEEN :assessment_from AND :assessment_to',
    $activeSemester ? 'ces.semester_id = :assessment_semester_id' : '1=0',
];
$assessmentParams = [
    'assessment_type' => $assessmentType,
    'assessment_from' => $dateFromSql,
    'assessment_to' => $dateToSql,
];
if ($activeSemester) {
    $assessmentParams['assessment_semester_id'] = (int) $activeSemester['id'];
}
if ($search !== '') {
    $assessmentWhere[] = 'm.module_title LIKE :assessment_q';
    $assessmentParams['assessment_q'] = "%$search%";
}
if ($deptFilter !== '') {
    $assessmentWhere[] = 'm.department_id = :assessment_dept';
    $assessmentParams['assessment_dept'] = (int) $deptFilter;
}
if ($sessionFilter !== '') {
    $assessmentWhere[] = 'm.session_type = :assessment_session';
    $assessmentParams['assessment_session'] = $sessionFilter;
}
$assessmentStmt = $db->prepare(
    "SELECT ces.schedule_id, ces.exam_type, ces.scheduled_date, ces.start_time, ces.end_time,
            ces.room, ces.created_at AS schedule_created_at,
            m.module_title, m.session_type, d.department_name,
            lecturer_user.full_name AS lecturer_name,
            invigilator_user.full_name AS invigilator_name,
            sub.submitted_at,
            (SELECT COUNT(*) FROM module_enrollments me WHERE me.module_id = m.module_id) AS registered_count,
            (SELECT COUNT(DISTINCT sin.user_id) FROM cat_exam_attendance_logs sin
                WHERE sin.schedule_id = ces.schedule_id AND sin.attendance_type = 'Sign In') AS sat_count,
            (SELECT COUNT(DISTINCT sout.user_id) FROM cat_exam_attendance_logs sout
                WHERE sout.schedule_id = ces.schedule_id AND sout.attendance_type = 'Sign Out'
                  AND sout.status = 'Present') AS completed_count,
            (SELECT COUNT(DISTINCT missed.user_id) FROM cat_exam_attendance_logs missed
                WHERE missed.schedule_id = ces.schedule_id AND missed.attendance_type = 'Sign Out'
                  AND missed.status = 'Absent') AS missed_signout_count
     FROM cat_exam_schedules ces
     JOIN modules m ON m.module_id = ces.module_id
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers lecturer ON lecturer.lecturer_id = m.lecturer_id
     LEFT JOIN users lecturer_user ON lecturer_user.user_id = lecturer.user_id
     LEFT JOIN lecturers invigilator ON invigilator.lecturer_id = ces.invigilator_id
     LEFT JOIN users invigilator_user ON invigilator_user.user_id = invigilator.user_id
     LEFT JOIN cat_exam_submissions sub ON sub.schedule_id = ces.schedule_id
     WHERE " . implode(' AND ', $assessmentWhere) . "
     ORDER BY ces.scheduled_date, ces.start_time, d.department_name, m.module_title"
);
$assessmentStmt->execute($assessmentParams);
$assessmentRows = $assessmentStmt->fetchAll();

$assessmentTotals = ['scheduled' => count($assessmentRows), 'registered' => 0, 'sat' => 0, 'absent' => 0, 'missed_signout' => 0, 'submitted' => 0];
foreach ($assessmentRows as &$assessmentRow) {
    $assessmentRow['absent_count'] = $assessmentRow['submitted_at']
        ? max(0, (int) $assessmentRow['registered_count'] - (int) $assessmentRow['sat_count'])
        : 0;
    $assessmentTotals['registered'] += (int) $assessmentRow['registered_count'];
    $assessmentTotals['sat'] += (int) $assessmentRow['sat_count'];
    $assessmentTotals['absent'] += (int) $assessmentRow['absent_count'];
    $assessmentTotals['missed_signout'] += (int) $assessmentRow['missed_signout_count'];
    if ($assessmentRow['submitted_at']) $assessmentTotals['submitted']++;
}
unset($assessmentRow);

// Headline cards intentionally describe only the newest schedule, regardless
// of the selected report date range. A newly created sitting immediately
// replaces the previous sitting's cards; the table remains period-based.
$latestWhere = ['ces.exam_type = :latest_type', $activeSemester ? 'ces.semester_id = :latest_semester_id' : '1=0'];
$latestParams = ['latest_type' => $assessmentType];
if ($activeSemester) {
    $latestParams['latest_semester_id'] = (int) $activeSemester['id'];
}
if ($search !== '') {
    $latestWhere[] = 'm.module_title LIKE :latest_q';
    $latestParams['latest_q'] = "%$search%";
}
if ($deptFilter !== '') {
    $latestWhere[] = 'm.department_id = :latest_dept';
    $latestParams['latest_dept'] = (int) $deptFilter;
}
if ($sessionFilter !== '') {
    $latestWhere[] = 'm.session_type = :latest_session';
    $latestParams['latest_session'] = $sessionFilter;
}
$latestStmt = $db->prepare(
    "SELECT ces.schedule_id, ces.scheduled_date, m.module_title,
            (SELECT COUNT(*) FROM module_enrollments me WHERE me.module_id = m.module_id) AS registered_count,
            (SELECT COUNT(DISTINCT sin.user_id) FROM cat_exam_attendance_logs sin
                WHERE sin.schedule_id = ces.schedule_id AND sin.attendance_type = 'Sign In') AS sat_count,
            EXISTS (SELECT 1 FROM cat_exam_submissions sub WHERE sub.schedule_id = ces.schedule_id) AS is_submitted
     FROM cat_exam_schedules ces
     JOIN modules m ON m.module_id = ces.module_id
     WHERE " . implode(' AND ', $latestWhere) . "
     ORDER BY ces.created_at DESC, ces.schedule_id DESC
     LIMIT 1"
);
$latestStmt->execute($latestParams);
$latestAssessmentRow = $latestStmt->fetch() ?: null;
if ($latestAssessmentRow) {
    $latestAssessmentRow['absent_count'] = (int) $latestAssessmentRow['is_submitted'] === 1
        ? max(0, (int) $latestAssessmentRow['registered_count'] - (int) $latestAssessmentRow['sat_count'])
        : 0;
}
$latestAssessmentStats = [
    'scheduled' => $latestAssessmentRow ? 1 : 0,
    'registered' => (int) ($latestAssessmentRow['registered_count'] ?? 0),
    'sat' => (int) ($latestAssessmentRow['sat_count'] ?? 0),
    'absent' => (int) ($latestAssessmentRow['absent_count'] ?? 0),
];

$selectedAssessment = null;
$assessmentRoster = [];
if ($reportMode === 'assessment' && $selectedScheduleId > 0) {
    foreach ($assessmentRows as $assessmentRow) {
        if ((int) $assessmentRow['schedule_id'] === $selectedScheduleId) {
            $selectedAssessment = $assessmentRow;
            break;
        }
    }
    if ($selectedAssessment) {
        $rosterStmt = $db->prepare(
            "SELECT u.full_name, u.reg_number,
                    sin.recorded_at AS signin_time, sout.recorded_at AS signout_time,
                    sout.status AS signout_status, sout.missed_reason, sout.missed_notes,
                    el.final_decision AS eligibility
             FROM module_enrollments me
             JOIN users u ON u.user_id = me.user_id
             JOIN cat_exam_schedules ces ON ces.module_id = me.module_id
             LEFT JOIN cat_exam_attendance_logs sin ON sin.schedule_id = ces.schedule_id
                AND sin.user_id = me.user_id AND sin.attendance_type = 'Sign In'
             LEFT JOIN cat_exam_attendance_logs sout ON sout.schedule_id = ces.schedule_id
                AND sout.user_id = me.user_id AND sout.attendance_type = 'Sign Out'
             LEFT JOIN cat_exam_eligibility el ON el.module_id = me.module_id
                AND el.user_id = me.user_id AND el.exam_type = ces.exam_type
             WHERE ces.schedule_id = :sid
             ORDER BY u.full_name"
        );
        $rosterStmt->execute(['sid' => $selectedScheduleId]);
        $assessmentRoster = $rosterStmt->fetchAll();
    }
}

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
             AND cs.status = 'Closed'
             AND cs.start_time >= me.registered_at
             AND cs.session_date BETWEEN " . $db->quote($dateFromSql) . " AND " . $db->quote($dateToSql) . "
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
    $moduleStudentDecisions = [];
    foreach ($studentHistories as $history) {
        $mid = (int) $history['module_id'];
        $moduleDecisionTotals[$mid] ??= ['Present' => 0, 'Late' => 0, 'Absent' => 0];
        $moduleStudentDecisions[$mid][] = $history['decisions'];
        foreach ($history['decisions'] as $decision) {
            $moduleDecisionTotals[$mid][$decision]++;
        }
    }
    $sessionsStmt = $db->query(
        "SELECT cs.module_id, COUNT(DISTINCT cs.session_date) AS sessions_held
         FROM class_sessions cs
         JOIN modules m ON m.module_id = cs.module_id
         LEFT JOIN holidays h ON h.holiday_date = cs.session_date AND h.holiday_type = 'Public Holiday'
         WHERE cs.module_id IN ($moduleIdSql)
           AND cs.status = 'Closed'
           AND cs.session_date BETWEEN " . $db->quote($dateFromSql) . " AND " . $db->quote($dateToSql) . "
           AND (m.cat_date IS NULL OR cs.session_date <> m.cat_date)
           AND (m.exam_date IS NULL OR cs.session_date <> m.exam_date)
           AND (m.session_type = 'Weekend' OR h.holiday_id IS NULL)
         GROUP BY cs.module_id"
    );
    $sessionsByModule = [];
    foreach ($sessionsStmt->fetchAll() as $sessionRow) {
        $sessionsByModule[(int) $sessionRow['module_id']] = (int) $sessionRow['sessions_held'];
    }
    foreach ($modules as &$moduleRow) {
        $mid = (int) $moduleRow['module_id'];
        $decisionCounts = $moduleDecisionTotals[$mid] ?? ['Present' => 0, 'Late' => 0, 'Absent' => 0];
        $moduleRow['sessions_held'] = $sessionsByModule[$mid] ?? 0;
        $moduleRow['present_count'] = $decisionCounts['Present'];
        $moduleRow['late_count'] = $decisionCounts['Late'];
        $moduleRow['absent_count'] = $decisionCounts['Absent'];
        $moduleRow['special_count'] = count(array_filter(
            $moduleStudentDecisions[$mid] ?? [],
            static function (array $decisions): bool {
                $absent = count(array_filter($decisions, static fn(string $d): bool => $d === 'Absent'));
                $late = count(array_filter($decisions, static fn(string $d): bool => $d === 'Late'));
                return $absent + intdiv($late, 2) >= 3;
            }
        ));
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
$summaryStudents = 0;
$summarySessions = 0;
$summaryPresent = 0;
$summaryAbsent = 0;
$summaryLate = 0;
$summarySpecial = 0;
foreach ($modules as $moduleRow) {
    $overallTotal += (int) $moduleRow['total_signins'];
    $overallAttended += (int) $moduleRow['attended_signins'];
    $summaryStudents += (int) $moduleRow['student_count'];
    $summarySessions += (int) $moduleRow['sessions_held'];
    $summaryPresent += (int) $moduleRow['present_count'];
    $summaryAbsent += (int) $moduleRow['absent_count'];
    $summaryLate += (int) $moduleRow['late_count'];
    $summarySpecial += (int) $moduleRow['special_count'];
}
$overallRate = $overallTotal > 0 ? round($overallAttended / $overallTotal * 100) : 0;

$exportType = $_GET['export'] ?? '';
if ($reportMode === 'assessment' && $exportType === 'assessment-summary-pdf') {
    if (!$activeSemester) {
        http_response_code(409);
        exit(Semester::NO_ACTIVE_MESSAGE);
    }
    $generatedAt = date('d F Y, h:i A');
    ob_start();
    ?>
<!doctype html>
<html><head><meta charset="utf-8"><style>
  @page { size:A4 landscape; margin:12mm; }
  body { font-family:DejaVu Sans, sans-serif; color:#172033; font-size:9px; }
  h1 { margin:0; text-align:center; font-size:20px; color:#172554; }
  h2 { margin:5px 0 2px; text-align:center; font-size:13px; }
  .meta { text-align:center; color:#475569; margin-bottom:14px; }
  .summary { width:100%; margin-bottom:12px; border-collapse:collapse; table-layout:fixed; }
  .summary th, .summary td { border:1px solid #000; background:#fff; color:#000; padding:7px; text-align:center; }
  .summary th { font-size:8px; text-transform:uppercase; }
  .summary td { font-size:13px; font-weight:bold; }
  table.report { width:100%; border-collapse:collapse; table-layout:fixed; }
  .report th, .report td { border:1px solid #000; }
  .report th { background:#fff; color:#000; padding:6px 4px; font-size:8px; text-transform:uppercase; }
  .report td { background:#fff; padding:6px 4px; vertical-align:top; overflow-wrap:anywhere; }
  .center { text-align:center; } .good { color:#166534; font-weight:bold; } .bad { color:#991b1b; font-weight:bold; }
  .small { color:#64748b; font-size:8px; margin-top:2px; }
  .totals td { background:#fff !important; font-weight:bold; }
  .footer { margin-top:22px; width:100%; }
  .footer td { width:33.33%; padding-top:22px; text-align:center; }
  .line { border-top:1px solid #334155; padding-top:4px; margin:0 18px; }
</style></head><body>
  <h1><?= e($universityName) ?></h1>
  <h2>University-Wide <?= e($assessmentType) ?> Attendance Report</h2>
  <div class="meta"><?= e(Semester::label($activeSemester)) ?> &nbsp;|&nbsp; <?= e($periodLabel) ?> &nbsp;|&nbsp; <?= e($sessionLabel) ?> &nbsp;|&nbsp; Generated <?= e($generatedAt) ?></div>
  <table class="summary">
    <thead><tr><th>Scheduled sittings</th><th>Registered candidates</th><th>Candidates who sat</th><th>Absent candidates</th><th>Missed sign-out</th></tr></thead>
    <tbody><tr><td><?= $assessmentTotals['scheduled'] ?></td><td><?= $assessmentTotals['registered'] ?></td><td><?= $assessmentTotals['sat'] ?></td><td><?= $assessmentTotals['absent'] ?></td><td><?= $assessmentTotals['missed_signout'] ?></td></tr></tbody>
  </table>
  <table class="report">
    <colgroup>
      <col style="width:10%"><col style="width:18%"><col style="width:9%"><col style="width:14%">
      <col style="width:14%"><col style="width:8%"><col style="width:6%"><col style="width:5%">
      <col style="width:5%"><col style="width:6%"><col style="width:5%">
    </colgroup>
    <thead><tr><th>Date / Session</th><th>Module / Department</th><th>Room</th><th>Lecturer</th><th>Invigilator</th><th>Status</th><th>Registered</th><th>Sat</th><th>Absent</th><th>Missed Out</th><th>Rate</th></tr></thead>
    <tbody>
    <?php foreach ($assessmentRows as $row):
        $rate = (int) $row['registered_count'] > 0 ? round((int) $row['sat_count'] * 100 / (int) $row['registered_count']) : 0;
    ?>
      <tr>
        <td><strong><?= date('d M Y', strtotime($row['scheduled_date'])) ?></strong><div class="small"><?= e($row['session_type']) ?> &middot; <?= $row['start_time'] ? date('h:i A', strtotime($row['start_time'])) : '-' ?></div></td>
        <td><strong><?= e($row['module_title']) ?></strong><div class="small"><?= e($row['department_name'] ?? '-') ?></div></td>
        <td><?= e($row['room'] ?? '-') ?></td>
        <td><?= e($row['lecturer_name'] ?? '-') ?></td>
        <td><?= e($row['invigilator_name'] ?? '-') ?></td>
        <td class="center"><?= $row['submitted_at'] ? 'Submitted' : 'Pending' ?></td>
        <td class="center"><?= (int) $row['registered_count'] ?></td>
        <td class="center good"><?= (int) $row['sat_count'] ?></td>
        <td class="center bad"><?= (int) $row['absent_count'] ?></td>
        <td class="center"><?= (int) $row['missed_signout_count'] ?></td>
        <td class="center"><?= $rate ?>%</td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$assessmentRows): ?><tr><td colspan="11" class="center">No matching assessment schedules.</td></tr><?php endif; ?>
    <?php if ($assessmentRows): ?><tr class="totals"><td colspan="6">UNIVERSITY TOTAL</td><td class="center"><?= $assessmentTotals['registered'] ?></td><td class="center"><?= $assessmentTotals['sat'] ?></td><td class="center"><?= $assessmentTotals['absent'] ?></td><td class="center"><?= $assessmentTotals['missed_signout'] ?></td><td class="center"><?= $assessmentTotals['registered'] ? round($assessmentTotals['sat'] * 100 / $assessmentTotals['registered']) : 0 ?>%</td></tr><?php endif; ?>
    </tbody>
  </table>
  <table class="footer"><tr>
    <td><div class="line"><strong><?= e($reportHodName) ?></strong><br>Prepared by / Head Of Department</div></td>
    <td><div class="line"><strong><?= e($reportRegistrarName) ?></strong><br>Academic Registrar</div></td>
    <td><div class="line"><strong><?= e($reportPrincipalName) ?></strong><br>Principal / Approval</div></td>
  </tr></table>
</body></html>
    <?php
    $html = (string) ob_get_clean();
    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream(
        strtolower($assessmentType) . '-university-attendance-' . $dateFromSql . '-to-' . $dateToSql . '.pdf',
        ['Attachment' => false]
    );
    exit;
}

require __DIR__ . '/../partials/layout_top.php';
?>
<style>
  .report-print-heading { display:none; }
  .plain-summary {
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
  }
  .plain-summary th,
  .plain-summary td {
    border:1px solid #000 !important;
    background:#fff !important;
    color:#000 !important;
    padding:.55rem;
    text-align:center;
  }
  .plain-summary th {
    font-weight:600;
    vertical-align:middle;
  }
  .plain-summary td {
    font-size:1rem;
    font-weight:700;
  }
  table.table {
    border-collapse:collapse;
  }
  table.table > :not(caption) > * > * {
    border:1px solid #000 !important;
    background:#fff !important;
    color:#000 !important;
    box-shadow:none !important;
  }
  table.table .badge {
    border:0 !important;
    background:#fff !important;
    color:#000 !important;
  }
  .report-print-area table {
    border-collapse:collapse;
  }
  .report-print-area table > :not(caption) > * > * {
    color:#000 !important;
    background:#fff !important;
    border:1px solid #000 !important;
    box-shadow:none !important;
  }
  .report-print-area table .badge {
    color:#000 !important;
    background:#fff !important;
    border:0 !important;
  }
  .report-approvals {
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:2rem;
    margin-top:2.5rem;
    text-align:center;
    color:#000;
  }
  .report-approval {
    border-top:1px solid #000;
    padding-top:.35rem;
  }
  .report-approval strong { display:block; }
  @media print {
    @page { size: landscape; margin: 12mm; }
    body * { visibility:hidden !important; }
    .print-selected, .print-selected * { visibility:visible !important; }
    .print-selected {
      position:absolute !important;
      inset:0 auto auto 0 !important;
      width:100% !important;
      border:0 !important;
      box-shadow:none !important;
      padding:0 !important;
    }
    .report-print-heading { display:block !important; text-align:center; margin-bottom:18px; }
    .report-print-heading h3 { margin:0 0 4px; }
    .report-print-heading p { margin:2px 0; }
    .report-screen-heading, .no-print { display:none !important; visibility:hidden !important; }
    .table-responsive { overflow:visible !important; }
    table { width:100% !important; font-size:9pt; }
    thead { display:table-header-group; }
    tr { break-inside:avoid; }
  }
</style>
<h4 class="display-font mb-1">Module &amp; Attendance Reports</h4>
<p class="text-muted small mb-3">
  <?= e($periodLabel) ?> &middot; University-wide finalized attendance reporting
</p>

<div class="btn-group mb-3" role="group" aria-label="Report type">
  <a class="btn btn-sm <?= $reportMode === 'class' ? 'btn-semas' : 'btn-outline-dark' ?>" href="?report=class"><i class="bi bi-people me-1"></i> Class Attendance</a>
  <a class="btn btn-sm <?= $reportMode === 'assessment' ? 'btn-semas' : 'btn-outline-dark' ?>" href="?report=assessment&amp;assessment_type=CAT"><i class="bi bi-journal-check me-1"></i> CAT / Exam Report</a>
</div>

<div class="mb-4">
  <?php if ($reportMode === 'assessment'): ?>
    <table class="plain-summary">
      <thead><tr><th>Scheduled sittings</th><th>Registered candidates</th><th>Candidates who sat</th><th>Absent candidates</th><th>Missed sign-out</th></tr></thead>
      <tbody><tr><td><?= $assessmentTotals['scheduled'] ?></td><td><?= $assessmentTotals['registered'] ?></td><td><?= $assessmentTotals['sat'] ?></td><td><?= $assessmentTotals['absent'] ?></td><td><?= $assessmentTotals['missed_signout'] ?></td></tr></tbody>
    </table>
  <?php else: ?>
    <table class="plain-summary">
      <thead><tr><th>Modules / <?= e($sessionLabel) ?></th><th>Closed sessions</th><th>Special cases / 3+ missed</th><th>Overall attendance rate</th></tr></thead>
      <tbody><tr><td><?= count($modules) ?></td><td><?= $summarySessions ?></td><td><?= $summarySpecial ?></td><td><?= $overallRate ?>%</td></tr></tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($reportMode === 'assessment'): ?>
<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2">
    <input type="hidden" name="report" value="assessment">
    <div class="col-lg-2 col-md-4"><input name="q" class="form-control form-control-sm" value="<?= e($search) ?>" placeholder="Module name"></div>
    <div class="col-lg-2 col-md-4">
      <select name="department_id" class="form-select form-select-sm">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= (string) $deptFilter === (string) $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-lg-2 col-md-4">
      <select name="session" class="form-select form-select-sm">
        <option value="" <?= $sessionFilter === '' ? 'selected' : '' ?>>All Sessions</option>
        <option value="Day" <?= $sessionFilter === 'Day' ? 'selected' : '' ?>>Day</option>
        <option value="Evening" <?= $sessionFilter === 'Evening' ? 'selected' : '' ?>>Evening</option>
        <option value="Weekend" <?= $sessionFilter === 'Weekend' ? 'selected' : '' ?>>Weekend</option>
      </select>
    </div>
    <div class="col-lg-2 col-md-4">
      <select name="assessment_type" class="form-select form-select-sm">
        <option value="CAT" <?= $assessmentType === 'CAT' ? 'selected' : '' ?>>CAT Report</option>
        <option value="Exam" <?= $assessmentType === 'Exam' ? 'selected' : '' ?>>Exam Report</option>
      </select>
    </div>
    <div class="col-lg-2 col-md-4">
      <select name="period" class="form-select form-select-sm">
        <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
        <option value="weekly" <?= $period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
        <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
      </select>
    </div>
    <div class="col-lg-2 col-md-4"><input type="date" name="date" class="form-control form-control-sm" value="<?= e($anchor->format('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="col-lg-2 col-md-4"><button class="btn btn-sm btn-semas w-100"><i class="bi bi-file-earmark-bar-graph me-1"></i> Generate Report</button></div>
  </form>
</div>

<div class="semas-card p-3 mb-3 report-print-area <?= $printTarget === 'assessment-summary' ? 'print-selected' : '' ?>">
  <div class="report-print-heading">
    <h3><?= e($universityName) ?></h3>
    <p><strong>University-Wide <?= e($assessmentType) ?> Attendance Report</strong></p>
    <p><?= e(Semester::label($activeSemester)) ?> &middot; <?= e($periodLabel) ?> &middot; <?= e($sessionLabel) ?></p>
  </div>
  <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
    <div class="report-screen-heading">
      <h6 class="display-font mb-0"><?= e($assessmentType) ?> University Summary</h6>
      <span class="text-muted small"><?= e($periodLabel) ?> &middot; <?= e($sessionLabel) ?></span>
    </div>
    <a class="btn btn-sm btn-outline-dark no-print" target="_blank" href="?<?= e(http_build_query(array_merge(array_diff_key($_GET, ['print' => true]), ['export' => 'assessment-summary-pdf']))) ?>"><i class="bi bi-file-earmark-pdf me-1"></i> Open PDF Report</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr><th>Date / Time</th><th>Module</th><th>Department</th><th>Session</th><th>Room</th><th>Lecturer</th><th>Invigilator</th><th>Registered</th><th>Sat</th><th>Absent</th><th>Missed Sign-Out</th><th>Submission</th><th class="no-print"></th></tr>
      </thead>
      <tbody>
        <?php foreach ($assessmentRows as $row):
          $detailQuery = $_GET;
          $detailQuery['report'] = 'assessment';
          $detailQuery['assessment_type'] = $assessmentType;
          $detailQuery['schedule_id'] = (int) $row['schedule_id'];
        ?>
          <tr>
            <td class="text-nowrap"><strong><?= date('d M Y', strtotime($row['scheduled_date'])) ?></strong><div class="text-muted small"><?= $row['start_time'] ? date('h:i A', strtotime($row['start_time'])) : '-' ?><?= $row['end_time'] ? ' - ' . date('h:i A', strtotime($row['end_time'])) : '' ?></div></td>
            <td class="fw-semibold"><?= e($row['module_title']) ?></td>
            <td><?= e($row['department_name'] ?? '-') ?></td>
            <td><?= e($row['session_type'] ?? '-') ?></td>
            <td><?= e($row['room'] ?? '-') ?></td>
            <td><?= e($row['lecturer_name'] ?? '-') ?></td>
            <td><?= e($row['invigilator_name'] ?? '-') ?></td>
            <td><?= (int) $row['registered_count'] ?></td>
            <td class="text-success fw-semibold"><?= (int) $row['sat_count'] ?></td>
            <td class="text-danger fw-semibold"><?= (int) $row['absent_count'] ?></td>
            <td class="<?= (int) $row['missed_signout_count'] ? 'text-warning fw-semibold' : '' ?>"><?= (int) $row['missed_signout_count'] ?></td>
            <td><span class="badge <?= $row['submitted_at'] ? 'badge-completed' : 'bg-warning text-dark' ?>"><?= $row['submitted_at'] ? 'Submitted' : 'Pending' ?></span></td>
            <td class="no-print"><a class="btn btn-sm btn-outline-dark text-nowrap" href="?<?= e(http_build_query($detailQuery)) ?>">View Students</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$assessmentRows): ?><tr><td colspan="13" class="text-muted text-center py-4">No <?= e($assessmentType) ?> schedules match this report period and filters.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($assessmentRows): ?>
        <tfoot class="table-light fw-bold">
          <tr><td colspan="7">University Total</td><td><?= $assessmentTotals['registered'] ?></td><td class="text-success"><?= $assessmentTotals['sat'] ?></td><td class="text-danger"><?= $assessmentTotals['absent'] ?></td><td><?= $assessmentTotals['missed_signout'] ?></td><td><?= $assessmentTotals['submitted'] ?>/<?= $assessmentTotals['scheduled'] ?></td><td class="no-print"></td></tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <div class="report-approvals">
    <div class="report-approval"><strong><?= e($reportHodName) ?></strong>Prepared by / Head Of Department</div>
    <div class="report-approval"><strong><?= e($reportRegistrarName) ?></strong>Academic Registrar</div>
    <div class="report-approval"><strong><?= e($reportPrincipalName) ?></strong>Principal / Approval</div>
  </div>
</div>

<?php if ($selectedAssessment): ?>
<div class="semas-card p-3 report-print-area <?= $printTarget === 'assessment-register' ? 'print-selected' : '' ?>">
  <div class="report-print-heading">
    <h3><?= e($universityName) ?></h3>
    <p><strong><?= e($selectedAssessment['exam_type']) ?> Assessment Attendance Register</strong></p>
    <p><?= e($selectedAssessment['module_title']) ?> &middot; <?= date('d F Y', strtotime($selectedAssessment['scheduled_date'])) ?> &middot; Room <?= e($selectedAssessment['room']) ?></p>
  </div>
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div class="report-screen-heading">
      <h6 class="display-font mb-1">Student Assessment Register / <?= e($selectedAssessment['module_title']) ?></h6>
      <div class="text-muted small"><?= e($selectedAssessment['exam_type']) ?> &middot; <?= date('d F Y', strtotime($selectedAssessment['scheduled_date'])) ?> &middot; Room <?= e($selectedAssessment['room']) ?> &middot; Invigilator: <?= e($selectedAssessment['invigilator_name'] ?? '-') ?></div>
    </div>
    <a class="btn btn-sm btn-outline-dark no-print" target="_blank" href="?<?= e(http_build_query(array_merge($_GET, ['print' => 'assessment-register']))) ?>"><i class="bi bi-printer me-1"></i> Print Register</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>NO</th><th>Registration Number</th><th>Student</th><th>Eligibility</th><th>Sign In</th><th>Sign Out</th><th>Assessment Status</th><th>Missed Reason</th></tr></thead>
      <tbody>
        <?php foreach ($assessmentRoster as $index => $student):
          $studentStatus = !$student['signin_time']
              ? 'Absent'
              : (!$student['signout_time'] || $student['signout_status'] === 'Absent' ? 'Missed Sign-Out' : 'Completed');
        ?>
          <tr class="<?= $studentStatus === 'Absent' ? 'table-danger' : ($studentStatus === 'Missed Sign-Out' ? 'table-warning' : '') ?>">
            <td><?= $index + 1 ?></td>
            <td><?= e($student['reg_number'] ?? '-') ?></td>
            <td class="fw-semibold"><?= e($student['full_name']) ?></td>
            <td><?= e($student['eligibility'] ?? 'Pending') ?></td>
            <td><?= $student['signin_time'] ? date('h:i A', strtotime($student['signin_time'])) : '-' ?></td>
            <td><?= $student['signout_time'] ? date('h:i A', strtotime($student['signout_time'])) : '-' ?></td>
            <td><span class="badge <?= $studentStatus === 'Completed' ? 'badge-completed' : ($studentStatus === 'Absent' ? 'bg-danger' : 'bg-warning text-dark') ?>"><?= e($studentStatus) ?></span></td>
            <td><?= e($student['missed_reason'] ?? '-') ?><?php if (!empty($student['missed_notes'])): ?><div class="text-muted small"><?= e($student['missed_notes']) ?></div><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="report-approvals">
    <div class="report-approval"><strong><?= e($reportHodName) ?></strong>Prepared by / Head Of Department</div>
    <div class="report-approval"><strong><?= e($reportRegistrarName) ?></strong>Academic Registrar</div>
    <div class="report-approval"><strong><?= e($reportPrincipalName) ?></strong>Principal / Approval</div>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2">
    <input type="hidden" name="report" value="<?= e($reportMode) ?>">
    <div class="col-lg-2 col-md-4"><input name="q" class="form-control form-control-sm" value="<?= e($search) ?>" placeholder="Module name"></div>
    <div class="col-lg-2 col-md-4">
      <select name="department_id" class="form-select form-select-sm">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= (string) $deptFilter === (string) $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-lg-2 col-md-4">
      <select name="session" class="form-select form-select-sm">
        <option value="" <?= $sessionFilter === '' ? 'selected' : '' ?>>All Sessions</option>
        <option value="Day" <?= $sessionFilter === 'Day' ? 'selected' : '' ?>>Day</option>
        <option value="Evening" <?= $sessionFilter === 'Evening' ? 'selected' : '' ?>>Evening</option>
        <option value="Weekend" <?= $sessionFilter === 'Weekend' ? 'selected' : '' ?>>Weekend</option>
      </select>
    </div>
    <div class="col-lg-2 col-md-4">
      <?php if ($reportMode === 'assessment'): ?>
        <select name="assessment_type" class="form-select form-select-sm">
          <option value="CAT" <?= $assessmentType === 'CAT' ? 'selected' : '' ?>>CAT Report</option>
          <option value="Exam" <?= $assessmentType === 'Exam' ? 'selected' : '' ?>>Exam Report</option>
        </select>
      <?php else: ?>
        <select name="period" class="form-select form-select-sm">
          <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily Report</option>
          <option value="weekly" <?= $period === 'weekly' ? 'selected' : '' ?>>Weekly Report</option>
          <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly Report</option>
        </select>
      <?php endif; ?>
    </div>
    <div class="col-lg-2 col-md-4">
      <?php if ($reportMode === 'assessment'): ?>
        <select name="period" class="form-select form-select-sm">
          <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
          <option value="weekly" <?= $period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
          <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
        </select>
      <?php else: ?>
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($anchor->format('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>">
      <?php endif; ?>
    </div>
    <?php if ($reportMode === 'assessment'): ?>
      <div class="col-lg-2 col-md-4"><input type="date" name="date" class="form-control form-control-sm" value="<?= e($anchor->format('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>"></div>
    <?php endif; ?>
    <div class="col-lg-2 col-md-4"><button class="btn btn-sm btn-semas w-100"><i class="bi bi-file-earmark-bar-graph me-1"></i> Generate Report</button></div>
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

<div class="semas-card p-3 report-print-area <?= $printTarget === 'class-summary' ? 'print-selected' : '' ?>">
  <div class="report-print-heading">
    <h3><?= e($universityName) ?></h3>
    <p><strong>University-Wide Module &amp; Class Attendance Report</strong></p>
    <p><?= e(Semester::label($activeSemester)) ?> &middot; <?= e($periodLabel) ?> &middot; <?= e($sessionLabel) ?></p>
  </div>
  <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
    <div class="report-screen-heading">
      <h6 class="display-font mb-0">All Modules Summary</h6>
      <span class="text-muted small"><?= e($periodLabel) ?></span>
    </div>
    <a class="btn btn-sm btn-outline-dark no-print" target="_blank" href="?<?= e(http_build_query(array_merge($_GET, ['print' => 'class-summary']))) ?>"><i class="bi bi-printer me-1"></i> Print Report</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Module Name</th><th>Lecturer</th><th>Room</th><th>Sessions</th><th>Registered Students</th><th>Presents</th><th>Absent</th><th>Late</th><th>Special Case (3+)</th><th>Attendance</th></tr></thead>
      <tbody>
        <?php foreach ($modules as $m):
            $rate = (int) $m['total_signins'] > 0 ? round((int) $m['attended_signins'] / (int) $m['total_signins'] * 100) : null;
        ?>
          <tr>
            <td class="fw-semibold"><?= e($m['module_title']) ?></td>
            <td><?= e($m['lecturer_name'] ?? '-') ?></td>
            <td><?= e($m['room_name'] ?? '-') ?></td>
            <td><?= (int) $m['sessions_held'] ?></td>
            <td><?= (int) $m['student_count'] ?></td>
            <td><span style="color:#155724;"><?= (int) $m['present_count'] ?></span></td>
            <td><span style="color:#721c24;"><?= (int) $m['absent_count'] ?></span></td>
            <td><span style="color:#856404;"><?= (int) $m['late_count'] ?></span></td>
            <td><span class="badge <?= (int) $m['special_count'] > 0 ? 'bg-danger' : 'bg-light text-dark border' ?>"><?= (int) $m['special_count'] ?></span></td>
            <td><?= $rate === null ? '<span class="text-muted">No data</span>' : '<span class="badge ' . ($rate >= 75 ? 'badge-completed' : 'badge-urgent') . '">' . $rate . '%</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$modules): ?><tr><td colspan="10" class="text-muted small text-center py-3">No modules match your filters.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($modules): ?>
        <tfoot class="table-light fw-bold">
          <tr>
            <td colspan="3">All Selected Modules</td>
            <td><?= $summarySessions ?></td>
            <td><?= $summaryStudents ?></td>
            <td class="text-success"><?= $summaryPresent ?></td>
            <td class="text-danger"><?= $summaryAbsent ?></td>
            <td style="color:#856404;"><?= $summaryLate ?></td>
            <td class="text-danger"><?= $summarySpecial ?></td>
            <td><?= $overallRate ?>%</td>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <div class="report-approvals">
    <div class="report-approval"><strong><?= e($reportHodName) ?></strong>Prepared by / Head Of Department</div>
    <div class="report-approval"><strong><?= e($reportRegistrarName) ?></strong>Academic Registrar</div>
    <div class="report-approval"><strong><?= e($reportPrincipalName) ?></strong>Principal / Approval</div>
  </div>
</div>

<?php endif; ?>
<?php if ($printTarget !== ''): ?>
<script>
window.addEventListener('load', function () {
  window.print();
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
