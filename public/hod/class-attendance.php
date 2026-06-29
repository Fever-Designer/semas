<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD', 'Coordinator']);
Module::autoCompleteExpired();

$pageTitle  = 'Class Attendance Overview';
$activeNav  = 'hod-attendance';
$db         = Database::connection();
$me         = Auth::user();
$isCoordinator = Auth::role() === 'Coordinator';

$moduleId      = (int) ($_GET['module_id'] ?? 0);
$rangeType     = $_GET['range'] ?? 'daily';
$rangeDate     = $_GET['date'] ?? date('Y-m-d');
$sessionFilter = $_GET['session'] ?? '';   // Day | Evening | Weekend | ''
if ($isCoordinator) {
    $sessionFilter = 'Weekend';
}

// Build SQL date range
if ($rangeType === 'weekly') {
    $monday   = date('Y-m-d', strtotime('monday this week', strtotime($rangeDate)));
    $sunday   = date('Y-m-d', strtotime('sunday this week', strtotime($rangeDate)));
    $dateFrom = $monday;
    $dateTo   = $sunday;
} elseif ($rangeType === 'monthly') {
    $dateFrom = date('Y-m-01', strtotime($rangeDate));
    $dateTo   = date('Y-m-t',  strtotime($rangeDate));
} else {
    $dateFrom = $rangeDate;
    $dateTo   = $rangeDate;
}

// Export CSV
if (($_GET['export'] ?? '') === 'csv' && $moduleId) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_' . $rangeType . '_' . $dateFrom . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student Name', 'Reg Number', 'Date', 'Session', 'Status', 'Check-in Time']);
    $rows = $db->prepare(
        "SELECT u.full_name, u.reg_number, cs.session_date, cs.window_name,
                cal.status, cal.checkin_time
         FROM class_attendance_logs cal
         JOIN class_sessions cs ON cs.session_id = cal.session_id
         JOIN users u ON u.user_id = cal.user_id
         WHERE cs.module_id = :mid AND cs.session_date BETWEEN :from AND :to
               AND cal.attendance_type = 'Sign In'
         ORDER BY cs.session_date, u.full_name"
    );
    $rows->execute(['mid' => $moduleId, 'from' => $dateFrom, 'to' => $dateTo]);
    foreach ($rows->fetchAll() as $r) {
        fputcsv($out, [$r['full_name'], $r['reg_number'], $r['session_date'], $r['window_name'], $r['status'], $r['checkin_time']]);
    }
    fclose($out);
    exit;
}

// All modules for sidebar selector
$allModulesSql =
    "SELECT m.module_id, m.module_title, m.status, d.department_name
     FROM modules m LEFT JOIN departments d ON d.department_id = m.department_id";
if ($isCoordinator) {
    $allModulesSql .= " WHERE m.session_type = 'Weekend'";
}
$allModulesSql .= " ORDER BY m.status DESC, m.module_title";
$allModules = $db->query($allModulesSql)->fetchAll();

// Summary table: per-module present/late/absent counts, optionally filtered by session type
$sessionWhere = in_array($sessionFilter, ['Day', 'Evening', 'Weekend'], true)
    ? "AND m.session_type = '" . $sessionFilter . "'"
    : '';
$summaryStmt = $db->query(
    "SELECT m.module_id, m.module_title, m.status, m.session_type,
        SUM(CASE WHEN cal.status = 'Present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN cal.status = 'Late'    THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN cal.status = 'Absent'  THEN 1 ELSE 0 END) AS absent_count,
        COUNT(DISTINCT cs.session_id) AS session_count
     FROM modules m
     LEFT JOIN class_sessions cs        ON cs.module_id = m.module_id
         AND cs.session_date BETWEEN '$dateFrom' AND '$dateTo'
     LEFT JOIN class_attendance_logs cal ON cal.session_id = cs.session_id
         AND cal.attendance_type = 'Sign In'
     WHERE 1=1 $sessionWhere
     GROUP BY m.module_id, m.module_title, m.status, m.session_type
     ORDER BY m.status DESC, m.module_title"
);
$summary          = $summaryStmt->fetchAll();
$ongoingSummary   = array_values(array_filter($summary, function ($r) { return $r['status'] === 'Ongoing'; }));
$completedSummary = array_values(array_filter($summary, function ($r) { return $r['status'] === 'Completed'; }));

// Calendar view: all sessions + all enrolled students + attendance pivot for selected module
$detailModule      = null;
$calendarSessions  = [];
$calendarStudents  = [];
$calendarAttMap    = [];
if ($moduleId) {
    foreach ($allModules as $am) {
        if ((int) $am['module_id'] === $moduleId) { $detailModule = $am; break; }
    }
    if ($detailModule) {
        $sessAllStmt = $db->prepare(
            "SELECT session_id, session_date, window_name FROM class_sessions
             WHERE module_id = :mid ORDER BY session_date ASC, window_name ASC"
        );
        $sessAllStmt->execute(['mid' => $moduleId]);
        $calendarSessions = $sessAllStmt->fetchAll();

        $stuAllStmt = $db->prepare(
            "SELECT u.user_id, u.full_name, u.reg_number
             FROM module_enrollments me JOIN users u ON u.user_id = me.user_id
             WHERE me.module_id = :mid ORDER BY u.full_name"
        );
        $stuAllStmt->execute(['mid' => $moduleId]);
        $calendarStudents = $stuAllStmt->fetchAll();

        if ($calendarSessions) {
            $attAllStmt = $db->prepare(
                "SELECT cal.user_id, cal.session_id, cal.status
                 FROM class_attendance_logs cal
                 JOIN class_sessions cs ON cs.session_id = cal.session_id
                 WHERE cs.module_id = :mid AND cal.attendance_type = 'Sign In'"
            );
            $attAllStmt->execute(['mid' => $moduleId]);
            foreach ($attAllStmt->fetchAll() as $att) {
                $calendarAttMap[(int)$att['user_id']][(int)$att['session_id']] = $att['status'];
            }
        }
    }
}

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h4 class="display-font mb-1">Class Attendance Overview</h4>
    <p class="text-muted small mb-0">Monitor attendance across all modules. Click a module row for the detailed session breakdown.</p>
  </div>
</div>

<!-- Filter controls -->
<?php
$qBase = 'range=' . urlencode($rangeType) . '&date=' . urlencode($rangeDate) . ($sessionFilter ? '&session=' . urlencode($sessionFilter) : '');
?>
<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="module_id" value="<?= $moduleId ?>">
    <div class="col-md-3">
      <label class="form-label small mb-1">Session</label>
      <select name="session" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All Sessions</option>
        <option value="Day"     <?= $sessionFilter === 'Day'     ? 'selected' : '' ?>>Day</option>
        <option value="Evening" <?= $sessionFilter === 'Evening' ? 'selected' : '' ?>>Evening</option>
        <option value="Weekend" <?= $sessionFilter === 'Weekend' ? 'selected' : '' ?>>Weekend</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1">View</label>
      <select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="daily"   <?= $rangeType === 'daily'   ? 'selected' : '' ?>>Daily</option>
        <option value="weekly"  <?= $rangeType === 'weekly'  ? 'selected' : '' ?>>Weekly</option>
        <option value="monthly" <?= $rangeType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-1">Date</label>
      <input type="date" name="date" class="form-control form-control-sm" value="<?= e($rangeDate) ?>" onchange="this.form.submit()">
    </div>
    <div class="col-md-2">
      <button class="btn btn-semas btn-sm w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
    </div>
    <?php if ($moduleId): ?>
      <div class="col-md-2 text-end">
        <a href="?module_id=<?= $moduleId ?>&<?= $qBase ?>&export=csv"
           class="btn btn-sm btn-outline-dark"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
        <a href="<?= APP_URL ?>/hod/attendance-pdf.php?module_id=<?= $moduleId ?>&<?= $qBase ?>"
           target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
      </div>
    <?php endif; ?>
  </form>
  <p class="text-muted small mb-0 mt-2">
    Showing: <strong><?= date('d M Y', strtotime($dateFrom)) ?></strong>
    <?php if ($dateFrom !== $dateTo): ?> to <strong><?= date('d M Y', strtotime($dateTo)) ?></strong><?php endif; ?>
    <?php if ($sessionFilter): ?> &middot; <span class="badge bg-secondary"><?= e($sessionFilter) ?> Session</span><?php endif; ?>
  </p>
</div>

<!-- Ongoing modules summary table -->
<div class="semas-card p-3 mb-4">
  <h6 class="display-font mb-2">Ongoing Modules — Attendance Summary</h6>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Module</th><th>Sessions</th><th class="text-success">Present</th><th class="text-warning">Late</th><th class="text-danger">Absent</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($ongoingSummary as $row): ?>
          <tr class="<?= (int) $row['module_id'] === $moduleId ? 'table-active' : '' ?>">
            <td class="fw-semibold"><?= e($row['module_title']) ?></td>
            <td><?= (int) $row['session_count'] ?></td>
            <td class="text-success fw-semibold"><?= (int) $row['present_count'] ?></td>
            <td class="text-warning fw-semibold"><?= (int) $row['late_count'] ?></td>
            <td class="text-danger fw-semibold"><?= (int) $row['absent_count'] ?></td>
            <td>
              <a href="?module_id=<?= (int) $row['module_id'] ?>&<?= $qBase ?>"
                 class="btn btn-sm btn-outline-dark">Detail</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$ongoingSummary): ?>
          <tr><td colspan="6" class="text-muted small text-center py-3">No ongoing modules.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Calendar view for selected module -->
<?php if ($moduleId && $detailModule): ?>
  <div class="semas-card p-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <div>
        <h6 class="display-font mb-0">
          <?= e($detailModule['module_title']) ?> — Attendance Calendar
          <span class="badge <?= $detailModule['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?> ms-1"><?= e($detailModule['status']) ?></span>
        </h6>
        <p class="text-muted small mb-0">All sessions from start to today. <span style="background:#d4edda;padding:1px 6px;border-radius:3px;font-size:.75rem;">P=Present</span> <span style="background:#fff3cd;padding:1px 6px;border-radius:3px;font-size:.75rem;">L=Late</span> <span style="background:#f8d7da;padding:1px 6px;border-radius:3px;font-size:.75rem;">A=Absent</span></p>
      </div>
      <div class="d-flex gap-2">
        <a href="?module_id=<?= $moduleId ?>&<?= $qBase ?>&export=csv" class="btn btn-sm btn-outline-dark"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
        <a href="<?= APP_URL ?>/hod/attendance-pdf.php?module_id=<?= $moduleId ?>&<?= $qBase ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        <a href="?<?= $qBase ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
      </div>
    </div>

    <?php if (!$calendarSessions): ?>
      <p class="text-muted small mb-0">No attendance sessions recorded for this module yet.</p>
    <?php elseif (!$calendarStudents): ?>
      <p class="text-muted small mb-0">No students enrolled in this module.</p>
    <?php else: ?>
      <?php $today2 = date('Y-m-d'); ?>
      <div style="overflow-x:auto;">
        <table class="table table-bordered table-sm mb-0" style="white-space:nowrap;font-size:.82rem;">
          <thead>
            <tr>
              <th style="position:sticky;left:0;z-index:2;background:#f8f9fa;min-width:190px;vertical-align:middle;">Student</th>
              <?php foreach ($calendarSessions as $cs): ?>
                <th class="text-center <?= $cs['session_date'] === $today2 ? 'table-primary' : '' ?>" style="min-width:54px;vertical-align:middle;">
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
                    $st = $calendarAttMap[$student['user_id']][$cs['session_id']] ?? null;
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
                  <?php $status = $calendarAttMap[$student['user_id']][$cs['session_id']] ?? null; ?>
                  <td class="text-center fw-bold" style="vertical-align:middle;<?php
                    if ($status === 'Present') echo 'background:#d4edda;color:#155724;';
                    elseif ($status === 'Late') echo 'background:#fff3cd;color:#856404;';
                    elseif ($status === 'Absent') echo 'background:#f8d7da;color:#721c24;';
                    else echo 'color:#ccc;';
                  ?>">
                    <?= $status ? ($status[0]) : '·' ?>
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
<?php endif; ?>

<?php if ($completedSummary): ?>
<div class="semas-card mt-4">
  <button class="btn w-100 text-start p-3 d-flex justify-content-between align-items-center"
          type="button" data-bs-toggle="collapse" data-bs-target="#completedModulesTable" aria-expanded="false">
    <span class="display-font" style="font-size:1rem;">
      Completed Modules <span class="badge bg-secondary ms-2"><?= count($completedSummary) ?></span>
    </span>
    <i class="bi bi-chevron-down"></i>
  </button>
  <div class="collapse" id="completedModulesTable">
    <div class="p-3 pt-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th>Module</th><th>Sessions</th><th class="text-success">Present</th><th class="text-warning">Late</th><th class="text-danger">Absent</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($completedSummary as $row): ?>
              <tr>
                <td class="fw-semibold"><?= e($row['module_title']) ?></td>
                <td><?= (int) $row['session_count'] ?></td>
                <td class="text-success fw-semibold"><?= (int) $row['present_count'] ?></td>
                <td class="text-warning fw-semibold"><?= (int) $row['late_count'] ?></td>
                <td class="text-danger fw-semibold"><?= (int) $row['absent_count'] ?></td>
                <td class="text-nowrap">
                  <a href="?module_id=<?= (int) $row['module_id'] ?>&range=monthly&date=<?= date('Y-m-d') ?>"
                     class="btn btn-sm btn-outline-dark"><i class="bi bi-clock-history me-1"></i>View Attendance</a>
                  <a href="?module_id=<?= (int) $row['module_id'] ?>&range=monthly&date=<?= date('Y-m-d') ?>&export=csv"
                     class="btn btn-sm btn-outline-dark"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
                  <a href="<?= APP_URL ?>/hod/attendance-pdf.php?module_id=<?= (int) $row['module_id'] ?>&range=monthly&date=<?= date('Y-m-d') ?>"
                     target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
document.querySelector('[data-bs-target="#completedModulesTable"]').addEventListener('click', function() {
  var icon = this.querySelector('.bi-chevron-down, .bi-chevron-up');
  if (icon) icon.className = icon.className.includes('chevron-down') ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
