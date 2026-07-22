<?php require __DIR__ . '/_layout.php'; semas_email_open('Reset your SEMAS password'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>We received a request to reset your SEMAS password. Click the button below to choose a new one.</p>
<p style="text-align:center;margin:28px 0;">
  <a href="<?= htmlspecialchars($reset_url) ?>" style="background:#1E2A52;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:8px;font-weight:bold;display:inline-block;">Reset My Password</a>
</p>
<p>This link will expire in <?= RESET_LINK_EXPIRY_MINUTES ?> minutes. If you did not request a password reset, please ignore this email / your password will not be changed.</p>
<p>Best regards,<br>University Administration<br>UNIVERSITY</p>
<?php semas_email_close(); ?>
