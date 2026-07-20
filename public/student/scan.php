<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$pageTitle = 'Event Check-in';
$activeNav = 'events';
$eventId = (int) ($_GET['e'] ?? ($_GET['event_id'] ?? 0));
$token = (string) ($_GET['t'] ?? '');

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-3">Event Check-in</h4>

<div class="semas-card p-4 mx-auto text-center" style="max-width:480px;">
  <div id="checkinStatus" class="text-muted small">
    <div class="spinner-border spinner-border-sm me-1"></div>
    Preparing your event check-in...
  </div>
</div>

<script>
(function () {
  const statusEl = document.getElementById('checkinStatus');
  const eventId = <?= (int) $eventId ?>;
  const token = <?= json_encode($token) ?>;
  const csrf = <?= json_encode(csrf_token()) ?>;

  function show(type, message) {
    const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
    statusEl.innerHTML = '<div class="alert alert-' + type + ' mb-0">' +
      '<i class="bi ' + icon + ' me-1"></i>' + message +
      '</div>';
  }

  if (!eventId || !token) {
    show('danger', 'This event QR code is missing required scan data.');
    return;
  }

  statusEl.innerHTML = '<div class="text-muted small"><div class="spinner-border spinner-border-sm me-1"></div>Confirming attendance...</div>';
  fetch('<?= APP_URL ?>/api/checkin.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      event_id: eventId,
      token: token,
      csrf_token: csrf
    })
  })
  .then(function (r) { return r.json(); })
  .then(function (data) {
    show(data.ok ? 'success' : 'danger', data.message || 'Unable to complete check-in.');
  })
  .catch(function () {
    show('danger', 'Network error. Please try scanning again.');
  });
})();
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
