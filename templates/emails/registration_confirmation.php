<?php require __DIR__ . '/_layout.php'; semas_email_open('Welcome to SEMAS'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>Your SEMAS account has been created successfully. Once your email is verified and your account is activated, you will be able to log in to view campus events, receive announcements, and check in to events using your personal QR code.</p>
<p>Best regards,<br>University Administration<br>University of Kigali</p>
<?php semas_email_close(); ?>
