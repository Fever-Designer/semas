<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal']);

$pageTitle = 'Manage Departments';
$activeNav = 'departments';
$db = Database::connection();
$me = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $db->prepare('INSERT INTO departments (faculty_id, department_name, department_code) VALUES (:fac, :name, :code)')
           ->execute([
               'fac' => (int) $_POST['faculty_id'],
               'name' => trim($_POST['department_name']),
               'code' => trim($_POST['department_code']),
           ]);
        $deptId = (int) $db->lastInsertId();
        AuditLog::record(Auth::id(), 'DEPARTMENT_CREATE', 'departments', $deptId);
        flash('success', 'Department created.');
    } elseif ($action === 'update') {
        $deptId = (int) $_POST['department_id'];
        $db->prepare('UPDATE departments SET department_name=:name, department_code=:code, faculty_id=:fac WHERE department_id=:id')
           ->execute(['name' => trim($_POST['department_name']), 'code' => trim($_POST['department_code']), 'fac' => (int) $_POST['faculty_id'], 'id' => $deptId]);
        AuditLog::record(Auth::id(), 'DEPARTMENT_UPDATE', 'departments', $deptId);
        flash('success', 'Department updated.');
    }
    redirect('/admin/departments.php');
}

$departments = $db->query(
    "SELECT d.*, f.faculty_name,
        (SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Student' AND u.department_id = d.department_id) AS student_count,
        (SELECT COUNT(*) FROM lecturers l WHERE l.department_id = d.department_id) AS lecturer_count,
        (SELECT COUNT(*) FROM modules m WHERE m.department_id = d.department_id) AS module_count,
        (SELECT u2.full_name FROM users u2 WHERE u2.user_id = d.hod_user_id) AS hod_name
     FROM departments d LEFT JOIN faculties f ON f.faculty_id = d.faculty_id
     ORDER BY d.department_name"
)->fetchAll();
$faculties = $db->query('SELECT faculty_id, faculty_name FROM faculties ORDER BY faculty_name')->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div><h4 class="display-font mb-1">Manage Departments</h4></div>
  <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#newDeptModal"><i class="bi bi-plus-circle me-1"></i> New Department</button>
</div>

<div class="row g-3">
  <?php foreach ($departments as $d): ?>
    <div class="col-md-4">
      <div class="semas-card p-3 h-100">
        <div class="d-flex justify-content-between align-items-start">
          <h6 class="display-font mb-1"><?= e($d['department_name']) ?></h6>
          <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#edit-<?= (int) $d['department_id'] ?>"><i class="bi bi-pencil"></i></button>
        </div>
        <p class="text-muted small mb-2"><?= e($d['faculty_name'] ?? '—') ?> &middot; Code: <?= e($d['department_code']) ?></p>
        <p class="small mb-0">HOD: <?= e($d['hod_name'] ?? 'Not assigned') ?></p>
        <p class="small text-muted mb-0"><?= (int) $d['student_count'] ?> students &middot; <?= (int) $d['lecturer_count'] ?> lecturers &middot; <?= (int) $d['module_count'] ?> modules</p>
      </div>
    </div>
    <div class="modal fade" id="edit-<?= (int) $d['department_id'] ?>" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="department_id" value="<?= (int) $d['department_id'] ?>">
            <div class="modal-header"><h6 class="modal-title display-font">Edit Department</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
              <div class="mb-2"><label class="form-label small">Department Name</label><input name="department_name" class="form-control form-control-sm" value="<?= e($d['department_name']) ?>" required></div>
              <div class="mb-2"><label class="form-label small">Department Code</label><input name="department_code" class="form-control form-control-sm" value="<?= e($d['department_code']) ?>" required placeholder="e.g. IT"></div>
              <div class="mb-2"><label class="form-label small">Faculty</label>
                <select name="faculty_id" class="form-select form-select-sm">
                  <?php foreach ($faculties as $f): ?><option value="<?= (int) $f['faculty_id'] ?>" <?= $d['faculty_id'] == $f['faculty_id'] ? 'selected' : '' ?>><?= e($f['faculty_name']) ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="modal-footer"><button class="btn btn-semas btn-sm">Save</button></div>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="newDeptModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header"><h6 class="modal-title display-font">New Department</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label small">Department Name</label><input name="department_name" class="form-control form-control-sm" required></div>
          <div class="mb-2"><label class="form-label small">Department Code</label><input name="department_code" class="form-control form-control-sm" required placeholder="e.g. IT"></div>
          <div class="mb-2"><label class="form-label small">Faculty</label>
            <select name="faculty_id" class="form-select form-select-sm" required>
              <option value="">Select faculty</option>
              <?php foreach ($faculties as $f): ?><option value="<?= (int) $f['faculty_id'] ?>"><?= e($f['faculty_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Create</button></div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
