<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Dean']);

$pageTitle = 'Attendance & QR Codes';
$activeNav = 'attendance';
$db = Database::connection();

$eventId = (int) ($_GET['event_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM events WHERE event_id = :id');
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

if (!$event) {
    $events = $db->query('SELECT event_id, title FROM events ORDER BY event_date DESC')->fetchAll();
}

$qrToken = $event ? QrService::buildPayload((int) $event['event_id'], $event['qr_secret']) : null;
$scanUrl = $event ? APP_URL . '/student/scan.php?e=' . $event['event_id'] . '&t=' . $qrToken : null;
$qrImage = $scanUrl ? SimpleQr::pngDataUri($scanUrl, 4, 3) : null;

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Event QR Code</h4>

<?php if (!$event): ?>
  <div class="semas-card p-3">
    <h6 class="display-font mb-3">Select an Event</h6>
    <table class="table table-sm align-middle">
      <thead><tr><th>Event</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($events as $ev): ?>
          <tr><td><?= e($ev['title']) ?></td><td><a href="?event_id=<?= (int) $ev['event_id'] ?>" class="btn btn-sm btn-outline-dark">View QR</a></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <div class="semas-card p-4 mx-auto text-center" style="max-width:480px;">
    <h6 class="display-font text-start mb-1"><?= e($event['title']) ?></h6>
    <p class="text-muted small text-start mb-3"><?= e($event['venue']) ?> &middot; <?= e($event['event_date']) ?>, <?= e($event['start_time']) ?>&ndash;<?= e($event['end_time']) ?></p>

    <div class="qr-frame">
      <div class="corner c1"></div><div class="corner c2"></div><div class="corner c3"></div><div class="corner c4"></div>
      <div id="qr-canvas"><?php if ($qrImage): ?><img id="qr-image" src="<?= e($qrImage) ?>" alt="Event check-in QR code" width="200" height="200"><?php endif; ?></div>
    </div>
    <div class="qr-token-label" id="qr-token-label"><?= e($scanUrl) ?></div>
    <?php if ((int) $event['qr_rotation_seconds'] > 0): ?>
      <div class="badge badge-urgent mt-2">Rotating every <?= (int) $event['qr_rotation_seconds'] ?>s — old scans expire automatically</div>
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
    <p class="text-muted mt-3" style="font-size:0.72rem;">
      This QR encodes an HMAC-signed, encrypted token and expires automatically. Students must be physically
      within the configured campus radius for their scan to be accepted.
    </p>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
