<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();

$pageTitle = 'Assignments';
$activeNav = 'modules';

$db = Database::connection();
Semester::enforceAcademicWrite($db);
$me = Auth::user();

/**
 * Default Instructions
 */
$defaultAssignmentInstructions = "Assignment Submission Instructions

1. Submit your own original work. Do not copy work submitted by another student; this is cheating.
2. AI-generated or AI-assisted content must not exceed 20% of the submitted work.
3. Name the file using your full name and registration number (for example: Firstname_Lastname_RegNo.pdf). SEMAS saves submissions under this required naming format.
4. Submit only a PDF or ZIP file with a maximum size of 10 MB.
5. Submit before the stated deadline. Late submissions are not accepted.
6. Review the assignment instructions and attachment before submitting.
7. Plagiarism, copied submissions, false declarations, and other academic misconduct may lead to disciplinary action.";

/**
 * Get Lecturer safely
 */
$lecStmt = $db->prepare('SELECT * FROM lecturers WHERE user_id = :uid');
$lecStmt->execute(['uid' => $me['user_id']]);
$lecturer = $lecStmt->fetch();

if (!$lecturer) {
    die('Lecturer not found.');
}

$lecturerId = (int) $lecturer['lecturer_id'];

function assignmentUploadPath(array $file, bool $required): ?string
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new RuntimeException('Assignment file is required.');
        }
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Please choose the file again.');
    }
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('Assignment file must be 10 MB or smaller.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip'
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only PDF or ZIP assignment files are allowed.');
    }

    $filename = 'assign' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $dest = __DIR__ . '/../uploads/assignments/' . $filename;
    if (!is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0755, true);
    }
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save the uploaded assignment file.');
    }

    return 'uploads/assignments/' . $filename;
}

/**
 * Get module safely
 */
$moduleId = (int) ($_GET['module_id'] ?? 0);

$modStmt = $db->prepare('SELECT * FROM modules WHERE module_id = :id AND lecturer_id = :lec');
$modStmt->execute([
    'id' => $moduleId,
    'lec' => $lecturerId
]);

$module = $modStmt->fetch();

if (!$module) {
    require __DIR__ . '/../partials/layout_top.php';
    echo '<div class="alert alert-warning small">
        Module not found or not assigned to you.
        <a href="' . APP_URL . '/lecturer/modules.php">Back</a>
    </div>';
    require __DIR__ . '/../partials/layout_bottom.php';
    exit;
}

/**
 * POST HANDLER
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? '';

    /**
     * CREATE ASSIGNMENT
     */
    if ($action === 'create') {

        $title = trim($_POST['title'] ?? '');
        $deadline = $_POST['deadline'] ?? '';

        if ($title === '' || $deadline === '') {
            flash('error', 'Title and deadline are required.');
            redirect('/lecturer/assignments.php?module_id=' . $moduleId);
        }

        // Assignment instructions are fixed globally by SEMAS and not set per assignment.
        $instructions = '';
        try {
            $attachmentPath = assignmentUploadPath($_FILES['attachment'] ?? [], true);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/lecturer/assignments.php?module_id=' . $moduleId);
        }

        /**
         * Insert Assignment
         */
        $db->prepare(
            'INSERT INTO assignments (module_id, title, instructions, attachment_path, deadline, created_by)
             VALUES (:mid, :title, :instr, :att, :deadline, :uid)'
        )->execute([
            'mid' => $moduleId,
            'title' => $title,
            'instr' => $instructions,
            'att' => $attachmentPath,
            'deadline' => $deadline,
            'uid' => $me['user_id'],
        ]);

        $assignmentId = (int) $db->lastInsertId();

        AuditLog::record(Auth::id(), 'ASSIGNMENT_CREATE', 'assignments', $assignmentId);

        // Notify enrolled students of the new assignment.
        $studentStmt = $db->prepare(
            'SELECT u.* FROM users u
             JOIN module_enrollments e ON e.user_id = u.user_id
             WHERE e.module_id = :mid AND u.status = :status'
        );
        $studentStmt->execute(['mid' => $moduleId, 'status' => 'Active']);
        $students = $studentStmt->fetchAll();
        $assignmentPayload = [
            'assignment_id'   => $assignmentId,
            'title'           => $title,
            'instructions'    => $instructions,
            'attachment_path' => $attachmentPath,
            'deadline'        => $deadline,
        ];
        foreach ($students as $student) {
            Mailer::enqueueAssignmentNotification($student, $assignmentPayload, $module, $me['full_name']);
        }
        Mailer::dispatch();

        flash('success', 'Assignment created successfully. Students have been notified.');
        redirect('/lecturer/assignments.php?module_id=' . $moduleId);
    }

    /**
     * EDIT ASSIGNMENT
     */
    if ($action === 'edit') {

        $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $deadline = $_POST['deadline'] ?? '';

        if (!$assignmentId || $title === '' || $deadline === '') {
            flash('error', 'Title and deadline are required.');
            redirect('/lecturer/assignments.php?module_id=' . $moduleId);
        }

        $ownStmt = $db->prepare('SELECT attachment_path FROM assignments WHERE assignment_id = :id AND module_id = :mid');
        $ownStmt->execute(['id' => $assignmentId, 'mid' => $moduleId]);
        $existingAssignment = $ownStmt->fetch();
        if (!$existingAssignment) {
            flash('error', 'Assignment not found.');
            redirect('/lecturer/assignments.php?module_id=' . $moduleId);
        }

        try {
            $newAttachmentPath = assignmentUploadPath($_FILES['attachment'] ?? [], false);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/lecturer/assignments.php?module_id=' . $moduleId);
        }

        if ($newAttachmentPath) {
            $db->prepare(
                'UPDATE assignments SET title = :title, deadline = :deadline, attachment_path = :att WHERE assignment_id = :id AND module_id = :mid'
            )->execute([
                'title' => $title,
                'deadline' => $deadline,
                'att' => $newAttachmentPath,
                'id' => $assignmentId,
                'mid' => $moduleId,
            ]);
        } else {
            $db->prepare(
                'UPDATE assignments SET title = :title, deadline = :deadline WHERE assignment_id = :id AND module_id = :mid'
            )->execute([
                'title' => $title,
                'deadline' => $deadline,
                'id' => $assignmentId,
                'mid' => $moduleId,
            ]);
        }

        AuditLog::record(Auth::id(), 'ASSIGNMENT_EDIT', 'assignments', $assignmentId);
        flash('success', 'Assignment updated.');
        redirect('/lecturer/assignments.php?module_id=' . $moduleId);
    }

    /**
     * EXTEND DEADLINE
     */
    if ($action === 'extend') {

        $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
        $newDeadline = $_POST['new_deadline'] ?? '';

        if ($assignmentId && $newDeadline) {
            $db->prepare(
                'UPDATE assignments SET deadline = :d WHERE assignment_id = :id AND module_id = :mid'
            )->execute([
                'd' => $newDeadline,
                'id' => $assignmentId,
                'mid' => $moduleId
            ]);

            AuditLog::record(Auth::id(), 'ASSIGNMENT_EXTEND_DEADLINE', 'assignments', $assignmentId);

            flash('success', 'Deadline updated.');
        }

        redirect('/lecturer/assignments.php?module_id=' . $moduleId);
    }
}

/**
 * GET ASSIGNMENTS
 */
$stmt = $db->prepare(
    "SELECT a.*,
        (SELECT COUNT(*) FROM assignment_submissions s WHERE s.assignment_id = a.assignment_id) AS submission_count
     FROM assignments a
     WHERE a.module_id = :mid
     ORDER BY a.created_at DESC"
);
$stmt->execute(['mid' => $moduleId]);
$assignments = $stmt->fetchAll();

/**
 * ENROLLED STUDENTS
 */
$enrolledStmt = $db->prepare(
    "SELECT u.user_id, u.full_name, u.reg_number
     FROM users u
     JOIN module_enrollments e ON e.user_id = u.user_id
     WHERE e.module_id = :mid AND u.status = 'Active'
     ORDER BY u.full_name"
);
$enrolledStmt->execute(['mid' => $moduleId]);
$enrolledStudents = $enrolledStmt->fetchAll();

/**
 * VIEW
 */
require __DIR__ . '/../partials/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3">
    <h4 class="display-font mb-1">Assignments / <?= e($module['module_title']) ?></h4>

    <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#newAssignmentModal">
        New Assignment
    </button>
</div>

<?php foreach ($assignments as $a): ?>

<?php
$deadlineTime = !empty($a['deadline']) ? strtotime($a['deadline']) : null;
$assignmentInstructions = trim($a['instructions'] ?? '');
if ($assignmentInstructions === '') {
    $assignmentInstructions = $defaultAssignmentInstructions;
}
?>

<div class="semas-card p-3 mb-3">

    <div class="d-flex justify-content-between">
        <h6><?= e($a['title']) ?></h6>
        <span class="badge <?= ($deadlineTime && $deadlineTime < time()) ? 'bg-secondary' : 'badge-completed' ?>">
            <?= ($deadlineTime && $deadlineTime < time()) ? 'Closed' : 'Open' ?>
        </span>
    </div>

    <p class="small text-muted"><?= nl2br(e($assignmentInstructions)) ?></p>

    <p class="small">
        Deadline: <?= e((string) date('d M Y, H:i', (int) $deadlineTime)) ?>
        • <?= (int) $a['submission_count'] ?> submissions
    </p>

    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/lecturer/assignment-submissions.php?assignment_id=<?= (int) $a['assignment_id'] ?>&module_id=<?= $moduleId ?>"
           class="btn btn-sm btn-outline-dark">
            <i class="bi bi-folder2-open me-1"></i> View / Download Submissions
        </a>
        <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#editAssignment-<?= (int) $a['assignment_id'] ?>">
            <i class="bi bi-pencil me-1"></i> Edit
        </button>
    </div>

</div>

<div class="modal fade" id="editAssignment-<?= (int) $a['assignment_id'] ?>" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title display-font">Edit Assignment</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="assignment_id" value="<?= (int) $a['assignment_id'] ?>">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small">Assignment Title</label>
            <input name="title" class="form-control" required value="<?= e($a['title']) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label small">Deadline</label>
            <input name="deadline" type="datetime-local" class="form-control" required value="<?= e(date('Y-m-d\TH:i', strtotime((string) $a['deadline']))) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label small">Instructions</label>
            <div class="form-control form-control-light small text-muted" style="min-height:120px;">
              <?= nl2br(e(trim($defaultAssignmentInstructions))) ?>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small">Replace Attachment</label>
            <input name="attachment" type="file" class="form-control" accept=".pdf,.zip">
            <small class="text-muted">Leave empty to keep the current file. PDF or ZIP up to 10 MB.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-semas-gold btn-sm">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endforeach; ?>

<?php if (!$assignments): ?>
<div class="semas-card p-3 text-center text-muted">No assignments found.</div>
<?php endif; ?>

<div class="modal fade" id="newAssignmentModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title display-font">New Assignment</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small">Assignment Title</label>
            <input name="title" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label small">Deadline</label>
            <input name="deadline" type="datetime-local" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label small">Instructions</label>
            <div class="form-control form-control-light small text-muted" style="min-height:120px;">
              <?= nl2br(e(trim($defaultAssignmentInstructions))) ?>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small">Assignment File <span class="text-danger">*</span></label>
            <input name="attachment" type="file" class="form-control" accept=".pdf,.zip" required>
            <small class="text-muted">Upload PDF or ZIP up to 10 MB.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-semas-gold btn-sm">Create Assignment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
