<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$uid = $_SESSION['pending_reset_uid'] ?? null;
if (!$uid) {
    redirect('/auth/forgot-password.php');
}

$errors = [];
$codeVerified = $_SESSION['reset_otp_verified'] ?? false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$codeVerified) {
    csrf_verify();
    $code = trim($_POST['code'] ?? '');
    $result = Otp::verify((int) $uid, 'password_reset', $code);
    if ($result['ok']) {
        $_SESSION['reset_otp_verified'] = true;
        $codeVerified = true;
    } else {
        $errors[] = $result['error'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $codeVerified) {
    csrf_verify();
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$errors) {
        $db = Database::connection();
        $db->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :uid')
           ->execute(['hash' => password_hash($password, PASSWORD_BCRYPT), 'uid' => $uid]);
        AuditLog::record((int) $uid, 'PASSWORD_RESET');

        $userStmt = $db->prepare('SELECT * FROM users WHERE user_id = :uid');
        $userStmt->execute(['uid' => $uid]);
        $user = $userStmt->fetch();
        if ($user) {
            Mailer::sendPasswordChangedNotice($user);
        }

        unset($_SESSION['pending_reset_uid'], $_SESSION['reset_otp_verified']);
        flash('success', 'Your password has been reset. Please sign in.');
        redirect('/auth/login.php');
    }
}

$pageTitle = 'Reset Password';
require __DIR__ . '/../partials/auth_top.php';
?>
    <h5 class="display-font"><?= $codeVerified ? 'Choose a New Password' : 'Enter Your Code' ?></h5>
    <?php foreach ($errors as $err): ?><div class="alert alert-danger small"><?= e($err) ?></div><?php endforeach; ?>
    <?php if (!$codeVerified): ?>
      <p class="text-muted small">Enter the 6-digit code emailed to you.</p>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><input name="code" class="form-control otp-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div>
        <button class="btn btn-semas w-100">Verify Code</button>
      </form>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label small">New Password</label><input type="password" name="password" class="form-control" required></div>
        <div class="mb-3"><label class="form-label small">Confirm New Password</label><input type="password" name="password_confirm" class="form-control" required></div>
        <button class="btn btn-semas w-100">Reset Password</button>
      </form>
    <?php endif; ?>
<?php require __DIR__ . '/../partials/auth_bottom.php'; ?>
