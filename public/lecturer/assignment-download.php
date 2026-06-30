<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();

$db = Database::connection();
$me = Auth::user();

$lecStmt = $db->prepare('SELECT * FROM lecturers WHERE user_id = :uid');
$lecStmt->execute(['uid' => $me['user_id']]);
$lecturer = $lecStmt->fetch();

if (!$lecturer) {
    http_response_code(403);
    die('Lecturer not found.');
}

$lecturerId   = (int) $lecturer['lecturer_id'];
$assignmentId = (int) ($_GET['assignment_id'] ?? 0);

$stmt = $db->prepare(
    "SELECT a.*, m.module_title, m.lecturer_id
     FROM assignments a
     JOIN modules m ON m.module_id = a.module_id
     WHERE a.assignment_id = :aid AND m.lecturer_id = :lec"
);
$stmt->execute(['aid' => $assignmentId, 'lec' => $lecturerId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    http_response_code(403);
    die('Assignment not found or not assigned to you.');
}

$uploadsRoot = __DIR__ . '/../uploads/assignments/';

function safe_name(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9_\-]+/', '_', trim($name));
    return trim((string) $name, '_') ?: 'student';
}

// ── Single-file download ────────────────────────────────────────────────────
if (!empty($_GET['user_id'])) {
    $userId = (int) $_GET['user_id'];

    $subStmt = $db->prepare(
        "SELECT s.file_path, u.full_name, u.reg_number
         FROM assignment_submissions s
         JOIN users u ON u.user_id = s.user_id
         WHERE s.assignment_id = :aid AND s.user_id = :uid"
    );
    $subStmt->execute(['aid' => $assignmentId, 'uid' => $userId]);
    $sub = $subStmt->fetch();

    if (!$sub || !$sub['file_path']) {
        http_response_code(404);
        die('Submission not found.');
    }

    $fullPath = __DIR__ . '/../' . $sub['file_path'];
    if (!is_file($fullPath)) {
        http_response_code(404);
        die('Submission file is missing on disk.');
    }

    $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
    $downloadName = safe_name($sub['full_name']) . '_' . safe_name((string) ($sub['reg_number'] ?? '')) . '.' . $ext;

    AuditLog::record(Auth::id(), 'ASSIGNMENT_SUBMISSION_DOWNLOAD', 'assignment_submissions', $assignmentId);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}

// ── Bulk ZIP download ───────────────────────────────────────────────────────
if (!empty($_GET['all'])) {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        die('ZIP support is not available on this server.');
    }

    $subStmt = $db->prepare(
        "SELECT s.file_path, u.full_name, u.reg_number
         FROM assignment_submissions s
         JOIN users u ON u.user_id = s.user_id
         WHERE s.assignment_id = :aid"
    );
    $subStmt->execute(['aid' => $assignmentId]);
    $subs = $subStmt->fetchAll();

    if (!$subs) {
        http_response_code(404);
        die('No submissions to download.');
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'semas_assign_');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        die('Could not create ZIP archive.');
    }

    $usedNames = [];
    foreach ($subs as $sub) {
        if (!$sub['file_path']) continue;
        $fullPath = __DIR__ . '/../' . $sub['file_path'];
        if (!is_file($fullPath)) continue;

        $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
        $entryName = safe_name($sub['full_name']) . '_' . safe_name((string) ($sub['reg_number'] ?? '')) . '.' . $ext;
        if (isset($usedNames[$entryName])) {
            $entryName = safe_name($sub['full_name']) . '_' . safe_name((string) ($sub['reg_number'] ?? '')) . '_' . substr(md5($fullPath), 0, 6) . '.' . $ext;
        }
        $usedNames[$entryName] = true;

        $zip->addFile($fullPath, $entryName);
    }
    $zip->close();

    AuditLog::record(Auth::id(), 'ASSIGNMENT_SUBMISSIONS_ZIP_DOWNLOAD', 'assignments', $assignmentId);

    $zipName = safe_name($assignment['module_title']) . '_' . safe_name($assignment['title']) . '_submissions.zip';

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    unlink($zipPath);
    exit;
}

http_response_code(400);
die('Missing user_id or all parameter.');
