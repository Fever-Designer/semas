<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal', 'Dean', 'HOD', 'Registrar']);

$pageTitle = 'Users & Roles';
$activeNav = 'users';
$db = Database::connection();
$me = Auth::user();
$myRole = Auth::role();

// ---- Scope resolution: Principal sees everyone; Dean is university-wide
//      and may see/manage every STUDENT account (faculty no longer restricts
//      a Dean — see migration_004.sql); HOD sees only the STUDENTS in their
//      own department, plus (per spec) every Dean account for view/activate/
//      deactivate/reset-password. Restricting by role_name here — not just
//      department_id — is what stops a Dean from ever touching an HOD/Dean/
//      Principal account, and stops an HOD from touching anything
//      outside their own department other than Deans. ----
$scopeDeptIds = null; // null = unrestricted (Principal — but still excludes Students, see below)
$hodCanSeeDeans = false;
$deanUniversityWide = false;
$registrarMode = false;
if ($myRole === 'HOD') {
    $scopeDeptIds = [(int) $me['department_id']];
    $hodCanSeeDeans = true;
} elseif ($myRole === 'Dean') {
    $deanUniversityWide = true;
} elseif ($myRole === 'Registrar') {
    // Registrar: redirect to dedicated students page for student management
    redirect('/registrar/students.php');
}

/** Mirrors the same role+department rule used in the listing query below, for POST actions. */
function user_in_scope(PDO $db, int $userId, string $myRole, ?array $scopeDeptIds, bool $hodCanSeeDeans, bool $deanUniversityWide): bool
{
    if ($scopeDeptIds === null && !$deanUniversityWide) return true; // Principal
    $stmt = $db->prepare('SELECT u.department_id, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = :id');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) return false;

    if ($deanUniversityWide) {
        return $row['role_name'] === 'Student'; // Dean: any student, university-wide; never staff accounts
    }
    if ($hodCanSeeDeans && $row['role_name'] === 'Dean') {
        return true; // HOD may manage any Dean account, per spec
    }
    // HOD may otherwise only touch Student accounts within their own department.
    return $row['role_name'] === 'Student' && in_array((int) $row['department_id'], $scopeDeptIds ?? [], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_staff') {
    csrf_verify();

    $newRole = $_POST['new_role'] ?? '';
    $canCreate = ($myRole === 'Principal' && in_array($newRole, ['HOD', 'Dean', 'Lecturer', 'Registrar', 'Coordinator'], true))
              || ($myRole === 'HOD' && $newRole === 'Dean');

    if (!$canCreate) {
        flash('error', 'You are not permitted to create that type of account.');
        redirect('/admin/users.php');
    }

    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone_number'] ?? '') ?: null;

    if ($fullName === '' || $email === '') {
        flash('error', 'Full name and email are required.');
        redirect('/admin/users.php');
    }
    $exists = $db->prepare('SELECT user_id FROM users WHERE email = :email');
    $exists->execute(['email' => $email]);
    if ($exists->fetch()) {
        flash('error', 'An account with this email already exists.');
        redirect('/admin/users.php');
    }

    $roleStmt = $db->prepare('SELECT role_id FROM roles WHERE role_name = :rn');
    $roleStmt->execute(['rn' => $newRole]);
    $roleId = (int) $roleStmt->fetchColumn();
    $tempPassword = bin2hex(random_bytes(5));
    $deptIdForNewUser = null;

    if ($newRole === 'HOD' || $newRole === 'Lecturer') {
        $deptIdForNewUser = (int) ($_POST['target_department_id'] ?? 0) ?: null;
    }

    $db->prepare(
        'INSERT INTO users (role_id, department_id, full_name, email, phone_number, password_hash, status, email_verified_at, created_by)
         VALUES (:role_id, :dept, :name, :email, :phone, :hash, :status, NOW(), :created_by)'
    )->execute([
        'role_id' => $roleId,
        'dept'    => $deptIdForNewUser,
        'name'    => $fullName,
        'email'   => $email,
        'phone'   => $phone,
        'hash'    => password_hash($tempPassword, PASSWORD_BCRYPT),
        'status'  => 'Active', // staff accounts created by Admin/HOD are pre-verified; only self-registered students go through email verification
        'created_by' => Auth::id(),
    ]);
    $newUserId = (int) $db->lastInsertId();

    if ($newRole === 'HOD' && $deptIdForNewUser) {
        $db->prepare('UPDATE departments SET hod_user_id = :uid WHERE department_id = :dept')
           ->execute(['uid' => $newUserId, 'dept' => $deptIdForNewUser]);
    } elseif ($newRole === 'Lecturer') {
        $db->prepare('INSERT INTO lecturers (user_id, department_id, title, specialization) VALUES (:uid, :dept, :title, :spec)')
           ->execute([
               'uid' => $newUserId, 'dept' => $deptIdForNewUser,
               'title' => trim($_POST['lecturer_title'] ?? '') ?: null,
               'spec' => trim($_POST['lecturer_specialization'] ?? '') ?: null,
           ]);
    } elseif ($newRole === 'Dean') {
        $targetFacultyId = (int) ($_POST['target_faculty_id'] ?? 0) ?: null;
        if ($targetFacultyId) {
            $db->prepare('UPDATE faculties SET dean_user_id = :uid WHERE faculty_id = :fac')
               ->execute(['uid' => $newUserId, 'fac' => $targetFacultyId]);
        }
    }

    AuditLog::record(Auth::id(), 'CREATE_STAFF_ACCOUNT', 'users', $newUserId, "role=$newRole created_by_role=$myRole");
    Mailer::sendStaffAccountCreated(['user_id' => $newUserId, 'full_name' => $fullName, 'email' => $email], $newRole, $tempPassword, $me['full_name']);
    NotificationCenter::notify($newUserId, 'Welcome to SEMAS', "Your $newRole account has been created. Check your email for login details.", 'System');

    flash('success', "$newRole account created for $fullName. Login credentials were emailed to $email.");
    redirect('/admin/users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $targetUserId = (int) ($_POST['user_id'] ?? 0);

    if (!user_in_scope($db, $targetUserId, $myRole, $scopeDeptIds, $hodCanSeeDeans, $deanUniversityWide)) {
        flash('error', 'You do not have permission to manage that user.');
        redirect('/admin/users.php');
    }

    $userStmt = $db->prepare('SELECT * FROM users WHERE user_id = :id');
    $userStmt->execute(['id' => $targetUserId]);
    $targetUser = $userStmt->fetch();

    if ($targetUser) {
        switch ($action) {
            case 'activate':
                $db->prepare("UPDATE users SET status='Active' WHERE user_id=:id")->execute(['id' => $targetUserId]);
                AuditLog::record(Auth::id(), 'ACTIVATE_USER', 'users', $targetUserId);
                Mailer::sendAccountActivated($targetUser);
                NotificationCenter::notify($targetUserId, 'Account activated', 'Your SEMAS account has been activated.', 'System');
                flash('success', 'Account activated.');
                break;

            case 'deactivate':
                $db->prepare("UPDATE users SET status='Deactivated' WHERE user_id=:id")->execute(['id' => $targetUserId]);
                AuditLog::record(Auth::id(), 'DEACTIVATE_USER', 'users', $targetUserId);
                Mailer::sendAccountDeactivated($targetUser);
                flash('success', 'Account deactivated.');
                break;

            case 'reset_password':
                $tempPassword = bin2hex(random_bytes(5));
                $db->prepare('UPDATE users SET password_hash=:hash WHERE user_id=:id')
                   ->execute(['hash' => password_hash($tempPassword, PASSWORD_BCRYPT), 'id' => $targetUserId]);
                AuditLog::record(Auth::id(), 'ADMIN_RESET_PASSWORD', 'users', $targetUserId);
                Mailer::sendPasswordChangedNotice($targetUser);
                flash('success', "Password reset. Temporary password: $tempPassword (share this with the user securely; it is not emailed in plaintext).");
                break;

            case 'update_info':
                // Role and department changes are Principal-only to prevent
                // a Dean/HOD from escalating a user's privileges or moving them
                // out of the scope the Dean/HOD can even see.
                $fields = [
                    'full_name' => trim($_POST['full_name']),
                    'email' => trim($_POST['email']),
                    'phone_number' => trim($_POST['phone_number']) ?: null,
                    'session_type' => $_POST['session_type'] ?: null,
                    'year_of_study' => $_POST['year_of_study'] ?: null,
                ];
                $sql = 'UPDATE users SET full_name=:full_name, email=:email, phone_number=:phone_number, session_type=:session_type, year_of_study=:year_of_study';
                if ($myRole === 'Principal') {
                    $fields['department_id'] = $_POST['department_id'] ?: null;
                    $fields['role_id'] = (int) $_POST['role_id'];
                    $sql .= ', department_id=:department_id, role_id=:role_id';
                }
                $sql .= ' WHERE user_id=:id';
                $fields['id'] = $targetUserId;
                $db->prepare($sql)->execute($fields);
                AuditLog::record(Auth::id(), 'UPDATE_USER_INFO', 'users', $targetUserId);
                flash('success', 'User information updated.');
                break;

            case 'upload_photo':
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
                    finfo_close($finfo);
                    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    if (isset($allowed[$mime]) && $_FILES['photo']['size'] <= 2 * 1024 * 1024) {
                        $filename = 'user' . $targetUserId . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
                        $dest = __DIR__ . '/../uploads/profile_photos/' . $filename;
                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                            $db->prepare('UPDATE users SET photo_path=:p WHERE user_id=:id')
                               ->execute(['p' => 'uploads/profile_photos/' . $filename, 'id' => $targetUserId]);
                            AuditLog::record(Auth::id(), 'ADMIN_UPDATE_PHOTO', 'users', $targetUserId);
                            flash('success', 'Photo updated.');
                        }
                    } else {
                        flash('error', 'Photo must be JPEG/PNG/WebP and under 2MB.');
                    }
                }
                break;
        }
    }
    redirect('/admin/users.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
}

// ---- Listing with search/filter ----
$search = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$where = [];
$params = [];
if ($myRole === 'Principal') {
    // Principal manages staff roles only; students are managed exclusively by the Registrar
    $where[] = "r.role_name != 'Student'";
} elseif ($deanUniversityWide) {
    $where[] = "r.role_name = 'Student'"; // Dean: every student in the university, never staff accounts
} elseif ($scopeDeptIds !== null) {
    if (!$scopeDeptIds && !$hodCanSeeDeans) {
        $where[] = '1=0';
    } else {
        $scopeClauses = [];
        if ($scopeDeptIds) {
            $placeholders = [];
            foreach ($scopeDeptIds as $i => $d) { $placeholders[] = ":dept$i"; $params["dept$i"] = $d; }
            $scopeClauses[] = "(r.role_name = 'Student' AND u.department_id IN (" . implode(',', $placeholders) . "))";
        } else {
            $scopeClauses[] = '1=0';
        }
        if ($hodCanSeeDeans) {
            $scopeClauses[] = "r.role_name = 'Dean'";
        }
        $where[] = '(' . implode(' OR ', $scopeClauses) . ')';
    }
}
if ($search !== '') {
    $where[] = '(u.full_name LIKE :search OR u.email LIKE :search OR u.reg_number LIKE :search)';
    $params['search'] = "%$search%";
}
if ($roleFilter !== '') {
    $where[] = 'r.role_name = :role';
    $params['role'] = $roleFilter;
}
if ($statusFilter !== '') {
    $where[] = 'u.status = :status';
    $params['status'] = $statusFilter;
}

$sql = "SELECT u.*, r.role_name, d.department_name, f.faculty_name FROM users u
        JOIN roles r ON r.role_id=u.role_id
        LEFT JOIN departments d ON d.department_id=u.department_id
        LEFT JOIN faculties f ON f.faculty_id = d.faculty_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY u.created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$roles = $db->query('SELECT * FROM roles ORDER BY role_id')->fetchAll();
$departments = $db->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll();
$faculties = $db->query('SELECT faculty_id, faculty_name FROM faculties ORDER BY faculty_name')->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
  <h4 class="display-font mb-1">Users &amp; Roles</h4>
  <?php if ($myRole === 'Principal' || $myRole === 'HOD'): ?>
    <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#createStaffModal">
      <i class="bi bi-person-plus-fill me-1"></i> <?= $myRole === 'Principal' ? 'Add HOD / Dean / Lecturer' : 'Add Dean Account' ?>
    </button>
  <?php endif; ?>
</div>
<p class="text-muted small mb-4">
  <?php if ($myRole === 'Principal'): ?>Full management of every account.
  <?php elseif ($myRole === 'Dean'): ?>University-wide: every student account, regardless of department or faculty.
  <?php else: ?>Scoped to students in your department, plus all Dean accounts.<?php endif; ?>
</p>

<?php if ($myRole === 'Principal' || $myRole === 'HOD'): ?>
<div class="modal fade" id="createStaffModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_staff">
        <div class="modal-header">
          <h6 class="modal-title display-font"><?= $myRole === 'Principal' ? 'Add HOD / Dean / Lecturer' : 'Add Dean Account' ?></h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small">Account Type</label>
            <select name="new_role" id="newRoleSelect" class="form-select form-select-sm" onchange="toggleStaffFields()" required>
              <?php if ($myRole === 'Principal'): ?>
                <option value="HOD">Head of Department (HOD)</option>
                <option value="Dean">Dean</option>
                <option value="Lecturer">Lecturer</option>
              <?php else: ?>
                <option value="Dean">Dean</option>
              <?php endif; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label small">Full Name</label><input name="full_name" class="form-control form-control-sm" required></div>
          <div class="mb-2"><label class="form-label small">Email Address</label><input type="email" name="email" class="form-control form-control-sm" required></div>
          <div class="mb-2"><label class="form-label small">Phone Number</label><input name="phone_number" class="form-control form-control-sm" placeholder="+250..."></div>
          <div class="mb-2" id="targetDeptField">
            <label class="form-label small">Department (for HOD / Lecturer)</label>
            <select name="target_department_id" class="form-select form-select-sm">
              <option value="">Select department</option>
              <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2" id="lecturerExtraFields" style="display:none;">
            <label class="form-label small">Title (optional)</label>
            <input name="lecturer_title" class="form-control form-control-sm mb-2" placeholder="e.g. Dr., Senior Lecturer">
            <label class="form-label small">Specialization (optional)</label>
            <input name="lecturer_specialization" class="form-control form-control-sm" placeholder="e.g. Database Systems">
          </div>
          <div class="mb-2" id="targetFacultyField" style="display:none;">
            <label class="form-label small">Faculty (for Dean)</label>
            <select name="target_faculty_id" class="form-select form-select-sm">
              <option value="">Select faculty</option>
              <?php foreach ($faculties as $f): ?><option value="<?= (int) $f['faculty_id'] ?>"><?= e($f['faculty_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <p class="text-muted" style="font-size:0.78rem;">A temporary password will be generated automatically and emailed to this address. The account is created already Active (no email verification step), since it was provisioned by staff rather than self-registered.</p>
        </div>
        <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Create Account</button></div>
      </form>
    </div>
  </div>
</div>
<script>
function toggleStaffFields() {
  const v = document.getElementById('newRoleSelect').value;
  document.getElementById('targetDeptField').style.display = (v === 'HOD' || v === 'Lecturer') ? '' : 'none';
  document.getElementById('lecturerExtraFields').style.display = (v === 'Lecturer') ? '' : 'none';
  document.getElementById('targetFacultyField').style.display = (v === 'Dean') ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function () {
  const sel = document.getElementById('newRoleSelect');
  if (sel) { toggleStaffFields(); sel.addEventListener('change', toggleStaffFields); }
});
</script>
<?php endif; ?>

<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2">
    <div class="col-md-5"><input name="q" class="form-control form-control-sm" placeholder="Search name, email, or reg. number" value="<?= e($search) ?>"></div>
    <div class="col-md-3">
      <select name="role" class="form-select form-select-sm">
        <option value="">All Roles</option>
        <?php foreach ($roles as $r): ?><option value="<?= e($r['role_name']) ?>" <?= $roleFilter === $r['role_name'] ? 'selected' : '' ?>><?= e($r['role_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <?php foreach (['Active', 'Pending', 'Deactivated'] as $s): ?><option <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-1"><button class="btn btn-sm btn-semas w-100"><i class="bi bi-search"></i></button></div>
  </form>
</div>

<div class="semas-card p-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th></th><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><img src="<?= $u['photo_path'] ? APP_URL . '/' . e($u['photo_path']) : 'https://ui-avatars.com/api/?name=' . urlencode($u['full_name']) . '&background=1E2A52&color=fff' ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;"></td>
            <td class="fw-semibold"><?= e($u['full_name']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td><?= e($u['role_name']) ?></td>
            <td><?= e($u['department_name'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $u['status'] === 'Active' ? 'completed' : ($u['status'] === 'Pending' ? 'upcoming' : 'cancelled') ?>"><?= e($u['status']) ?></span></td>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#edit-<?= (int) $u['user_id'] ?>"><i class="bi bi-pencil"></i></button>
              <form method="post" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                <?php if ($u['status'] === 'Active'): ?>
                  <button class="btn btn-sm btn-outline-dark" name="action" value="deactivate">Deactivate</button>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-dark" name="action" value="activate">Activate</button>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-dark" name="action" value="reset_password" onclick="return confirm('Reset this user\'s password?');">Reset Password</button>
              </form>
            </td>
          </tr>

          <!-- Edit modal -->
          <div class="modal fade" id="edit-<?= (int) $u['user_id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_info">
                  <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                  <div class="modal-header"><h6 class="modal-title display-font">Edit <?= e($u['full_name']) ?></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                  <div class="modal-body">
                    <div class="mb-2"><label class="form-label small">Full Name</label><input name="full_name" class="form-control form-control-sm" value="<?= e($u['full_name']) ?>" required></div>
                    <div class="mb-2"><label class="form-label small">Email</label><input type="email" name="email" class="form-control form-control-sm" value="<?= e($u['email']) ?>" required></div>
                    <div class="mb-2"><label class="form-label small">Phone</label><input name="phone_number" class="form-control form-control-sm" value="<?= e($u['phone_number'] ?? '') ?>"></div>
                    <div class="mb-2">
                      <label class="form-label small">Session</label>
                      <select name="session_type" class="form-select form-select-sm">
                        <option value="">Not set</option>
                        <?php foreach (['Day', 'Evening', 'Weekend'] as $s): ?><option <?= $u['session_type'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-2">
                      <label class="form-label small">Year of Study</label>
                      <select name="year_of_study" class="form-select form-select-sm">
                        <option value="">Not set</option>
                        <?php for ($y = 1; $y <= 6; $y++): ?><option value="<?= $y ?>" <?= (int) $u['year_of_study'] === $y ? 'selected' : '' ?>>Year <?= $y ?></option><?php endfor; ?>
                      </select>
                    </div>
                    <?php if ($myRole === 'Principal'): ?>
                      <div class="mb-2">
                        <label class="form-label small">Department</label>
                        <select name="department_id" class="form-select form-select-sm">
                          <option value="">None</option>
                          <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= $u['department_id'] == $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
                        </select>
                      </div>
                      <div class="mb-2">
                        <label class="form-label small">Role</label>
                        <select name="role_id" class="form-select form-select-sm">
                          <?php foreach ($roles as $r): ?><option value="<?= (int) $r['role_id'] ?>" <?= $u['role_id'] == $r['role_id'] ? 'selected' : '' ?>><?= e($r['role_name']) ?></option><?php endforeach; ?>
                        </select>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="modal-footer"><button class="btn btn-semas btn-sm">Save Changes</button></div>
                </form>
                <form method="post" enctype="multipart/form-data" class="px-3 pb-3">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="upload_photo">
                  <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                  <label class="form-label small">Profile Photo</label>
                  <div class="d-flex gap-2">
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm">
                    <button class="btn btn-sm btn-semas-gold text-nowrap">Upload</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="7" class="text-muted small text-center py-3">No users match your filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
