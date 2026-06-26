<?php require __DIR__ . '/_layout.php'; semas_email_open('Your SEMAS account has been deactivated'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>Your SEMAS account has been deactivated by an administrator. You will not be able to log in until it is reactivated.</p>
<p>If you believe this is a mistake, please contact your department office or the SEMAS helpdesk.</p>
<p>Best regards,<br>University Administration<br>University of Kigali</p>
<?php semas_email_close(); ?>
