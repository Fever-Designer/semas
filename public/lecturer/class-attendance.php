<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Lecturer']);

$pageTitle = 'Class Attendance';
$activeNav = 'modules';
$db = Database::connection();
$me = Auth::user();

$lecStmt = $db->prepare('SELECT * FROM lecturers WHERE user_id = :uid');
$lecStmt->execute(['uid' => $me['user_id']]);
$lecturer = $lecStmt->fetch();

$moduleId  = (int) ($_GET['module_id'] ?? 0);
$tab       = $_GET['tab'] ?? 'live';
$rangeType = $_GET['range'] ?? 'monthly';
$rangeDate = $_GET['date'] ?? date('Y-m-d');

if ($rangeType === 'weekly') {
    $dateFrom = date('Y-m-d', strtotime('monday this week', strtotime($rangeDate)));
    $dateTo   = date('Y-m-d', strtotime('sunday this week', strtotime($rangeDate)));
} elseif ($rangeType === 'daily') {
    $dateFrom = $rangeDate;
    $dateTo   = $rangeDate;
} else {
    $dateFrom = date('Y-m-01', strtotime($rangeDate));
    $dateTo   = date('Y-m-t',  strtotime($rangeDate));
}

$modStmt = $db->prepare('SELECT * FROM modules WHERE module_id = :id AND lecturer_id = :lec');
$modStmt->execute(['id' => $moduleId, 'lec' => $lecturer['lecturer_id'] ?? 0]);
$module = $modStmt->fetch();

if (!$module) {
    require __DIR__ . '/../partials/layout_top.php';
    echo '<div class="alert alert-warning small">Module not found, or it is not assigned to you. <a href="' . APP_URL . '/lecturer/modules.php">Back to My Modules</a></div>';
    require __DIR__ . '/../partials/layout_bottom.php';
    exit;
}

// CSV export
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_' . $moduleId . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student Name', 'Reg Number', 'Date', 'Session', 'Status', 'Check-in Time', 'Method']);
    $rows = $db->prepare(
        "SELECT u.full_name, u.reg_number, cs.session_date, cs.window_name,
                cal.status, cal.checkin_time, cal.verification_method
         FROM class_attendance_logs cal
         JOIN class_sessions cs ON cs.session_id = cal.session_id
         JOIN users u ON u.user_id = cal.user_id
         WHERE cs.module_id = :mid AND cs.session_date BETWEEN :from AND :to
               AND cal.attendance_type = 'Sign In'
         ORDER BY cs.session_date, u.full_name"
    );
    $rows->execute(['mid' => $moduleId, 'from' => $dateFrom, 'to' => $dateTo]);
    foreach ($rows->fetchAll() as $r) {
        fputcsv($out, [$r['full_name'], $r['reg_number'], $r['session_date'], $r['window_name'], $r['status'], $r['verification_method'] !== 'Auto' ? $r['checkin_time'] : '', $r['verification_method']]);
    }
    fclose($out);
    exit;
}

$activeWindow = ClassAttendance::currentWindow();
$session = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'open_attendance' && $activeWindow) {
        // Find-or-create exactly one class_sessions row for (module, today, this window).
        $find = $db->prepare("SELECT * FROM class_sessions WHERE module_id = :mid AND session_date = CURDATE() AND window_name = :win");
        $find->execute(['mid' => $moduleId, 'win' => $activeWindow['name']]);
        $existing = $find->fetch();
        if (!$existing) {
            $secret = QrService::generateSecret();
            $db->prepare(
                'INSERT INTO class_sessions (module_id, session_date, window_name, start_time, end_time, qr_secret, created_by)
                 VALUES (:mid, CURDATE(), :win, :start, :end, :secret, :uid)'
            )->execute([
                'mid' => $moduleId, 'win' => $activeWindow['name'],
                'start' => $activeWindow['start']->format('Y-m-d H:i:s'), 'end' => $activeWindow['end']->format('Y-m-d H:i:s'),
                'secret' => $secret, 'uid' => $me['user_id'],
            ]);
            $newSessionId = (int) $db->lastInsertId();
            AuditLog::record(Auth::id(), 'CLASS_SESSION_OPEN', 'class_sessions', $newSessionId);
            // Auto-add all enrolled students to the roster with Absent status.
            // When they actually scan in, this record gets updated to Present/Late.
            $db->prepare(
                "INSERT IGNORE INTO class_attendance_logs (session_id, user_id, attendance_type, status, verification_method)
                 SELECT :sid, e.user_id, 'Sign In', 'Absent', 'Auto'
                 FROM module_enrollments e WHERE e.module_id = :mid"
            )->execute(['sid' => $newSessionId, 'mid' => $moduleId]);
        }
        flash('success', 'Attendance is open for ' . ClassAttendance::describeWindow($activeWindow) . '.');
    } elseif ($action === 'close_session') {
        $sessionId = (int) $_POST['session_id'];
        $db->prepare("UPDATE class_sessions SET status='Closed' WHERE session_id=:id AND module_id=:mid")
           ->execute(['id' => $sessionId, 'mid' => $moduleId]);
        AuditLog::record(Auth::id(), 'CLASS_SESSION_CLOSE', 'class_sessions', $sessionId);
        flash('success', 'Attendance closed for this session.');
    }
    redirect('/lecturer/class-attendance.php?module_id=' . $moduleId);
}

if ($activeWindow) {
    $find = $db->prepare("SELECT * FROM class_sessions WHERE module_id = :mid AND session_date = CURDATE() AND window_name = :win AND status = 'Open'");
    $find->execute(['mid' => $moduleId, 'win' => $activeWindow['name']]);
    $session = $find->fetch();
}

$countStmt = $db->prepare('SELECT COUNT(*) FROM module_enrollments WHERE module_id = :mid');
$countStmt->execute(['mid' => $moduleId]);
$enrolledCount = (int) $countStmt->fetchColumn();

// Calendar: all sessions for this module
$calStmt = $db->prepare(
    "SELECT session_id, session_date, window_name FROM class_sessions
     WHERE module_id = :mid ORDER BY session_date ASC, window_name ASC"
);
$calStmt->execute(['mid' => $moduleId]);
$calendarSessions = $calStmt->fetchAll();

// All enrolled students
$calStudentsStmt = $db->prepare(
    "SELECT u.user_id, u.full_name, u.reg_number
     FROM module_enrollments me JOIN users u ON u.user_id = me.user_id
     WHERE me.module_id = :mid ORDER BY u.full_name"
);
$calStudentsStmt->execute(['mid' => $moduleId]);
$calendarStudents = $calStudentsStmt->fetchAll();

// Attendance pivot
$calAttMap = [];
if ($calendarSessions) {
    $calAttStmt = $db->prepare(
        "SELECT cal.user_id, cal.session_id, cal.status
         FROM class_attendance_logs cal
         JOIN class_sessions cs ON cs.session_id = cal.session_id
         WHERE cs.module_id = :mid AND cal.attendance_type = 'Sign In'"
    );
    $calAttStmt->execute(['mid' => $moduleId]);
    foreach ($calAttStmt->fetchAll() as $att) {
        $calAttMap[(int)$att['user_id']][(int)$att['session_id']] = $att['status'];
    }
}

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <h4 class="display-font mb-0">Class Attendance — <?= e($module['module_title']) ?></h4>
  <a href="<?= APP_URL ?>/lecturer/modules.php" class="btn btn-sm btn-outline-dark">Back to Modules</a>
</div>

<?php if (!$activeWindow): ?>
  <div class="semas-card p-4 text-center">
    <i class="bi bi-clock-history" style="font-size:2rem;color:var(--semas-text-muted);"></i>
    <h6 class="display-font mt-2">No Session Window Is Active Right Now</h6>
  </div>
<?php elseif (!$session): ?>
  <div class="semas-card p-4 text-center">
    <p class="small mb-2">It is currently <strong><?= e(ClassAttendance::describeWindow($activeWindow)) ?></strong>.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="open_attendance">
      <button class="btn btn-semas-gold"><i class="bi bi-unlock me-1"></i> Open Attendance for This Session</button></form>
  </div>
<?php else: ?>
  <div class="alert alert-success small d-flex justify-content-between align-items-center">
    <span>Attendance is open for <strong><?= e(ClassAttendance::describeWindow($activeWindow)) ?></strong>.</span>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="close_session"><input type="hidden" name="session_id" value="<?= (int) $session['session_id'] ?>">
      <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Close attendance for this session?');">Close</button></form>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-2"><i class="bi bi-qr-code-scan me-1"></i> Scan Student's Personal QR</h6>
        <p class="text-muted small">Scan the QR code from the student's own "My QR Code" page.</p>
        <div id="reader" style="width:100%;"></div>
        <button id="startScanBtn" class="btn btn-sm btn-semas-gold mt-2">Start Camera</button>
      </div>
    </div>
    <div class="col-md-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-2"><i class="bi bi-search me-1"></i> Manual Search</h6>
        <form id="searchForm" class="d-flex gap-2 mb-2" onsubmit="return false;">
          <input id="searchBox" class="form-control form-control-sm" placeholder="Search registered students...">
          <button id="searchBtn" class="btn btn-sm btn-semas text-nowrap">Search</button>
        </form>
        <div id="searchResults"></div>
        <div id="foundBar" class="alert alert-success small d-none mt-2 d-flex justify-content-between align-items-center">
          <span id="foundText"></span>
          <button id="confirmFoundBtn" class="btn btn-sm btn-semas-gold">Confirm &amp; View Profile</button>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-md-6">
      <div id="previewPanel" class="semas-card p-3" style="display:none;">
        <h6 class="display-font mb-3">Student Profile</h6>
        <div class="d-flex gap-3">
          <img id="prevPhoto" src="" style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid var(--semas-gold);">
          <div>
            <div class="fw-semibold" id="prevName"></div>
            <div class="text-muted small" id="prevReg"></div>
            <div class="text-muted small" id="prevDept"></div>
          </div>
        </div>
        <div id="prevWarning" class="alert alert-warning small mt-3" style="display:none;"></div>
        <div class="mt-3">
          <button id="confirmBtn" class="btn btn-semas">Confirm Attendance</button>
          <button id="cancelBtn" class="btn btn-outline-dark">Cancel</button>
        </div>
      </div>
      <div id="resultMsg"></div>
    </div>
    <div class="col-md-6">
      <div class="semas-card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="display-font mb-0">Live Roster</h6>
          <span class="text-muted small">Auto-refreshing</span>
        </div>
        <div id="rosterList"><p class="text-muted small">Loading...</p></div>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
  <script>
  const APP_URL = window.SEMAS_BASE_URL;
  const CSRF = '<?= csrf_token() ?>';
  const SESSION_ID = <?= (int) $session['session_id'] ?>;
  let pendingPreview = null;
  let foundUserId = null;
  let html5QrCode = null;

  function showPreview(data) {
    if (!data.ok) { document.getElementById('resultMsg').innerHTML = '<div class="alert alert-danger small">' + data.message + '</div>'; return; }
    pendingPreview = { user_id: data.student.user_id, method: data.__method || 'manual' };
    document.getElementById('prevPhoto').src = data.student.photo_url;
    document.getElementById('prevName').textContent = data.student.full_name;
    document.getElementById('prevReg').textContent = 'Reg. No: ' + (data.student.reg_number || '—');
    document.getElementById('prevDept').textContent = 'Department: ' + (data.student.department || '—');
    const warn = document.getElementById('prevWarning');
    if (data.already_marked) {
      warn.style.display = '';
      warn.textContent = 'Already marked ' + data.status + ' at ' + data.checkin_time + '. Confirming again will be blocked.';
    } else { warn.style.display = 'none'; }
    document.getElementById('foundBar').classList.add('d-none');
    document.getElementById('previewPanel').style.display = '';
  }

  document.getElementById('startScanBtn').addEventListener('click', function () {
    html5QrCode = new Html5Qrcode("reader");
    html5QrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: 240 }, function (decodedText) {
      html5QrCode.stop();
      fetch(APP_URL + '/api/class-scan-preview.php?mode=qr&session_id=' + SESSION_ID + '&token=' + encodeURIComponent(decodedText))
        .then(r => r.json()).then(function (data) { data.__method = 'qr'; showPreview(data); });
    });
  });

  function doSearch() {
    const q = document.getElementById('searchBox').value;
    if (q.length < 2) { document.getElementById('searchResults').innerHTML = ''; return; }
    fetch(APP_URL + '/api/class-scan-preview.php?mode=search&session_id=' + SESSION_ID + '&q=' + encodeURIComponent(q))
      .then(r => r.json()).then(function (data) {
        document.getElementById('searchResults').innerHTML = (data.results || []).map(function (s) {
          return '<div class="border-bottom py-1 small" style="cursor:pointer;" data-uid="' + s.user_id + '" data-name="' + s.full_name + '" data-reg="' + (s.reg_number || '') + '">' +
                 s.full_name + ' <span class="text-muted">(' + (s.reg_number || '—') + ')</span></div>';
        }).join('') || '<p class="text-muted small mb-0">No registered student matches.</p>';
      });
  }
  document.getElementById('searchBtn').addEventListener('click', doSearch);
  document.getElementById('searchBox').addEventListener('keydown', function (e) { if (e.key === 'Enter') doSearch(); });

  document.getElementById('searchResults').addEventListener('click', function (e) {
    const row = e.target.closest('[data-uid]');
    if (!row) return;
    foundUserId = row.getAttribute('data-uid');
    document.getElementById('foundText').textContent = 'Found: ' + row.getAttribute('data-name') + ' (Reg: ' + (row.getAttribute('data-reg') || '—') + ')';
    document.getElementById('foundBar').classList.remove('d-none');
    document.getElementById('previewPanel').style.display = 'none';
  });

  document.getElementById('confirmFoundBtn').addEventListener('click', function () {
    if (!foundUserId) return;
    fetch(APP_URL + '/api/class-scan-preview.php?mode=select&session_id=' + SESSION_ID + '&user_id=' + foundUserId)
      .then(r => r.json()).then(function (data) { data.__method = 'manual'; showPreview(data); });
  });

  document.getElementById('cancelBtn').addEventListener('click', function () {
    pendingPreview = null;
    document.getElementById('previewPanel').style.display = 'none';
  });

  document.getElementById('confirmBtn').addEventListener('click', function () {
    if (!pendingPreview) return;
    fetch(APP_URL + '/api/class-scan-confirm.php', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'session_id=' + SESSION_ID + '&user_id=' + pendingPreview.user_id + '&method=' + pendingPreview.method + '&csrf_token=' + encodeURIComponent(CSRF)
    }).then(r => r.json()).then(function (data) {
      document.getElementById('resultMsg').innerHTML = '<div class="alert alert-' + (data.ok ? 'success' : 'danger') + ' small">' + data.message + '</div>';
      if (data.ok) { document.getElementById('previewPanel').style.display = 'none'; pendingPreview = null; refreshRoster(); }
    });
  });

  function refreshRoster() {
    fetch(APP_URL + '/api/class-session-live.php?session_id=' + SESSION_ID)
      .then(r => r.json()).then(function (data) {
        if (!data.ok) return;
        document.getElementById('rosterList').innerHTML = (data.roster || []).map(function (r) {
          const badge = r.status === 'Present' ? 'badge-completed' : (r.status === 'Late' ? 'badge-urgent' : 'bg-secondary');
          return '<div class="d-flex justify-content-between border-bottom py-1 small"><span>' + r.full_name + ' (' + (r.reg_number || '—') + ')</span><span class="badge ' + badge + '">' + r.status + '</span></div>';
        }).join('') || '<p class="text-muted small mb-0">No check-ins yet.</p>';
      });
  }
  refreshRoster();
  setInterval(refreshRoster, 10000);
  </script>
<?php endif; ?>

<div class="semas-card p-3 mt-3">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <div>
      <h6 class="display-font mb-0">Attendance Calendar</h6>
      <p class="text-muted small mb-0">All sessions for this module — students as rows, dates as columns. <span style="background:#d4edda;padding:1px 5px;border-radius:3px;font-size:.75rem;">P</span> <span style="background:#fff3cd;padding:1px 5px;border-radius:3px;font-size:.75rem;">L</span> <span style="background:#f8d7da;padding:1px 5px;border-radius:3px;font-size:.75rem;">A</span></p>
    </div>
    <div class="d-flex gap-2">
      <a href="?module_id=<?= $moduleId ?>&export=csv&range=monthly&date=<?= date('Y-m-d') ?>"
         class="btn btn-sm btn-outline-dark"><i class="bi bi-filetype-csv me-1"></i>Export CSV</a>
      <a href="<?= APP_URL ?>/lecturer/attendance-pdf.php?module_id=<?= $moduleId ?>&range=monthly&date=<?= date('Y-m-d') ?>"
         target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-file-earmark-pdf me-1"></i>Export PDF</a>
    </div>
  </div>

  <?php if (!$calendarSessions): ?>
    <p class="text-muted small mb-0">No sessions recorded for this module yet.</p>
  <?php elseif (!$calendarStudents): ?>
    <p class="text-muted small mb-0">No students enrolled in this module.</p>
  <?php else: ?>
    <?php $todayDate = date('Y-m-d'); ?>
    <div style="overflow-x:auto;">
      <table class="table table-bordered table-sm mb-0" style="white-space:nowrap;font-size:.82rem;">
        <thead>
          <tr>
            <th style="position:sticky;left:0;z-index:2;background:#f8f9fa;min-width:190px;vertical-align:middle;">Student</th>
            <?php foreach ($calendarSessions as $cs): ?>
              <th class="text-center <?= $cs['session_date'] === $todayDate ? 'table-primary' : '' ?>" style="min-width:54px;vertical-align:middle;">
                <div><?= date('D', strtotime($cs['session_date'])) ?></div>
                <div><?= date('d/m', strtotime($cs['session_date'])) ?></div>
                <?php
                  $wn = $cs['window_name'];
                  if ($wn === 'WeekendMorning') echo '<div style="font-size:.6rem;">Morn</div>';
                  elseif ($wn === 'WeekendAfternoon') echo '<div style="font-size:.6rem;">Aftn</div>';
                  elseif (str_starts_with($wn, 'Umuganda')) echo '<div style="font-size:.6rem;">Umug</div>';
                ?>
              </th>
            <?php endforeach; ?>
            <th class="text-center" style="min-width:80px;vertical-align:middle;">Summary</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($calendarStudents as $student): ?>
            <?php
              $pC = 0; $lC = 0; $aC = 0;
              foreach ($calendarSessions as $cs) {
                  $st = $calAttMap[$student['user_id']][$cs['session_id']] ?? null;
                  if ($st === 'Present') $pC++;
                  elseif ($st === 'Late') $lC++;
                  elseif ($st === 'Absent') $aC++;
              }
            ?>
            <tr>
              <td style="position:sticky;left:0;z-index:1;background:#fff;font-weight:500;vertical-align:middle;">
                <?= e($student['full_name']) ?><br>
                <span class="text-muted" style="font-size:.7rem;"><?= e($student['reg_number'] ?? '') ?></span>
              </td>
              <?php foreach ($calendarSessions as $cs): ?>
                <?php $status = $calAttMap[$student['user_id']][$cs['session_id']] ?? null; ?>
                <td class="text-center fw-bold" style="vertical-align:middle;<?php
                  if ($status === 'Present') echo 'background:#d4edda;color:#155724;';
                  elseif ($status === 'Late') echo 'background:#fff3cd;color:#856404;';
                  elseif ($status === 'Absent') echo 'background:#f8d7da;color:#721c24;';
                  else echo 'color:#ccc;';
                ?>">
                  <?= $status ? $status[0] : '·' ?>
                </td>
              <?php endforeach; ?>
              <td class="text-center small" style="vertical-align:middle;">
                <span class="text-success fw-semibold"><?= $pC ?>P</span>
                <span class="text-warning fw-semibold ms-1"><?= $lC ?>L</span>
                <span class="text-danger fw-semibold ms-1"><?= $aC ?>A</span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
