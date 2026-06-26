<?php require __DIR__ . '/_layout.php'; semas_email_open('Your SEMAS password was changed'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>This confirms that your SEMAS account password was successfully changed on <?= htmlspecialchars($changed_at) ?>.</p>
<p>If you did not make this change, contact the SEMAS helpdesk immediately and reset your password again.</p>
<p>Best regards,<br>University Administration<br>University of Kigali</p>
<?php semas_email_close(); ?>
