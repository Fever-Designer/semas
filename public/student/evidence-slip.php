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
            ul.full_name AS lecturer_name, ul2.title AS invigilator_title,
            sub.submitted_at, sub.notes AS submission_notes,
            e.enrollment_id
     FROM cat_exam_schedules cs
     JOIN modules m ON m.module_id = cs.module_id
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = cs.invigilator_id
     LEFT JOIN users u ON u.user_id = l.user_id
     LEFT JOIN lecturers l2 ON l2.lecturer_id = cs.invigilator_id
     LEFT JOIN users ul2 ON ul2.user_id = l2.user_id
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
    echo 'Evidence slip not found or you are not enrolled in this module.';
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
    echo 'Evidence slip is only available after both Sign In and Sign Out have been recorded for this ' . htmlspecialchars($schedule['exam_type']) . '.';
    exit;
}

// Verification QR payload: sign with APP_KEY, expires 365 days (used only for slip authenticity)
$payload = json_encode(['schedule_id' => $scheduleId, 'user_id' => $me['user_id'], 'exp' => time() + 31536000]);
$key     = hash('sha256', APP_KEY !== '' ? APP_KEY : 'fallback-key', true);
$iv      = random_bytes(16);
$cipher  = openssl_encrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
$hmac    = hash_hmac('sha256', $iv . $cipher, APP_KEY !== '' ? APP_KEY : 'fallback-key', true);
$b64u    = fn(string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
$verifyToken = $b64u($iv) . '.' . $b64u($cipher) . '.' . $b64u($hmac);
$verifyUrl   = APP_URL . '/public/verify-slip.php?t=' . $verifyToken;

$examDate  = date('d F Y', strtotime($schedule['scheduled_date']));
$sinTime   = date('h:i A', strtotime($sinRecord['recorded_at']));
$soutTime  = date('h:i A', strtotime($soutRecord['recorded_at']));
$dayOfWeek = date('l', strtotime($schedule['scheduled_date']));
$timeRange = date('h:i A', strtotime($schedule['start_time'])) . ' – ' . date('h:i A', strtotime($schedule['end_time']));
$issuedAt  = date('d F Y, h:i A');
$uniName   = Settings::get('university_name', 'University of Kigali');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($schedule['exam_type']) ?> Evidence Slip — <?= e($schedule['module_title']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Georgia, "Times New Roman", serif; background: #f4f5f7; margin: 0; padding: 24px 0; color: #1B1F2A; }
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

  .sig-section { margin-top: 20px; padding-top: 16px; border-top: 1px dashed #ccc; display: flex; align-items: flex-start; gap: 20px; }
  .sig-photo { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 3px solid #1E2A52; flex-shrink: 0; }
  .sig-info { flex: 1; font-size: 12px; }
  .sig-info .name { font-weight: bold; font-size: 14px; color: #1E2A52; }
  .sig-line { margin-top: 12px; border-top: 1px solid #333; padding-top: 3px; font-size: 11px; color: #6B7280; }

  .qr-section { margin-top: 16px; text-align: center; }
  .qr-section canvas, .qr-section img { margin: 0 auto; display: block; }
  .qr-section .qr-label { font-size: 10px; color: #9CA3AF; margin-top: 4px; }

  .footer { background: #F6F7FB; padding: 10px 28px; font-size: 10px; color: #9CA3AF; text-align: center; border-top: 1px solid #E4E7EF; }

  .no-print { margin: 16px auto; max-width: 680px; text-align: center; }
  .no-print button { padding: 10px 28px; background: #D4A24C; color: #1E2A52; font-weight: bold; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }
  @media print { .no-print { display: none; } body { background: #fff; padding: 0; } }
</style>
</head>
<body>

<div class="no-print"><button onclick="window.print()"><i>⊞</i> Print / Save as PDF</button></div>

<div class="page">
  <div class="header">
    <div>
      <div class="brand">SEM<span>AS</span></div>
      <div class="uni"><?= e($uniName) ?></div>
    </div>
    <div style="font-size:11px;color:#A9B3CC;text-align:right;">Issued: <?= e($issuedAt) ?></div>
  </div>

  <div class="slip-title">
    <h2><?= e($schedule['exam_type']) ?> Evidence Slip</h2>
    <div class="sub"><?= e($schedule['module_title']) ?> · <?= e($dayOfWeek) ?>, <?= e($examDate) ?></div>
  </div>

  <div class="body">
    <table class="info">
      <tr><td class="label">Student Name</td>       <td class="value"><?= e($me['full_name']) ?></td></tr>
      <tr><td class="label">Registration Number</td><td class="value"><?= e($me['reg_number'] ?? '—') ?></td></tr>
      <tr><td class="label">Department</td>         <td class="value"><?= e($schedule['department_name'] ?? '—') ?></td></tr>
      <tr><td class="label">Module</td>             <td class="value"><?= e($schedule['module_title']) ?></td></tr>
      <tr><td class="label">Lecturer / Examiner</td><td class="value"><?= e($schedule['lecturer_name'] ?? '—') ?></td></tr>
      <tr><td class="label">Invigilator</td>        <td class="value"><?= e($schedule['invigilator_name'] ?? '—') ?></td></tr>
      <tr><td class="label"><?= e($schedule['exam_type']) ?> Date</td><td class="value"><?= e($dayOfWeek . ', ' . $examDate) ?></td></tr>
      <tr><td class="label">Scheduled Time</td>     <td class="value"><?= e($timeRange) ?></td></tr>
      <tr><td class="label">Room</td>               <td class="value"><?= e($schedule['room']) ?></td></tr>
      <tr><td class="label">Session</td>            <td class="value"><?= e($schedule['session_type'] ?? '—') ?></td></tr>
      <tr><td class="label">Attendance Status</td>  <td class="value"><?= e($soutRecord['status'] ?? 'Present') ?></td></tr>
      <tr><td class="label">Sign In Time</td>       <td class="value"><?= e($sinTime) ?></td></tr>
      <tr><td class="label">Sign Out Time</td>      <td class="value"><?= e($soutTime) ?></td></tr>
    </table>

    <div class="stamp <?= ($soutRecord['status'] ?? 'Present') !== 'Present' ? 'absent' : '' ?>">
      <?= ($soutRecord['status'] ?? 'Present') === 'Present' ? '✓ PRESENT — ATTENDANCE CONFIRMED' : '✗ ABSENT — ' . e($soutRecord['status'] ?? '') ?>
    </div>

    <!-- Invigilator Signature Block -->
    <div class="sig-section">
      <?php
        $photoSrc = $schedule['invigilator_photo']
            ? APP_URL . '/' . $schedule['invigilator_photo']
            : 'https://ui-avatars.com/api/?name=' . urlencode($schedule['invigilator_name'] ?? 'I') . '&background=1E2A52&color=fff&size=70';
      ?>
      <img class="sig-photo" src="<?= e($photoSrc) ?>" alt="Invigilator Photo">
      <div class="sig-info">
        <div class="name"><?= e($schedule['invigilator_name'] ?? '—') ?></div>
        <div style="color:#6B7280;margin-top:2px;">Invigilator · <?= e($uniName) ?></div>
        <?php if ($schedule['submitted_at']): ?>
          <div style="color:#6B7280;margin-top:2px;">Submitted: <?= e(date('d M Y H:i', strtotime($schedule['submitted_at']))) ?></div>
        <?php endif; ?>
        <div class="sig-line">Digital Signature — Verified via SEMAS</div>
      </div>
      <div class="qr-section" style="min-width:80px;">
        <div id="verifyQr"></div>
        <div class="qr-label">Scan to verify</div>
      </div>
    </div>
  </div>

  <div class="footer">
    This slip is permanently stored in SEMAS and can be reprinted at any time.
    Verification URL: <?= e($verifyUrl) ?>
  </div>
</div>

<div class="no-print" style="margin-top:8px;">
  <button onclick="window.print()">Print / Save as PDF</button>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous"></script>
<script>
  new QRCode(document.getElementById('verifyQr'), {
    text: '<?= e(addslashes($verifyUrl)) ?>',
    width: 72, height: 72,
    colorDark: '#1E2A52', colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
  });
</script>
</body>
</html>
