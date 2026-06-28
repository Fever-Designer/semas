<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);
Module::autoCompleteExpired();

$pageTitle = 'CAT / Exam Slips';
$activeNav = 'modules';
$db = Database::connection();
$me = Auth::user();

$modulesStmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE e.user_id = :uid AND (m.cat_date IS NOT NULL OR m.exam_date IS NOT NULL)
     ORDER BY m.module_title"
);
$modulesStmt->execute(['uid' => $me['user_id']]);
$modules = $modulesStmt->fetchAll();

function eligibility_badge(?array $row): string
{
    if (!$row) return '<span class="badge bg-secondary">Not generated yet</span>';
    if ($row['hod_decision'] === 'Pending') return '<span class="badge badge-urgent">Pending HOD review</span>';
    return $row['final_decision'] === 'Allowed'
        ? '<span class="badge badge-completed">Allowed</span>'
        : '<span class="badge badge-cancelled">Not Allowed</span>';
}

/** Returns ['signed_in'=>bool, 'signed_out'=>bool] for a CAT/Exam schedule, or null if no schedule. */
function cat_attendance_status(PDO $db, int $moduleId, int $userId, string $examType): ?array
{
    $sched = $db->prepare(
        "SELECT cs.schedule_id FROM cat_exam_schedules cs WHERE cs.module_id = :mid AND cs.exam_type = :type LIMIT 1"
    );
    $sched->execute(['mid' => $moduleId, 'type' => $examType]);
    $row = $sched->fetch();
    if (!$row) return null;

    $sid = (int) $row['schedule_id'];
    $sin = $db->prepare("SELECT schedule_id FROM cat_exam_attendance_logs WHERE schedule_id = :s AND user_id = :u AND attendance_type = 'Sign In'");
    $sin->execute(['s' => $sid, 'u' => $userId]);
    $sout = $db->prepare("SELECT schedule_id FROM cat_exam_attendance_logs WHERE schedule_id = :s AND user_id = :u AND attendance_type = 'Sign Out'");
    $sout->execute(['s' => $sid, 'u' => $userId]);
    return ['schedule_id' => $sid, 'signed_in' => (bool) $sin->fetch(), 'signed_out' => (bool) $sout->fetch()];
}

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">CAT / Exam Slips</h4>
<p class="text-muted small mb-4">
  Your eligibility is generated and reviewed by the HOD based on your attendance record.
  An <strong>Entry Slip</strong> can be printed once your status shows <strong>Allowed</strong>.
  An <strong>Evidence Slip</strong> is available after the invigilator records both your Sign In and Sign Out.
</p>

<?php foreach ($modules as $m): ?>
  <?php
    $cat  = Eligibility::statusFor((int) $m['module_id'], $me['user_id'], 'CAT');
    $exam = Eligibility::statusFor((int) $m['module_id'], $me['user_id'], 'Exam');
    $catAtt  = cat_attendance_status($db, (int) $m['module_id'], $me['user_id'], 'CAT');
    $examAtt = cat_attendance_status($db, (int) $m['module_id'], $me['user_id'], 'Exam');
  ?>
  <div class="semas-card p-3 mb-3">
    <h6 class="display-font mb-1"><?= e($m['module_title']) ?></h6>
    <p class="text-muted small mb-2"><?= e($m['department_name'] ?? '') ?> &middot; Lecturer: <?= e($m['lecturer_name'] ?? 'TBA') ?></p>

    <div class="row g-3">
      <!-- CAT -->
      <div class="col-md-6">
        <div class="border rounded p-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <strong class="small">CAT</strong>
            <?= eligibility_badge($cat) ?>
          </div>
          <p class="text-muted small mb-2">Date: <?= e($m['cat_date'] ?? 'Not scheduled') ?></p>
          <div class="d-flex flex-wrap gap-2">
            <?php if ($cat && $cat['hod_decision'] !== 'Pending' && $cat['final_decision'] === 'Allowed'): ?>
              <a href="<?= APP_URL ?>/student/slip-print.php?module_id=<?= (int) $m['module_id'] ?>&type=CAT"
                 target="_blank" class="btn btn-sm btn-semas-gold">
                <i class="bi bi-printer me-1"></i> Entry Slip
              </a>
            <?php endif; ?>
            <?php if ($catAtt && $catAtt['signed_in'] && $catAtt['signed_out']): ?>
              <a href="<?= APP_URL ?>/student/evidence-slip.php?schedule_id=<?= (int) $catAtt['schedule_id'] ?>"
                 target="_blank" class="btn btn-sm btn-semas">
                <i class="bi bi-award me-1"></i> Evidence Slip
              </a>
            <?php elseif ($catAtt && $catAtt['signed_in'] && !$catAtt['signed_out']): ?>
              <span class="badge badge-urgent small align-self-center">Evidence slip available after sign-out</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Exam -->
      <div class="col-md-6">
        <div class="border rounded p-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <strong class="small">Exam</strong>
            <?= eligibility_badge($exam) ?>
          </div>
          <p class="text-muted small mb-2">Date: <?= e($m['exam_date'] ?? 'Not scheduled') ?></p>
          <div class="d-flex flex-wrap gap-2">
            <?php if ($exam && $exam['hod_decision'] !== 'Pending' && $exam['final_decision'] === 'Allowed'): ?>
              <a href="<?= APP_URL ?>/student/slip-print.php?module_id=<?= (int) $m['module_id'] ?>&type=Exam"
                 target="_blank" class="btn btn-sm btn-semas-gold">
                <i class="bi bi-printer me-1"></i> Entry Slip
              </a>
            <?php endif; ?>
            <?php if ($examAtt && $examAtt['signed_in'] && $examAtt['signed_out']): ?>
              <a href="<?= APP_URL ?>/student/evidence-slip.php?schedule_id=<?= (int) $examAtt['schedule_id'] ?>"
                 target="_blank" class="btn btn-sm btn-semas">
                <i class="bi bi-award me-1"></i> Evidence Slip
              </a>
            <?php elseif ($examAtt && $examAtt['signed_in'] && !$examAtt['signed_out']): ?>
              <span class="badge badge-urgent small align-self-center">Evidence slip available after sign-out</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php if (!$modules): ?>
  <div class="semas-card p-4 text-center text-muted small">No CAT/Exam dates have been scheduled for your registered modules yet.</div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
