<?php require __DIR__ . '/_layout.php'; semas_email_open('Your SEMAS Student Account Credentials'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>Welcome to the Smart Education Management and Announcement System (SEMAS) at the University of Kigali. Your student account has been created by the Registrar's Office.</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
  <tr>
    <td style="padding:8px 12px;background:#F4F6FB;font-weight:bold;width:40%;">Registration Number</td>
    <td style="padding:8px 12px;background:#F4F6FB;"><?= htmlspecialchars($reg_number) ?></td>
  </tr>
  <tr>
    <td style="padding:8px 12px;font-weight:bold;">Default Password</td>
    <td style="padding:8px 12px;"><?= htmlspecialchars($password) ?></td>
  </tr>
</table>
<p><strong>⚠ Important:</strong> For security, you will be required to change this password the first time you log in.</p>
<p><a href="<?= htmlspecialchars($login_url) ?>" style="display:inline-block;background:#1E2A52;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;">Log In to SEMAS</a></p>
<p>If you have trouble logging in, please contact the Registrar's Office.</p>
<p>Best regards,<br>Registrar's Office<br>University of Kigali</p>
<?php semas_email_close(); ?>
