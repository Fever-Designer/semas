<?php require __DIR__ . '/_layout.php'; semas_email_open('Event registration confirmed'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>You have successfully registered for the following event:</p>
<table cellpadding="6" cellspacing="0" style="background:#F6F7FB;border-radius:8px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:bold;width:120px;">Event</td><td><?= htmlspecialchars($event['title']) ?></td></tr>
  <tr><td style="font-weight:bold;">Venue</td><td><?= htmlspecialchars($event['venue']) ?></td></tr>
  <tr><td style="font-weight:bold;">Date</td><td><?= htmlspecialchars($event['event_date']) ?></td></tr>
  <tr><td style="font-weight:bold;">Time</td><td><?= htmlspecialchars($event['start_time']) ?> / <?= htmlspecialchars($event['end_time']) ?></td></tr>
</table>
<p>Your personal QR code will be used to confirm your attendance at the venue. You can view it any time from your SEMAS dashboard.</p>
<p>Best regards,<br>University Administration<br>University of Kigali</p>
<?php semas_email_close(); ?>
