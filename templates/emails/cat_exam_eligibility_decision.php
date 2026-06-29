<?php
/** vars: full_name, exam_type, module_title, scheduled_date, start_time, end_time, room, invigilator_name, day_of_week, final_decision */
require_once __DIR__ . '/_layout.php';
semas_email_open($exam_type . ' Eligibility Decision — ' . $module_title);

date_default_timezone_set('Africa/Kigali');
$dateLabel = date('d F Y', strtotime($scheduled_date));
$timeLabel = date('h:i A', strtotime($start_time)) . ' – ' . date('h:i A', strtotime($end_time));
$typeWord  = $exam_type === 'Exam' ? 'Exam' : 'CAT';
$allowed   = $final_decision === 'Allowed';
?>
<p>Dear <strong><?= htmlspecialchars($full_name) ?></strong>,</p>

<?php if ($allowed): ?>
<p style="font-size:16px;font-weight:bold;color:#1E2A52;">
  Your <?= htmlspecialchars($typeWord) ?> eligibility has been approved. You may print your Entry Card in the SEMAS portal.
</p>
<?php else: ?>
<p style="font-size:16px;font-weight:bold;color:#B91C1C;">
  Your <?= htmlspecialchars($typeWord) ?> eligibility is <strong>Not Allowed</strong> due to attendance.
</p>
<?php endif; ?>

<p>
  <?= htmlspecialchars($typeWord) ?> decisions are out now!
  <strong>Check your SEMAS portal</strong> for the final eligibility result and next steps.
</p>

<table cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;font-size:14px;margin:16px 0;">
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

<?php if (!$allowed): ?>
<p style="font-weight:bold;color:#B91C1C;">
  You are <strong>Not Allowed</strong> for this <?= htmlspecialchars($typeWord) ?> due to attendance.<br>
  Please visit your <strong>Head of Department office</strong> before <?= htmlspecialchars($typeWord) ?> day (<?= htmlspecialchars($day_of_week . ', ' . $dateLabel) ?>) for further guidance.
</p>
<?php else: ?>
<p style="font-weight:bold;color:#2F9E68;">
  You are <strong>Allowed</strong> to sit this <?= htmlspecialchars($typeWord) ?>. Log in to the SEMAS portal to print your Entry Card.
</p>

<table cellpadding="0" cellspacing="0" width="100%" style="margin:20px 0;">
  <tr>
    <td style="background:#7C2D12;padding:10px 16px;border-radius:6px 6px 0 0;">
      <span style="color:#FFFFFF;font-size:13px;font-weight:bold;letter-spacing:.05em;">
        &#9888; IMPORTANT &mdash; STUDENT <?= htmlspecialchars(strtoupper($typeWord)) ?> INSTRUCTIONS
      </span>
    </td>
  </tr>
  <tr>
    <td style="background:#FFF7ED;border:2px solid #EA580C;border-top:none;border-radius:0 0 6px 6px;padding:14px 16px;">
      <table cellpadding="0" cellspacing="0" width="100%">
        <?php
        $instructions = [
            'Arrive at the examination room at least <strong>15–30 minutes before the start time</strong>.',
            'Bring your <strong>valid student card</strong> and <strong>Entry Slip</strong>.',
            'Mobile phones and all electronic devices are <strong>strictly not allowed</strong> in the examination room.',
            'Switch off all devices before entering and keep them outside the exam area as instructed.',
            'Enter the room in silence and follow seating instructions from invigilators.',
            'Any form of <strong>cheating or unauthorized communication is strictly prohibited</strong>.',
            'Read all exam instructions carefully before starting.',
            'Raise your hand if you need assistance from an invigilator.',
            'Do not leave the room without permission during the exam.',
            'Submit your work properly and wait for confirmation before leaving.',
        ];
        foreach ($instructions as $i => $text): ?>
        <tr>
          <td style="padding:5px 4px;vertical-align:top;width:28px;">
            <span style="display:inline-block;background:#EA580C;color:#fff;font-size:11px;font-weight:bold;
                         border-radius:50%;width:20px;height:20px;text-align:center;line-height:20px;">
              <?= $i + 1 ?>
            </span>
          </td>
          <td style="padding:5px 0;font-size:13px;color:#1B1F2A;line-height:1.5;">
            <?= $text ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </td>
  </tr>
</table>
<?php endif; ?>

<p>If you have any questions, please contact your Head of Department.</p>
<?php if ($allowed): ?><p>Good luck!</p><?php endif; ?>
<p><em>SEMAS Academic Team</em></p>
<?php semas_email_close(); ?>
