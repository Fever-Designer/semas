<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$pageTitle = 'Scan to Check In';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - SEMAS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body style="background:var(--semas-surface);">
<div style="max-width:480px;margin:32px auto;padding:0 16px;">
  <div class="semas-card p-3">
    <h6 class="display-font mb-2"><i class="bi bi-qr-code-scan me-1"></i> Scan Event QR Code</h6>
    <p class="text-muted small">Point your camera at the QR code displayed at the venue. Your device's GPS location is checked against the venue before attendance is recorded.</p>
    <div id="reader" style="width:100%;"></div>
    <div id="result" class="mt-3"></div>
  </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
function onScanSuccess(decodedText) {
  html5QrCode.stop();
  document.getElementById('result').innerHTML = '<p class="text-muted small">Getting your location...</p>';

  if (!navigator.geolocation) {
    document.getElementById('result').innerHTML = '<div class="alert alert-danger small">GPS is not supported on this device/browser.</div>';
    return;
  }

  navigator.geolocation.getCurrentPosition(function (pos) {
    submitCheckin(decodedText, pos.coords.latitude, pos.coords.longitude);
  }, function () {
    document.getElementById('result').innerHTML = '<div class="alert alert-danger small">Could not get your location. Please enable GPS/location permissions and try again.</div>';
  }, { enableHighAccuracy: true, timeout: 10000 });
}

function submitCheckin(qrUrl, lat, lng) {
  const url = new URL(qrUrl);
  const eventId = url.searchParams.get('event_id');
  const token = url.searchParams.get('t');

  fetch('<?= APP_URL ?>/api/checkin.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ event_id: eventId, token: token, latitude: lat, longitude: lng, csrf_token: '<?= csrf_token() ?>' })
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      document.getElementById('result').innerHTML =
        '<div class="alert alert-success text-center"><i class="bi bi-check-circle-fill" style="font-size:2rem;color:var(--semas-success);"></i>' +
        '<h6 class="display-font mt-2">Attendance Confirmed</h6><p class="small mb-0">' + data.message + '</p></div>';
    } else {
      document.getElementById('result').innerHTML = '<div class="alert alert-danger small">' + data.message + '</div>';
    }
  })
  .catch(() => {
    document.getElementById('result').innerHTML = '<div class="alert alert-danger small">Network error. Please try again.</div>';
  });
}

const html5QrCode = new Html5Qrcode("reader");
html5QrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: 240 }, onScanSuccess)
  .catch(err => { document.getElementById('result').innerHTML = '<div class="alert alert-danger small">Camera error: ' + err + '</div>'; });
</script>
</body></html>
