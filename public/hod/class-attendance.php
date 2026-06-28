<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD']);
Module::autoCompleteExpired();

$pageTitle  = 'Class Attendance Overview';
$activeNav  = 'hod-attendance';
$db         = Database::connection();
$me         = Auth::user();

$moduleId   = (int) ($_GET['module_id'] ?? 0);
$rangeType  = $_GET['range'] ?? 'daily';     // daily | weekly | monthly
$rangeDate  = $_GET['date'] ?? date('Y-m-d'); // anchor date

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
$allModules = $db->query(
    "SELECT m.module_id, m.module_title, m.status, d.department_name
     FROM modules m LEFT JOIN departments d ON d.department_id = m.department_id
     ORDER BY m.status DESC, m.module_title"
)->fetchAll();

// Summary table: per-module daily present/late/absent counts
$summaryStmt = $db->query(
    "SELECT m.module_id, m.module_title, m.status,
        SUM(CASE WHEN cal.status = 'Present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN cal.status = 'Late'    THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN cal.status = 'Absent'  THEN 1 ELSE 0 END) AS absent_count,
        COUNT(DISTINCT cs.session_id) AS session_count
     FROM modules m
     LEFT JOIN class_sessions cs        ON cs.module_id = m.module_id
         AND cs.session_date BETWEEN '$dateFrom' AND '$dateTo'
     LEFT JOIN class_attendance_logs cal ON cal.session_id = cs.session_id
         AND cal.attendance_type = 'Sign In'
     GROUP BY m.module_id, m.module_title, m.status
     ORDER BY m.status DESC, m.module_title"
);
$summary = $summaryStmt->fetchAll();

// Detailed view: attendance per session for selected module
$detailSessions = [];
$detailModule   = null;
if ($moduleId) {
    foreach ($allModules as $am) {
        if ((int) $am['module_id'] === $moduleId) { $detailModule = $am; break; }
    }
    $sessStmt = $db->prepare(
        "SELECT cs.session_id, cs.session_date, cs.window_name, cs.status AS session_status,
                SUM(CASE WHEN cal.status = 'Present' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN cal.status = 'Late'    THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN cal.status = 'Absent'  THEN 1 ELSE 0 END) AS absent_count
         FROM class_sessions cs
         LEFT JOIN class_attendance_logs cal ON cal.session_id = cs.session_id AND cal.attendance_type = 'Sign In'
         WHERE cs.module_id = :mid AND cs.session_date BETWEEN :from AND :to
         GROUP BY cs.session_id
         ORDER BY cs.session_date DESC, cs.window_name"
    );
    $sessStmt->execute(['mid' => $moduleId, 'from' => $dateFrom, 'to' => $dateTo]);
    $detailSessions = $sessStmt->fetchAll();
}

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h4 class="display-font mb-1">Class Attendance Overview</h4>
    <p class="text-muted small mb-0">Monitor attendance across all modules. Click a module row for the detailed session breakdown.</p>
  </div>
</div>

<!-- Range controls -->
<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="module_id" value="<?= $moduleId ?>">
    <div class="col-md-3">
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
      <div class="col-md-4 text-md-end">
        <a href="?module_id=<?= $moduleId ?>&range=<?= e($rangeType) ?>&date=<?= e($rangeDate) ?>&export=csv"
           class="btn btn-sm btn-outline-dark"><i class="bi bi-filetype-csv me-1"></i>Export CSV</a>
        <a href="<?= APP_URL ?>/hod/attendance-pdf.php?module_id=<?= $moduleId ?>&range=<?= e($rangeType) ?>&date=<?= e($rangeDate) ?>"
           target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-file-earmark-pdf me-1"></i>Export PDF</a>
      </div>
    <?php endif; ?>
  </form>
  <p class="text-muted small mb-0 mt-2">
    Showing: <strong><?= date('d M Y', strtotime($dateFrom)) ?></strong>
    <?php if ($dateFrom !== $dateTo): ?> to <strong><?= date('d M Y', strtotime($dateTo)) ?></strong><?php endif; ?>
  </p>
</div>

<!-- All-modules summary table -->
<div class="semas-card p-3 mb-4">
  <h6 class="display-font mb-2">All Modules — Attendance Summary</h6>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Module</th><th>Status</th><th>Sessions</th><th class="text-success">Present</th><th class="text-warning">Late</th><th class="text-danger">Absent</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($summary as $row): ?>
          <tr class="<?= (int) $row['module_id'] === $moduleId ? 'table-active' : '' ?>">
            <td class="fw-semibold"><?= e($row['module_title']) ?></td>
            <td><span class="badge <?= $row['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?>"><?= e($row['status']) ?></span></td>
            <td><?= (int) $row['session_count'] ?></td>
            <td class="text-success fw-semibold"><?= (int) $row['present_count'] ?></td>
            <td class="text-warning fw-semibold"><?= (int) $row['late_count'] ?></td>
            <td class="text-danger fw-semibold"><?= (int) $row['absent_count'] ?></td>
            <td>
              <a href="?module_id=<?= (int) $row['module_id'] ?>&range=<?= e($rangeType) ?>&date=<?= e($rangeDate) ?>"
                 class="btn btn-sm btn-outline-dark">Detail</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Detail panel for selected module -->
<?php if ($moduleId && $detailModule): ?>
  <div class="semas-card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="display-font mb-0">
        <?= e($detailModule['module_title']) ?> — Session Detail
        <span class="badge <?= $detailModule['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?> ms-1"><?= e($detailModule['status']) ?></span>
      </h6>
      <a href="?range=<?= e($rangeType) ?>&date=<?= e($rangeDate) ?>" class="btn btn-sm btn-outline-dark">Clear</a>
    </div>
    <?php if (!$detailSessions): ?>
      <p class="text-muted small mb-0">No sessions recorded in this period for the selected module.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>Date</th><th>Window</th><th>Session</th><th class="text-success">Present</th><th class="text-warning">Late</th><th class="text-danger">Absent</th><th>Student List</th></tr></thead>
          <tbody>
            <?php foreach ($detailSessions as $sess): ?>
              <tr>
                <td><?= e($sess['session_date']) ?></td>
                <td><?= e($sess['window_name']) ?></td>
                <td><span class="badge <?= $sess['session_status'] === 'Open' ? 'badge-urgent' : 'bg-secondary' ?>"><?= e($sess['session_status']) ?></span></td>
                <td class="text-success fw-semibold"><?= (int) $sess['present_count'] ?></td>
                <td class="text-warning fw-semibold"><?= (int) $sess['late_count'] ?></td>
                <td class="text-danger fw-semibold"><?= (int) $sess['absent_count'] ?></td>
                <td>
                  <button class="btn btn-sm btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#roster-<?= (int) $sess['session_id'] ?>">
                    <i class="bi bi-people"></i>
                  </button>
                </td>
              </tr>
              <tr class="collapse" id="roster-<?= (int) $sess['session_id'] ?>">
                <td colspan="7">
                  <?php
                    $rosterStmt = $db->prepare(
                        "SELECT u.full_name, u.reg_number, cal.status, cal.checkin_time, cal.verification_method
                         FROM class_attendance_logs cal
                         JOIN users u ON u.user_id = cal.user_id
                         WHERE cal.session_id = :sid AND cal.attendance_type = 'Sign In'
                         ORDER BY (cal.status = 'Absent'), cal.checkin_time"
                    );
                    $rosterStmt->execute(['sid' => $sess['session_id']]);
                    $roster = $rosterStmt->fetchAll();
                  ?>
                  <div class="p-2">
                    <table class="table table-sm mb-0">
                      <thead><tr><th>Student</th><th>Reg No.</th><th>Status</th><th>Check-in</th><th>Method</th></tr></thead>
                      <tbody>
                        <?php foreach ($roster as $r): ?>
                          <tr>
                            <td><?= e($r['full_name']) ?></td>
                            <td><?= e($r['reg_number'] ?? '—') ?></td>
                            <td><span class="badge <?= $r['status'] === 'Present' ? 'badge-completed' : ($r['status'] === 'Late' ? 'badge-urgent' : 'bg-secondary') ?>"><?= e($r['status']) ?></span></td>
                            <td class="small"><?= $r['checkin_time'] && $r['verification_method'] !== 'Auto' ? e(date('H:i', strtotime($r['checkin_time']))) : '—' ?></td>
                            <td class="small text-muted"><?= e($r['verification_method']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if (!$roster): ?><tr><td colspan="5" class="text-muted small text-center">No records.</td></tr><?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
