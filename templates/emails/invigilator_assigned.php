<?php
/** vars: full_name, exam_type, module_title, scheduled_date, start_time, end_time, room, day_of_week */
require_once __DIR__ . '/_layout.php';
semas_email_open('Invigilator Assignment — ' . $exam_type . ': ' . $module_title);

$dateLabel = date('d F Y', strtotime($scheduled_date));
$timeLabel = date('h:i A', strtotime($start_time)) . ' – ' . date('h:i A', strtotime($end_time));
$typeWord  = $exam_type === 'Exam' ? 'Exam' : 'CAT';
?>
<p>Dear <strong><?= htmlspecialchars($full_name) ?></strong>,</p>

<p>You have been selected to invigilate the upcoming <strong><?= htmlspecialchars($typeWord) ?></strong> on <strong><?= htmlspecialchars($day_of_week) ?></strong>. Please review the details below and ensure you are fully prepared.</p>

<table cellpadding="0" cellspacing="0" width="100%"
       style="border-collapse:collapse;font-size:14px;margin:16px 0;">
  <tr style="background:#F3F4F6;">
    <td style="padding:8px 12px;color:#6B7280;width:40%;border:1px solid #E4E7EF;">Module</td>
    <td style="padding:8px 12px;font-weight:bold;border:1px solid #E4E7EF;"><?= htmlspecialchars($module_title) ?></td>
  </tr>
  <tr>
    <td style="padding:8px 12px;color:#6B7280;border:1px solid #E4E7EF;">Session</td>
    <td style="padding:8px 12px;font-weight:bold;border:1px solid #E4E7EF;"><?= htmlspecialchars($typeWord) ?></td>
  </tr>
  <tr style="background:#F3F4F6;">
    <td style="padding:8px 12px;color:#6B7280;border:1px solid #E4E7EF;"><?= htmlspecialchars($typeWord) ?> Date</td>
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
</table>

<p style="font-size:15px;font-weight:bold;color:#1E2A52;margin:20px 0 8px 0;">
  EXAM / CAT INVIGILATOR &amp; SUPERVISOR INSTRUCTIONS
</p>

<ol style="font-size:13px;line-height:1.8;color:#1B1F2A;padding-left:18px;margin:0 0 16px 0;">
  <li>Arrive at the examination room at least <strong>30 minutes before start time</strong> and prepare seating, materials, and attendance system.</li>
  <li>Verify student identity using ID or SEMAS scan and confirm eligibility before entry.</li>
  <li>Allow entry only for students with valid Entry Slip and mark <strong>Sign In</strong> in SEMAS.</li>
  <li>Ensure strict silence, discipline, and proper conduct throughout the examination.</li>
  <li>Prevent cheating, unauthorized materials, and monitor students continuously.</li>
  <li>Allow late entry only within the approved time limit and ensure sign-in is still recorded.</li>
  <li>Do not leave the examination room unattended at any time.</li>
  <li>At the end, collect all scripts and confirm submission before students exit.</li>
  <li>Record <strong>Sign Out</strong> in SEMAS for each student before they leave.</li>
  <li>Submit attendance logs, incident reports, and ensure all materials are handed to the exam office securely.</li>
</ol>

<p style="background:#FEF3C7;padding:10px 14px;border-left:4px solid #D97706;border-radius:4px;font-size:13px;margin:12px 0;">
  <strong>Important:</strong> Use SEMAS to record Sign In and Sign Out for every student. Incomplete records cannot be certified.
</p>

<p>For any questions or concerns, please contact the Head of Department before the assessment date.</p>

<p><em>SEMAS Academic Team</em></p>
<?php semas_email_close(); ?>
