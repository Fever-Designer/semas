<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Coordinator', 'HOD']);
Module::autoCompleteExpired();

$pageTitle  = 'Manage Modules';
$activeNav  = 'modules';
$db         = Database::connection();
Semester::enforceAcademicWrite($db);
$me         = Auth::user();
$today      = date('Y-m-d');
$intakeList = availableIntakes();
$sessionTypes = ['Day', 'Evening', 'Weekend'];
$isCoordinator = Auth::role() === 'Coordinator';
if ($isCoordinator) {
    $sessionTypes = ['Weekend'];
}

// Ensure weekend_slot column exists (added for Morning/Afternoon sub-session support)
try {
    $db->exec('ALTER TABLE modules ADD COLUMN weekend_slot VARCHAR(20) NULL DEFAULT NULL');
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) !== 1060) throw $e; // 1060 = duplicate column, safe to ignore
}

function hodAvailableRooms(PDO $db, string $sessionType = '', int $excludeModuleId = 0): array
{
    if ($sessionType === '' || $sessionType === 'Weekend') {
        return $db->query('SELECT room_id, room_name FROM rooms ORDER BY room_name')->fetchAll();
    }

    $stmt = $db->prepare(
        "SELECT r.room_id, r.room_name FROM rooms r
         WHERE r.room_id NOT IN (
             SELECT m.room_id FROM modules m
             WHERE m.session_type=:session AND m.status='Ongoing'
               AND m.room_id IS NOT NULL AND m.module_id != :mid
         ) ORDER BY r.room_name"
    );
    $stmt->execute(['session' => $sessionType, 'mid' => $excludeModuleId]);
    return $stmt->fetchAll();
}

function hodRequireCoordinatorWeekendScope(PDO $db, int $moduleId, bool $isCoordinator): void
{
    if (!$isCoordinator) return;
    $guard = $db->prepare("SELECT 1 FROM modules WHERE module_id = :mid AND session_type = 'Weekend'");
    $guard->execute(['mid' => $moduleId]);
    if (!$guard->fetchColumn()) {
        flash('error', 'Coordinators can only manage Weekend modules.');
        redirect('/hod/modules.php');
    }
}

// ── POST handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate   = trim($_POST['end_date']   ?? '');
        $catDate   = trim($_POST['cat_date']   ?? '');
        $examDate  = trim($_POST['exam_date']  ?? '');
        $sessionType = trim($_POST['session_type'] ?? '');
        $lecUserId = (int) ($_POST['lecturer_user_id'] ?? 0);
        $roomId    = (int) ($_POST['room_id'] ?? 0) ?: null;
        $modId     = $action === 'update' ? (int) $_POST['module_id'] : 0;
        if ($action === 'update') {
            hodRequireCoordinatorWeekendScope($db, $modId, $isCoordinator);
        }

        // Resolve user_id → lecturers.lecturer_id (auto-create row for HOD/Coordinator who can teach)
        $lecId = 0;
        if ($lecUserId) {
            $lrRow = $db->prepare('SELECT lecturer_id FROM lecturers WHERE user_id = :uid');
            $lrRow->execute(['uid' => $lecUserId]);
            $lrFetch = $lrRow->fetch();
            if ($lrFetch) {
                $lecId = (int) $lrFetch['lecturer_id'];
            } else {
                $db->prepare('INSERT INTO lecturers (user_id) VALUES (:uid)')->execute(['uid' => $lecUserId]);
                $lecId = (int) $db->lastInsertId();
            }
        }

        // Departments are selected using checkboxes. Keep only real, unique IDs.
        $validDeptIds = array_map('intval', $db->query('SELECT department_id FROM departments')->fetchAll(PDO::FETCH_COLUMN));
        $deptIds = array_values(array_unique(array_intersect(
            array_map('intval', (array) ($_POST['dept_ids'] ?? [])),
            $validDeptIds
        )));
        // Editing checkbox order must not silently replace the owning department.
        if ($action === 'update' && $deptIds) {
            $currentDept = $db->prepare('SELECT department_id FROM modules WHERE module_id=:mid');
            $currentDept->execute(['mid' => $modId]);
            $currentDeptId = (int) $currentDept->fetchColumn();
            if (in_array($currentDeptId, $deptIds, true)) {
                $deptIds = array_values(array_unique(array_merge([$currentDeptId], $deptIds)));
            }
        }
        $primaryDeptId = $deptIds[0] ?? 0;
        $extraDeptIds  = array_slice($deptIds, 1);
        $weekendSlot = $sessionType === 'Weekend' ? (in_array($_POST['weekend_slot'] ?? '', ['Morning', 'Afternoon'], true) ? $_POST['weekend_slot'] : null) : null;

        // Helper: store error in session and reopen the form modal
        $formError = function(string $msg) use ($action, $modId): void {
            $_SESSION['mf_error'] = $msg;
            $_SESSION['mf_data']  = $_POST;
            $qs = ($action === 'create') ? '?reopen=create' : '?reopen=edit&rid=' . $modId;
            redirect('/hod/modules.php' . $qs);
        };

        if (!in_array($sessionType, $sessionTypes, true)) { $formError('A valid session must be selected.'); }
        if ($isCoordinator && $sessionType !== 'Weekend')  { $formError('Coordinators may only create or edit Weekend modules.'); }
        if ($sessionType === 'Weekend' && !in_array($weekendSlot, ['Morning', 'Afternoon'], true)) { $formError('Weekend modules must use either Morning or Afternoon.'); }
        if (!$lecId)         { $formError('A lecturer must be assigned.'); }
        if (!$primaryDeptId) { $formError('At least one department is required.'); }
        if (!$startDate || !$endDate || !$catDate || !$examDate) { $formError('All dates are required.'); }
        if ($startDate < $today && $action === 'create') { $formError('Start date cannot be in the past.'); }
        if ($startDate > $catDate || $catDate > $examDate || $examDate > $endDate) { $formError('Dates must follow: Start ≤ CAT ≤ Exam ≤ End.'); }

        // Validate intakes
        $intakes = array_filter($_POST['intakes'] ?? [], 'isValidIntakeCode');

        // Lecturer session constraint. Weekend Morning and Weekend Afternoon are separate sessions.
        $lecturerConflict = Module::lecturerOngoingSessionConflict($db, $lecId, $sessionType, $weekendSlot, $modId);
        if ($lecturerConflict) {
            $formError('This lecturer already has an Ongoing ' . Module::sessionLabel($lecturerConflict) . ' module.');
        }

        // Room conflict
        if ($roomId) {
            $roomSql = "SELECT module_title, session_type, weekend_slot FROM modules
                        WHERE room_id=:r AND session_type=:session AND status='Ongoing' AND module_id!=:mid";
            $roomParams = ['r' => $roomId, 'session' => $sessionType, 'mid' => $modId];
            if ($sessionType === 'Weekend') {
                $roomSql .= " AND (COALESCE(weekend_slot, '') = :slot OR COALESCE(weekend_slot, '') = '')";
                $roomParams['slot'] = (string) $weekendSlot;
            }
            $roomSql .= ' LIMIT 1';
            $rc = $db->prepare($roomSql);
            $rc->execute($roomParams);
            $roomConflict = $rc->fetch();
            if ($roomConflict) {
                $formError('This room is already assigned to another Ongoing ' . Module::sessionLabel($roomConflict) . ' module.');
            }
        }

        if ($action === 'create') {
            $qrSecret = bin2hex(random_bytes(32));
            $db->prepare(
                'INSERT INTO modules (module_title, department_id, lecturer_id, session_type, weekend_slot, room_id,
                 cat_date, exam_date, module_qr_secret, start_date, end_date, created_by)
                 VALUES (:title, :dept, :lec, :session, :wslot, :room, :cat, :exam, :qr, :start, :end, :uid)'
            )->execute([
                'title' => trim($_POST['module_title']), 'dept' => $primaryDeptId, 'lec' => $lecId,
                'session' => $sessionType, 'wslot' => $weekendSlot, 'room' => $roomId,
                'cat' => $catDate, 'exam' => $examDate, 'qr' => $qrSecret,
                'start' => $startDate, 'end' => $endDate, 'uid' => $me['user_id'],
            ]);
            $modId = (int) $db->lastInsertId();
        } else {
            $db->prepare(
                'UPDATE modules SET module_title=:title, department_id=:dept, lecturer_id=:lec,
                 session_type=:session, weekend_slot=:wslot, room_id=:room,
                 cat_date=:cat, exam_date=:exam, start_date=:start, end_date=:end
                 WHERE module_id=:id'
            )->execute([
                'title' => trim($_POST['module_title']), 'dept' => $primaryDeptId, 'lec' => $lecId,
                'session' => $sessionType, 'wslot' => $weekendSlot, 'room' => $roomId,
                'cat' => $catDate, 'exam' => $examDate,
                'start' => $startDate, 'end' => $endDate, 'id' => $modId,
            ]);
        }

        // Refresh intakes
        $db->prepare('DELETE FROM module_intakes WHERE module_id=:m')->execute(['m' => $modId]);
        foreach ($intakes as $ink) {
            $db->prepare('INSERT IGNORE INTO module_intakes (module_id, intake) VALUES (:m,:i)')->execute(['m' => $modId, 'i' => $ink]);
        }
        // Refresh extra departments
        $db->prepare('DELETE FROM module_departments WHERE module_id=:m')->execute(['m' => $modId]);
        foreach ($extraDeptIds as $edId) {
            $db->prepare('INSERT IGNORE INTO module_departments (module_id, department_id) VALUES (:m,:d)')->execute(['m' => $modId, 'd' => $edId]);
        }

        AuditLog::record(Auth::id(), 'MODULE_' . strtoupper($action), 'modules', $modId);
        flash('success', 'Module ' . ($action === 'create' ? 'created' : 'updated') . '.');
        redirect('/hod/modules.php');
    }

    if ($action === 'reopen') {
        $modId = (int) $_POST['module_id'];
        hodRequireCoordinatorWeekendScope($db, $modId, $isCoordinator);
        $db->prepare("UPDATE modules SET status='Ongoing' WHERE module_id=:id")->execute(['id' => $modId]);
        flash('success', 'Module reopened.');
        redirect('/hod/modules.php');
    }

    if ($action === 'delete_module') {
        $modId = (int) $_POST['module_id'];
        hodRequireCoordinatorWeekendScope($db, $modId, $isCoordinator);
        Module::deleteModule($db, $modId);
        AuditLog::record(Auth::id(), 'MODULE_DELETE', 'modules', $modId);
        flash('success', 'Module deleted.');
        redirect('/hod/modules.php');
    }

    if ($action === 'enroll_student') {
        $modId  = (int) $_POST['module_id'];
        hodRequireCoordinatorWeekendScope($db, $modId, $isCoordinator);
        $regNum = trim($_POST['search_reg_number'] ?? '');
        $exceptionType = trim($_POST['exception_type'] ?? '');
        $stuStmt = $db->prepare("SELECT u.user_id, u.full_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student' AND u.reg_number=:rn AND u.status='Active'");
        $stuStmt->execute(['rn' => $regNum]);
        $stu = $stuStmt->fetch();
        if (!$stu) {
            flash('error', "No active student found with reg number: {$regNum}");
        } else {
            $disciplinaryOverride = Module::isDisciplinarilyBlocked($db, $modId, (int) $stu['user_id']);
            $activeDisciplinaryModule = Module::activeDisciplinaryModule($db, (int) $stu['user_id']);
            $disciplinaryOverride = $disciplinaryOverride || (bool) $activeDisciplinaryModule;
            $completedStmt = $db->prepare(
                "SELECT cm.module_title
                 FROM modules target
                 JOIN modules cm ON cm.module_title = target.module_title AND cm.status = 'Completed'
                 JOIN module_enrollments ce ON ce.module_id = cm.module_id AND ce.user_id = :uid
                 WHERE target.module_id = :mid
                 LIMIT 1"
            );
            $completedStmt->execute(['uid' => $stu['user_id'], 'mid' => $modId]);
            $completedTitle = $completedStmt->fetchColumn();
            if ($completedTitle && !in_array($exceptionType, ['Retake', 'Special'], true)) {
                flash('error', $stu['full_name'] . ' already completed "' . $completedTitle . '". Enrollment is allowed only as Retake or Special case.');
                redirect('/hod/modules.php');
            }
            if (!Module::canAddOngoingEnrollment((int) $stu['user_id'], $modId)) {
                flash('error', Module::ongoingEnrollmentLimitMessage($stu['full_name']));
                redirect('/hod/modules.php');
            }
            $sessionConflict = Module::studentOngoingSessionConflict($db, (int) $stu['user_id'], $modId);
            if ($sessionConflict) {
                flash('error', $stu['full_name'] . ' is already registered for "' . $sessionConflict['module_title'] . '" in the same ' . Module::sessionLabel($sessionConflict) . ' session.');
                redirect('/hod/modules.php');
            }
            try {
                $db->prepare('INSERT INTO module_enrollments (module_id, user_id) VALUES (:m,:u)')->execute(['m' => $modId, 'u' => $stu['user_id']]);
                $reason = $exceptionType ? " ({$exceptionType})" : '';
                $auditAction = $disciplinaryOverride
                    ? 'MODULE_ENROLL_DISCIPLINARY_OVERRIDE'
                    : 'MODULE_ENROLL' . ($exceptionType ? '_' . strtoupper($exceptionType) : '');
                AuditLog::record(Auth::id(), $auditAction, 'modules', $modId, "user_id={$stu['user_id']}");
                flash('success', $stu['full_name'] . ' enrolled successfully' . $reason
                    . ($disciplinaryOverride ? ' by authorised disciplinary override.' : '.') );
            } catch (PDOException $e) {
                flash('error', $e->getCode() === '23000' ? 'Student already enrolled.' : 'Enrollment failed.');
            }
        }
        redirect('/hod/modules.php');
    }

    if ($action === 'de_register') {
        $modId  = (int) $_POST['module_id'];
        hodRequireCoordinatorWeekendScope($db, $modId, $isCoordinator);
        $userId = (int) $_POST['user_id'];
        $statusStmt = $db->prepare('SELECT status FROM modules WHERE module_id = :mid');
        $statusStmt->execute(['mid' => $modId]);
        if ($statusStmt->fetchColumn() === 'Completed') {
            flash('error', 'Completed module enrollments cannot be removed.');
            redirect('/hod/modules.php');
        }
        Module::removeEnrollment($db, $modId, $userId);
        AuditLog::record(Auth::id(), 'MODULE_UNENROLL', 'modules', $modId, "user_id=$userId");
        flash('success', 'Student unenrolled and removed from this module attendance.');
        redirect('/hod/modules.php');
    }

    redirect('/hod/modules.php');
}

// ── Fetch data ─────────────────────────────────────────────────────────
$search       = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$sessionFilter = $_GET['session'] ?? '';
$where        = [];
$params       = [];
if ($search)       { $where[] = 'm.module_title LIKE :q';   $params['q']      = "%$search%"; }
if ($statusFilter) { $where[] = 'm.status = :status';       $params['status'] = $statusFilter; }
if (in_array($sessionFilter, $sessionTypes, true)) { $where[] = 'm.session_type = :session'; $params['session'] = $sessionFilter; }
if ($isCoordinator) { $where[] = "m.session_type = 'Weekend'"; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare(
    "SELECT m.*, d.department_name, d.department_code, u.full_name AS lecturer_name, u.user_id AS lecturer_user_id, l.title AS lecturer_title, r.room_name,
        (SELECT COUNT(*) FROM module_enrollments e WHERE e.module_id=m.module_id) AS student_count,
        (SELECT GROUP_CONCAT(mi.intake ORDER BY mi.intake SEPARATOR ', ') FROM module_intakes mi WHERE mi.module_id=m.module_id) AS intakes_list,
        (SELECT GROUP_CONCAT(ad.department_code ORDER BY (ad.department_id=m.department_id) DESC, ad.department_code SEPARATOR ', ')
           FROM departments ad
          WHERE ad.department_id=m.department_id
             OR ad.department_id IN (SELECT md.department_id FROM module_departments md WHERE md.module_id=m.module_id)) AS departments_list
     FROM modules m
     LEFT JOIN departments d ON d.department_id=m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id=m.lecturer_id
     LEFT JOIN users u ON u.user_id=l.user_id
     LEFT JOIN rooms r ON r.room_id=m.room_id
     $whereSql ORDER BY m.created_at DESC"
);
$stmt->execute($params);
$modules = $stmt->fetchAll();
$ongoingModules = array_values(array_filter($modules, function ($m) { return ($m['status'] ?? '') === 'Ongoing'; }));
$completedModules = array_values(array_filter($modules, function ($m) { return ($m['status'] ?? '') === 'Completed'; }));

$departments = $db->query('SELECT d.department_id, d.department_name, d.department_code, f.faculty_name FROM departments d JOIN faculties f ON f.faculty_id=d.faculty_id ORDER BY f.faculty_name, d.department_name')->fetchAll();
$allRooms    = hodAvailableRooms($db);
// Include Lecturers, HOD, and Coordinators (they can all teach and invigilate)
$lecturers   = $db->query(
    "SELECT u.user_id, u.full_name, r.role_name, COALESCE(l.title,'') AS title
     FROM users u
     JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN lecturers l ON l.user_id = u.user_id
     WHERE u.status = 'Active' AND r.role_name IN ('Lecturer','HOD','Coordinator')
     ORDER BY u.full_name"
)->fetchAll();

// Read inline-form error from session (set on validation failure to reopen modal)
$mfError     = '';
$mfData      = [];
$reopenModal  = $_GET['reopen'] ?? '';
$reopenEditId = (int) ($_GET['rid'] ?? 0);
if (isset($_SESSION['mf_error'])) {
    $mfError = (string) $_SESSION['mf_error'];
    $mfData  = (array) ($_SESSION['mf_data'] ?? []);
    unset($_SESSION['mf_error'], $_SESSION['mf_data']);
}

require __DIR__ . '/../partials/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h4 class="display-font mb-1">All Modules</h4>
  </div>
  <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#newModuleModal">
    <i class="bi bi-plus-circle me-1"></i> New Module
  </button>
</div>

<div class="semas-card p-3 mb-3">
  <form method="GET" class="row g-2">
    <div class="col-md-8"><input name="q" class="form-control form-control-sm" value="<?= e($search) ?>"></div>
    <div class="col-md-2">
      <select name="session" class="form-select form-select-sm">
        <option value="">All Sessions</option>
        <?php foreach ($sessionTypes as $st): ?>
          <option value="<?= e($st) ?>" <?= $sessionFilter===$st ? 'selected' : '' ?>><?= e($st) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-sm btn-semas w-100"><i class="bi bi-search"></i></button></div>
  </form>
</div>

<div class="semas-card p-0">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Module</th><th>Session</th><th>Department</th><th>Lecturer</th><th>Room</th><th>Intakes</th><th>CAT</th><th>Exam</th><th>Students</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($ongoingModules as $m):
        $mId = (int) $m['module_id'];
        $modIntakes   = $db->prepare('SELECT intake FROM module_intakes WHERE module_id=:mid');
        $modIntakes->execute(['mid' => $mId]);
        $modIntakesArr = $modIntakes->fetchAll(PDO::FETCH_COLUMN);

        $modExtraDepts = $db->prepare('SELECT md.department_id, d.department_name FROM module_departments md JOIN departments d ON d.department_id=md.department_id WHERE md.module_id=:mid');
        $modExtraDepts->execute(['mid' => $mId]);
        $modExtraDeptRows = $modExtraDepts->fetchAll();

        $editRooms = hodAvailableRooms($db, (string) ($m['session_type'] ?? ''), $mId);
      ?>
        <tr>
          <td class="fw-semibold"><?= e($m['module_title']) ?></td>
          <td><?php
            $stype = $m['session_type'] ?? '/';
            $slot  = $m['weekend_slot'] ?? '';
            echo e($stype === 'Weekend' && $slot ? "Weekend / $slot" : $stype);
          ?></td>
          <td><?= e($m['departments_list'] ?? $m['department_code'] ?? '/') ?></td>
          <td><?= e($m['lecturer_name'] ?? '/') ?></td>
          <td><?= e($m['room_name'] ?? '/') ?></td>
          <td><small><?= e($m['intakes_list'] ?? '/') ?></small></td>
          <td><?= e($m['cat_date']  ?? '/') ?></td>
          <td><?= e($m['exam_date'] ?? '/') ?></td>
          <td><?= (int) $m['student_count'] ?></td>
          <td><span class="badge <?= $m['status']==='Ongoing'?'badge-completed':'bg-secondary' ?>"><?= e($m['status']) ?></span></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#edit-<?= $mId ?>"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#enroll-<?= $mId ?>"><i class="bi bi-person-plus"></i></button>
            <?php if ($m['module_qr_secret']): ?>
              <a href="<?= APP_URL ?>/hod/module-qr-print.php?module_id=<?= $mId ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-qr-code"></i></a>
            <?php endif; ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this module and all its attendance/enrollment records?')">
              <?= csrf_field() ?>
              <input type="hidden" name="module_id" value="<?= $mId ?>">
              <button name="action" value="delete_module" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php if ($m['status'] !== 'Ongoing'): ?>
            <form method="POST" class="d-inline"><?= csrf_field() ?>
              <input type="hidden" name="module_id" value="<?= $mId ?>">
              <button name="action" value="reopen" class="btn btn-sm btn-outline-dark">Reopen</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>

        <!-- Edit Modal -->
        <?php
          // On a validation-error reopen of THIS module's edit modal, restore exactly what
          // was submitted (so an unrelated error, e.g. a bad date, doesn't silently drop
          // department selections back to just the primary). Otherwise build the normal
          // combined dept list from the DB: primary first, then extras.
          if ($reopenModal === 'edit' && $reopenEditId === $mId && $mfData) {
              $editDepts = reconstructSelectedDepts((array) ($mfData['dept_ids'] ?? []), $departments);
          } else {
              $editDepts = [['department_id' => $m['department_id'], 'department_name' => $m['department_name']]];
              foreach ($modExtraDeptRows as $ed) {
                  if ($ed['department_id'] != $m['department_id']) $editDepts[] = $ed;
              }
          }
        ?>
        <div class="modal fade" id="edit-<?= $mId ?>" tabindex="-1">
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="module_id" value="<?= $mId ?>">
                <div class="modal-header"><h6 class="modal-title display-font">Edit: <?= e($m['module_title']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <?= moduleFormFields("e{$mId}", $lecturers, $editRooms, $intakeList, $sessionTypes, $departments, $m, $editDepts, $today, $m['room_id'], ($reopenModal === 'edit' && $reopenEditId === $mId) ? $mfError : '') ?>
                </div>
                <div class="modal-footer"><button class="btn btn-semas btn-sm">Save Changes</button></div>
              </form>
            </div>
          </div>
        </div>

        <!-- Enroll Modal (search → confirm OR already-enrolled unroll) -->
        <div class="modal fade" id="enroll-<?= $mId ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content">
              <div id="enrollSearch-<?= $mId ?>">
                <div class="modal-header"><h6 class="modal-title display-font">Enroll Student / <?= e($m['module_title']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <label class="form-label small fw-semibold">Registration Number <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="text" id="enrollReg-<?= $mId ?>" class="form-control" autocomplete="off">
                    <button type="button" class="btn btn-outline-dark" onclick="lookupStudent(<?= $mId ?>)"><i class="bi bi-search"></i> Search</button>
                  </div>
                  <div id="enrollError-<?= $mId ?>" class="text-danger small mt-2" style="display:none;"></div>
                </div>
              </div>
              <div id="enrollPreview-<?= $mId ?>" style="display:none;">
                <div class="modal-header"><h6 class="modal-title display-font">Confirm Enrollment</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <div id="enrollCard-<?= $mId ?>" class="d-flex align-items-center gap-3 p-2 border rounded mb-2"></div>
                  <p class="small text-muted mb-0">Enroll this student in <strong><?= e($m['module_title']) ?></strong>?</p>
                  <div id="enrollExceptionWrap-<?= $mId ?>" class="alert alert-warning small mt-2 mb-0" style="display:none;">
                    This student already completed this module. Choose why this enrollment is allowed.
                    <select name="exception_type" form="enrollForm-<?= $mId ?>" id="enrollException-<?= $mId ?>" class="form-select form-select-sm mt-2">
                      <option value="">Select reason</option>
                      <option value="Retake">Retake</option>
                      <option value="Special">Special case</option>
                    </select>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetEnroll(<?= $mId ?>)"><i class="bi bi-arrow-left me-1"></i> Back</button>
                  <form method="POST" class="d-inline" id="enrollForm-<?= $mId ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enroll_student">
                    <input type="hidden" name="module_id" value="<?= $mId ?>">
                    <input type="hidden" name="search_reg_number" id="enrollConfirmReg-<?= $mId ?>">
                    <button type="submit" class="btn btn-semas-gold btn-sm"><i class="bi bi-person-check me-1"></i> Confirm Enroll</button>
                  </form>
                </div>
              </div>
              <div id="enrollAlready-<?= $mId ?>" style="display:none;">
                <div class="modal-header"><h6 class="modal-title display-font">Already Enrolled</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <div id="enrollAlreadyCard-<?= $mId ?>" class="d-flex align-items-center gap-3 p-2 border rounded mb-2"></div>
                  <div class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle me-1"></i> This student is already enrolled in <strong><?= e($m['module_title']) ?></strong>.</div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetEnroll(<?= $mId ?>)"><i class="bi bi-arrow-left me-1"></i> Back</button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Unenroll this student?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="de_register">
                    <input type="hidden" name="module_id" value="<?= $mId ?>">
                    <input type="hidden" id="unrollUserId-<?= $mId ?>" name="user_id" value="">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-person-dash me-1"></i> Unroll</button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>

      <?php endforeach; ?>
      <?php if (!$ongoingModules): ?>
        <tr><td colspan="11" class="text-muted small text-center py-3">No ongoing modules.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($completedModules): ?>
<div class="semas-card mt-4">
  <button class="btn w-100 text-start p-3 d-flex justify-content-between align-items-center hist-toggle"
          type="button" data-bs-toggle="collapse" data-bs-target="#completedModulesHistory" aria-expanded="false">
    <span class="display-font" style="font-size:1rem;">
      Completed Modules History <span class="badge bg-secondary ms-2"><?= count($completedModules) ?></span>
    </span>
    <i class="bi bi-chevron-down"></i>
  </button>
  <div class="collapse" id="completedModulesHistory">
    <div class="p-3 pt-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th>Module</th><th>Session</th><th>Department</th><th>Lecturer</th><th>Room</th><th>Students</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($completedModules as $m):
              $mId = (int) $m['module_id'];
            ?>
              <tr>
                <td class="fw-semibold"><?= e($m['module_title']) ?></td>
                <td><?= e($m['session_type'] ?? '/') ?></td>
                <td><?= e($m['departments_list'] ?? $m['department_code'] ?? '/') ?></td>
                <td><?= e($m['lecturer_name'] ?? '/') ?></td>
                <td><?= e($m['room_name'] ?? '/') ?></td>
                <td><?= (int) $m['student_count'] ?></td>
                <td class="text-nowrap">
                  <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#view-completed-<?= $mId ?>">
                    <i class="bi bi-eye me-1"></i>View
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php foreach ($completedModules as $m):
  $mId = (int) $m['module_id'];
?>
  <div class="modal fade" id="view-completed-<?= $mId ?>" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title display-font"><?= e($m['module_title']) ?></h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body small">
          <div class="row g-2">
            <div class="col-6"><span class="text-muted">Session</span><br><strong><?= e($m['session_type'] ?? '/') ?></strong></div>
            <div class="col-6"><span class="text-muted">Departments</span><br><strong><?= e($m['departments_list'] ?? $m['department_code'] ?? '/') ?></strong></div>
            <div class="col-6"><span class="text-muted">Lecturer</span><br><strong><?= e($m['lecturer_name'] ?? '/') ?></strong></div>
            <div class="col-6"><span class="text-muted">Room</span><br><strong><?= e($m['room_name'] ?? '/') ?></strong></div>
            <div class="col-6"><span class="text-muted">CAT</span><br><strong><?= e($m['cat_date'] ?? '/') ?></strong></div>
            <div class="col-6"><span class="text-muted">Exam</span><br><strong><?= e($m['exam_date'] ?? '/') ?></strong></div>
            <div class="col-12"><span class="text-muted">Intakes</span><br><strong><?= e($m['intakes_list'] ?? '/') ?></strong></div>
            <div class="col-12"><span class="text-muted">Students</span><br><strong><?= (int) $m['student_count'] ?></strong></div>
          </div>
        </div>
        <div class="modal-footer">
          <?php if ($m['module_qr_secret']): ?>
            <a href="<?= APP_URL ?>/hod/module-qr-print.php?module_id=<?= $mId ?>" target="_blank" class="btn btn-outline-dark btn-sm"><i class="bi bi-qr-code me-1"></i>QR</a>
          <?php endif; ?>
          <form method="POST" class="d-inline"><?= csrf_field() ?>
            <input type="hidden" name="module_id" value="<?= $mId ?>">
            <button name="action" value="reopen" class="btn btn-semas btn-sm">Reopen</button>
          </form>
          <form method="POST" class="d-inline" onsubmit="return confirm('Delete this module and all its attendance/enrollment records?')">
            <?= csrf_field() ?>
            <input type="hidden" name="module_id" value="<?= $mId ?>">
            <button name="action" value="delete_module" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</button>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<!-- New Module Modal -->
<div class="modal fade" id="newModuleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header"><h6 class="modal-title display-font">New Module</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php
            $createPreFill = ($reopenModal === 'create' && $mfData) ? $mfData : [];
            $createError   = ($reopenModal === 'create') ? $mfError : '';
            $createPreFillDepts = ($reopenModal === 'create' && !empty($mfData['dept_ids']))
                ? reconstructSelectedDepts($mfData['dept_ids'], $departments) : [];
          ?>
          <?= moduleFormFields('new', $lecturers, $allRooms, $intakeList, $sessionTypes, $departments, $createPreFill, $createPreFillDepts, $today, null, $createError) ?>
        </div>
        <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Create Module</button></div>
      </form>
    </div>
  </div>
</div>

<?php
/**
 * Rebuilds a [department_id, department_name] list from raw submitted dept_ids[]
 * values (used to restore a HOD's actual multi-department selection when a
 * validation error reopens the form, instead of silently dropping it).
 */
function reconstructSelectedDepts(array $submittedIds, array $allDepartments): array
{
    $deptLookup = array_column($allDepartments, 'department_name', 'department_id');
    $result = [];
    foreach ($submittedIds as $id) {
        $id = (int) $id;
        if (isset($deptLookup[$id])) {
            $result[] = ['department_id' => $id, 'department_name' => $deptLookup[$id]];
        }
    }
    return $result;
}

/**
 * Renders the shared module form fields for create/edit modals.
 * @param string  $uid          Unique prefix to avoid duplicate HTML IDs
 * @param array[] $lecturers
 * @param array[] $rooms        Already-filtered available rooms
 * @param string[] $intakeList  e.g. ['JAN24','MAY24',…,'MAY26']
 * @param string[] $sessionTypes
 * @param array[] $departments   All departments available as checkboxes
 * @param array   $mod          Existing module row (empty for create)
 * @param array[] $selectedDepts Depts already on this module (primary first)
 * @param string  $today
 * @param int|null $currentRoomId
 */
function moduleFormFields(string $uid, array $lecturers, array $rooms, array $intakeList,
                          array $sessionTypes, array $departments, array $mod, array $selectedDepts, string $today, ?int $currentRoomId, string $error = ''): string
{
    $isCreate = empty($mod['module_id']);
    $selectedDeptIds = array_map('intval', array_column($selectedDepts, 'department_id'));
    ob_start(); ?>
    <?php if ($error !== ''): ?>
      <div class="alert alert-danger alert-sm small py-2 mb-2"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="row g-2">

      <!-- Title -->
      <div class="col-12">
        <label class="form-label small fw-semibold">Module Title <span class="text-danger">*</span></label>
        <input name="module_title" class="form-control form-control-sm" required value="<?= e($mod['module_title'] ?? '') ?>">
      </div>

      <!-- Department checkboxes -->
      <div class="col-12">
        <label class="form-label small fw-semibold">Departments <span class="text-danger">*</span> <span class="text-muted small fw-normal">(choose one or more)</span></label>
        <div class="d-flex flex-wrap gap-2 mt-1">
          <?php foreach ($departments as $department):
            $deptId = (int) $department['department_id']; ?>
            <div class="form-check form-check-inline" title="<?= e($department['department_name']) ?>">
              <input type="checkbox" class="form-check-input" name="dept_ids[]" value="<?= $deptId ?>"
                id="dept-<?= $uid ?>-<?= $deptId ?>" <?= in_array($deptId, $selectedDeptIds, true) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="dept-<?= $uid ?>-<?= $deptId ?>"><?= e($department['department_code']) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Session -->
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Session <span class="text-danger">*</span></label>
        <select name="session_type" class="form-select form-select-sm module-session-select" data-uid="<?= $uid ?>" data-module-id="<?= (int) ($mod['module_id'] ?? 0) ?>" required>
          <option value="">Select session</option>
          <?php foreach ($sessionTypes as $sessionType): ?>
            <option value="<?= e($sessionType) ?>" <?= ($mod['session_type'] ?? '')===$sessionType?'selected':'' ?>><?= e($sessionType) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Weekend Slot (shown only when Weekend session selected) -->
      <?php $currentSlot = $mod['weekend_slot'] ?? ''; ?>
      <div class="col-md-6 weekend-slot-wrap-<?= $uid ?>" <?= ($mod['session_type'] ?? '') !== 'Weekend' ? 'style="display:none;"' : '' ?>>
        <label class="form-label small fw-semibold">Weekend Session <span class="text-danger">*</span></label>
        <select name="weekend_slot" class="form-select form-select-sm" id="wslot-<?= $uid ?>">
          <option value="">Select slot</option>
          <option value="Morning" <?= $currentSlot === 'Morning' ? 'selected' : '' ?>>Weekend Morning</option>
          <option value="Afternoon" <?= $currentSlot === 'Afternoon' ? 'selected' : '' ?>>Weekend Afternoon</option>
        </select>
      </div>
      <?php if (($mod['session_type'] ?? '') !== 'Weekend'): ?><div class="col-md-6 weekend-slot-placeholder-<?= $uid ?>"></div><?php endif; ?>

      <!-- Lecturer -->
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Lecturer <span class="text-danger">*</span></label>
        <select name="lecturer_user_id" class="form-select form-select-sm" required>
          <option value="">Select lecturer / staff</option>
          <?php foreach ($lecturers as $l): ?>
            <option value="<?= $l['user_id'] ?>" <?= ($mod['lecturer_user_id'] ?? 0)==$l['user_id']?'selected':'' ?>><?= e($l['full_name']) ?><?= $l['role_name'] !== 'Lecturer' ? ' (' . e($l['role_name']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Room -->
      <div class="col-md-6">
        <div class="d-flex align-items-center justify-content-between gap-2">
          <label class="form-label small fw-semibold mb-1">Room <span class="text-muted small">(available for selected session)</span></label>
          <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" onclick="toggleAddRoom('<?= e($uid) ?>')">
            <i class="bi bi-plus-circle me-1"></i>Add new
          </button>
        </div>
        <select name="room_id" class="form-select form-select-sm module-room-select" id="room-<?= $uid ?>" data-current-room-id="<?= (int) ($currentRoomId ?? 0) ?>">
          <option value="">/ TBC /</option>
          <?php
          $shownRoomIds = array_column($rooms, 'room_id');
          foreach ($rooms as $rm): ?>
            <option value="<?= $rm['room_id'] ?>" <?= ($mod['room_id'] ?? null)==$rm['room_id']?'selected':'' ?>><?= e($rm['room_name']) ?></option>
          <?php endforeach;
          if ($currentRoomId && !in_array($currentRoomId, $shownRoomIds)): ?>
            <option value="<?= $currentRoomId ?>" selected>[Current: <?= e($mod['room_name'] ?? '') ?>]</option>
          <?php endif; ?>
        </select>
        <div class="input-group input-group-sm mt-2" id="add-room-<?= $uid ?>" style="display:none;">
          <input type="text" class="form-control" id="new-room-name-<?= $uid ?>" maxlength="100" placeholder="Enter room name">
          <button type="button" class="btn btn-outline-success" onclick="saveNewRoom('<?= e($uid) ?>', this)">Save</button>
          <button type="button" class="btn btn-outline-secondary" onclick="toggleAddRoom('<?= e($uid) ?>', false)" aria-label="Cancel">Cancel</button>
        </div>
        <div class="small mt-1" id="add-room-feedback-<?= $uid ?>" style="display:none;"></div>
      </div>

      <!-- Intakes -->
      <div class="col-12">
        <label class="form-label small fw-semibold">Intakes</label>
        <div class="d-flex flex-wrap gap-2 mt-1">
          <?php foreach ($intakeList as $ink): ?>
            <div class="form-check form-check-inline">
              <input type="checkbox" class="form-check-input" name="intakes[]" value="<?= $ink ?>"
                id="ink-<?= $uid ?>-<?= $ink ?>">
              <!-- checked state set by JS after modal renders -->
              <label class="form-check-label small" for="ink-<?= $uid ?>-<?= $ink ?>"><?= $ink ?></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Dates -->
      <div class="col-6"><label class="form-label small fw-semibold">Start Date <span class="text-danger">*</span></label>
        <input type="date" name="start_date" class="form-control form-control-sm" <?= $isCreate ? 'min="' . $today . '"' : '' ?> required value="<?= e($mod['start_date'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label small fw-semibold">End Date <span class="text-danger">*</span></label>
        <input type="date" name="end_date" class="form-control form-control-sm" min="<?= $today ?>" required value="<?= e($mod['end_date'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label small fw-semibold">CAT Date <span class="text-danger">*</span></label>
        <input type="date" name="cat_date" class="form-control form-control-sm" min="<?= $today ?>" required value="<?= e($mod['cat_date'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label small fw-semibold">Exam Date <span class="text-danger">*</span></label>
        <input type="date" name="exam_date" class="form-control form-control-sm" min="<?= $today ?>" required value="<?= e($mod['exam_date'] ?? '') ?>"></div>
    </div>
    <?php
    return (string) ob_get_clean();
}

// Intake data per module for pre-checking edit modals
$moduleIntakesMap = [];
foreach ($modules as $m) {
    $is = $db->prepare('SELECT intake FROM module_intakes WHERE module_id=:mid');
    $is->execute(['mid' => $m['module_id']]);
    $moduleIntakesMap[(int)$m['module_id']] = $is->fetchAll(PDO::FETCH_COLUMN);
}
?>

<script>
<?php if ($reopenModal === 'create'): ?>
document.addEventListener('DOMContentLoaded', function() {
    var m = new bootstrap.Modal(document.getElementById('newModuleModal'));
    m.show();
});
<?php elseif ($reopenModal === 'edit' && $reopenEditId): ?>
document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('edit-<?= $reopenEditId ?>');
    if (el) { var m = new bootstrap.Modal(el); m.show(); }
});
<?php endif; ?>
const MODULE_INTAKES = <?= json_encode($moduleIntakesMap) ?>;
const MODULE_CSRF = <?= json_encode(csrf_token()) ?>;

// Pre-check intakes for each edit modal
document.addEventListener('DOMContentLoaded', function() {
    Object.entries(MODULE_INTAKES).forEach(function([modId, intakes]) {
        intakes.forEach(function(ink) {
            var cb = document.getElementById('ink-e' + modId + '-' + ink);
            if (cb) cb.checked = true;
        });
    });

    document.querySelectorAll('.module-session-select').forEach(function(select) {
        select.addEventListener('change', function() {
            refreshRoomsForSession(this);
            toggleWeekendSlot(this);
        });
    });

    document.querySelectorAll('.hist-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var icon = this.querySelector('.bi-chevron-down, .bi-chevron-up');
            if (icon) icon.className = icon.className.includes('chevron-down') ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
        });
    });
});

function toggleWeekendSlot(select) {
    var uid = select.dataset.uid;
    var isWeekend = select.value === 'Weekend';
    var wrap = document.querySelector('.weekend-slot-wrap-' + uid);
    var placeholder = document.querySelector('.weekend-slot-placeholder-' + uid);
    if (wrap) wrap.style.display = isWeekend ? '' : 'none';
    if (placeholder) placeholder.style.display = isWeekend ? 'none' : '';
    var slotSelect = document.getElementById('wslot-' + uid);
    if (slotSelect && !isWeekend) slotSelect.value = '';
}

function refreshRoomsForSession(select) {
    var uid = select.dataset.uid;
    var roomSelect = document.getElementById('room-' + uid);
    if (!roomSelect) return;

    var previousValue = roomSelect.value;
    var moduleId = select.dataset.moduleId || '0';
    var sessionType = select.value;
    var url = window.SEMAS_BASE_URL + '/api/available-rooms.php?session_type='
        + encodeURIComponent(sessionType) + '&module_id=' + encodeURIComponent(moduleId);

    roomSelect.disabled = true;
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var options = ['<option value="">/ TBC /</option>'];
            if (data.ok && Array.isArray(data.rooms)) {
                data.rooms.forEach(function(room) {
                    var selected = String(room.room_id) === String(previousValue) ? ' selected' : '';
                    options.push('<option value="' + room.room_id + '"' + selected + '>' + escHtml(room.room_name) + '</option>');
                });
            }
            roomSelect.innerHTML = options.join('');
        })
        .finally(function() {
            roomSelect.disabled = false;
        });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toggleAddRoom(uid, show) {
    var wrap = document.getElementById('add-room-' + uid);
    var input = document.getElementById('new-room-name-' + uid);
    var feedback = document.getElementById('add-room-feedback-' + uid);
    if (!wrap) return;
    var shouldShow = typeof show === 'boolean' ? show : wrap.style.display === 'none';
    wrap.style.display = shouldShow ? 'flex' : 'none';
    if (feedback) feedback.style.display = 'none';
    if (shouldShow && input) {
        input.focus();
    } else if (input) {
        input.value = '';
    }
}

function saveNewRoom(uid, button) {
    var input = document.getElementById('new-room-name-' + uid);
    var feedback = document.getElementById('add-room-feedback-' + uid);
    var roomSelect = document.getElementById('room-' + uid);
    var roomName = input ? input.value.trim() : '';
    if (roomName.length < 2) {
        feedback.className = 'small mt-1 text-danger';
        feedback.textContent = 'Enter a room name with at least 2 characters.';
        feedback.style.display = '';
        return;
    }

    button.disabled = true;
    feedback.className = 'small mt-1 text-muted';
    feedback.textContent = 'Adding room...';
    feedback.style.display = '';

    fetch(window.SEMAS_BASE_URL + '/api/available-rooms.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({csrf_token: MODULE_CSRF, room_name: roomName})
    })
        .then(function(response) {
            return response.json().then(function(data) {
                if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to add the room.');
                return data;
            });
        })
        .then(function(data) {
            var room = data.room;
            document.querySelectorAll('.module-room-select').forEach(function(select) {
                var option = Array.from(select.options).find(function(item) {
                    return String(item.value) === String(room.room_id);
                });
                if (!option) {
                    option = new Option(room.room_name, room.room_id);
                    select.add(option);
                }
                if (select === roomSelect) select.value = String(room.room_id);
            });
            feedback.className = 'small mt-1 text-success';
            feedback.textContent = data.message;
            feedback.style.display = '';
            document.getElementById('add-room-' + uid).style.display = 'none';
            input.value = '';
        })
        .catch(function(error) {
            feedback.className = 'small mt-1 text-danger';
            feedback.textContent = error.message;
            feedback.style.display = '';
        })
        .finally(function() {
            button.disabled = false;
        });
}

// ── Enroll student lookup ──────────────────────────────────────────────
function lookupStudent(modId) {
    var regNum = document.getElementById('enrollReg-' + modId).value.trim();
    var errEl  = document.getElementById('enrollError-' + modId);
    errEl.style.display = 'none';
    if (!regNum) { errEl.textContent = 'Please enter a registration number.'; errEl.style.display = ''; return; }

    fetch(window.SEMAS_BASE_URL + '/api/student-lookup?reg_number=' + encodeURIComponent(regNum) + '&module_id=' + modId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) {
                errEl.textContent = data.message;
                errEl.style.display = '';
                return;
            }
            var s = data.student;
            var cardHtml = '<img src="' + s.photo_url + '" style="width:56px;height:56px;border-radius:50%;object-fit:cover;flex-shrink:0;">'
                + '<div><div class="fw-semibold">' + escHtml(s.full_name) + '</div>'
                + '<div class="small text-muted">' + escHtml(s.reg_number) + '</div>'
                + '<div class="small text-muted">' + escHtml(s.department) + (s.intake !== '/' ? ' · ' + escHtml(s.intake) : '') + '</div></div>';
            if (!data.enrolled && data.ongoing_limit_reached) {
                errEl.textContent = data.ongoing_limit_message || 'This student already has the maximum number of ongoing modules.';
                errEl.style.display = '';
                return;
            }
            if (!data.enrolled && data.session_conflict) {
                errEl.textContent = data.session_conflict_message || 'This student is already registered in the same session.';
                errEl.style.display = '';
                return;
            }
            document.getElementById('enrollSearch-' + modId).style.display = 'none';
            if (data.enrolled) {
                document.getElementById('enrollAlreadyCard-' + modId).innerHTML = cardHtml;
                document.getElementById('unrollUserId-' + modId).value = s.user_id;
                document.getElementById('enrollAlready-' + modId).style.display = '';
            } else {
                document.getElementById('enrollCard-' + modId).innerHTML = cardHtml;
                document.getElementById('enrollConfirmReg-' + modId).value = s.reg_number;
                var exceptionWrap = document.getElementById('enrollExceptionWrap-' + modId);
                var exceptionSelect = document.getElementById('enrollException-' + modId);
                if (exceptionWrap && exceptionSelect) {
                    exceptionWrap.style.display = data.completed_same_title ? '' : 'none';
                    exceptionSelect.required = !!data.completed_same_title;
                    if (!data.completed_same_title) exceptionSelect.value = '';
                }
                document.getElementById('enrollPreview-' + modId).style.display = '';
            }
        })
        .catch(function() { errEl.textContent = 'Lookup failed. Please try again.'; errEl.style.display = ''; });
}

function resetEnroll(modId) {
    document.getElementById('enrollSearch-' + modId).style.display = '';
    document.getElementById('enrollPreview-' + modId).style.display = 'none';
    document.getElementById('enrollAlready-' + modId).style.display = 'none';
    document.getElementById('enrollError-' + modId).style.display = 'none';
    var exceptionWrap = document.getElementById('enrollExceptionWrap-' + modId);
    var exceptionSelect = document.getElementById('enrollException-' + modId);
    if (exceptionWrap && exceptionSelect) {
        exceptionWrap.style.display = 'none';
        exceptionSelect.required = false;
        exceptionSelect.value = '';
    }
}

// Allow pressing Enter in reg number field to trigger search
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && e.target && e.target.id && e.target.id.startsWith('enrollReg-')) {
        e.preventDefault();
        var modId = e.target.id.replace('enrollReg-', '');
        lookupStudent(modId);
    }
});
</script>
<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
