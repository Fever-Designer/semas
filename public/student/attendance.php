<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);
Module::autoCompleteExpired();

$pageTitle = 'Class Attendance';
$activeNav = 'class-attendance';
$db = Database::connection();
$me = Auth::user();

$window  = ClassAttendance::currentWindow();
$holiday = ClassAttendance::holidayToday();

// All ongoing enrolled modules (with start/end dates for the calendar grid)
$modulesStmt = $db->prepare(
    "SELECT m.*, u.full_name AS lecturer_name FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE e.user_id = :uid AND m.status = 'Ongoing' ORDER BY m.module_title"
);
$modulesStmt->execute(['uid' => $me['user_id']]);
$modules = $modulesStmt->fetchAll();

// Derive the student's effective session types from their enrolled modules.
$sessionTypes = array_unique(array_filter(array_column($modules, 'session_type')));

// --- Session window description helpers scoped by session type ---------
function window_description_for_types(array $types): string
{
    $all = ['Day' => 'Day 08:00–11:30 (Mon–Fri)', 'Evening' => 'Evening 18:00–20:00 (Mon–Fri)', 'Weekend' => 'Weekend Morning 08:30–14:00 & Afternoon 14:30–20:30 (Sat–Sun)'];
    if (empty($types)) {
        return implode(' · ', $all);
    }
    $out = [];
    foreach ($types as $t) {
        if (isset($all[$t])) $out[] = $all[$t];
    }
    return $out ? implode(' · ', $out) : implode(' · ', $all);
}

function module_matches_window(array $module, ?array $window): bool
{
    if (!$window) return false;
    if (!$module['session_type']) return true;
    if ($module['session_type'] === 'Day')     return $window['name'] === 'Day';
    if ($module['session_type'] === 'Evening') return $window['name'] === 'Evening';
    if ($module['session_type'] === 'Weekend') return in_array($window['name'], ['WeekendMorning', 'WeekendAfternoon', 'UmugandaMorning', 'UmugandaAfternoon'], true);
    return true;
}

// Check if today is within a module's date bounds (Cairo date)
function module_within_dates(array $module): bool
{
    if (!$module['start_date'] || !$module['end_date']) return true; // no bounds set yet
    $today = ClassAttendance::now()->format('Y-m-d');
    return $today >= $module['start_date'] && $today <= $module['end_date'];
}

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Class Attendance</h4>
<p class="text-muted small mb-3">Sign in within the first 10 minutes of class to be marked <strong>Present</strong>, or within 20 minutes to be marked <strong>Late</strong>. Sign out within 10 minutes after the session ends.</p>

<?php if ($holiday): ?>
  <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>
    Today is <strong><?= e($holiday['title']) ?></strong> (<?= e($holiday['holiday_type']) ?>)<?= $holiday['holiday_type'] === 'Public Holiday' ? ' — attendance is disabled today.' : ' — Umuganda adjusted hours apply today.' ?>
  </div>
<?php elseif (!$window): ?>
  <div class="alert alert-secondary small">
    <i class="bi bi-clock me-1"></i> No session window is currently active.
    Your class time<?= count($sessionTypes) > 1 ? 's' : '' ?>:
    <strong><?= e(window_description_for_types($sessionTypes)) ?></strong> (Cairo time).
  </div>
<?php else: ?>
  <div class="alert alert-success small"><i class="bi bi-check-circle me-1"></i> Active now: <strong><?= e(ClassAttendance::describeWindow($window)) ?></strong></div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ($modules as $m):
    $matchesWindow = module_matches_window($m, $window);
    $withinDates   = module_within_dates($m);
    $canScan       = $matchesWindow && $withinDates;
  ?>
    <div class="col-md-4">
      <div class="semas-card p-3 h-100">
        <h6 class="fw-semibold mb-1"><?= e($m['module_title']) ?></h6>
        <p class="text-muted small mb-1">
          Lecturer: <?= e($m['lecturer_name'] ?? 'TBA') ?><br>
          Session: <?= e($m['session_type'] ?? 'Any') ?>
          <?php if ($m['room']): ?>&nbsp;&middot;&nbsp;Room: <?= e($m['room']) ?><?php endif; ?>
        </p>
        <?php if ($m['start_date'] && $m['end_date']): ?>
          <p class="text-muted" style="font-size:.73rem; margin-bottom:.4rem;">
            <i class="bi bi-calendar3 me-1"></i><?= e(date('d M Y', strtotime($m['start_date']))) ?> – <?= e(date('d M Y', strtotime($m['end_date']))) ?>
          </p>
        <?php endif; ?>

        <?php if (!$withinDates): ?>
          <span class="badge bg-secondary">Outside module period</span>
        <?php elseif ($canScan): ?>
          <button class="btn btn-sm btn-semas-gold scan-btn" data-module="<?= (int) $m['module_id'] ?>">
            <i class="bi bi-camera-fill me-1"></i> Scan In / Out
          </button>
        <?php else: ?>
          <span class="badge bg-secondary">No active class now</span>
        <?php endif; ?>

        <div class="scan-result mt-2 small" data-module-result="<?= (int) $m['module_id'] ?>"></div>
        <a href="#hist-<?= (int) $m['module_id'] ?>" class="d-block small mt-2 text-muted">View attendance &darr;</a>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$modules): ?>
    <div class="col-12">
      <div class="semas-card p-4 text-center text-muted small">
        You're not registered for any ongoing module. <a href="<?= APP_URL ?>/student/modules.php">Register now</a>.
      </div>
    </div>
  <?php endif; ?>
</div>

<?php foreach ($modules as $m): ?>
  <?php
    // Build daily attendance grid for this module within its date bounds.
    // Determine date range: use start_date/end_date if set, else fall back to last 30 days.
    $gridStart = $m['start_date'] ?: date('Y-m-d', strtotime('-30 days'));
    $gridEnd   = $m['end_date']   ?: date('Y-m-d');
    // Limit to today maximum (no future rows)
    if ($gridEnd > date('Y-m-d')) $gridEnd = date('Y-m-d');

    // Fetch all class sessions in range for this module
    $sessStmt = $db->prepare(
        "SELECT cs.*, cal_in.status AS signin_status, cal_in.checkin_time AS signin_time,
                cal_in.verification_method AS signin_method,
                cal_out.checkin_time AS signout_time
         FROM class_sessions cs
         LEFT JOIN class_attendance_logs cal_in  ON cal_in.session_id  = cs.session_id AND cal_in.user_id  = :uid AND cal_in.attendance_type  = 'Sign In'
         LEFT JOIN class_attendance_logs cal_out ON cal_out.session_id = cs.session_id AND cal_out.user_id = :uid2 AND cal_out.attendance_type = 'Sign Out'
         WHERE cs.module_id = :mid AND cs.session_date BETWEEN :start AND :end
         ORDER BY cs.session_date ASC, cs.window_name ASC"
    );
    $sessStmt->execute(['uid' => $me['user_id'], 'uid2' => $me['user_id'], 'mid' => $m['module_id'], 'start' => $gridStart, 'end' => $gridEnd]);
    $sessions = $sessStmt->fetchAll();

    // Attendance summary counters
    $totalSessions  = 0;
    $attendedCount  = 0;
    foreach ($sessions as $s) {
        if ($s['status'] === 'Closed') {
            $totalSessions++;
            if (in_array($s['signin_status'], ['Present', 'Late'], true)) $attendedCount++;
        }
    }
    $pct = $totalSessions > 0 ? round($attendedCount / $totalSessions * 100) : null;

    $statusColors = ['Present' => 'badge-completed', 'Late' => 'badge-urgent', 'Absent' => 'bg-secondary'];
  ?>
  <div class="semas-card p-3 mb-3" id="hist-<?= (int) $m['module_id'] ?>">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
      <h6 class="display-font mb-0"><?= e($m['module_title']) ?> — Daily Attendance</h6>
      <?php if ($pct !== null): ?>
        <span class="badge <?= $pct >= 75 ? 'badge-completed' : 'badge-cancelled' ?>" title="<?= $attendedCount ?> / <?= $totalSessions ?> sessions">
          <?= $pct ?>% attended
        </span>
      <?php endif; ?>
    </div>
    <?php if (!$sessions): ?>
      <p class="text-muted small mb-0">No sessions recorded yet for this module.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle" style="font-size:.84rem;">
          <thead>
            <tr>
              <th>Date</th>
              <th>Session</th>
              <th>Sign In</th>
              <th>Sign In Time</th>
              <th>Sign Out</th>
              <th>Sign Out Time</th>
              <th>Method</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sessions as $s):
              $sinStatus  = $s['signin_status'];
              $isAuto     = ($s['signin_method'] === 'Auto');
              $displayStatus = ($sinStatus && !$isAuto) ? $sinStatus : ($sinStatus === 'Absent' && $s['status'] === 'Closed' ? 'Absent' : null);
              $sinTime    = ($s['signin_time'] && !$isAuto) ? date('h:i A', strtotime($s['signin_time'])) : null;
              $soutTime   = $s['signout_time'] ? date('h:i A', strtotime($s['signout_time'])) : null;
              $dayLabel   = date('D d M', strtotime($s['session_date']));
              $winLabels  = ['Day' => 'Day', 'Evening' => 'Evening', 'WeekendMorning' => 'Wknd AM', 'WeekendAfternoon' => 'Wknd PM', 'UmugandaMorning' => 'Umuganda AM', 'UmugandaAfternoon' => 'Umuganda PM'];
            ?>
            <tr>
              <td class="text-nowrap"><?= e($dayLabel) ?></td>
              <td><?= e($winLabels[$s['window_name']] ?? $s['window_name']) ?></td>
              <td>
                <?php if ($displayStatus): ?>
                  <span class="badge <?= $statusColors[$displayStatus] ?? 'bg-secondary' ?>"><?= e($displayStatus) ?></span>
                <?php elseif ($s['status'] === 'Open'): ?>
                  <span class="text-muted small">Open</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Absent</span>
                <?php endif; ?>
              </td>
              <td class="text-nowrap"><?= $sinTime ? e($sinTime) : '<span class="text-muted">—</span>' ?></td>
              <td><?= $soutTime ? '<span class="badge bg-success-subtle text-success border">Out</span>' : '<span class="text-muted small">—</span>' ?></td>
              <td class="text-nowrap"><?= $soutTime ? e($soutTime) : '<span class="text-muted">—</span>' ?></td>
              <td class="text-muted small"><?= (!$isAuto && $s['signin_method']) ? e($s['signin_method']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pct !== null && $pct < 75): ?>
        <div class="alert alert-danger small mb-0 py-1 px-2">
          <i class="bi bi-exclamation-triangle me-1"></i>
          Your attendance is <strong><?= $pct ?>%</strong>. A minimum of <strong>75%</strong> is required for CAT/Exam eligibility.
          Contact your HOD if you have a valid reason for missed sessions.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<script>
const CSRF = '<?= csrf_token() ?>';
document.querySelectorAll('.scan-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    const moduleId = btn.getAttribute('data-module');
    const resultEl = document.querySelector('[data-module-result="' + moduleId + '"]');
    btn.disabled = true;
    fetch(window.SEMAS_BASE_URL + '/api/student-attendance-scan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'module_id=' + moduleId + '&csrf_token=' + encodeURIComponent(CSRF)
    }).then(r => r.json()).then(function (data) {
      resultEl.innerHTML = '<span class="' + (data.ok ? 'text-success' : 'text-danger') + '">' + data.message + '</span>';
      btn.disabled = false;
      if (data.ok && data.type === 'Sign In')  { btn.textContent = 'Signed In ✓ — Scan again to Sign Out'; }
      if (data.ok && data.type === 'Sign Out') { btn.disabled = true; btn.textContent = 'Done for this session'; }
    }).catch(function () {
      resultEl.innerHTML = '<span class="text-danger">Network error — please try again.</span>';
      btn.disabled = false;
    });
  });
});
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
