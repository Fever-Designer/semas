<?php require __DIR__ . '/_layout.php'; semas_email_open('Your SEMAS verification code'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>Your one-time verification code for <strong><?= htmlspecialchars($purpose_label) ?></strong> is:</p>
<p style="text-align:center;margin:28px 0;">
  <span style="display:inline-block;background:#F3F5FA;border:1px dashed #1E2A52;border-radius:8px;padding:14px 28px;font-size:28px;font-weight:bold;letter-spacing:6px;color:#1E2A52;"><?= htmlspecialchars($code) ?></span>
</p>
<p>This code will expire in a few minutes. Never share this code with anyone, including anyone claiming to be SEMAS support staff.</p>
<p>Best regards,<br>University Administration<br>UNIVERSITY</p>
<?php semas_email_close(); ?>
