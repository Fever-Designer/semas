<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$uid = (int) ($_GET['uid'] ?? $_POST['uid'] ?? 0);
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$validLink = false;

if ($uid && $token !== '') {
    $db = Database::connection();
    $stmt = $db->prepare(
        'SELECT * FROM password_resets WHERE user_id = :uid AND used_at IS NULL ORDER BY reset_id DESC LIMIT 1'
    );
    $stmt->execute(['uid' => $uid]);
    $row = $stmt->fetch();
    if ($row && hash_equals($row['token_hash'], hash('sha256', $token)) && strtotime($row['expires_at']) >= time()) {
        $validLink = true;
    }
}

if ($validLink && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
           ->execute(['uid' => $uid]);
        AuditLog::record($uid, 'PASSWORD_RESET');

        $userStmt = $db->prepare('SELECT * FROM users WHERE user_id = :uid');
        $userStmt->execute(['uid' => $uid]);
        $user = $userStmt->fetch();
        if ($user) {
            Mailer::sendPasswordChangedNotice($user);
        }

        flash('success', 'Your password has been reset. Please sign in.');
        redirect('/auth/login.php');
    }
}

$pageTitle = 'Reset Password';
require __DIR__ . '/../partials/auth_top.php';
?>
    <h5 class="display-font">Reset Your Password</h5>
    <?php if (!$validLink): ?>
      <p class="text-muted small">This reset link is invalid or has expired.</p>
      <a href="<?= APP_URL ?>/auth/forgot-password.php" class="btn btn-semas w-100">Request a New Link</a>
    <?php else: ?>
      <?php foreach ($errors as $err): ?><div class="alert alert-danger small"><?= e($err) ?></div><?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="uid" value="<?= (int) $uid ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="mb-3"><label class="form-label small">New Password</label><input type="password" name="password" class="form-control" required></div>
        <div class="mb-3"><label class="form-label small">Confirm New Password</label><input type="password" name="password_confirm" class="form-control" required></div>
        <button class="btn btn-semas w-100">Reset Password</button>
      </form>
    <?php endif; ?>
<?php require __DIR__ . '/../partials/auth_bottom.php'; ?>
