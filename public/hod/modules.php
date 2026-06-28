<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD']);
Module::autoCompleteExpired();

$pageTitle = 'Manage Modules';
$activeNav = 'modules';
$db = Database::connection();
$me = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        if (!(int) ($_POST['lecturer_id'] ?? 0)) {
            flash('error', 'Please select a lecturer to assign.');
            redirect('/hod/modules.php');
        }
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate   = trim($_POST['end_date'] ?? '');
        $catDate   = trim($_POST['cat_date'] ?? '');
        $examDate  = trim($_POST['exam_date'] ?? '');
        if (!$startDate || !$endDate || !$catDate || !$examDate) {
            flash('error', 'Start date, End date, CAT date and Exam date are all required.');
            redirect('/hod/modules.php');
        }
        if ($startDate > $catDate || $catDate > $examDate || $examDate > $endDate) {
            flash('error', 'Dates must follow: Start ≤ CAT ≤ Exam ≤ End.');
            redirect('/hod/modules.php');
        }
        $qrSecret = bin2hex(random_bytes(32));
        $db->prepare(
            'INSERT INTO modules (module_title, department_id, lecturer_id, session_type, room, cat_date, exam_date, module_qr_secret, start_date, end_date, created_by)
             VALUES (:title, :dept, :lec, :session, :room, :cat, :exam, :qr, :start, :end, :uid)'
        )->execute([
            'title'   => trim($_POST['module_title']),
            'dept'    => (int) $_POST['department_id'],
            'lec'     => (int) $_POST['lecturer_id'],
            'session' => $_POST['session_type'] ?: null,
            'room'    => trim($_POST['room'] ?? '') ?: null,
            'cat'     => $catDate,
            'exam'    => $examDate,
            'qr'      => $qrSecret,
            'start'   => $startDate,
            'end'     => $endDate,
            'uid'     => $me['user_id'],
        ]);
        $moduleId = (int) $db->lastInsertId();
        AuditLog::record(Auth::id(), 'MODULE_CREATE', 'modules', $moduleId);
        flash('success', 'Module created. QR code ready to print.');
    } elseif ($action === 'update') {
        if (!(int) ($_POST['lecturer_id'] ?? 0)) {
            flash('error', 'Please select a lecturer to assign.');
            redirect('/hod/modules.php');
        }
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate   = trim($_POST['end_date'] ?? '');
        $catDate   = trim($_POST['cat_date'] ?? '');
        $examDate  = trim($_POST['exam_date'] ?? '');
        if (!$startDate || !$endDate || !$catDate || !$examDate) {
            flash('error', 'Start date, End date, CAT date and Exam date are all required.');
            redirect('/hod/modules.php');
        }
        if ($startDate > $catDate || $catDate > $examDate || $examDate > $endDate) {
            flash('error', 'Dates must follow: Start ≤ CAT ≤ Exam ≤ End.');
            redirect('/hod/modules.php');
        }
        $moduleId = (int) $_POST['module_id'];
        $db->prepare(
            'UPDATE modules SET module_title=:title, department_id=:dept, lecturer_id=:lec,
                session_type=:session, room=:room, cat_date=:cat, exam_date=:exam,
                start_date=:start, end_date=:end WHERE module_id=:id'
        )->execute([
            'title'   => trim($_POST['module_title']),
            'dept'    => (int) $_POST['department_id'],
            'lec'     => (int) $_POST['lecturer_id'],
            'session' => $_POST['session_type'] ?: null,
            'room'    => trim($_POST['room'] ?? '') ?: null,
            'cat'     => $catDate,
            'exam'    => $examDate,
            'start'   => $startDate,
            'end'     => $endDate,
            'id'      => $moduleId,
        ]);
        AuditLog::record(Auth::id(), 'MODULE_UPDATE', 'modules', $moduleId);
        flash('success', 'Module updated.');
    } elseif ($action === 'mark_completed') {
        $moduleId = (int) $_POST['module_id'];
        $db->prepare("UPDATE modules SET status='Completed' WHERE module_id = :id")->execute(['id' => $moduleId]);
        AuditLog::record(Auth::id(), 'MODULE_MARK_COMPLETED', 'modules', $moduleId);
        flash('success', 'Module marked Completed. Students can now generate CAT/Exam slips.');
    } elseif ($action === 'reopen') {
        $moduleId = (int) $_POST['module_id'];
        $db->prepare("UPDATE modules SET status='Ongoing' WHERE module_id = :id")->execute(['id' => $moduleId]);
        AuditLog::record(Auth::id(), 'MODULE_REOPEN', 'modules', $moduleId);
        flash('success', 'Module reopened as Ongoing.');
    } elseif ($action === 'enroll_student') {
        // HOD manually enrolls a student — bypasses the session-type constraint
        $moduleId   = (int) $_POST['module_id'];
        $studentId  = (int) $_POST['student_user_id'];
        // Verify module exists and student is a student
        $modRow = $db->prepare('SELECT module_title FROM modules WHERE module_id = :id')->execute(['id' => $moduleId]) && false;
        $modStmt = $db->prepare('SELECT module_title FROM modules WHERE module_id = :id');
        $modStmt->execute(['id' => $moduleId]);
        $mod = $modStmt->fetch();
        $stuStmt = $db->prepare("SELECT u.user_id, u.full_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = :id AND r.role_name = 'Student'");
        $stuStmt->execute(['id' => $studentId]);
        $stu = $stuStmt->fetch();
        if (!$mod || !$stu) {
            flash('error', 'Invalid module or student.');
        } else {
            try {
                $db->prepare('INSERT INTO module_enrollments (module_id, user_id) VALUES (:m, :u)')
                   ->execute(['m' => $moduleId, 'u' => $studentId]);
                AuditLog::record(Auth::id(), 'MODULE_ENROLL_MANUAL', 'modules', $moduleId, "student_user_id=$studentId");
                flash('success', $stu['full_name'] . ' enrolled in "' . $mod['module_title'] . '".');
            } catch (PDOException $e) {
                flash('error', $e->getCode() === '23000' ? 'Student is already enrolled.' : 'Enrollment failed.');
            }
        }
        redirect('/hod/modules.php');
    }
    redirect('/hod/modules.php');
}

$search     = trim($_GET['q'] ?? '');
$deptFilter = $_GET['department_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$where  = [];
$params = [];
if ($search !== '')      { $where[] = 'm.module_title LIKE :q';      $params['q']      = "%$search%"; }
if ($deptFilter !== '')  { $where[] = 'm.department_id = :dept';     $params['dept']   = (int) $deptFilter; }
if ($statusFilter !== '') { $where[] = 'm.status = :status';         $params['status'] = $statusFilter; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name,
        (SELECT COUNT(*) FROM module_enrollments e WHERE e.module_id = m.module_id) AS student_count
     FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l  ON l.lecturer_id = m.lecturer_id
     LEFT JOIN users u      ON u.user_id = l.user_id
     $whereSql ORDER BY m.created_at DESC"
);
$stmt->execute($params);
$modules = $stmt->fetchAll();

$departments = $db->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll();
$lecturers   = $db->query(
    "SELECT l.lecturer_id, l.department_id, u.full_name FROM lecturers l JOIN users u ON u.user_id = l.user_id
     WHERE u.status = 'Active' ORDER BY u.full_name"
)->fetchAll();
$students = $db->query(
    "SELECT u.user_id, u.full_name, u.reg_number FROM users u JOIN roles r ON r.role_id = u.role_id
     WHERE r.role_name = 'Student' AND u.status = 'Active' ORDER BY u.full_name"
)->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h4 class="display-font mb-1">Manage Modules</h4>
    <p class="text-muted small mb-0">Create and assign modules. CAT &amp; Exam dates are required. Print each module's QR code and post it in the classroom — students scan it to take attendance.</p>
  </div>
  <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#newModuleModal"><i class="bi bi-plus-circle me-1"></i> New Module</button>
</div>

<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2">
    <div class="col-md-5"><input name="q" class="form-control form-control-sm" placeholder="Search module title" value="<?= e($search) ?>"></div>
    <div class="col-md-3">
      <select name="department_id" class="form-select form-select-sm">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int) $d['department_id'] ?>" <?= (string) $deptFilter === (string) $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <option value="Ongoing"   <?= $statusFilter === 'Ongoing'   ? 'selected' : '' ?>>Ongoing</option>
        <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
      </select>
    </div>
    <div class="col-md-1"><button class="btn btn-sm btn-semas w-100"><i class="bi bi-search"></i></button></div>
  </form>
</div>

<div class="semas-card p-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>Module</th><th>Department</th><th>Lecturer</th><th>Session</th><th>Room</th>
          <th>Start</th><th>End</th><th>CAT</th><th>Exam</th><th>Students</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($modules as $m): ?>
          <tr>
            <td class="fw-semibold"><?= e($m['module_title']) ?></td>
            <td><?= e($m['department_name'] ?? '—') ?></td>
            <td><?= e($m['lecturer_name'] ?? '—') ?></td>
            <td><?= e($m['session_type'] ?? '—') ?></td>
            <td><?= e($m['room'] ?? '—') ?></td>
            <td><?= e($m['start_date'] ?? '—') ?></td>
            <td><?= e($m['end_date'] ?? '—') ?></td>
            <td><?= e($m['cat_date'] ?? '—') ?></td>
            <td><?= e($m['exam_date'] ?? '—') ?></td>
            <td><?= (int) $m['student_count'] ?></td>
            <td><span class="badge <?= $m['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?>"><?= e($m['status']) ?></span></td>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#edit-<?= (int) $m['module_id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
              <?php if ($m['module_qr_secret']): ?>
                <a href="<?= APP_URL ?>/hod/module-qr-print.php?module_id=<?= (int) $m['module_id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Print QR"><i class="bi bi-qr-code"></i></a>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#enroll-<?= (int) $m['module_id'] ?>" title="Enroll Student"><i class="bi bi-person-plus"></i></button>
              <form method="post" class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="module_id" value="<?= (int) $m['module_id'] ?>">
                <?php if ($m['status'] === 'Ongoing'): ?>
                  <button class="btn btn-sm btn-outline-dark" name="action" value="mark_completed"
                    onclick="return confirm('Mark this module Completed?');">Complete</button>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-dark" name="action" value="reopen">Reopen</button>
                <?php endif; ?>
              </form>
            </td>
          </tr>

          <!-- Edit Modal -->
          <div class="modal fade" id="edit-<?= (int) $m['module_id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="module_id" value="<?= (int) $m['module_id'] ?>">
                  <div class="modal-header"><h6 class="modal-title display-font">Edit <?= e($m['module_title']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                  <div class="modal-body">
                    <div class="mb-2"><label class="form-label small">Module Title</label><input name="module_title" class="form-control form-control-sm" value="<?= e($m['module_title']) ?>" required></div>
                    <div class="mb-2"><label class="form-label small">Department</label>
                      <select name="department_id" class="form-select form-select-sm" required>
                        <?php foreach ($departments as $d): ?>
                          <option value="<?= (int) $d['department_id'] ?>" <?= $m['department_id'] == $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-2"><label class="form-label small">Lecturer</label>
                      <select name="lecturer_id" class="form-select form-select-sm" required>
                        <?php foreach ($lecturers as $l): ?>
                          <option value="<?= (int) $l['lecturer_id'] ?>" <?= $m['lecturer_id'] == $l['lecturer_id'] ? 'selected' : '' ?>><?= e($l['full_name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-2"><label class="form-label small">Session</label>
                      <select name="session_type" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <?php foreach (['Day', 'Evening', 'Weekend'] as $s): ?>
                          <option <?= $m['session_type'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-2"><label class="form-label small">Class Room</label><input name="room" class="form-control form-control-sm" value="<?= e($m['room'] ?? '') ?>"></div>
                    <div class="row g-2 mb-2">
                      <div class="col-6"><label class="form-label small">Start Date <span class="text-danger">*</span></label><input type="date" name="start_date" class="form-control form-control-sm" value="<?= e($m['start_date'] ?? '') ?>" required></div>
                      <div class="col-6"><label class="form-label small">End Date <span class="text-danger">*</span></label><input type="date" name="end_date" class="form-control form-control-sm" value="<?= e($m['end_date'] ?? '') ?>" required></div>
                    </div>
                    <div class="row g-2 mb-0">
                      <div class="col-6"><label class="form-label small">CAT Date <span class="text-danger">*</span></label><input type="date" name="cat_date" class="form-control form-control-sm" value="<?= e($m['cat_date'] ?? '') ?>" required></div>
                      <div class="col-6"><label class="form-label small">Exam Date <span class="text-danger">*</span></label><input type="date" name="exam_date" class="form-control form-control-sm" value="<?= e($m['exam_date'] ?? '') ?>" required></div>
                    </div>
                    <div class="form-text" style="font-size:.7rem;">Dates must follow: Start ≤ CAT ≤ Exam ≤ End. Attendance scans are blocked outside [Start, End].</div>
                  </div>
                  <div class="modal-footer"><button class="btn btn-semas btn-sm">Save Changes</button></div>
                </form>
              </div>
            </div>
          </div>

          <!-- Enroll Student Modal -->
          <div class="modal fade" id="enroll-<?= (int) $m['module_id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="enroll_student">
                  <input type="hidden" name="module_id" value="<?= (int) $m['module_id'] ?>">
                  <div class="modal-header"><h6 class="modal-title display-font">Enroll Student — <?= e($m['module_title']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                  <div class="modal-body">
                    <p class="text-muted small mb-2">HOD manual enrollment bypasses the student's session-type restriction. Use when a student needs to be in a module outside their normal session.</p>
                    <div class="mb-2"><label class="form-label small">Select Student</label>
                      <select name="student_user_id" class="form-select form-select-sm" required>
                        <option value="">Choose a student…</option>
                        <?php foreach ($students as $s): ?>
                          <option value="<?= (int) $s['user_id'] ?>"><?= e($s['full_name']) ?><?= $s['reg_number'] ? ' (' . e($s['reg_number']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Enroll</button></div>
                </form>
              </div>
            </div>
          </div>

        <?php endforeach; ?>
        <?php if (!$modules): ?>
          <tr><td colspan="10" class="text-muted small text-center py-3">No modules match your filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- New Module Modal -->
<div class="modal fade" id="newModuleModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header"><h6 class="modal-title display-font">New Module</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label small">Module Title</label><input name="module_title" class="form-control form-control-sm" required placeholder="e.g. Database Systems II"></div>
          <div class="mb-2"><label class="form-label small">Department</label>
            <select name="department_id" class="form-select form-select-sm" required>
              <option value="">Select department</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label small">Assign Lecturer</label>
            <select name="lecturer_id" class="form-select form-select-sm" required>
              <option value="">Select lecturer</option>
              <?php foreach ($lecturers as $l): ?>
                <option value="<?= (int) $l['lecturer_id'] ?>"><?= e($l['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label small">Session</label>
            <select name="session_type" class="form-select form-select-sm">
              <option value="">Any</option>
              <?php foreach (['Day', 'Evening', 'Weekend'] as $s): ?>
                <option><?= e($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label small">Class Room</label><input name="room" class="form-control form-control-sm" placeholder="e.g. Block C — Room 12"></div>
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label small">Start Date <span class="text-danger">*</span></label><input type="date" name="start_date" class="form-control form-control-sm" required></div>
            <div class="col-6"><label class="form-label small">End Date <span class="text-danger">*</span></label><input type="date" name="end_date" class="form-control form-control-sm" required></div>
          </div>
          <div class="row g-2 mb-0">
            <div class="col-6"><label class="form-label small">CAT Date <span class="text-danger">*</span></label><input type="date" name="cat_date" class="form-control form-control-sm" required></div>
            <div class="col-6"><label class="form-label small">Exam Date <span class="text-danger">*</span></label><input type="date" name="exam_date" class="form-control form-control-sm" required></div>
          </div>
          <div class="form-text" style="font-size:.7rem;">Dates must follow: Start ≤ CAT ≤ Exam ≤ End. Attendance scans are blocked outside [Start, End].</div>
        </div>
        <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Create Module</button></div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
