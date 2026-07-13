<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD', 'Coordinator', 'Principal']);
Module::autoCompleteExpired();

$pageTitle = 'CAT / Exam Eligibility';
$activeNav = 'eligibility';
$db = Database::connection();
$me = Auth::user();
$isCoordinator = Auth::role() === 'Coordinator';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    if ($isCoordinator && $moduleId) {
        $modType = $db->prepare('SELECT session_type FROM modules WHERE module_id = :id');
        $modType->execute(['id' => $moduleId]);
        if (($modType->fetchColumn() ?? '') !== 'Weekend') {
            flash('error', 'Selected module is not available for Weekend coordinator review.');
            redirect('/hod/eligibility.php');
        }
    }

    if ($action === 'save_schedule') {
        $examType   = $_POST['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $room       = trim($_POST['room'] ?? '');
        $invigId    = (int) ($_POST['invigilator_id'] ?? 0);
        $schedDate  = trim($_POST['scheduled_date'] ?? '');
        $startTime  = trim($_POST['start_time'] ?? '');
        $endTime    = trim($_POST['end_time'] ?? '');

        // Exam cannot be scheduled until CAT is finished for this module, i.e. the
        // invigilator has submitted the CAT attendance to the HOD (cat_exam_submissions row exists).
        $catFinishedCheck = $db->prepare(
            "SELECT 1 FROM cat_exam_schedules cs
             JOIN cat_exam_submissions sub ON sub.schedule_id = cs.schedule_id
             WHERE cs.module_id = :mid AND cs.exam_type = 'CAT'"
        );
        $catFinishedCheck->execute(['mid' => $moduleId]);
        $catFinished = (bool) $catFinishedCheck->fetchColumn();

        // Validate invigilator is a lecturer
        $invCheck = $db->prepare('SELECT lecturer_id FROM lecturers WHERE lecturer_id = :id');
        $invCheck->execute(['id' => $invigId]);
        if ($examType === 'Exam' && !$catFinished) {
            flash('error', 'CAT must be finished (attendance submitted to HOD) for this module before the Exam can be scheduled.');
        } elseif (!$invCheck->fetch()) {
            flash('error', 'Invalid invigilator selected.');
        } elseif (!$room || !$schedDate || !$startTime || !$endTime) {
            if ($examType === 'CAT') {
                flash('error', 'Room, scheduled date, start time and end time are all required for CAT scheduling.');
            } else {
                flash('error', 'Room, scheduled date, start time and end time are all required for Exam scheduling.');
            }
        } elseif ($startTime >= $endTime) {
            flash('error', 'Start time must be before end time.');
        } else {
            // Validate date matches module's cat_date or exam_date
            $modCheck = $db->prepare('SELECT cat_date, exam_date FROM modules WHERE module_id = :id');
            $modCheck->execute(['id' => $moduleId]);
            $modRow = $modCheck->fetch();
            $expectedDate = $examType === 'CAT' ? ($modRow['cat_date'] ?? null) : ($modRow['exam_date'] ?? null);

            // A lecturer can only invigilate ONE room per day, regardless of
            // time overlap / but several modules sharing that same
            // invigilator + day + room is fine (no conflict). Only check
            // against Ongoing modules; Completed modules' schedules are
            // historical and must not block new scheduling.
            $conflictStmt = $db->prepare(
                "SELECT cs.room FROM cat_exam_schedules cs
                 JOIN modules m ON m.module_id = cs.module_id
                 WHERE cs.invigilator_id = :inv AND cs.scheduled_date = :date AND cs.room <> :room
                   AND m.status = 'Ongoing'
                   AND NOT (cs.module_id = :mid AND cs.exam_type = :type)"
            );
            $conflictStmt->execute([
                'inv' => $invigId, 'date' => $schedDate, 'room' => $room,
                'mid' => $moduleId, 'type' => $examType,
            ]);
            $conflictRoom = $conflictStmt->fetchColumn();

            if ($expectedDate && $schedDate !== $expectedDate) {
                flash('error', 'The scheduled date (' . $schedDate . ') does not match the module\'s ' . $examType . ' date (' . $expectedDate . '). Update the module first if the date changed.');
            } elseif ($conflictRoom) {
                flash('error', "This invigilator is already assigned to Room \"$conflictRoom\" on $schedDate. A lecturer can only invigilate one room per day.");
            } else {
                $eligCheck = $db->prepare(
                    'SELECT COUNT(*) AS total, SUM(hod_decision = "Pending") AS pending
                     FROM cat_exam_eligibility
                     WHERE module_id = :mid AND exam_type = :type'
                );
                $eligCheck->execute(['mid' => $moduleId, 'type' => $examType]);
                $eligResult = $eligCheck->fetch();
                $totalEligibility = (int) ($eligResult['total'] ?? 0);
                $pendingEligibility = (int) ($eligResult['pending'] ?? 0);

                if ($totalEligibility === 0) {
                    flash('error', 'Generate the eligibility list for this module and exam type before scheduling.');
                } elseif ($pendingEligibility > 0) {
                    flash('error', 'All student eligibility decisions must be completed before scheduling this ' . $examType . '.');
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

                    // Fetch invigilator user row (needed for email + notification payload)
                    $invUserRow = $db->prepare('SELECT u.user_id, u.full_name, u.email FROM lecturers l JOIN users u ON u.user_id = l.user_id WHERE l.lecturer_id = :id');
                    $invUserRow->execute(['id' => $invigId]);
                    $invUser = $invUserRow->fetch() ?: [];
                    $invName = $invUser['full_name'] ?? 'TBA';
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
                    $timeLabel = date('h:i A', strtotime($startTime)) . '/' . date('h:i A', strtotime($endTime));
                    $notifTitle = "Your $examType is on $dayOfWeek / $modTitle";
                    $notifBody  = "$examType for $modTitle scheduled on $dayOfWeek, " . date('d M Y', strtotime($schedDate)) . " · Time: $timeLabel · Room: $room · Invigilator: $invName. Be prepared with all required documents.";

                    // Notify every enrolled student
                    $enrolledStmt = $db->prepare(
                        "SELECT u.* FROM module_enrollments e JOIN users u ON u.user_id = e.user_id WHERE e.module_id = :mid"
                    );
                    $enrolledStmt->execute(['mid' => $moduleId]);
                    $enrolledStudents = $enrolledStmt->fetchAll();
                    foreach ($enrolledStudents as $student) {
                        NotificationCenter::notify((int) $student['user_id'], $notifTitle, $notifBody, 'Attendance');
                        Mailer::enqueueCatExamSchedule($student, $schedPayload);
                    }
                    if (!empty($invUser['email'])) {
                        Mailer::enqueueInvigilatorAssigned($invUser, $schedPayload);
                    }
                    Mailer::dispatch();

                    flash('success', "$examType schedule saved. " . count($enrolledStudents) . " student(s) notified by email and in-app notification.");
                }
            }
        }
        redirect('/hod/eligibility.php?module_id=' . $moduleId . '&exam_type=' . $examType);
    } elseif ($action === 'generate') {
        $examType = $_POST['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $count = Eligibility::generate($moduleId, $examType);

        AuditLog::record(Auth::id(), 'ELIGIBILITY_GENERATE', 'modules', $moduleId, "exam_type=$examType;count=$count");
        flash('success', "Generated/refreshed $examType eligibility for $count student(s). Two Late days count as one absence; 0-2 effective absences are Allowed, while 3+ are Not Allowed.");
    } elseif ($action === 'decide') {
        $examType = $_POST['exam_type'] === 'Exam' ? 'Exam' : 'CAT';

        $scheduleStmt = $db->prepare(
            "SELECT cs.*, u.full_name AS invigilator_name, m.module_title
             FROM cat_exam_schedules cs
             JOIN modules m ON m.module_id = cs.module_id
             JOIN lecturers l ON l.lecturer_id = cs.invigilator_id
             JOIN users u ON u.user_id = l.user_id
             WHERE cs.module_id = :mid AND cs.exam_type = :type"
        );
        $scheduleStmt->execute(['mid' => $moduleId, 'type' => $examType]);
        $schedule = $scheduleStmt->fetch();

        $eligibilityId = (int) $_POST['eligibility_id'];
        $decision = $_POST['decision']; // approve | override_allow | override_deny
        $row = $db->prepare('SELECT * FROM cat_exam_eligibility WHERE eligibility_id = :id');
        $row->execute(['id' => $eligibilityId]);
        $elig = $row->fetch();
        if ($elig) {
            if ($decision === 'approve') {
                $finalDecision = $elig['system_decision'];
                $db->prepare("UPDATE cat_exam_eligibility SET hod_decision='Approved', final_decision = system_decision, decided_by=:uid, decided_at=NOW() WHERE eligibility_id=:id")
                   ->execute(['uid' => $me['user_id'], 'id' => $eligibilityId]);
            } else {
                $finalDecision = $decision === 'override_allow' ? 'Allowed' : 'Not Allowed';
                $db->prepare("UPDATE cat_exam_eligibility SET hod_decision='Overridden', final_decision=:final, override_reason=:reason, decided_by=:uid, decided_at=NOW() WHERE eligibility_id=:id")
                   ->execute(['final' => $finalDecision, 'reason' => trim($_POST['override_reason'] ?? ''), 'uid' => $me['user_id'], 'id' => $eligibilityId]);
            }

            if ($schedule) {
                $studentStmt = $db->prepare('SELECT user_id, full_name, email FROM users WHERE user_id = :id');
                $studentStmt->execute(['id' => $elig['user_id']]);
                $student = $studentStmt->fetch();
                if ($student && !empty($student['email'])) {
                    Mailer::enqueueEligibilityDecision($student, $schedule, $finalDecision);
                    Mailer::dispatch();
                }
            }

            AuditLog::record(Auth::id(), 'ELIGIBILITY_DECIDE', 'cat_exam_eligibility', $eligibilityId, "decision=$decision");
            flash('success', 'Decision recorded.');
        }
    }
    redirect('/hod/eligibility.php?module_id=' . $moduleId . '&exam_type=' . ($_POST['exam_type'] ?? $_GET['exam_type'] ?? 'CAT'));
}

$moduleId  = (int) ($_GET['module_id'] ?? 0);
$examType  = ($_GET['exam_type'] ?? 'CAT') === 'Exam' ? 'Exam' : 'CAT';
$viewMode  = ($_GET['view'] ?? 'active') === 'history' ? 'history' : 'active';

// Exam cannot be viewed/scheduled until CAT is finished for the selected module
// (invigilator has submitted the CAT attendance to the HOD).
$catFinishedForModule = false;
if ($moduleId) {
    $catCheckStmt = $db->prepare(
        "SELECT 1 FROM cat_exam_schedules cs
         JOIN cat_exam_submissions sub ON sub.schedule_id = cs.schedule_id
         WHERE cs.module_id = :mid AND cs.exam_type = 'CAT'"
    );
    $catCheckStmt->execute(['mid' => $moduleId]);
    $catFinishedForModule = (bool) $catCheckStmt->fetchColumn();
}
if ($moduleId && $examType === 'Exam' && !$catFinishedForModule) {
    $examType = 'CAT';
}

$ongoingFilter = $isCoordinator ? "AND m.session_type = 'Weekend'" : '';
$modules = $db->query(
    "SELECT m.*, d.department_name FROM modules m LEFT JOIN departments d ON d.department_id = m.department_id
     WHERE m.status = 'Ongoing' AND (m.cat_date IS NOT NULL OR m.exam_date IS NOT NULL) {$ongoingFilter}
     ORDER BY m.module_title"
)->fetchAll();

$completedModules = $db->query(
    "SELECT m.*, d.department_name FROM modules m LEFT JOIN departments d ON d.department_id = m.department_id
     WHERE m.status = 'Completed' AND (m.cat_date IS NOT NULL OR m.exam_date IS NOT NULL) {$ongoingFilter}
     ORDER BY m.module_title"
)->fetchAll();

$list = [];
$selectedModule = null;
$currentSchedule = null;
$currentSubmission = null;
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

// Load all rooms for the schedule room dropdown
$rooms = $db->query('SELECT room_id, room_name FROM rooms ORDER BY room_name')->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">CAT / Exam Eligibility</h4>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $viewMode === 'active' ? 'active' : '' ?>" href="?view=active">Ongoing Modules</a></li>
  <li class="nav-item"><a class="nav-link <?= $viewMode === 'history' ? 'active' : '' ?>" href="?view=history"><i class="bi bi-clock-history me-1"></i>History (Completed)</a></li>
</ul>

<?php if ($viewMode === 'active'): ?>
<div class="semas-card p-3 mb-4">
  <form method="get" class="row g-2">
    <input type="hidden" name="view" value="active">
    <div class="col-md-6">
      <select name="module_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Select an ongoing module</option>
        <?php foreach ($modules as $m): ?><option value="<?= (int) $m['module_id'] ?>" <?= $moduleId === (int) $m['module_id'] ? 'selected' : '' ?>><?= e($m['module_title']) ?> (<?= e($m['department_name'] ?? '') ?>)</option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <select name="exam_type" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="CAT" <?= $examType === 'CAT' ? 'selected' : '' ?>>CAT</option>
        <option value="Exam" <?= $examType === 'Exam' ? 'selected' : '' ?> <?= ($moduleId && !$catFinishedForModule) ? 'disabled' : '' ?>>Exam<?= ($moduleId && !$catFinishedForModule) ? ' (finish CAT first)' : '' ?></option>
      </select>
    </div>
  </form>
  <?php if ($moduleId && !$catFinishedForModule): ?>
    <p class="text-muted small mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Exam scheduling is locked until CAT is finished (attendance submitted to HOD) for this module.</p>
  <?php endif; ?>
</div>

<?php if ($selectedModule): ?>
  <div class="semas-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <div>
        <h6 class="display-font mb-0"><?= e($selectedModule['module_title']) ?> / <?= e($examType) ?> Eligibility</h6>
        <p class="text-muted small mb-0">Date from module: <strong><?= e($examType === 'CAT' ? ($selectedModule['cat_date'] ?? 'Not set') : ($selectedModule['exam_date'] ?? 'Not set')) ?></strong></p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="generate"><input type="hidden" name="module_id" value="<?= (int) $moduleId ?>"><input type="hidden" name="exam_type" value="<?= e($examType) ?>">
          <button class="btn btn-semas-gold btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Generate / Refresh Eligibility List</button></form>
      </div>
    </div>
    <!-- Schedule: room + invigilator + date -->
    <?php if ($currentSchedule): ?>
      <div class="alert alert-info small mb-2 py-2">
        <strong>Current Schedule:</strong>
        Room <strong><?= e($currentSchedule['room']) ?></strong> &middot;
        Invigilator: <strong><?= e($currentSchedule['invigilator_name']) ?></strong> &middot;
        Date: <strong><?= e($currentSchedule['scheduled_date']) ?></strong>
        <?php if ($currentSchedule['start_time']): ?>
          &middot; <strong><?= e(substr($currentSchedule['start_time'], 0, 5)) ?>/<?= e(substr($currentSchedule['end_time'], 0, 5)) ?></strong>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-dark ms-2" data-bs-toggle="collapse" data-bs-target="#scheduleForm">Edit</button>
      </div>
    <?php else: ?>
      <p class="text-muted small mb-1"><i class="bi bi-exclamation-circle me-1"></i>No room/invigilator set yet for this <?= e($examType) ?>. <button class="btn btn-sm btn-semas-gold" data-bs-toggle="collapse" data-bs-target="#scheduleForm">Set Schedule</button></p>
    <?php endif; ?>
    <div class="collapse <?= !$currentSchedule ? 'show' : '' ?>" id="scheduleForm">
      <?php $moduleDate = $examType === 'CAT' ? ($selectedModule['cat_date'] ?? '') : ($selectedModule['exam_date'] ?? ''); ?>
      <div class="alert alert-light border small py-2 mt-1 mb-2">
        <i class="bi bi-calendar-event me-1"></i>
        Date is taken from the module: <strong><?= e($moduleDate ?: '/') ?></strong>
      </div>
      <form method="post" class="row g-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_schedule">
        <input type="hidden" name="module_id" value="<?= (int) $moduleId ?>">
        <input type="hidden" name="exam_type" value="<?= e($examType) ?>">
        <input type="hidden" name="scheduled_date" value="<?= e($moduleDate) ?>">
        <div class="col-md-4">
          <label class="form-label small mb-1">Room</label>
          <select name="room" class="form-select form-select-sm" required>
            <option value="">Select room</option>
            <?php foreach ($rooms as $rm): ?>
              <option value="<?= e($rm['room_name']) ?>" <?= ($currentSchedule['room'] ?? '') === $rm['room_name'] ? 'selected' : '' ?>><?= e($rm['room_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label small mb-1">Invigilator (Lecturer)</label>
          <select name="invigilator_id" class="form-select form-select-sm" required>
            <option value="">Select invigilator</option>
            <?php foreach ($lecturers as $lect): ?>
              <option value="<?= (int) $lect['lecturer_id'] ?>" <?= (int) ($currentSchedule['invigilator_id'] ?? 0) === (int) $lect['lecturer_id'] ? 'selected' : '' ?>><?= e($lect['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
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
        <div class="col-12 text-end">
          <button class="btn btn-semas btn-sm px-4">Save Schedule</button>
        </div>
      </form>
    </div>
  </div>


  <div class="semas-card p-3">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Student</th><th>Reg No.</th><th>Attendance</th><th>Days</th><th>P</th><th>L</th><th>Sign In / No Out</th><th>Effective Absences</th><th>System</th><th>Review</th><th>Final</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($list as $row): ?>
            <?php
              $pct = isset($row['attendance_percent']) ? (float) $row['attendance_percent'] : 0.0;
              $review = !empty($row['requires_review']);
              $systemLabel = $review ? 'Requires HoD Approval' : $row['system_decision'];
              $systemClass = $row['system_decision'] === 'Allowed' ? 'badge-completed' : ($review ? 'badge-urgent' : 'badge-cancelled');
              $missed = (int) $row['absences_count'];
              $riskLabel = $missed >= 4 ? 'Critical' : ($missed === 3 ? 'Special Case' : ($missed === 2 ? 'Warning' : ''));
              $riskClass = $missed >= 4 ? 'bg-danger' : ($missed === 3 ? 'bg-warning text-dark' : 'bg-info text-dark');
            ?>
            <tr style="<?= $missed >= 4 ? 'background:#f8d7da;' : ($missed >= 2 ? 'background:#fff3cd;' : '') ?>">
              <td><?= e($row['full_name']) ?></td>
              <td><?= e($row['reg_number'] ?? '/') ?></td>
              <td><strong><?= number_format($pct, 1) ?>%</strong></td>
              <td><?= (int) ($row['total_sessions'] ?? 0) ?></td>
              <td><?= (int) ($row['present_count'] ?? 0) ?></td>
              <td><?= (int) ($row['late_count'] ?? 0) ?></td>
              <td><?= (int) ($row['left_early_count'] ?? 0) ?></td>
              <td>
                <strong><?= $missed ?></strong>
                <?php if ($riskLabel): ?><span class="badge <?= $riskClass ?> ms-1"><?= e($riskLabel) ?></span><?php endif; ?>
              </td>
              <td><span class="badge <?= $systemClass ?>"><?= e($systemLabel) ?></span></td>
              <td><span class="badge <?= $row['hod_decision'] === 'Pending' ? 'badge-urgent' : 'bg-secondary' ?>"><?= e($row['hod_decision']) ?></span></td>
              <td><span class="badge <?= $row['final_decision'] === 'Allowed' ? 'badge-completed' : 'badge-cancelled' ?>"><?= e($row['final_decision']) ?></span></td>
              <td class="text-nowrap">
                <?php if ($row['hod_decision'] === 'Pending'): ?>
                  <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="decide"><input type="hidden" name="eligibility_id" value="<?= (int) $row['eligibility_id'] ?>"><input type="hidden" name="module_id" value="<?= (int) $moduleId ?>"><input type="hidden" name="exam_type" value="<?= e($examType) ?>">
                    <button class="btn btn-sm btn-outline-dark" name="decision" value="approve">Keep Not Allowed</button>
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
                      <div class="mb-2"><label class="form-label small">Reason</label><textarea name="override_reason" class="form-control form-control-sm" rows="3" required></textarea></div>
                    </div>
                    <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Save Override</button></div>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$list): ?><tr><td colspan="12" class="text-muted small text-center py-3">No list generated yet for this module/exam type.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; // $selectedModule ?>

<?php elseif ($viewMode === 'history'): ?>
<!-- History: Completed modules / read-only view -->
<?php if (!$completedModules): ?>
  <div class="semas-card p-4 text-center text-muted small">No completed modules with CAT/Exam dates found.</div>
<?php else: ?>
  <?php
  $histModId = (int) ($_GET['module_id'] ?? 0);
  $histExamType = ($_GET['exam_type'] ?? 'CAT') === 'Exam' ? 'Exam' : 'CAT';
  ?>
  <?php if (!$histModId): ?>
  <div class="semas-card p-3">
    <p class="small text-muted mb-2">Completed modules (read-only / no actions available):</p>
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
    <small class="text-muted"><?= e($histMod['department_name'] ?? '') ?> · <?= $histExamType ?> Date: <?= e($histExamType==='CAT' ? ($histMod['cat_date']??'/') : ($histMod['exam_date']??'/')) ?></small>
  </div>
  <div class="semas-card p-3">
    <div class="alert alert-secondary small py-2 mb-3"><i class="bi bi-eye me-1"></i>History view / read only. No changes can be made to completed modules.</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Student</th><th>Reg No.</th><th>Missed</th><th>System Decision</th><th>HOD Status</th><th>Final</th></tr></thead>
        <tbody>
          <?php foreach ($histList as $row): ?>
          <tr>
            <td><?= e($row['full_name']) ?></td>
            <td><?= e($row['reg_number'] ?? '/') ?></td>
            <td><?= (int) $row['absences_count'] ?></td>
            <td><span class="badge <?= $row['system_decision']==='Allowed' ? 'badge-completed' : 'badge-cancelled' ?>"><?= e($row['system_decision']) ?></span></td>
            <td><span class="badge bg-secondary"><?= e($row['hod_decision']) ?></span></td>
            <td><span class="badge <?= $row['final_decision']==='Allowed' ? 'badge-completed' : 'badge-cancelled' ?>"><?= e($row['final_decision']) ?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$histList): ?><tr><td colspan="6" class="text-muted small text-center py-3">No eligibility records for this module/exam type.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<?php endif; // $viewMode ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
