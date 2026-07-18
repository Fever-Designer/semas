<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();

$pageTitle = 'Module Announcements';
$activeNav = 'modules';
$db = Database::connection();
Semester::enforceAcademicWrite($db);
$me = Auth::user();

$lecStmt = $db->prepare('SELECT * FROM lecturers WHERE user_id = :uid');
$lecStmt->execute(['uid' => $me['user_id']]);
$lecturer = $lecStmt->fetch();

$modules = $db->prepare(
    "SELECT m.*, (SELECT COUNT(*) FROM module_enrollments e WHERE e.module_id = m.module_id) AS student_count
     FROM modules m WHERE m.lecturer_id = :lec AND m.status = 'Ongoing' ORDER BY m.module_title"
);
$modules->execute(['lec' => $lecturer['lecturer_id'] ?? 0]);
$modules = $modules->fetchAll();

$selectedModuleId = (int) ($_GET['module_id'] ?? ($modules[0]['module_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (empty(trim($_POST['title'] ?? '')) || empty(trim($_POST['message'] ?? ''))) {
        flash('error', 'Title and message are required and cannot be empty.');
        redirect('/lecturer/announcements.php');
    }

    $moduleId = (int) $_POST['module_id'];
    $modCheck = $db->prepare('SELECT * FROM modules WHERE module_id = :id AND lecturer_id = :lec');
    $modCheck->execute(['id' => $moduleId, 'lec' => $lecturer['lecturer_id'] ?? 0]);
    $module = $modCheck->fetch();
    if (!$module) {
        flash('error', 'Module not found, or it is not assigned to you.');
        redirect('/lecturer/announcements.php');
    }

    $enrolledStmt = $db->prepare(
        "SELECT u.* FROM users u JOIN module_enrollments e ON e.user_id = u.user_id WHERE e.module_id = :mid AND u.status = 'Active'"
    );
    $enrolledStmt->execute(['mid' => $moduleId]);
    $recipients = $enrolledStmt->fetchAll();

    $result = Announcement::create([
        'title'           => $_POST['title'],
        'category'        => $_POST['category'] ?? 'General',
        'priority'        => $_POST['priority'] ?? 'Normal',
        'target_audience' => 'Module Students',
        'department_id'   => $module['department_id'],
        'message'         => $_POST['message'],
        'status'          => 'Published',
        'recipients'      => $recipients,
    ], $me, 'Lecturer', $module['module_title']);

    flash('success', "Announcement sent to {$result['recipients']} student(s) registered in \"{$module['module_title']}\".");
    redirect('/lecturer/announcements.php?module_id=' . $moduleId);
}

$myAnnouncements = $db->prepare("SELECT * FROM announcements WHERE posted_by = :uid ORDER BY posted_at DESC LIMIT 20");
$myAnnouncements->execute(['uid' => $me['user_id']]);
$myAnnouncements = $myAnnouncements->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Module Announcements</h4>

<?php if (!$modules): ?>
  <div class="semas-card p-4 text-center text-muted small">You have no modules assigned yet. Ask the Head Of Department to assign one to you.</div>
<?php else: ?>
  <div class="semas-card p-3 mb-4">
    <form method="post">
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-12">
          <label class="form-label small">Module</label>
          <select name="module_id" class="form-select" required>
            <?php foreach ($modules as $m): ?>
              <option value="<?= (int) $m['module_id'] ?>" <?= $selectedModuleId === (int) $m['module_id'] ? 'selected' : '' ?>>
                <?= e($m['module_title']) ?> (<?= (int) $m['student_count'] ?> registered)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
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
        <div class="col-md-6">
        </div>
      </div>
      <button class="btn btn-semas mt-3" onclick="this.disabled=true;this.innerHTML='<span class=\'spinner-border spinner-border-sm me-1\'></span> Sending…';this.form.submit()"><i class="bi bi-send me-1"></i> Send to Registered Students</button>
    </form>
  </div>
<?php endif; ?>

<div class="semas-card p-3 mb-3"><h6 class="display-font mb-0">Your Announcements</h6></div>
<?php if (!$myAnnouncements): ?>
  <div class="semas-card p-4 text-center text-muted small">You haven't posted any announcements yet.</div>
<?php else: foreach ($myAnnouncements as $a): include __DIR__ . '/../partials/announcement_card.php'; endforeach; endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
