<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD']);

$pageTitle = 'Academic Announcements';
$activeNav = 'announcements';
$db = Database::connection();
$me = Auth::user();
$tab = $_GET['tab'] ?? 'announcements';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'send_announcement';

    // ── Announcement send ──────────────────────────────────────────────────
    if ($action === 'send_announcement') {
        $audience = $_POST['audience'] ?? 'students';
        $deptId   = (int) ($_POST['department_id'] ?? 0) ?: null;
        $deptName = null;
        if ($deptId) {
            $dn = $db->prepare('SELECT department_name FROM departments WHERE department_id = :id');
            $dn->execute(['id' => $deptId]);
            $deptName = $dn->fetchColumn() ?: null;
        }
        $scopeLabel = $deptName ? 'Department of ' . $deptName : 'All Departments (university-wide)';

        if ($audience === 'lecturers') {
            $sql = "SELECT u.* FROM users u JOIN lecturers l ON l.user_id = u.user_id WHERE u.status = 'Active'";
            $params = [];
            if ($deptId) { $sql .= ' AND l.department_id = :dept'; $params['dept'] = $deptId; }
            $lecStmt = $db->prepare($sql);
            $lecStmt->execute($params);
            $recipients     = $lecStmt->fetchAll();
            $targetAudience = 'Department Lecturers';
            $audienceLabel  = ($deptName ?? 'All departments') . ' — Lecturers';
        } else {
            $scope      = $_POST['scope'] ?? 'all';
            $filters    = $deptId ? ['department_id' => $deptId] : [];
            $audienceLabel = ($deptName ?? 'All Departments') . ' — Students';
            if ($scope === 'session' && !empty($_POST['session_type'])) {
                $filters['session_type'] = $_POST['session_type'];
                $audienceLabel .= ' (' . $_POST['session_type'] . ' session)';
            } elseif ($scope === 'year' && !empty($_POST['year_of_study'])) {
                $filters['year_of_study'] = (int) $_POST['year_of_study'];
                $audienceLabel .= ' (Year ' . (int) $_POST['year_of_study'] . ')';
            }
            $recipients     = AudienceResolver::resolveStudentsScoped($filters);
            $targetAudience = $deptId ? 'Specific Department' : 'All Students';
        }

        $result = Announcement::create([
            'title'           => $_POST['title'],
            'category'        => $_POST['category'],
            'priority'        => $_POST['priority'],
            'target_audience' => $targetAudience,
            'department_id'   => $deptId,
            'message'         => $_POST['message'],
            'status'          => ($_POST['save_as'] ?? '') === 'draft' ? 'Draft' : 'Published',
            'recipients'      => $recipients,
        ], $me, 'Head of Department', $scopeLabel, isset($_POST['send_sms']));

        if (($_POST['save_as'] ?? '') === 'draft') {
            flash('success', 'Announcement saved as a draft.');
        } else {
            flash('success', "Announcement sent to {$result['recipients']} recipient(s) ($audienceLabel).");
        }
        redirect('/hod/announcements.php?tab=announcements');
    }

    // ── Holiday / Umuganda create ──────────────────────────────────────────
    if ($action === 'holiday_create') {
        $type = $_POST['holiday_type'] === 'Umuganda' ? 'Umuganda' : 'Public Holiday';
        try {
            $db->prepare(
                'INSERT INTO holidays (holiday_date, title, holiday_type,
                    override_morning_start, override_morning_end, override_afternoon_start, override_afternoon_end,
                    notes, created_by)
                 VALUES (:date, :title, :type, :ms, :me_, :as_, :ae, :notes, :uid)'
            )->execute([
                'date'  => $_POST['holiday_date'],
                'title' => trim($_POST['title']),
                'type'  => $type,
                'ms'    => $type === 'Umuganda' ? ($_POST['override_morning_start']   ?: '13:30') : null,
                'me_'   => $type === 'Umuganda' ? ($_POST['override_morning_end']     ?: '16:30') : null,
                'as_'   => $type === 'Umuganda' ? ($_POST['override_afternoon_start'] ?: '17:00') : null,
                'ae'    => $type === 'Umuganda' ? ($_POST['override_afternoon_end']   ?: '20:30') : null,
                'notes' => trim($_POST['notes'] ?? '') ?: null,
                'uid'   => $me['user_id'],
            ]);
            $holidayId = (int) $db->lastInsertId();
            AuditLog::record(Auth::id(), 'HOLIDAY_CREATE', 'holidays', $holidayId, "type=$type");

            // Build announcement recipients and send emails based on type
            if ($type === 'Umuganda') {
                $affected = $db->query(
                    "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                     WHERE r.role_name = 'Student' AND u.session_type = 'Weekend' AND u.status = 'Active'"
                )->fetchAll();
                $annTitle = 'Umuganda Schedule Change — ' . $_POST['holiday_date'];
                $annMsg   = "Umuganda falls on {$_POST['holiday_date']}. Weekend classes are rescheduled: Morning session 13:30–16:30, Afternoon session 17:00–20:30.";
                Announcement::create([
                    'title'           => $annTitle,
                    'category'        => 'General Notice',
                    'priority'        => 'High',
                    'target_audience' => 'Weekend Students',
                    'message'         => $annMsg,
                    'status'          => 'Published',
                    'recipients'      => $affected,
                ], $me, 'Head of Department', 'University-wide (Weekend students)', false);
                foreach ($affected as $u) {
                    Mailer::sendAnnouncementNotification($u, [
                        'title'    => $annTitle,
                        'message'  => $annMsg,
                        'category' => 'General Notice',
                        'priority' => 'High',
                    ]);
                }
                flash('success', 'Umuganda added. ' . count($affected) . ' weekend student(s) notified via email and announcement board.');
            } else {
                // Public Holiday → notify ALL active students
                $allStudents = $db->query(
                    "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                     WHERE r.role_name = 'Student' AND u.status = 'Active'"
                )->fetchAll();
                $annTitle = 'Public Holiday — ' . trim($_POST['title']) . ' (' . $_POST['holiday_date'] . ')';
                $annMsg   = trim($_POST['title']) . " on {$_POST['holiday_date']} is a Public Holiday. All classes and attendance scanning are suspended for the day." . (trim($_POST['notes'] ?? '') ? ' Note: ' . trim($_POST['notes']) : '');
                Announcement::create([
                    'title'           => $annTitle,
                    'category'        => 'General Notice',
                    'priority'        => 'High',
                    'target_audience' => 'All Students',
                    'message'         => $annMsg,
                    'status'          => 'Published',
                    'recipients'      => $allStudents,
                ], $me, 'Head of Department', 'University-wide (All students)', false);
                foreach ($allStudents as $u) {
                    Mailer::sendAnnouncementNotification($u, [
                        'title'    => $annTitle,
                        'message'  => $annMsg,
                        'category' => 'General Notice',
                        'priority' => 'High',
                    ]);
                }
                flash('success', 'Public holiday added. ' . count($allStudents) . ' student(s) notified via email and announcement board.');
            }
        } catch (PDOException $e) {
            flash('error', $e->getCode() === '23000' ? 'A holiday is already registered for that date.' : 'Could not save.');
        }
        redirect('/hod/announcements.php?tab=holidays');
    }

    // ── Holiday delete ─────────────────────────────────────────────────────
    if ($action === 'holiday_delete') {
        $holidayId = (int) $_POST['holiday_id'];
        $db->prepare('DELETE FROM holidays WHERE holiday_id = :id')->execute(['id' => $holidayId]);
        AuditLog::record(Auth::id(), 'HOLIDAY_DELETE', 'holidays', $holidayId);
        flash('success', 'Removed.');
        redirect('/hod/announcements.php?tab=holidays');
    }
}

$departments      = $db->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll();
$myAnnouncements  = $db->prepare("SELECT * FROM announcements WHERE posted_by = :uid ORDER BY posted_at DESC LIMIT 20");
$myAnnouncements->execute(['uid' => $me['user_id']]);
$myAnnouncements  = $myAnnouncements->fetchAll();
$holidays         = $db->query('SELECT * FROM holidays ORDER BY holiday_date DESC')->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Academic Announcements</h4>
<p class="text-muted small mb-3">Announce to students or lecturers, and manage public holidays &amp; Umuganda dates.</p>

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'announcements' ? 'active' : '' ?>" href="?tab=announcements">Announcements</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'holidays' ? 'active' : '' ?>" href="?tab=holidays">Holidays &amp; Umuganda</a></li>
</ul>

<?php if ($tab === 'announcements'): ?>

  <div class="semas-card p-3 mb-4">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_announcement">
      <div class="row g-3">
        <div class="col-md-7"><label class="form-label small">Title</label><input name="title" class="form-control" required></div>
        <div class="col-md-2">
          <label class="form-label small">Category</label>
          <select name="category" class="form-select">
            <?php foreach (NotificationGenerator::CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small">Priority</label>
          <select name="priority" class="form-select">
            <?php foreach (NotificationGenerator::PRIORITIES as $p): ?><option><?= e($p) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><hr class="my-1"><label class="form-label small fw-semibold">Send To</label></div>
        <div class="col-md-3">
          <select name="audience" id="audienceSelect" class="form-select" onchange="toggleAudience()">
            <option value="students">Students</option>
            <option value="lecturers">Lecturers</option>
          </select>
        </div>
        <div class="col-md-3">
          <select name="department_id" class="form-select">
            <option value="">All Departments (university-wide)</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3" id="scopeWrap">
          <select name="scope" id="scopeSelect" class="form-select" onchange="toggleScope()">
            <option value="all">All students in scope</option>
            <option value="session">By session/class</option>
            <option value="year">By academic year/level</option>
          </select>
        </div>
        <div class="col-md-3" id="sessionField" style="display:none;">
          <select name="session_type" class="form-select">
            <?php foreach (['Day', 'Evening', 'Weekend'] as $s): ?><option><?= e($s) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3" id="yearField" style="display:none;">
          <select name="year_of_study" class="form-select">
            <?php for ($y = 1; $y <= 6; $y++): ?><option value="<?= $y ?>">Year <?= $y ?></option><?php endfor; ?>
          </select>
        </div>
        <div class="col-12"><label class="form-label small">Message</label><textarea name="message" class="form-control" rows="3" required></textarea></div>
        <div class="col-md-6">
          <div class="form-check">
            <input type="checkbox" name="send_sms" id="send_sms" class="form-check-input" value="1">
            <label class="form-check-label small" for="send_sms">Also send via SMS</label>
          </div>
        </div>
      </div>
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-semas" name="save_as" value="publish"><i class="bi bi-send me-1"></i> Publish &amp; Notify</button>
        <button class="btn btn-outline-dark" name="save_as" value="draft">Save as Draft</button>
      </div>
    </form>
  </div>

  <div class="semas-card p-3 mb-3"><h6 class="display-font mb-0">Your Announcements</h6></div>
  <?php if (!$myAnnouncements): ?>
    <div class="semas-card p-4 text-center text-muted small">You haven't posted any announcements yet.</div>
  <?php else: foreach ($myAnnouncements as $a): ?>
    <?php if ($a['status'] === 'Draft'): ?>
      <div class="semas-card p-3 mb-3">
        <span class="badge bg-secondary mb-2">Draft</span>
        <h6 class="display-font"><?= e($a['title']) ?></h6>
        <p class="text-muted small mb-0"><?= e(mb_substr($a['message'], 0, 140)) ?>&hellip;</p>
      </div>
    <?php else: include __DIR__ . '/../partials/announcement_card.php'; endif; ?>
  <?php endforeach; endif; ?>

<?php else: /* tab = holidays */ ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <p class="text-muted small mb-0">Public holidays disable attendance for the day and email <strong>all students</strong>. Umuganda reschedules weekend classes and emails <strong>weekend students</strong>.</p>
    <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#newHolidayModal"><i class="bi bi-plus-circle me-1"></i> Add</button>
  </div>

  <div class="semas-card p-3">
    <table class="table table-sm align-middle">
      <thead><tr><th>Date</th><th>Title</th><th>Type</th><th>Notes</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($holidays as $h): ?>
          <tr>
            <td><?= e($h['holiday_date']) ?></td>
            <td><?= e($h['title']) ?></td>
            <td><span class="badge <?= $h['holiday_type'] === 'Umuganda' ? 'badge-urgent' : 'bg-secondary' ?>"><?= e($h['holiday_type']) ?></span></td>
            <td class="small text-muted"><?= e($h['notes'] ?? '') ?></td>
            <td>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="holiday_delete">
                <input type="hidden" name="holiday_id" value="<?= (int) $h['holiday_id'] ?>">
                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this date?');">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$holidays): ?>
          <tr><td colspan="5" class="text-muted small text-center py-3">No holidays registered yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="modal fade" id="newHolidayModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="holiday_create">
          <div class="modal-header"><h6 class="modal-title display-font">Add Holiday / Umuganda</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <div class="mb-2"><label class="form-label small">Date</label><input type="date" name="holiday_date" class="form-control form-control-sm" required></div>
            <div class="mb-2"><label class="form-label small">Title</label><input name="title" class="form-control form-control-sm" required placeholder="e.g. Liberation Day"></div>
            <div class="mb-2">
              <label class="form-label small">Type</label>
              <select name="holiday_type" id="holidayType" class="form-select form-select-sm" onchange="toggleUmuganda()">
                <option value="Public Holiday">Public Holiday — notifies all students</option>
                <option value="Umuganda">Umuganda — notifies weekend students only</option>
              </select>
            </div>
            <div id="umugandaFields" style="display:none;">
              <div class="row g-2">
                <div class="col-6"><label class="form-label small">Morning Start</label><input type="time" name="override_morning_start" class="form-control form-control-sm" value="13:30"></div>
                <div class="col-6"><label class="form-label small">Morning End</label><input type="time" name="override_morning_end" class="form-control form-control-sm" value="16:30"></div>
                <div class="col-6"><label class="form-label small">Afternoon Start</label><input type="time" name="override_afternoon_start" class="form-control form-control-sm" value="17:00"></div>
                <div class="col-6"><label class="form-label small">Afternoon End</label><input type="time" name="override_afternoon_end" class="form-control form-control-sm" value="20:30"></div>
              </div>
            </div>
            <div class="mb-2 mt-2"><label class="form-label small">Notes (optional)</label><input name="notes" class="form-control form-control-sm"></div>
          </div>
          <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Save &amp; Notify</button></div>
        </form>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
<script>
function toggleScope() {
  const v = document.getElementById('scopeSelect') ? document.getElementById('scopeSelect').value : '';
  if (document.getElementById('sessionField')) document.getElementById('sessionField').style.display = (v === 'session') ? '' : 'none';
  if (document.getElementById('yearField'))    document.getElementById('yearField').style.display    = (v === 'year')    ? '' : 'none';
}
function toggleAudience() {
  const el = document.getElementById('audienceSelect');
  const isStudents = el ? el.value === 'students' : true;
  if (document.getElementById('scopeWrap'))   document.getElementById('scopeWrap').style.display   = isStudents ? '' : 'none';
  if (document.getElementById('sessionField')) document.getElementById('sessionField').style.display = 'none';
  if (document.getElementById('yearField'))    document.getElementById('yearField').style.display    = 'none';
  if (isStudents && document.getElementById('scopeSelect')) toggleScope();
}
function toggleUmuganda() {
  const el = document.getElementById('holidayType');
  if (el && document.getElementById('umugandaFields'))
    document.getElementById('umugandaFields').style.display = el.value === 'Umuganda' ? '' : 'none';
}
if (document.getElementById('scopeSelect')) toggleScope();
if (document.getElementById('audienceSelect')) toggleAudience();
</script>
