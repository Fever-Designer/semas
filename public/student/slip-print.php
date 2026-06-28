<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$db = Database::connection();
$me = Auth::user();
$moduleId = (int) ($_GET['module_id'] ?? 0);
$examType = ($_GET['type'] ?? '') === 'Exam' ? 'Exam' : 'CAT';

$stmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name, uv.full_name AS invigilator_name
     FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id AND e.user_id = :uid
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     LEFT JOIN lecturers lv ON lv.lecturer_id = m.invigilator_id
     LEFT JOIN users uv ON uv.user_id = lv.user_id
     WHERE m.module_id = :id"
);
$stmt->execute(['uid' => $me['user_id'], 'id' => $moduleId]);
$module = $stmt->fetch();

$eligibility = $module ? Eligibility::statusFor($moduleId, $me['user_id'], $examType) : null;
$allowed = $eligibility && $eligibility['hod_decision'] !== 'Pending' && $eligibility['final_decision'] === 'Allowed';

if (!$module || !$allowed) {
    http_response_code(403);
    echo 'This slip is not available. Either the module/eligibility record was not found, you are not registered for it, or your eligibility status is not "Allowed" yet.';
    exit;
}
$examDate = $examType === 'CAT' ? $module['cat_date'] : $module['exam_date'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($examType) ?> Slip — <?= e($module['module_title']) ?></title>
<style>
  body { font-family: Georgia, serif; max-width: 640px; margin: 40px auto; color: #1B1F2A; }
  .slip { border: 2px solid #1E2A52; padding: 28px; border-radius: 8px; }
  h1 { font-size: 1.1rem; text-align: center; color: #1E2A52; margin-bottom: 4px; }
  .sub { text-align: center; color: #6B7280; font-size: 0.85rem; margin-bottom: 20px; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 6px 4px; vertical-align: top; }
  td.label { color: #6B7280; width: 40%; }
  .btn { display: inline-block; margin-top: 20px; padding: 8px 16px; background: #D4A24C; color: #1E2A52; text-decoration: none; border-radius: 6px; font-weight: bold; }
  .stamp { text-align: center; margin-top: 14px; font-weight: bold; color: #2F9E68; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>
<div class="slip">
  <h1><?= e(Settings::get('university_name', 'University of Kigali')) ?></h1>
  <div class="sub"><?= e(strtoupper($examType)) ?> ENTRY SLIP</div>
  <table>
    <tr><td class="label">Student Name</td><td><?= e($me['full_name']) ?></td></tr>
    <tr><td class="label">Registration Number</td><td><?= e($me['reg_number'] ?? '—') ?></td></tr>
    <tr><td class="label">Department</td><td><?= e($module['department_name'] ?? '—') ?></td></tr>
    <tr><td class="label">Module</td><td><?= e($module['module_title']) ?></td></tr>
    <tr><td class="label">Lecturer</td><td><?= e($module['lecturer_name'] ?? '—') ?></td></tr>
    <tr><td class="label">Invigilator</td><td><?= e($module['invigilator_name'] ?? 'TBA') ?></td></tr>
    <tr><td class="label"><?= e($examType) ?> Date</td><td><?= e($examDate ?? 'Not scheduled') ?></td></tr>
    <tr><td class="label">Room</td><td><?= e($module['room'] ?? '—') ?></td></tr>
    <tr><td class="label">Session</td><td><?= e($module['session_type'] ?? '—') ?></td></tr>
    <tr><td class="label">Issued</td><td><?= e(date('d F Y, h:i A')) ?></td></tr>
  </table>
  <div class="stamp">✓ ELIGIBLE — APPROVED BY HOD</div>
  <div style="text-align:center;"><a href="#" class="btn no-print" onclick="window.print(); return false;">Print Slip</a></div>
</div>
</body></html>
