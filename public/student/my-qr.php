<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$pageTitle = 'My QR Code';
$activeNav = 'dashboard';
$user = Auth::user();

$payload = json_encode(['user_id' => $user['user_id'], 'reg_number' => $user['reg_number'], 'exp' => time() + 3600]);
$key = hash('sha256', APP_KEY !== '' ? APP_KEY : 'fallback-key-change-me', true);
$iv = random_bytes(16);
$cipher = openssl_encrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
$hmac = hash_hmac('sha256', $iv . $cipher, APP_KEY !== '' ? APP_KEY : 'fallback-key-change-me', true);
$qrString = rtrim(strtr(base64_encode($iv), '+/', '-_'), '=') . '.'
          . rtrim(strtr(base64_encode($cipher), '+/', '-_'), '=') . '.'
          . rtrim(strtr(base64_encode($hmac), '+/', '-_'), '=');

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">My QR Code</h4>
<p class="text-muted small mb-4">Show this to event staff for manual check-in, or scan an event's own QR code from your dashboard.</p>

<div class="semas-card p-4 mx-auto text-center" style="max-width:420px;">
  <h6 class="display-font text-start mb-1"><?= e($user['full_name']) ?></h6>
  <p class="text-muted small text-start mb-3">Reg. No. <?= e($user['reg_number'] ?? '—') ?></p>
  <div class="qr-frame">
    <div class="corner c1"></div><div class="corner c2"></div><div class="corner c3"></div><div class="corner c4"></div>
    <div id="qr-canvas"></div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
  <script>
    new QRCode(document.getElementById('qr-canvas'), {
      text: <?= json_encode($qrString) ?>, width: 200, height: 200, colorDark: "#1E2A52", colorLight: "#ffffff"
    });
  </script>
  <p class="text-muted mt-3" style="font-size:0.72rem;">This code expires one hour after the page loads and refreshes automatically when you reload this page.</p>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
