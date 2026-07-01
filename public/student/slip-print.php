<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$db = Database::connection();
$me = Auth::user();
$moduleId = (int) ($_GET['module_id'] ?? 0);
$examType = ($_GET['type'] ?? '') === 'Exam' ? 'Exam' : 'CAT';

$stmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name
     FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id AND e.user_id = :uid
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
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

$eligibilityStmt = $db->prepare(
    "SELECT ce.*, u.full_name AS approver_name, r.role_name AS approver_role
     FROM cat_exam_eligibility ce
     LEFT JOIN users u ON u.user_id = ce.decided_by
     LEFT JOIN roles r ON r.role_id = u.role_id
     WHERE ce.module_id = :mid AND ce.user_id = :uid AND ce.exam_type = :type
     LIMIT 1"
);
$eligibilityStmt->execute(['mid' => $moduleId, 'uid' => $me['user_id'], 'type' => $examType]);
$eligibilityRow = $eligibilityStmt->fetch() ?: [];
$approverName = $eligibilityRow['approver_name'] ?? 'SEMAS Review';
$approverRole = $eligibilityRow['approver_role'] ?? 'HOD / Coordinator';
$approvedAt = $eligibilityRow['decided_at'] ? date('d F Y, h:i A', strtotime($eligibilityRow['decided_at'])) : null;
$brandLogo = Settings::get('logo_path');

// Get schedule details (room, invigilator, time) from cat_exam_schedules
$schedStmt = $db->prepare(
    "SELECT cs.schedule_id, cs.room, cs.start_time, cs.end_time, cs.scheduled_date, u.full_name AS invigilator_name
     FROM cat_exam_schedules cs
     JOIN lecturers l ON l.lecturer_id = cs.invigilator_id
     JOIN users u ON u.user_id = l.user_id
     WHERE cs.module_id = :mid AND cs.exam_type = :type
     LIMIT 1"
);
$schedStmt->execute(['mid' => $moduleId, 'type' => $examType]);
$sched = $schedStmt->fetch() ?: [];

// Once the student has signed out, the Entry Slip is no longer available — only the Attendance Slip remains.
if (!empty($sched['schedule_id'])) {
    $soutStmt = $db->prepare(
        "SELECT 1 FROM cat_exam_attendance_logs WHERE schedule_id = :s AND user_id = :u AND attendance_type = 'Sign Out'"
    );
    $soutStmt->execute(['s' => $sched['schedule_id'], 'u' => $me['user_id']]);
    if ($soutStmt->fetchColumn()) {
        http_response_code(403);
        echo 'This Entry Slip is no longer available because you have already signed out of this ' . e($examType) . '. '
            . 'Please use the ' . e($examType) . ' Attendance Slip instead: '
            . '<a href="' . APP_URL . '/student/evidence-slip.php?schedule_id=' . (int) $sched['schedule_id'] . '">View Attendance Slip</a>.';
        exit;
    }
}

$examDate = !empty($sched['scheduled_date']) ? $sched['scheduled_date'] : ($examType === 'CAT' ? $module['cat_date'] : $module['exam_date']);
$room = $sched['room'] ?? ($module['room'] ?? '—');
$invigilatorName = $sched['invigilator_name'] ?? '—';
$startTime = !empty($sched['start_time']) ? date('h:i A', strtotime($sched['start_time'])) : '—';
$endTime   = !empty($sched['end_time'])   ? date('h:i A', strtotime($sched['end_time']))   : '—';

$payload = json_encode(['module_id' => $moduleId, 'user_id' => $me['user_id'], 'exam_type' => $examType, 'exp' => time() + 31536000]);
$key = hash('sha256', APP_KEY !== '' ? APP_KEY : 'fallback-key', true);
$iv = random_bytes(16);
$cipher = openssl_encrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
$hmac = hash_hmac('sha256', $iv . $cipher, APP_KEY !== '' ? APP_KEY : 'fallback-key', true);
$b64u = function (string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); };
$verifyToken = $b64u($iv) . '.' . $b64u($cipher) . '.' . $b64u($hmac);
$verifyUrl = APP_URL . '/verify-slip.php?t=' . $verifyToken;
$qrPayload = json_encode([
    'type' => 'semas_slip',
    'slip' => 'entry',
    'verify_url' => $verifyUrl,
    'module_id' => $moduleId,
    'module_title' => $module['module_title'],
    'user_id' => $me['user_id'],
    'student_name' => $me['full_name'],
    'reg_number' => $me['reg_number'] ?? '',
    'exam_type' => $examType,
    'exam_date' => $examDate ? date('l, d F Y', strtotime($examDate)) : '',
    'room' => $room,
    'status' => $eligibilityRow['final_decision'] ?? 'Allowed',
    'time' => $startTime . ' – ' . $endTime,
    'issued_at' => date('c'),
    'sig' => $b64u(hash_hmac('sha256', json_encode([
        'module_id' => $moduleId,
        'user_id' => $me['user_id'],
        'exam_type' => $examType,
        'exam_date' => $examDate ? date('l, d F Y', strtotime($examDate)) : '',
        'room' => $room,
        'time' => $startTime . ' – ' . $endTime,
    ]), APP_KEY !== '' ? APP_KEY : 'fallback-key', true))
]);
$qrSig = substr($b64u(hash_hmac('sha256', $moduleId . '|' . $me['user_id'] . '|' . $examType . '|' . $examDate, APP_KEY !== '' ? APP_KEY : 'fallback-key', true)), 0, 12);
$qrPayload = implode('|', [
    'SE',
    (string) $moduleId,
    (string) $me['user_id'],
    strtoupper(substr($examType, 0, 1)),
    $examDate ? date('ymd', strtotime($examDate)) : '',
    substr(preg_replace('/\s+/', '', (string) ($me['reg_number'] ?? '')), 0, 12),
    $qrSig,
]);
$qrImage = SimpleQr::pngDataUri($qrPayload, 7, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($examType) ?> Entry Slip / <?= e($module['module_title']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Times New Roman', Times, serif; background: #FBF7EE; margin: 0; padding: 24px 0; color: #1B1F2A; }
  .page { max-width: 700px; margin: 0 auto; background: #ffffff; border: 2px solid #D4A24C; border-radius: 10px; overflow: hidden; }

  .header { background: #D4A24C; color: #1E2A52; padding: 18px 28px; display: flex; justify-content: space-between; align-items: center; }
  .header .brand { font-size: 22px; font-weight: 700; letter-spacing: 1px; }
  .header .brand span { color: #1E2A52; }
  .header .meta { text-align: right; font-size: 12px; color: #1E2A52; }

  .slip-title { text-align: center; padding: 20px 28px 14px; border-bottom: 2px solid #D4A24C; }
  .slip-title h2 { font-size: 18px; margin: 0 0 6px; text-transform: uppercase; letter-spacing: 2px; }
  .slip-title .sub { color: #6B7280; font-size: 12px; }

  .body { padding: 22px 28px 28px; }
  table.info { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.info td { padding: 8px 6px; border-bottom: 1px solid #F0E3C6; vertical-align: top; }
  table.info td.label { color: #6B7280; width: 38%; }
  table.info td.value { font-weight: 600; }

  .sig-section { margin-top: 20px; padding-top: 18px; border-top: 1px dashed #E4D6AF; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; }
  .sig-card { flex: 1 1 240px; background: #FFF7D9; border: 1px solid #F0E3C6; border-radius: 10px; padding: 16px; }
  .sig-card .title { color: #86610B; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 8px; }
  .sig-card .name { font-size: 16px; font-weight: 700; color: #1E2A52; margin-bottom: 4px; }
  .sig-card .role { color: #6B7280; font-size: 12px; margin-bottom: 8px; }
  .sig-card .note { color: #475569; font-size: 13px; line-height: 1.4; }

  .qr-section { flex: 0 0 205px; text-align: center; min-width: 205px; }
  .qr-section .qr-box { display: inline-block; padding: 10px; background: #ffffff; border: 2px solid #D4A24C; border-radius: 10px; width: 198px; height: 198px; }
  .qr-section .qr-box img { width: 174px; height: 174px; display: block; image-rendering: pixelated; }
  .qr-section .qr-label { margin-top: 8px; font-size: 11px; color: #1E2A52; font-weight: bold; }

  .footer { background: #FFF7D9; padding: 14px 28px; border-top: 1px solid #F0E3C6; font-size: 12px; color: #6B7280; text-align: center; }
  .footer a { color: #1E2A52; text-decoration: none; font-weight: 700; }

  .print-meta, .verification-url { display: block; }

  .no-print { margin: 18px auto 0; max-width: 700px; text-align: center; }
  .no-print button { padding: 10px 28px; background: #D4A24C; color: #1E2A52; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; }

  @media print {
    @page { size: A4 portrait; margin: 10mm; }
    html, body { margin: 0; padding: 0; background: #fff; }
    .no-print { display: none; }
    .print-meta, .verification-url { display: none !important; }
    .page { border-width: 1.5pt; }
    .header { padding: 14px 20px; }
    .slip-title { padding: 14px 20px 10px; }
    .body { padding: 14px 20px 20px; }
    .sig-card { padding: 12px; }
    .footer { padding: 10px 20px; font-size: 11px; }
  }
</style>
</head>
<body>

<div class="page">
  <div class="header">
    <div class="brand">
      <?php if (!empty($brandLogo)): ?>
        <img src="<?= e(APP_URL . '/' . ltrim($brandLogo, '/')) ?>" alt="SEMAS Logo" style="height:36px; max-height:40px;">
      <?php else: ?>
        SEM<span>AS</span>
      <?php endif; ?>
    </div>
    <div class="meta print-meta">Issued: <?= e(date('d F Y, h:i A')) ?></div>
  </div>
  <div class="slip-title">
    <h2><?= e($examType) ?> Entry Slip</h2>
    <div class="sub"><?= e($module['module_title']) ?> · <?= e($examDate ? date('l, d F Y', strtotime($examDate)) : '—') ?></div>
  </div>

  <div class="body">
    <table class="info">
      <tr><td class="label">Student Name</td><td class="value"><?= e($me['full_name']) ?></td></tr>
      <tr><td class="label">Registration Number</td><td class="value"><?= e($me['reg_number'] ?? '—') ?></td></tr>
      <tr><td class="label">Department</td><td class="value"><?= e($module['department_name'] ?? '—') ?></td></tr>
      <tr><td class="label">Module</td><td class="value"><?= e($module['module_title']) ?></td></tr>
      <tr><td class="label">Assessment Type</td><td class="value"><?= e($examType) ?></td></tr>
      <tr><td class="label"><?= e($examType) ?> Date</td><td class="value"><?= e($examDate ? date('l, d F Y', strtotime($examDate)) : '—') ?></td></tr>
      <tr><td class="label">Session</td><td class="value"><?= e($module['session_type'] ?? '—') ?></td></tr>
      <tr><td class="label">Room</td><td class="value"><?= e($room) ?></td></tr>
      <tr><td class="label">Time</td><td class="value"><?= e($startTime) ?> – <?= e($endTime) ?></td></tr>
      <tr><td class="label">Approval Status</td><td class="value"><?= e($eligibilityRow['final_decision'] ?? 'Allowed') ?></td></tr>
    </table>

    <div class="sig-section">
      <div class="sig-card">
        <div class="title">Approved by</div>
        <div class="name"><?= e($approverName) ?></div>
        <div class="role"><?= e($approverRole) ?></div>
        <div class="note">Approval time: <?= e($approvedAt ?? date('d F Y, h:i A')) ?></div>
      </div>
      <div class="qr-section">
        <div class="qr-box" id="verifyQr"><img src="<?= e($qrImage) ?>" alt="Scan to verify"></div>
        <div class="qr-label">Scan to verify</div>
      </div>
    </div>
  </div>

  <div class="footer verification-url">
    Verification URL: <a href="<?= e($verifyUrl) ?>" target="_blank"><?= e($verifyUrl) ?></a>
  </div>
</div>

<div class="no-print" style="margin-top:12px;"><button onclick="window.print()">Print / Save as PDF</button></div>

</body>
</html>
