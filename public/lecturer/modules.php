<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();
Module::autoCompleteExpired();

$pageTitle = 'My Modules';
$activeNav = 'modules';
$db = Database::connection();
$me = Auth::user();

$lecStmt = $db->prepare('SELECT * FROM lecturers WHERE user_id = :uid');
$lecStmt->execute(['uid' => $me['user_id']]);
$lecturer = $lecStmt->fetch();

if (!$lecturer) {
    require __DIR__ . '/../partials/layout_top.php';
    echo '<div class="alert alert-warning small">Your lecturer profile is not set up yet. Ask the HOD or Principal to assign you a department.</div>';
    require __DIR__ . '/../partials/layout_bottom.php';
    exit;
}

$modules = $db->prepare(
    "SELECT m.*, d.department_name,
        (SELECT COUNT(*) FROM module_enrollments e WHERE e.module_id = m.module_id) AS student_count
     FROM modules m LEFT JOIN departments d ON d.department_id = m.department_id
     WHERE m.lecturer_id = :lec ORDER BY m.created_at DESC"
);
$modules->execute(['lec' => $lecturer['lecturer_id']]);
$modules = $modules->fetchAll();
$ongoing   = array_values(array_filter($modules, function ($m) { return $m['status'] === 'Ongoing'; }));

// Today's CAT/Exam schedules where this lecturer is invigilator / keyed by module_id.
$todaySchedules = [];
$todayStmt = $db->prepare(
    "SELECT cs.schedule_id, cs.module_id, cs.exam_type, cs.start_time, cs.end_time, cs.room
     FROM cat_exam_schedules cs
     WHERE cs.invigilator_id = :lid AND cs.scheduled_date = CURDATE()"
);
$todayStmt->execute(['lid' => $lecturer['lecturer_id']]);
foreach ($todayStmt->fetchAll() as $ts) {
    $todaySchedules[(int) $ts['module_id']] = $ts;
}

require __DIR__ . '/../partials/layout_top.php';

function module_card(array $m, ?array $todaySchedule = null): void {
    $timeLabel = '';
    if ($todaySchedule && $todaySchedule['start_time']) {
        $timeLabel = date('h:i A', strtotime($todaySchedule['start_time']))
            . '/' . date('h:i A', strtotime($todaySchedule['end_time']));
    }
?>
  <div class="col-md-4">
    <div class="semas-card p-3 h-100 <?= $todaySchedule ? 'border border-warning' : '' ?>">
      <div class="d-flex justify-content-between align-items-start mb-1">
        <h6 class="display-font mb-0"><?= e($m['module_title']) ?></h6>
        <div class="d-flex gap-1 flex-wrap">
          <?php if ($todaySchedule): ?>
            <span class="badge badge-urgent"><i class="bi bi-alarm me-1"></i><?= e($todaySchedule['exam_type']) ?> Today</span>
          <?php endif; ?>
          <span class="badge <?= $m['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?>"><?= e($m['status']) ?></span>
        </div>
      </div>
      <p class="text-muted small mb-2">
        <?= e($m['department_name'] ?? '/') ?> &middot; <?= e($m['session_type'] ?? 'Any session') ?><br>
        <?= (int) $m['student_count'] ?> student(s) registered<?= $m['room'] ? ' &middot; Room ' . e($m['room']) : '' ?>
      </p>
      <?php if ($m['cat_date'] || $m['exam_date']): ?>
        <p class="text-muted small mb-2">
          <?php if ($m['cat_date']): ?>CAT: <?= e($m['cat_date']) ?><br><?php endif; ?>
          <?php if ($m['exam_date']): ?>Exam: <?= e($m['exam_date']) ?><?php endif; ?>
        </p>
      <?php endif; ?>
      <?php if ($todaySchedule): ?>
        <div class="alert alert-warning py-1 px-2 small mb-2">
          <i class="bi bi-clock-fill me-1"></i>
          <strong>Invigilating now:</strong> <?= e($todaySchedule['exam_type']) ?>,
          Room <?= e($todaySchedule['room']) ?><?= $timeLabel ? ', ' . e($timeLabel) : '' ?>
        </div>
      <?php endif; ?>
      <div class="d-flex flex-wrap gap-1">
        <?php if ($todaySchedule): ?>
          <a href="<?= APP_URL ?>/lecturer/cat-exam-attendance.php?schedule_id=<?= (int) $todaySchedule['schedule_id'] ?>"
             class="btn btn-sm btn-semas-gold fw-semibold">
            <i class="bi bi-pencil-square me-1"></i><?= e($todaySchedule['exam_type']) ?> Attendance
          </a>
        <?php endif; ?>
        <?php if ($m['status'] === 'Ongoing'): ?>
          <a href="<?= APP_URL ?>/lecturer/live-session.php?module_id=<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-semas"><i class="bi bi-camera-fill me-1"></i>Manage Attendance</a>
          <a href="<?= APP_URL ?>/lecturer/assignments.php?module_id=<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-file-earmark-plus me-1"></i>Send Assignment</a>
          <a href="<?= APP_URL ?>/lecturer/announcements.php?module_id=<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-megaphone me-1"></i>Announce</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/lecturer/live-session.php?module_id=<?= (int) $m['module_id'] ?>&tab=history" class="btn btn-sm btn-outline-dark"><i class="bi bi-clock-history me-1"></i>View Attendance</a>
        <a href="<?= APP_URL ?>/lecturer/live-session.php?module_id=<?= (int) $m['module_id'] ?>&export=csv" class="btn btn-sm btn-outline-dark"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
        <a href="<?= APP_URL ?>/lecturer/attendance-pdf.php?module_id=<?= (int) $m['module_id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
      </div>
    </div>
  </div>
<?php
}
?>
<h4 class="display-font mb-1">My Modules</h4>

<h6 class="display-font mb-2">Ongoing Modules</h6>
<div class="row g-3 mb-4">
  <?php foreach ($ongoing as $m): module_card($m, $todaySchedules[(int) $m['module_id']] ?? null); endforeach; ?>
  <?php if (!$ongoing): ?>
    <div class="col-12"><div class="semas-card p-4 text-center text-muted small">No ongoing modules assigned to you yet.</div></div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
