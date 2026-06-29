<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Dean']);

$pageTitle = 'Events & Announcement Management';
$activeNav = 'events';
$db = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_event') {
    csrf_verify();
    $secret = QrService::generateSecret();
    $db->prepare(
        'INSERT INTO events (title, description, venue, capacity, registration_deadline, waitlist_enabled, latitude, longitude, event_date, start_time, end_time, department_id, created_by, qr_secret, qr_expires_at, qr_rotation_seconds)
         VALUES (:title, :desc, :venue, :capacity, :deadline, :waitlist, :lat, :lng, :date, :start, :end, :dept, :uid, :secret, DATE_ADD(NOW(), INTERVAL :ttl HOUR), :rotation)'
    )->execute([
        'title' => $_POST['title'], 'desc' => $_POST['description'] ?: null, 'venue' => $_POST['venue'],
        'capacity' => $_POST['capacity'] ?: null,
        'deadline' => $_POST['registration_deadline'] ?: null,
        'waitlist' => isset($_POST['waitlist_enabled']) ? 1 : 0,
        'lat' => $_POST['latitude'] ?: null, 'lng' => $_POST['longitude'] ?: null,
        'date' => $_POST['event_date'], 'start' => $_POST['start_time'], 'end' => $_POST['end_time'],
        'dept' => $_POST['department_id'] ?: null, 'uid' => Auth::id(), 'secret' => $secret,
        'ttl' => (int) env('QR_DEFAULT_EXPIRY_HOURS', 6) ?: 6,
        'rotation' => (int) ($_POST['qr_rotation_seconds'] ?? 0),
    ]);
    $eventId = (int) $db->lastInsertId();
    AuditLog::record(Auth::id(), 'CREATE_EVENT', 'events', $eventId);
    flash('success', 'Event created. A signed QR code is now available for this event.');
    redirect('/admin/events.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_announcement') {
    csrf_verify();
    $audience = $_POST['target_audience'];
    $deptId = $audience === 'Specific Department' ? (int) $_POST['department_id'] : null;
    $facultyId = $audience === 'Specific Faculty' ? (int) $_POST['faculty_id'] : null;
    $eventIdForAudience = $audience === 'Event Participants' ? (int) $_POST['event_id'] : null;

    $result = Announcement::create([
        'event_id'        => $eventIdForAudience,
        'title'           => $_POST['title'],
        'category'        => $_POST['category'],
        'priority'        => $_POST['priority'],
        'target_audience' => $audience,
        'department_id'   => $deptId,
        'faculty_id'      => $facultyId,
        'message'         => $_POST['message'],
        'status'          => 'Published',
    ], Auth::user(), 'Principal', 'University-wide', true);

    flash('success', "Announcement posted (" . AudienceResolver::describe($audience, $deptId, $facultyId, $eventIdForAudience) . ") and reached {$result['recipients']} recipient(s).");
    redirect('/admin/events.php');
}

$events = $db->query(
    "SELECT e.*, (SELECT COUNT(*) FROM attendance_logs a WHERE a.event_id=e.event_id) AS attendees
     FROM events e ORDER BY e.event_date DESC"
)->fetchAll();
$announcements = $db->query(
    "SELECT * FROM announcements ORDER BY posted_at DESC LIMIT 15"
)->fetchAll();
$departments = $db->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll();
$faculties = $db->query('SELECT faculty_id, faculty_name FROM faculties ORDER BY faculty_name')->fetchAll();

function badge_for_status(string $status): string
{
    $s = strtolower($status);
    if ($s === 'completed') return 'completed';
    if ($s === 'cancelled') return 'cancelled';
    if ($s === 'ongoing') return 'upcoming';
    return 'upcoming';
}

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start mb-4">
  <div><h4 class="display-font mb-1">Manage Events &amp; Announcements</h4></div>
</div>

<div class="semas-card p-3 mb-4">
  <h6 class="display-font mb-3">Create Event</h6>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_event">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label small">Event Title</label><input name="title" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label small">Venue</label><input name="venue" class="form-control" required></div>
      <div class="col-md-4"><label class="form-label small">Date</label><input type="date" name="event_date" class="form-control" required></div>
      <div class="col-md-4"><label class="form-label small">Start Time</label><input type="time" name="start_time" class="form-control" required></div>
      <div class="col-md-4"><label class="form-label small">End Time</label><input type="time" name="end_time" class="form-control" required></div>
      <div class="col-md-6">
        <label class="form-label small">Department (optional)</label>
        <select name="department_id" class="form-select">
          <option value="">All / University-wide</option>
          <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label small">Venue Latitude</label><input name="latitude" class="form-control" placeholder="-1.9536"></div>
      <div class="col-md-3"><label class="form-label small">Venue Longitude</label><input name="longitude" class="form-control" placeholder="30.0947"></div>
      <div class="col-md-2"><label class="form-label small">Capacity</label><input type="number" min="1" name="capacity" class="form-control" placeholder="Unlimited"></div>
      <div class="col-md-4"><label class="form-label small">Registration Deadline</label><input type="datetime-local" name="registration_deadline" class="form-control"></div>
      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check"><input type="checkbox" name="waitlist_enabled" id="waitlist_enabled" class="form-check-input" value="1">
          <label class="form-check-label small" for="waitlist_enabled">Enable waiting list when full</label></div>
      </div>
      <div class="col-md-3"><label class="form-label small">QR Rotation (seconds, 0 = off)</label><input type="number" min="0" name="qr_rotation_seconds" class="form-control" value="0"></div>
      <div class="col-12"><label class="form-label small">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
    </div>
    <button class="btn btn-semas-gold mt-3"><i class="bi bi-qr-code me-1"></i> Save &amp; Generate QR Code</button>
  </form>
</div>

<div class="semas-card p-3 mb-4">
  <h6 class="display-font mb-3">All Events</h6>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Event</th><th>Venue</th><th>Date</th><th>Status</th><th>Check-ins</th><th>QR</th></tr></thead>
      <tbody>
        <?php foreach ($events as $ev): ?>
          <tr>
            <td class="fw-semibold"><?= e($ev['title']) ?></td>
            <td><?= e($ev['venue']) ?></td>
            <td><?= e($ev['event_date']) ?></td>
            <td><span class="badge badge-<?= badge_for_status($ev['status']) ?>"><?= e($ev['status']) ?></span></td>
            <td><?= (int) $ev['attendees'] ?></td>
            <td><a href="<?= APP_URL ?>/admin/qr.php?event_id=<?= (int) $ev['event_id'] ?>" class="small">View QR</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="semas-card p-3 mb-4">
  <h6 class="display-font mb-3">Post Announcement</h6>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_announcement">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label small">Title</label><input name="title" class="form-control" required></div>
      <div class="col-md-2">
        <label class="form-label small">Category</label>
        <select name="category" class="form-select">
          <?php foreach (NotificationGenerator::CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small">Priority</label>
        <select name="priority" class="form-select">
          <?php foreach (NotificationGenerator::PRIORITIES as $p): ?><option><?= e($p) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small">Audience</label>
        <select name="target_audience" class="form-select" id="audienceSelect" onchange="toggleAudienceFields()">
          <?php foreach (['All Students','First Year Students','Final Year Students','Specific Department','Specific Faculty','Day Students','Evening Students','Weekend Students','Staff','Event Participants','University Community'] as $a): ?><option><?= e($a) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3" id="deptField">
        <label class="form-label small">Department (if Specific Department)</label>
        <select name="department_id" class="form-select">
          <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3" id="facultyField">
        <label class="form-label small">Faculty (if Specific Faculty)</label>
        <select name="faculty_id" class="form-select">
          <?php foreach ($faculties as $f): ?><option value="<?= (int) $f['faculty_id'] ?>"><?= e($f['faculty_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label small">Related Event (required if Event Participants)</label>
        <select name="event_id" class="form-select">
          <option value="">None</option>
          <?php foreach ($events as $ev): ?><option value="<?= (int) $ev['event_id'] ?>"><?= e($ev['title']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-12"><label class="form-label small">Message</label><textarea name="message" class="form-control" rows="3" required></textarea></div>
    </div>
    <button class="btn btn-semas mt-3"><i class="bi bi-send me-1"></i> Post &amp; Notify (Email + SMS)</button>
  </form>
</div>

<div class="semas-card p-3">
  <h6 class="display-font mb-3">Posted Announcements</h6>
  <?php foreach ($announcements as $a): include __DIR__ . '/../partials/announcement_card.php'; endforeach; ?>
  <?php if (!$announcements): ?><p class="text-muted small mb-0">No announcements yet.</p><?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
<script>
function toggleAudienceFields() {
  const v = document.getElementById('audienceSelect').value;
  document.getElementById('deptField').style.display = (v === 'Specific Department') ? '' : 'none';
  document.getElementById('facultyField').style.display = (v === 'Specific Faculty') ? '' : 'none';
}
toggleAudienceFields();
</script>
