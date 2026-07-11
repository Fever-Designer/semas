<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD', 'Coordinator']);

$db       = Database::connection();
$moduleId = (int) ($_GET['module_id'] ?? 0);
$stmt     = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name
     FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l   ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u       ON u.user_id = l.user_id
     WHERE m.module_id = :id"
);
$stmt->execute(['id' => $moduleId]);
$module = $stmt->fetch();

if (!$module || !$module['module_qr_secret'] || (Auth::role() === 'Coordinator' && $module['session_type'] !== 'Weekend')) {
    http_response_code(404);
    die('Module not found or QR not generated.');
}

$b64u = function (string $binary): string {
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
};
$secret = (string) $module['module_qr_secret'];
$shortToken = ctype_xdigit($secret)
    ? $b64u(hex2bin($secret) ?: $secret)
    : $secret;
$scanPayload = 'SM:' . $moduleId . ':' . $shortToken;
$scanUrl = public_url('/student/attendance.php?module_id=' . $moduleId . '&t=' . rawurlencode($scanPayload));
$qrImage = SimpleQr::pngDataUri($scanUrl, 10, 2);
$brandName = Settings::get('university_name', 'University of Kigali');
$securityRef = strtoupper(substr(hash_hmac('sha256', (string) $moduleId, $secret), 0, 16));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Module QR / <?= e($module['module_title']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; background: #fff; margin: 0; padding: 18px; text-align: center; color: #111827; }
  .sheet { min-height: calc(100vh - 36px); border: 8px solid #1E2A52; padding: 24px; display: flex; flex-direction: column; align-items: center; justify-content: space-between; }
  .brand { color:#6B7280; font-size: 24px; font-weight: 700; margin: 0 0 8px; }
  h1 { font-size: 48px; line-height: 1.05; color: #1E2A52; margin: 8px 0 14px; text-transform: uppercase; }
  .info { font-size: 30px; font-weight: 700; margin: 8px 0; }
  .info span { color: #6B7280; font-weight: 600; }
  .qr-box { border: 6px solid #1E2A52; border-radius: 10px; display: inline-block; padding: 16px; margin: 22px auto; background:#fff; }
  .qr-box img { width: 430px; height: 430px; display:block; image-rendering: pixelated; }
  .instruction { background: #f8f3e8; border: 3px solid #D4A24C; border-radius: 8px; padding: 16px 24px; display: inline-block; margin-top: 8px; font-size: 24px; color: #1f2937; font-weight: 700; }
  .meta { margin-top: 16px; font-size: 20px; color: #4B5563; font-weight: 700; }
  .payload { margin-top: 6px; font-size: 11px; color: #9CA3AF; word-break: break-all; }
  .security-strip { width:100%; padding:8px; border-top:2px dashed #1E2A52; border-bottom:2px dashed #1E2A52; font-size:13px; letter-spacing:2px; color:#1E2A52; background:repeating-linear-gradient(135deg,#fff 0,#fff 8px,#f8f3e8 8px,#f8f3e8 16px); }
  @media print {
    .no-print { display: none; }
    @page { size: A4 portrait; margin: 8mm; }
    body { padding: 0; }
    .sheet { min-height: calc(100vh - 16mm); }
  }
</style>
</head>
<body>
<div class="sheet">
  <div>
    <p class="brand"><?= e($brandName) ?></p>
    <h1><?= e($module['module_title']) ?></h1>
    <div class="info"><?= e($module['department_name'] ?? '') ?></div>
    <div class="info"><span>Session:</span> <?= e($module['session_type'] ?? 'Any Session') ?></div>
    <?php if ($module['room']): ?>
      <div class="info"><span>Room:</span> <?= e($module['room']) ?></div>
    <?php endif; ?>
    <div class="info"><span>Lecturer:</span> <?= e($module['lecturer_name'] ?? '-') ?></div>
  </div>

  <div>
    <div class="security-strip">ORIGINAL CLASSROOM QR &middot; REF <?= e($securityRef) ?> &middot; SESSION/TIME VALIDATED</div>
    <div class="qr-box">
      <img src="<?= e($qrImage) ?>" alt="Class attendance QR code">
    </div>
    <div class="instruction">Students: scan this QR to mark class attendance.</div>
    <div class="meta">Scans are accepted only during the official sign-in and sign-out windows.</div>
    <div class="meta">CAT: <?= e($module['cat_date'] ?? '-') ?> &nbsp;|&nbsp; Exam: <?= e($module['exam_date'] ?? '-') ?></div>
    <div class="payload"><?= e($scanUrl) ?></div>
  </div>
</div>

<p class="no-print">
  <button onclick="window.print()" style="padding:10px 28px;background:#D4A24C;color:#fff;border:none;border-radius:6px;font-size:1rem;cursor:pointer;">
    Print / Save as PDF
  </button>
</p>
</body>
</html>
