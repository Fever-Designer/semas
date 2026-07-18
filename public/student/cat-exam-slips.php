<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);
Module::autoCompleteExpired();

$pageTitle = 'CAT / Exam Slips';
$activeNav = 'modules';
$db = Database::connection();
$me = Auth::user();

$baseSelect = "SELECT m.*, d.department_name, u.full_name AS lecturer_name
     FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE e.user_id = :uid AND (m.cat_date IS NOT NULL OR m.exam_date IS NOT NULL)";

$ongoingStmt = $db->prepare("$baseSelect AND m.status <> 'Completed' ORDER BY m.module_title");
$ongoingStmt->execute(['uid' => $me['user_id']]);
$ongoingModules = $ongoingStmt->fetchAll();

$completedStmt = $db->prepare("$baseSelect AND m.status = 'Completed' ORDER BY m.module_title");
$completedStmt->execute(['uid' => $me['user_id']]);
$completedModules = $completedStmt->fetchAll();

$pendingStmt = $db->prepare(
    "SELECT COUNT(*) FROM cat_exam_eligibility ce
     JOIN module_enrollments e ON e.module_id = ce.module_id AND e.user_id = ce.user_id
     WHERE ce.user_id = :uid AND ce.hod_decision = 'Pending'"
);
$pendingStmt->execute(['uid' => $me['user_id']]);
$pendingCount = (int) $pendingStmt->fetchColumn();

function eligibility_badge(?array $row): string
{
    if (!$row) return '<span class="badge bg-secondary">Not generated yet</span>';
    if ($row['hod_decision'] === 'Pending') return '<span class="badge badge-urgent">Pending Head Of Department review</span>';
    if (!empty($row['requires_review'])) return '<span class="badge badge-urgent">Requires approval</span>';
    return $row['final_decision'] === 'Allowed'
        ? '<span class="badge badge-completed">Allowed</span>'
        : '<span class="badge badge-cancelled">Not Allowed</span>';
}

function eligibility_summary(?array $row): string
{
    if (!$row) return '';
    $pct = isset($row['attendance_percent']) ? number_format((float) $row['attendance_percent'], 1) . '%' : '';
    $classes = (int) ($row['total_sessions'] ?? 0);
    return $pct ? '<div class="text-muted small mt-1">Attendance: <strong>' . e($pct) . '</strong> / Classes: ' . $classes . '</div>' : '';
}

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

<!-- Summary cards -->
<div class="row g-3 mb-4">
  <div class="col-4">
    <div class="semas-card p-3 text-center">
      <div class="display-font fs-4"><?= count($ongoingModules) ?></div>
      <div class="text-muted small">Ongoing Modules</div>
    </div>
  </div>
  <div class="col-4">
    <div class="semas-card p-3 text-center">
      <div class="display-font fs-4"><?= count($completedModules) ?></div>
      <div class="text-muted small">Completed Modules</div>
    </div>
  </div>
  <div class="col-4">
    <div class="semas-card p-3 text-center">
      <div class="display-font fs-4 <?= $pendingCount ? 'text-warning' : '' ?>"><?= $pendingCount ?></div>
      <div class="text-muted small">Pending Eligibility</div>
    </div>
  </div>
</div>

<!-- ── ONGOING MODULES ────────────────────────────────────────────────── -->
<h6 class="display-font text-uppercase text-muted small mb-2">Ongoing Modules</h6>

<?php if (!$ongoingModules): ?>
  <div class="semas-card p-4 text-center text-muted small mb-4">No ongoing modules with scheduled CAT/Exam dates.</div>
<?php endif; ?>

<?php foreach ($ongoingModules as $m): ?>
  <?php
    $cat     = $m['cat_date']  ? Eligibility::statusFor((int) $m['module_id'], $me['user_id'], 'CAT')  : null;
    $exam    = $m['exam_date'] ? Eligibility::statusFor((int) $m['module_id'], $me['user_id'], 'Exam') : null;
    $catAtt  = $m['cat_date']  ? cat_attendance_status($db, (int) $m['module_id'], $me['user_id'], 'CAT')  : null;
    $examAtt = $m['exam_date'] ? cat_attendance_status($db, (int) $m['module_id'], $me['user_id'], 'Exam') : null;
  ?>
  <div class="semas-card p-3 mb-3">
    <h6 class="display-font mb-1"><?= e($m['module_title']) ?></h6>
    <p class="text-muted small mb-2"><?= e($m['department_name'] ?? '') ?> &middot; Lecturer: <?= e($m['lecturer_name'] ?? 'TBA') ?></p>

    <div class="row g-3">
      <?php if ($m['cat_date']): ?>
      <div class="col-md-6">
        <div class="border rounded p-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <strong class="small">CAT</strong>
            <?= eligibility_badge($cat) ?>
          </div>
          <p class="text-muted small mb-2">Date: <?= e($m['cat_date']) ?></p>
          <?= eligibility_summary($cat) ?>
          <div class="d-flex flex-wrap gap-2">
            <?php if ($cat && $cat['hod_decision'] !== 'Pending' && $cat['final_decision'] === 'Allowed' && (!$catAtt || !$catAtt['signed_out'])): ?>
              <a href="<?= APP_URL ?>/student/slip-print.php?module_id=<?= (int) $m['module_id'] ?>&type=CAT"
                 target="_blank" class="btn btn-sm btn-semas-gold">
                <i class="bi bi-printer me-1"></i> Entry Slip
              </a>
            <?php endif; ?>
            <?php if ($catAtt && $catAtt['signed_in'] && $catAtt['signed_out']): ?>
              <a href="<?= APP_URL ?>/student/evidence-slip.php?schedule_id=<?= (int) $catAtt['schedule_id'] ?>"
                 target="_blank" class="btn btn-sm btn-semas">
                <i class="bi bi-award me-1"></i> Attendance Slip
              </a>
            <?php elseif ($catAtt && $catAtt['signed_in'] && !$catAtt['signed_out']): ?>
              <span class="badge badge-urgent small align-self-center">Attendance slip available after sign-out</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($m['exam_date']): ?>
      <div class="col-md-6">
        <div class="border rounded p-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <strong class="small">Exam</strong>
            <?= eligibility_badge($exam) ?>
          </div>
          <p class="text-muted small mb-2">Date: <?= e($m['exam_date']) ?></p>
          <?= eligibility_summary($exam) ?>
          <div class="d-flex flex-wrap gap-2">
            <?php if ($exam && $exam['hod_decision'] !== 'Pending' && $exam['final_decision'] === 'Allowed' && (!$examAtt || !$examAtt['signed_out'])): ?>
              <a href="<?= APP_URL ?>/student/slip-print.php?module_id=<?= (int) $m['module_id'] ?>&type=Exam"
                 target="_blank" class="btn btn-sm btn-semas-gold">
                <i class="bi bi-printer me-1"></i> Entry Slip
              </a>
            <?php endif; ?>
            <?php if ($examAtt && $examAtt['signed_in'] && $examAtt['signed_out']): ?>
              <a href="<?= APP_URL ?>/student/evidence-slip.php?schedule_id=<?= (int) $examAtt['schedule_id'] ?>"
                 target="_blank" class="btn btn-sm btn-semas">
                <i class="bi bi-award me-1"></i> Attendance Slip
              </a>
            <?php elseif ($examAtt && $examAtt['signed_in'] && !$examAtt['signed_out']): ?>
              <span class="badge badge-urgent small align-self-center">Attendance slip available after sign-out</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<!-- ── COMPLETED MODULES ──────────────────────────────────────────────── -->
<?php if ($completedModules): ?>
<h6 class="display-font text-uppercase text-muted small mb-2 mt-4">Completed Modules</h6>
<?php foreach ($completedModules as $m): ?>
  <?php
    $catAtt  = cat_attendance_status($db, (int) $m['module_id'], $me['user_id'], 'CAT');
    $examAtt = cat_attendance_status($db, (int) $m['module_id'], $me['user_id'], 'Exam');
    $hasSlips = $catAtt && $catAtt['signed_out'] || $examAtt && $examAtt['signed_out'];
  ?>
  <div class="semas-card p-3 mb-3">
    <div class="d-flex align-items-center gap-2 mb-1">
      <i class="bi bi-check-circle-fill text-success small"></i>
      <h6 class="display-font mb-0"><?= e($m['module_title']) ?></h6>
      <span class="badge badge-completed ms-1">Completed</span>
    </div>
    <p class="text-muted small mb-2"><?= e($m['department_name'] ?? '') ?> &middot; Lecturer: <?= e($m['lecturer_name'] ?? 'TBA') ?></p>
    <?php if ($hasSlips): ?>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($catAtt && $catAtt['signed_out']): ?>
          <a href="<?= APP_URL ?>/student/evidence-slip.php?schedule_id=<?= (int) $catAtt['schedule_id'] ?>"
             target="_blank" class="btn btn-sm btn-semas">
            <i class="bi bi-award me-1"></i> CAT Attendance Slip
          </a>
        <?php endif; ?>
        <?php if ($examAtt && $examAtt['signed_out']): ?>
          <a href="<?= APP_URL ?>/student/evidence-slip.php?schedule_id=<?= (int) $examAtt['schedule_id'] ?>"
             target="_blank" class="btn btn-sm btn-semas">
            <i class="bi bi-award me-1"></i> Exam Attendance Slip
          </a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <p class="text-muted small mb-0">No attendance slips available.</p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
