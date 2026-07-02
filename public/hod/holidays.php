<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD', 'Coordinator']);

$pageTitle = 'Holidays & Umuganda';
$activeNav = 'holidays';
$db = Database::connection();
$me = Auth::user();
$isCoordinator = Auth::role() === 'Coordinator';
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        // Coordinators can only add Umuganda (no Public Holidays in Weekend)
        $type = $isCoordinator ? 'Umuganda' : ($_POST['holiday_type'] === 'Umuganda' ? 'Umuganda' : 'Public Holiday');
        $holidayDate = trim((string) ($_POST['holiday_date'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $dateValue = DateTime::createFromFormat('Y-m-d', $holidayDate);

        if (!$dateValue || $dateValue->format('Y-m-d') !== $holidayDate) {
            flash('error', 'Please choose a valid date.');
            redirect('/hod/holidays.php');
        }
        if ($holidayDate < $today) {
            flash('error', ucfirst($type) . ' date cannot be in the past.');
            redirect('/hod/holidays.php');
        }
        if ($type === 'Umuganda' && $dateValue->format('N') !== '6') {
            flash('error', 'Umuganda can only be set on a Saturday.');
            redirect('/hod/holidays.php');
        }
        if ($title === '') {
            flash('error', 'Title is required.');
            redirect('/hod/holidays.php');
        }

        try {
            $db->prepare(
                'INSERT INTO holidays (holiday_date, title, holiday_type, override_morning_start, override_morning_end, override_afternoon_start, override_afternoon_end, notes, created_by)
                 VALUES (:date, :title, :type, :ms, :me_, :as_, :ae, :notes, :uid)'
            )->execute([
                'date' => $holidayDate, 'title' => $title, 'type' => $type,
                'ms' => $type === 'Umuganda' ? ($_POST['override_morning_start'] ?: '13:30') : null,
                'me_' => $type === 'Umuganda' ? ($_POST['override_morning_end'] ?: '16:30') : null,
                'as_' => $type === 'Umuganda' ? ($_POST['override_afternoon_start'] ?: '17:00') : null,
                'ae' => $type === 'Umuganda' ? ($_POST['override_afternoon_end'] ?: '20:30') : null,
                'notes' => trim($_POST['notes'] ?? '') ?: null, 'uid' => $me['user_id'],
            ]);
            $holidayId = (int) $db->lastInsertId();
            AuditLog::record(Auth::id(), 'HOLIDAY_CREATE', 'holidays', $holidayId, "type=$type");

            if ($type === 'Umuganda') {
                $weekendStudents = $db->query(
                    "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                     WHERE r.role_name = 'Student' AND u.session_type = 'Weekend' AND u.status = 'Active'"
                )->fetchAll();
                $result = Announcement::create([
                    'title' => 'Umuganda Schedule Change / ' . $holidayDate,
                    'category' => 'General Notice', 'priority' => 'High', 'target_audience' => 'Weekend Students',
                    'message' => "Umuganda falls on {$holidayDate}. Weekend classes are rescheduled: Morning session 13:30/16:30, Afternoon session 17:00/20:30.",
                    'status' => 'Published', 'recipients' => $weekendStudents,
                ], $me, 'Head of Department', 'University-wide (Weekend students)', false);
            }
            flash('success', ucfirst($type) . ' added' . ($type === 'Umuganda' ? ' and weekend students notified.' : '.'));
        } catch (PDOException $e) {
            flash('error', $e->getCode() === '23000' ? 'A holiday is already registered for that date.' : 'Could not save.');
        }
    } elseif ($action === 'delete') {
        $holidayId = (int) $_POST['holiday_id'];
        $db->prepare('DELETE FROM holidays WHERE holiday_id = :id')->execute(['id' => $holidayId]);
        AuditLog::record(Auth::id(), 'HOLIDAY_DELETE', 'holidays', $holidayId);
        flash('success', 'Removed.');
    }
    redirect('/hod/holidays.php');
}

$holidays = $isCoordinator
    ? $db->query("SELECT * FROM holidays WHERE holiday_type = 'Umuganda' ORDER BY holiday_date DESC")->fetchAll()
    : $db->query('SELECT * FROM holidays ORDER BY holiday_date DESC')->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div><h4 class="display-font mb-1"><?= $isCoordinator ? 'Umuganda Dates' : 'Holidays &amp; Umuganda' ?></h4></div>
  <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#newHolidayModal"><i class="bi bi-plus-circle me-1"></i> Add</button>
</div>

<div class="semas-card p-3">
  <table class="table table-sm align-middle">
    <thead><tr><th>Date</th><th>Title</th><?= $isCoordinator ? '' : '<th>Type</th>' ?><th>Notes</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($holidays as $h): ?>
        <tr>
          <td><?= e($h['holiday_date']) ?></td>
          <td><?= e($h['title']) ?></td>
          <?php if (!$isCoordinator): ?>
            <td><span class="badge <?= $h['holiday_type'] === 'Umuganda' ? 'badge-urgent' : 'bg-secondary' ?>"><?= e($h['holiday_type']) ?></span></td>
          <?php endif; ?>
          <td class="small text-muted"><?= e($h['notes'] ?? '') ?></td>
          <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="holiday_id" value="<?= (int) $h['holiday_id'] ?>">
            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this date?');">Remove</button></form></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$holidays): ?><tr><td colspan="<?= $isCoordinator ? 4 : 5 ?>" class="text-muted small text-center py-3">No <?= $isCoordinator ? 'Umuganda dates' : 'holidays' ?> registered yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="newHolidayModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="holidayForm" onsubmit="return validateHolidayForm(event)">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="modal-header"><h6 class="modal-title display-font"><?= $isCoordinator ? 'Add Umuganda Date' : 'Add Holiday / Umuganda' ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small">Date</label>
            <input type="date" name="holiday_date" id="holidayDate" class="form-control form-control-sm" min="<?= e($today) ?>" required>
            <div id="dateFeedback" class="text-danger small mt-1" style="display:none;">Umuganda can only be set on a Saturday.</div>
          </div>
          <div class="mb-2"><label class="form-label small">Title</label><input name="title" class="form-control form-control-sm" required></div>
          <?php if ($isCoordinator): ?>
            <input type="hidden" name="holiday_type" value="Umuganda">
          <?php else: ?>
          <div class="mb-2">
            <label class="form-label small">Type</label>
            <select name="holiday_type" id="holidayType" class="form-select form-select-sm" onchange="toggleUmuganda()">
              <option value="Public Holiday">Public Holiday (disables attendance for the day)</option>
              <option value="Umuganda">Umuganda (reschedules weekend sessions)</option>
            </select>
          </div>
          <?php endif; ?>
          <div id="umugandaFields" style="<?= $isCoordinator ? '' : 'display:none;' ?>">
            <div class="row g-2">
              <div class="col-6"><label class="form-label small">Morning Start</label><input type="time" name="override_morning_start" class="form-control form-control-sm" value="13:30"></div>
              <div class="col-6"><label class="form-label small">Morning End</label><input type="time" name="override_morning_end" class="form-control form-control-sm" value="16:30"></div>
              <div class="col-6"><label class="form-label small">Afternoon Start</label><input type="time" name="override_afternoon_start" class="form-control form-control-sm" value="17:00"></div>
              <div class="col-6"><label class="form-label small">Afternoon End</label><input type="time" name="override_afternoon_end" class="form-control form-control-sm" value="20:30"></div>
            </div>
          </div>
          <div class="mb-2 mt-2"><label class="form-label small">Notes (optional)</label><input name="notes" class="form-control form-control-sm"></div>
        </div>
        <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Save</button></div>
      </form>
    </div>
  </div>
</div>
<script>
var isCoordinatorView = <?= $isCoordinator ? 'true' : 'false' ?>;

function toggleUmuganda() {
  document.getElementById('umugandaFields').style.display = document.getElementById('holidayType').value === 'Umuganda' ? '' : 'none';
  checkHolidayDate();
}

function isUmugandaSelected() {
  var typeField = document.getElementById('holidayType');
  return isCoordinatorView || (typeField && typeField.value === 'Umuganda');
}

function isSaturday(dateStr) {
  var parts = dateStr.split('-').map(Number);
  var d = new Date(parts[0], parts[1] - 1, parts[2]);
  return d.getDay() === 6;
}

function checkHolidayDate() {
  var dateInput = document.getElementById('holidayDate');
  var feedback = document.getElementById('dateFeedback');
  var invalid = isUmugandaSelected() && dateInput.value && !isSaturday(dateInput.value);
  feedback.style.display = invalid ? '' : 'none';
  dateInput.setCustomValidity(invalid ? 'Umuganda can only be set on a Saturday.' : '');
  return !invalid;
}

function validateHolidayForm(event) {
  if (!checkHolidayDate()) {
    event.preventDefault();
    return false;
  }
  return true;
}

document.getElementById('holidayDate').addEventListener('change', checkHolidayDate);
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
