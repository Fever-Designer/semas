<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD', 'Coordinator']);
Module::autoCompleteExpired();

$pageTitle = 'CAT / Exam Eligibility';
$activeNav = 'eligibility';
$db = Database::connection();
$me = Auth::user();
$isCoordinator = Auth::role() === 'Coordinator';

// Tracks the lecturer's formal "Submit Module Attendance" step (see
// public/lecturer/class-attendance.php) — same lazy-create as there.
$db->exec(
    "CREATE TABLE IF NOT EXISTS module_attendance_submissions (
        submission_id  INT AUTO_INCREMENT PRIMARY KEY,
        module_id      INT NOT NULL,
        exam_type      ENUM('CAT','Exam') NOT NULL,
        submitted_by   INT NOT NULL,
        submitted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status         ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
        decided_by     INT NULL,
        decided_at     DATETIME NULL,
        decision_note  VARCHAR(255) NULL,
        UNIQUE KEY uniq_module_exam_type (module_id, exam_type),
        FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
        FOREIGN KEY (submitted_by) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (decided_by) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB"
);

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

        // Exam cannot be scheduled until CAT has been scheduled for this module.
        $catScheduledCheck = $db->prepare("SELECT 1 FROM cat_exam_schedules WHERE module_id = :mid AND exam_type = 'CAT'");
        $catScheduledCheck->execute(['mid' => $moduleId]);
        $catAlreadyScheduled = (bool) $catScheduledCheck->fetchColumn();

        // Validate invigilator is a lecturer
        $invCheck = $db->prepare('SELECT lecturer_id FROM lecturers WHERE lecturer_id = :id');
        $invCheck->execute(['id' => $invigId]);
        if ($examType === 'Exam' && !$catAlreadyScheduled) {
            flash('error', 'CAT must be scheduled for this module before the Exam can be scheduled.');
        } elseif (!$invCheck->fetch()) {
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

            // A lecturer cannot invigilate two different rooms at the same time — but
            // invigilating several modules in the same room/time is allowed.
            $conflictStmt = $db->prepare(
                "SELECT room FROM cat_exam_schedules
                 WHERE invigilator_id = :inv AND scheduled_date = :date AND room <> :room
                   AND NOT (end_time <= :stime OR start_time >= :etime)
                   AND NOT (module_id = :mid AND exam_type = :type)"
            );
            $conflictStmt->execute([
                'inv' => $invigId, 'date' => $schedDate, 'room' => $room,
                'stime' => $startTime, 'etime' => $endTime, 'mid' => $moduleId, 'type' => $examType,
            ]);
            $conflictRoom = $conflictStmt->fetchColumn();

            if ($expectedDate && $schedDate !== $expectedDate) {
                flash('error', 'The scheduled date (' . $schedDate . ') does not match the module\'s ' . $examType . ' date (' . $expectedDate . '). Update the module first if the date changed.');
            } elseif ($conflictRoom) {
                flash('error', "This invigilator is already assigned to Room \"$conflictRoom\" at an overlapping time on $schedDate. A lecturer cannot invigilate two different rooms simultaneously.");
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
                $timeLabel = date('h:i A', strtotime($startTime)) . '–' . date('h:i A', strtotime($endTime));
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
        redirect('/hod/eligibility.php?module_id=' . $moduleId . '&exam_type=' . $examType);
    } elseif ($action === 'generate') {
        $examType = $_POST['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $count = Eligibility::generate($moduleId, $examType);

        AuditLog::record(Auth::id(), 'ELIGIBILITY_GENERATE', 'modules', $moduleId, "exam_type=$examType;count=$count");
        flash('success', "Generated/refreshed $examType eligibility for $count student(s).");
    } elseif ($action === 'decide' || $action === 'bulk_approve_all') {
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

        if ($action === 'decide') {
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
        } else {
            $pendingStmt = $db->prepare(
                "SELECT ce.*, u.full_name, u.email
                 FROM cat_exam_eligibility ce
                 JOIN users u ON u.user_id = ce.user_id
                 WHERE ce.module_id = :mid AND ce.exam_type = :type AND ce.hod_decision = 'Pending'"
            );
            $pendingStmt->execute(['mid' => $moduleId, 'type' => $examType]);
            $pendingRows = $pendingStmt->fetchAll();

            if ($pendingRows) {
                $db->prepare(
                    "UPDATE cat_exam_eligibility
                     SET hod_decision='Approved', final_decision=system_decision, decided_by=:uid, decided_at=NOW()
                     WHERE module_id = :mid AND exam_type = :type AND hod_decision = 'Pending'"
                )->execute(['uid' => $me['user_id'], 'mid' => $moduleId, 'type' => $examType]);

                if ($schedule) {
                    foreach ($pendingRows as $studentRow) {
                        if (!empty($studentRow['email'])) {
                            Mailer::enqueueEligibilityDecision($studentRow, $schedule, $studentRow['system_decision']);
                        }
                    }
                    Mailer::dispatch();
                }
            }

            AuditLog::record(Auth::id(), 'ELIGIBILITY_DECIDE_BULK', 'modules', $moduleId, "exam_type=$examType;count=" . count($pendingRows));
            flash('success', "Approved " . count($pendingRows) . " pending student(s) and queued eligibility emails.");
        }
    } elseif ($action === 'approve_submission' || $action === 'reject_submission') {
        $examType = $_POST['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $subStmt = $db->prepare('SELECT * FROM module_attendance_submissions WHERE module_id = :mid AND exam_type = :type');
        $subStmt->execute(['mid' => $moduleId, 'type' => $examType]);
        $sub = $subStmt->fetch();
        if (!$sub || $sub['status'] !== 'Pending') {
            flash('error', 'No pending attendance submission found for this module/exam type.');
        } elseif ($action === 'approve_submission') {
            $db->prepare("UPDATE module_attendance_submissions SET status='Approved', decided_by=:uid, decided_at=NOW() WHERE submission_id=:id")
               ->execute(['uid' => $me['user_id'], 'id' => $sub['submission_id']]);
            AuditLog::record(Auth::id(), 'MODULE_ATTENDANCE_SUBMISSION_APPROVE', 'modules', $moduleId, "exam_type=$examType");
            flash('success', "$examType attendance submission approved and locked.");
        } else {
            $note = trim($_POST['decision_note'] ?? '');
            if ($note === '') {
                flash('error', 'A reason is required to reject the submission.');
                redirect('/hod/eligibility.php?module_id=' . $moduleId . '&exam_type=' . $examType);
            }
            $db->prepare("UPDATE module_attendance_submissions SET status='Rejected', decided_by=:uid, decided_at=NOW(), decision_note=:note WHERE submission_id=:id")
               ->execute(['uid' => $me['user_id'], 'note' => $note, 'id' => $sub['submission_id']]);
            AuditLog::record(Auth::id(), 'MODULE_ATTENDANCE_SUBMISSION_REJECT', 'modules', $moduleId, "exam_type=$examType");
            flash('success', "$examType attendance submission rejected. The lecturer can correct and resubmit.");
        }
    }
    redirect('/hod/eligibility.php?module_id=' . $moduleId . '&exam_type=' . ($_POST['exam_type'] ?? $_GET['exam_type'] ?? 'CAT'));
}

$moduleId  = (int) ($_GET['module_id'] ?? 0);
$examType  = ($_GET['exam_type'] ?? 'CAT') === 'Exam' ? 'Exam' : 'CAT';
$viewMode  = ($_GET['view'] ?? 'active') === 'history' ? 'history' : 'active';

// Exam cannot be viewed/scheduled until CAT has been scheduled for the selected module.
$catScheduledForModule = false;
if ($moduleId) {
    $catCheckStmt = $db->prepare("SELECT 1 FROM cat_exam_schedules WHERE module_id = :mid AND exam_type = 'CAT'");
    $catCheckStmt->execute(['mid' => $moduleId]);
    $catScheduledForModule = (bool) $catCheckStmt->fetchColumn();
}
if ($moduleId && $examType === 'Exam' && !$catScheduledForModule) {
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

    $subStmt = $db->prepare(
        "SELECT sub.*, su.full_name AS submitted_by_name FROM module_attendance_submissions sub
         LEFT JOIN users su ON su.user_id = sub.submitted_by
         WHERE sub.module_id = :mid AND sub.exam_type = :type"
    );
    $subStmt->execute(['mid' => $moduleId, 'type' => $examType]);
    $currentSubmission = $subStmt->fetch();
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
        <option value="Exam" <?= $examType === 'Exam' ? 'selected' : '' ?> <?= ($moduleId && !$catScheduledForModule) ? 'disabled' : '' ?>>Exam<?= ($moduleId && !$catScheduledForModule) ? ' (schedule CAT first)' : '' ?></option>
      </select>
    </div>
  </form>
  <?php if ($moduleId && !$catScheduledForModule): ?>
    <p class="text-muted small mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Exam scheduling is locked until a CAT schedule exists for this module.</p>
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
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="bulk_approve_all"><input type="hidden" name="module_id" value="<?= (int) $moduleId ?>"><input type="hidden" name="exam_type" value="<?= e($examType) ?>">
          <button class="btn btn-outline-success btn-sm"><i class="bi bi-check2-all me-1"></i> Approve Pending All</button></form>
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
          &middot; <strong><?= e(substr($currentSchedule['start_time'], 0, 5)) ?>–<?= e(substr($currentSchedule['end_time'], 0, 5)) ?></strong>
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
        Date is taken from the module: <strong><?= e($moduleDate ?: '—') ?></strong>
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

  <!-- Lecturer's "Submit Module Attendance" step -->
  <div class="semas-card p-3 mb-3">
    <p class="text-uppercase text-muted small fw-semibold mb-2" style="letter-spacing:.05em;">Attendance Submission</p>
    <?php if (!$currentSubmission): ?>
      <p class="text-muted small mb-0"><i class="bi bi-hourglass-split me-1"></i>The lecturer has not submitted the <?= e($examType) ?> attendance register yet.</p>
    <?php elseif ($currentSubmission['status'] === 'Pending'): ?>
      <div class="alert alert-warning small mb-0 py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          Submitted by <strong><?= e($currentSubmission['submitted_by_name'] ?? '—') ?></strong>
          on <?= e(date('d M Y, h:i A', strtotime($currentSubmission['submitted_at']))) ?> — awaiting your review.
        </div>
        <div class="d-flex gap-2">
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="approve_submission"><input type="hidden" name="module_id" value="<?= (int) $moduleId ?>"><input type="hidden" name="exam_type" value="<?= e($examType) ?>">
            <button class="btn btn-success btn-sm"><i class="bi bi-check2-circle me-1"></i> Approve Submission</button></form>
          <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectSubmissionModal"><i class="bi bi-x-circle me-1"></i> Reject</button>
        </div>
      </div>
      <div class="modal fade" id="rejectSubmissionModal" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="action" value="reject_submission"><input type="hidden" name="module_id" value="<?= (int) $moduleId ?>"><input type="hidden" name="exam_type" value="<?= e($examType) ?>">
              <div class="modal-header"><h6 class="modal-title display-font">Reject <?= e($examType) ?> Attendance Submission</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
              <div class="modal-body">
                <label class="form-label small">Reason <span class="text-danger">*</span></label>
                <textarea name="decision_note" class="form-control form-control-sm" rows="3" placeholder="e.g. Several sessions missing manual marks for absent students" required></textarea>
              </div>
              <div class="modal-footer"><button class="btn btn-danger btn-sm">Reject &amp; Unlock for Lecturer</button></div>
            </form>
          </div>
        </div>
      </div>
    <?php elseif ($currentSubmission['status'] === 'Approved'): ?>
      <p class="small mb-0"><span class="badge badge-completed"><i class="bi bi-lock-fill me-1"></i>Approved &amp; Locked</span>
        Approved on <?= e(date('d M Y, h:i A', strtotime((string) $currentSubmission['decided_at']))) ?>.</p>
    <?php else: ?>
      <p class="small mb-0"><span class="badge bg-secondary">Rejected</span> <?= e($currentSubmission['decision_note'] ?? '') ?> — the lecturer can correct the register and resubmit.</p>
    <?php endif; ?>
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
<?php endif; // $selectedModule ?>

<?php elseif ($viewMode === 'history'): ?>
<!-- History: Completed modules — read-only view -->
<?php if (!$completedModules): ?>
  <div class="semas-card p-4 text-center text-muted small">No completed modules with CAT/Exam dates found.</div>
<?php else: ?>
  <?php
  $histModId = (int) ($_GET['module_id'] ?? 0);
  $histExamType = ($_GET['exam_type'] ?? 'CAT') === 'Exam' ? 'Exam' : 'CAT';
  ?>
  <?php if (!$histModId): ?>
  <div class="semas-card p-3">
    <p class="small text-muted mb-2">Completed modules (read-only — no actions available):</p>
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
    <small class="text-muted"><?= e($histMod['department_name'] ?? '') ?> · <?= $histExamType ?> Date: <?= e($histExamType==='CAT' ? ($histMod['cat_date']??'—') : ($histMod['exam_date']??'—')) ?></small>
  </div>
  <div class="semas-card p-3">
    <div class="alert alert-secondary small py-2 mb-3"><i class="bi bi-eye me-1"></i>History view — read only. No changes can be made to completed modules.</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Student</th><th>Reg No.</th><th>Missed</th><th>System Decision</th><th>HOD Status</th><th>Final</th></tr></thead>
        <tbody>
          <?php foreach ($histList as $row): ?>
          <tr>
            <td><?= e($row['full_name']) ?></td>
            <td><?= e($row['reg_number'] ?? '—') ?></td>
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
