<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

if (Auth::check()) {
    redirect('/dashboard.php');
}

$errors = [];
const OTP_LOGIN_ENABLED = false; // flip to true to require OTP on every login

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'password') {
    csrf_verify();
    $email    = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $result   = Auth::attempt($email, $password);

    if (!$result['ok']) {
        $errors[] = $result['error'];
    } elseif (OTP_LOGIN_ENABLED) {
        $code = Otp::generate((int) $result['user']['user_id'], 'login', 'email');
        Mailer::sendOtp($result['user'], $code, 'login verification');
        $_SESSION['pending_login_uid'] = (int) $result['user']['user_id'];
        redirect('/auth/login-otp.php');
    } else {
        Auth::login($result['user']);
        redirect('/dashboard.php');
    }
}

$pageTitle  = 'Login';
$successMsg = flash('success');
require __DIR__ . '/../partials/auth_top.php';
?>
<?php if ($successMsg): ?><div class="alert alert-success small"><?= e($successMsg) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger small"><?= e($err) ?></div><?php endforeach; ?>

<form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="step" value="password">
  <div class="mb-3">
    <label class="form-label small">Username / Email</label>
    <input type="text" name="email" class="form-control" required autofocus>
  </div>
  <div class="mb-3">
    <label class="form-label small">Password</label>
    <div class="input-group">
      <input type="password" name="password" id="password" class="form-control" required>
      <button type="button" class="btn btn-outline-secondary" id="togglePassword" tabindex="-1" aria-label="Show password">
        <i class="bi bi-eye"></i>
      </button>
    </div>
  </div>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <a href="<?= APP_URL ?>/auth/forgot-password.php" class="small">Forgot password?</a>
  </div>
  <button type="submit" class="btn btn-semas w-100">Log In</button>
</form>
<script>
document.getElementById('togglePassword').addEventListener('click', function () {
  const input = document.getElementById('password');
  const icon  = this.querySelector('i');
  const show  = input.type === 'password';
  input.type = show ? 'text' : 'password';
  icon.classList.toggle('bi-eye', !show);
  icon.classList.toggle('bi-eye-slash', show);
  this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});
</script>
<?php require __DIR__ . '/../partials/auth_bottom.php'; ?>
