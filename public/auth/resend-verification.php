<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');
    $db = Database::connection();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email AND status = 'Pending'");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        $rawToken = bin2hex(random_bytes(32));
        $db->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at)
             VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL :hrs HOUR))'
        )->execute([
            'uid'  => $user['user_id'],
            'hash' => hash('sha256', $rawToken),
            'hrs'  => VERIFY_LINK_EXPIRY_HOURS,
        ]);
        $verifyUrl = APP_URL . '/auth/verify-email.php?uid=' . $user['user_id'] . '&token=' . $rawToken;
        Mailer::sendVerification($user, $verifyUrl);
    }
    $sent = true; // same response either way / do not reveal whether the email exists or is verified
}

$pageTitle = 'Resend Verification';
require __DIR__ . '/../partials/auth_top.php';
?>
    <?php if ($sent): ?>
      <div class="alert alert-success small">If an unverified account exists for that address, a new verification email has been sent.</div>
      <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-dark w-100">Back to Login</a>
    <?php else: ?>
      <p class="text-muted small">Enter the email you registered with.</p>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label small">Email Address</label><input type="email" name="email" class="form-control" required></div>
        <button class="btn btn-semas w-100">Send Verification Email</button>
      </form>
      <p class="text-center small text-muted mt-3 mb-0"><a href="<?= APP_URL ?>/auth/login.php">Back to Login</a></p>
    <?php endif; ?>
<?php require __DIR__ . '/../partials/auth_bottom.php'; ?>
