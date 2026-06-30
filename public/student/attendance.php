<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);
Module::autoCompleteExpired();

$pageTitle = 'My Attendance';
$activeNav = 'class-attendance';
$db        = Database::connection();
$me        = Auth::user();
$today     = date('Y-m-d');

$window  = ClassAttendance::currentWindow();
$holiday = ClassAttendance::holidayToday();

// Enrolled modules — Ongoing only
$modStmt = $db->prepare(
    "SELECT m.module_id, m.module_title, m.session_type, m.weekend_slot, m.status,
            m.start_date, m.end_date, m.cat_date, m.exam_date, m.room_id,
            COALESCE(lt.title,'') AS lecturer_title,
            u.full_name AS lecturer_name,
            r.room_name
     FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id
     LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = lt.user_id
     LEFT JOIN rooms r ON r.room_id = m.room_id
     WHERE e.user_id = :uid AND m.status = 'Ongoing'
     ORDER BY m.module_title"
);
$modStmt->execute(['uid' => $me['user_id']]);
$allModules = $modStmt->fetchAll();

$weekendStudent = (($me['session_type'] ?? '') === 'Weekend');
if (!$weekendStudent) {
    foreach ($allModules as $am) {
        if (($am['session_type'] ?? '') === 'Weekend') {
            $weekendStudent = true;
            break;
        }
    }
}
$showPublicHolidayNotice = $holiday && $holiday['holiday_type'] === 'Public Holiday';
$showUmugandaNotice = $holiday && $holiday['holiday_type'] === 'Umuganda' && $weekendStudent;

// Holidays
$holidayMap = [];
foreach ($db->query("SELECT holiday_date FROM holidays")->fetchAll() as $h) {
    $holidayMap[$h['holiday_date']] = true;
}

// Active session window check helper
function stu_window_matches(array $module, ?array $window): bool
{
    if (!$window) return false;
    $st   = $module['session_type'] ?? '';
    $slot = $module['weekend_slot'] ?? '';
    if ($st === 'Day')     return $window['name'] === 'Day';
    if ($st === 'Evening') return $window['name'] === 'Evening';
    if ($st === 'Weekend') {
        if ($slot === 'Morning')   return in_array($window['name'], ['WeekendMorning', 'UmugandaMorning'], true);
        if ($slot === 'Afternoon') return in_array($window['name'], ['WeekendAfternoon', 'UmugandaAfternoon'], true);
        return in_array($window['name'], ['WeekendMorning','WeekendAfternoon','UmugandaMorning','UmugandaAfternoon'], true);
    }
    return (bool) $window;
}

function stu_within_dates(array $module, string $today): bool
{
    return (!$module['start_date'] || $today >= $module['start_date'])
        && (!$module['end_date']   || $today <= $module['end_date']);
}

function stu_att_status(?array $e, string $date, string $today): string
{
    if (!$e || $e['is_auto'])                                return $date <= $today ? 'A' : '';
    if ($e['in_status'] === 'Present' && $e['out_time'])     return 'P';
    if ($e['in_status'] === 'Late'    && $e['out_time'])     return 'L';
    return 'A';
}

// Selected module tab
$selectedId = (int) ($_GET['module_id'] ?? ($allModules[0]['module_id'] ?? 0));

require __DIR__ . '/../partials/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
  <h4 class="display-font mb-0">My Attendance</h4>
</div>

<?php if ($showPublicHolidayNotice || $showUmugandaNotice): ?>
  <div class="alert alert-info small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Today is a <strong><?= e($holiday['holiday_type']) ?></strong>: <?= e($holiday['title']) ?>.
    <?= $showPublicHolidayNotice ? 'No attendance scanning today.' : 'Umuganda adjusted schedule applies.' ?>
  </div>
<?php elseif ($window): ?>
  <div class="alert alert-success small mb-3">
    <i class="bi bi-broadcast me-1"></i> Active session: <strong><?= e(ClassAttendance::describeWindow($window)) ?></strong>
  </div>
<?php endif; ?>

<?php if (!$allModules): ?>
  <div class="semas-card p-5 text-center text-muted">
    <i class="bi bi-journal-x" style="font-size:2.5rem;opacity:.3;"></i>
    <p class="mt-3 mb-0">You are not enrolled in any modules yet.</p>
  </div>
<?php else: ?>

<!-- Module tabs -->
<div class="mb-3" style="overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:4px;">
  <div class="d-flex gap-2 flex-nowrap" style="min-width:max-content;">
    <?php foreach ($allModules as $am): ?>
      <?php
        $amId = (int) $am['module_id'];
        $isActive = $amId === $selectedId;
        $scanable = $window && stu_window_matches($am, $window) && stu_within_dates($am, $today) && $am['status'] === 'Ongoing';
      ?>
      <a href="?module_id=<?= $amId ?>"
         class="btn btn-sm text-nowrap <?= $isActive ? 'btn-semas' : 'btn-outline-secondary' ?>"
         style="<?= $isActive ? '' : 'opacity:.75;' ?>">
        <?= e($am['module_title']) ?>
        <?php if ($am['status'] === 'Completed'): ?>
          <span class="badge bg-secondary ms-1" style="font-size:.6rem;">Done</span>
        <?php elseif ($scanable): ?>
          <span class="badge bg-success ms-1" style="font-size:.6rem;">Live</span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php
// Find selected module
$module = null;
foreach ($allModules as $am) {
    if ((int) $am['module_id'] === $selectedId) { $module = $am; break; }
}
if (!$module) { $module = $allModules[0]; $selectedId = (int) $module['module_id']; }

$moduleId = $selectedId;

// Build this module's sessions
$excludeDates = array_values(array_filter([$module['cat_date'], $module['exam_date']]));

$sessStmt = $db->prepare(
    "SELECT session_id, session_date, window_name FROM class_sessions
     WHERE module_id = :mid ORDER BY session_date ASC, start_time ASC"
);
$sessStmt->execute(['mid' => $moduleId]);
$allSess  = $sessStmt->fetchAll();
$sessions = array_values(array_filter($allSess, function ($s) use ($excludeDates) {
    return !in_array($s['session_date'], $excludeDates, true);
}));

// My attendance logs for this module
$logStmt = $db->prepare(
    "SELECT cal.session_id, cal.attendance_type, cal.status,
            cal.verification_method, cal.checkin_time
     FROM class_attendance_logs cal
     JOIN class_sessions cs ON cs.session_id = cal.session_id
     WHERE cs.module_id = :mid AND cal.user_id = :uid"
);
$logStmt->execute(['mid' => $moduleId, 'uid' => $me['user_id']]);
$myAttMap = [];  // [session_id] = ['in_time','in_status','out_time','is_auto']
foreach ($logStmt->fetchAll() as $log) {
    $sid = (int) $log['session_id'];
    if (!isset($myAttMap[$sid])) {
        $myAttMap[$sid] = ['in_time' => null, 'in_status' => null, 'out_time' => null, 'is_auto' => true];
    }
    if ($log['attendance_type'] === 'Sign In') {
        $myAttMap[$sid]['in_status'] = $log['status'];
        $isAuto = ($log['verification_method'] === 'Auto');
        $myAttMap[$sid]['is_auto'] = $isAuto;
        if (!$isAuto) {
            $myAttMap[$sid]['in_time'] = $log['checkin_time'] ? date('H:i', strtotime((string) $log['checkin_time'])) : null;
        }
    } else {
        $myAttMap[$sid]['out_time'] = $log['checkin_time'] ? date('H:i', strtotime((string) $log['checkin_time'])) : null;
    }
}

// Summary counts
$pCnt = 0; $lCnt = 0; $aCnt = 0;
foreach ($sessions as $s) {
    if (isset($holidayMap[$s['session_date']]) || $s['session_date'] > $today) continue;
    $fs = stu_att_status($myAttMap[(int)$s['session_id']] ?? null, $s['session_date'], $today);
    if ($fs === 'P') $pCnt++;
    elseif ($fs === 'L') $lCnt++;
    elseif ($fs === 'A') $aCnt++;
}
$total    = $pCnt + $lCnt + $aCnt;
$pct      = $total > 0 ? round(($pCnt + $lCnt) / $total * 100, 1) : 0;
$eligible = $pct >= 75;

$lTitle   = $module['lecturer_title'] ?? '';
$lName    = $module['lecturer_name']  ?? 'TBA';
$lecLabel = $lTitle ? strtoupper(rtrim((string) $lTitle, '.')) . '. ' . $lName : $lName;
$slot     = $module['weekend_slot'] ?? '';
$sessLabel = ($module['session_type'] === 'Weekend' && $slot) ? "Weekend – {$slot}" : $module['session_type'];
$isScanable = $window && stu_window_matches($module, $window) && stu_within_dates($module, $today) && $module['status'] === 'Ongoing';
?>

<!-- Module info + summary card -->
<div class="semas-card p-3 mb-3">
  <div class="row g-2 align-items-start">
    <div class="col-md-7">
      <h6 class="display-font mb-1"><?= e($module['module_title']) ?>
        <span class="badge <?= $module['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?> ms-1" style="font-size:.65rem;">
          <?= e($module['status']) ?>
        </span>
      </h6>
      <div class="text-muted small">
        <strong>Lecturer:</strong> <?= e($lecLabel) ?> &nbsp;&middot;&nbsp;
        <strong>Session:</strong> <?= e($sessLabel) ?>
        <?php if ($module['room_name']): ?> &nbsp;&middot;&nbsp; <strong>Room:</strong> <?= e($module['room_name']) ?><?php endif; ?>
      </div>
      <div class="text-muted small">
        <strong>Period:</strong> <?= e((string) date('d M Y', strtotime((string) ($module['start_date'] ?? '')))) ?> → <?= e((string) date('d M Y', strtotime((string) ($module['end_date'] ?? '')))) ?>
      </div>
    </div>
    <div class="col-md-5">
      <!-- Attendance summary box -->
      <div class="rounded p-2" style="background:#f8f9fa;font-size:.82rem;">
        <div class="d-flex justify-content-between mb-1">
          <span><span class="fw-bold text-success"><?= $pCnt ?></span> Present</span>
          <span><span class="fw-bold text-warning"><?= $lCnt ?></span> Late</span>
          <span><span class="fw-bold text-danger"><?= $aCnt ?></span> Absent</span>
          <span class="text-muted"><?= $total ?> total</span>
        </div>
        <div class="progress mb-1" style="height:6px;">
          <?php $pctBar = min(100, $pct); ?>
          <div class="progress-bar <?= $eligible ? 'bg-success' : 'bg-danger' ?>" style="width:<?= $pctBar ?>%;"></div>
        </div>
        <div class="d-flex justify-content-between">
          <span>
            <strong style="color:<?= $eligible ? '#155724' : '#721c24' ?>;"><?= number_format($pct, 1) ?>% attendance</strong>
          </span>
          <span>
            <?php if ($total > 0): ?>
              <i class="bi <?= $eligible ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i>
              <?= $eligible ? '<span class="text-success">Eligible</span>' : '<span class="text-danger">Not eligible</span>' ?>
            <?php else: ?>
              <span class="text-muted">No classes yet</span>
            <?php endif; ?>
          </span>
        </div>
      </div>
    </div>
  </div>

  <?php if ($isScanable): ?>
    <div class="mt-3 pt-2 border-top">
      <button class="btn btn-semas-gold btn-sm scan-btn"
              data-module-id="<?= $moduleId ?>"
              data-module-name="<?= e($module['module_title']) ?>">
        <i class="bi bi-qr-code-scan me-1"></i> Scan / Check In
      </button>
    </div>
  <?php elseif ($module['status'] === 'Ongoing' && !$holiday): ?>
    <p class="text-muted small mt-2 mb-0">
      <i class="bi bi-clock me-1"></i>
      No active session window for this module right now.
    </p>
  <?php endif; ?>
</div>

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

<!-- Attendance register — my own row -->
<?php if (!$sessions): ?>
  <div class="semas-card p-4 text-center text-muted small">No class sessions recorded yet for this module.</div>
<?php else: ?>

<div class="d-flex gap-3 mb-2 flex-wrap" style="font-size:.75rem;">
  <span><span class="px-2 rounded fw-bold" style="background:#d4edda;color:#155724;">P ✓</span> Present (signed in + out)</span>
  <span><span class="px-2 rounded fw-bold" style="background:#fff3cd;color:#856404;">L</span> Late</span>
  <span><span class="px-2 rounded fw-bold" style="background:#f8d7da;color:#721c24;">A</span> Absent / No sign-out</span>
  <span><span class="px-2 rounded fw-bold" style="background:#fff3cd;color:#856404;">H</span> Holiday</span>
</div>

<div class="semas-card p-0 mb-4">
  <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
    <table class="table table-bordered table-sm mb-0 align-middle" style="white-space:nowrap;font-size:.78rem;">
      <thead>
        <tr class="table-dark" style="font-size:.71rem;">
          <th style="min-width:100px;position:sticky;left:0;z-index:3;background:#212529;">You</th>
          <?php foreach ($sessions as $s):
            $isHol   = isset($holidayMap[$s['session_date']]);
            $isToday = ($s['session_date'] === $today);
            $thStyle = $isHol ? 'background:#fff3cd;color:#856404;' : ($isToday ? 'background:#1a4a8a;' : '');
          ?>
            <th class="text-center" style="min-width:62px;vertical-align:middle;<?= $thStyle ?>">
              <div><?= date('d M', strtotime($s['session_date'])) ?></div>
              <div style="font-weight:400;opacity:.8;"><?= date('D', strtotime($s['session_date'])) ?></div>
              <?php if ($isHol): ?>
                <div style="font-size:.58rem;color:#856404;">HoL</div>
              <?php elseif (in_array($s['window_name'], ['WeekendMorning','UmugandaMorning'], true)): ?>
                <div style="font-size:.58rem;opacity:.7;">Morn</div>
              <?php elseif (in_array($s['window_name'], ['WeekendAfternoon','UmugandaAfternoon'], true)): ?>
                <div style="font-size:.58rem;opacity:.7;">Aftn</div>
              <?php endif; ?>
            </th>
          <?php endforeach; ?>
          <th class="text-center" style="min-width:36px;background:#d4edda;color:#155724;">P</th>
          <th class="text-center" style="min-width:36px;background:#fff3cd;color:#856404;">L</th>
          <th class="text-center" style="min-width:36px;background:#f8d7da;color:#721c24;">A</th>
          <th class="text-center" style="min-width:42px;">Tot</th>
          <th class="text-center" style="min-width:65px;">%</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="position:sticky;left:0;z-index:1;background:#fff;font-weight:600;min-width:100px;">
            <?= e($me['full_name']) ?>
          </td>
          <?php foreach ($sessions as $s):
            $sid   = (int) $s['session_id'];
            $isHol = isset($holidayMap[$s['session_date']]);
            $entry = $myAttMap[$sid] ?? null;
            $fs    = stu_att_status($entry, $s['session_date'], $today);
          ?>
          <?php if ($isHol): ?>
            <td class="text-center fw-bold" style="background:#fff3cd;color:#856404;">H</td>
          <?php elseif ($fs === ''): ?>
            <td class="text-center" style="color:#ddd;">—</td>
          <?php elseif (!$entry || $entry['is_auto']): ?>
            <td class="text-center fw-bold" style="background:#f8d7da;color:#721c24;">A</td>
          <?php else: ?>
            <?php
              $hasOut = !empty($entry['out_time']);
              $inTime = $entry['in_time'] ?? '?';
              if ($fs === 'P')     { $bg = '#d4edda'; $fc = '#155724'; $sym = '✓'; }
              elseif ($fs === 'L') { $bg = '#fff3cd'; $fc = '#856404'; $sym = 'L'; }
              else                 { $bg = '#f8d7da'; $fc = '#721c24'; $sym = 'A'; }
            ?>
            <td style="background:<?= $bg ?>;color:<?= $fc ?>;text-align:center;line-height:1.4;padding:3px 3px;"
                title="In: <?= e($inTime) ?> | Out: <?= $hasOut ? e($entry['out_time']) : 'No sign-out' ?>">
              <div style="font-size:.67rem;"><?= e($inTime) ?></div>
              <div style="font-size:.67rem;">
                <?= $hasOut ? e($entry['out_time']) : '<span style="color:#dc3545;font-size:.6rem;">No Out</span>' ?>
              </div>
              <div style="font-weight:700;font-size:.75rem;"><?= $sym ?></div>
            </td>
          <?php endif; ?>
          <?php endforeach; ?>
          <td class="text-center fw-bold" style="background:#d4edda;color:#155724;"><?= $pCnt ?></td>
          <td class="text-center fw-bold" style="background:#fff3cd;color:#856404;"><?= $lCnt ?></td>
          <td class="text-center fw-bold" style="background:#f8d7da;color:#721c24;"><?= $aCnt ?></td>
          <td class="text-center fw-semibold"><?= $total ?></td>
          <td class="text-center fw-bold">
            <span style="color:<?= $eligible ? '#155724' : '#721c24' ?>;"><?= number_format($pct, 1) ?>%</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<?php endif; // sessions ?>
<?php endif; // allModules ?>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const CSRF = '<?= csrf_token() ?>';
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
  fetch(window.SEMAS_BASE_URL + '/api/student-attendance-scan.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body,
  })
  .then(function (r) { return r.json(); })
  .then(function (data) {
    const cls = data.ok ? 'alert-success' : 'alert-danger';
    document.getElementById('scanMsg').innerHTML = '<div class="alert ' + cls + ' small mt-2">' + data.message + '</div>';
    if (data.ok) {
      setTimeout(function () { location.reload(); }, 1500);
    }
  })
  .catch(function () {
    document.getElementById('scanMsg').innerHTML = '<div class="alert alert-danger small mt-2">Network error. Try again.</div>';
  });
}
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
