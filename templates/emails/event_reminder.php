<?php require __DIR__ . '/_layout.php'; semas_email_open('Event Reminder'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>This is a reminder that an event you registered for is <strong><?= htmlspecialchars($label) ?></strong>:</p>
<table cellpadding="6" cellspacing="0" style="background:#F6F7FB;border-radius:8px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:bold;width:120px;">Event</td><td><?= htmlspecialchars($event['title']) ?></td></tr>
  <tr><td style="font-weight:bold;">Venue</td><td><?= htmlspecialchars($event['venue']) ?></td></tr>
  <tr><td style="font-weight:bold;">Date</td><td><?= htmlspecialchars($event['event_date']) ?></td></tr>
  <tr><td style="font-weight:bold;">Time</td><td><?= htmlspecialchars($event['start_time']) ?> / <?= htmlspecialchars($event['end_time']) ?></td></tr>
</table>
<p>Remember to bring your personal QR code (or be ready to scan the event QR at the venue) to check in.</p>
<p>Best regards,<br>University Administration<br>UNIVERSITY</p>
<?php semas_email_close(); ?>
