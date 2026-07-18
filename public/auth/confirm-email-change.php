<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$uid = (int) ($_GET['uid'] ?? 0);
$token = (string) ($_GET['token'] ?? '');
$status = 'invalid';
$newEmail = null;

if ($uid && $token !== '') {
    $db = Database::connection();
    $stmt = $db->prepare(
        'SELECT * FROM email_change_requests WHERE user_id = :uid AND confirmed_at IS NULL ORDER BY request_id DESC LIMIT 1'
    );
    $stmt->execute(['uid' => $uid]);
    $row = $stmt->fetch();

    if ($row && hash_equals($row['token_hash'], hash('sha256', $token))) {
        if (strtotime($row['expires_at']) < time()) {
            $status = 'expired';
        } else {
            $exists = $db->prepare('SELECT user_id FROM users WHERE email = :email AND user_id != :uid');
            $exists->execute(['email' => $row['new_email'], 'uid' => $uid]);
            if ($exists->fetch()) {
                $status = 'taken';
            } else {
                $db->prepare('UPDATE users SET email = :email WHERE user_id = :uid')
                   ->execute(['email' => $row['new_email'], 'uid' => $uid]);
                $db->prepare('UPDATE email_change_requests SET confirmed_at = NOW() WHERE request_id = :id')
                   ->execute(['id' => $row['request_id']]);
                AuditLog::record($uid, 'EMAIL_CHANGED', 'users', $uid, "new_email={$row['new_email']}");
                NotificationCenter::notify($uid, 'Email address updated', 'Your login email was changed to ' . $row['new_email'] . '.', 'System');
                $changedUserStmt = $db->prepare('SELECT user_id, full_name FROM users WHERE user_id = :uid');
                $changedUserStmt->execute(['uid' => $uid]);
                $changedUser = $changedUserStmt->fetch();
                if ($changedUser) {
                    Mailer::sendEmailChangedNotice($changedUser, (string) $row['new_email']);
                }
                $status = 'confirmed';
                $newEmail = $row['new_email'];
            }
        }
    }
}

$pageTitle = 'Confirm Email Change';
require __DIR__ . '/../partials/auth_top.php';
?>
    <div class="text-center">
      <?php if ($status === 'confirmed'): ?>
        <i class="bi bi-check-circle-fill" style="font-size:3rem;color:var(--semas-success);"></i>
        <h5 class="display-font mt-3">Email Updated</h5>
        <p class="text-muted small">Your login email is now <strong><?= e($newEmail) ?></strong>.</p>
        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-semas">Go to Login</a>
      <?php elseif ($status === 'expired'): ?>
        <h5 class="display-font">Link Expired</h5>
        <p class="text-muted small">This confirmation link has expired. Please request the email change again from your profile.</p>
      <?php elseif ($status === 'taken'): ?>
        <h5 class="display-font">Email Already In Use</h5>
        <p class="text-muted small">That email address was registered to another account in the meantime.</p>
      <?php else: ?>
        <h5 class="display-font">Invalid Link</h5>
        <p class="text-muted small">This confirmation link is invalid or has already been used.</p>
      <?php endif; ?>
    </div>
<?php require __DIR__ . '/../partials/auth_bottom.php'; ?>
