<?php require __DIR__ . '/_layout.php'; semas_email_open('New Assignment — ' . htmlspecialchars($module_title)); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>Your lecturer has posted a new assignment for your module. Please read the details carefully.</p>

<table cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #E3E6EE;border-radius:8px;margin-bottom:16px;font-size:13px;">
  <tr><td style="background:#1E2A52;color:#fff;padding:10px 16px;border-radius:7px 7px 0 0;font-weight:bold;">Assignment Details</td></tr>
  <tr>
    <td style="padding:14px 16px;">
      <table cellpadding="0" cellspacing="0" width="100%" style="font-size:13px;color:#1B1F2A;">
        <tr><td style="padding:4px 0;color:#5B6478;width:160px;vertical-align:top;">MODULE:</td><td style="padding:4px 0;font-weight:bold;"><?= htmlspecialchars($module_title) ?></td></tr>
        <tr><td style="padding:4px 0;color:#5B6478;vertical-align:top;">LECTURER:</td><td style="padding:4px 0;font-weight:bold;"><?= htmlspecialchars($lecturer_name) ?></td></tr>
        <tr><td style="padding:4px 0;color:#5B6478;vertical-align:top;">SESSION:</td><td style="padding:4px 0;font-weight:bold;"><?= htmlspecialchars($session_type) ?></td></tr>
        <tr><td style="padding:4px 0;color:#5B6478;vertical-align:top;">SUBMISSION DEADLINE:</td><td style="padding:4px 0;font-weight:bold;color:#C0392B;"><?= htmlspecialchars($deadline_formatted) ?></td></tr>
      </table>
    </td>
  </tr>
</table>

<h3 style="margin:0 0 6px;color:#1E2A52;"><?= htmlspecialchars($assignment_title) ?></h3>

<?php if (!empty($instructions)): ?>
<p style="margin-bottom:14px;"><strong>Lecturer Instructions:</strong><br><?= nl2br(htmlspecialchars($instructions)) ?></p>
<?php endif; ?>

<?php if (!empty($attachment_url)): ?>
<p style="margin-bottom:14px;">
  <strong>Lecturer's Attachment File:</strong><br>
  <a href="<?= htmlspecialchars($attachment_url) ?>" style="color:#1E2A52;">Download Attachment</a>
</p>
<?php endif; ?>

<table cellpadding="0" cellspacing="0" width="100%" style="margin:18px 0;">
  <tr>
    <td style="background:#FFF8E7;border-left:4px solid #D4A24C;padding:12px 16px;font-size:13px;color:#856404;border-radius:0 6px 6px 0;">
      <strong>Reminder:</strong> Once you're done, remember to submit in your SEMAS Portal before the deadline ends.
    </td>
  </tr>
</table>

<p>Best regards,<br>SEMAS — University of Kigali</p>
<?php semas_email_close(); ?>
