<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();

$pageTitle = 'Live Session';
$activeNav = 'live-session';
$db        = Database::connection();
$me        = Auth::user();

// Fetch this lecturer's ongoing modules
$modStmt = $db->prepare(
    "SELECT m.module_id, m.module_title, m.session_type, m.weekend_slot, r.room_name
     FROM modules m
     LEFT JOIN rooms r ON r.room_id = m.room_id
     LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
     WHERE m.status = 'Ongoing'
       AND (lt.user_id = :uid OR :role IN ('HOD','Coordinator'))
     ORDER BY m.module_title"
);
$modStmt->execute(['uid' => $me['user_id'], 'role' => Auth::role()]);
$myModules = $modStmt->fetchAll();

$moduleId = (int) ($_GET['module_id'] ?? 0);
$module   = null;
foreach ($myModules as $m) {
    if ((int) $m['module_id'] === $moduleId) { $module = $m; break; }
}

require __DIR__ . '/../partials/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="display-font mb-0">Live Session</h4>
  <a href="<?= APP_URL ?>/lecturer/class-attendance.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i> Attendance Register
  </a>
</div>

<?php if (!$myModules): ?>
  <div class="semas-card p-4 text-center text-muted small">No ongoing modules assigned to you.</div>
<?php else: ?>

<!-- Module selector -->
<div class="semas-card p-3 mb-3">
  <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
    <label class="form-label small fw-semibold mb-0 text-nowrap">Select Module:</label>
    <select name="module_id" class="form-select form-select-sm flex-grow-1" style="max-width:400px;"
            onchange="this.form.submit()">
      <option value="">/ Choose a module /</option>
      <?php foreach ($myModules as $m): ?>
        <option value="<?= (int) $m['module_id'] ?>"
                <?= (int) $m['module_id'] === $moduleId ? 'selected' : '' ?>>
          <?= e($m['module_title']) ?> (<?= e($m['session_type']) ?>)
          <?php if ($m['room_name']): ?> / <?= e($m['room_name']) ?><?php endif; ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$module): ?>
  <div class="semas-card p-5 text-center text-muted">
    <i class="bi bi-qr-code" style="font-size:3rem;opacity:.3;"></i>
    <p class="mt-3 mb-0">Select a module above to start a live attendance session.</p>
  </div>
<?php else: ?>

<div class="row g-3">
  <!-- QR Code panel -->
  <div class="col-lg-5">
    <div class="semas-card p-4 text-center">
      <h6 class="display-font mb-1"><?= e($module['module_title']) ?></h6>
      <p class="text-muted small mb-3">
        <?= e($module['session_type']) ?>
        <?php if ($module['room_name']): ?> &middot; <?= e($module['room_name']) ?><?php endif; ?>
      </p>

      <!-- QR code display area -->
      <div id="qrWrap" style="display:inline-block;border:4px solid #1E2A52;border-radius:8px;padding:12px;background:#fff;">
        <div id="qrCanvas"></div>
      </div>

      <!-- Status / countdown -->
      <div class="mt-3">
        <div id="qrStatus" class="badge bg-secondary" style="font-size:.85rem;">Loading…</div>
        <div class="mt-1 text-muted small" id="qrExpiry">Generating session QR…</div>
      </div>

      <!-- Progress bar for countdown -->
      <div class="progress mt-2" style="height:6px;border-radius:3px;">
        <div id="qrProgress" class="progress-bar bg-success" style="width:100%;transition:width .5s linear;"></div>
      </div>

      <p class="text-muted mt-3 mb-0" style="font-size:.75rem;">
        <i class="bi bi-info-circle me-1"></i>
        QR rotates every 60 seconds. Students must scan before it expires.
        <br>1/2 metre scan range enforced.
      </p>
    </div>
  </div>

  <!-- Live roster panel -->
  <div class="col-lg-7">
    <div class="semas-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="display-font mb-0">Live Roster</h6>
        <div class="d-flex gap-2" style="font-size:.78rem;">
          <span class="badge bg-success" id="cntPresent">P: 0</span>
          <span class="badge bg-warning text-dark" id="cntLate">L: 0</span>
          <span class="badge bg-danger" id="cntAbsent">A: 0</span>
          <span class="badge bg-secondary" id="cntTotal">/ 0</span>
        </div>
      </div>
      <div id="rosterTable" style="max-height:420px;overflow-y:auto;">
        <div class="text-muted small text-center py-4">Starting session…</div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"
        integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSt9v3gPZ/6P5j0kSALgDaUSXOzZGkFN8l0QA=="
        crossorigin="anonymous"></script>
<script>
const CSRF     = '<?= csrf_token() ?>';
const BASE     = window.SEMAS_BASE_URL;
const MOD_ID   = <?= $moduleId ?>;
let sessionId  = null;
let qrObj      = null;
let refreshTimer = null;
let countdownInterval = null;
let expiresIn  = 60;

function buildQr(qrData) {
    const wrap = document.getElementById('qrCanvas');
    wrap.innerHTML = '';
    qrObj = new QRCode(wrap, {
        text: qrData,
        width: 200, height: 200,
        colorDark: '#1E2A52', colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M,
    });
}

function setCountdown(seconds) {
    expiresIn = seconds;
    clearInterval(countdownInterval);
    countdownInterval = setInterval(function () {
        expiresIn = Math.max(0, expiresIn - 1);
        const pct = (expiresIn / 60) * 100;
        const bar = document.getElementById('qrProgress');
        bar.style.width = pct + '%';
        bar.className = 'progress-bar ' + (pct > 40 ? 'bg-success' : pct > 15 ? 'bg-warning' : 'bg-danger');
        document.getElementById('qrExpiry').textContent = 'Expires in ' + expiresIn + 's';
        if (expiresIn <= 2) { refreshToken(); }
    }, 1000);
}

function openSession() {
    document.getElementById('qrStatus').textContent = 'Opening session…';
    document.getElementById('qrStatus').className = 'badge bg-secondary';
    fetch(BASE + '/api/lecturer-session-qr.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=open_session&module_id=' + MOD_ID + '&csrf_token=' + encodeURIComponent(CSRF),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            document.getElementById('qrStatus').textContent = data.message;
            document.getElementById('qrStatus').className = 'badge bg-danger';
            return;
        }
        sessionId = data.session_id;
        buildQr(data.qr_data);
        document.getElementById('qrStatus').textContent = 'Session Active';
        document.getElementById('qrStatus').className = 'badge bg-success';
        setCountdown(data.expires_in || 60);
        loadRoster();
    })
    .catch(() => {
        document.getElementById('qrStatus').textContent = 'Error / check connection';
        document.getElementById('qrStatus').className = 'badge bg-danger';
    });
}

function refreshToken() {
    if (!sessionId) return;
    clearInterval(countdownInterval);
    fetch(BASE + '/api/lecturer-session-qr.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=refresh&session_id=' + sessionId + '&csrf_token=' + encodeURIComponent(CSRF),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) return;
        if (qrObj) {
            try { qrObj.makeCode(data.qr_data); }
            catch (e) { buildQr(data.qr_data); }
        } else { buildQr(data.qr_data); }
        setCountdown(data.expires_in || 60);
    });
}

function loadRoster() {
    if (!sessionId) return;
    fetch(BASE + '/api/lecturer-session-qr.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=status&session_id=' + sessionId + '&csrf_token=' + encodeURIComponent(CSRF),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) return;
        document.getElementById('cntPresent').textContent = 'P: ' + data.present;
        document.getElementById('cntLate').textContent    = 'L: ' + data.late;
        document.getElementById('cntAbsent').textContent  = 'A: ' + data.absent;
        document.getElementById('cntTotal').textContent   = '/ ' + data.total;

        const colors = {P:'#d4edda',L:'#fff3cd',A:'#f8d7da'};
        const fcolors = {P:'#155724',L:'#856404',A:'#721c24'};
        let html = '<table class="table table-sm table-bordered mb-0" style="font-size:.78rem;">';
        html += '<thead class="table-light"><tr><th>#</th><th>Reg No</th><th>Name</th><th>In</th><th>Out</th><th>Status</th></tr></thead><tbody>';
        data.roster.forEach(function(s, i) {
            const bg = colors[s.status] || '#fff';
            const fc = fcolors[s.status] || '#000';
            html += '<tr>';
            html += '<td class="text-muted text-center">' + (i+1) + '</td>';
            html += '<td>' + (s.reg || '/') + '</td>';
            html += '<td class="fw-semibold">' + s.name + '</td>';
            html += '<td>' + (s.in_time || '/') + '</td>';
            html += '<td>' + (s.out_time || '/') + '</td>';
            html += '<td class="text-center fw-bold" style="background:' + bg + ';color:' + fc + ';">' + s.status + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('rosterTable').innerHTML = html;
    });
}

// Open session on page load
openSession();
// Refresh roster every 10 seconds
setInterval(loadRoster, 10000);
</script>

<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
