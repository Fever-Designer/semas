<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/dashboard.php');
}
csrf_verify();

$db = Database::connection();
$hod = Auth::user();
$targetUserId = (int) ($_POST['user_id'] ?? 0);

// Scope check: an HOD may only toggle students within their own department.
$stmt = $db->prepare('SELECT * FROM users WHERE user_id = :id AND department_id = :dept');
$stmt->execute(['id' => $targetUserId, 'dept' => $hod['department_id']]);
$student = $stmt->fetch();

if ($student) {
    $newStatus = $student['status'] === 'Active' ? 'Deactivated' : 'Active';
    $db->prepare('UPDATE users SET status = :status WHERE user_id = :id')
       ->execute(['status' => $newStatus, 'id' => $targetUserId]);
    AuditLog::record(Auth::id(), 'HOD_TOGGLE_STUDENT_STATUS', 'users', $targetUserId, "new_status=$newStatus");

    if ($newStatus === 'Active') {
        Mailer::sendAccountActivated($student);
    } else {
        Mailer::sendAccountDeactivated($student);
    }
    flash('success', 'Student status updated.');
} else {
    flash('error', 'Student not found in your department.');
}

redirect('/dashboard.php');
