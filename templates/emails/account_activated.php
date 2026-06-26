<?php require __DIR__ . '/_layout.php'; semas_email_open('Your SEMAS account is now active'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>Your SEMAS account has been activated by an administrator. You can now log in and use all features available to your role.</p>
<p>Best regards,<br>University Administration<br>University of Kigali</p>
<?php semas_email_close(); ?>
