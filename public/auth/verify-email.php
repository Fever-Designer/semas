<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$uid = (int) ($_GET['uid'] ?? 0);
$token = (string) ($_GET['token'] ?? '');
$status = 'invalid';

if ($uid && $token !== '') {
    $db = Database::connection();
    $stmt = $db->prepare(
        'SELECT * FROM email_verifications
         WHERE user_id = :uid AND verified_at IS NULL
         ORDER BY verification_id DESC LIMIT 1'
    );
    $stmt->execute(['uid' => $uid]);
    $row = $stmt->fetch();

    if ($row && hash_equals($row['token_hash'], hash('sha256', $token))) {
        if (strtotime($row['expires_at']) < time()) {
            $status = 'expired';
        } else {
            $db->prepare('UPDATE email_verifications SET verified_at = NOW() WHERE verification_id = :id')
               ->execute(['id' => $row['verification_id']]);
            $db->prepare("UPDATE users SET status = 'Active', email_verified_at = NOW() WHERE user_id = :uid")
               ->execute(['uid' => $uid]);
            AuditLog::record($uid, 'EMAIL_VERIFIED');

            $userStmt = $db->prepare('SELECT * FROM users WHERE user_id = :uid');
            $userStmt->execute(['uid' => $uid]);
            $user = $userStmt->fetch();
            if ($user) {
                Mailer::sendAccountActivated($user);
            }
            $status = 'verified';
        }
    }
}

$pageTitle = 'Email Verification';
require __DIR__ . '/../partials/auth_top.php';
?>
    <div class="text-center">
      <?php if ($status === 'verified'): ?>
        <i class="bi bi-check-circle-fill" style="font-size:3rem;color:var(--semas-success);"></i>
        <h5 class="display-font mt-3">Email Verified</h5>
        <p class="text-muted small">Your account is now active. You can log in.</p>
        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-semas">Go to Login</a>
      <?php elseif ($status === 'expired'): ?>
        <h5 class="display-font">Link Expired</h5>
        <p class="text-muted small">This verification link has expired. Please request a new one.</p>
        <a href="<?= APP_URL ?>/auth/resend-verification.php" class="btn btn-semas">Resend Verification Email</a>
      <?php else: ?>
        <h5 class="display-font">Invalid Link</h5>
        <p class="text-muted small">This verification link is invalid or has already been used.</p>
        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-dark">Back to Login</a>
      <?php endif; ?>
    </div>
<?php require __DIR__ . '/../partials/auth_bottom.php'; ?>
