<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD', 'Coordinator']);
Module::autoCompleteExpired();

$isCoordinator = Auth::role() === 'Coordinator';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$pageTitle = 'CAT / Exam Submissions';
$activeNav = 'cat-exam-submissions';
$db = Database::connection();
$me = Auth::user();

$scheduleId = (int) ($_GET['schedule_id'] ?? 0);
$export     = $_GET['export'] ?? '';

// ── HOD's modules only ────────────────────────────────────────────────────
$hodDepts = $db->prepare('SELECT department_id FROM departments WHERE hod_user_id = :uid');
$hodDepts->execute(['uid' => $me['user_id']]);
$deptIds = $hodDepts->fetchAll(PDO::FETCH_COLUMN);

// Scheduled but not yet submitted
$pendingWhere = $isCoordinator ? "AND m.session_type = 'Weekend'" : '';
$pendingStmt = $db->query(
    "SELECT cs.schedule_id, cs.exam_type, cs.scheduled_date, cs.start_time, cs.end_time, cs.room,
            m.module_title, m.module_id,
            u.full_name AS invigilator_name,
            d.department_name
     FROM cat_exam_schedules cs
     JOIN modules m ON m.module_id = cs.module_id
     JOIN lecturers l ON l.lecturer_id = cs.invigilator_id
     JOIN users u ON u.user_id = l.user_id
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN cat_exam_submissions sub ON sub.schedule_id = cs.schedule_id
     WHERE sub.submission_id IS NULL {$pendingWhere}
     ORDER BY cs.scheduled_date ASC"
);
$pendingSchedules = $pendingStmt->fetchAll();

// Submitted (history)
$historyWhere = $isCoordinator ? "AND m.session_type = 'Weekend'" : '';
$historyStmt = $db->query(
    "SELECT sub.*, cs.exam_type, cs.scheduled_date, cs.start_time, cs.end_time, cs.room,
            m.module_title, m.module_id,
            u.full_name AS invigilator_name,
            d.department_name
     FROM cat_exam_submissions sub
     JOIN cat_exam_schedules cs ON cs.schedule_id = sub.schedule_id
     JOIN modules m ON m.module_id = cs.module_id
     JOIN lecturers l ON l.lecturer_id = cs.invigilator_id
     JOIN users u ON u.user_id = l.user_id
     LEFT JOIN departments d ON d.department_id = m.department_id
     WHERE 1=1 {$historyWhere}
     ORDER BY sub.submitted_at DESC"
);
$historySubmissions = $historyStmt->fetchAll();

// ── Roster for selected schedule ──────────────────────────────────────────
$rosterData  = [];
$schedDetail = null;
if ($scheduleId) {
    $schedDetail = $db->prepare(
        "SELECT cs.*, m.module_title, m.module_id, m.session_type, d.department_name,
                u.full_name AS invigilator_name, ul.full_name AS lecturer_name,
                sub.submitted_at, sub.notes AS submission_notes
         FROM cat_exam_schedules cs
         JOIN modules m ON m.module_id = cs.module_id
         LEFT JOIN departments d ON d.department_id = m.department_id
         LEFT JOIN lecturers l ON l.lecturer_id = cs.invigilator_id
         LEFT JOIN users u ON u.user_id = l.user_id
         LEFT JOIN lecturers ll ON ll.lecturer_id = m.lecturer_id
         LEFT JOIN users ul ON ul.user_id = ll.user_id
         LEFT JOIN cat_exam_submissions sub ON sub.schedule_id = cs.schedule_id
         WHERE cs.schedule_id = :id"
    );
    $schedDetail->execute(['id' => $scheduleId]);
    $schedDetail = $schedDetail->fetch();

    $rosterStmt = $db->prepare(
        "SELECT u.full_name, u.reg_number, u.photo_path,
                sin.recorded_at  AS signin_time,
                sout.recorded_at AS signout_time,
                sout.status      AS signout_status,
                sout.missed_reason, sout.missed_notes,
                el.final_decision AS eligibility
         FROM module_enrollments e
         JOIN users u ON u.user_id = e.user_id
         LEFT JOIN cat_exam_attendance_logs sin  ON sin.schedule_id  = :sid  AND sin.user_id  = e.user_id AND sin.attendance_type  = 'Sign In'
         LEFT JOIN cat_exam_attendance_logs sout ON sout.schedule_id = :sid2 AND sout.user_id = e.user_id AND sout.attendance_type = 'Sign Out'
         LEFT JOIN cat_exam_eligibility el ON el.module_id = e.module_id AND el.user_id = e.user_id AND el.exam_type = :etype
         WHERE e.module_id = :mid
         ORDER BY u.full_name"
    );
    $rosterStmt->execute([
        'sid'   => $scheduleId,
        'sid2'  => $scheduleId,
        'mid'   => $schedDetail['module_id'] ?? 0,
        'etype' => $schedDetail['exam_type'] ?? 'CAT',
    ]);
    $rosterData = $rosterStmt->fetchAll();

    // ── CSV export ──────────────────────────────────────────────────────
    if ($export === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . strtolower($schedDetail['exam_type']) . '_attendance_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['NO', 'Student Name', 'Reg Number', 'Sign In Time', 'Sign Out Time', 'Status']);
        $i = 1;
        foreach ($rosterData as $r) {
            $status = $r['signin_time'] ? ($r['signout_time'] ? ($r['signout_status'] ?? 'Present') : 'No Sign-Out') : 'Absent';
            fputcsv($out, [
                $i++,
                $r['full_name'],
                $r['reg_number'] ?? '',
                $r['signin_time']  ? date('d/m/Y H:i', strtotime($r['signin_time']))  : 'Absent',
                $r['signout_time'] ? date('d/m/Y H:i', strtotime($r['signout_time'])) : 'No sign-out',
                $status,
            ]);
        }
        fclose($out);
        exit;
    }

    // ── Excel export ─────────────────────────────────────────────────────
    if ($export === 'excel') {
        $uniName  = Settings::get('university_name', 'University of Kigali');
        $examDate = $schedDetail ? date('d F Y', strtotime($schedDetail['scheduled_date'])) : '';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(($schedDetail['exam_type'] ?? 'CAT') . ' Attendance');

        // Title rows
        $sheet->setCellValue('A1', $uniName);
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $subTitle = ($schedDetail['exam_type'] ?? '') . ' Attendance - Module: '
            . ($schedDetail['module_title'] ?? '') . ' | Room: ' . ($schedDetail['room'] ?? '')
            . ' | Date: ' . $examDate . ' | Session: ' . ($schedDetail['session_type'] ?? '');
        $sheet->setCellValue('A2', $subTitle);
        $sheet->mergeCells('A2:F2');
        $sheet->getStyle('A2')->getFont()->setItalic(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A3', 'Invigilator: ' . ($schedDetail['invigilator_name'] ?? ''));
        $sheet->mergeCells('A3:F3');
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Column headers
        $sheet->fromArray(['NO', 'Student Name', 'Reg Number', 'Sign In', 'Sign Out', 'Status'], null, 'A5');
        $hStyle = $sheet->getStyle('A5:F5');
        $hStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E2A52');
        $hStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Data rows
        $rowNum = 6;
        $i = 1;
        foreach ($rosterData as $r) {
            $sin    = $r['signin_time']  ? date('h:i A', strtotime($r['signin_time']))  : 'Absent';
            $sout   = $r['signout_time'] ? date('h:i A', strtotime($r['signout_time'])) : 'No sign-out';
            $status = $r['signin_time'] ? ($r['signout_time'] ? ($r['signout_status'] ?? 'Present') : 'No Sign-Out') : 'Absent';
            $sheet->fromArray([$i++, $r['full_name'], $r['reg_number'] ?? '', $sin, $sout, $status], null, 'A' . $rowNum);
            if (!$r['signin_time']) {
                $sheet->getStyle('A' . $rowNum . ':F' . $rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEE2E2');
            } elseif (!$r['signout_time']) {
                $sheet->getStyle('A' . $rowNum . ':F' . $rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF9C3');
            }
            $rowNum++;
        }

        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = strtolower($schedDetail['exam_type'] ?? 'cat') . '_attendance_' . date('Ymd') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        AuditLog::record(Auth::id(), 'EXPORT_CAT_EXAM_EXCEL', 'cat_exam_schedules', $scheduleId);
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    // ── PDF export ──────────────────────────────────────────────────────
    if ($export === 'pdf') {
        require_once __DIR__ . '/../../includes/bootstrap.php';
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
        <title><?= e($schedDetail['exam_type']) ?> Attendance / <?= e($schedDetail['module_title']) ?></title>
        <style>
          body { font-family: Arial, sans-serif; font-size: 12px; color: #1B1F2A; margin: 20px; }
          h1 { font-size: 16px; text-align: center; }
          .sub { text-align: center; color: #555; margin-bottom: 12px; }
          table { width: 100%; border-collapse: collapse; margin-top: 12px; }
          th, td { border: 1px solid #ccc; padding: 5px 8px; }
          th { background: #1E2A52; color: #fff; }
          .present { color: #2F9E68; } .absent { color: #DC2626; } .late { color: #D97706; }
          @media print { .no-print { display: none; } }
        </style></head><body>
        <h1><?= e(Settings::get('university_name', 'University of Kigali')) ?></h1>
        <p style="text-align:center;font-weight:bold;margin-bottom:10px;"><?= e($schedDetail['exam_type']) ?> ATTENDANCE LIST</p>
        <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:14px;">
          <tr>
            <td style="width:60%;vertical-align:top;">
              <strong>Module:</strong> <?= e($schedDetail['module_title']) ?><br>
              <strong>Date:</strong> <?= e(date('d F Y', strtotime($schedDetail['scheduled_date']))) ?><br>
              <strong>Invigilator:</strong> <?= e($schedDetail['invigilator_name']) ?>
            </td>
            <td style="width:40%;vertical-align:top;text-align:right;">
              <strong>Room:</strong> <?= e($schedDetail['room']) ?><br>
              <strong>Session:</strong> <?= e($schedDetail['session_type'] ?? '—') ?>
            </td>
          </tr>
        </table>
        <table>
          <thead><tr><th>NO</th><th>Student Name</th><th>Reg No.</th><th>Sign In</th><th>Sign Out</th><th>Status</th></tr></thead>
          <tbody>
          <?php $i = 1; foreach ($rosterData as $r):
            $sin   = $r['signin_time']  ? date('h:i A', strtotime($r['signin_time']))  : '—';
            $sout  = $r['signout_time'] ? date('h:i A', strtotime($r['signout_time'])) : '—';
            $status = $r['signin_time'] ? ($r['signout_time'] ? ($r['signout_status'] ?? 'Present') : 'No Sign-Out') : 'Absent';
          ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= e($r['full_name']) ?></td>
              <td><?= e($r['reg_number'] ?? '—') ?></td>
              <td><?= e($sin) ?></td>
              <td><?= e($sout) ?></td>
              <td class="<?= $status === 'Present' ? 'present' : ($status === 'Absent' ? 'absent' : 'late') ?>"><?= e($status) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="no-print" style="margin-top:16px;"><button onclick="window.print()">Print / Save PDF</button></div>
        </body></html>
        <?php
        exit;
    }
}

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">CAT / Exam Submissions</h4>

<div class="row g-3">
  <div class="col-md-4">
    <div class="semas-card p-3">
      <h6 class="display-font mb-2">Scheduled</h6>
      <?php if (!$pendingSchedules): ?>
        <p class="text-muted small mb-0">No pending CAT / Exam schedules.</p>
      <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($pendingSchedules as $ps): ?>
            <a href="?schedule_id=<?= (int) $ps['schedule_id'] ?>"
               class="list-group-item list-group-item-action py-2 px-0 small <?= $scheduleId === (int) $ps['schedule_id'] ? 'active' : '' ?>">
              <div class="fw-semibold"><?= e($ps['module_title']) ?></div>
              <div class="text-muted" style="font-size:.75rem;">
                <?= e($ps['exam_type']) ?> · <?= e(date('d M Y', strtotime($ps['scheduled_date']))) ?> · <?= e($ps['department_name'] ?? '') ?>
              </div>
              <div style="font-size:.73rem;" class="<?= $scheduleId === (int) $ps['schedule_id'] ? '' : 'text-muted' ?>">
                Invigilator: <?= e($ps['invigilator_name']) ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-md-8">
    <?php if ($schedDetail && $rosterData !== null): ?>
      <div class="semas-card p-3 mb-2">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
          <div style="flex:1;">
            <!-- Left: Module / Date / Invigilator — Right: Room / Session -->
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-1">
              <div>
                <div class="small"><strong>Module:</strong> <?= e($schedDetail['module_title']) ?> <span class="badge bg-secondary ms-1"><?= e($schedDetail['exam_type']) ?></span></div>
                <div class="small"><strong>Date:</strong> <?= e(date('d F Y', strtotime($schedDetail['scheduled_date']))) ?> &middot; <?= e(date('h:i A', strtotime($schedDetail['start_time']))) ?>–<?= e(date('h:i A', strtotime($schedDetail['end_time']))) ?></div>
                <div class="small"><strong>Invigilator:</strong> <?= e($schedDetail['invigilator_name']) ?></div>
                <?php if ($schedDetail['submitted_at']): ?>
                  <div class="small text-muted">Submitted: <?= e(date('d M Y H:i', strtotime($schedDetail['submitted_at']))) ?></div>
                <?php endif; ?>
              </div>
              <div class="text-end text-md-end">
                <div class="small"><strong>Room:</strong> <?= e($schedDetail['room']) ?></div>
                <div class="small"><strong>Session:</strong> <?= e($schedDetail['session_type'] ?? '—') ?></div>
              </div>
            </div>
            <?php if ($schedDetail['submission_notes']): ?>
              <p class="text-muted small mb-0 mt-1"><em>Notes: <?= e($schedDetail['submission_notes']) ?></em></p>
            <?php endif; ?>
          </div>
          <div class="d-flex gap-2">
            <a href="?schedule_id=<?= (int) $scheduleId ?>&export=csv" class="btn btn-sm btn-outline-dark">
              <i class="bi bi-filetype-csv me-1"></i>CSV
            </a>
            <a href="?schedule_id=<?= (int) $scheduleId ?>&export=excel" class="btn btn-sm btn-outline-success">
              <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </a>
            <a href="?schedule_id=<?= (int) $scheduleId ?>&export=pdf" target="_blank" class="btn btn-sm btn-outline-dark">
              <i class="bi bi-file-earmark-pdf me-1"></i>PDF
            </a>
          </div>
        </div>

        <?php
          $missedExam   = array_filter($rosterData, function ($r) { return !$r['signin_time']; });
          $missedSignout= array_filter($rosterData, function ($r) { return $r['signin_time'] && !$r['signout_time']; });
          $present      = array_filter($rosterData, function ($r) { return $r['signin_time'] && $r['signout_time'] && ($r['signout_status'] === 'Present'); });
        ?>
        <div class="row g-2 mb-2 text-center" style="font-size:.8rem;">
          <div class="col-4"><div class="border rounded py-1"><span class="badge badge-completed"><?= count($present) ?></span><br>Present</div></div>
          <div class="col-4"><div class="border rounded py-1"><span class="badge badge-urgent"><?= count($missedSignout) ?></span><br>Missed Sign-Out</div></div>
          <div class="col-4"><div class="border rounded py-1"><span class="badge bg-secondary"><?= count($missedExam) ?></span><br>Absent / Missed Exam</div></div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle" style="font-size:.83rem;">
            <thead>
              <tr>
                <th>NO</th><th>Student</th><th>Reg No.</th>
                <th>Sign In</th><th>Sign Out</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php $i = 1; foreach ($rosterData as $r):
                $sin    = $r['signin_time']  ? date('h:i A', strtotime($r['signin_time']))  : null;
                $sout   = $r['signout_time'] ? date('h:i A', strtotime($r['signout_time'])) : null;
                $status = $sin ? ($sout ? ($r['signout_status'] ?? 'Present') : 'No Sign-Out') : 'Absent';
                $rowClass = !$sin ? 'table-danger' : (!$sout ? 'table-warning' : '');
              ?>
                <tr class="<?= $rowClass ?>">
                  <td><?= $i++ ?></td>
                  <td class="fw-semibold"><?= e($r['full_name']) ?></td>
                  <td class="text-muted small"><?= e($r['reg_number'] ?? '—') ?></td>
                  <td><?= $sin ? '<span class="badge badge-completed">' . e($sin) . '</span>' : '<span class="badge bg-secondary">—</span>' ?></td>
                  <td><?= $sout ? '<span class="badge bg-primary">' . e($sout) . '</span>' : '<span class="badge bg-secondary">—</span>' ?></td>
                  <td>
                    <?php
                      if ($status === 'Present') {
                          $cls = 'badge-completed';
                      } elseif ($status === 'Absent') {
                          $cls = 'bg-secondary';
                      } else {
                          $cls = 'badge-urgent';
                      }
                    ?>
                    <span class="badge <?= $cls ?>"><?= e($status) ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php elseif (!$scheduleId): ?>
      <div class="semas-card p-4 text-center text-muted small">Select a scheduled item on the left to view the attendance roster.</div>
    <?php endif; ?>
  </div>
</div>

<!-- History: submitted submissions, split by type -->
<?php
$catHistory  = array_values(array_filter($historySubmissions, function ($h) { return $h['exam_type'] === 'CAT'; }));
$examHistory = array_values(array_filter($historySubmissions, function ($h) { return $h['exam_type'] === 'Exam'; }));

function renderHistoryTable(array $rows, string $label): void { ?>
<?php if ($rows): ?>
<div class="semas-card mt-4">
  <button class="btn w-100 text-start p-3 d-flex justify-content-between align-items-center hist-toggle"
          type="button" data-bs-toggle="collapse" data-bs-target="#hist-<?= $label ?>" aria-expanded="false">
    <span class="display-font" style="font-size:1rem;">
      <?= htmlspecialchars($label) ?> History <span class="badge bg-secondary ms-2"><?= count($rows) ?></span>
    </span>
    <i class="bi bi-chevron-down"></i>
  </button>
  <div class="collapse" id="hist-<?= $label ?>">
    <div class="p-3 pt-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.84rem;">
          <thead class="table-light">
            <tr><th>Module</th><th>Date</th><th>Room</th><th>Invigilator</th><th>Submitted</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $hs): ?>
              <tr>
                <td class="fw-semibold"><?= htmlspecialchars($hs['module_title']) ?></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($hs['scheduled_date']))) ?></td>
                <td><?= htmlspecialchars($hs['room']) ?></td>
                <td><?= htmlspecialchars($hs['invigilator_name']) ?></td>
                <td class="text-muted small"><?= htmlspecialchars(date('d M Y H:i', strtotime($hs['submitted_at']))) ?></td>
                <td>
                  <a href="?schedule_id=<?= (int) $hs['schedule_id'] ?>" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-eye me-1"></i>View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php }

renderHistoryTable($catHistory,  'CAT');
renderHistoryTable($examHistory, 'Exam');
?>
<script>
document.querySelectorAll('.hist-toggle').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var icon = this.querySelector('.bi-chevron-down, .bi-chevron-up');
    if (icon) icon.className = icon.className.includes('chevron-down') ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
  });
});
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>



