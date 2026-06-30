<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

// This page is only for authenticated users who must change their password.
if (!Auth::check()) {
    redirect('/auth/login.php');
}

$db = Database::connection();
$user = Auth::user();

// If they don't need to change, send them home
if (!$user || !(int) $user['must_change_password']) {
    redirect('/dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $newPass     = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (strlen($newPass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($newPass !== $confirmPass) {
        $errors[] = 'Passwords do not match.';
    } elseif ($newPass === $user['reg_number']) {
        $errors[] = 'New password cannot be the same as your registration number.';
    } else {
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $db->prepare('UPDATE users SET password_hash=:h, must_change_password=0 WHERE user_id=:id')
           ->execute(['h' => $hash, 'id' => $user['user_id']]);
        $_SESSION['must_change_password'] = false; // keep session in sync with the DB so enforceMustChangePassword() stops redirecting here
        AuditLog::record(Auth::id(), 'PASSWORD_CHANGED_FIRST_LOGIN', 'users', $user['user_id']);
        flash('success', 'Password changed successfully. Welcome to SEMAS!');
        redirect('/dashboard.php');
    }
}

$pageTitle = 'Change Your Password';
require __DIR__ . '/../partials/auth_top.php';
?>
<div class="text-center mb-4">
  <div class="alert alert-warning small text-start">
    <i class="bi bi-shield-lock-fill me-1"></i>
    <strong>Security Notice:</strong> You are currently using a default password. Please create a new password before continuing.
  </div>
</div>

<h5 class="display-font mb-1 text-center">Create New Password</h5>
<p class="text-muted small text-center mb-4">Choose a strong, unique password you haven't used before.</p>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger small"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="POST">
  <?= csrf_field() ?>
  <div class="mb-3">
    <label class="form-label small">New Password <span class="text-danger">*</span></label>
    <input type="password" name="new_password" class="form-control" required minlength="8" autofocus>
    <div class="form-text">At least 8 characters.</div>
  </div>
  <div class="mb-4">
    <label class="form-label small">Confirm New Password <span class="text-danger">*</span></label>
    <input type="password" name="confirm_password" class="form-control" required minlength="8">
  </div>
  <button type="submit" class="btn btn-semas w-100">
    <i class="bi bi-check-lg me-1"></i> Set New Password &amp; Continue
  </button>
</form>
<?php require __DIR__ . '/../partials/auth_bottom.php'; ?>
