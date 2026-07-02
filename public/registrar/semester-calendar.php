<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Registrar']);

$pageTitle = 'Semester Calendar';
$activeNav = 'semester-calendar';
$db        = Database::connection();
$me        = Auth::user();
$today     = date('Y-m-d');

// Current and next academic year options
function academicYearOptions(): array
{
    $y = (int) date('Y');
    $years = [];
    for ($i = -1; $i <= 2; $i++) {
        $years[] = ($y + $i) . '/' . ($y + $i + 1);
    }
    return $years;
}

function ensureYearCodedIntakes(PDO $db): void
{
    try {
        $columns = $db->query(
            "SELECT TABLE_NAME, COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('users','module_intakes','semester_calendars')
               AND COLUMN_NAME = 'intake'"
        )->fetchAll();

        foreach ($columns as $column) {
            if (stripos((string) $column['COLUMN_TYPE'], 'enum(') === false) {
                continue;
            }

            if ($column['TABLE_NAME'] === 'users') {
                $db->exec('ALTER TABLE users MODIFY COLUMN intake VARCHAR(10) NULL');
            } elseif ($column['TABLE_NAME'] === 'module_intakes') {
                $db->exec('ALTER TABLE module_intakes MODIFY COLUMN intake VARCHAR(10) NOT NULL');
            } elseif ($column['TABLE_NAME'] === 'semester_calendars') {
                $db->exec('ALTER TABLE semester_calendars MODIFY COLUMN intake VARCHAR(10) NOT NULL');
            }
        }
    } catch (Throwable $e) {
        error_log('[SemesterCalendar] intake column compatibility check failed: ' . $e->getMessage());
    }
}

function semesterIntakeCode(string $semesterName, string $startDate): ?string
{
    $semPrefixMap = ['SEMESTER I' => 'JAN', 'SEMESTER II' => 'MAY', 'SEMESTER III' => 'SEPT'];
    $intakePrefix = $semPrefixMap[$semesterName] ?? null;
    return ($intakePrefix && $startDate) ? ($intakePrefix . date('y', strtotime($startDate))) : null;
}

ensureYearCodedIntakes($db);

// ── POST handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $calendarId   = (int) ($_POST['calendar_id'] ?? 0);
        $academicYear = trim($_POST['academic_year'] ?? '');
        $semName      = trim($_POST['semester_name'] ?? '');
        $startDate    = trim($_POST['start_date'] ?? '');
        $endDate      = trim($_POST['end_date'] ?? '');
        $notes        = null;

        // Derive intake code from semester + year of start_date (e.g. SEMESTER I + 2026 → JAN26)
        $intake = semesterIntakeCode($semName, $startDate);

        if (!$academicYear || !$intake || !$startDate || !$endDate) {
            flash('error', 'All fields except Notes are required.');
            redirect('/registrar/semester-calendar.php');
        }
        // Validate that start date year belongs to the selected academic year (e.g. 2026/2027 → 2026 or 2027)
        $startYear = (int) date('Y', strtotime($startDate));
        [$ayFirst, $ayLast] = array_map('intval', explode('/', $academicYear));
        if ($startYear < $ayFirst || $startYear > $ayLast) {
            flash('error', "Start date year ($startYear) does not match the selected academic year ($academicYear). The start date must fall within $ayFirst/$ayLast.");
            redirect('/registrar/semester-calendar.php');
        }
        if ($startDate >= $endDate) {
            flash('error', 'End date must be after start date.');
            redirect('/registrar/semester-calendar.php');
        }

        $dupStmt = $db->prepare(
            'SELECT id FROM semester_calendars WHERE academic_year = :yr AND intake = :intake AND id <> :id LIMIT 1'
        );
        $dupStmt->execute(['yr' => $academicYear, 'intake' => $intake, 'id' => $calendarId]);
        $duplicateCalendarId = (int) $dupStmt->fetchColumn();
        if ($duplicateCalendarId > 0) {
            if ($calendarId > 0) {
                flash('error', "A calendar already exists for {$academicYear} / {$intake}. Please edit that calendar instead.");
                redirect('/registrar/semester-calendar.php');
            }
            $calendarId = $duplicateCalendarId;
        }

        if ($calendarId > 0) {
            $existingStmt = $db->prepare('SELECT end_date FROM semester_calendars WHERE id = :id');
            $existingStmt->execute(['id' => $calendarId]);
            $existingEndDate = $existingStmt->fetchColumn();
            if ($existingEndDate && $existingEndDate <= $today) {
                flash('error', 'Completed semester calendars are history records and cannot be edited.');
                redirect('/registrar/semester-calendar.php');
            }

            $db->prepare(
                'UPDATE semester_calendars
                 SET academic_year = :yr, intake = :intake, semester_name = :name,
                     start_date = :start, end_date = :end, notes = :notes, created_by = :uid
                 WHERE id = :id'
            )->execute([
                'yr'     => $academicYear,
                'intake' => $intake,
                'name'   => $semName,
                'start'  => $startDate,
                'end'    => $endDate,
                'notes'  => $notes,
                'uid'    => $me['user_id'],
                'id'     => $calendarId,
            ]);
        } else {
            $db->prepare(
                'INSERT INTO semester_calendars (academic_year, intake, semester_name, start_date, end_date, notes, created_by)
                 VALUES (:yr, :intake, :name, :start, :end, :notes, :uid)
                 ON DUPLICATE KEY UPDATE semester_name=:name2, start_date=:start2, end_date=:end2, notes=:notes2, created_by=:uid2'
            )->execute([
                'yr'     => $academicYear,
                'intake' => $intake,
                'name'   => $semName,
                'start'  => $startDate,
                'end'    => $endDate,
                'notes'  => $notes,
                'uid'    => $me['user_id'],
                'name2'  => $semName,
                'start2' => $startDate,
                'end2'   => $endDate,
                'notes2' => $notes,
                'uid2'   => $me['user_id'],
            ]);
            $calendarId = (int) $db->lastInsertId();
        }

        AuditLog::record(Auth::id(), 'SEMESTER_CALENDAR_SAVE', 'semester_calendars', $calendarId, "year=$academicYear;intake=$intake");

        // Notify every active student when the Registrar publishes or updates a calendar.
        $studentsStmt = $db->query(
            "SELECT u.user_id, u.full_name, u.email FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE r.role_name = 'Student' AND u.status = 'Active'"
        );
        $students = $studentsStmt->fetchAll();

        $calendarData = [
            'academic_year' => $academicYear,
            'intake'        => $intake,
            'semester_name' => $semName,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'notes'         => '',
        ];

        $notifMsg = 'Semester ' . $semName . ' starts on ' . date('d M Y', strtotime($startDate)) . ' and ends on ' . date('d M Y', strtotime($endDate)) . '. Log in to register for your modules.';
        foreach ($students as $student) {
            NotificationCenter::notify((int) $student['user_id'], 'Semester Calendar Published: ' . $semName, $notifMsg, 'System');
            Mailer::enqueueSemesterCalendar($student, $calendarData);
        }
        Mailer::dispatch();

        flash('success', 'Semester calendar saved. ' . count($students) . ' student(s) notified by email.');
        redirect('/registrar/semester-calendar.php');
    }

}

// ── Fetch calendars ────────────────────────────────────────────────────
$calendars = $db->query(
    "SELECT sc.*, u.full_name AS created_by_name
     FROM semester_calendars sc
     LEFT JOIN users u ON u.user_id = sc.created_by
     ORDER BY sc.academic_year DESC,
              CAST(RIGHT(sc.intake, 2) AS UNSIGNED) DESC,
              FIELD(LEFT(sc.intake, CHAR_LENGTH(sc.intake) - 2), 'JAN','MAY','SEPT')"
)->fetchAll();

// Count students per intake for display
$intakeCounts = [];
$icStmt = $db->query(
    "SELECT u.intake, COUNT(*) AS cnt FROM users u
     JOIN roles r ON r.role_id = u.role_id
     WHERE r.role_name = 'Student' AND u.status = 'Active' AND u.intake IS NOT NULL
     GROUP BY u.intake"
);
foreach ($icStmt->fetchAll() as $row) {
    $intakeCounts[$row['intake']] = (int) $row['cnt'];
}

$currentCalendars = [];
$completedCalendars = [];
foreach ($calendars as $calendar) {
    if ($calendar['end_date'] <= $today) {
        $completedCalendars[] = $calendar;
    } else {
        $currentCalendars[] = $calendar;
    }
}

require __DIR__ . '/../partials/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h4 class="display-font mb-1">Semester Calendar</h4>
  </div>
  <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addCalendarModal">
    <i class="bi bi-calendar-plus me-1"></i> Add / Update Calendar
  </button>
</div>

<!-- Intake stats -->
<div class="semas-card p-3 mb-3">
  <div class="d-flex flex-wrap gap-2">
    <?php foreach (availableIntakes() as $ink): ?>
    <div class="d-flex align-items-center gap-2 px-3 py-2 border rounded">
      <i class="bi bi-people-fill text-primary"></i>
      <div>
        <div class="fw-semibold small"><?= $ink ?></div>
        <div class="text-muted" style="font-size:.75rem;"><?= $intakeCounts[$ink] ?? 0 ?> student<?= ($intakeCounts[$ink] ?? 0) === 1 ? '' : 's' ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Calendar table -->
<?php if (!$calendars): ?>
  <div class="semas-card p-4 text-center text-muted small">No semester calendars set yet. Click "Add / Update Calendar" to publish one.</div>
<?php else: ?>
<?php if ($currentCalendars): ?>
<div class="semas-card p-0">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Academic Year</th><th>Intake</th><th>Semester</th><th>Start</th><th>End</th><th>Duration</th><th>Notes</th><th>Set By</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($currentCalendars as $c):
        $weeks = (int) round((strtotime($c['end_date']) - strtotime($c['start_date'])) / 604800);
        $isActive = $c['start_date'] <= $today && $c['end_date'] > $today;
      ?>
        <tr class="<?= $isActive ? 'table-success' : '' ?>">
          <td class="fw-semibold"><?= e($c['academic_year']) ?></td>
          <td><span class="badge bg-primary"><?= e($c['intake']) ?></span></td>
          <td><?= e($c['semester_name']) ?></td>
          <td><?= date('d M Y', strtotime($c['start_date'])) ?></td>
          <td><?= date('d M Y', strtotime($c['end_date'])) ?></td>
          <td><small><?= $weeks ?> wks</small>
            <?php if ($isActive): ?><span class="badge badge-completed ms-1">Active</span><?php endif; ?>
          </td>
          <td><small class="text-muted"><?= $c['notes'] ? e(mb_substr($c['notes'], 0, 50)) . (mb_strlen($c['notes']) > 50 ? '…' : '') : '/' ?></small></td>
          <td><small><?= e($c['created_by_name'] ?? '/') ?></small></td>
          <td>
            <button class="btn btn-sm btn-outline-dark"
              data-bs-toggle="modal" data-bs-target="#editCal-<?= $c['id'] ?>"
              title="Edit"><i class="bi bi-pencil"></i></button>
          </td>
        </tr>

        <!-- Edit Modal -->
        <div class="modal fade" id="editCal-<?= $c['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="POST" id="editCalForm-<?= $c['id'] ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="calendar_id" value="<?= (int) $c['id'] ?>">
                <div class="modal-header">
                  <h6 class="modal-title display-font">Edit / <?= e($c['semester_name']) ?></h6>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <?= calendarFormFields($c) ?>
                </div>
                <div class="modal-footer">
                  <button class="btn btn-semas btn-sm" onclick="disableAndSubmit(this)">
                    <i class="bi bi-send me-1"></i> Save &amp; Notify Students
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php if (!$currentCalendars && $completedCalendars): ?>
  <div class="semas-card p-4 text-center text-muted small mb-3">No current or upcoming semester calendars. Completed calendars are shown in history below.</div>
<?php endif; ?>
<?php if ($completedCalendars): ?>
<div class="d-flex justify-content-between align-items-center mt-4 mb-2">
  <h6 class="display-font mb-0">Completed History</h6>
  <span class="badge bg-secondary"><?= count($completedCalendars) ?> completed</span>
</div>
<div class="semas-card p-0">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Academic Year</th><th>Intake</th><th>Semester</th><th>Start</th><th>End</th><th>Duration</th><th>Notes</th><th>Set By</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($completedCalendars as $c):
        $weeks = (int) round((strtotime($c['end_date']) - strtotime($c['start_date'])) / 604800);
      ?>
        <tr class="text-muted">
          <td class="fw-semibold"><?= e($c['academic_year']) ?></td>
          <td><span class="badge bg-secondary"><?= e($c['intake']) ?></span></td>
          <td><?= e($c['semester_name']) ?></td>
          <td><?= date('d M Y', strtotime($c['start_date'])) ?></td>
          <td><?= date('d M Y', strtotime($c['end_date'])) ?></td>
          <td><small><?= $weeks ?> wks</small></td>
          <td><small class="text-muted"><?= $c['notes'] ? e(mb_substr($c['notes'], 0, 50)) . (mb_strlen($c['notes']) > 50 ? '...' : '') : '-' ?></small></td>
          <td><small><?= e($c['created_by_name'] ?? '-') ?></small></td>
          <td><span class="badge bg-secondary">Completed</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Add Calendar Modal -->
<div class="modal fade" id="addCalendarModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" id="addCalForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-header">
          <h6 class="modal-title display-font">Add / Update Semester Calendar</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= calendarFormFields([]) ?>
          <div class="alert alert-info small mt-3 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            If a calendar for the same <strong>Academic Year + Intake</strong> already exists it will be updated, not duplicated. Students in that intake will be notified immediately.
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-semas-gold btn-sm" onclick="disableAndSubmit(this)">
            <i class="bi bi-send me-1"></i> Save &amp; Notify Students
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
/** Render the shared form fields for add/edit modal. */
function calendarFormFields(array $c): string
{
    $years = academicYearOptions();
    ob_start();
    ?>
    <?php $minDate = date('Y-m-d'); $sems = ['SEMESTER I', 'SEMESTER II', 'SEMESTER III']; ?>
    <div class="row g-2">
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Academic Year <span class="text-danger">*</span></label>
        <select name="academic_year" class="form-select form-select-sm" required>
          <option value="">Select year</option>
          <?php foreach ($years as $yr): ?>
            <option value="<?= e($yr) ?>" <?= ($c['academic_year'] ?? '') === $yr ? 'selected' : '' ?>><?= e($yr) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Semester <span class="text-danger">*</span></label>
        <select name="semester_name" class="form-select form-select-sm" required>
          <option value="">Select semester</option>
          <?php foreach ($sems as $s): ?>
            <option value="<?= $s ?>" <?= ($c['semester_name'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Start Date <span class="text-danger">*</span></label>
        <input type="date" name="start_date" class="form-control form-control-sm" required
          min="<?= $minDate ?>" value="<?= e($c['start_date'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">End Date <span class="text-danger">*</span></label>
        <input type="date" name="end_date" class="form-control form-control-sm" required
          min="<?= $minDate ?>" value="<?= e($c['end_date'] ?? '') ?>">
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}
?>

<script>
function disableAndSubmit(btn) {
    const form = btn.closest('form');
    const ay = form.querySelector('[name="academic_year"]').value;
    const sd = form.querySelector('[name="start_date"]').value;
    if (ay && sd) {
        const parts = ay.split('/');
        const startYear = new Date(sd).getFullYear();
        if (parts.length === 2 && (startYear < parseInt(parts[0]) || startYear > parseInt(parts[1]))) {
            alert('Start date year (' + startYear + ') does not match the selected academic year (' + ay + ').\n\nThe start date must fall within ' + parts[0] + '/' + parts[1] + '.');
            return;
        }
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving & Notifying…';
    form.submit();
}
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
