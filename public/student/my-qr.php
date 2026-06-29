<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$pageTitle = 'Event QR Code';
$activeNav = 'events';
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
<h4 class="display-font mb-3">Event QR Code</h4>

<ul class="nav nav-pills mb-3" id="qrTabs">
  <li class="nav-item">
    <a class="nav-link active" id="tab-qr" href="#" onclick="switchTab('qr');return false;">
      <i class="bi bi-qr-code me-1"></i> My QR Code
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" id="tab-scan" href="#" onclick="switchTab('scan');return false;">
      <i class="bi bi-qr-code-scan me-1"></i> Scan to Check In
    </a>
  </li>
</ul>

<!-- Tab: My QR Code -->
<div id="pane-qr">
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
    <p class="text-muted mt-3" style="font-size:0.72rem;">Expires one hour after page load. Reload to refresh.</p>
  </div>
</div>

<!-- Tab: Scan to Check In -->
<div id="pane-scan" style="display:none;">
  <div class="semas-card p-3 mx-auto" style="max-width:480px;">
    <div id="reader" style="width:100%;"></div>
    <div id="result" class="mt-3"></div>
  </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
var html5QrCode = null;
var scannerRunning = false;

function switchTab(tab) {
  document.getElementById('pane-qr').style.display   = tab === 'qr'   ? '' : 'none';
  document.getElementById('pane-scan').style.display  = tab === 'scan' ? '' : 'none';
  document.getElementById('tab-qr').classList.toggle('active',   tab === 'qr');
  document.getElementById('tab-scan').classList.toggle('active',  tab === 'scan');

  if (tab === 'scan' && !scannerRunning) {
    startScanner();
  } else if (tab === 'qr' && scannerRunning) {
    html5QrCode.stop().then(function() { scannerRunning = false; });
  }
}

function startScanner() {
  if (!html5QrCode) html5QrCode = new Html5Qrcode('reader');
  html5QrCode.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: 240 },
    onScanSuccess
  ).then(function() {
    scannerRunning = true;
  }).catch(function(err) {
    document.getElementById('result').innerHTML =
      '<div class="alert alert-danger small">Camera error: ' + err + '</div>';
  });
}

function onScanSuccess(decodedText) {
  html5QrCode.stop().then(function() { scannerRunning = false; });
  document.getElementById('result').innerHTML = '<p class="text-muted small">Getting your location...</p>';

  if (!navigator.geolocation) {
    document.getElementById('result').innerHTML =
      '<div class="alert alert-danger small">GPS is not supported on this device/browser.</div>';
    return;
  }

  navigator.geolocation.getCurrentPosition(function(pos) {
    submitCheckin(decodedText, pos.coords.latitude, pos.coords.longitude);
  }, function() {
    document.getElementById('result').innerHTML =
      '<div class="alert alert-danger small">Could not get your location. Enable GPS and try again.</div>';
  }, { enableHighAccuracy: true, timeout: 10000 });
}

function submitCheckin(qrUrl, lat, lng) {
  var url = new URL(qrUrl);
  var eventId = url.searchParams.get('event_id');
  var token   = url.searchParams.get('t');

  fetch('<?= APP_URL ?>/api/checkin.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ event_id: eventId, token: token, latitude: lat, longitude: lng, csrf_token: '<?= csrf_token() ?>' })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (data.ok) {
      document.getElementById('result').innerHTML =
        '<div class="alert alert-success text-center">' +
        '<i class="bi bi-check-circle-fill" style="font-size:2rem;color:var(--semas-success);"></i>' +
        '<h6 class="display-font mt-2">Attendance Confirmed</h6>' +
        '<p class="small mb-0">' + data.message + '</p>' +
        '<button class="btn btn-sm btn-semas-gold mt-2" onclick="resetScanner()">Scan Another</button>' +
        '</div>';
    } else {
      document.getElementById('result').innerHTML =
        '<div class="alert alert-danger small">' + data.message +
        '<br><button class="btn btn-sm btn-outline-secondary mt-2" onclick="resetScanner()">Try Again</button></div>';
    }
  })
  .catch(function() {
    document.getElementById('result').innerHTML =
      '<div class="alert alert-danger small">Network error. Please try again.' +
      '<br><button class="btn btn-sm btn-outline-secondary mt-2" onclick="resetScanner()">Try Again</button></div>';
  });
}

function resetScanner() {
  document.getElementById('result').innerHTML = '';
  startScanner();
}
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
