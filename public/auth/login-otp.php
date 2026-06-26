<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$uid = $_SESSION['pending_login_uid'] ?? null;
if (!$uid) {
    redirect('/auth/login.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $code = trim($_POST['code'] ?? '');
    $result = Otp::verify((int) $uid, 'login', $code);

    if ($result['ok']) {
        $stmt = Database::connection()->prepare(
            'SELECT u.*, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = :uid'
        );
        $stmt->execute(['uid' => $uid]);
        $user = $stmt->fetch();
        unset($_SESSION['pending_login_uid']);
        Auth::login($user);
        redirect('/dashboard.php');
    } else {
        $error = $result['error'];
    }
}

$pageTitle = 'Verify Code';
require __DIR__ . '/../partials/auth_top.php';
?>
    <h5 class="display-font">Enter Verification Code</h5>
    <p class="text-muted small">We emailed a 6-digit code to your address. It expires in a few minutes.</p>
    <?php if ($error): ?><div class="alert alert-danger small"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label small">6-Digit Code</label>
        <input name="code" class="form-control otp-input" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required>
      </div>
      <button class="btn btn-semas w-100">Verify &amp; Sign In</button>
    </form>
<?php require __DIR__ . '/../partials/auth_bottom.php'; ?>
