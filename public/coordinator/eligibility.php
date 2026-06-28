<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Coordinator']);
Module::autoCompleteExpired();

$pageTitle = 'Weekend CAT / Exam Eligibility';
$activeNav = 'eligibility';
$db        = Database::connection();
$me        = Auth::user();
$today     = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action   = $_POST['action'] ?? '';
    $moduleId = (int) ($_POST['module_id'] ?? 0);

    if ($action === 'save_schedule') {
        $examType   = $_POST['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $room       = trim($_POST['room'] ?? '');
        $invigId    = (int) ($_POST['invigilator_id'] ?? 0);
        $schedDate  = trim($_POST['scheduled_date'] ?? '');
        $startTime  = trim($_POST['start_time'] ?? '');
        $endTime    = trim($_POST['end_time'] ?? '');

        $invCheck = $db->prepare('SELECT lecturer_id FROM lecturers WHERE lecturer_id = :id');
        $invCheck->execute(['id' => $invigId]);
        if (!$invCheck->fetch()) {
            flash('error', 'Invalid invigilator selected.');
        } elseif (!$room || !$schedDate || !$startTime || !$endTime) {
            flash('error', 'Room, date, start time and end time are required.');
        } elseif ($startTime >= $endTime) {
            flash('error', 'Start time must be before end time.');
        } elseif ($schedDate < $today) {
            flash('error', 'Scheduled date cannot be in the past.');
        } else {
            $modCheck = $db->prepare('SELECT cat_date, exam_date FROM modules WHERE module_id = :id AND session_type = \'Weekend\'');
            $modCheck->execute(['id' => $moduleId]);
            $modRow = $modCheck->fetch();
            if (!$modRow) { flash('error', 'Weekend module not found.'); redirect('/coordinator/eligibility.php'); }
            $expectedDate = $examType === 'CAT' ? ($modRow['cat_date'] ?? null) : ($modRow['exam_date'] ?? null);
            if ($expectedDate && $schedDate !== $expectedDate) {
                flash('error', "The scheduled date ($schedDate) does not match the module's $examType date ($expectedDate).");
            } else {
                $db->prepare(
                    "INSERT INTO cat_exam_schedules (module_id, exam_type, scheduled_date, start_time, end_time, room, invigilator_id, created_by)
                     VALUES (:mid, :type, :date, :stime, :etime, :room, :inv, :uid)
                     ON DUPLICATE KEY UPDATE scheduled_date=:date2, start_time=:stime2, end_time=:etime2, room=:room2, invigilator_id=:inv2, created_by=:uid2"
                )->execute([
                    'mid' => $moduleId, 'type' => $examType, 'date' => $schedDate,
                    'stime' => $startTime, 'etime' => $endTime, 'room' => $room, 'inv' => $invigId, 'uid' => $me['user_id'],
                    'date2' => $schedDate, 'stime2' => $startTime, 'etime2' => $endTime, 'room2' => $room, 'inv2' => $invigId, 'uid2' => $me['user_id'],
                ]);
                AuditLog::record(Auth::id(), 'CAT_EXAM_SCHEDULE_SAVE', 'modules', $moduleId);

                $invUserRow = $db->prepare('SELECT u.user_id, u.full_name, u.email FROM lecturers l JOIN users u ON u.user_id = l.user_id WHERE l.lecturer_id = :id');
                $invUserRow->execute(['id' => $invigId]);
                $invUser = $invUserRow->fetch() ?: [];
                $invName = $invUser['full_name'] ?? 'TBA';
                $modTitleRow = $db->prepare('SELECT module_title FROM modules WHERE module_id = :id');
                $modTitleRow->execute(['id' => $moduleId]);
                $modTitle = $modTitleRow->fetchColumn() ?: '';
                $dayOfWeek = date('l', strtotime($schedDate));
                $schedPayload = [
                    'exam_type' => $examType, 'module_title' => $modTitle,
                    'scheduled_date' => $schedDate, 'start_time' => $startTime, 'end_time' => $endTime,
                    'room' => $room, 'invigilator_name' => $invName,
                ];
                $timeLabel  = date('h:i A', strtotime($startTime)) . '–' . date('h:i A', strtotime($endTime));
                $notifTitle = "Your $examType is on $dayOfWeek — $modTitle";
                $notifBody  = "$examType for $modTitle scheduled on $dayOfWeek, " . date('d M Y', strtotime($schedDate)) . " · Time: $timeLabel · Room: $room · Invigilator: $invName.";

                $enrolledStmt = $db->prepare(
                    "SELECT u.* FROM module_enrollments e JOIN users u ON u.user_id = e.user_id WHERE e.module_id = :mid"
                );
                $enrolledStmt->execute(['mid' => $moduleId]);
                foreach ($enrolledStmt->fetchAll() as $student) {
                    NotificationCenter::notify((int) $student['user_id'], $notifTitle, $notifBody, 'Attendance');
                    Mailer::sendCatExamSchedule($student, $schedPayload);
                }
                if (!empty($invUser['email'])) {
                    Mailer::sendInvigilatorAssigned($invUser, $schedPayload);
                }
                flash('success', "$examType schedule saved.");
            }
        }
        redirect('/coordinator/eligibility.php?module_id=' . $moduleId . '&exam_type=' . $examType);
    } elseif ($action === 'generate') {
        $examType = $_POST['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $count = Eligibility::generate($moduleId, $examType);
        AuditLog::record(Auth::id(), 'ELIGIBILITY_GENERATE', 'modules', $moduleId, "exam_type=$examType;count=$count");
        flash('success', "Generated $examType eligibility for $count student(s).");
    } elseif ($action === 'decide') {
        $eligibilityId = (int) $_POST['eligibility_id'];
        $decision = $_POST['decision'];
        $row = $db->prepare('SELECT * FROM cat_exam_eligibility WHERE eligibility_id = :id');
        $row->execute(['id' => $eligibilityId]);
        $elig = $row->fetch();
        if ($elig) {
            if ($decision === 'approve') {
                $db->prepare("UPDATE cat_exam_eligibility SET hod_decision='Approved', final_decision=system_decision, decided_by=:uid, decided_at=NOW() WHERE eligibility_id=:id")
                   ->execute(['uid' => $me['user_id'], 'id' => $eligibilityId]);
            } else {
                $finalDecision = $decision === 'override_allow' ? 'Allowed' : 'Not Allowed';
                $db->prepare("UPDATE cat_exam_eligibility SET hod_decision='Overridden', final_decision=:final, override_reason=:reason, decided_by=:uid, decided_at=NOW() WHERE eligibility_id=:id")
                   ->execute(['final' => $finalDecision, 'reason' => trim($_POST['override_reason'] ?? ''), 'uid' => $me['user_id'], 'id' => $eligibilityId]);
            }
            AuditLog::record(Auth::id(), 'ELIGIBILITY_DECIDE', 'cat_exam_eligibility', $eligibilityId, "decision=$decision");
            flash('success', 'Decision recorded.');
        }
    }
    redirect('/coordinator/eligibility.php?module_id=' . $moduleId . '&exam_type=' . ($_POST['exam_type'] ?? $_GET['exam_type'] ?? 'CAT'));
}

$moduleId  = (int) ($_GET['module_id'] ?? 0);
$examType  = ($_GET['exam_type'] ?? 'CAT') === 'Exam' ? 'Exam' : 'CAT';
$viewMode  = ($_GET['view'] ?? 'active') === 'history' ? 'history' : 'active';

$modules = $db->query(
    "SELECT m.*, d.department_name FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     WHERE m.session_type = 'Weekend' AND m.status = 'Ongoing'
       AND (m.cat_date IS NOT NULL OR m.exam_date IS NOT NULL)
     ORDER BY m.module_title"
)->fetchAll();

$completedModules = $db->query(
    "SELECT m.*, d.department_name FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     WHERE m.session_type = 'Weekend' AND m.status = 'Completed'
       AND (m.cat_date IS NOT NULL OR m.exam_date IS NOT NULL)
     ORDER BY m.module_title"
)->fetchAll();

$list = [];
$selectedModule = null;
$currentSchedule = null;
if ($moduleId) {
    foreach ($modules as $m) {
        if ((int) $m['module_id'] === $moduleId) { $selectedModule = $m; break; }
    }
    $listStmt = $db->prepare(
        "SELECT ce.*, u.full_name, u.reg_number FROM cat_exam_eligibility ce JOIN users u ON u.user_id = ce.user_id
         WHERE ce.module_id = :mid AND ce.exam_type = :type ORDER BY u.full_name"
    );
    $listStmt->execute(['mid' => $moduleId, 'type' => $examType]);
    $list = $listStmt->fetchAll();

    $schedStmt = $db->prepare(
        "SELECT cs.*, u.full_name AS invigilator_name FROM cat_exam_schedules cs
         LEFT JOIN lecturers l ON l.lecturer_id = cs.invigilator_id
         LEFT JOIN users u ON u.user_id = l.user_id
         WHERE cs.module_id = :mid AND cs.exam_type = :type"
    );
    $schedStmt->execute(['mid' => $moduleId, 'type' => $examType]);
    $currentSchedule = $schedStmt->fetch();
}

$lecturers = $db->query(
    "SELECT l.lecturer_id, u.full_name FROM lecturers l JOIN users u ON u.user_id = l.user_id WHERE u.status='Active' ORDER BY u.full_name"
)->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>

<h4 class="display-font mb-1">Weekend CAT / Exam Eligibility</h4>
<p class="text-muted small mb-3">Manage eligibility and schedules for Weekend session modules.</p>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $viewMode === 'active' ? 'active' : '' ?>" href="?view=active">Ongoing Modules</a></li>
  <li class="nav-item"><a class="nav-link <?= $viewMode === 'history' ? 'active' : '' ?>" href="?view=history"><i class="bi bi-clock-history me-1"></i>History (Completed)</a></li>
</ul>

<?php if ($viewMode === 'active'): ?>
<?php if (!$moduleId): ?>
<div class="semas-card p-3 mb-3">
  <p class="small text-muted mb-2">Select an ongoing Weekend module:</p>
  <div class="row g-2">
    <?php foreach ($modules as $m): ?>
      <div class="col-md-4">
        <a href="?view=active&module_id=<?= $m['module_id'] ?>&exam_type=CAT" class="btn btn-outline-dark btn-sm w-100 text-start py-2">
          <strong><?= e($m['module_title']) ?></strong><br>
          <small class="text-muted"><?= e($m['department_name'] ?? '') ?></small>
        </a>
      </div>
    <?php endforeach; ?>
    <?php if (!$modules): ?>
      <div class="col-12"><div class="text-muted small">No ongoing Weekend modules with CAT/Exam dates found.</div></div>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<div class="mb-3 d-flex gap-2 flex-wrap">
  <a href="?module_id=<?= $moduleId ?>&exam_type=CAT" class="btn btn-sm <?= $examType==='CAT' ? 'btn-semas' : 'btn-outline-dark' ?>">CAT</a>
  <a href="?module_id=<?= $moduleId ?>&exam_type=Exam" class="btn btn-sm <?= $examType==='Exam' ? 'btn-semas' : 'btn-outline-dark' ?>">Exam</a>
  <a href="?module_id=0" class="btn btn-sm btn-outline-secondary ms-auto">← All Modules</a>
</div>

<?php if ($selectedModule): ?>
<div class="semas-card p-3 mb-3">
  <h6 class="display-font mb-1"><?= e($selectedModule['module_title']) ?></h6>
  <small class="text-muted"><?= e($selectedModule['department_name'] ?? '') ?> · Weekend ·
    <?= $examType ?> Date: <?= e($examType==='CAT' ? ($selectedModule['cat_date']??'—') : ($selectedModule['exam_date']??'—')) ?>
  </small>
</div>
<?php endif; ?>

<!-- Schedule form -->
<div class="semas-card p-3 mb-3">
  <h6 class="display-font mb-2">Schedule <?= $examType ?></h6>
  <form method="POST" class="row g-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_schedule">
    <input type="hidden" name="module_id" value="<?= $moduleId ?>">
    <input type="hidden" name="exam_type" value="<?= $examType ?>">
    <div class="col-md-3"><label class="form-label small">Room <span class="text-danger">*</span></label>
      <input name="room" class="form-control form-control-sm" value="<?= e($currentSchedule['room'] ?? '') ?>" required></div>
    <div class="col-md-3"><label class="form-label small">Date <span class="text-danger">*</span></label>
      <input type="date" name="scheduled_date" class="form-control form-control-sm" min="<?= $today ?>" value="<?= e($currentSchedule['scheduled_date'] ?? '') ?>" required></div>
    <div class="col-md-2"><label class="form-label small">Start</label>
      <input type="time" name="start_time" class="form-control form-control-sm" value="<?= e($currentSchedule['start_time'] ?? '') ?>" required></div>
    <div class="col-md-2"><label class="form-label small">End</label>
      <input type="time" name="end_time" class="form-control form-control-sm" value="<?= e($currentSchedule['end_time'] ?? '') ?>" required></div>
    <div class="col-md-2"><label class="form-label small">Invigilator <span class="text-danger">*</span></label>
      <select name="invigilator_id" class="form-select form-select-sm" required>
        <option value="">Select</option>
        <?php foreach ($lecturers as $l): ?>
          <option value="<?= $l['lecturer_id'] ?>" <?= ($currentSchedule['invigilator_id']??0)==$l['lecturer_id']?'selected':'' ?>><?= e($l['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12"><button class="btn btn-semas-gold btn-sm">Save Schedule &amp; Notify Students</button></div>
  </form>
</div>

<!-- Eligibility list -->
<div class="semas-card p-3 mb-2">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="display-font mb-0">Eligibility List</h6>
    <form method="POST" class="d-inline">
      <?= csrf_field() ?><input type="hidden" name="action" value="generate"><input type="hidden" name="module_id" value="<?= $moduleId ?>">
      <input type="hidden" name="exam_type" value="<?= $examType ?>">
      <button class="btn btn-sm btn-outline-dark">Recalculate Eligibility</button>
    </form>
  </div>
  <?php if (!$list): ?>
    <p class="text-muted small">No eligibility records. Click Recalculate to generate them.</p>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Student</th><th>Reg #</th><th>Attendance</th><th>System</th><th>HOD Decision</th><th>Final</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($list as $ce): ?>
        <tr>
          <td><?= e($ce['full_name']) ?></td>
          <td><?= e($ce['reg_number'] ?? '—') ?></td>
          <td><?= $ce['attendance_pct'] ?? '0' ?>%</td>
          <td><span class="badge <?= $ce['system_decision']==='Allowed'?'badge-completed':'bg-danger' ?>"><?= e($ce['system_decision']) ?></span></td>
          <td><?= e($ce['hod_decision'] ?? 'Pending') ?></td>
          <td><span class="badge <?= $ce['final_decision']==='Allowed'||$ce['final_decision']==='Eligible'?'badge-completed':'bg-secondary' ?>"><?= e($ce['final_decision'] ?? '—') ?></span></td>
          <td>
            <?php if (!$ce['hod_decision'] || $ce['hod_decision']==='Pending'): ?>
              <form method="POST" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="decide">
                <input type="hidden" name="module_id" value="<?= $moduleId ?>">
                <input type="hidden" name="exam_type" value="<?= $examType ?>">
                <input type="hidden" name="eligibility_id" value="<?= $ce['eligibility_id'] ?>">
                <button name="decision" value="approve" class="btn btn-xs btn-outline-dark">Approve</button>
                <button name="decision" value="override_allow" class="btn btn-xs btn-outline-success">Override Allow</button>
                <button name="decision" value="override_deny" class="btn btn-xs btn-outline-danger">Override Deny</button>
              </form>
            <?php else: ?>
              <span class="text-muted small"><?= e($ce['hod_decision']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php endif; // !$moduleId / else ?>

<?php elseif ($viewMode === 'history'): ?>
<!-- History: Completed Weekend modules — read-only view -->
<?php if (!$completedModules): ?>
  <div class="semas-card p-4 text-center text-muted small">No completed Weekend modules with CAT/Exam dates found.</div>
<?php else: ?>
  <?php
  $histModId   = (int) ($_GET['module_id'] ?? 0);
  $histExamType = ($_GET['exam_type'] ?? 'CAT') === 'Exam' ? 'Exam' : 'CAT';
  ?>
  <?php if (!$histModId): ?>
  <div class="semas-card p-3">
    <p class="small text-muted mb-2">Completed Weekend modules (read-only):</p>
    <div class="row g-2">
      <?php foreach ($completedModules as $m): ?>
      <div class="col-md-4">
        <a href="?view=history&module_id=<?= $m['module_id'] ?>&exam_type=CAT" class="btn btn-outline-secondary btn-sm w-100 text-start py-2">
          <strong><?= e($m['module_title']) ?></strong><br>
          <small class="text-muted"><?= e($m['department_name'] ?? '') ?></small>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php else: ?>
  <?php
  $histMod = null;
  foreach ($completedModules as $m) { if ((int)$m['module_id'] === $histModId) { $histMod = $m; break; } }
  $histList = [];
  if ($histMod) {
      $hs = $db->prepare("SELECT ce.*, u.full_name, u.reg_number FROM cat_exam_eligibility ce JOIN users u ON u.user_id = ce.user_id WHERE ce.module_id = :mid AND ce.exam_type = :type ORDER BY u.full_name");
      $hs->execute(['mid' => $histModId, 'type' => $histExamType]);
      $histList = $hs->fetchAll();
  }
  ?>
  <div class="mb-2 d-flex gap-2 flex-wrap">
    <a href="?view=history&module_id=<?= $histModId ?>&exam_type=CAT" class="btn btn-sm <?= $histExamType==='CAT' ? 'btn-semas' : 'btn-outline-dark' ?>">CAT</a>
    <a href="?view=history&module_id=<?= $histModId ?>&exam_type=Exam" class="btn btn-sm <?= $histExamType==='Exam' ? 'btn-semas' : 'btn-outline-dark' ?>">Exam</a>
    <a href="?view=history" class="btn btn-sm btn-outline-secondary ms-auto">← All Completed</a>
  </div>
  <?php if ($histMod): ?>
  <div class="semas-card p-3 mb-3">
    <h6 class="display-font mb-1"><?= e($histMod['module_title']) ?> <span class="badge bg-secondary ms-1">Completed</span></h6>
    <small class="text-muted"><?= e($histMod['department_name'] ?? '') ?> · Weekend · <?= $histExamType ?> Date: <?= e($histExamType==='CAT' ? ($histMod['cat_date']??'—') : ($histMod['exam_date']??'—')) ?></small>
  </div>
  <div class="semas-card p-3">
    <div class="alert alert-secondary small py-2 mb-3"><i class="bi bi-eye me-1"></i>History view — read only. No changes can be made to completed modules.</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Student</th><th>Reg No.</th><th>Attendance</th><th>System</th><th>HOD Status</th><th>Final</th></tr></thead>
        <tbody>
          <?php foreach ($histList as $row): ?>
          <tr>
            <td><?= e($row['full_name']) ?></td>
            <td><?= e($row['reg_number'] ?? '—') ?></td>
            <td><?= $row['attendance_pct'] ?? '0' ?>%</td>
            <td><span class="badge <?= $row['system_decision']==='Allowed' ? 'badge-completed' : 'bg-danger' ?>"><?= e($row['system_decision']) ?></span></td>
            <td><span class="badge bg-secondary"><?= e($row['hod_decision'] ?? 'Pending') ?></span></td>
            <td><span class="badge <?= $row['final_decision']==='Allowed' ? 'badge-completed' : 'badge-cancelled' ?>"><?= e($row['final_decision'] ?? '—') ?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$histList): ?><tr><td colspan="6" class="text-muted small text-center py-3">No eligibility records for this module/exam type.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; // $histMod ?>
  <?php endif; // $histModId ?>
<?php endif; // $completedModules ?>

<?php endif; // $viewMode ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
