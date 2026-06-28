<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD']);
Module::autoCompleteExpired();

$pageTitle = 'CAT / Exam Eligibility';
$activeNav = 'eligibility';
$db = Database::connection();
$me = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $moduleId = (int) ($_POST['module_id'] ?? 0);

    if ($action === 'save_schedule') {
        $examType   = $_POST['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $room       = trim($_POST['room'] ?? '');
        $invigId    = (int) ($_POST['invigilator_id'] ?? 0);
        $schedDate  = trim($_POST['scheduled_date'] ?? '');
        $startTime  = trim($_POST['start_time'] ?? '');
        $endTime    = trim($_POST['end_time'] ?? '');

        // Validate invigilator is a lecturer
        $invCheck = $db->prepare('SELECT lecturer_id FROM lecturers WHERE lecturer_id = :id');
        $invCheck->execute(['id' => $invigId]);
        if (!$invCheck->fetch()) {
            flash('error', 'Invalid invigilator selected.');
        } elseif (!$room || !$schedDate || !$startTime || !$endTime) {
            flash('error', 'Room, scheduled date, start time and end time are all required.');
        } elseif ($startTime >= $endTime) {
            flash('error', 'Start time must be before end time.');
        } else {
            // Validate date matches module's cat_date or exam_date
            $modCheck = $db->prepare('SELECT cat_date, exam_date FROM modules WHERE module_id = :id');
            $modCheck->execute(['id' => $moduleId]);
            $modRow = $modCheck->fetch();
            $expectedDate = $examType === 'CAT' ? ($modRow['cat_date'] ?? null) : ($modRow['exam_date'] ?? null);
            if ($expectedDate && $schedDate !== $expectedDate) {
                flash('error', 'The scheduled date (' . $schedDate . ') does not match the module\'s ' . $examType . ' date (' . $expectedDate . '). Update the module first if the date changed.');
            } else {
                $db->prepare(
                    "INSERT INTO cat_exam_schedules (module_id, exam_type, scheduled_date, start_time, end_time, room, invigilator_id, created_by)
                     VALUES (:mid, :type, :date, :stime, :etime, :room, :inv, :uid)
                     ON DUPLICATE KEY UPDATE scheduled_date=:date2, start_time=:stime2, end_time=:etime2, room=:room2, invigilator_id=:inv2, created_by=:uid2"
                )->execute([
                    'mid' => $moduleId, 'type' => $examType, 'date' => $schedDate,
                    'stime' => $startTime, 'etime' => $endTime,
                    'room' => $room, 'inv' => $invigId, 'uid' => $me['user_id'],
                    'date2' => $schedDate, 'stime2' => $startTime, 'etime2' => $endTime,
                    'room2' => $room, 'inv2' => $invigId, 'uid2' => $me['user_id'],
                ]);
                AuditLog::record(Auth::id(), 'CAT_EXAM_SCHEDULE_SAVE', 'modules', $moduleId, "exam_type=$examType;room=$room;date=$schedDate;start=$startTime;end=$endTime");

                // Build notification/email payload for enrolled students
                $invNameRow = $db->prepare('SELECT u.full_name FROM lecturers l JOIN users u ON u.user_id = l.user_id WHERE l.lecturer_id = :id');
                $invNameRow->execute(['id' => $invigId]);
                $invName = $invNameRow->fetchColumn() ?: 'TBA';
                $modTitleRow = $db->prepare('SELECT module_title FROM modules WHERE module_id = :id');
                $modTitleRow->execute(['id' => $moduleId]);
                $modTitle = $modTitleRow->fetchColumn() ?: '';
                $dayOfWeek = date('l', strtotime($schedDate));
                $schedPayload = [
                    'exam_type'        => $examType,
                    'module_title'     => $modTitle,
                    'scheduled_date'   => $schedDate,
                    'start_time'       => $startTime,
                    'end_time'         => $endTime,
                    'room'             => $room,
                    'invigilator_name' => $invName,
                ];
                $timeLabel = date('h:i A', strtotime($startTime)) . '–' . date('h:i A', strtotime($endTime));
                $notifTitle = "Your $examType is on $dayOfWeek — $modTitle";
                $notifBody  = "$examType for $modTitle scheduled on $dayOfWeek, " . date('d M Y', strtotime($schedDate)) . " · Time: $timeLabel · Room: $room · Invigilator: $invName. Be prepared with all required documents.";

                // Notify every enrolled student
                $enrolledStmt = $db->prepare(
                    "SELECT u.* FROM module_enrollments e JOIN users u ON u.user_id = e.user_id WHERE e.module_id = :mid"
                );
                $enrolledStmt->execute(['mid' => $moduleId]);
                $enrolledStudents = $enrolledStmt->fetchAll();
                foreach ($enrolledStudents as $student) {
                    NotificationCenter::notify((int) $student['user_id'], $notifTitle, $notifBody, 'Attendance');
                    Mailer::sendCatExamSchedule($student, $schedPayload);
                }
                flash('success', "$examType schedule saved. " . count($enrolledStudents) . " student(s) notified by email and in-app notification.");
            }
        }
        redirect('/hod/eligibility.php?module_id=' . $moduleId . '&exam_type=' . $examType);
    } elseif ($action === 'generate') {
        $examType = $_POST['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $count = Eligibility::generate($moduleId, $examType);

        AuditLog::record(Auth::id(), 'ELIGIBILITY_GENERATE', 'modules', $moduleId, "exam_type=$examType;count=$count");
        flash('success', "Generated/refreshed $examType eligibility for $count student(s).");
    } elseif ($action === 'decide') {
        $eligibilityId = (int) $_POST['eligibility_id'];
        $decision = $_POST['decision']; // approve | override_allow | override_deny
        $row = $db->prepare('SELECT * FROM cat_exam_eligibility WHERE eligibility_id = :id');
        $row->execute(['id' => $eligibilityId]);
        $elig = $row->fetch();
        if ($elig) {
            if ($decision === 'approve') {
                $db->prepare("UPDATE cat_exam_eligibility SET hod_decision='Approved', final_decision = system_decision, decided_by=:uid, decided_at=NOW() WHERE eligibility_id=:id")
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
    redirect('/hod/eligibility.php?module_id=' . $moduleId . '&exam_type=' . ($_POST['exam_type'] ?? $_GET['exam_type'] ?? 'CAT'));
}

$moduleId = (int) ($_GET['module_id'] ?? 0);
$examType = ($_GET['exam_type'] ?? 'CAT') === 'Exam' ? 'Exam' : 'CAT';

$modules = $db->query(
    "SELECT m.*, d.department_name FROM modules m LEFT JOIN departments d ON d.department_id = m.department_id
     WHERE m.cat_date IS NOT NULL OR m.exam_date IS NOT NULL ORDER BY m.module_title"
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
         JOIN lecturers l ON l.lecturer_id = cs.invigilator_id
         JOIN users u ON u.user_id = l.user_id
         WHERE cs.module_id = :mid AND cs.exam_type = :type"
    );
    $schedStmt->execute(['mid' => $moduleId, 'type' => $examType]);
    $currentSchedule = $schedStmt->fetch();
}

// Load all lecturers for the invigilator dropdown
$lecturers = $db->query(
    "SELECT l.lecturer_id, u.full_name FROM lecturers l JOIN users u ON u.user_id = l.user_id ORDER BY u.full_name"
)->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">CAT / Exam Eligibility</h4>
<p class="text-muted small mb-4">Select a module to generate or review eligibility. A student who missed 2 or more classes before the cutoff is recommended "Not Allowed" — you can Approve that, or Override it with a reason.</p>

<div class="semas-card p-3 mb-4">
  <form method="get" class="row g-2">
    <div class="col-md-6">
      <select name="module_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Select a module</option>
        <?php foreach ($modules as $m): ?><option value="<?= (int) $m['module_id'] ?>" <?= $moduleId === (int) $m['module_id'] ? 'selected' : '' ?>><?= e($m['module_title']) ?> (<?= e($m['department_name'] ?? '') ?>)</option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <select name="exam_type" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="CAT" <?= $examType === 'CAT' ? 'selected' : '' ?>>CAT</option>
        <option value="Exam" <?= $examType === 'Exam' ? 'selected' : '' ?>>Exam</option>
      </select>
    </div>
  </form>
</div>

<?php if ($selectedModule): ?>
  <div class="semas-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <div>
        <h6 class="display-font mb-0"><?= e($selectedModule['module_title']) ?> — <?= e($examType) ?> Eligibility</h6>
        <p class="text-muted small mb-0">Date from module: <strong><?= e($examType === 'CAT' ? ($selectedModule['cat_date'] ?? 'Not set') : ($selectedModule['exam_date'] ?? 'Not set')) ?></strong></p>
      </div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="generate"><input type="hidden" name="module_id" value="<?= (int) $moduleId ?>"><input type="hidden" name="exam_type" value="<?= e($examType) ?>">
        <button class="btn btn-semas-gold btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Generate / Refresh Eligibility List</button></form>
    </div>
    <!-- Schedule: room + invigilator + date -->
    <?php if ($currentSchedule): ?>
      <div class="alert alert-info small mb-2 py-2">
        <strong>Current Schedule:</strong>
        Room <strong><?= e($currentSchedule['room']) ?></strong> &middot;
        Invigilator: <strong><?= e($currentSchedule['invigilator_name']) ?></strong> &middot;
        Date: <strong><?= e($currentSchedule['scheduled_date']) ?></strong>
        <?php if ($currentSchedule['start_time']): ?>
          &middot; <strong><?= e(substr($currentSchedule['start_time'], 0, 5)) ?>–<?= e(substr($currentSchedule['end_time'], 0, 5)) ?></strong>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-dark ms-2" data-bs-toggle="collapse" data-bs-target="#scheduleForm">Edit</button>
      </div>
    <?php else: ?>
      <p class="text-muted small mb-1"><i class="bi bi-exclamation-circle me-1"></i>No room/invigilator set yet for this <?= e($examType) ?>. <button class="btn btn-sm btn-semas-gold" data-bs-toggle="collapse" data-bs-target="#scheduleForm">Set Schedule</button></p>
    <?php endif; ?>
    <div class="collapse <?= !$currentSchedule ? 'show' : '' ?>" id="scheduleForm">
      <form method="post" class="row g-2 mt-1">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_schedule">
        <input type="hidden" name="module_id" value="<?= (int) $moduleId ?>">
        <input type="hidden" name="exam_type" value="<?= e($examType) ?>">
        <div class="col-md-3">
          <label class="form-label small mb-1">Room</label>
          <input name="room" class="form-control form-control-sm" placeholder="e.g. Hall A, Room 201" value="<?= e($currentSchedule['room'] ?? '') ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label small mb-1">Invigilator (Lecturer)</label>
          <select name="invigilator_id" class="form-select form-select-sm" required>
            <option value="">Select invigilator</option>
            <?php foreach ($lecturers as $lect): ?>
              <option value="<?= (int) $lect['lecturer_id'] ?>" <?= (int) ($currentSchedule['invigilator_id'] ?? 0) === (int) $lect['lecturer_id'] ? 'selected' : '' ?>><?= e($lect['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Scheduled Date</label>
          <input type="date" name="scheduled_date" class="form-control form-control-sm"
                 value="<?= e($currentSchedule['scheduled_date'] ?? ($examType === 'CAT' ? ($selectedModule['cat_date'] ?? '') : ($selectedModule['exam_date'] ?? ''))) ?>"
                 required>
          <div class="form-text" style="font-size:0.7rem;">Must match <?= e($examType) ?> date: <strong><?= e($examType === 'CAT' ? ($selectedModule['cat_date'] ?? '—') : ($selectedModule['exam_date'] ?? '—')) ?></strong></div>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Start Time <span class="text-danger">*</span></label>
          <input type="time" name="start_time" class="form-control form-control-sm"
                 value="<?= e($currentSchedule['start_time'] ? substr($currentSchedule['start_time'], 0, 5) : '') ?>" required>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">End Time <span class="text-danger">*</span></label>
          <input type="time" name="end_time" class="form-control form-control-sm"
                 value="<?= e($currentSchedule['end_time'] ? substr($currentSchedule['end_time'], 0, 5) : '') ?>" required>
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <button class="btn btn-semas btn-sm w-100">Save</button>
        </div>
      </form>
    </div>
  </div>

  <div class="semas-card p-3">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Student</th><th>Reg No.</th><th>Missed</th><th>System Decision</th><th>HOD Status</th><th>Final</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($list as $row): ?>
            <tr>
              <td><?= e($row['full_name']) ?></td>
              <td><?= e($row['reg_number'] ?? '—') ?></td>
              <td><?= (int) $row['absences_count'] ?></td>
              <td><span class="badge <?= $row['system_decision'] === 'Allowed' ? 'badge-completed' : 'badge-cancelled' ?>"><?= e($row['system_decision']) ?></span></td>
              <td><span class="badge <?= $row['hod_decision'] === 'Pending' ? 'badge-urgent' : 'bg-secondary' ?>"><?= e($row['hod_decision']) ?></span></td>
              <td><span class="badge <?= $row['final_decision'] === 'Allowed' ? 'badge-completed' : 'badge-cancelled' ?>"><?= e($row['final_decision']) ?></span></td>
              <td class="text-nowrap">
                <?php if ($row['hod_decision'] === 'Pending'): ?>
                  <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="decide"><input type="hidden" name="eligibility_id" value="<?= (int) $row['eligibility_id'] ?>"><input type="hidden" name="module_id" value="<?= (int) $moduleId ?>"><input type="hidden" name="exam_type" value="<?= e($examType) ?>">
                    <button class="btn btn-sm btn-outline-dark" name="decision" value="approve">Approve</button>
                  </form>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#override-<?= (int) $row['eligibility_id'] ?>">Override</button>
              </td>
            </tr>
            <div class="modal fade" id="override-<?= (int) $row['eligibility_id'] ?>" tabindex="-1">
              <div class="modal-dialog">
                <div class="modal-content">
                  <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="decide"><input type="hidden" name="eligibility_id" value="<?= (int) $row['eligibility_id'] ?>"><input type="hidden" name="module_id" value="<?= (int) $moduleId ?>"><input type="hidden" name="exam_type" value="<?= e($examType) ?>">
                    <div class="modal-header"><h6 class="modal-title display-font">Override for <?= e($row['full_name']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                      <div class="mb-2">
                        <label class="form-label small">Decision</label>
                        <select name="decision" class="form-select form-select-sm">
                          <option value="override_allow">Allow (override Not Allowed)</option>
                          <option value="override_deny">Not Allowed (override Allowed)</option>
                        </select>
                      </div>
                      <div class="mb-2"><label class="form-label small">Reason</label><textarea name="override_reason" class="form-control form-control-sm" rows="3" placeholder="e.g. Medical certificate provided for missed sessions" required></textarea></div>
                    </div>
                    <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Save Override</button></div>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$list): ?><tr><td colspan="7" class="text-muted small text-center py-3">No list generated yet for this module/exam type.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
