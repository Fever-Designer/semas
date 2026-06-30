<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();

$db = Database::connection();
$me = Auth::user();

$lecStmt = $db->prepare('SELECT * FROM lecturers WHERE user_id = :uid');
$lecStmt->execute(['uid' => $me['user_id']]);
$lecturer = $lecStmt->fetch();

$moduleId = (int) ($_GET['module_id'] ?? 0);

$modStmt = $db->prepare(
    "SELECT m.*, u.full_name AS lecturer_name
     FROM modules m
     LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = lt.user_id
     WHERE m.module_id = :id AND m.lecturer_id = :lec"
);
$modStmt->execute(['id' => $moduleId, 'lec' => $lecturer['lecturer_id'] ?? 0]);
$module = $modStmt->fetch();

if (!$module) {
    http_response_code(403);
    die('Module not found or not assigned to you.');
}

$students  = AttendanceSheet::students($db, $moduleId);
$classDates = AttendanceSheet::expectedClassDates($module);
$sessLabel = AttendanceSheet::sessionLabel($module);

AuditLog::record(Auth::id(), 'ATTENDANCE_SHEET_PRINT', 'modules', $moduleId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attendance Sheet / <?= e($module['module_title']) ?></title>
<style>
  body { font-family: Arial, sans-serif; font-size: 11px; color: #1B1F2A; margin: 20px; }
  table { width: 100%; border-collapse: collapse; }
  td, th { border: 1px solid #333; padding: 4px 6px; }
  .hdr-table td { border: none; padding: 2px 6px; font-weight: bold; }
  th { background: #f1f1f1; text-align: center; white-space: nowrap; }
  .no-print { margin-bottom: 12px; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>
<div class="no-print"><button onclick="window.print()">Print</button></div>

<table class="hdr-table" style="margin-bottom:10px;">
  <tr>
    <td style="width:55%;">MODULE NAME: <?= e($module['module_title']) ?></td>
    <td>START DATE: <?= $module['start_date'] ? e(date('d M Y', strtotime($module['start_date']))) : '' ?></td>
  </tr>
  <tr>
    <td>SESSION: <?= e($sessLabel) ?></td>
    <td>END DATE: <?= $module['end_date'] ? e(date('d M Y', strtotime($module['end_date']))) : '' ?></td>
  </tr>
  <tr>
    <td>LECTURER: <?= e($module['lecturer_name'] ?? '') ?></td>
    <td></td>
  </tr>
</table>

<table>
  <thead>
    <tr>
      <th>NO</th>
      <th>STUDENT NAME</th>
      <th>REG NUMBER</th>
      <th>PHONE NUMBER</th>
      <?php foreach ($classDates as $d): ?>
        <th style="min-width:34px;">
          <div><?= e(date('d M', strtotime($d))) ?></div>
          <div style="font-weight:400;"><?= e(date('D', strtotime($d))) ?></div>
        </th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php $no = 1; foreach ($students as $s): ?>
      <tr>
        <td style="text-align:center;"><?= $no++ ?></td>
        <td><?= e($s['full_name']) ?></td>
        <td><?= e($s['reg_number'] ?? '') ?></td>
        <td><?= e($s['phone_number'] ?? '') ?></td>
        <?php foreach ($classDates as $d): ?><td>&nbsp;</td><?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php for ($extra = 0; $extra < 5; $extra++): ?>
      <tr>
        <td style="text-align:center;"><?= $no++ ?></td>
        <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
        <?php foreach ($classDates as $d): ?><td>&nbsp;</td><?php endforeach; ?>
      </tr>
    <?php endfor; ?>
  </tbody>
</table>

</body>
</html>
