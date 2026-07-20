<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Dean']);

$pageTitle = 'Attendance & QR Codes';
$activeNav = 'attendance';
$db = Database::connection();
EventLifecycle::sync($db);

$eventId = (int) ($_GET['event_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM events WHERE event_id = :id');
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

$ongoingEvents = $db->query(
    "SELECT event_id, title, event_date, start_time, end_time
     FROM events WHERE status = 'Ongoing' ORDER BY event_date, start_time"
)->fetchAll();

$qrToken = $event && $event['status'] === 'Ongoing'
    ? QrService::buildPayload((int) $event['event_id'], $event['qr_secret'])
    : null;
$scanUrl = $qrToken ? APP_URL . '/student/scan.php?e=' . $event['event_id'] . '&t=' . $qrToken : null;
$qrImage = $scanUrl ? SimpleQr::pngDataUri($scanUrl, 4, 3) : null;

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Event QR Code</h4>

<div class="semas-card p-3 mb-3">
  <label class="form-label small fw-semibold">Ongoing Events</label>
  <select class="form-select" onchange="if(this.value){window.location.href='?event_id='+this.value;}">
    <option value="">Select an ongoing event...</option>
    <?php foreach ($ongoingEvents as $ongoingEvent): ?>
      <option value="<?= (int) $ongoingEvent['event_id'] ?>" <?= $eventId === (int) $ongoingEvent['event_id'] ? 'selected' : '' ?>>
        <?= e($ongoingEvent['title']) ?> &middot; <?= e($ongoingEvent['event_date']) ?>, <?= e($ongoingEvent['start_time']) ?>/<?= e($ongoingEvent['end_time']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php if (!$ongoingEvents): ?>
    <div class="text-muted small mt-2">There are no ongoing events right now.</div>
  <?php endif; ?>
</div>

<?php if (!$event): ?>
  <div class="alert alert-light border small">Select an ongoing event above to display its attendance QR code.</div>
<?php elseif ($event['status'] !== 'Ongoing'): ?>
  <div class="alert alert-info">This event is not ongoing. Completed events are available in Event History.</div>
  <a href="<?= APP_URL ?>/admin/events.php" class="btn btn-outline-dark">View Event History</a>
<?php else: ?>
  <div class="semas-card p-4 mx-auto text-center" style="max-width:480px;">
    <h6 class="display-font text-start mb-1"><?= e($event['title']) ?></h6>
    <p class="text-muted small text-start mb-3"><?= e($event['venue']) ?> &middot; <?= e($event['event_date']) ?>, <?= e($event['start_time']) ?>/<?= e($event['end_time']) ?></p>

    <div class="qr-frame">
      <div class="corner c1"></div><div class="corner c2"></div><div class="corner c3"></div><div class="corner c4"></div>
      <div id="qr-canvas"><?php if ($qrImage): ?><img id="qr-image" src="<?= e($qrImage) ?>" alt="Event check-in QR code" width="200" height="200"><?php endif; ?></div>
    </div>
    <div class="qr-token-label" id="qr-token-label"><?= e($scanUrl) ?></div>
    <?php if ((int) $event['qr_rotation_seconds'] > 0): ?>
      <div class="badge badge-urgent mt-2">Rotating every <?= (int) $event['qr_rotation_seconds'] ?>s / old scans expire automatically</div>
    <?php endif; ?>
    <script>
      const rotationSeconds = <?= (int) $event['qr_rotation_seconds'] ?>;
      if (rotationSeconds > 0) {
        setInterval(function () {
          fetch('<?= APP_URL ?>/api/qr-refresh.php?event_id=<?= (int) $event['event_id'] ?>')
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (!data.ok) return;
              document.getElementById('qr-image').src = data.qr_data_uri;
              document.getElementById('qr-token-label').textContent = data.scan_url;
            });
        }, rotationSeconds * 1000);
      }
    </script>
    <button class="btn btn-semas-gold mt-3"><i class="bi bi-printer me-1"></i> Print QR Code</button>
    <?php if ($event['status'] === 'Ongoing'): ?>
      <a href="<?= APP_URL ?>/admin/scan-student.php?event_id=<?= (int) $event['event_id'] ?>" class="btn btn-semas mt-3">
        <i class="bi bi-camera me-1"></i> Scan Student QR
      </a>
    <?php else: ?>
      <button class="btn btn-secondary mt-3" disabled><i class="bi bi-camera me-1"></i> Scanner Available When Ongoing</button>
    <?php endif; ?>
    <p class="text-muted mt-3" style="font-size:0.72rem;">
      This QR encodes an HMAC-signed, encrypted token and expires automatically. Only registered students
      can check in while the event is ongoing. The Dean can also scan a registered student's personal QR code.
    </p>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
