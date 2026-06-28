<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Coordinator']);
Module::autoCompleteExpired();

$pageTitle = 'Coordinator Dashboard';
$activeNav = 'dashboard';
$db = Database::connection();
$user = Auth::user();

$stats = [
    'weekend_modules'    => (int) $db->query("SELECT COUNT(*) FROM modules WHERE session_type='Weekend'")->fetchColumn(),
    'ongoing_weekend'    => (int) $db->query("SELECT COUNT(*) FROM modules WHERE session_type='Weekend' AND status='Ongoing'")->fetchColumn(),
    'completed_weekend'  => (int) $db->query("SELECT COUNT(*) FROM modules WHERE session_type='Weekend' AND status='Completed'")->fetchColumn(),
    'weekend_students'   => (int) $db->query(
        "SELECT COUNT(DISTINCT me.user_id) FROM module_enrollments me
         JOIN modules m ON m.module_id=me.module_id WHERE m.session_type='Weekend'")->fetchColumn(),
];

$recentModules = $db->query(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name
     FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE m.session_type = 'Weekend'
     ORDER BY m.created_at DESC LIMIT 8"
)->fetchAll();

$pendingEligibility = (int) $db->query(
    "SELECT COUNT(*) FROM cat_exam_eligibility ce
     JOIN modules m ON m.module_id=ce.module_id
     WHERE ce.hod_decision='Pending' AND m.session_type='Weekend'"
)->fetchColumn();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Coordinator Dashboard</h4>
<p class="text-muted small mb-3">You manage Weekend session modules, attendance, and academic operations for Weekend students.</p>

<div class="row g-3 mb-4">
  <div class="col-md-6 col-lg-3">
    <div class="stat-card"><i class="bi bi-journal-bookmark-fill stat-icon"></i>
      <div class="stat-label">Weekend Modules</div><div class="stat-value"><?= $stats['weekend_modules'] ?></div>
    </div>
  </div>
  <div class="col-md-6 col-lg-3">
    <div class="stat-card"><i class="bi bi-play-circle-fill stat-icon"></i>
      <div class="stat-label">Ongoing</div><div class="stat-value"><?= $stats['ongoing_weekend'] ?></div>
    </div>
  </div>
  <div class="col-md-6 col-lg-3">
    <div class="stat-card"><i class="bi bi-check-circle-fill stat-icon"></i>
      <div class="stat-label">Completed</div><div class="stat-value"><?= $stats['completed_weekend'] ?></div>
    </div>
  </div>
  <div class="col-md-6 col-lg-3">
    <div class="stat-card"><i class="bi bi-mortarboard-fill stat-icon"></i>
      <div class="stat-label">Weekend Students</div><div class="stat-value"><?= $stats['weekend_students'] ?></div>
    </div>
  </div>
</div>

<?php if ($pendingEligibility > 0): ?>
  <div class="alert alert-warning small d-flex justify-content-between align-items-center">
    <span><i class="bi bi-exclamation-triangle-fill me-1"></i> <strong><?= $pendingEligibility ?></strong> CAT/Exam eligibility decision(s) awaiting your review.</span>
    <a href="<?= APP_URL ?>/coordinator/eligibility.php" class="btn btn-sm btn-semas-gold">Review</a>
  </div>
<?php endif; ?>

<div class="semas-card p-3 mb-4">
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/coordinator/modules.php" class="btn btn-semas-gold btn-sm"><i class="bi bi-journal-plus me-1"></i> Manage Weekend Modules</a>
    <a href="<?= APP_URL ?>/coordinator/announcements.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-megaphone me-1"></i> Announcements</a>
    <a href="<?= APP_URL ?>/admin/suggestions.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-chat-left-text-fill me-1"></i> Suggestion Box</a>
    <a href="<?= APP_URL ?>/campus/lost-found.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-search-heart me-1"></i> Lost &amp; Found</a>
  </div>
</div>

<div class="semas-card p-3">
  <h6 class="display-font mb-3">Recent Weekend Modules</h6>
  <?php if (!$recentModules): ?>
    <p class="text-muted small mb-0">No Weekend modules yet. <a href="<?= APP_URL ?>/coordinator/modules.php">Create one</a>.</p>
  <?php else: ?>
    <table class="table table-sm align-middle">
      <thead><tr><th>Module</th><th>Department</th><th>Lecturer</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentModules as $m): ?>
        <tr>
          <td><?= e($m['module_title']) ?></td>
          <td><?= e($m['department_name'] ?? '—') ?></td>
          <td><?= e($m['lecturer_name'] ?? '—') ?></td>
          <td><span class="badge <?= $m['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?>"><?= e($m['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
