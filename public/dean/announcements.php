<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Dean']);

$pageTitle = 'Announcements';
$activeNav = 'announcements';
$db = Database::connection();
$me = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (empty(trim($_POST['title'] ?? '')) || empty(trim($_POST['message'] ?? ''))) {
        flash('error', 'Title and message are required and cannot be empty.');
        redirect('/dean/announcements.php');
    }

    $who      = $_POST['who']       ?? 'students';
    $subScope = $_POST['sub_scope'] ?? 'all';
    $sessionT = $_POST['session_type']  ?? '';
    $yearVal  = (int) ($_POST['year_of_study'] ?? 0);

    $recipients  = [];
    $audienceKey = 'All Students';
    $scopeLabel  = 'All Students (university-wide)';

    if ($who === 'everyone') {
        $audienceKey = 'University Community';
        $scopeLabel  = 'University-wide / All active users';
        $recipients  = AudienceResolver::resolve('University Community');

    } elseif ($who === 'lecturers') {
        $audienceKey = 'Department Lecturers';
        $scopeLabel  = 'All Lecturers (university-wide)';
        $stmt = $db->query(
            "SELECT u.* FROM users u JOIN lecturers l ON l.user_id = u.user_id WHERE u.status = 'Active'"
        );
        $recipients = $stmt->fetchAll();

    } elseif ($who === 'students') {
        if ($subScope === 'session' && $sessionT) {
            $audienceKey = $sessionT . ' Students';
            $scopeLabel  = $sessionT . ' Session / All Students';
            $recipients  = AudienceResolver::resolve($sessionT . ' Students');
        } elseif ($subScope === 'first_year') {
            $audienceKey = 'First Year Students';
            $scopeLabel  = 'First Year Students (university-wide)';
            $recipients  = AudienceResolver::resolve('First Year Students');
        } elseif ($subScope === 'final_year') {
            $audienceKey = 'Final Year Students';
            $scopeLabel  = 'Final Year Students (university-wide)';
            $recipients  = AudienceResolver::resolve('Final Year Students');
        } elseif ($subScope === 'year' && $yearVal) {
            $audienceKey = 'All Students';
            $scopeLabel  = 'Year ' . $yearVal . ' Students (university-wide)';
            $stmt = $db->prepare(
                "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                 WHERE r.role_name = 'Student' AND u.status = 'Active' AND u.year_of_study = :year"
            );
            $stmt->execute(['year' => $yearVal]);
            $recipients = $stmt->fetchAll();
        } else {
            $audienceKey = 'All Students';
            $scopeLabel  = 'All Students (university-wide)';
            $recipients  = AudienceResolver::resolve('All Students');
        }
    }

    $isDraft = ($_POST['save_as'] ?? '') === 'draft';

    $result = Announcement::create([
        'title'           => $_POST['title'],
        'category'        => $_POST['category'],
        'priority'        => $_POST['priority'],
        'target_audience' => $audienceKey,
        'message'         => $_POST['message'],
        'status'          => $isDraft ? 'Draft' : 'Published',
        'recipients'      => $recipients,
    ], $me, 'Dean', $scopeLabel, isset($_POST['send_sms']));

    if ($isDraft) {
        flash('success', 'Announcement saved as a draft (not sent yet).');
    } else {
        flash('success', "Announcement sent to {$result['recipients']} recipient(s). Scope: $scopeLabel.");
    }
    redirect('/dean/announcements.php');
}

$myAnnouncements = $db->prepare("SELECT * FROM announcements WHERE posted_by = :uid ORDER BY posted_at DESC LIMIT 20");
$myAnnouncements->execute(['uid' => $me['user_id']]);
$myAnnouncements = $myAnnouncements->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Announcements</h4>

<div class="semas-card p-3 mb-4">
  <form method="post">
    <?= csrf_field() ?>
    <div class="row g-3">

      <div class="col-md-7"><label class="form-label small">Title</label><input name="title" class="form-control" required></div>
      <div class="col-md-2">
        <label class="form-label small">Category</label>
        <select name="category" class="form-select">
          <?php foreach (NotificationGenerator::CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small">Priority</label>
        <select name="priority" class="form-select">
          <?php foreach (NotificationGenerator::PRIORITIES as $p): ?><option><?= e($p) ?></option><?php endforeach; ?>
        </select>
      </div>

      <div class="col-12"><label class="form-label small">Message</label><textarea name="message" class="form-control" rows="3" required></textarea></div>

      <div class="col-12"><hr class="my-0"><label class="form-label small fw-semibold mt-1">Send To</label></div>

      <!-- Who -->
      <div class="col-md-4">
        <label class="form-label small text-muted">Recipient group</label>
        <select name="who" id="whoSelect" class="form-select" onchange="syncWho()">
          <option value="students">Students</option>
          <option value="lecturers">All Lecturers</option>
          <option value="everyone">Everyone (students + lecturers + staff)</option>
        </select>
      </div>

      <!-- Students sub-scope -->
      <div class="col-md-4" id="subScopeWrap">
        <label class="form-label small text-muted">Filter students by</label>
        <select name="sub_scope" id="subScope" class="form-select" onchange="syncSubScope()">
          <option value="all">All students</option>
          <option value="session">Session type</option>
          <option value="first_year">First year students</option>
          <option value="final_year">Final year students</option>
          <option value="year">Specific year of study</option>
        </select>
      </div>

      <!-- Session type picker -->
      <div class="col-md-4" id="sessionWrap" style="display:none;">
        <label class="form-label small text-muted">Session</label>
        <select name="session_type" class="form-select">
          <option value="Day">Day</option>
          <option value="Evening">Evening</option>
          <option value="Weekend">Weekend</option>
        </select>
      </div>

      <!-- Year of study picker -->
      <div class="col-md-4" id="yearWrap" style="display:none;">
        <label class="form-label small text-muted">Year of study</label>
        <select name="year_of_study" class="form-select">
          <?php for ($y = 1; $y <= 6; $y++): ?>
            <option value="<?= $y ?>">Year <?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>

    </div>

    <div class="mt-3 d-flex align-items-center gap-3 flex-wrap">
      <button class="btn btn-semas" name="save_as" value="publish" onclick="this.disabled=true;this.innerHTML='<span class=\'spinner-border spinner-border-sm me-1\'></span> Sending…';this.form.submit()"><i class="bi bi-send me-1"></i> Publish &amp; Notify</button>
      <button class="btn btn-outline-dark" name="save_as" value="draft">Save as Draft</button>
      <div class="form-check mb-0">
        <input type="checkbox" name="send_sms" id="send_sms" class="form-check-input" value="1">
        <label class="form-check-label small" for="send_sms">Also send via SMS</label>
      </div>
    </div>
  </form>
</div>

<div class="semas-card p-3 mb-3"><h6 class="display-font mb-0">Your Announcements</h6></div>
<?php if (!$myAnnouncements): ?>
  <div class="semas-card p-4 text-center text-muted small">You haven't posted any announcements yet.</div>
<?php else: foreach ($myAnnouncements as $a): ?>
  <?php if ($a['status'] === 'Draft'): ?>
    <div class="semas-card p-3 mb-3">
      <span class="badge bg-secondary mb-2">Draft</span>
      <h6 class="display-font"><?= e($a['title']) ?></h6>
      <p class="text-muted small mb-0"><?= e(mb_substr($a['message'], 0, 140)) ?>&hellip;</p>
    </div>
  <?php else: include __DIR__ . '/../partials/announcement_card.php'; endif; ?>
<?php endforeach; endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
<script>
function syncWho() {
  const who = document.getElementById('whoSelect').value;
  const showStudentSub = (who === 'students');
  document.getElementById('subScopeWrap').style.display = showStudentSub ? '' : 'none';
  if (!showStudentSub) {
    document.getElementById('sessionWrap').style.display = 'none';
    document.getElementById('yearWrap').style.display    = 'none';
  } else {
    syncSubScope();
  }
}
function syncSubScope() {
  const sub = document.getElementById('subScope').value;
  document.getElementById('sessionWrap').style.display = (sub === 'session') ? '' : 'none';
  document.getElementById('yearWrap').style.display    = (sub === 'year')    ? '' : 'none';
}
syncWho();
</script>
