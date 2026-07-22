<?php require __DIR__ . '/_layout.php'; semas_email_open('Verify your SEMAS account'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>Thank you for registering on SEMAS. Please confirm your email address to activate your account.</p>
<p style="text-align:center;margin:28px 0;">
  <a href="<?= htmlspecialchars($verify_url) ?>" style="background:#D4A24C;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:8px;font-weight:bold;display:inline-block;">Verify My Email</a>
</p>
<p>If the button does not work, copy and paste this link into your browser:<br>
<a href="<?= htmlspecialchars($verify_url) ?>"><?= htmlspecialchars($verify_url) ?></a></p>
<p>This link will expire in <?= VERIFY_LINK_EXPIRY_HOURS ?> hours. If you did not create a SEMAS account, you can ignore this email.</p>
<p>Best regards,<br>University Administration<br>UNIVERSITY</p>
<?php semas_email_close(); ?>
