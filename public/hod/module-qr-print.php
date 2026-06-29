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

$scanUrl = APP_URL . '/student/class-scan.php?module_id=' . $moduleId . '&t=' . urlencode($module['module_qr_secret']);
$brandName = Settings::get('university_name', 'University of Kigali');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Module QR — <?= e($module['module_title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  body { font-family: 'Inter', sans-serif; background: #fff; margin: 0; padding: 40px; text-align: center; }
  h1 { font-family: 'Sora', sans-serif; font-size: 1.5rem; color: #1E2A52; margin-bottom: 4px; }
  p  { color: #555; font-size: 0.9rem; margin: 4px 0; }
  .qr-box { border: 3px solid #1E2A52; border-radius: 12px; display: inline-block; padding: 20px; margin: 24px auto; }
  .instruction { background: #f8f3e8; border-radius: 8px; padding: 12px 20px; display: inline-block; margin-top: 12px; font-size: 0.85rem; color: #333; }
  .meta { margin-top: 20px; font-size: 0.8rem; color: #888; }
  @media print {
    .no-print { display: none; }
    body { padding: 20px; }
  }
</style>
</head>
<body>
<p style="color:#888;font-size:0.8rem;"><?= e($brandName) ?></p>
<h1><?= e($module['module_title']) ?></h1>
<p><?= e($module['department_name'] ?? '') ?> &middot; <?= e($module['session_type'] ?? 'Any Session') ?></p>
<?php if ($module['room']): ?>
  <p><strong>Room:</strong> <?= e($module['room']) ?></p>
<?php endif; ?>
<p>Lecturer: <?= e($module['lecturer_name'] ?? '—') ?></p>

<div class="qr-box">
  <canvas id="qrCanvas"></canvas>
</div>

<div class="instruction">
  <strong>Students:</strong> Scan this QR code with your phone camera to mark your attendance.<br>
  Sign in within the first 10 minutes to be marked <strong>Present</strong>
</div>

<div class="meta">
  CAT: <?= e($module['cat_date'] ?? '—') ?> &nbsp;|&nbsp; Exam: <?= e($module['exam_date'] ?? '—') ?>
</div>

<p class="mt-4 no-print">
  <button onclick="window.print()" style="padding:8px 24px;background:#D4A24C;color:#fff;border:none;border-radius:6px;font-size:0.9rem;cursor:pointer;">
    Print / Save as PDF
  </button>
</p>

<script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
<script>
QRCode.toCanvas(document.getElementById('qrCanvas'), <?= json_encode($scanUrl) ?>, {
  width: 280, margin: 2,
  color: { dark: '#1E2A52', light: '#ffffff' }
}, function (err) { if (err) console.error(err); });
</script>
</body>
</html>
