<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Dean']);

$pageTitle = 'Scan / Mark Attendance';
$activeNav = 'attendance';
$db = Database::connection();
EventLifecycle::sync($db);
$events = $db->query("SELECT event_id, title, event_date FROM events WHERE status = 'Ongoing' ORDER BY event_date")->fetchAll();
$selectedEventId = (int) ($_GET['event_id'] ?? 0);

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Scan / Mark Attendance</h4>
<p class="text-muted small mb-4">
  Point your camera at the student ID card barcode or QR code, then check the card before confirming attendance.
  Either way, nothing is saved until you confirm the preview below.
</p>

<div class="semas-card p-3 mb-3">
  <label class="form-label small">Event</label>
  <select id="eventSelect" class="form-select" style="max-width:420px;">
    <option value="">Select an event...</option>
    <?php foreach ($events as $ev): ?><option value="<?= (int) $ev['event_id'] ?>" <?= $selectedEventId === (int) $ev['event_id'] ? 'selected' : '' ?>><?= e($ev['title']) ?> &middot; <?= e($ev['event_date']) ?></option><?php endforeach; ?>
  </select>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="semas-card p-3">
      <h6 class="display-font mb-2"><i class="bi bi-qr-code-scan me-1"></i> Method 2: Check Student Card</h6>
      <p class="text-muted small mb-2">Keep the card in view until the student preview appears.</p>
      <div id="reader" style="width:100%;"></div>
      <button id="startScanBtn" class="btn btn-sm btn-semas-gold mt-2"><i class="bi bi-camera me-1"></i> Check Card</button>
      <div id="scanHint" class="text-muted small mt-2" style="display:none;">Scanning... keep the card in view.</div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="semas-card p-3">
      <h6 class="display-font mb-2"><i class="bi bi-search me-1"></i> Method 3: Manual Search</h6>
      <form id="searchForm" class="d-flex gap-2 mb-2" onsubmit="return false;">
        <input id="searchBox" class="form-control">
        <button id="searchBtn" class="btn btn-semas text-nowrap">Search</button>
      </form>
      <div id="searchResults"></div>
      <div id="foundBar" class="alert alert-success small d-none mt-2 d-flex justify-content-between align-items-center">
        <span id="foundText"></span>
        <button id="confirmFoundBtn" class="btn btn-sm btn-semas-gold">Confirm &amp; View Profile</button>
      </div>
    </div>
  </div>
</div>

<div id="previewPanel" class="semas-card p-3 mt-3" style="display:none;">
  <h6 class="display-font mb-3">Student Profile / Confirm Attendance</h6>
  <div class="d-flex gap-3">
    <img id="prevPhoto" src="" style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid var(--semas-gold);">
    <div>
      <div class="fw-semibold" id="prevName"></div>
      <div class="text-muted small" id="prevReg"></div>
      <div class="text-muted small" id="prevDept"></div>
      <div class="text-muted small" id="prevFaculty"></div>
      <div class="text-muted small" id="prevSession"></div>
    </div>
  </div>
  <div id="prevWarning" class="alert alert-warning small mt-3" style="display:none;"></div>
  <div class="mt-3">
    <button id="confirmBtn" class="btn btn-semas">Confirm Attendance</button>
    <button id="cancelBtn" class="btn btn-outline-dark">Cancel</button>
  </div>
</div>
<div id="resultMsg" class="mt-3"></div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const APP_URL = window.SEMAS_BASE_URL;
const CSRF = '<?= csrf_token() ?>';
let pendingPreview = null;
let html5QrCode = null;

function getEventId() {
  const id = document.getElementById('eventSelect').value;
  if (!id) alert('Please select an event first.');
  return id;
}

function showPreview(data, method) {
  if (!data.ok) { document.getElementById('resultMsg').innerHTML = '<div class="alert alert-danger small">' + data.message + '</div>'; return; }
  pendingPreview = { user_id: data.student.user_id, method: method };
  document.getElementById('prevPhoto').src = data.student.photo_url;
  document.getElementById('prevName').textContent = data.student.full_name;
  document.getElementById('prevReg').textContent = 'Reg. No: ' + (data.student.reg_number || '/');
  document.getElementById('prevDept').textContent = 'Department: ' + (data.student.department || '/');
  document.getElementById('prevFaculty').textContent = 'Faculty: ' + (data.student.faculty || '/');
  document.getElementById('prevSession').textContent = 'Session: ' + (data.student.session_type || '/');
  const warn = document.getElementById('prevWarning');
  if (data.already_marked) {
    warn.style.display = '';
    warn.textContent = 'This student is already marked present (at ' + data.checkin_time + '). Confirming again will be blocked.';
  } else {
    warn.style.display = 'none';
  }
  document.getElementById('previewPanel').style.display = '';
}

document.getElementById('startScanBtn').addEventListener('click', function () {
  const eventId = getEventId();
  if (!eventId) return;
  document.getElementById('scanHint').style.display = '';
  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Checking...';
  html5QrCode = new Html5Qrcode("reader");
  html5QrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: 240 }, function (decodedText) {
    html5QrCode.stop().finally(function () {
      document.getElementById('startScanBtn').disabled = false;
      document.getElementById('startScanBtn').innerHTML = '<i class="bi bi-camera me-1"></i> Check Card';
      document.getElementById('scanHint').style.display = 'none';
    });
    fetch(APP_URL + '/api/admin-scan-preview.php?mode=qr&event_id=' + eventId + '&token=' + encodeURIComponent(decodedText))
      .then(r => r.json()).then(data => showPreview(data, 'qr'));
  }).catch(function () {
    document.getElementById('startScanBtn').disabled = false;
    document.getElementById('startScanBtn').innerHTML = '<i class="bi bi-camera me-1"></i> Check Card';
    document.getElementById('scanHint').style.display = 'none';
    document.getElementById('resultMsg').innerHTML = '<div class="alert alert-danger small">Camera not available. Allow camera permission and try again.</div>';
  });
});

let searchTimer;
function doManualSearch() {
  const q = document.getElementById('searchBox').value;
  const eventId = document.getElementById('eventSelect').value;
  if (!eventId) { alert('Please select an event first.'); return; }
  if (q.length < 2) { document.getElementById('searchResults').innerHTML = ''; return; }
  fetch(APP_URL + '/api/admin-scan-preview.php?mode=search&event_id=' + eventId + '&q=' + encodeURIComponent(q))
    .then(r => r.json()).then(function (data) {
      document.getElementById('searchResults').innerHTML = (data.results || []).map(function (s) {
        return '<div class="border-bottom py-1 small" style="cursor:pointer;" data-uid="' + s.user_id + '" data-name="' + s.full_name + '" data-reg="' + (s.reg_number || '') + '">' +
               s.full_name + ' <span class="text-muted">(' + (s.reg_number || '/') + ')</span></div>';
      }).join('') || '<p class="text-muted small mb-0">No matching students found.</p>';
    });
}
document.getElementById('searchBtn').addEventListener('click', doManualSearch);
document.getElementById('searchBox').addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(doManualSearch, 300); });
document.getElementById('searchBox').addEventListener('keydown', function (e) { if (e.key === 'Enter') doManualSearch(); });

let foundUserId = null;
document.getElementById('searchResults').addEventListener('click', function (e) {
  const row = e.target.closest('[data-uid]');
  if (!row) return;
  foundUserId = row.getAttribute('data-uid');
  document.getElementById('foundText').textContent = 'Found: ' + row.getAttribute('data-name') + ' (Reg: ' + (row.getAttribute('data-reg') || '/') + ')';
  document.getElementById('foundBar').classList.remove('d-none');
  document.getElementById('previewPanel').style.display = 'none';
});

document.getElementById('confirmFoundBtn').addEventListener('click', function () {
  const eventId = getEventId();
  if (!eventId || !foundUserId) return;
  fetch(APP_URL + '/api/admin-scan-preview.php?mode=select&event_id=' + eventId + '&user_id=' + foundUserId)
    .then(r => r.json()).then(function (data) { document.getElementById('foundBar').classList.add('d-none'); showPreview(data, 'manual'); });
});

document.getElementById('cancelBtn').addEventListener('click', function () {
  pendingPreview = null;
  document.getElementById('previewPanel').style.display = 'none';
});

document.getElementById('confirmBtn').addEventListener('click', function () {
  if (!pendingPreview) return;
  const eventId = document.getElementById('eventSelect').value;
  fetch(APP_URL + '/api/admin-scan-confirm.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'event_id=' + eventId + '&user_id=' + pendingPreview.user_id + '&method=' + pendingPreview.method + '&csrf_token=' + encodeURIComponent(CSRF)
  }).then(r => r.json()).then(function (data) {
    document.getElementById('resultMsg').innerHTML = '<div class="alert alert-' + (data.ok ? 'success' : 'danger') + ' small">' + data.message + '</div>';
    if (data.ok) {
      document.getElementById('previewPanel').style.display = 'none';
      pendingPreview = null;
    }
  });
});
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
