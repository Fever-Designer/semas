<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['HOD']);
Module::autoCompleteExpired();

$pageTitle  = 'Manage Modules';
$activeNav  = 'modules';
$db         = Database::connection();
$me         = Auth::user();
$today      = date('Y-m-d');
$intakeList = availableIntakes();

// ── Room helper: rooms not already assigned to an Ongoing module in the
//    same session_type (current module excluded so Edit works correctly).
function hodAvailableRooms(PDO $db, string $sessionType, int $excludeModuleId = 0): array
{
    if (!$sessionType) return $db->query('SELECT room_id, room_name FROM rooms ORDER BY room_name')->fetchAll();
    $stmt = $db->prepare(
        "SELECT r.room_id, r.room_name FROM rooms r
         WHERE r.room_id NOT IN (
             SELECT m.room_id FROM modules m
             WHERE m.session_type = :st AND m.status = 'Ongoing'
               AND m.room_id IS NOT NULL AND m.module_id != :mid
         ) ORDER BY r.room_name"
    );
    $stmt->execute(['st' => $sessionType, 'mid' => $excludeModuleId]);
    return $stmt->fetchAll();
}

// ── POST handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ── CREATE ───────────────────────────────────────────────────────────
    if ($action === 'create') {
        $lecId     = (int) ($_POST['lecturer_id'] ?? 0);
        $sessionType = trim($_POST['session_type'] ?? '');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate   = trim($_POST['end_date'] ?? '');
        $catDate   = trim($_POST['cat_date'] ?? '');
        $examDate  = trim($_POST['exam_date'] ?? '');
        $roomId    = (int) ($_POST['room_id'] ?? 0) ?: null;
        $deptId    = (int) ($_POST['department_id'] ?? 0);

        if (!$lecId) { flash('error', 'Please select a lecturer.'); redirect('/hod/modules.php'); }
        if (!$startDate || !$endDate || !$catDate || !$examDate) { flash('error', 'All date fields are required.'); redirect('/hod/modules.php'); }
        if ($startDate < $today) { flash('error', 'Start date cannot be in the past.'); redirect('/hod/modules.php'); }
        if ($startDate > $catDate || $catDate > $examDate || $examDate > $endDate) { flash('error', 'Dates must follow: Start ≤ CAT ≤ Exam ≤ End.'); redirect('/hod/modules.php'); }

        // Lecturer one-module-per-session-type constraint (Ongoing only)
        if ($sessionType) {
            $lc = $db->prepare("SELECT COUNT(*) FROM modules WHERE lecturer_id=:lec AND session_type=:st AND status='Ongoing'");
            $lc->execute(['lec' => $lecId, 'st' => $sessionType]);
            if ((int) $lc->fetchColumn() > 0) {
                flash('error', 'This lecturer already has an Ongoing module in the ' . $sessionType . ' session. A lecturer may only teach one module per session type while it is active.');
                redirect('/hod/modules.php');
            }
        }

        // Room conflict check
        if ($roomId && $sessionType) {
            $rc = $db->prepare("SELECT COUNT(*) FROM modules WHERE room_id=:r AND session_type=:st AND status='Ongoing'");
            $rc->execute(['r' => $roomId, 'st' => $sessionType]);
            if ((int) $rc->fetchColumn() > 0) {
                flash('error', 'This room is already assigned to another Ongoing module in the ' . $sessionType . ' session.');
                redirect('/hod/modules.php');
            }
        }

        $qrSecret = bin2hex(random_bytes(32));
        $db->prepare(
            'INSERT INTO modules (module_title, department_id, lecturer_id, session_type, room_id,
             cat_date, exam_date, module_qr_secret, start_date, end_date, created_by)
             VALUES (:title, :dept, :lec, :session, :room, :cat, :exam, :qr, :start, :end, :uid)'
        )->execute([
            'title'   => trim($_POST['module_title']),
            'dept'    => $deptId,
            'lec'     => $lecId,
            'session' => $sessionType ?: null,
            'room'    => $roomId,
            'cat'     => $catDate,
            'exam'    => $examDate,
            'qr'      => $qrSecret,
            'start'   => $startDate,
            'end'     => $endDate,
            'uid'     => $me['user_id'],
        ]);
        $moduleId = (int) $db->lastInsertId();

        // Save intakes
        foreach (($_POST['intakes'] ?? []) as $ink) {
            if (isValidIntakeCode($ink)) {
                $db->prepare('INSERT IGNORE INTO module_intakes (module_id, intake) VALUES (:m,:i)')->execute(['m' => $moduleId, 'i' => $ink]);
            }
        }
        // Save extra departments
        foreach (($_POST['extra_departments'] ?? []) as $edId) {
            $db->prepare('INSERT IGNORE INTO module_departments (module_id, department_id) VALUES (:m,:d)')->execute(['m' => $moduleId, 'd' => (int) $edId]);
        }

        AuditLog::record(Auth::id(), 'MODULE_CREATE', 'modules', $moduleId);
        flash('success', 'Module created. QR code ready to print.');
        redirect('/hod/modules.php');
    }

    // ── UPDATE ───────────────────────────────────────────────────────────
    if ($action === 'update') {
        $moduleId  = (int) $_POST['module_id'];
        $lecId     = (int) ($_POST['lecturer_id'] ?? 0);
        $sessionType = trim($_POST['session_type'] ?? '');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate   = trim($_POST['end_date'] ?? '');
        $catDate   = trim($_POST['cat_date'] ?? '');
        $examDate  = trim($_POST['exam_date'] ?? '');
        $roomId    = (int) ($_POST['room_id'] ?? 0) ?: null;

        if (!$lecId) { flash('error', 'Please select a lecturer.'); redirect('/hod/modules.php'); }
        if (!$startDate || !$endDate || !$catDate || !$examDate) { flash('error', 'All date fields are required.'); redirect('/hod/modules.php'); }
        if ($startDate > $catDate || $catDate > $examDate || $examDate > $endDate) { flash('error', 'Dates must follow: Start ≤ CAT ≤ Exam ≤ End.'); redirect('/hod/modules.php'); }

        // Room conflict check (exclude current module)
        if ($roomId && $sessionType) {
            $rc = $db->prepare("SELECT COUNT(*) FROM modules WHERE room_id=:r AND session_type=:st AND status='Ongoing' AND module_id!=:mid");
            $rc->execute(['r' => $roomId, 'st' => $sessionType, 'mid' => $moduleId]);
            if ((int) $rc->fetchColumn() > 0) {
                flash('error', 'This room is already assigned to another Ongoing module in the ' . $sessionType . ' session.');
                redirect('/hod/modules.php');
            }
        }

        $db->prepare(
            'UPDATE modules SET module_title=:title, department_id=:dept, lecturer_id=:lec,
             session_type=:session, room_id=:room, cat_date=:cat, exam_date=:exam,
             start_date=:start, end_date=:end WHERE module_id=:id'
        )->execute([
            'title'   => trim($_POST['module_title']),
            'dept'    => (int) $_POST['department_id'],
            'lec'     => $lecId,
            'session' => $sessionType ?: null,
            'room'    => $roomId,
            'cat'     => $catDate,
            'exam'    => $examDate,
            'start'   => $startDate,
            'end'     => $endDate,
            'id'      => $moduleId,
        ]);

        // Refresh intakes
        $db->prepare('DELETE FROM module_intakes WHERE module_id=:m')->execute(['m' => $moduleId]);
        foreach (($_POST['intakes'] ?? []) as $ink) {
            if (isValidIntakeCode($ink)) {
                $db->prepare('INSERT IGNORE INTO module_intakes (module_id, intake) VALUES (:m,:i)')->execute(['m' => $moduleId, 'i' => $ink]);
            }
        }
        // Refresh extra departments
        $db->prepare('DELETE FROM module_departments WHERE module_id=:m')->execute(['m' => $moduleId]);
        foreach (($_POST['extra_departments'] ?? []) as $edId) {
            $db->prepare('INSERT IGNORE INTO module_departments (module_id, department_id) VALUES (:m,:d)')->execute(['m' => $moduleId, 'd' => (int) $edId]);
        }

        AuditLog::record(Auth::id(), 'MODULE_UPDATE', 'modules', $moduleId);
        flash('success', 'Module updated.');
        redirect('/hod/modules.php');
    }

    if ($action === 'mark_completed') {
        $moduleId = (int) $_POST['module_id'];
        $db->prepare("UPDATE modules SET status='Completed' WHERE module_id=:id")->execute(['id' => $moduleId]);
        AuditLog::record(Auth::id(), 'MODULE_MARK_COMPLETED', 'modules', $moduleId);
        flash('success', 'Module marked Completed. Students can now generate CAT/Exam slips.');
        redirect('/hod/modules.php');
    }

    if ($action === 'reopen') {
        $moduleId = (int) $_POST['module_id'];
        $db->prepare("UPDATE modules SET status='Ongoing' WHERE module_id=:id")->execute(['id' => $moduleId]);
        AuditLog::record(Auth::id(), 'MODULE_REOPEN', 'modules', $moduleId);
        flash('success', 'Module reopened as Ongoing.');
        redirect('/hod/modules.php');
    }

    // ── MANUAL STUDENT ENROLL (search by reg number) ─────────────────────
    if ($action === 'enroll_student') {
        $moduleId = (int) $_POST['module_id'];
        $regNum   = trim($_POST['search_reg_number'] ?? '');
        $stuStmt  = $db->prepare(
            "SELECT u.user_id, u.full_name FROM users u JOIN roles r ON r.role_id=u.role_id
             WHERE r.role_name='Student' AND u.reg_number=:rn AND u.status='Active'"
        );
        $stuStmt->execute(['rn' => $regNum]);
        $stu = $stuStmt->fetch();
        $modStmt = $db->prepare('SELECT module_title FROM modules WHERE module_id=:id');
        $modStmt->execute(['id' => $moduleId]);
        $mod = $modStmt->fetch();

        if (!$stu) {
            flash('error', "No active student found with registration number: {$regNum}");
        } elseif (!$mod) {
            flash('error', 'Module not found.');
        } else {
            try {
                $db->prepare('INSERT INTO module_enrollments (module_id, user_id) VALUES (:m,:u)')->execute(['m' => $moduleId, 'u' => $stu['user_id']]);
                AuditLog::record(Auth::id(), 'MODULE_ENROLL_MANUAL', 'modules', $moduleId, "student={$regNum}");
                flash('success', $stu['full_name'] . ' enrolled in "' . $mod['module_title'] . '".');
            } catch (PDOException $e) {
                flash('error', $e->getCode() === '23000' ? 'Student is already enrolled in this module.' : 'Enrollment failed.');
            }
        }
        redirect('/hod/modules.php');
    }

    // ── DE-REGISTER STUDENT (HoD only) ──────────────────────────────────
    if ($action === 'deregister_student') {
        $moduleId = (int) $_POST['module_id'];
        $userId   = (int) $_POST['user_id'];
        $reason   = trim($_POST['reason'] ?? '');

        if (!$reason) {
            flash('error', 'A reason is required to de-register a student.');
            redirect('/hod/modules.php');
        }

        $stuStmt = $db->prepare('SELECT full_name FROM users WHERE user_id = :id');
        $stuStmt->execute(['id' => $userId]);
        $stuRow = $stuStmt->fetch();

        $modStmt = $db->prepare('SELECT module_title FROM modules WHERE module_id = :id');
        $modStmt->execute(['id' => $moduleId]);
        $modRow = $modStmt->fetch();

        $db->prepare('DELETE FROM module_enrollments WHERE module_id = :m AND user_id = :u')
           ->execute(['m' => $moduleId, 'u' => $userId]);

        AuditLog::record(Auth::id(), 'MODULE_DEREGISTER', 'modules', $moduleId,
            'student_user_id=' . $userId . ';reason=' . substr($reason, 0, 200));

        NotificationCenter::notify(
            $userId,
            'Module De-registration — ' . ($modRow['module_title'] ?? ''),
            'You have been de-registered from this module by your HoD. Reason: ' . $reason,
            'System'
        );

        flash('success', ($stuRow['full_name'] ?? 'Student') . ' has been de-registered from the module.');
        redirect('/hod/modules.php');
    }

    redirect('/hod/modules.php');
}

// ── Fetch modules ──────────────────────────────────────────────────────
$search       = trim($_GET['q'] ?? '');
$deptFilter   = $_GET['department_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$sessionFilter= $_GET['session_type'] ?? '';

$where  = [];
$params = [];
if ($search !== '')       { $where[] = 'm.module_title LIKE :q';      $params['q']      = "%$search%"; }
if ($deptFilter !== '')   { $where[] = 'm.department_id = :dept';     $params['dept']   = (int) $deptFilter; }
if ($statusFilter !== '') { $where[] = 'm.status = :status';          $params['status'] = $statusFilter; }
if ($sessionFilter !== '') { $where[] = 'm.session_type = :stype';    $params['stype']  = $sessionFilter; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name, r.room_name,
        (SELECT COUNT(*) FROM module_enrollments e WHERE e.module_id = m.module_id) AS student_count,
        (SELECT GROUP_CONCAT(mi.intake ORDER BY mi.intake SEPARATOR ', ')
         FROM module_intakes mi WHERE mi.module_id = m.module_id) AS intakes_list
     FROM modules m
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers l   ON l.lecturer_id   = m.lecturer_id
     LEFT JOIN users u       ON u.user_id        = l.user_id
     LEFT JOIN rooms r       ON r.room_id        = m.room_id
     $whereSql ORDER BY m.created_at DESC"
);
$stmt->execute($params);
$modules = $stmt->fetchAll();

$departments = $db->query(
    'SELECT d.department_id, d.department_name, d.department_code, f.faculty_name
     FROM departments d JOIN faculties f ON f.faculty_id=d.faculty_id
     ORDER BY f.faculty_name, d.department_name'
)->fetchAll();

$allRooms  = $db->query('SELECT room_id, room_name FROM rooms ORDER BY room_name')->fetchAll();
$lecturers = $db->query(
    "SELECT l.lecturer_id, u.full_name FROM lecturers l JOIN users u ON u.user_id=l.user_id
     WHERE u.status='Active' ORDER BY u.full_name"
)->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h4 class="display-font mb-1">Manage Modules</h4>
    <p class="text-muted small mb-0">Rooms are conflict-checked per session. Lecturer can only hold one Ongoing module per session type.</p>
  </div>
  <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#newModuleModal">
    <i class="bi bi-plus-circle me-1"></i> New Module
  </button>
</div>

<!-- Filter bar -->
<div class="semas-card p-3 mb-3">
  <form method="GET" class="row g-2">
    <div class="col-md-4"><input name="q" class="form-control form-control-sm" placeholder="Search module title" value="<?= e($search) ?>"></div>
    <div class="col-md-2">
      <select name="department_id" class="form-select form-select-sm">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= $d['department_id'] ?>" <?= (string)$deptFilter===$d['department_id'] ? 'selected':'' ?>><?= e($d['department_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="session_type" class="form-select form-select-sm">
        <option value="">All Sessions</option>
        <option value="Day"     <?= $sessionFilter==='Day'     ?'selected':'' ?>>Day</option>
        <option value="Evening" <?= $sessionFilter==='Evening' ?'selected':'' ?>>Evening</option>
        <option value="Weekend" <?= $sessionFilter==='Weekend' ?'selected':'' ?>>Weekend</option>
      </select>
    </div>
    <div class="col-md-2">
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <option value="Ongoing"   <?= $statusFilter==='Ongoing'   ?'selected':'' ?>>Ongoing</option>
        <option value="Completed" <?= $statusFilter==='Completed' ?'selected':'' ?>>Completed</option>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-sm btn-semas w-100"><i class="bi bi-search"></i></button></div>
  </form>
</div>

<!-- Module table -->
<div class="semas-card p-0">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Module</th><th>Dept</th><th>Lecturer</th><th>Session</th><th>Room</th><th>Intakes</th><th>CAT</th><th>Exam</th><th>Students</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($modules as $m):
        $mId = (int) $m['module_id'];
        $modIntakes = [];
        $miStmt = $db->prepare('SELECT intake FROM module_intakes WHERE module_id=:mid');
        $miStmt->execute(['mid' => $mId]);
        $modIntakes = $miStmt->fetchAll(PDO::FETCH_COLUMN);
        $modExtraDeptIds = [];
        $medStmt = $db->prepare('SELECT department_id FROM module_departments WHERE module_id=:mid');
        $medStmt->execute(['mid' => $mId]);
        $modExtraDeptIds = $medStmt->fetchAll(PDO::FETCH_COLUMN);
        $editRooms = hodAvailableRooms($db, $m['session_type'] ?? '', $mId);

        // Enrolled students for this module
        $enrolledStmt = $db->prepare(
            "SELECT u.user_id, u.full_name, u.reg_number, u.intake, d.department_name, e.registered_at
             FROM module_enrollments e
             JOIN users u ON u.user_id = e.user_id
             LEFT JOIN departments d ON d.department_id = u.department_id
             WHERE e.module_id = :mid ORDER BY u.full_name"
        );
        $enrolledStmt->execute(['mid' => $mId]);
        $enrolledStudents = $enrolledStmt->fetchAll();
      ?>
        <tr>
          <td class="fw-semibold"><?= e($m['module_title']) ?></td>
          <td><span class="badge bg-light text-dark border" style="font-size:.7rem;"><?= e($m['department_name'] ?? '—') ?></span></td>
          <td><?= e($m['lecturer_name'] ?? '—') ?></td>
          <td><?= e($m['session_type'] ?? '—') ?></td>
          <td><?= e($m['room_name'] ?? ($m['room'] ?? '—')) ?></td>
          <td><small><?= e($m['intakes_list'] ?? '—') ?></small></td>
          <td><?= e($m['cat_date'] ?? '—') ?></td>
          <td><?= e($m['exam_date'] ?? '—') ?></td>
          <td><?= (int) $m['student_count'] ?></td>
          <td><span class="badge <?= $m['status']==='Ongoing' ? 'badge-completed' : 'bg-secondary' ?>"><?= e($m['status']) ?></span></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#edit-<?= $mId ?>" title="Edit"><i class="bi bi-pencil"></i></button>
            <?php if ($m['module_qr_secret']): ?>
              <a href="<?= APP_URL ?>/hod/module-qr-print.php?module_id=<?= $mId ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Print QR"><i class="bi bi-qr-code"></i></a>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#enroll-<?= $mId ?>" title="Enroll Student"><i class="bi bi-person-plus"></i></button>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#students-<?= $mId ?>" title="View Enrolled Students">
              <i class="bi bi-people-fill"></i> <?= (int) $m['student_count'] ?>
            </button>
            <form method="POST" class="d-inline"><?= csrf_field() ?>
              <input type="hidden" name="module_id" value="<?= $mId ?>">
              <?php if ($m['status'] === 'Ongoing'): ?>
                <button class="btn btn-sm btn-outline-dark" name="action" value="mark_completed" onclick="return confirm('Mark this module Completed?')">Complete</button>
              <?php else: ?>
                <button class="btn btn-sm btn-outline-dark" name="action" value="reopen">Reopen</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>

        <!-- Edit Modal -->
        <div class="modal fade" id="edit-<?= $mId ?>" tabindex="-1">
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <form method="POST" id="editForm-<?= $mId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="module_id" value="<?= $mId ?>">
                <div class="modal-header"><h6 class="modal-title display-font">Edit: <?= e($m['module_title']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <div class="row g-2 mb-2">
                    <div class="col-12">
                      <label class="form-label small fw-semibold">Module Title <span class="text-danger">*</span></label>
                      <input name="module_title" class="form-control form-control-sm" value="<?= e($m['module_title']) ?>" required>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label small fw-semibold">Primary Department <span class="text-danger">*</span></label>
                      <select name="department_id" class="form-select form-select-sm" required>
                        <?php foreach ($departments as $d): ?>
                          <option value="<?= $d['department_id'] ?>" <?= $m['department_id']==$d['department_id']?'selected':'' ?>><?= e($d['department_name']) ?> (<?= e($d['department_code']) ?>)</option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label small fw-semibold">Lecturer <span class="text-danger">*</span></label>
                      <select name="lecturer_id" class="form-select form-select-sm" required>
                        <?php foreach ($lecturers as $l): ?>
                          <option value="<?= $l['lecturer_id'] ?>" <?= $m['lecturer_id']==$l['lecturer_id']?'selected':'' ?>><?= e($l['full_name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small fw-semibold">Session Type</label>
                      <select name="session_type" class="form-select form-select-sm" id="editSession-<?= $mId ?>" onchange="loadEditRooms(<?= $mId ?>, this.value)">
                        <option value="">Any</option>
                        <?php foreach (['Day','Evening','Weekend'] as $s): ?>
                          <option <?= $m['session_type']===$s?'selected':'' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small fw-semibold">Room</label>
                      <select name="room_id" class="form-select form-select-sm" id="editRoom-<?= $mId ?>">
                        <option value="">— No room —</option>
                        <?php
                        // Show available rooms + current room even if it would normally be excluded
                        $shownRoomIds = array_column($editRooms, 'room_id');
                        foreach ($editRooms as $rm): ?>
                          <option value="<?= $rm['room_id'] ?>" <?= $m['room_id']==$rm['room_id']?'selected':'' ?>><?= e($rm['room_name']) ?></option>
                        <?php endforeach; ?>
                        <?php if ($m['room_id'] && !in_array($m['room_id'], $shownRoomIds)): ?>
                          <option value="<?= $m['room_id'] ?>" selected>[Current: <?= e($m['room_name'] ?? '') ?>]</option>
                        <?php endif; ?>
                      </select>
                    </div>
                    <div class="col-12">
                      <label class="form-label small fw-semibold">Intakes</label>
                      <div class="d-flex flex-wrap gap-2 mt-1">
                        <?php foreach ($intakeList as $ink): ?>
                          <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" name="intakes[]" value="<?= $ink ?>" id="ei<?= $mId ?>_<?= $ink ?>" <?= in_array($ink,$modIntakes)?'checked':'' ?>>
                            <label class="form-check-label small" for="ei<?= $mId ?>_<?= $ink ?>"><?= $ink ?></label>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <div class="col-12">
                      <label class="form-label small fw-semibold">Additional Departments <span class="text-muted small fw-normal">(cross-cutting)</span></label>
                      <div class="dept-tags d-flex flex-wrap gap-1 mb-1" id="deptTags-e<?= $mId ?>">
                        <?php foreach ($modExtraDeptIds as $edId):
                          $edName = '';
                          foreach ($departments as $dd) { if ($dd['department_id'] == $edId) { $edName = $dd['department_name']; break; } }
                          if (!$edName) continue; ?>
                          <span class="badge bg-secondary d-flex align-items-center gap-1" data-dept-id="<?= $edId ?>">
                            <?= e($edName) ?>
                            <button type="button" class="btn-close btn-close-white" style="font-size:.6rem;" onclick="removeDept(this,'e<?= $mId ?>')"></button>
                            <input type="hidden" name="extra_departments[]" value="<?= $edId ?>">
                          </span>
                        <?php endforeach; ?>
                      </div>
                      <div class="position-relative">
                        <input type="text" class="form-control form-control-sm dept-search-input" id="deptSearch-e<?= $mId ?>"
                          placeholder="Type department name to add…" autocomplete="off" data-uid="e<?= $mId ?>" data-mode="extra">
                        <div class="dept-dropdown border rounded bg-white shadow-sm" id="deptDD-e<?= $mId ?>"
                          style="display:none;position:absolute;z-index:1050;width:100%;max-height:180px;overflow-y:auto;"></div>
                      </div>
                    </div>
                    <div class="col-6"><label class="form-label small fw-semibold">Start Date <span class="text-danger">*</span></label><input type="date" name="start_date" class="form-control form-control-sm" value="<?= e($m['start_date']??'') ?>" required></div>
                    <div class="col-6"><label class="form-label small fw-semibold">End Date <span class="text-danger">*</span></label><input type="date" name="end_date" class="form-control form-control-sm" min="<?= $today ?>" value="<?= e($m['end_date']??'') ?>" required></div>
                    <div class="col-6"><label class="form-label small fw-semibold">CAT Date <span class="text-danger">*</span></label><input type="date" name="cat_date" class="form-control form-control-sm" min="<?= $today ?>" value="<?= e($m['cat_date']??'') ?>" required></div>
                    <div class="col-6"><label class="form-label small fw-semibold">Exam Date <span class="text-danger">*</span></label><input type="date" name="exam_date" class="form-control form-control-sm" min="<?= $today ?>" value="<?= e($m['exam_date']??'') ?>" required></div>
                  </div>
                  <div class="form-text" style="font-size:.7rem;">Start ≤ CAT ≤ Exam ≤ End.</div>
                </div>
                <div class="modal-footer"><button class="btn btn-semas btn-sm">Save Changes</button></div>
              </form>
            </div>
          </div>
        </div>

        <!-- Enroll Student Modal (2-step: search → preview → confirm) -->
        <div class="modal fade" id="enroll-<?= $mId ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content">
              <!-- Step 1: Search -->
              <div id="enrollSearch-<?= $mId ?>">
                <div class="modal-header"><h6 class="modal-title display-font">Enroll Student — <?= e($m['module_title']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <p class="text-muted small mb-3">Enter the student's registration number. Manual enrollment bypasses intake/session restrictions.</p>
                  <label class="form-label small fw-semibold">Registration Number <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="text" id="enrollReg-<?= $mId ?>" class="form-control" placeholder="e.g. 2601001192" autocomplete="off">
                    <button type="button" class="btn btn-outline-dark" onclick="lookupStudent(<?= $mId ?>)">
                      <i class="bi bi-search"></i> Search
                    </button>
                  </div>
                  <div id="enrollError-<?= $mId ?>" class="text-danger small mt-2" style="display:none;"></div>
                </div>
              </div>
              <!-- Step 2: Preview -->
              <div id="enrollPreview-<?= $mId ?>" style="display:none;">
                <div class="modal-header"><h6 class="modal-title display-font">Confirm Enrollment</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <div id="enrollCard-<?= $mId ?>" class="d-flex align-items-center gap-3 p-2 border rounded mb-2"></div>
                  <p class="small text-muted mb-0">Enroll this student in <strong><?= e($m['module_title']) ?></strong>?</p>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetEnroll(<?= $mId ?>)"><i class="bi bi-arrow-left me-1"></i> Back</button>
                  <form method="POST" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enroll_student">
                    <input type="hidden" name="module_id" value="<?= $mId ?>">
                    <input type="hidden" name="search_reg_number" id="enrollConfirmReg-<?= $mId ?>">
                    <button type="submit" class="btn btn-semas-gold btn-sm"><i class="bi bi-person-check me-1"></i> Confirm Enroll</button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Enrolled Students Modal -->
        <div class="modal fade" id="students-<?= $mId ?>" tabindex="-1">
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h6 class="modal-title display-font"><i class="bi bi-people-fill me-1"></i> Enrolled Students — <?= e($m['module_title']) ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body p-0">
                <?php if (!$enrolledStudents): ?>
                  <p class="text-muted small text-center py-4 mb-0">No students enrolled yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                      <tr><th>Name</th><th>Reg No.</th><th>Dept</th><th>Intake</th><th>Registered</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($enrolledStudents as $es): ?>
                      <tr>
                        <td class="fw-semibold"><?= e($es['full_name']) ?></td>
                        <td><code class="small"><?= e($es['reg_number'] ?? '—') ?></code></td>
                        <td><small><?= e($es['department_name'] ?? '—') ?></small></td>
                        <td><span class="badge bg-primary"><?= e($es['intake'] ?? '—') ?></span></td>
                        <td><small class="text-muted"><?= $es['registered_at'] ? date('d M Y', strtotime($es['registered_at'])) : '—' ?></small></td>
                        <td>
                          <button type="button" class="btn btn-sm btn-outline-danger"
                            onclick="openDeregister(<?= (int) $es['user_id'] ?>, <?= $mId ?>, <?= json_encode($es['full_name']) ?>, <?= json_encode($m['module_title']) ?>)">
                            <i class="bi bi-person-dash me-1"></i>De-register
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-semas btn-sm" data-bs-dismiss="modal"
                  onclick="setTimeout(()=>new bootstrap.Modal(document.getElementById('enroll-<?= $mId ?>')).show(),300)">
                  <i class="bi bi-person-plus me-1"></i> Enroll Another
                </button>
              </div>
            </div>
          </div>
        </div>

      <?php endforeach; ?>
      <?php if (!$modules): ?>
        <tr><td colspan="11" class="text-muted small text-center py-3">No modules match your filters.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- New Module Modal -->
<?php $newDayRooms = hodAvailableRooms($db, ''); ?>
<div class="modal fade" id="newModuleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="newModuleForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header"><h6 class="modal-title display-font">New Module</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-12">
              <label class="form-label small fw-semibold">Module Title <span class="text-danger">*</span></label>
              <input name="module_title" class="form-control form-control-sm" required placeholder="e.g. Database Systems II">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Primary Department <span class="text-danger">*</span></label>
              <div class="position-relative">
                <input type="text" class="form-control form-control-sm dept-search-input" id="deptSearch-newPrimary"
                  placeholder="Type department name…" autocomplete="off" data-uid="newPrimary" data-mode="primary">
                <div class="dept-dropdown border rounded bg-white shadow-sm" id="deptDD-newPrimary"
                  style="display:none;position:absolute;z-index:1050;width:100%;max-height:180px;overflow-y:auto;"></div>
              </div>
              <div id="primaryDeptDisplay" class="mt-1"></div>
              <input type="hidden" name="department_id" id="primaryDeptId" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Assign Lecturer <span class="text-danger">*</span></label>
              <select name="lecturer_id" class="form-select form-select-sm" required>
                <option value="">Select lecturer</option>
                <?php foreach ($lecturers as $l): ?>
                  <option value="<?= $l['lecturer_id'] ?>"><?= e($l['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Session Type</label>
              <select name="session_type" class="form-select form-select-sm" id="newSessionType" onchange="loadNewRooms(this.value)">
                <option value="">Any</option>
                <option value="Day">Day</option>
                <option value="Evening">Evening</option>
                <option value="Weekend">Weekend</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Room <span class="text-muted small">(available for session)</span></label>
              <select name="room_id" class="form-select form-select-sm" id="newRoomSelect">
                <option value="">— No room —</option>
                <?php foreach ($allRooms as $rm): ?>
                  <option value="<?= $rm['room_id'] ?>"><?= e($rm['room_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Intakes</label>
              <div class="d-flex flex-wrap gap-2 mt-1">
                <?php foreach ($intakeList as $ink): ?>
                  <div class="form-check form-check-inline">
                    <input type="checkbox" class="form-check-input" name="intakes[]" value="<?= $ink ?>" id="ni_<?= $ink ?>">
                    <label class="form-check-label small" for="ni_<?= $ink ?>"><?= $ink ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Additional Departments <span class="text-muted small fw-normal">(cross-cutting)</span></label>
              <div class="dept-tags d-flex flex-wrap gap-1 mb-1" id="deptTags-newExtra"></div>
              <div class="position-relative">
                <input type="text" class="form-control form-control-sm dept-search-input" id="deptSearch-newExtra"
                  placeholder="Type department name to add…" autocomplete="off" data-uid="newExtra" data-mode="extra">
                <div class="dept-dropdown border rounded bg-white shadow-sm" id="deptDD-newExtra"
                  style="display:none;position:absolute;z-index:1050;width:100%;max-height:180px;overflow-y:auto;"></div>
              </div>
            </div>
            <div class="col-6"><label class="form-label small fw-semibold">Start Date <span class="text-danger">*</span></label><input type="date" name="start_date" class="form-control form-control-sm" min="<?= $today ?>" required></div>
            <div class="col-6"><label class="form-label small fw-semibold">End Date <span class="text-danger">*</span></label><input type="date" name="end_date" class="form-control form-control-sm" min="<?= $today ?>" required></div>
            <div class="col-6"><label class="form-label small fw-semibold">CAT Date <span class="text-danger">*</span></label><input type="date" name="cat_date" class="form-control form-control-sm" min="<?= $today ?>" required></div>
            <div class="col-6"><label class="form-label small fw-semibold">Exam Date <span class="text-danger">*</span></label><input type="date" name="exam_date" class="form-control form-control-sm" min="<?= $today ?>" required></div>
          </div>
          <div class="form-text mt-1" style="font-size:.7rem;">Start ≤ CAT ≤ Exam ≤ End.</div>
        </div>
        <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Create Module</button></div>
      </form>
    </div>
  </div>
</div>

<script>
// All rooms keyed by room_id, for dynamic filtering
const ALL_ROOMS = <?= json_encode(array_values($allRooms)) ?>;

// Fetch available rooms for a given session and module (used in New Module form)
function loadNewRooms(sessionType) {
    var sel = document.getElementById('newRoomSelect');
    var cur = sel.value;
    sel.innerHTML = '<option value="">— No room —</option>';
    if (!sessionType) {
        ALL_ROOMS.forEach(function(r) {
            sel.innerHTML += '<option value="' + r.room_id + '">' + r.room_name + '</option>';
        });
        return;
    }
    // Fetch via AJAX
    fetch(window.SEMAS_BASE_URL + '/api/available-rooms.php?session_type=' + encodeURIComponent(sessionType) + '&module_id=0')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            (data.rooms || []).forEach(function(r) {
                sel.innerHTML += '<option value="' + r.room_id + '">' + r.room_name + '</option>';
            });
            sel.value = cur;
        })
        .catch(function() {
            // fallback: show all rooms
            ALL_ROOMS.forEach(function(r) {
                sel.innerHTML += '<option value="' + r.room_id + '">' + r.room_name + '</option>';
            });
        });
}

function loadEditRooms(moduleId, sessionType) {
    var sel = document.getElementById('editRoom-' + moduleId);
    var cur = sel.value;
    sel.innerHTML = '<option value="">— No room —</option>';
    if (!sessionType) {
        ALL_ROOMS.forEach(function(r) {
            sel.innerHTML += '<option value="' + r.room_id + '">' + r.room_name + '</option>';
        });
        return;
    }
    fetch(window.SEMAS_BASE_URL + '/api/available-rooms.php?session_type=' + encodeURIComponent(sessionType) + '&module_id=' + moduleId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            (data.rooms || []).forEach(function(r) {
                sel.innerHTML += '<option value="' + r.room_id + '">' + r.room_name + '</option>';
            });
            sel.value = cur;
        })
        .catch(function() {
            ALL_ROOMS.forEach(function(r) {
                sel.innerHTML += '<option value="' + r.room_id + '">' + r.room_name + '</option>';
            });
        });
}

// ── Department search ──────────────────────────────────────────────────
const HOD_DEPTS = <?= json_encode(array_values(array_map(function($d) {
    return ['id' => (int)$d['department_id'], 'name' => $d['department_name'],
            'code' => $d['department_code'], 'faculty' => $d['faculty_name']];
}, $departments))) ?>;

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.dept-search-input').forEach(function(inp) {
        inp.addEventListener('input', function() { hodShowDepts(this); });
        inp.addEventListener('blur', function() {
            var uid = this.dataset.uid;
            setTimeout(function() {
                var dd = document.getElementById('deptDD-' + uid);
                if (dd) dd.style.display = 'none';
            }, 200);
        });
    });
});

function hodShowDepts(inp) {
    var uid = inp.dataset.uid;
    var q   = inp.value.toLowerCase().trim();
    var dd  = document.getElementById('deptDD-' + uid);
    if (!q) { dd.style.display = 'none'; return; }
    var used = getUsedDeptIds(uid);
    var hits = HOD_DEPTS.filter(function(d) {
        return (d.name.toLowerCase().includes(q) || d.code.toLowerCase().includes(q)) && !used.includes(d.id);
    }).slice(0, 8);
    if (!hits.length) { dd.innerHTML = '<div class="p-2 small text-muted">No results</div>'; dd.style.display = ''; return; }
    dd.innerHTML = hits.map(function(d) {
        return '<div class="dept-opt px-3 py-2 small" style="cursor:pointer;border-bottom:1px solid #f1f5f9;"'
            + ' onmousedown="hodAddDept(' + d.id + ',\'' + escH(d.name) + '\',\'' + uid + '\')">'
            + '<strong>' + escH(d.name) + '</strong> <span class="text-muted">(' + d.code + ')</span></div>';
    }).join('');
    dd.style.display = '';
}

function getUsedDeptIds(uid) {
    var container = document.getElementById('deptTags-' + uid);
    if (!container) return [];
    return Array.from(container.querySelectorAll('input[type=hidden]')).map(function(i) { return parseInt(i.value); });
}

function hodAddDept(id, name, uid) {
    var inp = document.getElementById('deptSearch-' + uid);
    var mode = inp ? inp.dataset.mode : 'extra';

    if (mode === 'primary') {
        // For primary dept: set hidden input + display badge
        document.getElementById('primaryDeptId').value = id;
        document.getElementById('primaryDeptDisplay').innerHTML =
            '<span class="badge bg-primary">' + escH(name) + '</span>';
        inp.value = '';
        document.getElementById('deptDD-' + uid).style.display = 'none';
        return;
    }

    // Extra dept mode: add tag
    var container = document.getElementById('deptTags-' + uid);
    if (getUsedDeptIds(uid).includes(id)) return;
    var span = document.createElement('span');
    span.className = 'badge bg-secondary d-flex align-items-center gap-1';
    span.setAttribute('data-dept-id', id);
    span.innerHTML = escH(name)
        + ' <button type="button" class="btn-close btn-close-white" style="font-size:.6rem;" onclick="removeDept(this,\'' + uid + '\')"></button>'
        + '<input type="hidden" name="extra_departments[]" value="' + id + '">';
    container.appendChild(span);
    inp.value = '';
    document.getElementById('deptDD-' + uid).style.display = 'none';
}

function removeDept(btn, uid) { btn.closest('span').remove(); }

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Enroll student lookup ──────────────────────────────────────────────
function lookupStudent(modId) {
    var regNum = document.getElementById('enrollReg-' + modId).value.trim();
    var errEl  = document.getElementById('enrollError-' + modId);
    errEl.style.display = 'none';
    if (!regNum) { errEl.textContent = 'Please enter a registration number.'; errEl.style.display = ''; return; }

    fetch(window.SEMAS_BASE_URL + '/api/student-lookup.php?reg_number=' + encodeURIComponent(regNum))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) {
                errEl.textContent = data.message;
                errEl.style.display = '';
                return;
            }
            var s = data.student;
            document.getElementById('enrollCard-' + modId).innerHTML =
                '<img src="' + s.photo_url + '" style="width:56px;height:56px;border-radius:50%;object-fit:cover;flex-shrink:0;">'
                + '<div><div class="fw-semibold">' + escH(s.full_name) + '</div>'
                + '<div class="small text-muted">' + escH(s.reg_number) + '</div>'
                + '<div class="small text-muted">' + escH(s.department) + (s.intake !== '—' ? ' · ' + escH(s.intake) : '') + '</div></div>';
            document.getElementById('enrollConfirmReg-' + modId).value = s.reg_number;
            document.getElementById('enrollSearch-' + modId).style.display = 'none';
            document.getElementById('enrollPreview-' + modId).style.display = '';
        })
        .catch(function() { errEl.textContent = 'Lookup failed. Please try again.'; errEl.style.display = ''; });
}

function resetEnroll(modId) {
    document.getElementById('enrollSearch-' + modId).style.display = '';
    document.getElementById('enrollPreview-' + modId).style.display = 'none';
    document.getElementById('enrollError-' + modId).style.display = 'none';
}

// Allow pressing Enter in reg number field to trigger search
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && e.target && e.target.id && e.target.id.startsWith('enrollReg-')) {
        e.preventDefault();
        var modId = e.target.id.replace('enrollReg-', '');
        lookupStudent(modId);
    }
});

// ── De-register confirmation ───────────────────────────────────────────
function openDeregister(userId, moduleId, studentName, moduleTitle) {
    document.getElementById('deregStudentName').textContent = studentName;
    document.getElementById('deregModuleTitle').textContent = moduleTitle;
    document.getElementById('deregUserId').value   = userId;
    document.getElementById('deregModuleId').value = moduleId;
    document.getElementById('deregReason').value   = '';
    // Close any open students modal first, then show de-register modal
    var openModal = document.querySelector('.modal.show');
    if (openModal) {
        bootstrap.Modal.getInstance(openModal).hide();
        openModal.addEventListener('hidden.bs.modal', function onHide() {
            openModal.removeEventListener('hidden.bs.modal', onHide);
            new bootstrap.Modal(document.getElementById('deregisterModal')).show();
        });
    } else {
        new bootstrap.Modal(document.getElementById('deregisterModal')).show();
    }
}
</script>

<!-- Shared De-register Confirmation Modal -->
<div class="modal fade" id="deregisterModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h6 class="modal-title"><i class="bi bi-person-dash me-1"></i> De-register Student</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="deregister_student">
        <input type="hidden" name="user_id" id="deregUserId">
        <input type="hidden" name="module_id" id="deregModuleId">
        <div class="modal-body">
          <p class="mb-2">You are about to de-register <strong id="deregStudentName"></strong> from <strong id="deregModuleTitle"></strong>.</p>
          <div class="alert alert-warning small py-2 mb-3">
            <i class="bi bi-exclamation-triangle me-1"></i>
            This cannot be undone by the student. They will be notified with your reason.
          </div>
          <label class="form-label small fw-semibold">Reason <span class="text-danger">*</span></label>
          <textarea name="reason" id="deregReason" class="form-control" rows="3" required
            placeholder="e.g. Registered by mistake, wrong module, student request…"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm">
            <i class="bi bi-person-dash me-1"></i> Confirm De-register
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
