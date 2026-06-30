<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();

$pageTitle = 'Assignment Submissions';
$activeNav = 'modules';

$db = Database::connection();
$me = Auth::user();

$lecStmt = $db->prepare('SELECT * FROM lecturers WHERE user_id = :uid');
$lecStmt->execute(['uid' => $me['user_id']]);
$lecturer = $lecStmt->fetch();

if (!$lecturer) {
    die('Lecturer not found.');
}

$lecturerId   = (int) $lecturer['lecturer_id'];
$assignmentId = (int) ($_GET['assignment_id'] ?? 0);

$stmt = $db->prepare(
    "SELECT a.*, m.module_title, m.module_id
     FROM assignments a
     JOIN modules m ON m.module_id = a.module_id
     WHERE a.assignment_id = :aid AND m.lecturer_id = :lec"
);
$stmt->execute(['aid' => $assignmentId, 'lec' => $lecturerId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    require __DIR__ . '/../partials/layout_top.php';
    echo '<div class="alert alert-warning small">
        Assignment not found or not assigned to you.
        <a href="' . APP_URL . '/lecturer/modules.php">Back</a>
    </div>';
    require __DIR__ . '/../partials/layout_bottom.php';
    exit;
}

$moduleId = (int) $assignment['module_id'];

$subStmt = $db->prepare(
    "SELECT u.user_id, u.full_name, u.reg_number, s.file_path, s.submitted_at
     FROM users u
     JOIN module_enrollments e ON e.user_id = u.user_id
     LEFT JOIN assignment_submissions s ON s.assignment_id = :aid AND s.user_id = u.user_id
     WHERE e.module_id = :mid AND u.status = 'Active'
     ORDER BY u.full_name"
);
$subStmt->execute(['aid' => $assignmentId, 'mid' => $moduleId]);
$students = $subStmt->fetchAll();

$submittedCount = 0;
foreach ($students as $s) {
    if ($s['file_path']) $submittedCount++;
}

require __DIR__ . '/../partials/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h4 class="display-font mb-1"><?= e($assignment['title']) ?></h4>
        <p class="text-muted small mb-0"><?= e($assignment['module_title']) ?> &middot; <?= $submittedCount ?> / <?= count($students) ?> submitted</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/lecturer/assignments.php?module_id=<?= $moduleId ?>" class="btn btn-sm btn-outline-dark">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <?php if ($submittedCount > 0): ?>
        <a href="<?= APP_URL ?>/lecturer/assignment-download.php?assignment_id=<?= $assignmentId ?>&all=1" class="btn btn-sm btn-semas-gold">
            <i class="bi bi-file-earmark-zip me-1"></i> Download All (ZIP)
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="semas-card p-3">
    <div class="table-responsive">
        <table class="table table-sm align-middle" style="font-size:.85rem;">
            <thead>
                <tr><th>Student</th><th>Reg No.</th><th>Status</th><th>Submitted</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td class="fw-semibold"><?= e($s['full_name']) ?></td>
                    <td class="text-muted"><?= e($s['reg_number'] ?? '—') ?></td>
                    <td>
                        <?php if ($s['file_path']): ?>
                            <span class="badge badge-completed">Submitted</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Not submitted</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= $s['submitted_at'] ? e(date('d M Y, H:i', strtotime($s['submitted_at']))) : '—' ?></td>
                    <td>
                        <?php if ($s['file_path']): ?>
                            <a href="<?= APP_URL ?>/lecturer/assignment-download.php?assignment_id=<?= $assignmentId ?>&user_id=<?= (int) $s['user_id'] ?>"
                               class="btn btn-sm btn-outline-dark">
                                <i class="bi bi-download me-1"></i> Download
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$students): ?>
                <tr><td colspan="5" class="text-center text-muted">No enrolled students.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
