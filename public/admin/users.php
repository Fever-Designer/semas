<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Administrator', 'Dean', 'HOD']);

$pageTitle = 'Users & Roles';
$activeNav = 'users';
$db = Database::connection();
$me = Auth::user();
$myRole = Auth::role();

// ---- Scope resolution: Administrator sees everyone; Dean sees their faculty's
//      departments; HOD sees only their own department. ----
$scopeDeptIds = null; // null = unrestricted (Administrator)
if ($myRole === 'HOD') {
    $scopeDeptIds = [(int) $me['department_id']];
} elseif ($myRole === 'Dean') {
    $facStmt = $db->prepare('SELECT faculty_id FROM faculties WHERE dean_user_id = :uid');
    $facStmt->execute(['uid' => $me['user_id']]);
    $facultyId = $facStmt->fetchColumn();
    if ($facultyId) {
        $deptStmt = $db->prepare('SELECT department_id FROM departments WHERE faculty_id = :fid');
        $deptStmt->execute(['fid' => $facultyId]);
        $scopeDeptIds = array_map('intval', $deptStmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $scopeDeptIds = [];
    }
}

function user_in_scope(PDO $db, int $userId, ?array $scopeDeptIds): bool
{
    if ($scopeDeptIds === null) return true; // Administrator
    $stmt = $db->prepare('SELECT department_id FROM users WHERE user_id = :id');
    $stmt->execute(['id' => $userId]);
    $dept = (int) $stmt->fetchColumn();
    return in_array($dept, $scopeDeptIds, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $targetUserId = (int) ($_POST['user_id'] ?? 0);

    if (!user_in_scope($db, $targetUserId, $scopeDeptIds)) {
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
                // Role and department changes are Administrator-only to prevent
                // a Dean/HOD from escalating a user's privileges or moving them
                // out of the scope the Dean/HOD can even see.
                $fields = [
                    'full_name' => trim($_POST['full_name']),
                    'email' => trim($_POST['email']),
                    'phone_number' => trim($_POST['phone_number']) ?: null,
                    'session_type' => $_POST['session_type'] ?: null,
                ];
                $sql = 'UPDATE users SET full_name=:full_name, email=:email, phone_number=:phone_number, session_type=:session_type';
                if ($myRole === 'Administrator') {
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
if ($scopeDeptIds !== null) {
    if (!$scopeDeptIds) {
        $where[] = '1=0'; // Dean with no faculty assigned sees nobody, rather than everybody
    } else {
        $placeholders = [];
        foreach ($scopeDeptIds as $i => $d) { $placeholders[] = ":dept$i"; $params["dept$i"] = $d; }
        $where[] = 'u.department_id IN (' . implode(',', $placeholders) . ')';
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

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Users &amp; Roles</h4>
<p class="text-muted small mb-4">
  <?php if ($myRole === 'Administrator'): ?>Full management of every account.
  <?php elseif ($myRole === 'Dean'): ?>Scoped to students in your faculty's departments.
  <?php else: ?>Scoped to students in your department.<?php endif; ?>
</p>

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
                    <?php if ($myRole === 'Administrator'): ?>
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
