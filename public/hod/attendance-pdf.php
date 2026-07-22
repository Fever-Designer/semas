<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD']);

$db        = Database::connection();
$moduleId  = (int) ($_GET['module_id'] ?? 0);
$rangeType = $_GET['range'] ?? 'daily';
$rangeDate = $_GET['date'] ?? date('Y-m-d');

if ($rangeType === 'weekly') {
    $dateFrom = date('Y-m-d', strtotime('monday this week', strtotime($rangeDate)));
    $dateTo   = date('Y-m-d', strtotime('sunday this week', strtotime($rangeDate)));
} elseif ($rangeType === 'monthly') {
    $dateFrom = date('Y-m-01', strtotime($rangeDate));
    $dateTo   = date('Y-m-t',  strtotime($rangeDate));
} else {
    $dateFrom = $rangeDate;
    $dateTo   = $rangeDate;
}

$modStmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name
     FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l   ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u       ON u.user_id = l.user_id
     WHERE m.module_id = :id"
);
$modStmt->execute(['id' => $moduleId]);
$module = $modStmt->fetch();
if (!$module) { http_response_code(404); die('Module not found.'); }
$metrics = AttendanceSheet::currentMetrics($db, $moduleId, $module);

$rows = $db->prepare(
    "SELECT u.user_id, u.full_name, u.reg_number, cs.session_date, cs.window_name,
            si.checkin_time AS sign_in_time, si.verification_method AS sign_in_method,
            so.checkin_time AS sign_out_time, so.verification_method AS sign_out_method
     FROM module_enrollments e
     JOIN users u ON u.user_id = e.user_id AND u.status = 'Active'
     JOIN class_sessions cs ON cs.module_id = e.module_id
     LEFT JOIN class_attendance_logs si ON si.session_id = cs.session_id AND si.user_id = u.user_id AND si.attendance_type = 'Sign In'
     LEFT JOIN class_attendance_logs so ON so.session_id = cs.session_id AND so.user_id = u.user_id AND so.attendance_type = 'Sign Out'
     LEFT JOIN holidays h ON h.holiday_date = cs.session_date AND h.holiday_type = 'Public Holiday'
     WHERE cs.module_id = :mid AND cs.session_date BETWEEN :from AND :to
       AND (:weekend = 1 OR h.holiday_id IS NULL)
     ORDER BY cs.session_date, u.full_name"
);
$rows->execute(['mid' => $moduleId, 'from' => $dateFrom, 'to' => $dateTo, 'weekend' => $module['session_type'] === 'Weekend' ? 1 : 0]);
$records = $rows->fetchAll();

$brandName = 'UNIVERSITY';
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attendance Report / <?= e($module['module_title']) ?></title>
<style>
  body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 20px; }
  h2 { font-size: 16px; margin: 0 0 4px; }
  h3 { font-size: 13px; margin: 0 0 12px; color: #444; }
  table { width: 100%; border-collapse: collapse; margin-top: 16px; }
  th { background: #1E2A52; color: #fff; padding: 6px 8px; text-align: left; font-size: 11px; }
  td { border-bottom: 1px solid #ddd; padding: 5px 8px; }
  .present { color: #146c3c; font-weight: bold; }
  .late    { color: #856404; font-weight: bold; }
  .absent  { color: #842029; font-weight: bold; }
  .footer  { margin-top: 24px; font-size: 10px; color: #888; border-top: 1px solid #ddd; padding-top: 8px; }
  @media print { body { margin: 10px; } }
</style>
</head>
<body>
<h2><?= e($brandName) ?> / Attendance Report</h2>
<h3><?= e($module['module_title']) ?> (<?= e($module['department_name'] ?? '') ?>)</h3>
<p><strong>Lecturer:</strong> <?= e($module['lecturer_name'] ?? '/') ?> &nbsp;|&nbsp;
   <strong>Session:</strong> <?= e($module['session_type'] ?? 'Any') ?> &nbsp;|&nbsp;
   <strong>Period:</strong> <?= ucfirst($rangeType) ?> / <?= date('d M Y', strtotime($dateFrom)) ?><?= $dateFrom !== $dateTo ? ' to ' . date('d M Y', strtotime($dateTo)) : '' ?></p>

<table>
  <thead>
    <tr><th>NO</th><th>Student Name</th><th>Reg No.</th><th>Date</th><th>Session</th><th>Status</th><th>Sign In</th><th>Sign Out</th><th>Current %</th></tr>
  </thead>
  <tbody>
    <?php $i = 0; foreach ($records as $r): $i++;
      $decision = AttendanceSheet::decisionForLogs(
          $r['sign_in_method'] ? ['verification_method' => $r['sign_in_method']] : null,
          $r['sign_out_method'] ? ['verification_method' => $r['sign_out_method']] : null
      );
    ?>
      <tr>
        <td><?= $i ?></td>
        <td><?= e($r['full_name']) ?></td>
        <td><?= e($r['reg_number'] ?? '/') ?></td>
        <td><?= e($r['session_date']) ?></td>
        <td><?= e($r['window_name']) ?></td>
        <td class="<?= strtolower($decision) ?>"><?= e($decision) ?></td>
        <td><?= ($r['sign_in_time'] && in_array((string) $r['sign_in_method'], ['QR', 'Manual'], true)) ? e(date('H:i', strtotime($r['sign_in_time']))) : '/' ?></td>
        <td><?= $r['sign_out_time'] ? e(date('H:i', strtotime($r['sign_out_time']))) : '/' ?></td>
        <?php $m = $metrics[(int) $r['user_id']] ?? ['percent' => 0]; ?>
        <td><?= e(number_format((float) $m['percent'], 1)) ?>%</td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$records): ?>
      <tr><td colspan="9" style="text-align:center;color:#888;padding:16px;">No records in this period.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<div class="footer">Generated on <?= date('d M Y H:i') ?> / SEMAS / <?= e($brandName) ?></div>
<script>window.print();</script>
</body>
</html>
