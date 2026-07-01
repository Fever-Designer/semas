<?php require __DIR__ . '/_layout.php'; semas_email_open(htmlspecialchars($exam_type) . ' Attendance Warning'); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p><?= htmlspecialchars($body) ?></p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0;">
  <tr>
    <td style="padding:10px 14px;background:#F4F6FB;border-bottom:1px solid #e2e8f0;">Module</td>
    <td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;"><strong><?= htmlspecialchars($module_title) ?></strong></td>
  </tr>
  <tr>
    <td style="padding:10px 14px;background:#F4F6FB;border-bottom:1px solid #e2e8f0;">Assessment</td>
    <td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;"><?= htmlspecialchars($exam_type) ?></td>
  </tr>
  <tr>
    <td style="padding:10px 14px;background:#F4F6FB;border-bottom:1px solid #e2e8f0;">Missed days</td>
    <td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;"><?= (int) $missed_days ?></td>
  </tr>
</table>
<p>Please attend all remaining classes and contact your HoD if you have a documented reason for an absence.</p>
<?php semas_email_close(); ?>
