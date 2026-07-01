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

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Module &amp; Attendance Reports</h4>
<p class="text-muted small mb-3">
  Ongoing modules only. Current session:
  <?= $currentWindow ? e(ClassAttendance::describeWindow($currentWindow)) : 'No active session window' ?>
</p>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Ongoing Modules / <?= e($sessionLabel) ?></div><div class="stat-value"><?= count($modules) ?></div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Total Sign-Ins Recorded</div><div class="stat-value"><?= $overallTotal ?></div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Overall Attendance Rate</div><div class="stat-value"><?= $overallRate ?>%</div>
    <div class="progress mt-2" style="height:6px;"><div class="progress-bar" style="width:<?= $overallRate ?>%;background-color:var(--semas-gold);"></div></div>
  </div></div>
</div>

<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2">
    <div class="col-md-4"><input name="q" class="form-control form-control-sm" placeholder="Search module title" value="<?= e($search) ?>"></div>
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

<div class="semas-card p-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Module</th><th>Department</th><th>Lecturer</th><th>Session</th><th>Room</th><th>Students</th><th>Sessions</th><th>P</th><th>L</th><th>A</th><th>Attendance</th></tr></thead>
      <tbody>
        <?php foreach ($modules as $m):
            $rate = (int) $m['total_signins'] > 0 ? round((int) $m['attended_signins'] / (int) $m['total_signins'] * 100) : null;
            $slotLabel = ($m['session_type'] === 'Weekend' && !empty($m['weekend_slot'])) ? 'Weekend - ' . $m['weekend_slot'] : $m['session_type'];
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
