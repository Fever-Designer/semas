<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);
Module::autoCompleteExpired();

$pageTitle  = 'Class Attendance';
$activeNav  = 'class-attendance';
$db         = Database::connection();
$me         = Auth::user();

// Active window info for display
$window      = ClassAttendance::currentWindow();
$holidayInfo = ClassAttendance::holidayToday();

// If a specific module QR scan is incoming (from the printed classroom QR)
$moduleIdParam = (int) ($_GET['module_id'] ?? 0);
$tokenParam    = $_GET['t'] ?? '';

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Class Attendance</h4>
<p class="text-muted small mb-3">
  <?php if ($holidayInfo && $holidayInfo['holiday_type'] === 'Public Holiday'): ?>
    <span class="badge bg-danger">Public Holiday</span> Today is a public holiday — no attendance required.
  <?php elseif (!$window): ?>
    <span class="badge bg-secondary">No Active Session</span> No session window is active right now.
    (Day: 08:00–11:30 &nbsp;|&nbsp; Evening: 18:00–20:00 &nbsp;|&nbsp; Weekend Morning: 08:30–14:00 &nbsp;|&nbsp; Weekend Afternoon: 14:30–20:30)
  <?php else: ?>
    <span class="badge badge-completed">Active</span> <?= e(ClassAttendance::describeWindow($window)) ?> — Sign in within the first <strong>10 min</strong> to be marked Present, within <strong>20 min</strong> for Late. After 20 minutes you will be marked Absent automatically.
  <?php endif; ?>
</p>

<?php
// Load student's enrolled ongoing modules
$enrolledStmt = $db->prepare(
    "SELECT m.module_id, m.module_title, m.session_type, m.room, m.module_qr_secret, d.department_name,
            u.full_name AS lecturer_name
     FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id AND e.user_id = :uid
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l   ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u       ON u.user_id = l.user_id
     WHERE m.status = 'Ongoing'
     ORDER BY m.module_title"
);
$enrolledStmt->execute(['uid' => $me['user_id']]);
$enrolledModules = $enrolledStmt->fetchAll();

if (!$enrolledModules): ?>
  <div class="semas-card p-4 text-center text-muted small">
    You are not registered in any ongoing module. <a href="<?= APP_URL ?>/student/modules.php">Register for a module</a>.
  </div>
<?php else: ?>
  <div class="row g-3" id="moduleList">
    <?php foreach ($enrolledModules as $m):
      $matchesWindow = false;
      if ($window) {
          if (!$m['session_type']) {
              $matchesWindow = true;
          } elseif ($m['session_type'] === 'Day' && $window['name'] === 'Day') {
              $matchesWindow = true;
          } elseif ($m['session_type'] === 'Evening' && $window['name'] === 'Evening') {
              $matchesWindow = true;
          } elseif ($m['session_type'] === 'Weekend' && in_array($window['name'], ['WeekendMorning', 'WeekendAfternoon', 'UmugandaMorning', 'UmugandaAfternoon'], true)) {
              $matchesWindow = true;
          }
      }
    ?>
      <div class="col-md-6">
        <div class="semas-card p-3 h-100">
          <h6 class="fw-semibold mb-1"><?= e($m['module_title']) ?></h6>
          <p class="text-muted small mb-2">
            <?= e($m['lecturer_name'] ?? 'TBA') ?> &middot; <?= e($m['session_type'] ?? 'Any') ?>
            <?= $m['room'] ? ' &middot; Room ' . e($m['room']) : '' ?>
          </p>
          <?php if (!$window || $holidayInfo): ?>
            <span class="badge bg-secondary small">No active session window</span>
          <?php elseif (!$matchesWindow): ?>
            <span class="badge bg-secondary small">Your module session (<?= e($m['session_type']) ?>) doesn't match the active window</span>
          <?php else: ?>
            <button class="btn btn-semas-gold btn-sm scan-btn"
                    data-module-id="<?= (int) $m['module_id'] ?>"
                    data-module-name="<?= e($m['module_title']) ?>">
              <i class="bi bi-qr-code-scan me-1"></i> Scan / Check In
            </button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Scan modal — opens when student taps "Scan / Check In" -->
<div class="modal fade" id="scanModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title display-font" id="scanModalTitle">Scan Class QR</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="stopCamera()"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small" id="scanInstruction">Point your camera at the QR code posted in your classroom.</p>
        <div id="reader" style="width:100%;"></div>
        <div id="scanMsg" class="mt-2"></div>
        <!-- Manual checkin (for students who can't use camera) -->
        <hr>
        <p class="text-muted small mb-1">No camera? Use manual check-in:</p>
        <button id="manualCheckinBtn" class="btn btn-outline-dark btn-sm w-100">Check In Without QR Scan</button>
      </div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const APP_URL  = window.SEMAS_BASE_URL;
const CSRF     = '<?= csrf_token() ?>';
let html5QrCode = null;
let activeModuleId   = null;
let activeModuleName = null;

function stopCamera() {
  if (html5QrCode) {
    html5QrCode.stop().catch(() => {});
    html5QrCode = null;
  }
}

document.querySelectorAll('.scan-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    activeModuleId   = btn.dataset.moduleId;
    activeModuleName = btn.dataset.moduleName;
    document.getElementById('scanModalTitle').textContent = activeModuleName;
    document.getElementById('scanMsg').innerHTML = '';
    document.getElementById('reader').innerHTML  = '';
    const modal = new bootstrap.Modal(document.getElementById('scanModal'));
    modal.show();
    html5QrCode = new Html5Qrcode('reader');
    html5QrCode.start({ facingMode: 'environment' }, { fps: 10, qrbox: 220 }, function (decodedText) {
      html5QrCode.stop().then(function () { html5QrCode = null; submitAttendance(activeModuleId, decodedText); });
    }).catch(function () {
      document.getElementById('reader').innerHTML = '<p class="text-muted small text-center mt-2">Camera not available. Use manual check-in below.</p>';
    });
  });
});

document.getElementById('manualCheckinBtn').addEventListener('click', function () {
  submitAttendance(activeModuleId, null);
});

function submitAttendance(moduleId, qrToken) {
  const body = 'module_id=' + moduleId + '&csrf_token=' + encodeURIComponent(CSRF) + (qrToken ? '&qr_token=' + encodeURIComponent(qrToken) : '');
  fetch(APP_URL + '/api/student-attendance-scan.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body,
  })
  .then(function (r) { return r.json(); })
  .then(function (data) {
    const cls = data.ok ? 'alert-success' : 'alert-danger';
    document.getElementById('scanMsg').innerHTML = '<div class="alert ' + cls + ' small mt-2">' + data.message + '</div>';
  })
  .catch(function () {
    document.getElementById('scanMsg').innerHTML = '<div class="alert alert-danger small mt-2">Network error. Try again.</div>';
  });
}

// If QR token is present in URL (student scanned a printed module QR with phone camera)
<?php if ($moduleIdParam && $tokenParam): ?>
window.addEventListener('DOMContentLoaded', function () {
  submitAttendance(<?= (int) $moduleIdParam ?>, <?= json_encode($tokenParam) ?>);
});
<?php endif; ?>
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
