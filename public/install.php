<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$db = Database::connection();
$existingAdminCount = (int) $db->query(
    "SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Administrator'"
)->fetchColumn();

$done = false;
$error = null;

if ($existingAdminCount > 0) {
    $error = 'An Administrator account already exists. For security, delete this file (public/install.php) now.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($fullName === '' || $email === '' || strlen($password) < 8) {
        $error = 'Please provide a full name, a valid email, and a password of at least 8 characters.';
    } else {
        $roleId = (int) $db->query("SELECT role_id FROM roles WHERE role_name='Administrator'")->fetchColumn();
        $db->prepare(
            'INSERT INTO users (role_id, full_name, email, password_hash, status, email_verified_at)
             VALUES (:rid, :name, :email, :hash, :status, NOW())'
        )->execute([
            'rid'    => $roleId,
            'name'   => $fullName,
            'email'  => $email,
            'hash'   => password_hash($password, PASSWORD_BCRYPT),
            'status' => 'Active',
        ]);
        $done = true;
    }
}

$pageTitle = 'First-Time Setup';
require __DIR__ . '/partials/auth_top.php';
?>
    <h5 class="display-font">SEMAS First-Time Setup</h5>
    <?php if ($done): ?>
      <div class="alert alert-success small">
        Administrator account created. <strong>Now delete public/install.php from the server</strong> —
        leaving it in place is a security risk even though it refuses to run again.
      </div>
      <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-semas w-100">Go to Login</a>
    <?php elseif ($error): ?>
      <div class="alert alert-danger small"><?= e($error) ?></div>
    <?php else: ?>
      <p class="text-muted small">Create the first Administrator account for this installation.</p>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label small">Full Name</label><input name="full_name" class="form-control" required></div>
        <div class="mb-3"><label class="form-label small">Email</label><input type="email" name="email" class="form-control" required></div>
        <div class="mb-3"><label class="form-label small">Password</label><input type="password" name="password" class="form-control" required minlength="8"></div>
        <button class="btn btn-semas w-100">Create Administrator Account</button>
      </form>
    <?php endif; ?>
<?php require __DIR__ . '/partials/auth_bottom.php'; ?>
