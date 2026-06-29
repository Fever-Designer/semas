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

$modulesStmt = $db->prepare(
    "SELECT m.*, u.full_name AS lecturer_name FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE e.user_id = :uid AND m.status = 'Ongoing' ORDER BY m.module_title"
);
$modulesStmt->execute(['uid' => $me['user_id']]);
$allModules = $modulesStmt->fetchAll();

// Attendance calendar: all enrolled modules (ongoing + completed)
$histModulesStmt = $db->prepare(
    "SELECT m.module_id, m.module_title, m.session_type, m.start_date, m.end_date, m.status,
            u.full_name AS lecturer_name
     FROM modules m
     JOIN module_enrollments e ON e.module_id = m.module_id
     LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE e.user_id = :uid ORDER BY m.status DESC, m.module_title"
);
$histModulesStmt->execute(['uid' => $me['user_id']]);
$histModules = $histModulesStmt->fetchAll();


function module_matches_window(array $module, ?array $window): bool
{
    if (!$window) return false;
    if (!$module['session_type']) return true;
    if ($module['session_type'] === 'Day')     return $window['name'] === 'Day';
    if ($module['session_type'] === 'Evening') return $window['name'] === 'Evening';
    if ($module['session_type'] === 'Weekend') return in_array($window['name'], ['WeekendMorning', 'WeekendAfternoon', 'UmugandaMorning', 'UmugandaAfternoon'], true);
    return true;
}

function module_within_dates(array $module): bool
{
    if (!$module['start_date'] || !$module['end_date']) return true;
    $today = ClassAttendance::now()->format('Y-m-d');
    return $today >= $module['start_date'] && $today <= $module['end_date'];
}

// Only show modules that match the currently active session window
$modules = $window
  ? array_values(array_filter($allModules, function ($m) use ($window) { return module_matches_window($m, $window) && module_within_dates($m); }))
  : [];

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Class Attendance</h4>

<?php if ($holiday): ?>
  <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>
    Today is <strong><?= e($holiday['title']) ?></strong> (<?= e($holiday['holiday_type']) ?>)<?= $holiday['holiday_type'] === 'Public Holiday' ? ' — attendance is disabled today.' : ' — Umuganda adjusted hours apply today.' ?>
  </div>
<?php elseif (!$window): ?>
  <div class="alert alert-secondary small">
    <i class="bi bi-clock me-1"></i> No session window is currently active.
  </div>
<?php else: ?>
  <div class="alert alert-success small"><i class="bi bi-check-circle me-1"></i> Active now: <strong><?= e(ClassAttendance::describeWindow($window)) ?></strong></div>
<?php endif; ?>

<?php if ($window): ?>
<div class="row g-3">
  <?php foreach ($modules as $m): ?>
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
        <button class="btn btn-sm btn-semas-gold scan-btn" data-module="<?= (int) $m['module_id'] ?>">
          <i class="bi bi-camera-fill me-1"></i> Scan In / Out
        </button>
        <div class="scan-result mt-2 small" data-module-result="<?= (int) $m['module_id'] ?>"></div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$modules): ?>
    <div class="col-12">
      <div class="semas-card p-4 text-center text-muted small">
        No modules active during the current session (<?= e(ClassAttendance::describeWindow($window)) ?>).
      </div>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

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

<!-- ── Attendance History Calendar ─────────────────────────────────────── -->
<div class="mt-4">
  <h5 class="display-font mb-1">Attendance History</h5>
  <p class="text-muted small mb-3">Your attendance per session for each enrolled module. <span style="background:#d4edda;padding:1px 5px;border-radius:3px;font-size:.75rem;">P=Present</span> <span style="background:#fff3cd;padding:1px 5px;border-radius:3px;font-size:.75rem;">L=Late</span> <span style="background:#f8d7da;padding:1px 5px;border-radius:3px;font-size:.75rem;">A=Absent</span></p>

  <?php if (!$histModules): ?>
    <div class="semas-card p-4 text-center text-muted small">You are not enrolled in any modules yet.</div>
  <?php endif; ?>

  <?php foreach ($histModules as $hm): ?>
    <?php
      $hmSessStmt = $db->prepare(
          "SELECT session_id, session_date, window_name FROM class_sessions
           WHERE module_id = :mid ORDER BY session_date ASC, window_name ASC"
      );
      $hmSessStmt->execute(['mid' => $hm['module_id']]);
      $hmSessions = $hmSessStmt->fetchAll();
      if (!$hmSessions) continue;

      $hmAttStmt = $db->prepare(
          "SELECT cal.session_id, cal.status
           FROM class_attendance_logs cal
           JOIN class_sessions cs ON cs.session_id = cal.session_id
           WHERE cs.module_id = :mid AND cal.user_id = :uid AND cal.attendance_type = 'Sign In'"
      );
      $hmAttStmt->execute(['mid' => $hm['module_id'], 'uid' => $me['user_id']]);
      $hmAttMap = [];
      foreach ($hmAttStmt->fetchAll() as $ha) {
          $hmAttMap[$ha['session_id']] = $ha['status'];
      }
      $todayNow = date('Y-m-d');
      $pCnt = 0; $lCnt = 0; $aCnt = 0;
      foreach ($hmSessions as $hs) {
          $s = $hmAttMap[$hs['session_id']] ?? null;
          if ($s === 'Present') $pCnt++;
          elseif ($s === 'Late') $lCnt++;
          elseif ($s === 'Absent') $aCnt++;
      }
      $total = $pCnt + $lCnt + $aCnt;
      $pct = $total > 0 ? round(($pCnt + $lCnt) / $total * 100) : 0;
    ?>
    <div class="semas-card p-3 mb-3" id="hist-<?= (int) $hm['module_id'] ?>">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-2">
        <div>
          <h6 class="display-font mb-0"><?= e($hm['module_title']) ?>
            <span class="badge <?= $hm['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?> ms-1" style="font-size:.65rem;"><?= e($hm['status']) ?></span>
          </h6>
          <p class="text-muted small mb-0">Lecturer: <?= e($hm['lecturer_name'] ?? 'TBA') ?> &middot; <?= e($hm['session_type'] ?? 'Any') ?></p>
        </div>
        <div class="text-end small">
          <span class="text-success fw-semibold"><?= $pCnt ?>P</span>
          <span class="text-warning fw-semibold ms-1"><?= $lCnt ?>L</span>
          <span class="text-danger fw-semibold ms-1"><?= $aCnt ?>A</span>
          <span class="text-muted ms-2"><?= $pct ?>% attendance</span>
        </div>
      </div>
      <div style="overflow-x:auto;">
        <table class="table table-bordered table-sm mb-0" style="white-space:nowrap;font-size:.82rem;">
          <thead>
            <tr>
              <th style="position:sticky;left:0;z-index:2;background:#f8f9fa;min-width:80px;vertical-align:middle;">You</th>
              <?php foreach ($hmSessions as $hs): ?>
                <th class="text-center <?= $hs['session_date'] === $todayNow ? 'table-primary' : '' ?>" style="min-width:50px;vertical-align:middle;">
                  <div><?= date('D', strtotime($hs['session_date'])) ?></div>
                  <div><?= date('d/m', strtotime($hs['session_date'])) ?></div>
                  <?php
                    $wn = $hs['window_name'];
                    if ($wn === 'WeekendMorning') echo '<div style="font-size:.6rem;">Morn</div>';
                    elseif ($wn === 'WeekendAfternoon') echo '<div style="font-size:.6rem;">Aftn</div>';
                    elseif (str_starts_with($wn, 'Umuganda')) echo '<div style="font-size:.6rem;">Umug</div>';
                  ?>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td style="position:sticky;left:0;z-index:1;background:#fff;font-weight:500;vertical-align:middle;">My Attendance</td>
              <?php foreach ($hmSessions as $hs): ?>
                <?php $status = $hmAttMap[$hs['session_id']] ?? null; ?>
                <td class="text-center fw-bold" style="vertical-align:middle;<?php
                  if ($status === 'Present') echo 'background:#d4edda;color:#155724;';
                  elseif ($status === 'Late') echo 'background:#fff3cd;color:#856404;';
                  elseif ($status === 'Absent') echo 'background:#f8d7da;color:#721c24;';
                  elseif ($hs['session_date'] <= $todayNow) echo 'color:#ccc;';
                  else echo 'color:#e0e0e0;';
                ?>">
                  <?= $status ? $status[0] : ($hs['session_date'] <= $todayNow ? '·' : '') ?>
                </td>
              <?php endforeach; ?>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
