<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$pageTitle = 'My Assignments';
$activeNav = 'modules';
$db = Database::connection();
$me = Auth::user();

$moduleId = (int) ($_GET['module_id'] ?? 0);

if (!$moduleId) {
    // Generic landing: every assignment across every module the student is registered in.
    $allStmt = $db->prepare(
        "SELECT a.*, m.module_title, m.module_id, s.file_path AS my_file, s.submitted_at AS my_submitted_at
         FROM assignments a
         JOIN modules m ON m.module_id = a.module_id AND m.status = 'Ongoing'
         JOIN module_enrollments e ON e.module_id = m.module_id AND e.user_id = :uid
         LEFT JOIN assignment_submissions s ON s.assignment_id = a.assignment_id AND s.user_id = :uid2
         ORDER BY a.deadline ASC"
    );
    $allStmt->execute(['uid' => $me['user_id'], 'uid2' => $me['user_id']]);
    $allAssignments = $allStmt->fetchAll();

    $pageTitle = 'My Assignments';
    $activeNav = 'assignments';
    require __DIR__ . '/../partials/layout_top.php';
    ?>
    <h4 class="display-font mb-1">My Assignments</h4>
    <p class="text-muted small mb-4">Across every module you're registered in. Open a module's page for full instructions and to submit.</p>
    <?php foreach ($allAssignments as $a): $closed = strtotime($a['deadline']) < time(); ?>
      <div class="semas-card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-start">
          <div><h6 class="display-font mb-0"><?= e($a['title']) ?></h6><p class="text-muted small mb-0"><?= e($a['module_title']) ?></p></div>
          <span class="badge <?= $closed ? 'bg-secondary' : 'badge-completed' ?>"><?= $closed ? 'Closed' : 'Open' ?></span>
        </div>
        <p class="small mt-2 mb-1">Deadline: <?= e(date('d M Y, h:i A', strtotime($a['deadline']))) ?></p>
        <?php if ($a['my_file']): ?><p class="small text-success mb-1"><i class="bi bi-check-circle-fill"></i> Submitted</p><?php endif; ?>
        <a href="<?= APP_URL ?>/student/assignments.php?module_id=<?= (int) $a['module_id'] ?>" class="small">Open module &rarr;</a>
      </div>
    <?php endforeach; ?>
    <?php if (!$allAssignments): ?><div class="semas-card p-4 text-center text-muted small">No assignments posted in any of your registered modules yet.</div><?php endif; ?>
    <?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
    <?php
    exit;
}

$enrolled = $db->prepare('SELECT m.* FROM modules m JOIN module_enrollments e ON e.module_id = m.module_id WHERE m.module_id = :id AND e.user_id = :uid');
$enrolled->execute(['id' => $moduleId, 'uid' => $me['user_id']]);
$module = $enrolled->fetch();

if (!$module) {
    require __DIR__ . '/../partials/layout_top.php';
    echo '<div class="alert alert-warning small">You are not registered for this module. <a href="' . APP_URL . '/student/modules.php">Go to Module Registration</a></div>';
    require __DIR__ . '/../partials/layout_bottom.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $assignmentId = (int) $_POST['assignment_id'];
    $assignStmt = $db->prepare('SELECT * FROM assignments WHERE assignment_id = :id AND module_id = :mid');
    $assignStmt->execute(['id' => $assignmentId, 'mid' => $moduleId]);
    $assignment = $assignStmt->fetch();

    if (!$assignment) {
        flash('error', 'Assignment not found.');
    } elseif (strtotime($assignment['deadline']) < time()) {
        flash('error', 'The deadline for this assignment has passed. Submissions are no longer accepted.');
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Please choose a file to submit.');
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
        finfo_close($finfo);
        $allowed = ['application/pdf' => 'pdf', 'application/zip' => 'zip', 'application/x-zip-compressed' => 'zip'];
        if (!isset($allowed[$mime]) || $_FILES['file']['size'] > 10 * 1024 * 1024) {
            flash('error', 'Only PDF or ZIP files under 10MB are accepted.');
        } else {
            $filename = 'sub' . $me['user_id'] . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
            $dest = __DIR__ . '/../uploads/assignments/' . $filename;
            if (!is_dir(dirname($dest))) { mkdir(dirname($dest), 0755, true); }
            move_uploaded_file($_FILES['file']['tmp_name'], $dest);
            $relPath = 'uploads/assignments/' . $filename;

            $existing = $db->prepare('SELECT submission_id FROM assignment_submissions WHERE assignment_id = :a AND user_id = :u');
            $existing->execute(['a' => $assignmentId, 'u' => $me['user_id']]);
            if ($existing->fetch()) {
                $db->prepare('UPDATE assignment_submissions SET file_path = :p, submitted_at = NOW() WHERE assignment_id = :a AND user_id = :u')
                   ->execute(['p' => $relPath, 'a' => $assignmentId, 'u' => $me['user_id']]);
                flash('success', 'Your submission has been updated.');
            } else {
                $db->prepare('INSERT INTO assignment_submissions (assignment_id, user_id, file_path) VALUES (:a, :u, :p)')
                   ->execute(['a' => $assignmentId, 'u' => $me['user_id'], 'p' => $relPath]);
                flash('success', 'Assignment submitted.');
            }
            AuditLog::record(Auth::id(), 'ASSIGNMENT_SUBMIT', 'assignments', $assignmentId);
        }
    }
    redirect('/student/assignments.php?module_id=' . $moduleId);
}

$assignments = $db->prepare(
    "SELECT a.*, s.file_path AS my_file, s.submitted_at AS my_submitted_at
     FROM assignments a LEFT JOIN assignment_submissions s ON s.assignment_id = a.assignment_id AND s.user_id = :uid
     WHERE a.module_id = :mid ORDER BY a.deadline ASC"
);
$assignments->execute(['uid' => $me['user_id'], 'mid' => $moduleId]);
$assignments = $assignments->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Assignments — <?= e($module['module_title']) ?></h4>
<p class="text-muted small mb-4">Submit PDF or ZIP files only. No submissions are accepted after the deadline.</p>

<?php foreach ($assignments as $a): $closed = strtotime($a['deadline']) < time(); ?>
  <div class="semas-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-start">
      <h6 class="display-font mb-1"><?= e($a['title']) ?></h6>
      <span class="badge <?= $closed ? 'bg-secondary' : 'badge-completed' ?>"><?= $closed ? 'Closed' : 'Open' ?></span>
    </div>
    <p class="text-muted small mb-2"><?= e($a['instructions'] ?? '') ?></p>
    <p class="small mb-2">Deadline: <?= e(date('d M Y, h:i A', strtotime($a['deadline']))) ?>
      <?php if ($a['attachment_path']): ?> &middot; <a href="<?= APP_URL . '/' . e($a['attachment_path']) ?>" target="_blank">Lecturer's attachment</a><?php endif; ?>
    </p>
    <?php if ($a['my_file']): ?>
      <p class="small text-success mb-2"><i class="bi bi-check-circle-fill"></i> Submitted <?= e(date('d M Y, h:i A', strtotime($a['my_submitted_at']))) ?> — <a href="<?= APP_URL . '/' . e($a['my_file']) ?>" target="_blank">View your file</a></p>
    <?php endif; ?>
    <?php if (!$closed): ?>
      <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end">
        <?= csrf_field() ?><input type="hidden" name="assignment_id" value="<?= (int) $a['assignment_id'] ?>">
        <input type="file" name="file" accept=".pdf,.zip" class="form-control form-control-sm" required>
        <button class="btn btn-sm btn-semas-gold text-nowrap"><?= $a['my_file'] ? 'Resubmit' : 'Submit' ?></button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$assignments): ?><div class="semas-card p-4 text-center text-muted small">No assignments posted for this module yet.</div><?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
