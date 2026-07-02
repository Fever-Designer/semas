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

        // Verify module is accessible to this student (department + intake + session match)
        $studentDeptId     = (int) ($me['department_id'] ?? 0);
        $studentIntake     = $me['intake'] ?? null;
        $studentSessionType = trim($me['session_type'] ?? '');

        $modStmt = $db->prepare(
            "SELECT m.* FROM modules m
             WHERE m.module_id = :id AND m.status = 'Ongoing'
               AND (
                 m.department_id = :dept
                 OR EXISTS (SELECT 1 FROM module_departments md WHERE md.module_id = m.module_id AND md.department_id = :dept2)
               )
               AND (
                 NOT EXISTS (SELECT 1 FROM module_intakes mi WHERE mi.module_id = m.module_id)
                 OR EXISTS (SELECT 1 FROM module_intakes mi WHERE mi.module_id = m.module_id AND mi.intake = :intake)
               )
               AND (:session = '' OR m.session_type = :session2)"
        );
        $modStmt->execute([
            'id'      => $moduleId,
            'dept'    => $studentDeptId,
            'dept2'   => $studentDeptId,
            'intake'  => $studentIntake,
            'session' => $studentSessionType,
            'session2' => $studentSessionType,
        ]);
        $module = $modStmt->fetch();

        if (!$module) {
            flash('error', 'Module not available for registration.');
        } else {
            $completedSameTitle = $db->prepare(
                "SELECT 1 FROM modules cm
                 JOIN module_enrollments ce ON ce.module_id = cm.module_id AND ce.user_id = :uid
                 WHERE cm.status = 'Completed' AND cm.module_title = :title
                 LIMIT 1"
            );
            $completedSameTitle->execute(['uid' => $me['user_id'], 'title' => $module['module_title']]);
            if ($completedSameTitle->fetchColumn()) {
                flash('error', 'You already completed "' . $module['module_title'] . '". Contact your HoD if this must be registered as a retake or special case.');
                redirect('/student/modules?tab=' . ($_GET['tab'] ?? 'browse'));
            }
            if (!Module::canAddOngoingEnrollment((int) $me['user_id'], $moduleId)) {
                flash('error', 'You already have ' . Module::MAX_ONGOING_ENROLLMENTS . ' ongoing modules. Complete one module before registering for another.');
                redirect('/student/modules?tab=' . ($_GET['tab'] ?? 'browse'));
            }

            // Block if already has an ongoing module in the same session slot.
            $conflict = false;
            $sessionConflict = Module::studentOngoingSessionConflict($db, (int) $me['user_id'], $moduleId);
            if ($sessionConflict) {
                $conflict = true;
                flash('error', 'You are already registered for "' . $sessionConflict['module_title'] . '" in the same ' . Module::sessionLabel($sessionConflict) . ' session. Contact your HoD if you need an exception.');
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
    redirect('/student/modules?tab=' . ($_GET['tab'] ?? 'browse'));
}

$tab = $_GET['tab'] ?? 'browse';

// Browse: Ongoing modules scoped to student's department + intake.
$studentDeptId      = (int) ($me['department_id'] ?? 0);
$studentIntake      = $me['intake'] ?? null;
$studentSessionType = trim($me['session_type'] ?? '');

$browseParams = [
    'dept'    => $studentDeptId,
    'dept2'   => $studentDeptId,
    'intake'  => $studentIntake,
    'session' => $studentSessionType,
];

$browseStmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name
     FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE m.status = 'Ongoing'
       AND (
         m.department_id = :dept
         OR EXISTS (SELECT 1 FROM module_departments md WHERE md.module_id = m.module_id AND md.department_id = :dept2)
       )
       AND (
         NOT EXISTS (SELECT 1 FROM module_intakes mi WHERE mi.module_id = m.module_id)
         OR EXISTS (SELECT 1 FROM module_intakes mi WHERE mi.module_id = m.module_id AND mi.intake = :intake)
       )
       AND (:session = '' OR m.session_type = :session2)
     ORDER BY d.department_name, m.module_title"
);
$browseParams['session2'] = $browseParams['session'];
$browseStmt->execute($browseParams);
$browseModules = $browseStmt->fetchAll();

$myEnrolledIds = $db->prepare('SELECT module_id FROM module_enrollments WHERE user_id = :uid');
$myEnrolledIds->execute(['uid' => $me['user_id']]);
$myEnrolledIds = array_map('intval', $myEnrolledIds->fetchAll(PDO::FETCH_COLUMN));

// Module titles the student has already completed (a later re-offering of the
// same title shouldn't be suggested again in Browse).
$completedTitlesStmt = $db->prepare(
    "SELECT DISTINCT m.module_title FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id AND e.user_id = :uid
     WHERE m.status = 'Completed'"
);
$completedTitlesStmt->execute(['uid' => $me['user_id']]);
$myCompletedTitles = $completedTitlesStmt->fetchAll(PDO::FETCH_COLUMN);

// Session slots the student is already enrolled in (for ongoing modules).
$enrolledSessionsStmt = $db->prepare(
    "SELECT DISTINCT
        CASE
          WHEN m.session_type = 'Weekend' THEN CONCAT('Weekend:', COALESCE(m.weekend_slot, ''))
          ELSE m.session_type
        END AS session_key
     FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id
     WHERE e.user_id = :uid AND m.status = 'Ongoing' AND m.session_type IS NOT NULL"
);
$enrolledSessionsStmt->execute(['uid' => $me['user_id']]);
$enrolledSessionKeys = $enrolledSessionsStmt->fetchAll(PDO::FETCH_COLUMN);

$grouped = [];
foreach ($browseModules as $m) {
    $alreadyEnrolled = in_array((int) $m['module_id'], $myEnrolledIds, true);
    $sessionKey = ($m['session_type'] ?? '') === 'Weekend'
        ? 'Weekend:' . (string) ($m['weekend_slot'] ?? '')
        : (string) ($m['session_type'] ?? '');
    $sessionTaken = $sessionKey !== '' && in_array($sessionKey, $enrolledSessionKeys, true) && !$alreadyEnrolled;
    if ($sessionTaken) continue; // student already has a module in this session
    if (!$alreadyEnrolled && in_array($m['module_title'], $myCompletedTitles, true)) continue; // already completed this module
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

// Completed + enrolled / eligible for CAT/Exam slip.
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
            <p class="text-muted small mb-2">
              Lecturer: <?= e($m['lecturer_name'] ?? 'TBA') ?><br>
              <?= e(Module::sessionLabel($m)) ?>
              <?= $m['room'] ? ' &middot; Room ' . e($m['room']) : '' ?>
            </p>
            <?php if ($isEnrolled): ?>
              <span class="badge badge-completed">Registered</span>
            <?php else: ?>
              <button class="btn btn-sm btn-semas-gold"
                onclick="openRegisterConfirm(<?= (int) $m['module_id'] ?>, <?= e(json_encode($m['module_title'])) ?>, <?= e(json_encode($m['lecturer_name'] ?? 'TBA')) ?>, <?= e(json_encode(Module::sessionLabel($m))) ?>)">
                Register
              </button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$grouped): ?>
    <div class="semas-card p-4 text-center text-muted small">
      No modules are currently open for registration in your department
      <?= $studentIntake ? '(' . e($studentIntake) . ' intake)' : '' ?>.
      Check back later or contact your HoD.
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'mine'): ?>
  <div class="alert alert-info small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    To de-register from a module, contact your <strong>Head of Department (HoD)</strong>. Only the HoD can remove a registration.
  </div>
  <div class="row g-3">
    <?php foreach ($myOngoing as $m): ?>
      <div class="col-md-4">
        <div class="semas-card p-3 h-100">
          <h6 class="fw-semibold mb-1"><?= e($m['module_title']) ?></h6>
          <p class="text-muted small mb-2">Lecturer: <?= e($m['lecturer_name'] ?? 'TBA') ?><br><?= e($m['department_name'] ?? '') ?></p>
          <div class="d-flex flex-wrap gap-1">
            <a href="<?= APP_URL ?>/student/attendance#hist-<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-semas">Attendance</a>
            <a href="<?= APP_URL ?>/student/assignments?module_id=<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-outline-dark">Assignments</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$myOngoing): ?>
      <div class="col-12"><div class="semas-card p-4 text-center text-muted small">You're not registered for any ongoing module yet. <a href="?tab=browse">Browse modules</a>.</div></div>
    <?php endif; ?>
  </div>

<?php else: /* completed */ ?>
  <div class="row g-3">
    <?php foreach ($myCompleted as $m): ?>
      <div class="col-md-4">
        <div class="semas-card p-3 h-100">
          <h6 class="fw-semibold mb-1"><?= e($m['module_title']) ?></h6>
          <p class="text-muted small mb-2">Lecturer: <?= e($m['lecturer_name'] ?? 'TBA') ?><br><?= e($m['department_name'] ?? '') ?></p>
          <a href="<?= APP_URL ?>/student/cat-exam-slips?module_id=<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-semas-gold"><i class="bi bi-printer me-1"></i> CAT / Exam Slips</a>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$myCompleted): ?>
      <div class="col-12"><div class="semas-card p-4 text-center text-muted small">No completed modules yet.</div></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- Registration Confirmation Modal -->
<div class="modal fade" id="registerConfirmModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title display-font"><i class="bi bi-journal-check me-1"></i> Confirm Registration</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">You are about to register for:</p>
        <div class="semas-card p-3 mb-3">
          <div class="fw-semibold" id="confirmModuleTitle"></div>
          <div class="text-muted small mt-1">Lecturer: <span id="confirmLecturer"></span></div>
          <div class="text-muted small">Session: <span id="confirmSession"></span></div>
        </div>
        <p class="small text-muted mb-0">
          <i class="bi bi-exclamation-circle me-1"></i>
          Once registered, only your HoD can cancel this registration. Make sure this is the right module.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <form method="post" id="registerConfirmForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="register">
          <input type="hidden" name="module_id" id="confirmModuleId">
          <button type="submit" class="btn btn-semas-gold btn-sm">
            <i class="bi bi-check-circle me-1"></i> Yes, Register Me
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function openRegisterConfirm(moduleId, title, lecturer, session) {
    document.getElementById('confirmModuleTitle').textContent = title;
    document.getElementById('confirmLecturer').textContent = lecturer || 'TBA';
    document.getElementById('confirmSession').textContent = session || 'Any session';
    document.getElementById('confirmModuleId').value = moduleId;
    new bootstrap.Modal(document.getElementById('registerConfirmModal')).show();
}
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
