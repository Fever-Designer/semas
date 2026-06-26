<?php require __DIR__ . '/_layout.php'; semas_email_open($announcement['title']); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<table cellpadding="0" cellspacing="0" style="margin-bottom:12px;">
  <tr>
    <td style="background:#FCEAEA;color:#E2554B;font-size:11px;font-weight:bold;padding:3px 10px;border-radius:14px;"><?= htmlspecialchars($announcement['priority']) ?> PRIORITY</td>
    <td style="padding-left:8px;color:#5B6478;font-size:11px;"><?= htmlspecialchars($announcement['category']) ?></td>
  </tr>
</table>
<h3 style="margin:0 0 10px;color:#1E2A52;"><?= htmlspecialchars($announcement['title']) ?></h3>
<p><?= nl2br(htmlspecialchars($announcement['message'])) ?></p>
<p>Best regards,<br>University Administration<br>University of Kigali</p>
<?php semas_email_close(); ?>
