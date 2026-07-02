<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Registrar']);

$pageTitle = 'Registrar Dashboard';
$activeNav = 'dashboard';
$db = Database::connection();
$user = Auth::user();

// Student counts
$totalStudents   = (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student'")->fetchColumn();
$activeStudents  = (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student' AND u.status='Active'")->fetchColumn();
$pendingStudents = (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student' AND u.status='Pending'")->fetchColumn();
$deactivated     = (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student' AND u.status='Deactivated'")->fetchColumn();

// Students by department
$byDept = $db->query(
    "SELECT d.department_name, d.department_code, COUNT(u.user_id) AS cnt
     FROM departments d
     LEFT JOIN users u ON u.department_id = d.department_id
     LEFT JOIN roles r  ON r.role_id = u.role_id AND r.role_name = 'Student'
     GROUP BY d.department_id ORDER BY cnt DESC"
)->fetchAll();

// Students by intake
$byIntake = $db->query(
    "SELECT COALESCE(u.intake,'Not Set') AS intake, COUNT(*) AS cnt
     FROM users u JOIN roles r ON r.role_id=u.role_id
     WHERE r.role_name='Student'
     GROUP BY u.intake ORDER BY cnt DESC"
)->fetchAll();

// Recently added students
$recentStudents = $db->query(
    "SELECT u.full_name, u.reg_number, u.email, d.department_name, u.status, u.created_at
     FROM users u JOIN roles r ON r.role_id=u.role_id
     LEFT JOIN departments d ON d.department_id=u.department_id
     WHERE r.role_name='Student' ORDER BY u.created_at DESC LIMIT 10"
)->fetchAll();

$totalFaculties   = (int) $db->query('SELECT COUNT(*) FROM faculties')->fetchColumn();
$totalDepartments = (int) $db->query('SELECT COUNT(*) FROM departments')->fetchColumn();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Registrar Dashboard</h4>

<div class="row g-3 mb-3">
  <div class="col-md-6 col-lg-3">
    <div class="stat-card"><i class="bi bi-mortarboard-fill stat-icon"></i>
      <div class="stat-label">Total Students</div><div class="stat-value"><?= $totalStudents ?></div>
    </div>
  </div>
  <div class="col-md-6 col-lg-3">
    <div class="stat-card"><i class="bi bi-person-check-fill stat-icon"></i>
      <div class="stat-label">Active Students</div><div class="stat-value"><?= $activeStudents ?></div>
    </div>
  </div>
  <div class="col-md-6 col-lg-3">
    <div class="stat-card"><i class="bi bi-hourglass-split stat-icon"></i>
      <div class="stat-label">Pending Activation</div><div class="stat-value"><?= $pendingStudents ?></div>
    </div>
  </div>
  <div class="col-md-6 col-lg-3">
    <div class="stat-card"><i class="bi bi-building stat-icon"></i>
      <div class="stat-label">Faculties / Departments</div><div class="stat-value"><?= $totalFaculties ?> / <?= $totalDepartments ?></div>
    </div>
  </div>
</div>

<div class="semas-card p-3 mb-4">
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/registrar/students.php" class="btn btn-semas-gold btn-sm"><i class="bi bi-person-plus-fill me-1"></i> Manage Students</a>
    <a href="<?= APP_URL ?>/registrar/announcements.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-megaphone-fill me-1"></i> Send Announcement</a>
    <a href="<?= APP_URL ?>/admin/suggestions.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-chat-left-text-fill me-1"></i> Suggestion Box</a>
    <a href="<?= APP_URL ?>/announcements/board.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-megaphone me-1"></i> Announcement Board</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="semas-card p-3">
      <h6 class="display-font mb-3">Students by Department</h6>
      <?php if (!$byDept): ?><p class="text-muted small mb-0">No departments found.</p>
      <?php else: ?>
        <table class="table table-sm align-middle">
          <thead><tr><th>Department</th><th>Code</th><th>Students</th></tr></thead>
          <tbody>
          <?php foreach ($byDept as $row): ?>
            <tr>
              <td><?= e($row['department_name']) ?></td>
              <td><span class="badge bg-light text-dark border"><?= e($row['department_code']) ?></span></td>
              <td><strong><?= (int) $row['cnt'] ?></strong></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="semas-card p-3">
      <h6 class="display-font mb-3">Students by Intake</h6>
      <?php if (!$byIntake): ?><p class="text-muted small mb-0">No intake data yet.</p>
      <?php else: ?>
        <table class="table table-sm">
          <thead><tr><th>Intake</th><th>Count</th></tr></thead>
          <tbody>
          <?php foreach ($byIntake as $row): ?>
            <tr><td><?= e($row['intake']) ?></td><td><strong><?= (int) $row['cnt'] ?></strong></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="semas-card p-3">
  <h6 class="display-font mb-3">Recently Registered Students</h6>
  <?php if (!$recentStudents): ?>
    <p class="text-muted small mb-0">No students registered yet. <a href="<?= APP_URL ?>/registrar/students.php">Add students</a>.</p>
  <?php else: ?>
    <table class="table table-sm align-middle">
      <thead><tr><th>Name</th><th>Reg No.</th><th>Department</th><th>Status</th><th>Registered</th></tr></thead>
      <tbody>
      <?php foreach ($recentStudents as $s): ?>
        <tr>
          <td><?= e($s['full_name']) ?></td>
          <td><code><?= e($s['reg_number'] ?? '—') ?></code></td>
          <td><?= e($s['department_name'] ?? '—') ?></td>
          <td><span class="badge <?= $s['status'] === 'Active' ? 'badge-completed' : ($s['status'] === 'Pending' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= e($s['status']) ?></span></td>
          <td><?= e(date('d M Y', strtotime($s['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
