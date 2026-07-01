<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

// Student self-registration is disabled. Accounts are created by the Registrar.
flash('error', 'Student self-registration is not available. Please contact the Registrar office for your login credentials.');
redirect('/auth/login.php');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $regNumber = trim($_POST['reg_number'] ?? '');
    $phone = trim($_POST['phone_number'] ?? '');
    $departmentId = (int) ($_POST['department_id'] ?? 0);
    $yearOfStudy = (int) ($_POST['year_of_study'] ?? 0) ?: null;
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($fullName === '' || $email === '' || $password === '') {
        $errors[] = 'Full name, email, and password are required.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'Passwords do not match.';
    }
    if ($phone !== '' && !preg_match('/^\d{10}$/', $phone)) {
        $errors[] = 'Phone number must be exactly 10 digits.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    if (!$errors) {
        $db = Database::connection();
        $exists = $db->prepare('SELECT user_id FROM users WHERE email = :email');
        $exists->execute(['email' => $email]);
        if ($exists->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (!$errors) {
        $db = Database::connection();
        $studentRoleId = (int) $db->query("SELECT role_id FROM roles WHERE role_name = 'Student'")->fetchColumn();

        $db->prepare(
            'INSERT INTO users (role_id, department_id, reg_number, full_name, email, phone_number, password_hash, status, year_of_study)
             VALUES (:role_id, :dept, :reg, :name, :email, :phone, :hash, :status, :year)'
        )->execute([
            'role_id' => $studentRoleId,
            'dept'    => $departmentId ?: null,
            'reg'     => $regNumber ?: null,
            'name'    => $fullName,
            'email'   => $email,
            'phone'   => $phone ?: null,
            'hash'    => password_hash($password, PASSWORD_BCRYPT),
            'status'  => 'Pending',
            'year'    => $yearOfStudy,
        ]);
        $userId = (int) $db->lastInsertId();
        AuditLog::record($userId, 'REGISTER');

        $rawToken = bin2hex(random_bytes(32));
        $db->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at)
             VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL :hrs HOUR))'
        )->execute([
            'uid'  => $userId,
            'hash' => hash('sha256', $rawToken),
            'hrs'  => VERIFY_LINK_EXPIRY_HOURS,
        ]);

        $verifyUrl = APP_URL . '/auth/verify-email.php?uid=' . $userId . '&token=' . $rawToken;
        $userRow = ['user_id' => $userId, 'full_name' => $fullName, 'email' => $email];
        Mailer::sendVerification($userRow, $verifyUrl);
        Mailer::sendRegistrationConfirmation($userRow);

        flash('success', 'Account created. Please check your email to verify your account before logging in.');
        redirect('/auth/login.php');
    }
}

$departments = Database::connection()->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll();
$pageTitle = 'Register';
require __DIR__ . '/../partials/auth_top.php';
?>
    <?php foreach ($errors as $err): ?><div class="alert alert-danger small"><?= e($err) ?></div><?php endforeach; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label small">Full Name</label><input name="full_name" class="form-control" required value="<?= e($_POST['full_name'] ?? '') ?>"></div>
      <div class="mb-3"><label class="form-label small">Email Address</label><input type="email" name="email" class="form-control" required value="<?= e($_POST['email'] ?? '') ?>"></div>
      <div class="mb-3"><label class="form-label small">Registration Number</label><input name="reg_number" class="form-control" value="<?= e($_POST['reg_number'] ?? '') ?>"></div>
      <div class="mb-3"><label class="form-label small">Phone Number (for SMS notifications)</label><input name="phone_number" class="form-control" inputmode="numeric" pattern="\d{10}" maxlength="10" value="<?= e($_POST['phone_number'] ?? '') ?>"></div>
      <div class="mb-3">
        <label class="form-label small">Department</label>
        <select name="department_id" class="form-select">
          <option value="">Select department</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label small">Year of Study</label>
        <select name="year_of_study" class="form-select">
          <option value="">Select year</option>
          <?php for ($y = 1; $y <= 6; $y++): ?><option value="<?= $y ?>">Year <?= $y ?></option><?php endfor; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label small">Password</label><input type="password" name="password" class="form-control" required></div>
      <div class="mb-3"><label class="form-label small">Confirm Password</label><input type="password" name="password_confirm" class="form-control" required></div>
      <button class="btn btn-semas w-100">Create Account</button>
    </form>
    <p class="text-center small text-muted mt-3 mb-0">Already have an account? <a href="<?= APP_URL ?>/auth/login.php">Sign in</a></p>
<?php require __DIR__ . '/../partials/auth_bottom.php'; ?>
