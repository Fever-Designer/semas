<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$result = null;
$errorDetail = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $to = trim($_POST['to_email'] ?? '');

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result = false;
        $errorDetail = 'Please enter a valid email address to send the test to.';
    } else {
        $debugOutput = '';
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_USERNAME;
            $mail->Password   = MAIL_PASSWORD;
            $mail->SMTPSecure = MAIL_ENCRYPTION === 'ssl'
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = MAIL_PORT;
            $mail->SMTPDebug  = 2;
            $mail->Debugoutput = function ($str) use (&$debugOutput) { $debugOutput .= $str . "\n"; };

            $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = 'SEMAS test email - ' . date('Y-m-d H:i:s');
            $mail->Body = '<p>This is a real test email sent from your SEMAS installation via '
                        . htmlspecialchars(MAIL_HOST) . '.</p><p>If you received this, SMTP sending is working.</p>';

            $mail->send();
            $result = true;
        } catch (Throwable $e) {
            $result = false;
            $errorDetail = $e->getMessage() . "\n\n" . $debugOutput;
        }
    }
}

$pageTitle = 'SMTP Test';
require __DIR__ . '/partials/auth_top.php';
?>
    <h5 class="display-font">SMTP Test</h5>
    <p class="text-muted small">Sends one real email using the settings currently in <code>.env</code>
      (host: <?= e(MAIL_HOST) ?>, user: <?= e(MAIL_USERNAME) ?>).</p>

    <?php if ($result === true): ?>
      <div class="alert alert-success small">Sent successfully. Check the inbox (and spam folder) of the address you entered.</div>
    <?php elseif ($result === false): ?>
      <div class="alert alert-danger small" style="white-space:pre-wrap;font-family:monospace;font-size:0.72rem;max-height:240px;overflow:auto;"><?= e((string) $errorDetail) ?></div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label small">Send test email to</label><input type="email" name="to_email" class="form-control" required value="<?= e($_POST['to_email'] ?? MAIL_USERNAME) ?>"></div>
      <button class="btn btn-semas w-100">Send Test Email</button>
    </form>
    <p class="text-center small text-muted mt-3 mb-0">Delete this file (public/test-email.php) once email is confirmed working.</p>
<?php require __DIR__ . '/partials/auth_bottom.php'; ?>
