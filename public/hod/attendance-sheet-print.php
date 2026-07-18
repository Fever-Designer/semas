<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD', 'Coordinator']);

$db            = Database::connection();
$me            = Auth::user();
$isCoordinator = Auth::role() === 'Coordinator';

$moduleId = (int) ($_GET['module_id'] ?? 0);

$modSql = "SELECT m.*, u.full_name AS lecturer_name
           FROM modules m
           LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
           LEFT JOIN users u ON u.user_id = lt.user_id
           WHERE m.module_id = :id";
if ($isCoordinator) {
    $modSql .= " AND m.session_type = 'Weekend'";
}
$modStmt = $db->prepare($modSql);
$modStmt->execute(['id' => $moduleId]);
$module = $modStmt->fetch();

if (!$module) {
    http_response_code(403);
    die('Module not found.');
}

$students   = AttendanceSheet::students($db, $moduleId);
$classDates = AttendanceSheet::expectedClassDates($module, true);
$sessLabel  = AttendanceSheet::sessionLabel($module);
$metrics = AttendanceSheet::currentMetrics($db, $moduleId, $module);
$decisions = AttendanceSheet::decisionsByDate($db, $moduleId, $module, $classDates);
$effectiveEnd = empty($classDates)
    ? date('Y-m-d')
    : end($classDates);
reset($classDates);

AuditLog::record(Auth::id(), 'ATTENDANCE_SHEET_PRINT', 'modules', $moduleId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attendance Sheet / <?= e($module['module_title']) ?></title>
<style>
  @page { size: A4 landscape; margin: 8mm; }
  body { font-family: Arial, sans-serif; font-size: 10px; color: #1B1F2A; margin: 12px; }
  table { width: 100%; border-collapse: collapse; table-layout: auto; }
  td, th { border: 1px solid #333; padding: 5px 8px; vertical-align: middle; }
  .hdr-table td { border: none; padding: 2px 6px; font-weight: bold; }
  th { background: #f1f1f1; text-align: center; white-space: nowrap; }
  .col-no { width: 36px; text-align: center; }
  .col-name { min-width: 210px; white-space: nowrap; }
  .col-reg { min-width: 115px; white-space: nowrap; }
  .col-phone { min-width: 105px; white-space: nowrap; }
  .col-num { width: 42px; text-align: center; }
  .col-pct { width: 58px; text-align: center; }
  .date-col { min-width: 58px; width: 58px; text-align: center; }
  .no-print { margin-bottom: 12px; }
  @media print {
    .no-print { display: none; }
    body { margin: 0; }
  }
</style>
</head>
<body onload="window.print()">
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
    <td colspan="2">LECTURER: <?= e($module['lecturer_name'] ?? '') ?></td>
  </tr>
</table>

<table>
  <thead>
    <tr>
      <th class="col-no">NO</th>
      <th class="col-name">STUDENT NAME</th>
      <th class="col-reg">REG NUMBER</th>
      <th class="col-phone">PHONE NUMBER</th>
      <th class="col-num">P</th>
      <th class="col-num">L</th>
      <th class="col-num">A</th>
      <th class="col-num">TOT</th>
      <th class="col-pct">%</th>
      <?php foreach ($classDates as $d): ?>
        <th class="date-col">
          <div><?= e(date('d M', strtotime($d))) ?></div>
          <div style="font-weight:400;"><?= e(date('D', strtotime($d))) ?></div>
        </th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php $no = 1; foreach ($students as $s): ?>
      <tr>
        <td class="col-no"><?= $no++ ?></td>
        <td class="col-name"><?= e($s['full_name']) ?></td>
        <td class="col-reg"><?= e($s['reg_number'] ?? '') ?></td>
        <td class="col-phone"><?= e($s['phone_number'] ?? '') ?></td>
        <?php $m = $metrics[(int) $s['user_id']] ?? ['present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0, 'percent' => 0]; ?>
        <td class="col-num"><?= (int) $m['present'] ?></td>
        <td class="col-num"><?= (int) $m['late'] ?></td>
        <td class="col-num"><?= (int) $m['absent'] ?></td>
        <td class="col-num"><?= (int) $m['total'] ?></td>
        <td class="col-pct"><?= e(number_format((float) $m['percent'], 1)) ?>%</td>
        <?php foreach ($classDates as $d): ?>
          <td class="date-col"><?= e($decisions[(int) $s['user_id']][$d] ?? 'Absent') ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php for ($extra = 0; $extra < 5; $extra++): ?>
      <tr>
        <td class="col-no"><?= $no++ ?></td>
        <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
        <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
        <?php foreach ($classDates as $d): ?><td class="date-col">&nbsp;</td><?php endforeach; ?>
      </tr>
    <?php endfor; ?>
  </tbody>
</table>

</body>
</html>
