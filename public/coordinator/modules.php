<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Coordinator']);
// Use the shared HoD implementation so Coordinator receives the same module
// features. That page applies a strict Weekend-only scope for this role.
redirect('/hod/modules.php');
Module::autoCompleteExpired();

$pageTitle  = 'Weekend Modules';
$activeNav  = 'modules';
$db         = Database::connection();
Semester::enforceAcademicWrite($db);
$me         = Auth::user();
$today      = date('Y-m-d');
$intakeList = availableIntakes();

try {
    $db->exec('ALTER TABLE modules ADD COLUMN weekend_slot VARCHAR(20) NULL DEFAULT NULL');
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) !== 1060) throw $e;
}

function coordAvailableRooms(PDO $db, int $excludeModuleId = 0): array
{
    return $db->query('SELECT room_id, room_name FROM rooms ORDER BY room_name')->fetchAll();
}

function coordRequireWeekendModule(PDO $db, int $moduleId): void
{
    if ($moduleId <= 0) {
        flash('error', 'Invalid module selected.');
        redirect('/coordinator/modules.php');
    }
    $stmt = $db->prepare("SELECT 1 FROM modules WHERE module_id = :id AND session_type = 'Weekend'");
    $stmt->execute(['id' => $moduleId]);
    if (!$stmt->fetchColumn()) {
        flash('error', 'Coordinators can only manage Weekend modules.');
        redirect('/coordinator/modules.php');
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
        $lecUserId = (int) ($_POST['lecturer_user_id'] ?? 0);
        $roomId    = (int) ($_POST['room_id'] ?? 0) ?: null;
        $modId     = $action === 'update' ? (int) $_POST['module_id'] : 0;
        $weekendSlot = in_array($_POST['weekend_slot'] ?? '', ['Morning', 'Afternoon'], true) ? $_POST['weekend_slot'] : null;
        if ($action === 'update') {
            coordRequireWeekendModule($db, $modId);
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

        // All department IDs come in as dept_ids[] (first = primary)
        $deptIds = array_map('intval', array_filter($_POST['dept_ids'] ?? []));
        $primaryDeptId = $deptIds[0] ?? 0;
        $extraDeptIds  = array_slice($deptIds, 1);

        if (!$lecId)          { flash('error', 'A lecturer must be assigned.');   redirect('/coordinator/modules.php'); }
        if (!$weekendSlot)    { flash('error', 'Weekend modules must use either Morning or Afternoon.'); redirect('/coordinator/modules.php'); }
        if (!$primaryDeptId)  { flash('error', 'At least one department is required.'); redirect('/coordinator/modules.php'); }
        if (!$startDate || !$endDate || !$catDate || !$examDate) { flash('error', 'All dates are required.'); redirect('/coordinator/modules.php'); }
        if ($startDate < $today && $action === 'create') { flash('error', 'Start date cannot be in the past.'); redirect('/coordinator/modules.php'); }
        if ($startDate > $catDate || $catDate > $examDate || $examDate > $endDate) { flash('error', 'Dates must follow: Start ≤ CAT ≤ Exam ≤ End.'); redirect('/coordinator/modules.php'); }

        // Validate intakes
        $intakes = array_filter($_POST['intakes'] ?? [], 'isValidIntakeCode');

        // Lecturer session constraint. Weekend Morning and Weekend Afternoon are separate sessions.
        $lecturerConflict = Module::lecturerOngoingSessionConflict($db, $lecId, 'Weekend', $weekendSlot, $modId);
        if ($lecturerConflict) {
            flash('error', 'This lecturer already has an Ongoing ' . Module::sessionLabel($lecturerConflict) . ' module.');
            redirect('/coordinator/modules.php');
        }

        // Room conflict
        if ($roomId) {
            $rc = $db->prepare(
                "SELECT module_title, session_type, weekend_slot FROM modules
                 WHERE room_id=:r AND session_type='Weekend'
                   AND (COALESCE(weekend_slot, '')=:slot OR COALESCE(weekend_slot, '')='')
                   AND status='Ongoing' AND module_id!=:mid
                 LIMIT 1"
            );
            $rc->execute(['r' => $roomId, 'slot' => $weekendSlot, 'mid' => $modId]);
            $roomConflict = $rc->fetch();
            if ($roomConflict) {
                flash('error', 'This room is already assigned to another Ongoing ' . Module::sessionLabel($roomConflict) . ' module.');
                redirect('/coordinator/modules.php');
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
                'session' => 'Weekend', 'wslot' => $weekendSlot, 'room' => $roomId,
                'cat' => $catDate, 'exam' => $examDate, 'qr' => $qrSecret,
                'start' => $startDate, 'end' => $endDate, 'uid' => $me['user_id'],
            ]);
            $modId = (int) $db->lastInsertId();
        } else {
            $db->prepare(
                'UPDATE modules SET module_title=:title, department_id=:dept, lecturer_id=:lec,
                 weekend_slot=:wslot, room_id=:room, cat_date=:cat, exam_date=:exam, start_date=:start, end_date=:end
                 WHERE module_id=:id'
            )->execute([
                'title' => trim($_POST['module_title']), 'dept' => $primaryDeptId, 'lec' => $lecId,
                'wslot' => $weekendSlot, 'room' => $roomId, 'cat' => $catDate, 'exam' => $examDate,
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
        redirect('/coordinator/modules.php');
    }

    if ($action === 'reopen') {
        $modId = (int) $_POST['module_id'];
        coordRequireWeekendModule($db, $modId);
        $db->prepare("UPDATE modules SET status='Ongoing' WHERE module_id=:id")->execute(['id' => $modId]);
        flash('success', 'Module reopened.');
        redirect('/coordinator/modules.php');
    }

    if ($action === 'delete_module') {
        $modId = (int) $_POST['module_id'];
        coordRequireWeekendModule($db, $modId);
        Module::deleteModule($db, $modId);
        AuditLog::record(Auth::id(), 'MODULE_DELETE', 'modules', $modId);
        flash('success', 'Module deleted.');
        redirect('/coordinator/modules.php');
    }

    if ($action === 'enroll_student') {
        $modId  = (int) $_POST['module_id'];
        coordRequireWeekendModule($db, $modId);
        $regNum = trim($_POST['search_reg_number'] ?? '');
        $stuStmt = $db->prepare("SELECT u.user_id, u.full_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student' AND u.reg_number=:rn AND u.status='Active'");
        $stuStmt->execute(['rn' => $regNum]);
        $stu = $stuStmt->fetch();
        if (!$stu) {
            flash('error', "No active student found with reg number: {$regNum}");
        } else {
            $disciplinaryOverride = Module::isDisciplinarilyBlocked($db, $modId, (int) $stu['user_id']);
            $activeDisciplinaryModule = Module::activeDisciplinaryModule($db, (int) $stu['user_id']);
            $disciplinaryOverride = $disciplinaryOverride || (bool) $activeDisciplinaryModule;
            $registrationModuleStmt = $db->prepare('SELECT module_title, start_date FROM modules WHERE module_id = :mid');
            $registrationModuleStmt->execute(['mid' => $modId]);
            $registrationModule = $registrationModuleStmt->fetch() ?: [];
            if (!Module::isStudentRegistrationOpen($registrationModule)) {
                flash('error', Module::lateRegistrationMessage($registrationModule));
                redirect('/coordinator/modules.php');
            }
            if (!Module::canAddOngoingEnrollment((int) $stu['user_id'], $modId)) {
                flash('error', Module::ongoingEnrollmentLimitMessage($stu['full_name']));
                redirect('/coordinator/modules.php');
            }
            $sessionConflict = Module::studentOngoingSessionConflict($db, (int) $stu['user_id'], $modId);
            if ($sessionConflict) {
                flash('error', $stu['full_name'] . ' is already registered for "' . $sessionConflict['module_title'] . '" in the same ' . Module::sessionLabel($sessionConflict) . ' session.');
                redirect('/coordinator/modules.php');
            }
            try {
                $db->prepare('INSERT INTO module_enrollments (module_id, user_id) VALUES (:m,:u)')->execute(['m' => $modId, 'u' => $stu['user_id']]);
                AuditLog::record(
                    Auth::id(),
                    $disciplinaryOverride ? 'MODULE_ENROLL_DISCIPLINARY_OVERRIDE' : 'MODULE_ENROLL',
                    'modules',
                    $modId,
                    "user_id={$stu['user_id']}"
                );
                flash('success', $stu['full_name'] . ' enrolled successfully'
                    . ($disciplinaryOverride ? ' by authorised disciplinary override.' : '.') );
            } catch (PDOException $e) {
                flash('error', $e->getCode() === '23000' ? 'Student already enrolled.' : 'Enrollment failed.');
            }
        }
        redirect('/coordinator/modules.php');
    }

    if ($action === 'de_register') {
        $modId  = (int) $_POST['module_id'];
        $userId = (int) $_POST['user_id'];
        coordRequireWeekendModule($db, $modId);
        Module::removeEnrollment($db, $modId, $userId);
        AuditLog::record(Auth::id(), 'MODULE_UNENROLL', 'modules', $modId, "user_id=$userId");
        flash('success', 'Student unenrolled and removed from this module attendance.');
        redirect('/coordinator/modules.php');
    }

    redirect('/coordinator/modules.php');
}

// ── Fetch data ─────────────────────────────────────────────────────────
$search       = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$where        = ["m.session_type = 'Weekend'"];
$params       = [];
if ($search)       { $where[] = 'm.module_title LIKE :q';   $params['q']      = "%$search%"; }
if ($statusFilter) { $where[] = 'm.status = :status';       $params['status'] = $statusFilter; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name, u.user_id AS lecturer_user_id, r.room_name,
        (SELECT COUNT(*) FROM module_enrollments e WHERE e.module_id=m.module_id) AS student_count,
        (SELECT GROUP_CONCAT(mi.intake ORDER BY mi.intake SEPARATOR ', ') FROM module_intakes mi WHERE mi.module_id=m.module_id) AS intakes_list
     FROM modules m
     LEFT JOIN departments d ON d.department_id=m.department_id
     LEFT JOIN lecturers l ON l.lecturer_id=m.lecturer_id
     LEFT JOIN users u ON u.user_id=l.user_id
     LEFT JOIN rooms r ON r.room_id=m.room_id
     $whereSql ORDER BY m.created_at DESC"
);
$stmt->execute($params);
$modules = $stmt->fetchAll();

$departments = $db->query('SELECT d.department_id, d.department_name, d.department_code, f.faculty_name FROM departments d JOIN faculties f ON f.faculty_id=d.faculty_id ORDER BY f.faculty_name, d.department_name')->fetchAll();
$allRooms    = coordAvailableRooms($db);
$lecturers   = $db->query(
    "SELECT u.user_id, u.full_name, r.role_name, COALESCE(l.lecturer_id, 0) AS lecturer_id
     FROM users u
     JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN lecturers l ON l.user_id = u.user_id
     WHERE u.status = 'Active' AND r.role_name IN ('Lecturer','HOD','Coordinator')
     ORDER BY u.full_name"
)->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h4 class="display-font mb-1">Weekend Modules</h4>
  </div>
  <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#newModuleModal">
    <i class="bi bi-plus-circle me-1"></i> New Weekend Module
  </button>
</div>

<div class="semas-card p-3 mb-3">
  <form method="GET" class="row g-2">
    <div class="col-md-7"><input name="q" class="form-control form-control-sm" value="<?= e($search) ?>"></div>
    <div class="col-md-3">
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <option value="Ongoing"   <?= $statusFilter==='Ongoing'   ?'selected':'' ?>>Ongoing</option>
        <option value="Completed" <?= $statusFilter==='Completed' ?'selected':'' ?>>Completed</option>
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
      <?php foreach ($modules as $m):
        $mId = (int) $m['module_id'];
        $modIntakes   = $db->prepare('SELECT intake FROM module_intakes WHERE module_id=:mid');
        $modIntakes->execute(['mid' => $mId]);
        $modIntakesArr = $modIntakes->fetchAll(PDO::FETCH_COLUMN);

        $modExtraDepts = $db->prepare('SELECT md.department_id, d.department_name FROM module_departments md JOIN departments d ON d.department_id=md.department_id WHERE md.module_id=:mid');
        $modExtraDepts->execute(['mid' => $mId]);
        $modExtraDeptRows = $modExtraDepts->fetchAll();

        $editRooms = coordAvailableRooms($db, $mId);
      ?>
        <tr>
          <td class="fw-semibold"><?= e($m['module_title']) ?></td>
          <td><?= e(Module::sessionLabel($m)) ?></td>
          <td><?= e($m['department_name'] ?? '/') ?></td>
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
            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this weekend module and all its attendance/enrollment records?')">
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
          // Build combined dept list: primary first, then extras
          $editDepts = [['department_id' => $m['department_id'], 'department_name' => $m['department_name']]];
          foreach ($modExtraDeptRows as $ed) {
              if ($ed['department_id'] != $m['department_id']) $editDepts[] = $ed;
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
                  <?= moduleFormFields("e{$mId}", $lecturers, $editRooms, $intakeList, $m, $editDepts, $today, $m['room_id']) ?>
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
      <?php if (!$modules): ?>
        <tr><td colspan="11" class="text-muted small text-center py-3">No Weekend modules yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- New Module Modal -->
<div class="modal fade" id="newModuleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="session_type" value="Weekend">
        <div class="modal-header"><h6 class="modal-title display-font">New Weekend Module</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= moduleFormFields('new', $lecturers, $allRooms, $intakeList, [], [], $today, null) ?>
        </div>
        <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Create Module</button></div>
      </form>
    </div>
  </div>
</div>

<?php
/**
 * Renders the shared module form fields for create/edit modals.
 * @param string  $uid          Unique prefix to avoid duplicate HTML IDs
 * @param array[] $lecturers
 * @param array[] $rooms        Already-filtered available rooms
 * @param string[] $intakeList  e.g. ['JAN24','MAY24',…,'MAY26']
 * @param array   $mod          Existing module row (empty for create)
 * @param array[] $selectedDepts Depts already on this module (primary first)
 * @param string  $today
 * @param int|null $currentRoomId
 */
function moduleFormFields(string $uid, array $lecturers, array $rooms, array $intakeList,
                          array $mod, array $selectedDepts, string $today, ?int $currentRoomId): string
{
    ob_start(); ?>
    <div class="row g-2">

      <!-- Title -->
      <div class="col-12">
        <label class="form-label small fw-semibold">Module Title <span class="text-danger">*</span></label>
        <input name="module_title" class="form-control form-control-sm" required value="<?= e($mod['module_title'] ?? '') ?>">
      </div>

      <!-- Department search+add -->
      <div class="col-12">
        <label class="form-label small fw-semibold">Departments <span class="text-danger">*</span> <span class="text-muted small fw-normal">(first added = primary)</span></label>
        <div class="dept-tags d-flex flex-wrap gap-1 mb-1" id="deptTags-<?= $uid ?>">
          <?php foreach ($selectedDepts as $sd): ?>
            <span class="badge bg-primary d-flex align-items-center gap-1" data-dept-id="<?= $sd['department_id'] ?>">
              <?= e($sd['department_name']) ?>
              <button type="button" class="btn-close btn-close-white" style="font-size:.6rem;" onclick="removeDept(this,'<?= $uid ?>')"></button>
              <input type="hidden" name="dept_ids[]" value="<?= $sd['department_id'] ?>">
            </span>
          <?php endforeach; ?>
        </div>
        <div class="position-relative">
          <input type="text" class="form-control form-control-sm dept-search-input" id="deptSearch-<?= $uid ?>"
            autocomplete="off" data-uid="<?= $uid ?>">
          <div class="dept-dropdown border rounded bg-white shadow-sm" id="deptDD-<?= $uid ?>"
            style="display:none;position:absolute;z-index:1050;width:100%;max-height:180px;overflow-y:auto;"></div>
        </div>
      </div>

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

      <div class="col-md-6">
        <label class="form-label small fw-semibold">Weekend Session <span class="text-danger">*</span></label>
        <?php $currentSlot = $mod['weekend_slot'] ?? ''; ?>
        <select name="weekend_slot" class="form-select form-select-sm" required>
          <option value="">Select slot</option>
          <option value="Morning" <?= $currentSlot === 'Morning' ? 'selected' : '' ?>>Weekend Morning</option>
          <option value="Afternoon" <?= $currentSlot === 'Afternoon' ? 'selected' : '' ?>>Weekend Afternoon</option>
        </select>
      </div>

      <!-- Room -->
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Room <span class="text-muted small">(Weekend-available)</span></label>
        <select name="room_id" class="form-select form-select-sm">
          <option value="">/ No room /</option>
          <?php
          $shownRoomIds = array_column($rooms, 'room_id');
          foreach ($rooms as $rm): ?>
            <option value="<?= $rm['room_id'] ?>" <?= ($mod['room_id'] ?? null)==$rm['room_id']?'selected':'' ?>><?= e($rm['room_name']) ?></option>
          <?php endforeach;
          if ($currentRoomId && !in_array($currentRoomId, $shownRoomIds)): ?>
            <option value="<?= $currentRoomId ?>" selected>[Current: <?= e($mod['room_name'] ?? '') ?>]</option>
          <?php endif; ?>
        </select>
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
        <input type="date" name="start_date" class="form-control form-control-sm" min="<?= $today ?>" required value="<?= e($mod['start_date'] ?? '') ?>"></div>
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

// Department JSON for JS search
$deptJson = json_encode(array_values(array_map(function($d) {
    return [
        'id'     => (int) $d['department_id'],
        'name'   => $d['department_name'],
        'code'   => $d['department_code'],
        'faculty'=> $d['faculty_name'],
    ];
}, $departments)));

// Intake data per module for pre-checking edit modals
$moduleIntakesMap = [];
foreach ($modules as $m) {
    $is = $db->prepare('SELECT intake FROM module_intakes WHERE module_id=:mid');
    $is->execute(['mid' => $m['module_id']]);
    $moduleIntakesMap[(int)$m['module_id']] = $is->fetchAll(PDO::FETCH_COLUMN);
}
?>

<script>
const DEPTS_DATA = <?= $deptJson ?>;
const MODULE_INTAKES = <?= json_encode($moduleIntakesMap) ?>;

// Pre-check intakes for each edit modal
document.addEventListener('DOMContentLoaded', function() {
    Object.entries(MODULE_INTAKES).forEach(function([modId, intakes]) {
        intakes.forEach(function(ink) {
            var cb = document.getElementById('ink-e' + modId + '-' + ink);
            if (cb) cb.checked = true;
        });
    });

    // Wire up department search inputs
    document.querySelectorAll('.dept-search-input').forEach(function(input) {
        input.addEventListener('input', function() { showDeptSuggestions(this); });
        input.addEventListener('blur', function() {
            setTimeout(function() {
                var dd = document.getElementById('deptDD-' + input.dataset.uid);
                if (dd) dd.style.display = 'none';
            }, 200);
        });
    });
});

function showDeptSuggestions(input) {
    var uid = input.dataset.uid;
    var q = input.value.toLowerCase().trim();
    var dd = document.getElementById('deptDD-' + uid);
    if (!q) { dd.style.display = 'none'; return; }

    // Get already-selected IDs
    var selected = getSelectedDeptIds(uid);
    var matches = DEPTS_DATA.filter(function(d) {
        return (d.name.toLowerCase().includes(q) || d.code.toLowerCase().includes(q))
            && !selected.includes(d.id);
    }).slice(0, 8);

    if (!matches.length) { dd.innerHTML = '<div class="p-2 small text-muted">No results</div>'; dd.style.display = ''; return; }

    dd.innerHTML = matches.map(function(d) {
        return '<div class="dept-option px-3 py-2 small" style="cursor:pointer;" '
            + 'onmousedown="addDeptById(' + d.id + ',\'' + escHtml(d.name) + '\',\'' + uid + '\')">'
            + '<strong>' + escHtml(d.name) + '</strong> <span class="text-muted">(' + escHtml(d.code) + ') / ' + escHtml(d.faculty) + '</span></div>';
    }).join('');
    dd.style.display = '';
}

function getSelectedDeptIds(uid) {
    var tags = document.getElementById('deptTags-' + uid);
    return Array.from(tags.querySelectorAll('input[name="dept_ids[]"]')).map(function(i) { return parseInt(i.value); });
}

function addDeptById(id, name, uid) {
    var tags = document.getElementById('deptTags-' + uid);
    if (getSelectedDeptIds(uid).includes(id)) return;
    var span = document.createElement('span');
    span.className = 'badge bg-primary d-flex align-items-center gap-1';
    span.setAttribute('data-dept-id', id);
    span.innerHTML = escHtml(name)
        + ' <button type="button" class="btn-close btn-close-white" style="font-size:.6rem;" onclick="removeDept(this,\'' + uid + '\')"></button>'
        + '<input type="hidden" name="dept_ids[]" value="' + id + '">';
    tags.appendChild(span);
    // Clear search
    var inp = document.getElementById('deptSearch-' + uid);
    if (inp) inp.value = '';
    var dd = document.getElementById('deptDD-' + uid);
    if (dd) dd.style.display = 'none';
}

function removeDept(btn, uid) {
    btn.closest('span').remove();
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Highlight hover on dropdown options
document.addEventListener('mouseover', function(e) {
    if (e.target.classList.contains('dept-option') || e.target.closest('.dept-option')) {
        var opt = e.target.classList.contains('dept-option') ? e.target : e.target.closest('.dept-option');
        opt.parentNode.querySelectorAll('.dept-option').forEach(function(o) { o.style.background = ''; });
        opt.style.background = '#f1f5f9';
    }
});

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
