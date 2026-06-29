<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Lecturer']);

$pageTitle = 'Assignments';
$activeNav = 'modules';
$db = Database::connection();
$me = Auth::user();
$defaultAssignmentInstructions = "Assignment Submission Instructions\n\n• Complete your work individually without using automated writing tools or copied content.\n• Ensure all submissions are made before the stated deadline. Late submissions may not be accepted.\n• Only PDF or ZIP file formats will be accepted for submission.\n• Rename your file properly using your full name and registration number before uploading.\n• Any form of plagiarism or dishonest academic practice will lead to penalties according to university rules.";

$lecStmt = $db->prepare('SELECT * FROM lecturers WHERE user_id = :uid');
$lecStmt->execute(['uid' => $me['user_id']]);
$lecturer = $lecStmt->fetch();

$moduleId = (int) ($_GET['module_id'] ?? 0);
$modStmt = $db->prepare('SELECT * FROM modules WHERE module_id = :id AND lecturer_id = :lec');
$modStmt->execute(['id' => $moduleId, 'lec' => $lecturer['lecturer_id'] ?? 0]);
$module = $modStmt->fetch();

if (!$module) {
    require __DIR__ . '/../partials/layout_top.php';
    echo '<div class="alert alert-warning small">Module not found, or it is not assigned to you. <a href="' . APP_URL . '/lecturer/modules.php">Back to My Modules</a></div>';
    require __DIR__ . '/../partials/layout_bottom.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $attachmentPath = null;
        $instructions = trim($_POST['instructions'] ?? '');
        if ($instructions === '') {
            $instructions = $defaultAssignmentInstructions;
        }
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['attachment']['tmp_name']);
            finfo_close($finfo);
            $allowed = ['application/pdf' => 'pdf', 'application/zip' => 'zip', 'application/x-zip-compressed' => 'zip'];
            if (isset($allowed[$mime]) && $_FILES['attachment']['size'] <= 10 * 1024 * 1024) {
                $filename = 'assign' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                $dest = __DIR__ . '/../uploads/assignments/' . $filename;
                if (!is_dir(dirname($dest))) { mkdir(dirname($dest), 0755, true); }
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
                    $attachmentPath = 'uploads/assignments/' . $filename;
                }
            }
        }
        $db->prepare(
            'INSERT INTO assignments (module_id, title, instructions, attachment_path, deadline, created_by)
             VALUES (:mid, :title, :instr, :att, :deadline, :uid)'
        )->execute([
            'mid' => $moduleId, 'title' => trim($_POST['title']), 'instr' => $instructions,
            'att' => $attachmentPath, 'deadline' => $_POST['deadline'], 'uid' => $me['user_id'],
        ]);
        $assignmentId = (int) $db->lastInsertId();
        AuditLog::record(Auth::id(), 'ASSIGNMENT_CREATE', 'assignments', $assignmentId);

        $assignment = [
            'assignment_id'   => $assignmentId,
            'title'           => trim($_POST['title']),
            'instructions'    => $instructions,
            'attachment_path' => $attachmentPath,
            'deadline'        => $_POST['deadline'],
        ];
        $lecNameStmt = $db->prepare('SELECT u.full_name FROM users u WHERE u.user_id = :uid');
        $lecNameStmt->execute(['uid' => $me['user_id']]);
        $lecName = (string) ($lecNameStmt->fetchColumn() ?: $me['full_name'] ?? 'Lecturer');

        $enrolledStmt = $db->prepare("SELECT u.user_id, u.full_name, u.email FROM users u JOIN module_enrollments e ON e.user_id = u.user_id WHERE e.module_id = :mid AND u.status = 'Active'");
        $enrolledStmt->execute(['mid' => $moduleId]);
        foreach ($enrolledStmt->fetchAll() as $student) {
            NotificationCenter::notify((int) $student['user_id'], 'New assignment: ' . $assignment['title'], 'Due ' . date('d M Y, h:i A', strtotime($assignment['deadline'])) . ' for ' . $module['module_title'] . '.', 'Assignment');
            if (!empty($student['email'])) {
                Mailer::enqueueAssignmentNotification($student, $assignment, $module, $lecName);
            }
        }
        Mailer::dispatch();
        flash('success', 'Assignment created and registered students notified.');
    } elseif ($action === 'extend') {
        $assignmentId = (int) $_POST['assignment_id'];
        $db->prepare('UPDATE assignments SET deadline = :d WHERE assignment_id = :id AND module_id = :mid')
           ->execute(['d' => $_POST['new_deadline'], 'id' => $assignmentId, 'mid' => $moduleId]);
        AuditLog::record(Auth::id(), 'ASSIGNMENT_EXTEND_DEADLINE', 'assignments', $assignmentId);
        flash('success', 'Deadline extended.');
    }
    redirect('/lecturer/assignments.php?module_id=' . $moduleId);
}

$assignments = $db->prepare(
    "SELECT a.*, (SELECT COUNT(*) FROM assignment_submissions s WHERE s.assignment_id = a.assignment_id) AS submission_count
     FROM assignments a WHERE a.module_id = :mid ORDER BY a.created_at DESC"
);
$assignments->execute(['mid' => $moduleId]);
$assignments = $assignments->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div><h4 class="display-font mb-1">Assignments — <?= e($module['module_title']) ?></h4></div>
  <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#newAssignmentModal"><i class="bi bi-plus-circle me-1"></i> New Assignment</button>
</div>

<?php foreach ($assignments as $a): ?>
  <div class="semas-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-start">
      <h6 class="display-font mb-1"><?= e($a['title']) ?></h6>
      <span class="badge <?= strtotime($a['deadline']) < time() ? 'bg-secondary' : 'badge-completed' ?>">
        <?= strtotime($a['deadline']) < time() ? 'Closed' : 'Open' ?>
      </span>
    </div>
    <?php $assignmentInstructions = $a['instructions'] ?: $defaultAssignmentInstructions; ?>
    <p class="text-muted small mb-2"><?= nl2br(e($assignmentInstructions)) ?></p>
    <p class="small mb-2">Deadline: <?= e((string) date('d M Y, h:i A', strtotime((string) ($a['deadline'] ?? '')))) ?> &middot; <?= (int) $a['submission_count'] ?> submission(s)
      <?php if ($a['attachment_path']): ?> &middot; <a href="<?= APP_URL . '/' . e($a['attachment_path']) ?>" target="_blank">Attachment</a><?php endif; ?>
    </p>
    <details>
      <summary class="small text-muted" style="cursor:pointer;">View submissions</summary>
      <?php
        $subs = $db->prepare("SELECT s.*, u.full_name, u.reg_number FROM assignment_submissions s JOIN users u ON u.user_id = s.user_id WHERE s.assignment_id = :id ORDER BY s.submitted_at DESC");
        $subs->execute(['id' => $a['assignment_id']]);
        $subs = $subs->fetchAll();
      ?>
      <?php if (!$subs): ?><p class="text-muted small mt-2">No submissions yet.</p><?php endif; ?>
      <?php foreach ($subs as $s): ?>
        <div class="d-flex justify-content-between border-bottom py-1 small">
          <span><?= e($s['full_name']) ?> (<?= e($s['reg_number'] ?? '—') ?>) &middot; <?= e((string) date('d M Y H:i', strtotime((string) ($s['submitted_at'] ?? '')))) ?></span>
          <a href="<?= APP_URL . '/' . e($s['file_path']) ?>" target="_blank">Download</a>
        </div>
      <?php endforeach; ?>
    </details>
    <form method="post" class="mt-2 d-flex gap-2 align-items-end">
      <?= csrf_field() ?><input type="hidden" name="action" value="extend"><input type="hidden" name="assignment_id" value="<?= (int) $a['assignment_id'] ?>">
      <div><label class="form-label small mb-0">Extend deadline to</label><input type="datetime-local" name="new_deadline" class="form-control form-control-sm"></div>
      <button class="btn btn-sm btn-outline-dark">Extend</button>
    </form>
  </div>
<?php endforeach; ?>
<?php if (!$assignments): ?><div class="semas-card p-4 text-center text-muted small">No assignments yet for this module.</div><?php endif; ?>

<div class="modal fade" id="newAssignmentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header"><h6 class="modal-title display-font">New Assignment</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label small">Title</label><input name="title" class="form-control form-control-sm" required></div>
          <div class="alert alert-light border small mb-2">
            <i class="bi bi-info-circle me-1"></i> SEMAS will attach the standard submission instructions automatically for every assignment.
          </div>
          <div class="mb-2"><label class="form-label small">Deadline</label><input type="datetime-local" name="deadline" class="form-control form-control-sm" required></div>
          <div class="mb-2"><label class="form-label small">Attachment (PDF/ZIP, optional)</label><input type="file" name="attachment" accept=".pdf,.zip" class="form-control form-control-sm"></div>
        </div>
        <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Create</button></div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
