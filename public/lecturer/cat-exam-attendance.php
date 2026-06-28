<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Lecturer']);

$pageTitle = 'CAT / Exam Attendance';
$activeNav = 'cat-exam';
$db = Database::connection();
$me = Auth::user();

$lecStmt = $db->prepare('SELECT * FROM lecturers WHERE user_id = :uid');
$lecStmt->execute(['uid' => $me['user_id']]);
$lecturer = $lecStmt->fetch();
if (!$lecturer) {
    require __DIR__ . '/../partials/layout_top.php';
    echo '<div class="alert alert-warning small">Lecturer profile not found. Contact the Principal.</div>';
    require __DIR__ . '/../partials/layout_bottom.php';
    exit;
}

$cols = "cs.*, m.module_title, m.module_id, d.department_name,
         (SELECT COUNT(*) FROM module_enrollments e WHERE e.module_id = m.module_id) AS enrolled_count,
         (SELECT COUNT(*) FROM cat_exam_attendance_logs cal WHERE cal.schedule_id = cs.schedule_id AND cal.attendance_type = 'Sign In')  AS signed_in_count,
         (SELECT COUNT(*) FROM cat_exam_attendance_logs cal WHERE cal.schedule_id = cs.schedule_id AND cal.attendance_type = 'Sign Out') AS signed_out_count";

// Active: not yet submitted
$activeStmt = $db->prepare(
    "SELECT $cols
     FROM cat_exam_schedules cs
     JOIN modules m ON m.module_id = cs.module_id
     LEFT JOIN departments d ON d.department_id = m.department_id
     WHERE cs.invigilator_id = :lid
       AND NOT EXISTS (SELECT 1 FROM cat_exam_submissions s2 WHERE s2.schedule_id = cs.schedule_id)
     ORDER BY cs.scheduled_date ASC, cs.start_time ASC"
);
$activeStmt->execute(['lid' => $lecturer['lecturer_id']]);
$activeSchedules = $activeStmt->fetchAll();

// History: submitted
$histStmt = $db->prepare(
    "SELECT $cols,
            sub.submitted_at, sub.notes AS submission_notes,
            su.full_name AS submitted_by_name
     FROM cat_exam_schedules cs
     JOIN modules m ON m.module_id = cs.module_id
     LEFT JOIN departments d ON d.department_id = m.department_id
     JOIN cat_exam_submissions sub ON sub.schedule_id = cs.schedule_id
     LEFT JOIN users su ON su.user_id = sub.submitted_by
     WHERE cs.invigilator_id = :lid
     ORDER BY cs.scheduled_date DESC, cs.start_time DESC"
);
$histStmt->execute(['lid' => $lecturer['lecturer_id']]);
$histSchedules = $histStmt->fetchAll();

// Build time labels for all schedules
$schedMeta = [];
foreach (array_merge($activeSchedules, $histSchedules) as $s) {
    $sid = (int) $s['schedule_id'];
    $schedMeta[$sid] = [
        'time_label' => $s['start_time']
            ? date('h:i A', strtotime($s['start_time'])) . '–' . date('h:i A', strtotime($s['end_time']))
            : '—',
    ];
}

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">CAT / Exam Attendance</h4>
<p class="text-muted small mb-4">
  Sign students in and out during assessments you are invigilating. Students cannot self-scan during CAT or Exam.
  Submit the full list to HOD when done.
</p>

<?php if (!$activeSchedules && !$histSchedules): ?>
  <div class="semas-card p-4 text-center">
    <i class="bi bi-calendar-x" style="font-size:2rem;color:var(--semas-text-muted);"></i>
    <h6 class="display-font mt-2 mb-1">No Assigned Assessments</h6>
    <p class="text-muted small mb-0">You have no CAT / Exam sessions assigned to you.</p>
  </div>
<?php endif; ?>

<!-- ═══════════════════════════ ACTIVE ═══════════════════════════ -->
<?php if ($activeSchedules): ?>
<h6 class="display-font text-uppercase text-muted small mb-3" style="letter-spacing:.07em;">
  <i class="bi bi-activity me-1"></i>Active Sessions
</h6>

<?php foreach ($activeSchedules as $s):
    $sid  = (int) $s['schedule_id'];
    $meta = $schedMeta[$sid];
?>
<div class="semas-card mb-4" id="card-<?= $sid ?>">

  <!-- Card header -->
  <div class="p-3 border-bottom">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div>
        <h5 class="display-font mb-0">
          <?= e($s['module_title']) ?>
          <span class="badge bg-secondary ms-1"><?= e($s['exam_type']) ?></span>
        </h5>
        <p class="text-muted small mb-0">
          <?= e($s['department_name'] ?? '') ?>
          &middot; <strong><?= e(date('D, d M Y', strtotime($s['scheduled_date']))) ?></strong>
          &middot; Room: <strong><?= e($s['room']) ?></strong>
          &middot; <strong><?= e($meta['time_label']) ?></strong>
        </p>
      </div>
      <div class="text-end small text-muted">
        Enrolled: <strong><?= (int) $s['enrolled_count'] ?></strong>
        &nbsp;&middot;&nbsp;
        In Room: <strong id="sinCount-<?= $sid ?>"><?= (int) $s['signed_in_count'] ?></strong>
        &nbsp;&middot;&nbsp;
        Signed Out: <strong id="soutCount-<?= $sid ?>"><?= (int) $s['signed_out_count'] ?></strong>
      </div>
    </div>
  </div>

  <!-- Manual attendance input -->
  <div class="p-3 border-bottom" style="background:#fafafa;">
    <p class="small fw-semibold mb-2 text-uppercase text-muted" style="letter-spacing:.04em;">Manual Attendance</p>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <input id="regInput-<?= $sid ?>"
             class="form-control form-control-sm"
             placeholder="Enter Registration Number…"
             style="max-width:250px;"
             onkeydown="if(event.key==='Enter') triggerLookup(<?= $sid ?>, 'sign_in')">
      <button class="btn btn-sm btn-semas-gold"
              onclick="triggerLookup(<?= $sid ?>, 'sign_in')">
        <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
      </button>
      <button class="btn btn-sm btn-semas"
              onclick="triggerLookup(<?= $sid ?>, 'sign_out')">
        <i class="bi bi-box-arrow-right me-1"></i>Sign Out
      </button>
    </div>
    <div id="inputFeedback-<?= $sid ?>" class="small mt-1"></div>
  </div>

  <!-- Live roster tabs -->
  <div class="p-3">
    <ul class="nav nav-tabs mb-2 small" id="rosterTabs-<?= $sid ?>">
      <li class="nav-item">
        <button class="nav-link active py-1"
                onclick="showRosterTab(<?= $sid ?>, 'in_room', this)">
          <i class="bi bi-person-check me-1"></i>In Room
          <span class="badge bg-secondary ms-1" id="inRoomCount-<?= $sid ?>">—</span>
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link py-1"
                onclick="showRosterTab(<?= $sid ?>, 'missed', this)">
          <i class="bi bi-person-x me-1"></i>Not Signed In
          <span class="badge badge-urgent ms-1" id="missedCount-<?= $sid ?>">—</span>
        </button>
      </li>
    </ul>
    <div id="rosterContainer-<?= $sid ?>">
      <p class="text-muted small mb-0">Loading…</p>
    </div>
  </div>

  <!-- Submit -->
  <div class="p-3 border-top d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <p class="text-muted small mb-0">
      After submitting, no further sign-in or sign-out changes can be made. The HOD will review this list.
    </p>
    <button class="btn btn-semas-gold btn-sm text-nowrap"
            onclick="openSubmitModal(<?= $sid ?>, '<?= e($s['module_title']) ?>')">
      <i class="bi bi-send me-1"></i> Submit Attendance List to HOD
    </button>
  </div>

</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ════════════════════════ HISTORY ════════════════════════════ -->
<?php if ($histSchedules): ?>
<hr class="my-4">
<h6 class="display-font text-uppercase text-muted small mb-3" style="letter-spacing:.07em;">
  <i class="bi bi-clock-history me-1"></i>Completed &amp; Submitted
</h6>

<?php foreach ($histSchedules as $s):
    $sid  = (int) $s['schedule_id'];
    $meta = $schedMeta[$sid];
?>
<div class="semas-card mb-3" id="card-<?= $sid ?>">

  <!-- History card header -->
  <div class="p-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div>
        <h6 class="display-font mb-0">
          <?= e($s['module_title']) ?>
          <span class="badge bg-secondary ms-1"><?= e($s['exam_type']) ?></span>
          <span class="badge badge-completed ms-1"><i class="bi bi-check-circle me-1"></i>Submitted to HOD</span>
        </h6>
        <p class="text-muted small mb-0">
          <?= e($s['department_name'] ?? '') ?>
          &middot; <strong><?= e(date('D, d M Y', strtotime($s['scheduled_date']))) ?></strong>
          &middot; Room: <strong><?= e($s['room']) ?></strong>
          &middot; <strong><?= e($meta['time_label']) ?></strong>
        </p>
        <?php if ($s['submitted_at']): ?>
        <p class="text-muted small mb-0">
          Submitted: <?= e(date('d M Y, h:i A', strtotime($s['submitted_at']))) ?>
          <?php if ($s['submitted_by_name']): ?>
            by <?= e($s['submitted_by_name']) ?>
          <?php endif; ?>
        </p>
        <?php endif; ?>
      </div>
      <div class="text-end small text-muted d-flex align-items-center gap-3">
        <div>
          Enrolled: <strong><?= (int) $s['enrolled_count'] ?></strong>
          &nbsp;&middot;&nbsp;
          Signed In: <strong id="sinCount-<?= $sid ?>"><?= (int) $s['signed_in_count'] ?></strong>
          &nbsp;&middot;&nbsp;
          Signed Out: <strong id="soutCount-<?= $sid ?>"><?= (int) $s['signed_out_count'] ?></strong>
        </div>
        <button class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.78rem;"
                onclick="toggleHistoryRoster(<?= $sid ?>, this)">
          <i class="bi bi-list-ul me-1"></i>View Roster
        </button>
      </div>
    </div>

    <!-- Roster (hidden by default) -->
    <div id="histRoster-<?= $sid ?>" style="display:none;" class="mt-3">
      <ul class="nav nav-tabs mb-2 small" id="rosterTabs-<?= $sid ?>">
        <li class="nav-item">
          <button class="nav-link active py-1"
                  onclick="showRosterTab(<?= $sid ?>, 'in_room', this)">
            <i class="bi bi-person-check me-1"></i>Signed In
            <span class="badge bg-secondary ms-1" id="inRoomCount-<?= $sid ?>">—</span>
          </button>
        </li>
        <li class="nav-item">
          <button class="nav-link py-1"
                  onclick="showRosterTab(<?= $sid ?>, 'missed', this)">
            <i class="bi bi-person-x me-1"></i>Not Signed In
            <span class="badge badge-urgent ms-1" id="missedCount-<?= $sid ?>">—</span>
          </button>
        </li>
      </ul>
      <div id="rosterContainer-<?= $sid ?>">
        <p class="text-muted small mb-0">Loading…</p>
      </div>
    </div>
  </div>

</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ── Preview / Confirm Modal (shared) ──────────────────────────────────── -->
<div class="modal fade" id="previewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title display-font" id="previewModalTitle">Confirm Action</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="previewLoading" class="text-center py-3">
          <div class="spinner-border spinner-border-sm text-secondary"></div>
          <p class="text-muted small mt-2 mb-0">Looking up student…</p>
        </div>
        <div id="previewContent" style="display:none;">
          <div class="d-flex gap-3 align-items-start mb-3">
            <img id="prevPhoto" src="" alt=""
                 style="width:88px;height:88px;border-radius:50%;object-fit:cover;border:3px solid var(--semas-gold);flex-shrink:0;">
            <div>
              <div class="fw-semibold fs-6 mb-0" id="prevName"></div>
              <div class="text-muted small" id="prevReg"></div>
              <div class="text-muted small" id="prevDept"></div>
              <div id="prevEligBadge" class="mt-1"></div>
            </div>
          </div>
          <div id="prevSignStatus" class="small mb-2"></div>
          <div id="prevWarning" class="alert alert-warning small py-2 px-3 mb-2" style="display:none;"></div>
          <div id="prevBlocked" class="alert alert-danger small py-2 px-3 mb-2" style="display:none;"></div>
        </div>
        <div id="previewError" class="alert alert-danger small py-2 px-3" style="display:none;"></div>
        <div id="searchResultsList" style="display:none;">
          <p class="small text-muted mb-1">Multiple students found — select one:</p>
          <div id="searchResultsBody"></div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button id="confirmActionBtn" class="btn btn-semas-gold btn-sm" style="display:none;">
          <i class="bi bi-check2 me-1"></i><span id="confirmBtnText">Confirm</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Submit Modal (shared) ─────────────────────────────────────────────── -->
<div class="modal fade" id="submitModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title display-font" id="submitModalTitle">Submit Attendance</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Students without sign-out require a reason before submitting.
        </p>
        <div id="submitRosterReview"></div>
        <div class="mt-3">
          <label class="form-label small">General Notes (optional)</label>
          <textarea id="submissionNotes" class="form-control form-control-sm" rows="2"
                    placeholder="e.g. Power outage for 10 min at 09:45"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button id="confirmSubmitBtn" class="btn btn-semas-gold btn-sm">
          <i class="bi bi-send me-1"></i>Confirm Submit
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const APP_URL = window.SEMAS_BASE_URL;
const CSRF    = '<?= csrf_token() ?>';

let confirmState    = { scheduleId: 0, studentId: 0, action: '' };
let activeSubmitSid = 0;
const rosterTab     = {};
const rosterCache   = {};

// ── Roster ─────────────────────────────────────────────────────────────────
function refreshRoster(sid) {
    postApi(sid, 'roster', {}, function(data) {
        if (!data.ok) return;
        rosterCache[sid] = data.roster || [];
        renderRoster(sid);
    });
}

function showRosterTab(sid, tab, btn) {
    rosterTab[sid] = tab;
    document.querySelectorAll('#rosterTabs-' + sid + ' .nav-link').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    if (rosterCache[sid]) renderRoster(sid);
}

function renderRoster(sid) {
    const roster    = rosterCache[sid] || [];
    const tab       = rosterTab[sid] || 'in_room';
    const container = document.getElementById('rosterContainer-' + sid);
    const hasInput  = !!document.getElementById('regInput-' + sid); // false for history cards

    const inRoom = roster.filter(r => r.signin_time);
    const missed = roster.filter(r => !r.signin_time);
    const sinOut = inRoom.filter(r => r.signout_time);

    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('sinCount-'    + sid, inRoom.length);
    set('soutCount-'   + sid, sinOut.length);
    set('inRoomCount-' + sid, inRoom.length);
    set('missedCount-' + sid, missed.length);

    if (tab === 'in_room') {
        if (inRoom.length === 0) {
            container.innerHTML = '<p class="text-muted small mb-0">No students have signed in yet.</p>';
            return;
        }
        const rows = inRoom.map(r => {
            const sinTime  = fmtTime(r.signin_time);
            const soutCell = r.signout_time
                ? `<span class="badge bg-primary">${fmtTime(r.signout_time)}</span>`
                : `<span class="badge badge-urgent">Not out</span>`;
            const actionCell = hasInput && !r.signout_time
                ? `<button class="btn btn-sm btn-semas py-0 px-2" style="font-size:.74rem;"
                           onclick="loadPreviewModal(${sid},${r.user_id},'sign_out')">Sign Out</button>`
                : '';
            return `<tr>
              <td class="fw-semibold">${esc(r.full_name)}</td>
              <td class="text-muted">${esc(r.reg_number||'—')}</td>
              <td><span class="badge badge-completed">${sinTime}</span></td>
              <td>${soutCell}</td>
              <td>${actionCell}</td>
            </tr>`;
        });
        container.innerHTML = `<div class="table-responsive">
          <table class="table table-sm align-middle mb-0" style="font-size:.83rem;">
            <thead><tr><th>Student</th><th>Reg No.</th><th>Sign In</th><th>Sign Out</th><th></th></tr></thead>
            <tbody>${rows.join('')}</tbody>
          </table></div>`;
    } else {
        if (missed.length === 0) {
            container.innerHTML = '<p class="text-muted small mb-0">All enrolled students have signed in.</p>';
            return;
        }
        const rows = missed.map(r => {
            const actionCell = hasInput
                ? `<button class="btn btn-sm btn-semas-gold py-0 px-2" style="font-size:.74rem;"
                           onclick="loadPreviewModal(${sid},${r.user_id},'sign_in')">Sign In</button>`
                : '';
            return `<tr>
              <td class="fw-semibold">${esc(r.full_name)}</td>
              <td class="text-muted">${esc(r.reg_number||'—')}</td>
              <td><span class="badge bg-secondary">Not signed in</span></td>
              <td>${actionCell}</td>
            </tr>`;
        });
        container.innerHTML = `<div class="table-responsive">
          <table class="table table-sm align-middle mb-0" style="font-size:.83rem;">
            <thead><tr><th>Student</th><th>Reg No.</th><th>Status</th><th></th></tr></thead>
            <tbody>${rows.join('')}</tbody>
          </table></div>`;
    }
}

// Toggle history roster visibility & lazy-load
function toggleHistoryRoster(sid, btn) {
    const panel = document.getElementById('histRoster-' + sid);
    const open  = panel.style.display !== 'none';
    panel.style.display = open ? 'none' : '';
    btn.innerHTML = open
        ? '<i class="bi bi-list-ul me-1"></i>View Roster'
        : '<i class="bi bi-chevron-up me-1"></i>Hide Roster';
    if (!open && !rosterCache[sid]) {
        rosterTab[sid] = 'in_room';
        refreshRoster(sid);
    } else if (!open && rosterCache[sid]) {
        renderRoster(sid);
    }
}

// ── Lookup by reg number ────────────────────────────────────────────────────
function triggerLookup(sid, action) {
    const regInput = document.getElementById('regInput-' + sid);
    const q = regInput ? regInput.value.trim() : '';
    const fb = document.getElementById('inputFeedback-' + sid);
    if (!q) { setFeedback(fb, 'Enter a registration number.', 'danger'); return; }
    setFeedback(fb, '', '');
    openPreviewModal(action);
    postApi(sid, 'lookup', { reg_number: q }, function(data) {
        if (!data.ok) { showPreviewError(data.message || 'Student not found.'); return; }
        if (data.single) {
            confirmState = { scheduleId: sid, studentId: data.student.user_id, action };
            populatePreview(data, sid, action);
        } else {
            showSearchPicker(sid, data.results || [], action);
        }
    });
}

function openPreviewModal(action) {
    document.getElementById('previewModalTitle').textContent =
        action === 'sign_in' ? 'Confirm Sign In' : 'Confirm Sign Out';
    document.getElementById('previewLoading').style.display    = '';
    document.getElementById('previewContent').style.display    = 'none';
    document.getElementById('previewError').style.display      = 'none';
    document.getElementById('searchResultsList').style.display = 'none';
    document.getElementById('confirmActionBtn').style.display  = 'none';
    const modalEl = document.getElementById('previewModal');
    (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).show();
}

function loadPreviewModal(sid, userId, action) {
    confirmState = { scheduleId: sid, studentId: userId, action };
    openPreviewModal(action);
    postApi(sid, 'preview', { user_id: userId }, function(data) {
        if (!data.ok) { showPreviewError(data.message || 'Could not load student.'); return; }
        populatePreview(data, sid, action);
    });
}

function populatePreview(data, sid, action) {
    const s = data.student;
    document.getElementById('prevPhoto').src           = s.photo_url;
    document.getElementById('prevName').textContent    = s.full_name;
    document.getElementById('prevReg').textContent     = 'Reg. No: ' + (s.reg_number || '—');
    document.getElementById('prevDept').textContent    = s.department || '';
    document.getElementById('prevEligBadge').innerHTML = data.eligible
        ? '<span class="badge badge-completed">Eligible</span>'
        : `<span class="badge badge-cancelled">Not Eligible — ${esc(data.elig_status)}</span>`;

    let statusHtml = '';
    if (data.signed_in && data.signed_out)
        statusHtml = `<span class="badge bg-success">In: ${data.signin_time} &middot; Out: ${data.signout_time}</span>`;
    else if (data.signed_in)
        statusHtml = `<span class="badge badge-completed">Signed in at ${data.signin_time}</span> <span class="badge bg-secondary">Not yet out</span>`;
    else
        statusHtml = `<span class="badge bg-secondary">Not yet signed in</span>`;
    document.getElementById('prevSignStatus').innerHTML = statusHtml;

    const warn    = document.getElementById('prevWarning');
    const blocked = document.getElementById('prevBlocked');
    warn.style.display = blocked.style.display = 'none';

    let canConfirm = true;
    if (action === 'sign_in') {
        if (data.signed_in) {
            blocked.textContent = s.full_name + ' is already signed in.';
            blocked.style.display = ''; canConfirm = false;
        } else if (!data.eligible) {
            warn.textContent = 'This student is NOT eligible. You can still sign them in — the HOD will be notified.';
            warn.style.display = '';
        }
    } else {
        if (!data.signed_in) {
            blocked.textContent = s.full_name + ' has not signed in yet.';
            blocked.style.display = ''; canConfirm = false;
        } else if (data.signed_out) {
            blocked.textContent = s.full_name + ' has already signed out.';
            blocked.style.display = ''; canConfirm = false;
        }
    }

    document.getElementById('previewLoading').style.display    = 'none';
    document.getElementById('previewContent').style.display    = '';
    document.getElementById('searchResultsList').style.display = 'none';
    const confirmBtn = document.getElementById('confirmActionBtn');
    confirmBtn.style.display = canConfirm ? '' : 'none';
    document.getElementById('confirmBtnText').textContent =
        action === 'sign_in' ? 'Confirm Sign In' : 'Confirm Sign Out';
}

function showPreviewError(msg) {
    document.getElementById('previewLoading').style.display   = 'none';
    document.getElementById('previewContent').style.display   = 'none';
    document.getElementById('previewError').style.display     = '';
    document.getElementById('previewError').textContent       = msg;
    document.getElementById('confirmActionBtn').style.display = 'none';
}

function showSearchPicker(sid, results, action) {
    document.getElementById('previewLoading').style.display    = 'none';
    document.getElementById('searchResultsList').style.display = '';
    document.getElementById('searchResultsBody').innerHTML = results.map(r =>
        `<div class="border rounded p-2 mb-1 small d-flex justify-content-between align-items-center gap-2">
           <div><span class="fw-semibold">${esc(r.full_name)}</span> <span class="text-muted">${esc(r.reg_number||'—')}</span></div>
           <button class="btn btn-sm btn-semas-gold py-0 px-2" style="font-size:.74rem;"
                   onclick="loadPreviewModal(${sid},${r.user_id},'${action}')">Select</button>
         </div>`
    ).join('');
}

document.getElementById('confirmActionBtn').addEventListener('click', function() {
    const { scheduleId, studentId, action } = confirmState;
    this.disabled = true;
    document.getElementById('confirmBtnText').textContent = 'Processing…';
    postApi(scheduleId, action, { user_id: studentId }, function(data) {
        const btn = document.getElementById('confirmActionBtn');
        btn.disabled = false;
        document.getElementById('confirmBtnText').textContent =
            confirmState.action === 'sign_in' ? 'Confirm Sign In' : 'Confirm Sign Out';
        if (data.ok) {
            bootstrap.Modal.getInstance(document.getElementById('previewModal'))?.hide();
            const regInput = document.getElementById('regInput-' + scheduleId);
            if (regInput) regInput.value = '';
            const fb = document.getElementById('inputFeedback-' + scheduleId);
            if (fb) {
                setFeedback(fb, data.message, 'success');
                setTimeout(() => setFeedback(fb, '', ''), 3000);
            }
            refreshRoster(scheduleId);
        } else {
            document.getElementById('prevBlocked').textContent = data.message;
            document.getElementById('prevBlocked').style.display = '';
        }
    });
});

// ── Submit ──────────────────────────────────────────────────────────────────
function openSubmitModal(sid, moduleTitle) {
    activeSubmitSid = sid;
    document.getElementById('submitModalTitle').textContent = 'Submit Attendance — ' + moduleTitle;
    postApi(sid, 'roster', {}, function(data) {
        if (!data.ok) return;
        const missing = (data.roster || []).filter(r => r.signin_time && !r.signout_time);
        let html = '';
        if (missing.length === 0) {
            html = '<div class="alert alert-success small">All signed-in students have signed out. Ready to submit.</div>';
        } else {
            html = `<div class="alert alert-warning small mb-2">${missing.length} student(s) signed in without signing out — provide a reason for each:</div>`;
            missing.forEach(r => {
                html += `<div class="border rounded p-2 mb-2 small">
                  <div class="fw-semibold mb-1">${esc(r.full_name)} <span class="text-muted">(${esc(r.reg_number||'—')})</span></div>
                  <div class="row g-2">
                    <div class="col-5">
                      <select class="form-select form-select-sm missing-reason" data-uid="${r.user_id}" required>
                        <option value="">Select reason…</option>
                        <option value="Cheating">Cheating / Expelled</option>
                        <option value="Sickness">Sickness / Medical</option>
                        <option value="Other">Other</option>
                      </select>
                    </div>
                    <div class="col-7">
                      <input class="form-control form-control-sm missing-notes" data-uid="${r.user_id}" placeholder="Notes (optional)">
                    </div>
                  </div>
                </div>`;
            });
        }
        document.getElementById('submitRosterReview').innerHTML = html;
        document.getElementById('submissionNotes').value = '';
        new bootstrap.Modal(document.getElementById('submitModal')).show();
    });
}

document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
    const missingSignouts = [];
    let valid = true;
    document.querySelectorAll('.missing-reason').forEach(sel => {
        const uid    = sel.dataset.uid;
        const reason = sel.value;
        const notes  = document.querySelector(`.missing-notes[data-uid="${uid}"]`)?.value || '';
        if (!reason) { sel.classList.add('is-invalid'); valid = false; }
        else { sel.classList.remove('is-invalid'); missingSignouts.push({ user_id: uid, reason, notes }); }
    });
    if (!valid) return;

    postApi(activeSubmitSid, 'submit', {
        missing_signouts: JSON.stringify(missingSignouts),
        submission_notes: document.getElementById('submissionNotes').value,
    }, function(data) {
        if (data.ok) {
            bootstrap.Modal.getInstance(document.getElementById('submitModal'))?.hide();
            setTimeout(() => location.reload(), 1200);
        } else {
            document.getElementById('submitRosterReview').insertAdjacentHTML('afterbegin',
                `<div class="alert alert-danger small">${esc(data.message)}</div>`);
        }
    });
});

// ── Helpers ─────────────────────────────────────────────────────────────────
function postApi(sid, action, extra, cb) {
    const params = new URLSearchParams({ action, schedule_id: sid, csrf_token: CSRF, ...extra });
    fetch(APP_URL + '/api/cat-exam-attendance-confirm.php', { method: 'POST', body: params })
        .then(r => r.json()).then(cb).catch(err => console.error(err));
}

function fmtTime(dt) {
    if (!dt) return '—';
    try { return new Date(dt).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); }
    catch { return dt; }
}

function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function setFeedback(el, msg, type) {
    if (!el) return;
    el.textContent = msg;
    el.className   = msg ? ('small mt-1 text-' + type) : 'small mt-1';
}

// ── Init: load rosters for active sessions only ─────────────────────────────
<?php foreach ($activeSchedules as $s): ?>
rosterTab[<?= (int) $s['schedule_id'] ?>] = 'in_room';
refreshRoster(<?= (int) $s['schedule_id'] ?>);
<?php endforeach; ?>

setInterval(function() {
    <?php foreach ($activeSchedules as $s): ?>
    refreshRoster(<?= (int) $s['schedule_id'] ?>);
    <?php endforeach; ?>
}, 20000);
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
