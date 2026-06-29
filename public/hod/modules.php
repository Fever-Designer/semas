<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Coordinator', 'HOD']);
Module::autoCompleteExpired();

$pageTitle  = 'Manage Modules';
$activeNav  = 'modules';
$db         = Database::connection();
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
    if ($sessionType === '') {
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

        // All department IDs come in as dept_ids[] (first = primary)
        $deptIds = array_map('intval', array_filter($_POST['dept_ids'] ?? []));
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
        if (!$lecId)         { $formError('A lecturer must be assigned.'); }
        if (!$primaryDeptId) { $formError('At least one department is required.'); }
        if (!$startDate || !$endDate || !$catDate || !$examDate) { $formError('All dates are required.'); }
        if ($startDate < $today && $action === 'create') { $formError('Start date cannot be in the past.'); }
        if ($startDate > $catDate || $catDate > $examDate || $examDate > $endDate) { $formError('Dates must follow: Start ≤ CAT ≤ Exam ≤ End.'); }

        // Validate intakes
        $intakes = array_filter($_POST['intakes'] ?? [], 'isValidIntakeCode');

        // Lecturer session constraint (Ongoing modules in the same session)
        $lc = $db->prepare("SELECT COUNT(*) FROM modules WHERE lecturer_id=:lec AND session_type=:session AND status='Ongoing' AND module_id!=:mid");
        $lc->execute(['lec' => $lecId, 'session' => $sessionType, 'mid' => $modId]);
        if ((int) $lc->fetchColumn() > 0) {
            $formError('This lecturer already has an Ongoing ' . $sessionType . ' module. One lecturer per session type.');
        }

        // Room conflict
        if ($roomId) {
            $rc = $db->prepare("SELECT COUNT(*) FROM modules WHERE room_id=:r AND session_type=:session AND status='Ongoing' AND module_id!=:mid");
            $rc->execute(['r' => $roomId, 'session' => $sessionType, 'mid' => $modId]);
            if ((int) $rc->fetchColumn() > 0) {
                $formError('This room is already assigned to another Ongoing ' . $sessionType . ' module.');
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
        $db->prepare("UPDATE modules SET status='Ongoing' WHERE module_id=:id")->execute(['id' => $modId]);
        flash('success', 'Module reopened.');
        redirect('/hod/modules.php');
    }

    if ($action === 'enroll_student') {
        $modId  = (int) $_POST['module_id'];
        $regNum = trim($_POST['search_reg_number'] ?? '');
        $stuStmt = $db->prepare("SELECT u.user_id, u.full_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student' AND u.reg_number=:rn AND u.status='Active'");
        $stuStmt->execute(['rn' => $regNum]);
        $stu = $stuStmt->fetch();
        if (!$stu) {
            flash('error', "No active student found with reg number: {$regNum}");
        } else {
            try {
                $db->prepare('INSERT INTO module_enrollments (module_id, user_id) VALUES (:m,:u)')->execute(['m' => $modId, 'u' => $stu['user_id']]);
                flash('success', $stu['full_name'] . ' enrolled successfully.');
            } catch (PDOException $e) {
                flash('error', $e->getCode() === '23000' ? 'Student already enrolled.' : 'Enrollment failed.');
            }
        }
        redirect('/hod/modules.php');
    }

    if ($action === 'de_register') {
        $modId  = (int) $_POST['module_id'];
        $userId = (int) $_POST['user_id'];
        $db->prepare('DELETE FROM module_enrollments WHERE module_id=:m AND user_id=:u')
           ->execute(['m' => $modId, 'u' => $userId]);
        AuditLog::record(Auth::id(), 'MODULE_UNENROLL', 'modules', $modId, "user_id=$userId");
        flash('success', 'Student unenrolled.');
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
    "SELECT m.*, d.department_name, u.full_name AS lecturer_name, u.user_id AS lecturer_user_id, l.title AS lecturer_title, r.room_name,
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
    <div class="col-md-8"><input name="q" class="form-control form-control-sm" placeholder="Search module title" value="<?= e($search) ?>"></div>
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
            $stype = $m['session_type'] ?? '—';
            $slot  = $m['weekend_slot'] ?? '';
            echo e($stype === 'Weekend' && $slot ? "Weekend – $slot" : $stype);
          ?></td>
          <td><?= e($m['department_name'] ?? '—') ?></td>
          <td><?= e($m['lecturer_name'] ?? '—') ?></td>
          <td><?= e($m['room_name'] ?? '—') ?></td>
          <td><small><?= e($m['intakes_list'] ?? '—') ?></small></td>
          <td><?= e($m['cat_date']  ?? '—') ?></td>
          <td><?= e($m['exam_date'] ?? '—') ?></td>
          <td><?= (int) $m['student_count'] ?></td>
          <td><span class="badge <?= $m['status']==='Ongoing'?'badge-completed':'bg-secondary' ?>"><?= e($m['status']) ?></span></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#edit-<?= $mId ?>"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#enroll-<?= $mId ?>"><i class="bi bi-person-plus"></i></button>
            <?php if ($m['module_qr_secret']): ?>
              <a href="<?= APP_URL ?>/hod/module-qr-print.php?module_id=<?= $mId ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-qr-code"></i></a>
            <?php endif; ?>
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
                  <?= moduleFormFields("e{$mId}", $lecturers, $editRooms, $intakeList, $sessionTypes, $m, $editDepts, $today, $m['room_id'], ($reopenModal === 'edit' && $reopenEditId === $mId) ? $mfError : '') ?>
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
                <div class="modal-header"><h6 class="modal-title display-font">Enroll Student — <?= e($m['module_title']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <label class="form-label small fw-semibold">Registration Number <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="text" id="enrollReg-<?= $mId ?>" class="form-control" placeholder="e.g. 2601001192" autocomplete="off">
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
                <td><?= e($m['session_type'] ?? '—') ?></td>
                <td><?= e($m['department_name'] ?? '—') ?></td>
                <td><?= e($m['lecturer_name'] ?? '—') ?></td>
                <td><?= e($m['room_name'] ?? '—') ?></td>
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
            <div class="col-6"><span class="text-muted">Session</span><br><strong><?= e($m['session_type'] ?? '—') ?></strong></div>
            <div class="col-6"><span class="text-muted">Department</span><br><strong><?= e($m['department_name'] ?? '—') ?></strong></div>
            <div class="col-6"><span class="text-muted">Lecturer</span><br><strong><?= e($m['lecturer_name'] ?? '—') ?></strong></div>
            <div class="col-6"><span class="text-muted">Room</span><br><strong><?= e($m['room_name'] ?? '—') ?></strong></div>
            <div class="col-6"><span class="text-muted">CAT</span><br><strong><?= e($m['cat_date'] ?? '—') ?></strong></div>
            <div class="col-6"><span class="text-muted">Exam</span><br><strong><?= e($m['exam_date'] ?? '—') ?></strong></div>
            <div class="col-12"><span class="text-muted">Intakes</span><br><strong><?= e($m['intakes_list'] ?? '—') ?></strong></div>
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
          ?>
          <?= moduleFormFields('new', $lecturers, $allRooms, $intakeList, $sessionTypes, $createPreFill, [], $today, null, $createError) ?>
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
 * @param string[] $sessionTypes
 * @param array   $mod          Existing module row (empty for create)
 * @param array[] $selectedDepts Depts already on this module (primary first)
 * @param string  $today
 * @param int|null $currentRoomId
 */
function moduleFormFields(string $uid, array $lecturers, array $rooms, array $intakeList,
                          array $sessionTypes, array $mod, array $selectedDepts, string $today, ?int $currentRoomId, string $error = ''): string
{
    $isCreate = empty($mod['module_id']);
    ob_start(); ?>
    <?php if ($error !== ''): ?>
      <div class="alert alert-danger alert-sm small py-2 mb-2"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="row g-2">

      <!-- Title -->
      <div class="col-12">
        <label class="form-label small fw-semibold">Module Title <span class="text-danger">*</span></label>
        <input name="module_title" class="form-control form-control-sm" required value="<?= e($mod['module_title'] ?? '') ?>" placeholder="e.g. Database Systems II">
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
            placeholder="Type department name…" autocomplete="off" data-uid="<?= $uid ?>">
          <div class="dept-dropdown border rounded bg-white shadow-sm" id="deptDD-<?= $uid ?>"
            style="display:none;position:absolute;z-index:1050;width:100%;max-height:180px;overflow-y:auto;"></div>
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
        <select name="lecturer_id" class="form-select form-select-sm" required>
          <option value="">Select lecturer</option>
          <?php foreach ($lecturers as $l): ?>
            <option value="<?= $l['lecturer_id'] ?>" <?= ($mod['lecturer_id'] ?? 0)==$l['lecturer_id']?'selected':'' ?>><?= e($l['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Room -->
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Room <span class="text-muted small">(available for selected session)</span></label>
        <select name="room_id" class="form-select form-select-sm module-room-select" id="room-<?= $uid ?>" data-current-room-id="<?= (int) ($currentRoomId ?? 0) ?>">
          <option value="">— TBC —</option>
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
        <input type="date" name="start_date" class="form-control form-control-sm" <?= $isCreate ? 'min="' . $today . '"' : '' ?> required value="<?= e($mod['start_date'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label small fw-semibold">End Date <span class="text-danger">*</span></label>
        <input type="date" name="end_date" class="form-control form-control-sm" min="<?= $today ?>" required value="<?= e($mod['end_date'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label small fw-semibold">CAT Date <span class="text-danger">*</span></label>
        <input type="date" name="cat_date" class="form-control form-control-sm" min="<?= $today ?>" required value="<?= e($mod['cat_date'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label small fw-semibold">Exam Date <span class="text-danger">*</span></label>
        <input type="date" name="exam_date" class="form-control form-control-sm" min="<?= $today ?>" required value="<?= e($mod['exam_date'] ?? '') ?>"></div>
      <div class="col-12"><div class="form-text" style="font-size:.7rem;">Start ≤ CAT ≤ Exam ≤ End.</div></div>
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
            var options = ['<option value="">— TBC —</option>'];
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
            + '<strong>' + escHtml(d.name) + '</strong> <span class="text-muted">(' + escHtml(d.code) + ') — ' + escHtml(d.faculty) + '</span></div>';
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

    fetch(window.SEMAS_BASE_URL + '/api/student-lookup.php?reg_number=' + encodeURIComponent(regNum) + '&module_id=' + modId)
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
                + '<div class="small text-muted">' + escHtml(s.department) + (s.intake !== '—' ? ' · ' + escHtml(s.intake) : '') + '</div></div>';
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
