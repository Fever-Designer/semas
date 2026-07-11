<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$db = Database::connection();
$me = Auth::user();
$scheduleId = (int) ($_GET['schedule_id'] ?? 0);

// Load schedule + module + submission
$stmt = $db->prepare(
    "SELECT cs.*, m.module_title, m.session_type, m.room AS module_room,
            d.department_name,
            u.full_name AS invigilator_name, u.photo_path AS invigilator_photo,
            ul.full_name AS lecturer_name,
            sub.submitted_at, sub.notes AS submission_notes,
            e.enrollment_id
     FROM cat_exam_schedules cs
     JOIN modules m ON m.module_id = cs.module_id
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = cs.invigilator_id
     LEFT JOIN users u ON u.user_id = l.user_id
     LEFT JOIN lecturers lm ON lm.lecturer_id = m.lecturer_id
     LEFT JOIN users ul ON ul.user_id = lm.user_id
     LEFT JOIN cat_exam_submissions sub ON sub.schedule_id = cs.schedule_id
     JOIN module_enrollments e ON e.module_id = m.module_id AND e.user_id = :uid
     WHERE cs.schedule_id = :sid"
);
$stmt->execute(['uid' => $me['user_id'], 'sid' => $scheduleId]);
$schedule = $stmt->fetch();

if (!$schedule) {
    http_response_code(403);
    echo 'Attendance slip not found or you are not enrolled in this module.';
    exit;
}

// Must have both Sign In AND Sign Out recorded
$sinStmt = $db->prepare("SELECT recorded_at FROM cat_exam_attendance_logs WHERE schedule_id = :s AND user_id = :u AND attendance_type = 'Sign In'");
$sinStmt->execute(['s' => $scheduleId, 'u' => $me['user_id']]);
$sinRecord = $sinStmt->fetch();

$soutStmt = $db->prepare("SELECT recorded_at, status FROM cat_exam_attendance_logs WHERE schedule_id = :s AND user_id = :u AND attendance_type = 'Sign Out'");
$soutStmt->execute(['s' => $scheduleId, 'u' => $me['user_id']]);
$soutRecord = $soutStmt->fetch();

if (!$sinRecord || !$soutRecord) {
    http_response_code(403);
    echo 'Attendance slip is only available after both Sign In and Sign Out have been recorded for this ' . htmlspecialchars($schedule['exam_type']) . '.';
    exit;
}

$examDate  = date('d F Y', strtotime($schedule['scheduled_date']));
$sinTime   = date('h:i A', strtotime($sinRecord['recorded_at']));
$soutTime  = date('h:i A', strtotime($soutRecord['recorded_at']));
$dayOfWeek = date('l', strtotime($schedule['scheduled_date']));
$timeRange = date('h:i A', strtotime($schedule['start_time'])) . ' / ' . date('h:i A', strtotime($schedule['end_time']));
$issuedAt  = date('d F Y, h:i A', strtotime($soutRecord['recorded_at']));
$uniName   = Settings::get('university_name', 'University of Kigali');
$brandLogo = Settings::get('logo_path');

// Verification QR payload: sign with APP_KEY, expires 365 days (used only for slip authenticity)
$payload = json_encode(['schedule_id' => $scheduleId, 'user_id' => $me['user_id'], 'exp' => time() + 31536000]);
$key     = hash('sha256', APP_KEY !== '' ? APP_KEY : 'fallback-key', true);
$iv      = random_bytes(16);
$cipher  = openssl_encrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
$hmac    = hash_hmac('sha256', $iv . $cipher, APP_KEY !== '' ? APP_KEY : 'fallback-key', true);
$b64u    = function (string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); };
$verifyToken = $b64u($iv) . '.' . $b64u($cipher) . '.' . $b64u($hmac);
$verifyUrl   = APP_URL . '/verify-slip.php?t=' . $verifyToken;

// Plain text is supported by ordinary QR scanners offline (unlike data: URLs,
// which many phone cameras block). It also carries the signed online URL.
$attendanceStatus = $soutRecord['status'] ?? 'Present';
try {
    $qrImage = SimpleQr::pngDataUri(SlipVerification::offlineText(
        $schedule['exam_type'] . ' Attendance Slip',
        [
            ['Student', $me['full_name']],
            ['Reg No', $me['reg_number'] ?? '/'],
            ['Module', $schedule['module_title']],
            ['Department', $schedule['department_name'] ?? '/'],
            ['Assessment', $schedule['exam_type']],
            ['Date', $examDate],
            ['Room', $schedule['room'] ?? ($schedule['module_room'] ?? '/')],
            ['Time', $sinTime . '-' . $soutTime],
        ],
        'ATTENDANCE RECORDED - ' . $attendanceStatus,
        $verifyUrl,
        $uniName
    ), 6, 2);
} catch (Throwable $e) {
    // Retain online verification if an unusually long record exceeds QR capacity.
    $qrImage = SimpleQr::pngDataUri($verifyUrl, 6, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($schedule['exam_type']) ?> Attendance Slip / <?= e($schedule['module_title']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Times New Roman', Times, serif; background: #f4f5f7; margin: 0; padding: 24px 0; color: #1B1F2A; }
  .page { max-width: 680px; margin: 0 auto; background: #fff; border: 2px solid #1E2A52; border-radius: 8px; overflow: hidden; }

  .header { background: #1E2A52; color: #fff; padding: 18px 28px; display: flex; justify-content: space-between; align-items: center; }
  .header .brand { font-size: 20px; font-weight: bold; letter-spacing: 1px; }
  .header .brand span { color: #D4A24C; }
  .header .uni { font-size: 12px; color: #A9B3CC; }

  .slip-title { text-align: center; padding: 18px 28px 8px; border-bottom: 2px solid #1E2A52; }
  .slip-title h2 { font-size: 16px; text-transform: uppercase; letter-spacing: 2px; color: #1E2A52; margin: 0 0 4px; }
  .slip-title .sub { font-size: 12px; color: #6B7280; }

  .body { padding: 20px 28px; }
  table.info { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.info td { padding: 6px 4px; border-bottom: 1px solid #F0F0F0; vertical-align: top; }
  table.info td.label { color: #6B7280; width: 38%; }
  table.info td.value { font-weight: bold; }

  .stamp { margin: 16px 0 0; padding: 10px 14px; background: #ECFDF5; border: 1px solid #6EE7B7; border-radius: 6px; font-size: 13px; color: #065F46; text-align: center; font-weight: bold; letter-spacing: .5px; }
  .stamp.absent { background: #FEF2F2; border-color: #FECACA; color: #991B1B; }

  .sig-section { margin-top: 20px; padding-top: 16px; border-top: 1px dashed #ccc; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
  .sig-photo { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 3px solid #1E2A52; flex-shrink: 0; }
  .sig-info { flex: 1; font-size: 12px; }
  .sig-info .name { font-weight: bold; font-size: 14px; color: #1E2A52; }
  .sig-line { margin-top: 12px; border-top: 1px solid #333; padding-top: 3px; font-size: 11px; color: #6B7280; }

  .qr-section { margin-top: 0; text-align: center; flex: 0 0 250px; min-width: 250px; }
  .qr-section #verifyQr { display: inline-block; padding: 8px; background: #ffffff; border: 2px solid #1E2A52; border-radius: 8px; width: 235px; height: 235px; }
  .qr-section canvas, .qr-section img { width: 219px; height: 219px; margin: 0 auto; display: block; image-rendering: pixelated; }
  .qr-section .qr-label { font-size: 11px; color: #374151; margin-top: 6px; font-weight: bold; }

  .footer { background: #F6F7FB; padding: 10px 28px; font-size: 10px; color: #9CA3AF; text-align: center; border-top: 1px solid #E4E7EF; }
  .footer a { color: #9CA3AF; text-decoration: none; }
  .print-meta, .verification-url { display: block; }

  .no-print { margin: 16px auto; max-width: 680px; text-align: center; }
  .no-print button { padding: 10px 28px; background: #D4A24C; color: #1E2A52; font-weight: bold; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }

  @media print {
    @page { size: A4 portrait; margin: 10mm; }
    html, body { margin: 0; padding: 0; background: #fff; height: 100%; }
    .no-print { display: none; }
    .page {
      max-width: 100%;
      width: 100%;
      border-radius: 0;
      border: 1.5pt solid #1E2A52;
      page-break-inside: avoid;
      break-inside: avoid;
    }
    .header { padding: 10px 18px; }
    .header .brand { font-size: 16px; }
    .slip-title { padding: 10px 18px 6px; }
    .slip-title h2 { font-size: 13px; }
    .body { padding: 10px 18px; }
    table.info td { padding: 4px 3px; font-size: 11px; }
    .sig-section { margin-top: 10px; padding-top: 10px; gap: 14px; }
    .sig-photo { width: 54px; height: 54px; }
    .sig-info .name { font-size: 12px; }
    .sig-info { font-size: 10px; }
    .qr-section { flex: 0 0 230px; min-width: 230px; }
    .footer { padding: 6px 18px; font-size: 9px; }
  }
</style>
</head>
<body>

<div class="page">
  <div class="header">
    <div>
      <div class="brand">
        <?php if (!empty($brandLogo)): ?>
          <img src="<?= e(APP_URL . '/' . ltrim($brandLogo, '/')) ?>" alt="SEMAS Logo" style="height:36px; max-height:40px;">
        <?php else: ?>
          SEM<span>AS</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="slip-title">
    <h2><?= e($schedule['exam_type']) ?> Attendance Slip</h2>
    <div class="sub"><?= e($schedule['module_title']) ?> · <?= e($dayOfWeek) ?>, <?= e($examDate) ?></div>
  </div>

  <div class="body">
    <table class="info">
      <tr><td class="label">Student Name</td>       <td class="value"><?= e($me['full_name']) ?></td></tr>
      <tr><td class="label">Registration Number</td><td class="value"><?= e($me['reg_number'] ?? '/') ?></td></tr>
      <tr><td class="label">Department</td>         <td class="value"><?= e($schedule['department_name'] ?? '/') ?></td></tr>
      <tr><td class="label">Module</td>             <td class="value"><?= e($schedule['module_title']) ?></td></tr>
      <tr><td class="label">Examiner</td><td class="value"><?= e($schedule['lecturer_name'] ?? '/') ?></td></tr>
      <tr><td class="label">Invigilator</td>        <td class="value"><?= e($schedule['invigilator_name'] ?? '/') ?></td></tr>
      <tr><td class="label"><?= e($schedule['exam_type']) ?> Date</td><td class="value"><?= e($dayOfWeek . ', ' . $examDate) ?></td></tr>
      <tr><td class="label">Room</td>               <td class="value"><?= e($schedule['room']) ?></td></tr>
      <tr><td class="label">Session</td>            <td class="value"><?= e($schedule['session_type'] ?? '/') ?></td></tr>
    </table>

    <!-- Invigilator Signature Block -->
    <div class="sig-section">
      <div class="sig-info">
        <div class="name"><?= e($schedule['invigilator_name'] ?? '/') ?></div>
        <div style="color:#6B7280;margin-top:2px;">Invigilator · <?= e($uniName) ?></div>
        <?php if ($schedule['submitted_at']): ?>
          <div style="color:#6B7280;margin-top:2px;">Submitted: <?= e(date('d M Y H:i', strtotime($schedule['submitted_at']))) ?></div>
        <?php endif; ?>
        <div class="sig-line">Digital Signature Verified via SEMAS</div>
      </div>
      <div class="qr-section">
        <div id="verifyQr"><img src="<?= e($qrImage) ?>" alt="Scan to verify"></div>
        <div class="qr-label">Scan for details (works offline) / use the included link for live verification</div>
      </div>
    </div>
  </div>

  <div class="footer verification-url">
    Verification URL: <?= e($verifyUrl) ?>
  </div>
</div>

<div class="no-print" style="margin-top:12px;"><button onclick="window.print()">Print / Save as PDF</button></div>

</body>
</html>
