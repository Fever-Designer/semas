<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$sent = false;
$usedOtp = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');
    $method = $_POST['method'] ?? 'link';
    if ($method === 'otp') {
        unset($_SESSION['pending_reset_uid'], $_SESSION['reset_otp_verified']);
    }

    $db = Database::connection();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email AND status != 'Deactivated'");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        if ($method === 'otp') {
            $code = Otp::generate((int) $user['user_id'], 'password_reset', 'email');
            Mailer::sendOtp($user, $code, 'password reset');
            $_SESSION['pending_reset_uid'] = (int) $user['user_id'];
        } else {
            $rawToken = bin2hex(random_bytes(32));
            $db->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL :mins MINUTE))'
            )->execute([
                'uid'  => $user['user_id'],
                'hash' => hash('sha256', $rawToken),
                'mins' => RESET_LINK_EXPIRY_MINUTES,
            ]);
            $resetUrl = APP_URL . '/auth/reset-password.php?uid=' . $user['user_id'] . '&token=' . $rawToken;
            Mailer::sendPasswordResetLink($user, $resetUrl);
        }
    }
    $sent = true;
    $usedOtp = $method === 'otp';
}

$pageTitle = 'Forgot Password';
require __DIR__ . '/../partials/auth_top.php';
?>
    <h5 class="display-font">Forgot Password</h5>
    <?php if ($sent): ?>
      <div class="alert alert-success small">If that email exists in our system, instructions have been sent.</div>
      <?php if ($usedOtp): ?>
        <a href="<?= APP_URL ?>/auth/reset-password-otp.php" class="btn btn-semas w-100">Enter Code</a>
      <?php else: ?>
        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-dark w-100">Back to Login</a>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-muted small">Choose how you'd like to reset your password.</p>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label small">Email Address</label><input type="email" name="email" class="form-control" required></div>
        <div class="mb-3">
          <label class="form-label small d-block">Delivery Method</label>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="method" value="link" id="m1" checked>
            <label class="form-check-label small" for="m1">Email Link</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="method" value="otp" id="m2">
            <label class="form-check-label small" for="m2">6-Digit Code</label>
          </div>
        </div>
        <button class="btn btn-semas w-100">Send Reset Instructions</button>
      </form>
    <?php endif; ?>
    <p class="text-center small text-muted mt-3 mb-0"><a href="<?= APP_URL ?>/auth/login.php">Back to Login</a></p>
<?php require __DIR__ . '/../partials/auth_bottom.php'; ?>
