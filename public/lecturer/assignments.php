<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();

$pageTitle = 'Assignments';
$activeNav = 'modules';

$db = Database::connection();
$me = Auth::user();

/**
 * Default Instructions
 */
$defaultAssignmentInstructions = "📘 Assignment Submission Instructions

• Complete your work individually without using automated writing tools or copied content.
• Ensure all submissions are made before the stated deadline.
• Only PDF or ZIP file formats are accepted.
• Rename your file properly using your full name and registration number.
• Plagiarism will lead to penalties.";

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

        $instructions = trim($_POST['instructions'] ?? '');
        if ($instructions === '') {
            $instructions = $defaultAssignmentInstructions;
        }

        $attachmentPath = null;

        /**
         * File Upload
         */
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['attachment']['tmp_name']);
            finfo_close($finfo);

            $allowed = [
                'application/pdf' => 'pdf',
                'application/zip' => 'zip',
                'application/x-zip-compressed' => 'zip'
            ];

            if (isset($allowed[$mime]) && $_FILES['attachment']['size'] <= 10 * 1024 * 1024) {

                $filename = 'assign' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                $dest = __DIR__ . '/../uploads/assignments/' . $filename;

                if (!is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0755, true);
                }

                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
                    $attachmentPath = 'uploads/assignments/' . $filename;
                }
            }
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

        flash('success', 'Assignment created successfully.');
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

</div>

<?php endforeach; ?>

<?php if (!$assignments): ?>
<div class="semas-card p-3 text-center text-muted">No assignments found.</div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>