<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal']);

$pageTitle = 'System Announcements';
$activeNav = 'announcements';
$db = Database::connection();
$me = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (empty(trim($_POST['title'] ?? '')) || empty(trim($_POST['message'] ?? ''))) {
        flash('error', 'Title and message are required and cannot be empty.');
        redirect('/admin/announcements.php');
    }

    // ── Resolve recipients ────────────────────────────────────────────────
    $who      = $_POST['who']      ?? 'everyone';       // everyone|students|lecturers|staff
    $subScope = $_POST['sub_scope'] ?? 'all';           // all|department|session|year|first_year|final_year
    $deptId   = (int) ($_POST['department_id'] ?? 0) ?: null;
    $sessionT = $_POST['session_type'] ?? '';
    $yearVal  = (int) ($_POST['year_of_study'] ?? 0);

    $recipients   = [];
    $audienceKey  = 'University Community';
    $scopeLabel   = 'University-wide (system notice)';

    if ($who === 'everyone') {
        $audienceKey = 'University Community';
        $scopeLabel  = 'University-wide / All active users';
        $recipients  = AudienceResolver::resolve('University Community');

    } elseif ($who === 'staff') {
        $audienceKey = 'All Staff';
        $scopeLabel  = 'All Staff / Principals, Deans, HODs, Registrars, Coordinators & Lecturers';
        // Include all non-student roles
        $stmt = $db->query(
            "SELECT u.* FROM users u JOIN roles r ON r.role_id=u.role_id
             WHERE r.role_name IN ('Principal','Dean','HOD','Lecturer','Registrar','Coordinator')
               AND u.status='Active'"
        );
        $recipients = $stmt->fetchAll();

    } elseif ($who === 'registrar') {
        $audienceKey = 'Registrar';
        $scopeLabel  = 'Registrar Office';
        $stmt = $db->query(
            "SELECT u.* FROM users u JOIN roles r ON r.role_id=u.role_id
             WHERE r.role_name='Registrar' AND u.status='Active'"
        );
        $recipients = $stmt->fetchAll();

    } elseif ($who === 'coordinator') {
        $audienceKey = 'Coordinator';
        $scopeLabel  = 'Weekend Coordinators';
        $stmt = $db->query(
            "SELECT u.* FROM users u JOIN roles r ON r.role_id=u.role_id
             WHERE r.role_name='Coordinator' AND u.status='Active'"
        );
        $recipients = $stmt->fetchAll();

    } elseif ($who === 'lecturers') {
        $audienceKey = 'Department Lecturers';
        $sql = "SELECT u.* FROM users u JOIN lecturers l ON l.user_id = u.user_id WHERE u.status = 'Active'";
        $params = [];
        if ($deptId) {
            $sql .= ' AND l.department_id = :dept';
            $params['dept'] = $deptId;
            $dn = $db->prepare('SELECT department_name FROM departments WHERE department_id = :id');
            $dn->execute(['id' => $deptId]);
            $deptName   = $dn->fetchColumn() ?: '';
            $scopeLabel = 'Lecturers / ' . $deptName;
            $audienceKey = 'Department Lecturers';
        } else {
            $scopeLabel = 'All Lecturers';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $recipients = $stmt->fetchAll();

    } elseif ($who === 'students') {
        if ($subScope === 'department' && $deptId) {
            $dn = $db->prepare('SELECT department_name FROM departments WHERE department_id = :id');
            $dn->execute(['id' => $deptId]);
            $deptName    = $dn->fetchColumn() ?: '';
            $audienceKey = 'Specific Department';
            $scopeLabel  = 'Students / Department of ' . $deptName;
            $recipients  = AudienceResolver::resolve('Specific Department', $deptId);
        } elseif ($subScope === 'session' && $sessionT) {
            $audienceKey = $sessionT . ' Students';
            $scopeLabel  = $sessionT . ' Students';
            $recipients  = AudienceResolver::resolve($sessionT . ' Students');
        } elseif ($subScope === 'first_year') {
            $audienceKey = 'First Year Students';
            $scopeLabel  = 'First Year Students';
            $recipients  = AudienceResolver::resolve('First Year Students');
        } elseif ($subScope === 'final_year') {
            $audienceKey = 'Final Year Students';
            $scopeLabel  = 'Final Year Students';
            $recipients  = AudienceResolver::resolve('Final Year Students');
        } elseif ($subScope === 'year' && $yearVal) {
            $audienceKey = 'All Students';
            $scopeLabel  = 'Students / Year ' . $yearVal;
            $stmt = $db->prepare(
                "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                 WHERE r.role_name = 'Student' AND u.status = 'Active' AND u.year_of_study = :year"
            );
            $stmt->execute(['year' => $yearVal]);
            $recipients = $stmt->fetchAll();
        } else {
            $audienceKey = 'All Students';
            $scopeLabel  = 'All Students';
            $recipients  = AudienceResolver::resolve('All Students');
        }
    }

    $result = Announcement::create([
        'title'           => $_POST['title'],
        'category'        => 'General Notice',
        'priority'        => $_POST['priority'],
        'target_audience' => $audienceKey,
        'message'         => $_POST['message'],
        'status'          => 'Published',
        'recipients'      => $recipients,
    ], $me, 'Principal', $scopeLabel);

    flash('success', "System announcement sent to {$result['recipients']} user(s). Scope: $scopeLabel.");
    redirect('/admin/announcements.php');
}

$departments = $db->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll();

$myAnnouncements = $db->prepare("SELECT * FROM announcements WHERE posted_by = :uid ORDER BY posted_at DESC LIMIT 20");
$myAnnouncements->execute(['uid' => $me['user_id']]);
$myAnnouncements = $myAnnouncements->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">System Announcements</h4>

<div class="semas-card p-3 mb-4">
  <form method="post">
    <?= csrf_field() ?>
    <div class="row g-3">

      <div class="col-md-8"><label class="form-label small">Title</label><input name="title" class="form-control" required></div>
      <div class="col-md-4">
        <label class="form-label small">Priority</label>
        <select name="priority" class="form-select">
          <option>Medium</option><option>High</option><option>Urgent</option><option>Low</option>
        </select>
      </div>

      <div class="col-12"><label class="form-label small">Message</label><textarea name="message" class="form-control" rows="3" required></textarea></div>

      <div class="col-12"><hr class="my-0"><label class="form-label small fw-semibold mt-1">Send To</label></div>

      <!-- Who -->
      <div class="col-md-3">
        <label class="form-label small text-muted">Recipient group</label>
        <select name="who" id="whoSelect" class="form-select" onchange="syncWho()">
          <option value="everyone">Everyone (all users)</option>
          <option value="students">Students</option>
          <option value="lecturers">Lecturers</option>
          <option value="staff">All Staff (Admins / Deans / HODs / Registrars / Coordinators)</option>
          <option value="registrar">Registrar Office only</option>
          <option value="coordinator">Coordinator(s) only</option>
        </select>
      </div>

      <!-- Students sub-scope -->
      <div class="col-md-3" id="subScopeWrap" style="display:none;">
        <label class="form-label small text-muted">Filter students by</label>
        <select name="sub_scope" id="subScope" class="form-select" onchange="syncSubScope()">
          <option value="all">All students</option>
          <option value="department">Department</option>
          <option value="session">Session type</option>
          <option value="first_year">First year students</option>
          <option value="final_year">Final year students</option>
          <option value="year">Specific year of study</option>
        </select>
      </div>

      <!-- Department picker (students by dept OR lecturers by dept) -->
      <div class="col-md-3" id="deptWrap" style="display:none;">
        <label class="form-label small text-muted">Department</label>
        <select name="department_id" class="form-select">
          <option value="">All departments</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Session type picker -->
      <div class="col-md-3" id="sessionWrap" style="display:none;">
        <label class="form-label small text-muted">Session</label>
        <select name="session_type" class="form-select">
          <option value="Day">Day</option>
          <option value="Evening">Evening</option>
          <option value="Weekend">Weekend</option>
        </select>
      </div>

      <!-- Year of study picker -->
      <div class="col-md-3" id="yearWrap" style="display:none;">
        <label class="form-label small text-muted">Year of study</label>
        <select name="year_of_study" class="form-select">
          <?php for ($y = 1; $y <= 6; $y++): ?>
            <option value="<?= $y ?>">Year <?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>

    </div>

    <div class="mt-3 d-flex align-items-center gap-3 flex-wrap">
      <button class="btn btn-semas" id="adminSendBtn" onclick="this.disabled=true;this.innerHTML='<span class=\'spinner-border spinner-border-sm me-1\'></span> Sending…';this.form.submit()">
        <i class="bi bi-send me-1"></i> Publish &amp; Notify
      </button>
      <div class="small text-muted mb-0">Email and SMS are sent automatically to every recipient with a phone number.</div>
    </div>
  </form>
</div>

<div class="semas-card p-3 mb-3"><h6 class="display-font mb-0">Your System Announcements</h6></div>
<?php if (!$myAnnouncements): ?>
  <div class="semas-card p-4 text-center text-muted small">No system announcements posted yet.</div>
<?php else: foreach ($myAnnouncements as $a): include __DIR__ . '/../partials/announcement_card.php'; endforeach; endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
<script>
function syncWho() {
  const who = document.getElementById('whoSelect').value;
  document.getElementById('subScopeWrap').style.display = (who === 'students') ? '' : 'none';
  if (who === 'lecturers') {
    document.getElementById('deptWrap').style.display = '';
    document.getElementById('sessionWrap').style.display = 'none';
    document.getElementById('yearWrap').style.display = 'none';
  } else if (who === 'students') {
    syncSubScope();
  } else {
    // everyone / staff / registrar / coordinator: no sub-filters
    document.getElementById('deptWrap').style.display = 'none';
    document.getElementById('sessionWrap').style.display = 'none';
    document.getElementById('yearWrap').style.display = 'none';
  }
}
function syncSubScope() {
  const sub = document.getElementById('subScope') ? document.getElementById('subScope').value : 'all';
  document.getElementById('deptWrap').style.display     = (sub === 'department') ? '' : 'none';
  document.getElementById('sessionWrap').style.display  = (sub === 'session')    ? '' : 'none';
  document.getElementById('yearWrap').style.display     = (sub === 'year')       ? '' : 'none';
}
syncWho();
</script>
