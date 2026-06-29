<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Registrar']);

$pageTitle = 'Manage Students';
$activeNav = 'students';
$db = Database::connection();
$me = Auth::user();

// Get student role_id
$studentRoleId = (int) $db->query("SELECT role_id FROM roles WHERE role_name='Student' LIMIT 1")->fetchColumn();

/**
 * Detect intake from a University of Kigali registration number.
 * Format: [YYY][N]XXXXXX where YYY = year code (260=2026, 250=2025 …)
 * and N (4th character, index 3) = 1→JAN, 5→MAY, 9→SEPT.
 */
// Alias for the shared IntakeHelper function — kept for backward compat with import code
function detectIntakeFromRegNumber(string $regNumber): ?string
{
    return detectIntakeCode($regNumber);
}

// One-time migration: upgrade old 3-char intake codes (JAN/MAY/SEPT) to year-coded (JAN24/MAY24/SEPT24)
(function () use ($db): void {
    $rows = $db->query(
        "SELECT user_id, reg_number FROM users
         WHERE intake IN ('JAN','MAY','SEPT') AND reg_number IS NOT NULL AND reg_number != ''"
    )->fetchAll();
    foreach ($rows as $r) {
        $newIntake = detectIntakeCode((string) $r['reg_number']);
        if ($newIntake) {
            $db->prepare('UPDATE users SET intake = :i WHERE user_id = :id')
               ->execute(['i' => $newIntake, 'id' => (int) $r['user_id']]);
        }
    }
})();

// -------------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---- ADD or UPDATE a single student ----
    if ($action === 'create_student') {
        $regNumber  = trim($_POST['reg_number'] ?? '');
        $fullName   = trim($_POST['full_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone_number'] ?? '') ?: null;
        $deptId     = (int) ($_POST['department_id'] ?? 0) ?: null;
        $sessionType = trim($_POST['session_type'] ?? '') ?: null;
        $yearStudy  = (int) ($_POST['year_of_study'] ?? 0) ?: null;

        // Always auto-detect intake from reg number
        $intake = detectIntakeCode($regNumber);

        if (!$regNumber || !$fullName || !$email) {
            flash('error', 'Registration number, full name and email are required.');
            redirect('/registrar/students.php');
        }

        // Check if student already exists (by reg_number)
        $exists = $db->prepare('SELECT user_id FROM users WHERE reg_number = :rn');
        $exists->execute(['rn' => $regNumber]);
        $existingUser = $exists->fetch();

        if ($existingUser) {
            // Update existing student
            $db->prepare(
                'UPDATE users SET full_name=:name, email=:email, phone_number=:phone,
                 department_id=:dept, session_type=:session, intake=:intake,
                 year_of_study=:year, updated_at=NOW() WHERE user_id=:id'
            )->execute([
                'name'    => $fullName,
                'email'   => $email,
                'phone'   => $phone,
                'dept'    => $deptId,
                'session' => $sessionType,
                'intake'  => $intake,
                'year'    => $yearStudy,
                'id'      => (int) $existingUser['user_id'],
            ]);
            AuditLog::record(Auth::id(), 'STUDENT_UPDATE', 'users', (int) $existingUser['user_id']);
            flash('success', "Student {$fullName} ({$regNumber}) updated successfully.");
        } else {
            // Create new student — password = reg_number
            $emailExists = $db->prepare('SELECT user_id FROM users WHERE email = :e');
            $emailExists->execute(['e' => $email]);
            if ($emailExists->fetch()) {
                flash('error', 'An account with this email already exists.');
                redirect('/registrar/students.php');
            }
            $hash = password_hash($regNumber, PASSWORD_BCRYPT);
            $db->prepare(
                'INSERT INTO users (role_id, department_id, reg_number, full_name, email, phone_number,
                 password_hash, status, email_verified_at, must_change_password, session_type,
                 intake, year_of_study, created_by)
                 VALUES (:rid, :dept, :rn, :name, :email, :phone, :hash, :status, NOW(), 1,
                 :session, :intake, :year, :created_by)'
            )->execute([
                'rid'        => $studentRoleId,
                'dept'       => $deptId,
                'rn'         => $regNumber,
                'name'       => $fullName,
                'email'      => $email,
                'phone'      => $phone,
                'hash'       => $hash,
                'status'     => 'Active',
                'session'    => $sessionType,
                'intake'     => $intake,
                'year'       => $yearStudy,
                'created_by' => $me['user_id'],
            ]);
            $newUserId = (int) $db->lastInsertId();
            AuditLog::record(Auth::id(), 'STUDENT_CREATE', 'users', $newUserId);

            // Send credentials email
            try {
                Mailer::send($email, 'Your SEMAS Login Credentials', 'student_credentials', [
                    'full_name'  => $fullName,
                    'reg_number' => $regNumber,
                    'password'   => $regNumber,
                    'login_url'  => APP_URL . '/auth/login.php',
                ], $newUserId);
            } catch (Exception $e) {
                // Email failure is non-fatal
            }
            flash('success', "Student {$fullName} ({$regNumber}) registered. Login credentials sent to {$email}.");
        }
        redirect('/registrar/students.php');
    }

    // ---- TOGGLE ACTIVE / DEACTIVATED ----
    if ($action === 'toggle_status') {
        $userId = (int) $_POST['user_id'];
        $stmt = $db->prepare('SELECT status FROM users WHERE user_id=:id');
        $stmt->execute(['id' => $userId]);
        $cur = $stmt->fetchColumn();
        $newStatus = ($cur === 'Active') ? 'Deactivated' : 'Active';
        $db->prepare('UPDATE users SET status=:s WHERE user_id=:id')->execute(['s' => $newStatus, 'id' => $userId]);
        AuditLog::record(Auth::id(), 'STUDENT_' . strtoupper($newStatus), 'users', $userId);
        flash('success', "Student account " . strtolower($newStatus) . ".");
        redirect('/registrar/students.php');
    }

    // ---- BULK IMPORT (CSV / EXCEL) ----
    if ($action === 'import_preview' && isset($_FILES['import_file'])) {
        $file = $_FILES['import_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Upload failed. Please try again.');
            redirect('/registrar/students.php');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $rows = [];
        $parseError = null;

        if ($ext === 'csv') {
            $handle = fopen($file['tmp_name'], 'r');
            $headers = null;
            while (($line = fgetcsv($handle)) !== false) {
                if ($headers === null) { $headers = array_map('trim', $line); continue; }
                if (count($line) < 2) continue;
                $map = @array_combine($headers, $line);
                if ($map) $rows[] = array_map('trim', $map);
            }
            fclose($handle);
        } elseif (in_array($ext, ['xlsx', 'xls'], true)) {
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file['tmp_name']);
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($file['tmp_name']);
                $sheet = $spreadsheet->getActiveSheet();
                $data = $sheet->toArray(null, true, true, false);
                $headers = null;
                foreach ($data as $row) {
                  $row = array_map(function ($v) { return trim((string) $v); }, $row);
                  if (!array_filter($row)) continue;
                  if ($headers === null) { $headers = $row; continue; }
                  $map = @array_combine($headers, $row);
                  if ($map) $rows[] = $map;
                }
            } catch (Exception $e) {
                $parseError = 'Could not read Excel file: ' . $e->getMessage();
            }
        } else {
            flash('error', 'Only CSV, XLS, or XLSX files are accepted.');
            redirect('/registrar/students.php');
        }

        if ($parseError) {
            flash('error', $parseError);
            redirect('/registrar/students.php');
        }

        // Store rows in session for preview/confirm step
        $_SESSION['import_rows'] = $rows;
        redirect('/registrar/students.php?import_preview=1');
    }

    // ---- CONFIRM BULK IMPORT ----
    if ($action === 'import_confirm') {
        $rows = $_SESSION['import_rows'] ?? [];
        unset($_SESSION['import_rows']);
        $created = 0; $updated = 0; $failed = 0; $failedRows = [];

        foreach ($rows as $idx => $row) {
            $regNum   = trim($row['reg_number'] ?? $row['RegNumber'] ?? $row['Reg Number'] ?? $row['REG_NUMBER'] ?? '');
            $name     = trim($row['full_name']  ?? $row['FullName']  ?? $row['Full Name']  ?? $row['NAME'] ?? '');
            $email    = trim($row['email']       ?? $row['Email']     ?? $row['EMAIL'] ?? '');
            $phone    = trim($row['phone']       ?? $row['Phone'] ?? $row['phone_number'] ?? '') ?: null;
            $dept     = trim($row['department_code'] ?? $row['DeptCode'] ?? $row['dept_code'] ?? '') ?: null;
            $session  = trim($row['session_type'] ?? $row['Session'] ?? '') ?: null;
            $intake   = trim($row['intake'] ?? $row['Intake'] ?? '') ?: null;
            $year     = (int) ($row['year_of_study'] ?? $row['Year'] ?? 0) ?: null;
            // Auto-detect intake from reg number if not in the file
            if (!$intake && $regNum) $intake = detectIntakeFromRegNumber($regNum);

            if (!$regNum || !$name || !$email) {
                $failed++;
                $failedRows[] = "Row " . ($idx + 2) . ": Missing reg_number, full_name, or email.";
                continue;
            }

            // Resolve department by code
            $deptId = null;
            if ($dept) {
                $dStmt = $db->prepare('SELECT department_id FROM departments WHERE department_code = :code');
                $dStmt->execute(['code' => $dept]);
                $deptId = $dStmt->fetchColumn() ?: null;
            }

            $existing = $db->prepare('SELECT user_id FROM users WHERE reg_number = :rn');
            $existing->execute(['rn' => $regNum]);
            $existRow = $existing->fetch();

            try {
                if ($existRow) {
                    $db->prepare(
                        'UPDATE users SET full_name=:n, email=:e, phone_number=:p, department_id=:d,
                         session_type=:s, intake=:i, year_of_study=:y WHERE user_id=:id'
                    )->execute(['n' => $name, 'e' => $email, 'p' => $phone, 'd' => $deptId,
                                's' => $session, 'i' => $intake, 'y' => $year, 'id' => (int) $existRow['user_id']]);
                    $updated++;
                } else {
                    $emailCheck = $db->prepare('SELECT user_id FROM users WHERE email=:e');
                    $emailCheck->execute(['e' => $email]);
                    if ($emailCheck->fetch()) {
                        $failed++;
                        $failedRows[] = "Row " . ($idx + 2) . ": Email {$email} already in use by another account.";
                        continue;
                    }
                    $hash = password_hash($regNum, PASSWORD_BCRYPT);
                    $db->prepare(
                        'INSERT INTO users (role_id, department_id, reg_number, full_name, email, phone_number,
                         password_hash, status, email_verified_at, must_change_password, session_type,
                         intake, year_of_study, created_by)
                         VALUES (:rid, :d, :rn, :n, :e, :p, :h, :st, NOW(), 1, :s, :i, :y, :cb)'
                    )->execute([
                        'rid' => $studentRoleId, 'd' => $deptId, 'rn' => $regNum, 'n' => $name,
                        'e' => $email, 'p' => $phone, 'h' => $hash, 'st' => 'Active',
                        's' => $session, 'i' => $intake, 'y' => $year, 'cb' => $me['user_id'],
                    ]);
                    $newId = (int) $db->lastInsertId();
                    // Send credentials email silently
                    try {
                        Mailer::send($email, 'Your SEMAS Login Credentials', 'student_credentials', [
                            'full_name' => $name, 'reg_number' => $regNum,
                            'password'  => $regNum,
                            'login_url' => APP_URL . '/auth/login.php',
                        ], $newId);
                    } catch (Exception $ignore) {}
                    $created++;
                }
            } catch (Exception $ex) {
                $failed++;
                $failedRows[] = "Row " . ($idx + 2) . " ({$regNum}): " . $ex->getMessage();
            }
        }

        AuditLog::record(Auth::id(), 'STUDENT_BULK_IMPORT', 'users', null,
            "created={$created}; updated={$updated}; failed={$failed}");

        $msg = "Import complete — {$created} created, {$updated} updated, {$failed} failed.";
        if ($failedRows) {
            $msg .= ' Failures: ' . implode(' | ', array_slice($failedRows, 0, 5));
        }
        flash('success', $msg);
        redirect('/registrar/students.php');
    }
}

// -------------------------------------------------------------------
// Fetch data for display
// -------------------------------------------------------------------
$showPreview = isset($_GET['import_preview']) && !empty($_SESSION['import_rows']);
$importRows  = $showPreview ? ($_SESSION['import_rows'] ?? []) : [];

$departments = $db->query('SELECT d.*, f.faculty_name FROM departments d JOIN faculties f ON f.faculty_id=d.faculty_id ORDER BY f.faculty_name, d.department_name')->fetchAll();

// Search / filter
$search    = trim($_GET['q'] ?? '');
$filterDept= (int) ($_GET['dept'] ?? 0);
$filterStatus= trim($_GET['status'] ?? '');
$filterIntake= trim($_GET['intake'] ?? '');

$where = ["r.role_name = 'Student'"];
$params = [];
if ($search) {
    $where[] = "(u.full_name LIKE :q1 OR u.email LIKE :q2 OR u.reg_number LIKE :q3)";
    $params['q1'] = $params['q2'] = $params['q3'] = "%{$search}%";
}
if ($filterDept) { $where[] = "u.department_id = :dept"; $params['dept'] = $filterDept; }
if ($filterStatus) { $where[] = "u.status = :status"; $params['status'] = $filterStatus; }
if ($filterIntake) {
    // Support both exact code (JAN24) and group prefix (JAN → all JAN*)
    if (isValidIntakeCode($filterIntake)) {
        $where[] = "u.intake = :intake"; $params['intake'] = $filterIntake;
    } else {
        $where[] = "u.intake LIKE :intake"; $params['intake'] = $filterIntake . '%';
    }
}

$sql = "SELECT u.*, d.department_name, d.department_code, f.faculty_name
        FROM users u JOIN roles r ON r.role_id=u.role_id
        LEFT JOIN departments d ON d.department_id=u.department_id
        LEFT JOIN faculties f ON f.faculty_id=d.faculty_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY u.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>

<?php if ($showPreview): ?>
<!-- ============================================================
     IMPORT PREVIEW
     ============================================================ -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="display-font mb-0">Import Preview</h4>
  <a href="<?= APP_URL ?>/registrar/students.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Cancel</a>
</div>
<div class="semas-card p-3 mb-3">
  <p class="mb-2 small text-muted">Review the records below before saving. Existing students (matched by Reg Number) will be <strong>updated</strong>. New ones will be <strong>created</strong>.</p>
  <div class="alert alert-info small mb-2"><i class="bi bi-info-circle me-1"></i> <strong><?= count($importRows) ?></strong> record(s) found in file.</div>
  <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light sticky-top"><tr>
        <th>NO</th><th>Reg Number</th><th>Full Name</th><th>Email</th><th>Dept Code</th><th>Session</th><th>Intake</th><th>Year</th>
      </tr></thead>
      <tbody>
      <?php foreach ($importRows as $i => $row): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><code><?= e($row['reg_number'] ?? $row['RegNumber'] ?? $row['Reg Number'] ?? $row['REG_NUMBER'] ?? '') ?></code></td>
          <td><?= e($row['full_name']  ?? $row['FullName']  ?? $row['Full Name']  ?? $row['NAME'] ?? '') ?></td>
          <td><?= e($row['email']      ?? $row['Email']     ?? $row['EMAIL'] ?? '') ?></td>
          <td><?= e($row['department_code'] ?? $row['DeptCode'] ?? $row['dept_code'] ?? '') ?></td>
          <td><?= e($row['session_type'] ?? $row['Session'] ?? '') ?></td>
          <td><?= e($row['intake'] ?? $row['Intake'] ?? '') ?></td>
          <td><?= e((string) ($row['year_of_study'] ?? $row['Year'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <form method="POST" class="mt-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import_confirm">
    <button type="submit" class="btn btn-semas-gold" onclick="this.disabled=true;this.innerText='Saving…';this.form.submit();">
      <i class="bi bi-cloud-upload me-1"></i> Save <?= count($importRows) ?> Record(s)
    </button>
  </form>
</div>

<?php else: ?>
<!-- ============================================================
     MAIN STUDENT LIST
     ============================================================ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="display-font mb-0">Manage Students</h4>
  <div class="d-flex gap-2">
    <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addStudentModal">
      <i class="bi bi-person-plus-fill me-1"></i> Add Student
    </button>
    <button class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
      <i class="bi bi-file-earmark-arrow-up me-1"></i> Import CSV / Excel
    </button>
  </div>
</div>

<!-- Filters -->
<div class="semas-card p-3 mb-3">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-md-4">
      <input type="search" name="q" class="form-control form-control-sm" placeholder="Search name, email, reg no." value="<?= e($search) ?>">
    </div>
    <div class="col-md-3">
      <select name="dept" class="form-select form-select-sm">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= $d['department_id'] ?>" <?= $filterDept == $d['department_id'] ? 'selected' : '' ?>>
            <?= e($d['department_name']) ?> (<?= e($d['department_code']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="status" class="form-select form-select-sm">
        <option value="">All Status</option>
        <option value="Active" <?= $filterStatus === 'Active' ? 'selected' : '' ?>>Active</option>
        <option value="Deactivated" <?= $filterStatus === 'Deactivated' ? 'selected' : '' ?>>Deactivated</option>
        <option value="Pending" <?= $filterStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
      </select>
    </div>
    <div class="col-md-2">
      <select name="intake" class="form-select form-select-sm">
        <option value="">All Intakes</option>
        <optgroup label="By group">
          <option value="JAN"  <?= $filterIntake === 'JAN'  ? 'selected' : '' ?>>All JAN</option>
          <option value="MAY"  <?= $filterIntake === 'MAY'  ? 'selected' : '' ?>>All MAY</option>
          <option value="SEPT" <?= $filterIntake === 'SEPT' ? 'selected' : '' ?>>All SEPT</option>
        </optgroup>
        <optgroup label="By cohort">
          <?php foreach (availableIntakes() as $ic): ?>
            <option value="<?= $ic ?>" <?= $filterIntake === $ic ? 'selected' : '' ?>><?= $ic ?></option>
          <?php endforeach; ?>
        </optgroup>
      </select>
    </div>
    <div class="col-md-1">
      <button type="submit" class="btn btn-semas btn-sm w-100">Filter</button>
    </div>
  </form>
</div>


<!-- Student table -->
<div class="semas-card p-0">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Reg No.</th><th>Name</th><th>Email</th><th>Department</th>
          <th>Session</th><th>Intake</th><th>Year</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$students): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No students found<?= ($search || $filterDept || $filterStatus) ? ' matching your filters.' : '. Add your first student above.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($students as $s): ?>
        <tr>
          <td><code><?= e($s['reg_number'] ?? '—') ?></code></td>
          <td><?= e($s['full_name']) ?></td>
          <td class="small text-muted"><?= e($s['email']) ?></td>
          <td class="small"><?= e($s['department_name'] ?? '—') ?></td>
          <td><?= e($s['session_type'] ?? '—') ?></td>
          <td><?= e($s['intake'] ?? '—') ?></td>
          <td><?= e(isset($s['year_of_study']) ? (string) $s['year_of_study'] : '—') ?></td>
          <td><span class="badge <?= $s['status'] === 'Active' ? 'badge-completed' : ($s['status'] === 'Pending' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= e($s['status']) ?></span></td>
          <td>
            <button class="btn btn-sm btn-outline-dark py-0 px-1 edit-student-btn"
              data-uid="<?= $s['user_id'] ?>"
              data-rn="<?= e($s['reg_number'] ?? '') ?>"
              data-name="<?= e($s['full_name']) ?>"
              data-email="<?= e($s['email']) ?>"
              data-phone="<?= e($s['phone_number'] ?? '') ?>"
              data-dept="<?= (int) ($s['department_id'] ?? 0) ?>"
              data-session="<?= e($s['session_type'] ?? '') ?>"
              data-intake="<?= e($s['intake'] ?? '') ?>"
              data-year="<?= e((string) ($s['year_of_study'] ?? '')) ?>"
              title="Edit"><i class="bi bi-pencil-fill"></i></button>
            <form method="POST" class="d-inline" onsubmit="return confirm('Toggle account status?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_status">
              <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
              <button type="submit" class="btn btn-sm <?= $s['status'] === 'Active' ? 'btn-outline-danger' : 'btn-outline-success' ?> py-0 px-1" title="<?= $s['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>">
                <i class="bi bi-<?= $s['status'] === 'Active' ? 'person-dash' : 'person-check' ?>-fill"></i>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="p-2 text-muted small border-top"><?= count($students) ?> student(s) found.</div>
</div>

<!-- ============================================================
     ADD / EDIT STUDENT MODAL
     ============================================================ -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="studentForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_student">
        <input type="hidden" name="edit_user_id" id="editUserId" value="">
        <div class="modal-header">
          <h5 class="modal-title display-font" id="studentModalTitle">Add New Student</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Registration Number <span class="text-danger">*</span></label>
              <input type="text" name="reg_number" id="fieldRegNumber" class="form-control" required placeholder="e.g. 2401001192">
              <div class="form-text">Used as default password.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="full_name" id="fieldFullName" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" id="fieldEmail" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Phone Number</label>
              <input type="tel" name="phone_number" id="fieldPhone" class="form-control" placeholder="+250700000000">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Department</label>
              <select name="department_id" id="fieldDept" class="form-select">
                <option value="">— Select Department —</option>
                <?php
                $lastFac = null;
                foreach ($departments as $d):
                    if ($d['faculty_name'] !== $lastFac):
                        if ($lastFac !== null) echo '</optgroup>';
                        echo '<optgroup label="' . e($d['faculty_name']) . '">';
                        $lastFac = $d['faculty_name'];
                    endif;
                ?>
                  <option value="<?= $d['department_id'] ?>"><?= e($d['department_name']) ?> (<?= e($d['department_code']) ?>)</option>
                <?php endforeach; if ($lastFac) echo '</optgroup>'; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Session Type</label>
              <select name="session_type" id="fieldSession" class="form-select">
                <option value="">— None —</option>
                <option value="Day">Day</option>
                <option value="Evening">Evening</option>
                <option value="Weekend">Weekend</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Year of Study</label>
              <select name="year_of_study" id="fieldYear" class="form-select">
                <option value="">— None —</option>
                <option value="1">Year 1</option>
                <option value="2">Year 2</option>
                <option value="3">Year 3</option>
                <option value="4">Year 4</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-semas-gold" id="studentSubmitBtn"
            onclick="this.disabled=true;this.innerText='Saving…';this.form.submit();">
            <i class="bi bi-floppy-fill me-1"></i> Save Student
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================
     IMPORT MODAL
     ============================================================ -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_preview">
        <div class="modal-header">
          <h5 class="modal-title display-font">Bulk Import Students</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info small">
            <strong>Required columns:</strong> <code>reg_number, full_name, email</code><br>
            <strong>Optional:</strong> <code>phone, department_code, session_type, intake, year_of_study</code><br>
            Existing students matched by <code>reg_number</code> will be <strong>updated</strong>, not duplicated.
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Upload File (CSV, XLS, XLSX)</label>
            <input type="file" name="import_file" class="form-control" accept=".csv,.xls,.xlsx" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-semas-gold"
            onclick="this.disabled=true;this.innerText='Reading file…';this.form.submit();">
            <i class="bi bi-eye me-1"></i> Preview Import
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
document.querySelectorAll('.edit-student-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('studentModalTitle').textContent = 'Edit Student';
        document.getElementById('fieldRegNumber').value  = this.dataset.rn;
        document.getElementById('fieldRegNumber').readOnly = true;
        document.getElementById('fieldFullName').value   = this.dataset.name;
        document.getElementById('fieldEmail').value      = this.dataset.email;
        document.getElementById('fieldPhone').value      = this.dataset.phone;
        document.getElementById('fieldDept').value       = this.dataset.dept;
        document.getElementById('fieldSession').value    = this.dataset.session;
        document.getElementById('fieldYear').value       = this.dataset.year;
        var modal = new bootstrap.Modal(document.getElementById('addStudentModal'));
        modal.show();
    });
});
document.getElementById('addStudentModal').addEventListener('hide.bs.modal', function() {
    document.getElementById('studentModalTitle').textContent = 'Add New Student';
    document.getElementById('studentForm').reset();
    document.getElementById('fieldRegNumber').readOnly = false;
    document.getElementById('studentSubmitBtn').disabled = false;
    document.getElementById('studentSubmitBtn').innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Save Student';
});
</script>
<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
