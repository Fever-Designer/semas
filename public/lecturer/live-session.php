<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();

$pageTitle = 'Manage Attendance / Live Session';
$activeNav = 'class-attendance';
$db        = Database::connection();
$me        = Auth::user();

// Fetch this lecturer's ongoing modules
$modStmt = $db->prepare(
    "SELECT m.module_id, m.module_title, m.session_type, m.weekend_slot,
            m.start_date, m.end_date, m.module_qr_secret, r.room_name
     FROM modules m
     LEFT JOIN rooms r ON r.room_id = m.room_id
     LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
     WHERE m.status = 'Ongoing'
       AND (lt.user_id = :uid OR :role IN ('HOD','Coordinator'))
     ORDER BY m.module_title"
);
$modStmt->execute(['uid' => $me['user_id'], 'role' => Auth::role()]);
$myModules = $modStmt->fetchAll();

$moduleId = (int) ($_GET['module_id'] ?? 0);
$module   = null;
foreach ($myModules as $m) {
    if ((int) $m['module_id'] === $moduleId) { $module = $m; break; }
}

$manualRoster = [];
$overallAttendance = [];
if ($module) {
    $rosterStmt = $db->prepare(
        "SELECT u.user_id, u.full_name, u.reg_number, u.photo_path,
                COALESCE(d.department_name, '') AS department_name
         FROM module_enrollments e
         JOIN users u ON u.user_id = e.user_id
         LEFT JOIN departments d ON d.department_id = u.department_id
         WHERE e.module_id = :mid AND u.status = 'Active'
         ORDER BY u.full_name"
    );
    $rosterStmt->execute(['mid' => $moduleId]);
    foreach ($rosterStmt->fetchAll() as $student) {
        $manualRoster[] = [
            'user_id' => (int) $student['user_id'],
            'full_name' => $student['full_name'],
            'reg_number' => $student['reg_number'],
            'department' => $student['department_name'],
            'photo_url' => !empty($student['photo_path'])
                ? APP_URL . '/' . $student['photo_path']
                : 'https://ui-avatars.com/api/?name=' . urlencode((string) $student['full_name']) . '&background=1E2A52&color=fff',
        ];
    }

    $overallStmt = $db->prepare(
        "SELECT u.full_name, u.reg_number,
                COUNT(DISTINCT cs.session_id) AS sessions_held,
                SUM(CASE WHEN cal.status = 'Present' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN cal.status = 'Late' THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN cal.status = 'Absent' OR cal.attendance_id IS NULL THEN 1 ELSE 0 END) AS absent_count
         FROM module_enrollments e
         JOIN users u ON u.user_id = e.user_id
         LEFT JOIN class_sessions cs
                ON cs.module_id = e.module_id
               AND cs.session_date <= CURDATE()
               AND cs.status = 'Closed'
         LEFT JOIN class_attendance_logs cal
                ON cal.session_id = cs.session_id
               AND cal.user_id = e.user_id
               AND cal.attendance_type = 'Sign In'
         WHERE e.module_id = :mid AND u.status = 'Active'
         GROUP BY u.user_id, u.full_name, u.reg_number
         ORDER BY u.full_name"
    );
    $overallStmt->execute(['mid' => $moduleId]);
    $overallAttendance = $overallStmt->fetchAll();
}

$moduleQrImage = null;
if ($module && !empty($module['module_qr_secret'])) {
    $secret = (string) $module['module_qr_secret'];
    $shortToken = $secret;
    if (ctype_xdigit($secret)) {
        $binarySecret = hex2bin($secret);
        if ($binarySecret !== false) {
            $shortToken = rtrim(strtr(base64_encode($binarySecret), '+/', '-_'), '=');
        }
    }
    $scanPayload = 'SM:' . $moduleId . ':' . $shortToken;
    $scanUrl = public_url('/student/attendance.php?module_id=' . $moduleId . '&t=' . rawurlencode($scanPayload));
    $moduleQrImage = SimpleQr::pngDataUri($scanUrl, 5, 3);
}

require __DIR__ . '/../partials/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="display-font mb-0">Manage Attendance / Live Session</h4>
    <div class="text-muted small">Start and control the Sign In and Sign Out session.</div>
  </div>
</div>

<?php if (!$myModules): ?>
  <div class="semas-card p-4 text-center text-muted small">No ongoing modules assigned to you.</div>
<?php else: ?>

<!-- Module selector -->
<div class="semas-card p-3 mb-3">
  <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
    <label class="form-label small fw-semibold mb-0 text-nowrap">Select Module:</label>
    <select name="module_id" class="form-select form-select-sm flex-grow-1" style="max-width:400px;"
            onchange="this.form.submit()">
      <option value="">/ Choose a module /</option>
      <?php foreach ($myModules as $m): ?>
        <option value="<?= (int) $m['module_id'] ?>"
                <?= (int) $m['module_id'] === $moduleId ? 'selected' : '' ?>>
          <?= e($m['module_title']) ?> (<?= e($m['session_type']) ?>)
          <?php if ($m['room_name']): ?> / <?= e($m['room_name']) ?><?php endif; ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$module): ?>
  <div class="semas-card p-5 text-center text-muted">
    <i class="bi bi-qr-code" style="font-size:3rem;opacity:.3;"></i>
    <p class="mt-3 mb-0">Select a module above to start a live attendance session.</p>
  </div>
<?php else: ?>

<div class="row g-3">
  <!-- QR Code panel -->
  <div class="col-lg-5">
    <div class="semas-card p-4 text-center">
      <h6 class="display-font mb-1"><?= e($module['module_title']) ?></h6>
      <p class="text-muted small mb-3">
        <?= e($module['session_type']) ?>
        <?php if ($module['room_name']): ?> &middot; <?= e($module['room_name']) ?><?php endif; ?>
      </p>

      <div class="d-flex flex-wrap justify-content-center gap-2 mb-3" id="phaseControls">
        <?php if (Auth::role() === 'Lecturer'): ?>
          <button type="button" class="btn btn-semas-gold btn-sm d-none" id="profileManualAttendanceBtn"
                  data-bs-toggle="modal" data-bs-target="#profileManualAttendanceModal">
            <i class="bi bi-person-check-fill me-1"></i>Manual Attendance
          </button>
        <?php endif; ?>
        <button type="button" class="btn btn-success btn-sm" id="startSignInBtn" onclick="startPhase('SignIn')">
          <i class="bi bi-box-arrow-in-right me-1"></i>Start Sign In
        </button>
        <button type="button" class="btn btn-outline-danger btn-sm d-none" id="closeSignInBtn" onclick="closePhase('SignIn')">
          <i class="bi bi-stop-circle me-1"></i>Close Sign In
        </button>
        <button type="button" class="btn btn-primary btn-sm d-none" id="startSignOutBtn" onclick="startPhase('SignOut')">
          <i class="bi bi-box-arrow-right me-1"></i>Start Sign Out
        </button>
        <button type="button" class="btn btn-outline-danger btn-sm d-none" id="closeSignOutBtn" onclick="closePhase('SignOut')">
          <i class="bi bi-stop-circle me-1"></i>Close Sign Out
        </button>
      </div>

      <?php if ($moduleQrImage): ?>
        <div style="display:inline-block;border:4px solid #1E2A52;border-radius:8px;padding:12px;background:#fff;">
          <img src="<?= e($moduleQrImage) ?>" alt="Permanent module attendance QR" width="220" height="220"
               style="display:block;max-width:100%;height:auto;">
        </div>
        <div class="small text-muted mt-2">Permanent module QR created by the Head Of Department.</div>
      <?php else: ?>
        <div class="alert alert-warning small">
          This module has no classroom QR. Ask the Head Of Department to generate it from Manage Modules.
        </div>
      <?php endif; ?>

      <!-- Lecturer-controlled attendance status -->
      <div class="mt-3">
        <div id="qrStatus" class="badge bg-secondary" style="font-size:.85rem;">Not Started</div>
        <div class="mt-1 text-muted small" id="qrExpiry">Waiting for lecturer.</div>
      </div>

    </div>
  </div>

  <!-- Live roster panel -->
  <div class="col-lg-7">
    <div class="semas-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="display-font mb-0">Live Roster</h6>
        <div class="d-flex gap-2" style="font-size:.78rem;">
          <span class="badge bg-success" id="cntPresent">P: 0</span>
          <span class="badge bg-warning text-dark" id="cntLate">L: 0</span>
          <span class="badge bg-danger" id="cntAbsent">A: 0</span>
          <span class="badge bg-secondary" id="cntTotal">/ 0</span>
        </div>
      </div>
      <div id="manualAttendanceMsg" class="small mb-2"></div>
      <div id="rosterTable" style="max-height:420px;overflow-y:auto;">
        <div class="text-muted small text-center py-4">Starting session…</div>
      </div>
    </div>
  </div>
</div>

<div class="semas-card p-3 mt-3">
  <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
    <h6 class="display-font mb-0">Overall Attendance</h6>
    <a href="<?= APP_URL ?>/lecturer/class-attendance.php?module_id=<?= $moduleId ?>"
       class="btn btn-sm btn-outline-dark">
      <i class="bi bi-list-ul me-1"></i>See List
    </a>
  </div>
  <div class="table-responsive" style="max-height:360px;overflow-y:auto;">
    <table class="table table-sm table-bordered align-middle mb-0">
      <thead class="table-light">
        <tr><th>NO</th><th>Reg No</th><th>Student</th><th>Sessions</th><th>Present</th><th>Late</th><th>Absent</th><th>Attendance</th></tr>
      </thead>
      <tbody>
        <?php foreach ($overallAttendance as $index => $attendance):
          $sessionsHeld = (int) $attendance['sessions_held'];
          $attended = (int) $attendance['present_count'] + (int) $attendance['late_count'];
          $attendanceRate = $sessionsHeld > 0 ? (int) round($attended * 100 / $sessionsHeld) : 0;
        ?>
          <tr>
            <td class="text-center"><?= $index + 1 ?></td>
            <td><?= e($attendance['reg_number'] ?? '/') ?></td>
            <td class="fw-semibold"><?= e($attendance['full_name']) ?></td>
            <td><?= $sessionsHeld ?></td>
            <td><?= (int) $attendance['present_count'] ?></td>
            <td><?= (int) $attendance['late_count'] ?></td>
            <td><?= (int) $attendance['absent_count'] ?></td>
            <td class="fw-semibold"><?= $attendanceRate ?>%</td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$overallAttendance): ?>
          <tr><td colspan="8" class="text-center text-muted py-3">No enrolled students found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Profile-verified manual attendance, kept inside the main live feature. -->
<div class="modal fade" id="profileManualAttendanceModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title display-font">Manual Attendance</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label small fw-semibold">Student Registration Number</label>
        <div class="input-group">
          <input id="profileManualReg" class="form-control" inputmode="numeric" maxlength="10" autocomplete="off">
          <button type="button" class="btn btn-outline-dark" id="profileManualSearchBtn">
            <i class="bi bi-search me-1"></i>Retrieve Profile
          </button>
        </div>
        <div id="profileManualFeedback" class="alert alert-danger small py-2 px-3 mt-2 mb-0" style="display:none;"></div>
        <div id="profileManualPreview" class="border rounded p-3 mt-3" style="display:none;">
          <div class="d-flex gap-3 align-items-center">
            <img id="profileManualPhoto" src="" alt="Student profile"
                 style="width:88px;height:88px;border-radius:50%;object-fit:cover;border:3px solid var(--semas-gold);">
            <div>
              <div class="fw-semibold fs-6" id="profileManualName"></div>
              <div class="text-muted small" id="profileManualRegDisplay"></div>
              <div class="text-muted small" id="profileManualDepartment"></div>
              <div class="mt-2"><span class="badge badge-completed">Profile verified</span></div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-semas-gold btn-sm" id="profileManualConfirmBtn" style="display:none;">
          <i class="bi bi-person-check me-1"></i>Confirm Manual Attendance
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF     = '<?= csrf_token() ?>';
const BASE     = window.SEMAS_BASE_URL;
const MOD_ID   = <?= $moduleId ?>;
const PROFILE_MANUAL_ROSTER = <?= json_encode($manualRoster, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
let sessionId  = null;
let profileManualStudentId = null;

function phaseRequest(action, phase) {
    const params = new URLSearchParams({action: action, module_id: MOD_ID, csrf_token: CSRF});
    if (phase) params.set('phase', phase);
    fetch(BASE + '/api/lecturer-session-qr.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString(),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            document.getElementById('qrStatus').textContent = data.message;
            document.getElementById('qrStatus').className = 'badge bg-danger';
            return;
        }
        renderPhaseState(data);
    })
    .catch(() => {
        document.getElementById('qrStatus').textContent = 'Error / check connection';
        document.getElementById('qrStatus').className = 'badge bg-danger';
    });
}

function setButtonVisible(id, visible) {
    const button = document.getElementById(id);
    if (button) button.classList.toggle('d-none', !visible);
}

function renderPhaseState(data) {
    sessionId = data.session_id || null;
    const phase = data.phase || 'Inactive';
    const completed = data.status === 'Closed';
    const active = phase === 'SignIn' || phase === 'SignOut';

    setButtonVisible('startSignInBtn', !sessionId);
    setButtonVisible('closeSignInBtn', phase === 'SignIn');
    setButtonVisible('startSignOutBtn', !!sessionId && phase === 'Inactive' && !completed);
    setButtonVisible('closeSignOutBtn', phase === 'SignOut');
    setButtonVisible('profileManualAttendanceBtn', active);

    if (active) {
        document.getElementById('qrStatus').textContent = phase === 'SignIn' ? 'Sign In Active' : 'Sign Out Active';
        document.getElementById('qrStatus').className = 'badge ' + (phase === 'SignIn' ? 'bg-success' : 'bg-primary');
        document.getElementById('qrExpiry').textContent = phase === 'SignIn'
            ? 'Students may now scan the mounted module QR to sign in.'
            : 'Students who signed in may now scan the same QR to sign out.';
    } else if (completed) {
        document.getElementById('qrStatus').textContent = 'Attendance Closed';
        document.getElementById('qrStatus').className = 'badge bg-secondary';
        document.getElementById('qrExpiry').textContent = 'Sign In and Sign Out are complete.';
    } else if (sessionId) {
        document.getElementById('qrStatus').textContent = 'Sign In Closed';
        document.getElementById('qrStatus').className = 'badge bg-warning text-dark';
        document.getElementById('qrExpiry').textContent = 'Ready to start Sign Out.';
    } else {
        document.getElementById('qrStatus').textContent = 'Not Started';
        document.getElementById('qrStatus').className = 'badge bg-secondary';
        document.getElementById('qrExpiry').textContent = 'Waiting for lecturer.';
    }
    if (sessionId) loadRoster();
}

function loadPhaseState() { phaseRequest('get_state', null); }
function startPhase(phase) {
    document.getElementById('qrStatus').textContent = 'Starting ' + (phase === 'SignIn' ? 'Sign In' : 'Sign Out') + '…';
    phaseRequest('start_phase', phase);
}
function closePhase(phase) {
    if (!confirm('Close the active ' + (phase === 'SignIn' ? 'Sign In' : 'Sign Out') + ' phase? Students will no longer be able to scan this QR.')) return;
    phaseRequest('close_phase', phase);
}

function manualMark(userId, fromProfileModal = false) {
    const msg = fromProfileModal
        ? document.getElementById('profileManualFeedback')
        : document.getElementById('manualAttendanceMsg');
    if (!sessionId) {
        msg.style.display = '';
        msg.className = 'alert alert-danger small py-2 px-3 mt-2 mb-0';
        msg.textContent = 'Start the Sign In or Sign Out phase before recording manual attendance.';
        return;
    }
    msg.style.display = '';
    msg.className = 'small mb-2 text-muted';
    msg.textContent = 'Recording manual attendance...';
    fetch(BASE + '/api/lecturer-session-qr.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'manual_mark',
            session_id: sessionId,
            user_id: userId,
            csrf_token: CSRF,
        }).toString(),
    })
    .then(r => r.json())
    .then(data => {
        msg.className = 'small mb-2 ' + (data.ok ? 'text-success' : 'text-danger');
        msg.textContent = data.message;
        if (data.ok) {
            loadRoster();
            if (fromProfileModal) {
                setTimeout(function () {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('profileManualAttendanceModal')).hide();
                    resetProfileManualLookup();
                }, 650);
            }
        }
    })
    .catch(() => {
        msg.className = 'small mb-2 text-danger';
        msg.textContent = 'Could not record manual attendance.';
    });
}

function resetProfileManualLookup() {
    profileManualStudentId = null;
    document.getElementById('profileManualPreview').style.display = 'none';
    document.getElementById('profileManualConfirmBtn').style.display = 'none';
    const feedback = document.getElementById('profileManualFeedback');
    feedback.style.display = 'none';
    feedback.textContent = '';
}

function retrieveProfileForManualAttendance() {
    resetProfileManualLookup();
    const input = document.getElementById('profileManualReg');
    const reg = input.value.trim().toLowerCase();
    const feedback = document.getElementById('profileManualFeedback');
    if (!reg) {
        feedback.style.display = '';
        feedback.textContent = 'Enter the student registration number.';
        return;
    }
    const student = PROFILE_MANUAL_ROSTER.find(function (row) {
        return String(row.reg_number || '').toLowerCase() === reg;
    });
    if (!student) {
        feedback.style.display = '';
        feedback.textContent = 'No active student with that registration number is enrolled in this module.';
        return;
    }

    profileManualStudentId = student.user_id;
    document.getElementById('profileManualPhoto').src = student.photo_url || '';
    document.getElementById('profileManualName').textContent = student.full_name || '-';
    document.getElementById('profileManualRegDisplay').textContent = 'Reg. No: ' + (student.reg_number || '-');
    document.getElementById('profileManualDepartment').textContent = student.department || 'Department not assigned';
    document.getElementById('profileManualPreview').style.display = '';
    document.getElementById('profileManualConfirmBtn').style.display = '';
}

function openManualProfile(userId) {
    const student = PROFILE_MANUAL_ROSTER.find(function (row) {
        return Number(row.user_id) === Number(userId);
    });
    if (!student) return;
    document.getElementById('profileManualReg').value = student.reg_number || '';
    retrieveProfileForManualAttendance();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('profileManualAttendanceModal')).show();
}

document.getElementById('profileManualSearchBtn').addEventListener('click', retrieveProfileForManualAttendance);
document.getElementById('profileManualReg').addEventListener('input', function () {
    this.value = this.value.replace(/\D+/g, '').slice(0, 10);
    resetProfileManualLookup();
});
document.getElementById('profileManualReg').addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        retrieveProfileForManualAttendance();
    }
});
document.getElementById('profileManualConfirmBtn').addEventListener('click', function () {
    if (profileManualStudentId) manualMark(profileManualStudentId, true);
});

function loadRoster() {
    if (!sessionId) return;
    fetch(BASE + '/api/lecturer-session-qr.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=status&session_id=' + sessionId + '&csrf_token=' + encodeURIComponent(CSRF),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) return;
        document.getElementById('cntPresent').textContent = 'P: ' + data.present;
        document.getElementById('cntLate').textContent    = 'L: ' + data.late;
        document.getElementById('cntAbsent').textContent  = 'A: ' + data.absent;
        document.getElementById('cntTotal').textContent   = '/ ' + data.total;

        const colors = {P:'#d4edda',L:'#fff3cd',A:'#f8d7da'};
        const fcolors = {P:'#155724',L:'#856404',A:'#721c24'};
        let html = '<table class="table table-sm table-bordered mb-0" style="font-size:.78rem;">';
        html += '<thead class="table-light"><tr><th>NO</th><th>Reg No</th><th>Name</th><th>In</th><th>Out</th><th>Status</th><th>Manual</th></tr></thead><tbody>';
        data.roster.forEach(function(s, i) {
            const bg = colors[s.status] || '#fff';
            const fc = fcolors[s.status] || '#000';
            html += '<tr>';
            html += '<td class="text-muted text-center">' + (i+1) + '</td>';
            html += '<td>' + (s.reg || '/') + '</td>';
            html += '<td class="fw-semibold">' + s.name + '</td>';
            html += '<td>' + (s.in_time || '/') + '</td>';
            html += '<td>' + (s.out_time || '/') + '</td>';
            html += '<td class="text-center fw-bold" style="background:' + bg + ';color:' + fc + ';">' + s.status + '</td>';
            let manualAction = '<span class="text-muted">/</span>';
            if (data.phase === 'SignIn') {
                manualAction = s.in_time
                    ? '<span class="text-success small">Recorded</span>'
                    : '<button type="button" class="btn btn-success btn-sm py-0" onclick="openManualProfile(' + s.user_id + ')">Mark In</button>';
            } else if (data.phase === 'SignOut') {
                if (s.out_time) {
                    manualAction = '<span class="text-success small">Recorded</span>';
                } else {
                    manualAction = '<button type="button" class="btn btn-primary btn-sm py-0" onclick="openManualProfile(' + s.user_id + ')">' + (s.in_time ? 'Mark Out' : 'Mark Out (Late)') + '</button>';
                }
            }
            html += '<td class="text-center">' + manualAction + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('rosterTable').innerHTML = html;
    });
}

// Load existing state without starting attendance automatically.
loadPhaseState();
// Refresh roster every 10 seconds
setInterval(loadRoster, 10000);
</script>

<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
