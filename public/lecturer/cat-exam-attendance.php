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
    echo '<div class="alert alert-warning small">Lecturer profile not found. Contact the administrator.</div>';
    require __DIR__ . '/../partials/layout_bottom.php';
    exit;
}

// Today's schedules where this lecturer is invigilator
$todayStmt = $db->prepare(
    "SELECT cs.*, m.module_title, m.session_type, m.module_id,
            d.department_name,
            lec.full_name AS module_lecturer_name,
            (SELECT COUNT(*) FROM module_enrollments e WHERE e.module_id = m.module_id) AS enrolled_count,
            (SELECT COUNT(*) FROM cat_exam_attendance_logs cal WHERE cal.schedule_id = cs.schedule_id AND cal.attendance_type = 'Sign In') AS signed_in_count,
            (SELECT COUNT(*) FROM cat_exam_attendance_logs cal WHERE cal.schedule_id = cs.schedule_id AND cal.attendance_type = 'Sign Out') AS signed_out_count,
            (SELECT submission_id FROM cat_exam_submissions sub WHERE sub.schedule_id = cs.schedule_id LIMIT 1) AS submission_id
     FROM cat_exam_schedules cs
     JOIN modules m ON m.module_id = cs.module_id
     LEFT JOIN departments d ON d.department_id = m.department_id
     LEFT JOIN lecturers ll ON ll.lecturer_id = m.lecturer_id
     LEFT JOIN users lec ON lec.user_id = ll.user_id
     WHERE cs.invigilator_id = :lid AND cs.scheduled_date = CURDATE()
     ORDER BY cs.start_time ASC"
);
$todayStmt->execute(['lid' => $lecturer['lecturer_id']]);
$todaySchedules = $todayStmt->fetchAll();

// Selected schedule
$scheduleId = (int) ($_GET['schedule_id'] ?? ($todaySchedules[0]['schedule_id'] ?? 0));
$selected   = null;
foreach ($todaySchedules as $s) {
    if ((int) $s['schedule_id'] === $scheduleId) { $selected = $s; break; }
}

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">CAT / Exam Attendance</h4>
<p class="text-muted small mb-3">
  Sign students in and out during assessments you are invigilating. Students cannot self-scan during CAT or Exam.
  <strong>Sign-out is blocked within the first 60 minutes.</strong> Submit the full list to HOD when done.
</p>

<?php if (!$todaySchedules): ?>
  <div class="semas-card p-4 text-center">
    <i class="bi bi-calendar-x" style="font-size:2rem;color:var(--semas-text-muted);"></i>
    <h6 class="display-font mt-2">No Assessments Today</h6>
    <p class="text-muted small mb-0">You have no CAT or Exam sessions assigned for today.</p>
  </div>
<?php else: ?>

  <!-- Schedule tabs -->
  <?php if (count($todaySchedules) > 1): ?>
    <ul class="nav nav-pills mb-3">
      <?php foreach ($todaySchedules as $s): ?>
        <li class="nav-item">
          <a class="nav-link <?= $scheduleId === (int) $s['schedule_id'] ? 'active' : '' ?>"
             href="?schedule_id=<?= (int) $s['schedule_id'] ?>">
            <?= e($s['module_title']) ?>
            <span class="badge bg-light text-dark ms-1"><?= e($s['exam_type']) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($selected): ?>
    <?php
      $isSubmitted = (bool) $selected['submission_id'];
      $nowCairo    = ClassAttendance::now();
      $examStartDt = $selected['start_time']
          ? new DateTime($selected['scheduled_date'] . ' ' . $selected['start_time'], new DateTimeZone('Africa/Cairo'))
          : null;
      $elapsedMin  = $examStartDt ? ($nowCairo->getTimestamp() - $examStartDt->getTimestamp()) / 60 : 99;
      $signOutOpen = $elapsedMin >= 60;
      $timeLabel   = $selected['start_time']
          ? date('h:i A', strtotime($selected['start_time'])) . '–' . date('h:i A', strtotime($selected['end_time']))
          : '—';
    ?>

    <div class="semas-card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
          <h5 class="display-font mb-0"><?= e($selected['module_title']) ?> — <?= e($selected['exam_type']) ?></h5>
          <p class="text-muted small mb-0">
            <?= e($selected['department_name'] ?? '') ?> &middot;
            Room: <strong><?= e($selected['room']) ?></strong> &middot;
            Time: <strong><?= e($timeLabel) ?></strong>
          </p>
        </div>
        <div class="text-end">
          <div class="small text-muted">Enrolled: <strong><?= (int) $selected['enrolled_count'] ?></strong></div>
          <div class="small text-muted">Signed In: <strong id="sinCount"><?= (int) $selected['signed_in_count'] ?></strong> &nbsp; Signed Out: <strong id="soutCount"><?= (int) $selected['signed_out_count'] ?></strong></div>
          <?php if ($isSubmitted): ?>
            <span class="badge badge-completed mt-1"><i class="bi bi-check-circle me-1"></i>Submitted to HOD</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$signOutOpen && !$isSubmitted): ?>
      <div class="alert alert-info small">
        <i class="bi bi-clock me-1"></i>
        <strong>Sign-out locked</strong> — students must remain for at least 60 minutes.
        Sign-out opens at <strong><?= $examStartDt ? date('h:i A', $examStartDt->getTimestamp() + 3600) : '—' ?></strong>.
      </div>
    <?php endif; ?>

    <?php if (!$isSubmitted): ?>
    <div class="row g-3 mb-3">
      <!-- Search Panel -->
      <div class="col-md-6">
        <div class="semas-card p-3">
          <h6 class="display-font mb-2"><i class="bi bi-search me-1"></i> Find Student</h6>
          <div class="d-flex gap-2 mb-2">
            <input id="searchBox" class="form-control form-control-sm" placeholder="Name or Registration Number…">
            <button id="searchBtn" class="btn btn-sm btn-semas text-nowrap">Search</button>
          </div>
          <div id="searchResults"></div>
          <div id="foundBar" class="alert alert-success small d-none mt-2 py-2 d-flex justify-content-between align-items-center">
            <span id="foundText"></span>
            <button id="confirmFoundBtn" class="btn btn-sm btn-semas-gold">View Profile</button>
          </div>
        </div>
      </div>

      <!-- Profile Preview -->
      <div class="col-md-6">
        <div id="previewPanel" class="semas-card p-3" style="display:none;">
          <h6 class="display-font mb-2">Student Profile</h6>
          <div class="d-flex gap-3 align-items-start">
            <img id="prevPhoto" src="" alt="" style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid var(--semas-gold);">
            <div>
              <div class="fw-semibold" id="prevName"></div>
              <div class="text-muted small" id="prevReg"></div>
              <div class="text-muted small" id="prevDept"></div>
              <div id="prevEligBadge" class="mt-1"></div>
            </div>
          </div>
          <div id="prevSignStatus" class="mt-2 small text-muted"></div>
          <div id="prevWarning" class="alert alert-danger small mt-2 py-1 px-2" style="display:none;"></div>
          <div class="mt-3 d-flex gap-2" id="actionBtns">
            <button id="signInBtn"  class="btn btn-sm btn-semas-gold"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</button>
            <button id="signOutBtn" class="btn btn-sm btn-semas" <?= !$signOutOpen ? 'disabled title="Sign-out opens after 60 min"' : '' ?>><i class="bi bi-box-arrow-right me-1"></i>Sign Out</button>
            <button id="cancelBtn"  class="btn btn-sm btn-outline-dark">Cancel</button>
          </div>
          <div id="actionResult" class="mt-2 small"></div>
        </div>
      </div>
    </div>

    <!-- Live Roster -->
    <div class="semas-card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="display-font mb-0"><i class="bi bi-people me-1"></i> Live Roster</h6>
        <button class="btn btn-sm btn-outline-dark" onclick="refreshRoster()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
      </div>
      <div id="rosterContainer"><p class="text-muted small">Loading…</p></div>
    </div>

    <!-- Submit Button -->
    <div class="semas-card p-3">
      <h6 class="display-font mb-1">Submit Attendance List</h6>
      <p class="text-muted small mb-2">
        After submitting, no further sign-in or sign-out changes can be made. The HOD will see this submission.
        For every student who signed in but did NOT sign out, you must provide a reason before submitting.
      </p>
      <button class="btn btn-semas-gold" id="submitBtn"><i class="bi bi-send me-1"></i> Submit Full List to HOD</button>
    </div>
    <?php else: ?>
    <!-- Already submitted: read-only roster -->
    <div class="semas-card p-3 mb-3">
      <h6 class="display-font mb-2"><i class="bi bi-people me-1"></i> Submitted Attendance Roster</h6>
      <div id="rosterContainer"><p class="text-muted small">Loading…</p></div>
    </div>
    <?php endif; ?>

    <!-- Submit Modal -->
    <div class="modal fade" id="submitModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h6 class="modal-title display-font">Submit Attendance — <?= e($selected['module_title']) ?></h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="text-muted small mb-3">Review students below. Students without sign-out require a reason before you can submit.</p>
            <div id="submitRosterReview"></div>
            <div class="mb-3 mt-3">
              <label class="form-label small">General Notes (optional)</label>
              <textarea id="submissionNotes" class="form-control form-control-sm" rows="2" placeholder="e.g. Power outage for 10 min at 09:45"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button id="confirmSubmitBtn" class="btn btn-semas-gold btn-sm"><i class="bi bi-send me-1"></i> Confirm Submit</button>
          </div>
        </div>
      </div>
    </div>

  <?php endif; ?>
<?php endif; ?>

<script>
const APP_URL     = window.SEMAS_BASE_URL;
const CSRF        = '<?= csrf_token() ?>';
const SCHEDULE_ID = <?= (int) $scheduleId ?>;
const IS_SUBMITTED = <?= json_encode($isSubmitted ?? false) ?>;
const SIGNOUT_OPEN = <?= json_encode($signOutOpen ?? false) ?>;

let pendingStudentId = null;

// ── Search ──────────────────────────────────────────────────────────────
document.getElementById('searchBtn')?.addEventListener('click', doSearch);
document.getElementById('searchBox')?.addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });

function doSearch() {
  const q = document.getElementById('searchBox').value;
  if (q.length < 2) return;
  postApi('search', { q }, data => {
    const box = document.getElementById('searchResults');
    box.innerHTML = (data.results || []).map(s =>
      `<div class="border-bottom py-1 small" style="cursor:pointer"
            data-uid="${s.user_id}" data-name="${s.full_name}" data-reg="${s.reg_number || ''}">
        ${s.full_name} <span class="text-muted">(${s.reg_number || '—'})</span>
      </div>`
    ).join('') || '<p class="text-muted small mb-0">No registered student matches.</p>';
  });
}

document.getElementById('searchResults')?.addEventListener('click', function(e) {
  const row = e.target.closest('[data-uid]');
  if (!row) return;
  pendingStudentId = row.dataset.uid;
  document.getElementById('foundText').textContent = 'Found: ' + row.dataset.name + ' (' + (row.dataset.reg || '—') + ')';
  document.getElementById('foundBar').classList.remove('d-none');
  document.getElementById('previewPanel').style.display = 'none';
});

document.getElementById('confirmFoundBtn')?.addEventListener('click', () => {
  if (!pendingStudentId) return;
  loadPreview(pendingStudentId);
});

function loadPreview(userId) {
  postApi('preview', { user_id: userId }, data => {
    if (!data.ok) {
      document.getElementById('actionResult').innerHTML = `<span class="text-danger">${data.message}</span>`;
      return;
    }
    const s = data.student;
    document.getElementById('prevPhoto').src = s.photo_url;
    document.getElementById('prevName').textContent  = s.full_name;
    document.getElementById('prevReg').textContent   = 'Reg. No: ' + (s.reg_number || '—');
    document.getElementById('prevDept').textContent  = s.department || '';
    document.getElementById('prevEligBadge').innerHTML = data.eligible
      ? '<span class="badge badge-completed">Eligible</span>'
      : `<span class="badge badge-cancelled">Not Eligible — ${data.elig_status}</span>`;

    let statusHtml = '';
    if (data.signed_in && data.signed_out)   statusHtml = `<span class="badge bg-success">Signed In ${data.signin_time} · Out ${data.signout_time}</span>`;
    else if (data.signed_in)                 statusHtml = `<span class="badge badge-completed">Signed In at ${data.signin_time}</span> <span class="badge bg-secondary">Not Signed Out</span>`;
    else                                     statusHtml = `<span class="badge bg-secondary">Not yet signed in</span>`;
    document.getElementById('prevSignStatus').innerHTML = statusHtml;

    const warn = document.getElementById('prevWarning');
    if (!data.eligible) { warn.style.display = ''; warn.textContent = 'This student is NOT eligible for this ' + '<?= e($selected['exam_type'] ?? '') ?>' + '. You may still sign them in but the HOD will be notified.'; }
    else { warn.style.display = 'none'; }

    document.getElementById('signInBtn').disabled  = IS_SUBMITTED || (data.signed_in && data.signed_out) || data.signed_in;
    document.getElementById('signOutBtn').disabled = IS_SUBMITTED || !data.signed_in || data.signed_out || !SIGNOUT_OPEN;
    document.getElementById('foundBar').classList.add('d-none');
    document.getElementById('previewPanel').style.display = '';
    document.getElementById('actionResult').innerHTML = '';
    pendingStudentId = userId;
  });
}

document.getElementById('cancelBtn')?.addEventListener('click', () => {
  document.getElementById('previewPanel').style.display = 'none';
  pendingStudentId = null;
});

document.getElementById('signInBtn')?.addEventListener('click', () => {
  if (!pendingStudentId) return;
  postApi('sign_in', { user_id: pendingStudentId }, data => {
    document.getElementById('actionResult').innerHTML = `<span class="${data.ok ? 'text-success' : 'text-danger'}">${data.message}</span>`;
    if (data.ok) { refreshRoster(); loadPreview(pendingStudentId); }
  });
});

document.getElementById('signOutBtn')?.addEventListener('click', () => {
  if (!pendingStudentId) return;
  postApi('sign_out', { user_id: pendingStudentId }, data => {
    document.getElementById('actionResult').innerHTML = `<span class="${data.ok ? 'text-success' : 'text-danger'}">${data.message}</span>`;
    if (data.ok) { refreshRoster(); loadPreview(pendingStudentId); }
  });
});

// ── Roster ───────────────────────────────────────────────────────────────
function refreshRoster() {
  postApi('roster', {}, data => {
    if (!data.ok) return;
    const container = document.getElementById('rosterContainer');
    if (!data.roster || data.roster.length === 0) {
      container.innerHTML = '<p class="text-muted small mb-0">No students found.</p>';
      return;
    }
    let sinCount = 0, soutCount = 0;
    const rows = data.roster.map(r => {
      if (r.signin_time)  sinCount++;
      if (r.signout_time) soutCount++;
      const sinBadge  = r.signin_time  ? `<span class="badge badge-completed">${r.signin_time ? new Date(r.signin_time).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}) : '—'}</span>` : '<span class="badge bg-secondary">—</span>';
      const soutBadge = r.signout_time ? `<span class="badge bg-primary">${new Date(r.signout_time).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'})}</span>`
                      : (r.signin_time ? '<span class="badge badge-urgent">Not out</span>' : '<span class="badge bg-secondary">Absent</span>');
      return `<tr>
        <td>${r.full_name}</td>
        <td class="text-muted small">${r.reg_number || '—'}</td>
        <td>${sinBadge}</td>
        <td>${soutBadge}</td>
      </tr>`;
    });
    container.innerHTML = `<div class="table-responsive"><table class="table table-sm align-middle" style="font-size:.84rem;">
      <thead><tr><th>Student</th><th>Reg No.</th><th>Sign In</th><th>Sign Out</th></tr></thead>
      <tbody>${rows.join('')}</tbody>
    </table></div>`;
    const sc = document.getElementById('sinCount');
    const oc = document.getElementById('soutCount');
    if (sc) sc.textContent = sinCount;
    if (oc) oc.textContent = soutCount;
  });
}
refreshRoster();
setInterval(refreshRoster, 15000);

// ── Submit ────────────────────────────────────────────────────────────────
document.getElementById('submitBtn')?.addEventListener('click', () => {
  postApi('roster', {}, data => {
    if (!data.ok) return;
    const missing = (data.roster || []).filter(r => r.signin_time && !r.signout_time);
    let reviewHtml = '';
    if (missing.length === 0) {
      reviewHtml = '<div class="alert alert-success small">All signed-in students have signed out. Ready to submit.</div>';
    } else {
      reviewHtml = `<div class="alert alert-warning small mb-2">${missing.length} student(s) signed in but did NOT sign out. Provide a reason for each:</div>`;
      missing.forEach(r => {
        reviewHtml += `<div class="border rounded p-2 mb-2 small">
          <div class="fw-semibold mb-1">${r.full_name} <span class="text-muted">(${r.reg_number || '—'})</span></div>
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
              <input class="form-control form-control-sm missing-notes" data-uid="${r.user_id}" placeholder="Additional notes (optional)">
            </div>
          </div>
        </div>`;
      });
    }
    document.getElementById('submitRosterReview').innerHTML = reviewHtml;
    new bootstrap.Modal(document.getElementById('submitModal')).show();
  });
});

document.getElementById('confirmSubmitBtn')?.addEventListener('click', () => {
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

  const notes = document.getElementById('submissionNotes').value;
  postApi('submit', { missing_signouts: JSON.stringify(missingSignouts), submission_notes: notes }, data => {
    if (data.ok) {
      bootstrap.Modal.getInstance(document.getElementById('submitModal'))?.hide();
      document.querySelector('.alert')?.insertAdjacentHTML('afterend', `<div class="alert alert-success small">${data.message}</div>`);
      setTimeout(() => location.reload(), 1500);
    } else {
      document.getElementById('submitRosterReview').insertAdjacentHTML('afterbegin', `<div class="alert alert-danger small">${data.message}</div>`);
    }
  });
});

// ── Helper ────────────────────────────────────────────────────────────────
function postApi(action, extra, cb) {
  const params = new URLSearchParams({ action, schedule_id: SCHEDULE_ID, csrf_token: CSRF, ...extra });
  fetch(APP_URL + '/api/cat-exam-attendance-confirm.php', { method: 'POST', body: params })
    .then(r => r.json()).then(cb).catch(err => console.error(err));
}
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
