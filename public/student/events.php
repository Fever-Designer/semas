<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$pageTitle = 'Events';
$activeNav = 'events';
$db = Database::connection();
Semester::enforceAcademicWrite($db);
EventLifecycle::sync($db);
$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    csrf_verify();
    $eventId = (int) $_POST['event_id'];

    $evStmt = $db->prepare('SELECT * FROM events WHERE event_id = :id');
    $evStmt->execute(['id' => $eventId]);
    $event = $evStmt->fetch();

    if (!$event) {
        flash('error', 'Event not found.');
    } elseif ($event['registration_deadline'] && strtotime($event['registration_deadline']) < time()) {
        flash('error', 'The registration deadline for this event has passed.');
    } else {
        $already = $db->prepare("SELECT registration_id, status FROM event_registrations WHERE event_id=:e AND user_id=:u");
        $already->execute(['e' => $eventId, 'u' => $user['user_id']]);
        $existing = $already->fetch();

        if ($existing && $existing['status'] !== 'Cancelled') {
            flash('error', 'You are already registered for this event.');
        } else {
            if ($existing) {
                $db->prepare("UPDATE event_registrations SET status='Registered', registered_at=NOW() WHERE registration_id=:id")
                   ->execute(['id' => $existing['registration_id']]);
            } else {
                $db->prepare("INSERT INTO event_registrations (event_id, user_id, status) VALUES (:e, :u, 'Registered')")
                   ->execute(['e' => $eventId, 'u' => $user['user_id']]);
            }
            AuditLog::record($user['user_id'], 'EVENT_REGISTER', 'events', $eventId, 'status=Registered');
            NotificationCenter::notify($user['user_id'], 'Registration confirmed', 'You are registered for "' . $event['title'] . '".', 'Event');
            Mailer::sendEventRegistrationConfirmation($user, $event);
            flash('success', 'You are registered for this event.');
        }
    }
    redirect('/student/events.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    csrf_verify();
    $eventId = (int) $_POST['event_id'];
    $db->prepare("UPDATE event_registrations SET status='Cancelled' WHERE event_id=:e AND user_id=:u")
       ->execute(['e' => $eventId, 'u' => $user['user_id']]);

    AuditLog::record($user['user_id'], 'EVENT_REGISTRATION_CANCELLED', 'events', $eventId);
    flash('success', 'Registration cancelled.');
    redirect('/student/events.php');
}

$stmt = $db->prepare(
    "SELECT e.*,
        (SELECT status FROM event_registrations er2 WHERE er2.event_id=e.event_id AND er2.user_id=:uid LIMIT 1) AS my_status
     FROM events e
     WHERE e.status IN ('Scheduled', 'Ongoing')
     ORDER BY e.event_date"
);
$stmt->execute(['uid' => $user['user_id']]);
$events = $stmt->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Events</h4>

<div class="row g-3">
  <?php foreach ($events as $ev):
      $deadlinePassed = $ev['registration_deadline'] && strtotime($ev['registration_deadline']) < time();
  ?>
  <div class="col-md-6">
    <div class="semas-card p-3 h-100">
      <div class="d-flex justify-content-between align-items-start">
        <h6 class="display-font mb-1"><?= e($ev['title']) ?></h6>
        <span class="badge badge-upcoming"><?= e($ev['status']) ?></span>
      </div>
      <div class="text-muted small mb-2"><?= e($ev['event_date']) ?>, <?= e($ev['start_time']) ?> &middot; <?= e($ev['venue']) ?></div>
      <p class="small text-muted"><?= e(mb_substr((string) $ev['description'], 0, 140)) ?></p>

      <?php if ($ev['my_status'] === 'Registered'): ?>
        <span class="badge badge-completed mb-2">You're registered</span><br>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="event_id" value="<?= (int) $ev['event_id'] ?>">
          <button class="btn btn-sm btn-outline-dark mt-1">Cancel Registration</button></form>
      <?php elseif ($deadlinePassed): ?>
        <span class="text-muted small">Registration closed</span>
      <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="register"><input type="hidden" name="event_id" value="<?= (int) $ev['event_id'] ?>">
          <button class="btn btn-sm btn-semas">Register</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$events): ?><p class="text-muted small">No events scheduled right now.</p><?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
