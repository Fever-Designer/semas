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
$brandName = 'UNIVERSITY';
$securityRef = strtoupper(substr(hash_hmac('sha256', (string) $moduleId, $secret), 0, 16));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Module QR / <?= e($module['module_title']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; background: #f3f4f6; margin: 0; padding: 18px; text-align: center; color: #111827; }
  .sheet { max-width: 760px; margin: 0 auto; background:#fff; border: 7px solid #1E2A52; padding: 28px 34px; display: flex; flex-direction: column; align-items: stretch; gap: 18px; }
  .header { padding-bottom:16px; border-bottom:3px solid #D4A24C; }
  .brand { color:#6B7280; font-size: 21px; font-weight: 700; margin: 0 0 6px; }
  h1 { font-size: 40px; line-height: 1.08; color: #1E2A52; margin: 4px 0 0; text-transform: uppercase; }
  .details { display:grid; grid-template-columns:1fr 1fr; gap:10px; text-align:left; }
  .info { padding:10px 12px; border:1px solid #D7DCE8; border-radius:7px; font-size:17px; font-weight:700; background:#F8FAFC; }
  .info.wide { grid-column:1 / -1; }
  .info span { display:block; color:#6B7280; font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; margin-bottom:3px; }
  .qr-area { display:flex; flex-direction:column; align-items:center; }
  .qr-box { border: 5px solid #1E2A52; border-radius: 12px; display: inline-block; padding: 12px; margin: 0 auto 12px; background:#fff; }
  .qr-box img { width: 370px; height: 370px; display:block; image-rendering: pixelated; }
  .instruction { background: #FFF8E8; border: 2px solid #D4A24C; border-radius: 8px; padding: 10px 18px; font-size: 18px; color: #1E2A52; font-weight: 700; }
  .meta { margin-top: 10px; font-size: 14px; color: #4B5563; font-weight: 700; }
  .payload { margin-top: 6px; font-size: 9px; color: #9CA3AF; word-break: break-all; }
  .security-strip { margin-top:10px; padding:6px 12px; border:1px dashed #1E2A52; border-radius:5px; font-size:9px; letter-spacing:1.2px; color:#1E2A52; background:#F8FAFC; }
  @media print {
    .no-print { display: none; }
    @page { size: A4 portrait; margin: 8mm; }
    html, body { margin: 0; padding: 0; background:#fff; }
    .sheet { width: 190mm; max-width:none; max-height:267mm; margin:0 auto; padding:9mm 11mm; gap:4mm; border-width:5px; overflow:hidden; break-inside:avoid; page-break-inside:avoid; page-break-after:avoid; }
    .header { padding-bottom:3mm; }
    .brand { font-size:18px; }
    h1 { font-size:30px; }
    .details { gap:2mm; }
    .info { padding:2mm 3mm; font-size:14px; }
    .qr-box { padding:3mm; margin-bottom:3mm; border-width:4px; }
    .qr-box img { width:92mm; height:92mm; }
    .instruction { padding:2mm 4mm; font-size:15px; }
    .meta { margin-top:2mm; font-size:12px; }
    .security-strip { margin-top:2mm; padding:1.5mm 3mm; font-size:8px; }
    .payload { display:none; }
  }
</style>
</head>
<body>
<div class="sheet">
  <div class="header">
    <p class="brand"><?= e($brandName) ?></p>
    <h1><?= e($module['module_title']) ?></h1>
  </div>

  <div class="details">
    <div class="info"><span>Department</span><?= e($module['department_name'] ?? '-') ?></div>
    <div class="info"><span>Session</span><?= e($module['session_type'] ?? 'Any Session') ?></div>
    <div class="info"><span>Start Date</span><?= !empty($module['start_date']) ? e(date('d M Y', strtotime($module['start_date']))) : '-' ?></div>
    <div class="info"><span>End Date</span><?= !empty($module['end_date']) ? e(date('d M Y', strtotime($module['end_date']))) : '-' ?></div>
    <?php if (!empty($module['room'])): ?><div class="info"><span>Room</span><?= e($module['room']) ?></div><?php endif; ?>
    <div class="info <?= empty($module['room']) ? 'wide' : '' ?>"><span>Lecturer</span><?= e($module['lecturer_name'] ?? '-') ?></div>
  </div>

  <div class="qr-area">
    <div class="qr-box">
      <img src="<?= e($qrImage) ?>" alt="Class attendance QR code">
    </div>
    <div class="instruction">Students: scan this QR to mark class attendance.</div>
    <div class="meta">CAT: <?= e($module['cat_date'] ?? '-') ?> &nbsp;|&nbsp; Exam: <?= e($module['exam_date'] ?? '-') ?></div>
    <div class="security-strip">ORIGINAL CLASSROOM QR &middot; REF <?= e($securityRef) ?></div>
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
