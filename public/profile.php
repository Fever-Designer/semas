<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireRole(['Principal', 'Dean', 'HOD', 'Student', 'Lecturer', 'Registrar', 'Coordinator']);

$pageTitle = 'My Profile';
$db = Database::connection();
$userId = Auth::id();

$stmt = $db->prepare(
    "SELECT u.*, r.role_name, d.department_name, f.faculty_name
     FROM users u
     JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN departments d ON d.department_id = u.department_id
     LEFT JOIN faculties f ON f.faculty_id = d.faculty_id
     WHERE u.user_id = :id"
);
$stmt->execute(['id' => $userId]);
$me = $stmt->fetch();

$errors = [];
$readOnlyContactRoles = ['Student', 'Lecturer', 'HOD', 'Coordinator', 'Dean'];

// ---- Update phone / session (own data only / no role/department/status fields here) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'contact') {
    csrf_verify();
    if (in_array($me['role_name'], $readOnlyContactRoles, true)) {
        flash('error', role_display_name($me['role_name']) . ' contact details are read-only.');
        redirect('/profile.php');
    }
    $phone = trim($_POST['phone_number'] ?? '');
    if ($phone !== '' && !preg_match('/^\d{10}$/', $phone)) {
        flash('error', 'Phone number must be exactly 10 digits.');
        redirect('/profile.php');
    }
    $db->prepare('UPDATE users SET phone_number = :phone WHERE user_id = :id')
       ->execute(['phone' => $phone ?: null, 'id' => $userId]);
    AuditLog::record($userId, 'PROFILE_UPDATE_CONTACT');
    flash('success', 'Contact details updated.');
    redirect('/profile.php');
}

// ---- Change password (requires current password) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    csrf_verify();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['new_password_confirm'] ?? '');

    if (!password_verify($current, $me['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters long.';
    } elseif ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
    }

    if (!$errors) {
        $db->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id')
           ->execute(['hash' => password_hash($new, PASSWORD_BCRYPT), 'id' => $userId]);
        AuditLog::record($userId, 'PROFILE_PASSWORD_CHANGE');
        Mailer::sendPasswordChangedNotice($me);
        NotificationCenter::notify($userId, 'Password changed', 'Your password was changed successfully.', 'System');
        flash('success', 'Password updated.');
        redirect('/profile.php');
    }
}

// ---- Request email change (sends a verification link to the NEW address; live email is untouched until confirmed) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'email') {
    csrf_verify();
    if ($me['role_name'] === 'Student') {
        flash('error', 'Students cannot change their email address. Please contact the Registrar Office.');
        redirect('/profile.php');
    }
    $newEmail = trim($_POST['new_email'] ?? '');

    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $exists = $db->prepare('SELECT user_id FROM users WHERE email = :email AND user_id != :id');
        $exists->execute(['email' => $newEmail, 'id' => $userId]);
        if ($exists->fetch()) {
            $errors[] = 'That email address is already in use by another account.';
        }
    }

    if (!$errors) {
        $rawToken = bin2hex(random_bytes(32));
        $db->prepare(
            'INSERT INTO email_change_requests (user_id, new_email, token_hash, expires_at)
             VALUES (:uid, :email, :hash, DATE_ADD(NOW(), INTERVAL :hrs HOUR))'
        )->execute([
            'uid' => $userId, 'email' => $newEmail, 'hash' => hash('sha256', $rawToken), 'hrs' => VERIFY_LINK_EXPIRY_HOURS,
        ]);
        $confirmUrl = APP_URL . '/auth/confirm-email-change.php?uid=' . $userId . '&token=' . $rawToken;
        // Sent to the NEW address / proves the user actually controls it before we switch.
        Mailer::send($newEmail, 'Confirm your new SEMAS email address', 'verification', [
            'full_name' => $me['full_name'], 'verify_url' => $confirmUrl,
        ], $userId);
        AuditLog::record($userId, 'PROFILE_EMAIL_CHANGE_REQUESTED', 'users', $userId, "new_email=$newEmail");
        flash('success', "A confirmation link was sent to $newEmail. Your current login email stays active until you click it.");
        redirect('/profile.php');
    }
}

// ---- Photo upload ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'photo' && isset($_FILES['photo'])) {
    csrf_verify();
    $file = $_FILES['photo'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload failed. Please try again.';
    } elseif ($file['size'] > 2 * 1024 * 1024) {
        $errors[] = 'Photo must be smaller than 2MB.';
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed[$mime])) {
            $errors[] = 'Photo must be a JPEG, PNG, or WebP image.';
        } else {
            $filename = 'user' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
            $destDir = __DIR__ . '/uploads/profile_photos/';
            if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                if ($me['photo_path'] && file_exists($destDir . basename($me['photo_path']))) {
                    @unlink($destDir . basename($me['photo_path']));
                }
                $db->prepare('UPDATE users SET photo_path = :path WHERE user_id = :id')
                   ->execute(['path' => 'uploads/profile_photos/' . $filename, 'id' => $userId]);
                AuditLog::record($userId, 'PROFILE_PHOTO_UPDATE');
                flash('success', 'Profile photo updated.');
                redirect('/profile.php');
            } else {
                $errors[] = 'Could not save the uploaded photo.';
            }
        }
    }
}

$activeNav = 'dashboard';
require __DIR__ . '/partials/layout_top.php';
?>
<style>
  .profile-grid > [class*="col-"] { min-width:0; }
  .profile-card { overflow:hidden; }
  .profile-card,
  .profile-card * { min-width:0; }
  .profile-value,
  .profile-help,
  .profile-card strong {
    overflow-wrap:anywhere;
    word-break:break-word;
  }
  .profile-info {
    margin:0;
    border:1px solid var(--semas-border);
  }
  .profile-info-row {
    display:grid;
    grid-template-columns:minmax(105px, 36%) minmax(0, 1fr);
    border-bottom:1px solid var(--semas-border);
  }
  .profile-info-row:last-child { border-bottom:0; }
  .profile-info dt,
  .profile-info dd {
    margin:0;
    padding:.65rem .75rem;
    font-size:.82rem;
  }
  .profile-info dt {
    background:#f8f9fb;
    border-right:1px solid var(--semas-border);
    font-weight:600;
  }
  .profile-info dd { text-align:right; }
  @media (max-width:575.98px) {
    .profile-info-row { grid-template-columns:1fr; }
    .profile-info dt {
      border-right:0;
      border-bottom:1px solid var(--semas-border);
      padding-bottom:.35rem;
    }
    .profile-info dd {
      text-align:left;
      padding-top:.45rem;
    }
  }
</style>
<h4 class="display-font mb-1">My Profile</h4>

<?php foreach ($errors as $err): ?><div class="alert alert-danger small"><?= e($err) ?></div><?php endforeach; ?>

<div class="row g-3 profile-grid">
  <div class="col-lg-4">
    <div class="semas-card profile-card p-3 text-center">
      <img src="<?= $me['photo_path'] ? APP_URL . '/' . e($me['photo_path']) : 'https://ui-avatars.com/api/?name=' . urlencode($me['full_name']) . '&background=1E2A52&color=fff' ?>"
           alt="Profile photo" class="rounded-circle mb-3" style="width:120px;height:120px;object-fit:cover;border:3px solid var(--semas-gold);">
      <h6 class="display-font mb-0"><?= e($me['full_name']) ?></h6>
      <div class="text-muted small mb-3"><?= e(role_display_name($me['role_name'])) ?></div>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="photo">
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm mb-2" required>
        <button class="btn btn-sm btn-semas-gold w-100">Update Photo</button>
      </form>
    </div>

    <div class="semas-card profile-card p-3 mt-3">
      <h6 class="display-font mb-3">Account Info</h6>
      <dl class="profile-info">
        <?php if (!in_array($me['role_name'], ['Principal', 'Dean', 'Registrar'], true)): ?>
        <div class="profile-info-row"><dt>Department</dt><dd class="profile-value"><?= e($me['department_name'] ?? '/') ?></dd></div>
        <div class="profile-info-row"><dt>Faculty</dt><dd class="profile-value"><?= e($me['faculty_name'] ?? '/') ?></dd></div>
        <?php endif; ?>
        <?php if ($me['role_name'] === 'Student'): ?>
        <div class="profile-info-row"><dt>Reg. Number</dt><dd class="profile-value"><?= e($me['reg_number'] ?? '/') ?></dd></div>
        <?php endif; ?>
        <div class="profile-info-row"><dt>Status</dt><dd><span class="badge badge-<?= $me['status'] === 'Active' ? 'completed' : 'cancelled' ?>"><?= e($me['status']) ?></span></dd></div>
        <div class="profile-info-row"><dt>Last Login</dt><dd class="profile-value"><?= e($me['last_login_at'] ?? 'Never') ?></dd></div>
      </dl>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="semas-card profile-card p-3 mb-3">
      <h6 class="display-font mb-3">Contact Details</h6>
      <?php if (in_array($me['role_name'], $readOnlyContactRoles, true)): ?>
      <dl class="profile-info">
        <div class="profile-info-row"><dt>Phone Number</dt><dd class="profile-value"><?= e($me['phone_number'] ?? 'Not set') ?></dd></div>
        <?php if ($me['role_name'] === 'Student'): ?>
        <div class="profile-info-row"><dt>Session</dt><dd class="profile-value"><?= e($me['session_type'] ?? 'Not set') ?></dd></div>
        <div class="profile-info-row"><dt>Email Address</dt><dd class="profile-value"><?= e($me['email']) ?></dd></div>
        <?php endif; ?>
      </dl>
      <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="contact">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label small">Phone Number</label><input name="phone_number" class="form-control" value="<?= e($me['phone_number'] ?? '') ?>" inputmode="numeric" pattern="\d{10}" maxlength="10"></div>
        </div>
        <button class="btn btn-semas mt-3">Save Contact Details</button>
      </form>
      <?php endif; ?>
    </div>

    <?php if ($me['role_name'] !== 'Student'): ?>
    <div class="semas-card profile-card p-3 mb-3">
      <h6 class="display-font mb-3">Change Email Address</h6>
      <p class="text-muted small profile-help">Current: <strong><?= e($me['email']) ?></strong></p>
      <form method="post" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="email">
        <div class="col-md"><label class="form-label small">New Email Address</label><input type="email" name="new_email" class="form-control w-100" required></div>
        <div class="col-md-auto"><button class="btn btn-semas-gold w-100">Send Confirmation</button></div>
      </form>
    </div>
    <?php endif; ?>

    <div class="semas-card profile-card p-3">
      <h6 class="display-font mb-3">Change Password</h6>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="password">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label small">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
          <div class="col-md-4"><label class="form-label small">New Password</label><input type="password" name="new_password" class="form-control" required></div>
          <div class="col-md-4"><label class="form-label small">Confirm New Password</label><input type="password" name="new_password_confirm" class="form-control" required></div>
        </div>
        <button class="btn btn-semas mt-3">Change Password</button>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/layout_bottom.php'; ?>
