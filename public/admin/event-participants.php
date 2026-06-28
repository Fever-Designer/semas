<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Dean']);

$pageTitle = 'Event Participants';
$activeNav = 'events';
$db = Database::connection();

$eventId = (int) ($_GET['event_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $regId = (int) ($_POST['registration_id'] ?? 0);

    if ($action === 'remove') {
        $db->prepare("UPDATE event_registrations SET status='Cancelled' WHERE registration_id=:id")->execute(['id' => $regId]);
        AuditLog::record(Auth::id(), 'PARTICIPANT_REMOVED', 'event_registrations', $regId);
        flash('success', 'Participant removed from the event.');
    } elseif ($action === 'mark_present') {
        $studentUserId = (int) $_POST['user_id'];
        $existing = $db->prepare('SELECT attendance_id FROM attendance_logs WHERE event_id=:e AND user_id=:u');
        $existing->execute(['e' => $eventId, 'u' => $studentUserId]);
        if (!$existing->fetch()) {
            $db->prepare("INSERT INTO attendance_logs (event_id, user_id, verification_method, confirmed_by) VALUES (:e, :u, 'Manual', :by)")
               ->execute(['e' => $eventId, 'u' => $studentUserId, 'by' => Auth::id()]);
            AuditLog::record(Auth::id(), 'ATTENDANCE_MANUAL_MARK', 'events', $eventId, "student=$studentUserId");
            flash('success', 'Marked present.');
        } else {
            flash('error', 'Already marked present.');
        }
    }
    redirect('/admin/event-participants.php?event_id=' . $eventId);
}

$events = $db->query('SELECT event_id, title, event_date FROM events ORDER BY event_date DESC')->fetchAll();

$participants = [];
if ($eventId) {
    $search = trim($_GET['q'] ?? '');
    $sql = "SELECT er.registration_id, er.status AS reg_status, u.user_id, u.full_name, u.reg_number, d.department_name,
                   (a.attendance_id IS NOT NULL) AS attended
            FROM event_registrations er
            JOIN users u ON u.user_id = er.user_id
            LEFT JOIN departments d ON d.department_id = u.department_id
            LEFT JOIN attendance_logs a ON a.event_id = er.event_id AND a.user_id = er.user_id
            WHERE er.event_id = :eid";
    $params = ['eid' => $eventId];
    if ($search !== '') {
        $sql .= ' AND (u.full_name LIKE :q OR u.reg_number LIKE :q)';
        $params['q'] = "%$search%";
    }
    $sql .= " ORDER BY er.status, u.full_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $participants = $stmt->fetchAll();
}

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Event Participants</h4>
<p class="text-muted small mb-4">View, search, export, or remove registrants. You can also mark attendance manually here.</p>

<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2">
    <div class="col-md-6">
      <select name="event_id" class="form-select" onchange="this.form.submit()">
        <option value="">Select an event...</option>
        <?php foreach ($events as $ev): ?><option value="<?= (int) $ev['event_id'] ?>" <?= $eventId === (int) $ev['event_id'] ? 'selected' : '' ?>><?= e($ev['title']) ?> &middot; <?= e($ev['event_date']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4"><input name="q" class="form-control" placeholder="Search by name or reg. number" value="<?= e($_GET['q'] ?? '') ?>"></div>
    <div class="col-md-2"><button class="btn btn-semas w-100">Filter</button></div>
  </form>
</div>

<?php if ($eventId): ?>
<div class="semas-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="display-font mb-0"><?= count($participants) ?> participant(s)</h6>
    <div>
      <a href="<?= APP_URL ?>/reports/export-pdf.php?event_id=<?= $eventId ?>" target="_blank" class="btn btn-sm btn-semas"><i class="bi bi-file-earmark-pdf-fill me-1"></i> Export PDF</a>
      <a href="<?= APP_URL ?>/reports/export-excel.php?event_id=<?= $eventId ?>" class="btn btn-sm btn-semas-gold"><i class="bi bi-file-earmark-excel-fill me-1"></i> Export Excel</a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Name</th><th>Reg. No</th><th>Department</th><th>Status</th><th>Attendance</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($participants as $p): ?>
          <tr>
            <td class="fw-semibold"><?= e($p['full_name']) ?></td>
            <td><?= e($p['reg_number']) ?></td>
            <td><?= e($p['department_name'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $p['reg_status'] === 'Registered' ? 'completed' : ($p['reg_status'] === 'Waitlisted' ? 'urgent' : 'cancelled') ?>"><?= e($p['reg_status']) ?></span></td>
            <td><?= $p['attended'] ? '<span class="badge badge-completed">Present</span>' : '<span class="badge badge-cancelled">Not yet</span>' ?></td>
            <td class="text-nowrap">
              <?php if (!$p['attended']): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="mark_present">
                <input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="user_id" value="<?= (int) $p['user_id'] ?>">
                <button class="btn btn-sm btn-outline-dark">Mark Present</button>
              </form>
              <?php endif; ?>
              <?php if ($p['reg_status'] !== 'Cancelled'): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Remove this participant from the event?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="remove">
                <input type="hidden" name="registration_id" value="<?= (int) $p['registration_id'] ?>">
                <button class="btn btn-sm btn-outline-danger">Remove</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$participants): ?><tr><td colspan="6" class="text-muted small text-center py-3">No participants registered yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
