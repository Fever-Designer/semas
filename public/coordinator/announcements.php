<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Coordinator']);

$pageTitle = 'Weekend Announcements';
$activeNav = 'announcements';
$db = Database::connection();
$me = Auth::user();

$categories = ['Academic','Examination','Event','Registration','General Notice','Emergency','Workshop'];
$priorities  = ['Low','Medium','High','Urgent'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (empty(trim($_POST['title'] ?? '')) || empty(trim($_POST['message'] ?? ''))) {
        flash('error', 'Title and message are required and cannot be empty.');
        redirect('/coordinator/announcements.php');
    }

    $who = $_POST['who'] ?? 'weekend_students';

    if ($who === 'weekend_students') {
        $audienceKey = 'Weekend Students';
        $scopeLabel  = 'Weekend Students';
        $recipients  = AudienceResolver::resolve('Weekend Students');
    } elseif ($who === 'weekend_lecturers') {
        $audienceKey = 'Department Lecturers';
        $scopeLabel  = 'Weekend Session Lecturers';
        // Lecturers assigned to Weekend modules
        $stmt = $db->query(
            "SELECT DISTINCT u.* FROM users u
             JOIN lecturers l ON l.user_id=u.user_id
             JOIN modules m ON m.lecturer_id=l.lecturer_id
             WHERE m.session_type='Weekend' AND m.status='Ongoing' AND u.status='Active'"
        );
        $recipients = $stmt->fetchAll();
    } else {
        $audienceKey = 'Weekend Students';
        $scopeLabel  = 'Weekend Students and Lecturers';
        $stStmt = $db->query("SELECT u.* FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student' AND u.session_type='Weekend' AND u.status='Active'");
        $lcStmt = $db->query("SELECT DISTINCT u.* FROM users u JOIN lecturers l ON l.user_id=u.user_id JOIN modules m ON m.lecturer_id=l.lecturer_id WHERE m.session_type='Weekend' AND m.status='Ongoing' AND u.status='Active'");
        $recipients = array_merge($stStmt->fetchAll(), $lcStmt->fetchAll());
    }

    Announcement::create([
        'title'           => trim($_POST['title'] ?? ''),
        'category'        => $_POST['category'] ?? 'General Notice',
        'priority'        => $_POST['priority'] ?? 'Medium',
        'message'         => trim($_POST['message'] ?? ''),
        'target_audience' => $audienceKey,
        'department_id'   => null,
        'faculty_id'      => null,
        'event_id'        => null,
        'recipients'      => $recipients,
    ], $me, 'Coordinator', $scopeLabel, false);

    flash('success', 'Announcement sent to ' . count($recipients) . ' recipient(s).');
    redirect('/coordinator/announcements.php');
}

$sent = $db->prepare('SELECT a.*, COALESCE(a.recipients_count,0) AS rc FROM announcements a WHERE a.posted_by=:uid ORDER BY a.posted_at DESC LIMIT 20');
$sent->execute(['uid' => $me['user_id']]);
$recentAnnouncements = $sent->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Weekend Announcements</h4>
<p class="text-muted small mb-4">Send announcements to Weekend-session students and lecturers.</p>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="semas-card p-4">
      <form method="POST" id="announcementForm">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Send To</label>
          <select name="who" class="form-select">
            <option value="weekend_students">Weekend Students Only</option>
            <option value="weekend_lecturers">Weekend Lecturers Only</option>
            <option value="both">Weekend Students &amp; Lecturers</option>
          </select>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Category</label>
            <select name="category" class="form-select">
              <?php foreach ($categories as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Priority</label>
            <select name="priority" class="form-select">
              <?php foreach ($priorities as $p): ?><option <?= $p === 'Medium' ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Title <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control" required maxlength="200">
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Message <span class="text-danger">*</span></label>
          <textarea name="message" class="form-control" rows="6" required></textarea>
        </div>
        <button type="submit" id="submitBtn" class="btn btn-semas-gold w-100">
          <i class="bi bi-megaphone-fill me-1"></i> Send Announcement
        </button>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="semas-card p-3">
      <h6 class="display-font mb-3">Recently Sent</h6>
      <?php if (!$recentAnnouncements): ?><p class="text-muted small mb-0">No announcements sent yet.</p>
      <?php else: foreach ($recentAnnouncements as $a): ?>
        <div class="border-bottom py-2">
          <div class="fw-semibold small"><?= e($a['title']) ?></div>
          <div class="text-muted" style="font-size:0.75rem;"><?= (int) $a['rc'] ?> recipients &middot; <?= e(date('d M Y', strtotime($a['posted_at']))) ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<script>
document.getElementById('announcementForm').addEventListener('submit', function() {
    var b = document.getElementById('submitBtn');
    b.disabled = true;
    b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending…';
});
</script>
<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
