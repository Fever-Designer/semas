<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Student QR Code';
$token = trim((string) ($_GET['u'] ?? ''));
$valid = false;

if ($token !== '') {
    $secret = APP_KEY !== '' ? APP_KEY : 'fallback-key-change-me';
    if (preg_match('/^SEMASU:(\d+):(\d+):([0-9a-f]{8}):([0-9a-f]{20})$/i', $token, $m)) {
        $expected = substr(hash_hmac('sha256', $m[1] . '|' . $m[2] . '|' . strtolower($m[3]), $secret), 0, 20);
        $valid = hash_equals($expected, strtolower($m[4])) && (int) $m[2] >= time();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - SEMAS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3" style="background:#f5f7fb;">
  <div class="semas-card p-4 text-center" style="max-width:480px;">
    <?php if ($valid): ?>
      <i class="bi bi-qr-code" style="font-size:2.5rem;color:var(--semas-ink);"></i>
      <h4 class="display-font mt-2 mb-2">Student QR Code</h4>
      <p class="text-muted small mb-0">This is a valid SEMAS student QR code. A staff member should scan it inside SEMAS to mark attendance.</p>
    <?php else: ?>
      <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size:2.5rem;"></i>
      <h4 class="display-font mt-2 mb-2">QR Code Expired</h4>
      <p class="text-muted small mb-0">Ask the student to reload their My QR Code page and try again.</p>
    <?php endif; ?>
  </div>
</body>
</html>
