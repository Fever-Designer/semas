<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$db = Database::connection();
$me = Auth::user();
$assignmentId = (int) ($_GET['assignment_id'] ?? 0);
$type = (string) ($_GET['type'] ?? 'attachment');

$assignment = $db->prepare(
    "SELECT a.assignment_id, a.title, a.attachment_path
     FROM assignments a
     JOIN module_enrollments me ON me.module_id = a.module_id
     WHERE a.assignment_id = :assignment_id AND me.user_id = :user_id
     LIMIT 1"
);
$assignment->execute([
    'assignment_id' => $assignmentId,
    'user_id' => (int) $me['user_id'],
]);
$assignmentRow = $assignment->fetch();
if (!$assignmentRow) {
    http_response_code(404);
    die('Assignment not found.');
}

if ($type === 'submission') {
    $submission = $db->prepare(
        'SELECT file_path FROM assignment_submissions
         WHERE assignment_id = :assignment_id AND user_id = :user_id'
    );
    $submission->execute([
        'assignment_id' => $assignmentId,
        'user_id' => (int) $me['user_id'],
    ]);
    $relativePath = (string) ($submission->fetchColumn() ?: '');
} else {
    $relativePath = (string) ($assignmentRow['attachment_path'] ?? '');
}

$uploadsRoot = realpath(__DIR__ . '/../uploads/assignments');
$fullPath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));
if ($uploadsRoot === false || $fullPath === false
    || !str_starts_with($fullPath, $uploadsRoot . DIRECTORY_SEPARATOR)
    || !is_file($fullPath)) {
    http_response_code(404);
    die('File not found.');
}

$downloadName = basename($fullPath);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, '"\\') . '"');
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
exit;
