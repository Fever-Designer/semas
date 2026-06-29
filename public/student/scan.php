<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$pageTitle = 'Scan QR Code';
$activeNav = 'attendance';

require __DIR__ . '/../partials/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="display-font mb-0">Scan Attendance QR</h4>
  <a href="<?= APP_URL ?>/student/attendance.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i> Back to Attendance
  </a>
</div>

<!-- Result / status alert -->
<div id="scanResult" class="alert d-none mb-3" role="alert" style="font-size:.95rem;"></div>

<div class="semas-card p-3">
  <p class="text-muted small mb-3 text-center">
    <i class="bi bi-info-circle me-1"></i>
    Point your camera at the <strong>rotating QR code on the lecturer's screen</strong>.
    Hold the phone <strong>1–2 metres away</strong> &mdash; too far and it will not register.
  </p>

  <div class="text-center mb-2">
    <div style="position:relative;display:inline-block;width:100%;max-width:440px;">
      <video id="camVideo" autoplay playsinline muted
             style="width:100%;border-radius:8px;background:#000;"></video>
      <canvas id="camCanvas" style="display:none;"></canvas>
      <!-- Corner-bracket overlay -->
      <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:60%;padding-top:60%;pointer-events:none;">
        <div style="position:absolute;inset:0;border:3px solid rgba(255,255,255,.4);border-radius:6px;"></div>
        <div style="position:absolute;top:0;left:0;width:22px;height:22px;border-top:3px solid #fff;border-left:3px solid #fff;border-radius:2px 0 0 0;"></div>
        <div style="position:absolute;top:0;right:0;width:22px;height:22px;border-top:3px solid #fff;border-right:3px solid #fff;border-radius:0 2px 0 0;"></div>
        <div style="position:absolute;bottom:0;left:0;width:22px;height:22px;border-bottom:3px solid #fff;border-left:3px solid #fff;border-radius:0 0 0 2px;"></div>
        <div style="position:absolute;bottom:0;right:0;width:22px;height:22px;border-bottom:3px solid #fff;border-right:3px solid #fff;border-radius:0 0 2px 0;"></div>
      </div>
    </div>
  </div>

  <p class="text-center mb-0 small" id="scanStatus" style="min-height:1.5em;">
    <span class="text-muted">Initialising camera&hellip;</span>
  </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js" crossorigin="anonymous"></script>
<script>
const CSRF        = '<?= csrf_token() ?>';
const BASE        = window.SEMAS_BASE_URL;
const MIN_QR_FRAC = 0.15;
const SCAN_DELAY  = 200;
let scanning      = true;
let lastData      = '';

const video   = document.getElementById('camVideo');
const canvas  = document.getElementById('camCanvas');
const ctx     = canvas.getContext('2d', { willReadFrequently: true });
const status  = document.getElementById('scanStatus');
const result  = document.getElementById('scanResult');

function setStatus(html, cls) {
    status.innerHTML = '<span class="' + (cls || 'text-muted') + '">' + html + '</span>';
}
function showResult(ok, msg) {
    result.className  = 'alert mb-3 ' + (ok ? 'alert-success' : 'alert-danger');
    result.innerHTML  = (ok ? '<i class="bi bi-check-circle-fill me-2"></i>' : '<i class="bi bi-x-circle-fill me-2"></i>') + msg;
    result.classList.remove('d-none');
}

async function startCamera() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
        });
        video.srcObject = stream;
        video.addEventListener('loadedmetadata', function () {
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            setStatus('Scanning&hellip; hold QR code at 1&ndash;2 metres.', 'text-muted');
            scheduleFrame();
        });
    } catch (err) {
        setStatus('Camera access denied. Allow camera permission and reload.', 'text-danger');
    }
}

function scheduleFrame() {
    if (!scanning) return;
    setTimeout(processFrame, SCAN_DELAY);
}

function processFrame() {
    if (!scanning) return;
    if (video.readyState < video.HAVE_ENOUGH_DATA) { scheduleFrame(); return; }

    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const qr      = jsQR(imgData.data, imgData.width, imgData.height, { inversionAttempts: 'dontInvert' });

    if (!qr) { setStatus('Scanning&hellip; hold QR code at 1&ndash;2 metres.', 'text-muted'); scheduleFrame(); return; }

    const qrW  = Math.abs(qr.location.topRightCorner.x - qr.location.topLeftCorner.x);
    const frac = qrW / canvas.width;
    if (frac < MIN_QR_FRAC) {
        setStatus('QR detected &mdash; move <strong>closer</strong> (1&ndash;2 metres).', 'text-warning');
        scheduleFrame();
        return;
    }

    const raw = qr.data.trim();
    if (!/^SEMAS:\d+:\d+:[0-9a-f]+$/.test(raw)) {
        setStatus('Not a SEMAS QR code. Scan the lecturer\'s attendance QR.', 'text-warning');
        scheduleFrame();
        return;
    }

    if (raw === lastData) { scheduleFrame(); return; }
    lastData = raw;
    scanning = false;
    setStatus('<span class="spinner-border spinner-border-sm me-1"></span> Processing&hellip;', 'text-primary');
    submitQr(raw);
}

async function submitQr(qrData) {
    const parts = qrData.split(':');
    const body  = new URLSearchParams({
        qr_data:    qrData,
        module_id:  parts[1] || '',
        session_id: parts[2] || '',
        csrf_token: CSRF,
    });
    try {
        const r    = await fetch(BASE + '/api/student-attendance-scan.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    body.toString(),
        });
        const data = await r.json();
        showResult(data.ok, data.message || (data.ok ? 'Attendance recorded.' : 'An error occurred.'));
        setStatus(data.ok ? 'Done! Redirecting&hellip;' : 'Tap "Back" or wait to retry.', data.ok ? 'text-success' : 'text-danger');
        if (data.ok) {
            setTimeout(function () { window.location.href = BASE + '/student/attendance.php'; }, 2000);
        } else {
            setTimeout(function () { lastData = ''; scanning = true; scheduleFrame(); }, 3500);
        }
    } catch (_) {
        showResult(false, 'Network error. Check your connection and try again.');
        setStatus('Network error.', 'text-danger');
        setTimeout(function () { lastData = ''; scanning = true; scheduleFrame(); }, 3500);
    }
}

startCamera();
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
