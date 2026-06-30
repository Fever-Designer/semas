<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Registrar']);

$pageTitle = 'Student Announcements';
$activeNav = 'announcements';
$db = Database::connection();
$me = Auth::user();

// Categories / priorities for the form
$categories = ['Academic','Examination','Event','Registration','Scholarship','Sports','General Notice','Emergency','Workshop','Career Opportunity'];
$priorities  = ['Low','Medium','High','Urgent'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (empty(trim($_POST['title'] ?? '')) || empty(trim($_POST['message'] ?? ''))) {
        flash('error', 'Title and message are required and cannot be empty.');
        redirect('/registrar/announcements.php');
    }

    $subScope = $_POST['sub_scope'] ?? 'all';
    $deptId   = (int) ($_POST['department_id'] ?? 0) ?: null;
    $sessionT = $_POST['session_type'] ?? '';
    $intake   = $_POST['intake'] ?? '';

    // Registrar can only send to Students — scope selection within students only
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
    } elseif ($subScope === 'intake' && $intake) {
        $audienceKey = 'All Students';
        $scopeLabel  = "Students / {$intake} Intake";
        $stmt = $db->prepare(
            "SELECT u.* FROM users u JOIN roles r ON r.role_id=u.role_id
             WHERE r.role_name='Student' AND u.status='Active' AND u.intake=:intake"
        );
        $stmt->execute(['intake' => $intake]);
        $recipients = $stmt->fetchAll();
    } else {
        $audienceKey = 'All Students';
        $scopeLabel  = 'All Active Students';
        $recipients  = AudienceResolver::resolve('All Students');
    }

    $result = Announcement::create([
        'title'           => trim($_POST['title'] ?? ''),
        'category'        => $_POST['category'] ?? 'General Notice',
        'priority'        => $_POST['priority'] ?? 'Medium',
        'message'         => trim($_POST['message'] ?? ''),
        'target_audience' => $audienceKey,
        'department_id'   => $deptId,
        'faculty_id'      => null,
        'event_id'        => null,
        'recipients'      => $recipients,
    ], $me, 'Registrar', $scopeLabel, (bool) ($_POST['send_sms'] ?? false));

    flash('success', "Announcement sent to {$result['recipients']} student(s).");
    redirect('/registrar/announcements.php');
}

// Recent announcements sent by this Registrar
$recentSent = $db->prepare(
    'SELECT a.*, COALESCE(a.recipients_count,0) AS rc FROM announcements a
     WHERE a.posted_by = :uid ORDER BY a.posted_at DESC LIMIT 20'
);
$recentSent->execute(['uid' => $me['user_id']]);
$recentAnnouncements = $recentSent->fetchAll();

$departments = $db->query('SELECT d.*, f.faculty_name FROM departments d JOIN faculties f ON f.faculty_id=d.faculty_id ORDER BY f.faculty_name, d.department_name')->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Send Announcement to Students</h4>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="semas-card p-4">
      <form method="POST" id="announcementForm">
        <?= csrf_field() ?>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Target Audience</label>
          <select name="sub_scope" id="subScope" class="form-select" onchange="toggleScope(this.value)">
            <option value="all">All Students</option>
            <option value="department">Specific Department</option>
            <option value="session">By Session Type</option>
            <option value="intake">By Intake</option>
          </select>
        </div>

        <div id="scopeDept" class="mb-3" style="display:none;">
          <label class="form-label small fw-semibold">Department</label>
          <select name="department_id" class="form-select">
            <option value="">— Select —</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= $d['department_id'] ?>"><?= e($d['department_name']) ?> (<?= e($d['department_code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="scopeSession" class="mb-3" style="display:none;">
          <label class="form-label small fw-semibold">Session Type</label>
          <select name="session_type" class="form-select">
            <option value="">— Select —</option>
            <option value="Day">Day</option>
            <option value="Evening">Evening</option>
            <option value="Weekend">Weekend</option>
          </select>
        </div>

        <div id="scopeIntake" class="mb-3" style="display:none;">
          <label class="form-label small fw-semibold">Intake</label>
          <select name="intake" class="form-select">
            <option value="">— Select —</option>
            <option value="JAN">January (JAN)</option>
            <option value="MAY">May (MAY)</option>
            <option value="SEPT">September (SEPT)</option>
          </select>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Category</label>
            <select name="category" class="form-select">
              <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Priority</label>
            <select name="priority" class="form-select">
              <?php foreach ($priorities as $p): ?><option value="<?= e($p) ?>" <?= $p === 'Medium' ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Title <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control" required maxlength="200">
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Message <span class="text-danger">*</span></label>
          <textarea name="message" class="form-control" rows="6" required maxlength="5000"></textarea>
        </div>

        <div class="form-check mb-3">
          <input type="checkbox" name="send_sms" value="1" class="form-check-input" id="sendSms">
          <label class="form-check-label small" for="sendSms">Also send via SMS (for students who opted in)</label>
        </div>

        <button type="submit" id="announcementSubmitBtn" class="btn btn-semas-gold w-100">
          <i class="bi bi-megaphone-fill me-1"></i> Send Announcement
        </button>
      </form>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="semas-card p-3">
      <h6 class="display-font mb-3">Recently Sent</h6>
      <?php if (!$recentAnnouncements): ?>
        <p class="text-muted small mb-0">No announcements sent yet.</p>
      <?php else: foreach ($recentAnnouncements as $a): ?>
        <div class="border-bottom py-2">
          <div class="fw-semibold small"><?= e($a['title']) ?></div>
          <div class="text-muted" style="font-size:0.75rem;">
            <?= e($a['target_audience']) ?> &middot; <?= (int) $a['rc'] ?> recipient(s) &middot; <?= e(date('d M Y H:i', strtotime($a['posted_at']))) ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<script>
function toggleScope(v) {
    document.getElementById('scopeDept').style.display    = v === 'department' ? '' : 'none';
    document.getElementById('scopeSession').style.display = v === 'session'    ? '' : 'none';
    document.getElementById('scopeIntake').style.display  = v === 'intake'     ? '' : 'none';
}
document.getElementById('announcementForm').addEventListener('submit', function() {
    var btn = document.getElementById('announcementSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending…';
});
</script>
<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
