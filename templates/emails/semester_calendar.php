<?php require __DIR__ . '/_layout.php'; semas_email_open('Semester Calendar / ' . htmlspecialchars($semester_name)); ?>
<p>Dear <?= htmlspecialchars($full_name) ?>,</p>
<p>The Registrar's Office has published the academic calendar for your intake cohort (<strong><?= htmlspecialchars($intake) ?></strong>). Please take note of the following dates:</p>

<table style="width:100%;border-collapse:collapse;margin:16px 0;">
  <tr>
    <td style="padding:10px 14px;background:#F4F6FB;font-weight:bold;width:40%;border-bottom:1px solid #e2e8f0;">Academic Year</td>
    <td style="padding:10px 14px;background:#F4F6FB;border-bottom:1px solid #e2e8f0;"><?= htmlspecialchars($academic_year) ?></td>
  </tr>
  <tr>
    <td style="padding:10px 14px;font-weight:bold;border-bottom:1px solid #e2e8f0;">Semester</td>
    <td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;"><?= htmlspecialchars($semester_name) ?></td>
  </tr>
  <tr>
    <td style="padding:10px 14px;background:#F4F6FB;font-weight:bold;border-bottom:1px solid #e2e8f0;">Intake Cohort</td>
    <td style="padding:10px 14px;background:#F4F6FB;border-bottom:1px solid #e2e8f0;"><?= htmlspecialchars($intake) ?></td>
  </tr>
  <tr>
    <td style="padding:10px 14px;font-weight:bold;border-bottom:1px solid #e2e8f0;">Semester Starts</td>
    <td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;"><strong style="color:#1E2A52;"><?= htmlspecialchars(date('l, d F Y', strtotime($start_date))) ?></strong></td>
  </tr>
  <tr>
    <td style="padding:10px 14px;background:#F4F6FB;font-weight:bold;">Semester Ends</td>
    <td style="padding:10px 14px;background:#F4F6FB;"><strong style="color:#1E2A52;"><?= htmlspecialchars(date('l, d F Y', strtotime($end_date))) ?></strong></td>
  </tr>
</table>

<?php if (!empty($notes)): ?>
<p style="padding:12px;background:#fffbeb;border-left:4px solid #D4A24C;margin:16px 0;">
  <strong>Notes from the Registrar:</strong><br><?= nl2br(htmlspecialchars($notes)) ?>
</p>
<?php endif; ?>

<p>Please ensure you complete your module registration before the semester begins. Log in to SEMAS to register for your modules.</p>
<p><a href="<?= htmlspecialchars($login_url) ?>" style="display:inline-block;background:#1E2A52;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;">Log In to SEMAS</a></p>
<p>Best regards,<br>Registrar's Office<br>University of Kigali</p>
<?php semas_email_close(); ?>
