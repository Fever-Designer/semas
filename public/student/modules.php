<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);
Module::autoCompleteExpired();

$pageTitle = 'Module Registration';
$activeNav = 'modules';
$db = Database::connection();
$me = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'register') {
        $moduleId = (int) $_POST['module_id'];
        $modStmt = $db->prepare("SELECT * FROM modules WHERE module_id = :id AND status = 'Ongoing'");
        $modStmt->execute(['id' => $moduleId]);
        $module = $modStmt->fetch();
        if (!$module) {
            flash('error', 'Module not available for registration.');
        } else {
            // Block registration if student already has an ongoing module in the same session type
            $conflict = false;
            if ($module['session_type']) {
                $chk = $db->prepare(
                    "SELECT m.module_title FROM modules m
                     JOIN module_enrollments e ON e.module_id = m.module_id AND e.user_id = :uid
                     WHERE m.status = 'Ongoing' AND m.session_type = :stype AND m.module_id != :mid
                     LIMIT 1"
                );
                $chk->execute(['uid' => $me['user_id'], 'stype' => $module['session_type'], 'mid' => $moduleId]);
                $existingTitle = $chk->fetchColumn();
                if ($existingTitle) {
                    $conflict = true;
                    flash('error', 'You are already registered for "' . $existingTitle . '" which runs in the same ' . $module['session_type'] . ' session. You can only register one module per session type. Contact your HOD if you need an exception.');
                }
            }
            if (!$conflict) {
                try {
                    $db->prepare('INSERT INTO module_enrollments (module_id, user_id) VALUES (:m, :u)')
                       ->execute(['m' => $moduleId, 'u' => $me['user_id']]);
                    AuditLog::record(Auth::id(), 'MODULE_REGISTER', 'modules', $moduleId);
                    flash('success', 'You are now registered for "' . $module['module_title'] . '".');
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        flash('error', 'You are already registered for this module.');
                    } else {
                        throw $e;
                    }
                }
            }
        }
    }
    redirect('/student/modules.php?tab=' . ($_GET['tab'] ?? 'browse'));
}

$tab = $_GET['tab'] ?? 'browse';

// Browse: Ongoing modules not yet registered, grouped by department.
$browseStmt = $db->query(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name
     FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE m.status = 'Ongoing' ORDER BY d.department_name, m.module_title"
);
$browseModules = $browseStmt->fetchAll();
$myEnrolledIds = $db->prepare('SELECT module_id FROM module_enrollments WHERE user_id = :uid');
$myEnrolledIds->execute(['uid' => $me['user_id']]);
$myEnrolledIds = array_map('intval', $myEnrolledIds->fetchAll(PDO::FETCH_COLUMN));

$grouped = [];
foreach ($browseModules as $m) {
    $grouped[$m['department_name'] ?? 'Unassigned'][] = $m;
}

// My Modules: enrolled + Ongoing.
$mineStmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name
     FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id AND e.user_id = :uid
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE m.status = 'Ongoing' ORDER BY m.module_title"
);
$mineStmt->execute(['uid' => $me['user_id']]);
$myOngoing = $mineStmt->fetchAll();

// Completed + enrolled — eligible for CAT/Exam slip.
$completedStmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name
     FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id AND e.user_id = :uid
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE m.status = 'Completed' ORDER BY m.module_title"
);
$completedStmt->execute(['uid' => $me['user_id']]);
$myCompleted = $completedStmt->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Module Registration</h4>
<p class="text-muted small mb-3">Browse available modules, register, and access attendance, assignments, and announcements once registered.</p>

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'browse' ? 'active' : '' ?>" href="?tab=browse">Browse &amp; Register</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'mine' ? 'active' : '' ?>" href="?tab=mine">My Modules (<?= count($myOngoing) ?>)</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'completed' ? 'active' : '' ?>" href="?tab=completed">Completed (<?= count($myCompleted) ?>)</a></li>
</ul>

<?php if ($tab === 'browse'): ?>
  <?php foreach ($grouped as $deptName => $mods): ?>
    <h6 class="display-font mb-2"><?= e($deptName) ?></h6>
    <div class="row g-3 mb-4">
      <?php foreach ($mods as $m): $isEnrolled = in_array((int) $m['module_id'], $myEnrolledIds, true); ?>
        <div class="col-md-4">
          <div class="semas-card p-3 h-100">
            <h6 class="fw-semibold mb-1"><?= e($m['module_title']) ?></h6>
            <p class="text-muted small mb-2">Lecturer: <?= e($m['lecturer_name'] ?? 'TBA') ?><br><?= e($m['session_type'] ?? 'Any session') ?><?= $m['room'] ? ' &middot; Room ' . e($m['room']) : '' ?></p>
            <?php if ($isEnrolled): ?>
              <span class="badge badge-completed">Registered</span>
            <?php else: ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="register"><input type="hidden" name="module_id" value="<?= (int) $m['module_id'] ?>">
                <button class="btn btn-sm btn-semas-gold">Register</button></form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$grouped): ?><div class="semas-card p-4 text-center text-muted small">No modules are currently open for registration.</div><?php endif; ?>

<?php elseif ($tab === 'mine'): ?>
  <div class="row g-3">
    <?php foreach ($myOngoing as $m): ?>
      <div class="col-md-4">
        <div class="semas-card p-3 h-100">
          <h6 class="fw-semibold mb-1"><?= e($m['module_title']) ?></h6>
          <p class="text-muted small mb-2">Lecturer: <?= e($m['lecturer_name'] ?? 'TBA') ?><br><?= e($m['department_name'] ?? '') ?></p>
          <div class="d-flex flex-wrap gap-1">
            <a href="<?= APP_URL ?>/student/attendance.php#hist-<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-semas">Attendance</a>
            <a href="<?= APP_URL ?>/student/assignments.php?module_id=<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-outline-dark">Assignments</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$myOngoing): ?><div class="col-12"><div class="semas-card p-4 text-center text-muted small">You're not registered for any ongoing module yet. <a href="?tab=browse">Browse modules</a>.</div></div><?php endif; ?>
  </div>

<?php else: /* completed */ ?>
  <div class="row g-3">
    <?php foreach ($myCompleted as $m): ?>
      <div class="col-md-4">
        <div class="semas-card p-3 h-100">
          <h6 class="fw-semibold mb-1"><?= e($m['module_title']) ?></h6>
          <p class="text-muted small mb-2">Lecturer: <?= e($m['lecturer_name'] ?? 'TBA') ?><br><?= e($m['department_name'] ?? '') ?></p>
          <a href="<?= APP_URL ?>/student/cat-exam-slips.php?module_id=<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-semas-gold"><i class="bi bi-printer me-1"></i> CAT / Exam Slips</a>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$myCompleted): ?><div class="col-12"><div class="semas-card p-4 text-center text-muted small">No completed modules yet.</div></div><?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
