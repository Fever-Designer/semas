<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Dean']);

use Dompdf\Dompdf;
use Dompdf\Options;

$db = Database::connection();
$currentUser = Auth::user();
$universityName = mb_strtoupper(Settings::get('university_name', 'University of Kigali'), 'UTF-8');
$principalStmt = $db->prepare(
    "SELECT u.full_name
     FROM users u
     JOIN roles r ON r.role_id = u.role_id
     WHERE r.role_name = 'Principal' AND u.status = 'Active'
     ORDER BY u.full_name LIMIT 1"
);
$principalStmt->execute();
$principalName = (string) ($principalStmt->fetchColumn() ?: 'Not assigned');
$filters = scoped_report_filters($_GET, $currentUser);
$rows = build_attendance_report_rows($db, $filters);

$totalRows = count($rows);
$present = count(array_filter($rows, function ($r) {
    return $r['attendance_status'] === 'Present';
}));
$rate = $totalRows ? round($present / $totalRows * 100) : 0;

ob_start();
?>
<html>
<head>
<style>
  @page { size: A4 landscape; margin: 12mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #000; }
  h1 { font-size: 19px; color: #000; text-align: center; margin: 0 0 4px; text-transform: uppercase; }
  h2 { font-size: 13px; text-align: center; margin: 0 0 3px; }
  .meta { color: #000; font-size: 9px; text-align: center; margin-bottom: 14px; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  th, td { border: 1px solid #000; background: #fff; color: #000; padding: 6px 5px; font-size: 8px; vertical-align: top; }
  th { text-align: left; font-weight: bold; text-transform: uppercase; }
  .summary { table-layout: fixed; }
  .summary th, .summary td { text-align: center; }
  .summary td { font-size: 13px; font-weight: bold; }
  .present, .absent { color: #000; font-weight: bold; }
  .approvals { margin-top: 28px; border: 0; }
  .approvals td { width: 50%; border: 0; padding: 22px 45px 0; text-align: center; }
  .signature { border-top: 1px solid #000; padding-top: 5px; }
  .signature strong { display: block; font-size: 10px; }
</style>
</head>
<body>
  <h1><?= e($universityName) ?></h1>
  <h2>Attendance &amp; Compliance Report</h2>
  <div class="meta">
    Student Event Management and Announcement System (SEMAS)<br>
    Generated on <?= date('d F Y, h:i A') ?>
  </div>
  <table class="summary">
    <thead><tr><th>Registrations</th><th>Present</th><th>Absent</th><th>Attendance Rate</th></tr></thead>
    <tbody><tr><td><?= $totalRows ?></td><td><?= $present ?></td><td><?= $totalRows - $present ?></td><td><?= $rate ?>%</td></tr></tbody>
  </table>
  <table>
    <tr>
      <th>Event</th><th>Venue</th><th>Date</th><th>Reg. Number</th><th>Full Name</th>
      <th>Department</th><th>Phone</th><th>Email</th><th>Check-in Time</th><th>Status</th>
    </tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['event_title']) ?></td>
        <td><?= htmlspecialchars($r['venue']) ?></td>
        <td><?= htmlspecialchars($r['event_date']) ?></td>
        <td><?= htmlspecialchars($r['reg_number'] ?? '') ?></td>
        <td><?= htmlspecialchars($r['full_name']) ?></td>
        <td><?= htmlspecialchars($r['department_name'] ?? '') ?></td>
        <td><?= htmlspecialchars($r['phone_number'] ?? '') ?></td>
        <td><?= htmlspecialchars($r['email']) ?></td>
        <td><?= htmlspecialchars($r['checkin_time'] ?? '') ?></td>
        <td class="<?= $r['attendance_status'] === 'Present' ? 'present' : 'absent' ?>"><?= htmlspecialchars($r['attendance_status']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <table class="approvals"><tr>
    <td><div class="signature"><strong><?= e($currentUser['full_name']) ?></strong>Prepared by / Dean</div></td>
    <td><div class="signature"><strong><?= e($principalName) ?></strong>Approved by / Principal</div></td>
  </tr></table>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

AuditLog::record(Auth::id(), 'EXPORT_PDF_REPORT', null, null, json_encode($filters));

$dompdf->stream('semas-attendance-report-' . date('Ymd-His') . '.pdf', ['Attachment' => true]);
