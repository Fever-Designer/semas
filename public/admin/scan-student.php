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
  Scan the personal QR code shown on the student's SEMAS page, then review the profile before confirming attendance.
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
      <h6 class="display-font mb-2"><i class="bi bi-qr-code-scan me-1"></i> Method 2: Scan Student QR</h6>
      <p class="text-muted small mb-2">Scan the personal QR code from the student's My QR Code page.</p>
      <div id="reader" style="width:100%;"></div>
      <button id="startScanBtn" class="btn btn-sm btn-semas-gold mt-2"><i class="bi bi-camera me-1"></i> Scan Student QR</button>
      <div id="scanHint" class="text-muted small mt-2" style="display:none;">Scanning... keep the student's QR code in view.</div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="semas-card p-3">
      <h6 class="display-font mb-2"><i class="bi bi-search me-1"></i> Method 3: Manual Search</h6>
      <form id="searchForm" class="d-flex gap-2 mb-2" onsubmit="return false;">
        <input id="searchBox" class="form-control" inputmode="numeric" pattern="\d{10}" maxlength="10" autocomplete="off" placeholder="Registration number" required>
        <button id="searchBtn" class="btn btn-semas text-nowrap">Search</button>
      </form>
      <div id="manualFeedback" class="alert alert-danger small py-2 px-3 mb-0" style="display:none;"></div>
    </div>
  </div>
</div>

<div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title display-font" id="profileModalTitle">Student Profile / Confirm Attendance</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-3">
          <img id="prevPhoto" src="" alt="Student profile photo" style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid var(--semas-gold);">
          <div>
            <div class="fw-semibold" id="prevName"></div>
            <div class="text-muted small" id="prevReg"></div>
            <div class="text-muted small" id="prevDept"></div>
            <div class="text-muted small" id="prevFaculty"></div>
            <div class="text-muted small" id="prevSession"></div>
          </div>
        </div>
        <div id="prevWarning" class="alert alert-warning small mt-3 mb-0" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button id="cancelBtn" type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancel</button>
        <button id="confirmBtn" type="button" class="btn btn-semas">Confirm Attendance</button>
      </div>
    </div>
  </div>
</div>
<div id="resultMsg" class="mt-3"></div>

<script src="<?= APP_URL ?>/assets/vendor/html5-qrcode/html5-qrcode.min.js"></script>
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
  pendingPreview = data.already_marked ? null : { user_id: data.student.user_id, method: method };
  document.getElementById('prevPhoto').src = data.student.photo_url;
  document.getElementById('prevName').textContent = data.student.full_name;
  document.getElementById('prevReg').textContent = 'Reg. No: ' + (data.student.reg_number || '/');
  document.getElementById('prevDept').textContent = 'Department: ' + (data.student.department || '/');
  document.getElementById('prevFaculty').textContent = 'Faculty: ' + (data.student.faculty || '/');
  document.getElementById('prevSession').textContent = 'Session: ' + (data.student.session_type || '/');
  const warn = document.getElementById('prevWarning');
  const confirmBtn = document.getElementById('confirmBtn');
  if (data.already_marked) {
    warn.style.display = '';
    warn.textContent = 'Attendance already exists for this student (checked in at ' + data.checkin_time + ').';
    confirmBtn.disabled = true;
  } else {
    warn.style.display = 'none';
    confirmBtn.disabled = false;
  }
  bootstrap.Modal.getOrCreateInstance(document.getElementById('profileModal')).show();
}

function showRequestError(message) {
  document.getElementById('resultMsg').innerHTML = '<div class="alert alert-danger small"></div>';
  document.querySelector('#resultMsg .alert').textContent = message;
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
      document.getElementById('startScanBtn').innerHTML = '<i class="bi bi-camera me-1"></i> Scan Student QR';
      document.getElementById('scanHint').style.display = 'none';
    });
    fetch(APP_URL + '/api/admin-scan-preview.php?mode=qr&event_id=' + eventId + '&token=' + encodeURIComponent(decodedText))
      .then(function (r) { if (!r.ok) throw new Error('QR lookup failed.'); return r.json(); })
      .then(data => showPreview(data, 'qr'))
      .catch(function () { showRequestError('The student QR could not be checked. Please try again.'); });
  }).catch(function () {
    document.getElementById('startScanBtn').disabled = false;
    document.getElementById('startScanBtn').innerHTML = '<i class="bi bi-camera me-1"></i> Scan Student QR';
    document.getElementById('scanHint').style.display = 'none';
    document.getElementById('resultMsg').innerHTML = '<div class="alert alert-danger small">Camera not available. Allow camera permission and try again.</div>';
  });
});

function doManualSearch() {
  const input = document.getElementById('searchBox');
  const regNumber = input.value.trim();
  const eventId = document.getElementById('eventSelect').value;
  if (!eventId) { alert('Please select an event first.'); return; }
  const feedback = document.getElementById('manualFeedback');
  feedback.style.display = 'none';
  bootstrap.Modal.getInstance(document.getElementById('profileModal'))?.hide();
  pendingPreview = null;
  if (!/^\d{10}$/.test(regNumber)) {
    feedback.textContent = 'Enter the complete 10-digit registration number.';
    feedback.style.display = '';
    return;
  }
  fetch(APP_URL + '/api/admin-scan-preview.php?mode=manual&event_id=' + eventId + '&reg_number=' + encodeURIComponent(regNumber))
    .then(function (r) { if (!r.ok) throw new Error('Search failed.'); return r.json(); })
    .then(function (data) {
      if (!data.ok) {
        feedback.textContent = data.message || 'Student not found.';
        feedback.style.display = '';
        return;
      }
      showPreview(data, 'manual');
    })
    .catch(function () {
      feedback.textContent = 'Manual search could not be completed. Please try again.';
      feedback.style.display = '';
    });
}
document.getElementById('searchBtn').addEventListener('click', doManualSearch);
document.getElementById('searchBox').addEventListener('input', function () {
  this.value = this.value.replace(/\D+/g, '').slice(0, 10);
  document.getElementById('manualFeedback').style.display = 'none';
  bootstrap.Modal.getInstance(document.getElementById('profileModal'))?.hide();
  pendingPreview = null;
});
document.getElementById('searchBox').addEventListener('keydown', function (e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    doManualSearch();
  }
});

document.getElementById('eventSelect').addEventListener('change', function () {
  pendingPreview = null;
  bootstrap.Modal.getInstance(document.getElementById('profileModal'))?.hide();
  document.getElementById('manualFeedback').style.display = 'none';
});

document.getElementById('profileModal').addEventListener('hidden.bs.modal', function () {
  pendingPreview = null;
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
      bootstrap.Modal.getInstance(document.getElementById('profileModal'))?.hide();
      pendingPreview = null;
    }
  });
});
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
