<?php require __DIR__ . '/_layout.php'; semas_email_open($announcement['title']); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<table cellpadding="0" cellspacing="0" style="margin-bottom:12px;">
  <tr>
    <td style="background:#FCEAEA;color:#E2554B;font-size:11px;font-weight:bold;padding:3px 10px;border-radius:14px;"><?= htmlspecialchars((string) ($announcement['priority'] ?? 'Normal')) ?> PRIORITY</td>
    <td style="padding-left:8px;color:#5B6478;font-size:11px;"><?= htmlspecialchars((string) ($announcement['category'] ?? 'General')) ?></td>
  </tr>
</table>
<h3 style="margin:0 0 10px;color:#1E2A52;"><?= htmlspecialchars($announcement['title']) ?></h3>
<p><?= nl2br(htmlspecialchars($announcement['message'])) ?></p>
<table cellpadding="0" cellspacing="0" style="margin-top:14px;border-top:1px solid #E3E6EE;padding-top:10px;width:100%;font-size:12px;color:#5B6478;">
  <tr>
    <td style="width:50%;">Sent by:<br><strong style="color:#1E2A52;"><?= htmlspecialchars($announcement['sender_name'] ?? 'University Administration') ?></strong></td>
    <td style="width:50%;">Role:<br><strong style="color:#1E2A52;"><?= htmlspecialchars($announcement['sender_role'] ?? '') ?></strong><?= !empty($announcement['sender_scope']) ? '<br>' . htmlspecialchars($announcement['sender_scope']) : '' ?></td>
  </tr>
  <tr>
    <td style="padding-top:6px;">Date:<br><?= htmlspecialchars(date('d F Y', strtotime($announcement['posted_at'] ?? 'now'))) ?></td>
    <td style="padding-top:6px;">Time:<br><?= htmlspecialchars(date('h:i A', strtotime($announcement['posted_at'] ?? 'now'))) ?></td>
  </tr>
</table>
<p>Best regards,<br>UNIVERSITY</p>
<?php semas_email_close(); ?>
