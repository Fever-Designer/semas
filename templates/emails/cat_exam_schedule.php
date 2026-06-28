<?php
/** vars: full_name, exam_type, module_title, scheduled_date, start_time, end_time, room, invigilator_name, day_of_week */
require_once __DIR__ . '/_layout.php';
semas_email_open($exam_type . ' Schedule — ' . $module_title);

$dateLabel  = date('d F Y', strtotime($scheduled_date));
$timeLabel  = date('h:i A', strtotime($start_time)) . ' – ' . date('h:i A', strtotime($end_time));

$typeWord   = $exam_type === 'Exam' ? 'Exam' : 'CAT';
$actionWord = $exam_type === 'Exam' ? 'Your Exam' : 'Your CAT';
?>
<p>Dear <strong><?= htmlspecialchars($full_name) ?></strong>,</p>

<p style="font-size:16px;font-weight:bold;color:#1E2A52;">
  <?= htmlspecialchars($actionWord) ?> is on <strong><?= htmlspecialchars($day_of_week) ?></strong> — Be Prepared with All Required Documents.
</p>

<p>Here are the details for your upcoming <strong><?= htmlspecialchars($typeWord) ?></strong>:</p>

<table cellpadding="0" cellspacing="0" width="100%"
       style="border-collapse:collapse;font-size:14px;margin:16px 0;">
  <tr style="background:#F3F4F6;">
    <td style="padding:8px 12px;color:#6B7280;width:40%;border:1px solid #E4E7EF;">Module</td>
    <td style="padding:8px 12px;font-weight:bold;border:1px solid #E4E7EF;"><?= htmlspecialchars($module_title) ?></td>
  </tr>
  <tr>
    <td style="padding:8px 12px;color:#6B7280;border:1px solid #E4E7EF;">Assessment Type</td>
    <td style="padding:8px 12px;font-weight:bold;border:1px solid #E4E7EF;"><?= htmlspecialchars($typeWord) ?></td>
  </tr>
  <tr style="background:#F3F4F6;">
    <td style="padding:8px 12px;color:#6B7280;border:1px solid #E4E7EF;">Date</td>
    <td style="padding:8px 12px;font-weight:bold;border:1px solid #E4E7EF;"><?= htmlspecialchars($day_of_week . ', ' . $dateLabel) ?></td>
  </tr>
  <tr>
    <td style="padding:8px 12px;color:#6B7280;border:1px solid #E4E7EF;">Time</td>
    <td style="padding:8px 12px;font-weight:bold;border:1px solid #E4E7EF;"><?= htmlspecialchars($timeLabel) ?></td>
  </tr>
  <tr style="background:#F3F4F6;">
    <td style="padding:8px 12px;color:#6B7280;border:1px solid #E4E7EF;">Room</td>
    <td style="padding:8px 12px;font-weight:bold;border:1px solid #E4E7EF;"><?= htmlspecialchars($room) ?></td>
  </tr>
  <tr>
    <td style="padding:8px 12px;color:#6B7280;border:1px solid #E4E7EF;">Invigilator</td>
    <td style="padding:8px 12px;font-weight:bold;border:1px solid #E4E7EF;"><?= htmlspecialchars($invigilator_name) ?></td>
  </tr>
</table>

<p style="background:#FEF3C7;padding:10px 14px;border-left:4px solid #D97706;border-radius:4px;font-size:13px;margin:12px 0;">
  <strong>Reminder:</strong> Bring all required documents and arrive <strong>at least 10 minutes early</strong>.
  Students who are not signed in within the first 20 minutes of the <?= htmlspecialchars($typeWord) ?> will be marked Absent.
</p>

<p>If you have any questions, please contact your Head of Department.</p>

<p>Good luck!</p>
<p><em>SEMAS Academic Team</em></p>
<?php semas_email_close(); ?>
