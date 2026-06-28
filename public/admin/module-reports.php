<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal']);

$pageTitle = 'Module & Attendance Reports';
$activeNav = 'module-reports';
$db = Database::connection();

$search = trim($_GET['q'] ?? '');
$deptFilter = $_GET['department_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$where = [];
$params = [];
if ($search !== '') { $where[] = 'm.module_title LIKE :q'; $params['q'] = "%$search%"; }
if ($deptFilter !== '') { $where[] = 'm.department_id = :dept'; $params['dept'] = (int) $deptFilter; }
if ($statusFilter !== '') { $where[] = 'm.status = :status'; $params['status'] = $statusFilter; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Read-only oversight: department, lecturer, registered students, sessions
// held, and an attendance rate (Present+Late Sign-Ins ÷ total Sign-Ins)
// for every module university-wide. This is reporting only — the
// Principal cannot edit a module, take attendance, or touch eligibility
// from here; that stays with the HOD/Lecturer.
$stmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name,
        (SELECT COUNT(*) FROM module_enrollments e WHERE e.module_id = m.module_id) AS student_count,
        (SELECT COUNT(*) FROM class_sessions cs WHERE cs.module_id = m.module_id AND cs.status = 'Closed') AS sessions_held,
        (SELECT COUNT(*) FROM class_attendance_logs cal JOIN class_sessions cs2 ON cs2.session_id = cal.session_id
            WHERE cs2.module_id = m.module_id AND cal.attendance_type = 'Sign In') AS total_signins,
        (SELECT COUNT(*) FROM class_attendance_logs cal JOIN class_sessions cs3 ON cs3.session_id = cal.session_id
            WHERE cs3.module_id = m.module_id AND cal.attendance_type = 'Sign In' AND cal.status IN ('Present','Late')) AS attended_signins
     FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     $whereSql ORDER BY d.department_name, m.module_title"
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

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Module &amp; Attendance Reports</h4>
<p class="text-muted small mb-4">Read-only oversight across every module, university-wide. Editing modules, taking attendance, and academic decisions stay with the HOD and Lecturers.</p>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Modules Shown</div><div class="stat-value"><?= count($modules) ?></div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Total Sign-Ins Recorded</div><div class="stat-value"><?= $overallTotal ?></div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Overall Attendance Rate</div><div class="stat-value"><?= $overallRate ?>%</div>
    <div class="progress mt-2" style="height:6px;"><div class="progress-bar" style="width:<?= $overallRate ?>%;background-color:var(--semas-gold);"></div></div>
  </div></div>
</div>

<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2">
    <div class="col-md-5"><input name="q" class="form-control form-control-sm" placeholder="Search module title" value="<?= e($search) ?>"></div>
    <div class="col-md-3">
      <select name="department_id" class="form-select form-select-sm">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= (string) $deptFilter === (string) $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <option value="Ongoing" <?= $statusFilter === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
        <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
      </select>
    </div>
    <div class="col-md-1"><button class="btn btn-sm btn-semas w-100"><i class="bi bi-search"></i></button></div>
  </form>
</div>

<div class="semas-card p-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Module</th><th>Department</th><th>Lecturer</th><th>Students</th><th>Sessions Held</th><th>Attendance Rate</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($modules as $m): $rate = (int) $m['total_signins'] > 0 ? round((int) $m['attended_signins'] / (int) $m['total_signins'] * 100) : null; ?>
          <tr>
            <td class="fw-semibold"><?= e($m['module_title']) ?></td>
            <td><?= e($m['department_name'] ?? '—') ?></td>
            <td><?= e($m['lecturer_name'] ?? '—') ?></td>
            <td><?= (int) $m['student_count'] ?></td>
            <td><?= (int) $m['sessions_held'] ?></td>
            <td><?= $rate === null ? '<span class="text-muted">No data</span>' : '<span class="badge ' . ($rate >= 75 ? 'badge-completed' : 'badge-urgent') . '">' . $rate . '%</span>' ?></td>
            <td><span class="badge <?= $m['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?>"><?= e($m['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$modules): ?><tr><td colspan="7" class="text-muted small text-center py-3">No modules match your filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
