<?php require __DIR__ . '/_layout.php'; semas_email_open('Your SEMAS ' . $role_label . ' account has been created'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>An account has been created for you on the Smart Education Management and Announcement System (SEMAS) with the role of <strong><?= htmlspecialchars($role_label) ?></strong>, by <?= htmlspecialchars($created_by_name) ?>.</p>
<p>Your temporary password is:</p>
<p style="font-size:18px;font-weight:bold;background:#F4F6FB;padding:10px 14px;border-radius:8px;letter-spacing:1px;"><?= htmlspecialchars($temp_password) ?></p>
<p>For security, please log in and change this password as soon as possible.</p>
<p>Best regards,<br>University Administration<br>University of Kigali</p>
<?php semas_email_close(); ?>
