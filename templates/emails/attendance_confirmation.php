<?php require __DIR__ . '/_layout.php'; semas_email_open('Attendance confirmed'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>Your attendance has been recorded for:</p>
<table cellpadding="6" cellspacing="0" style="background:#F6F7FB;border-radius:8px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:bold;width:120px;">Event</td><td><?= htmlspecialchars($event['title']) ?></td></tr>
  <tr><td style="font-weight:bold;">Venue</td><td><?= htmlspecialchars($event['venue']) ?></td></tr>
  <tr><td style="font-weight:bold;">Checked in at</td><td><?= htmlspecialchars($checkin_time) ?></td></tr>
</table>
<p>If you did not check in to this event yourself, please contact the SEMAS helpdesk immediately.</p>
<p>Best regards,<br>University Administration<br>UNIVERSITY</p>
<?php semas_email_close(); ?>
