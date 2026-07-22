<?php require __DIR__ . '/_layout.php'; semas_email_open('Your SEMAS email address was changed'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>This confirms that your SEMAS login email was successfully changed to <strong><?= htmlspecialchars($new_email) ?></strong> on <?= htmlspecialchars($changed_at) ?>.</p>
<p>If you did not make this change, contact the SEMAS helpdesk immediately.</p>
<p>Best regards,<br>University Administration<br>UNIVERSITY</p>
<?php semas_email_close(); ?>
