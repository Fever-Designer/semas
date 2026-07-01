<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
if (!Auth::check()) {
    redirect('/auth/login.php');
}

$pageTitle = 'Suggestion Box';
$activeNav = 'suggestions';
$db = Database::connection();
$user = Auth::user();
$isStudent = Auth::role() === 'Student';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $category = $_POST['category'] ?? '';
    $message = trim($_POST['message'] ?? '');

    if (in_array($category, Suggestion::CATEGORIES, true) && $message !== '') {
        $departmentId = $isStudent ? ($user['department_id'] ?? null) : null;
        Suggestion::submit($category, $message, $departmentId ? (int) $departmentId : null, $user['user_id']);
        AuditLog::record($user['user_id'], 'SUGGESTION_SUBMITTED', 'suggestions', null, "category=$category");
        flash('success', $isStudent ? 'Your submission has been sent.' : 'Your suggestion has been sent to the Principal.');
        redirect('/student/suggestions.php');
    } else {
        flash('error', 'Please choose a category and write a message.');
    }
}

$myStuff = $db->prepare(
    'SELECT suggestion_id, category, message, status, admin_reply, replied_at, created_at
     FROM suggestions WHERE submitted_by_user_id = :uid ORDER BY created_at DESC'
);
$myStuff->execute(['uid' => $user['user_id']]);
$mySubmissions = $myStuff->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Suggestion Box</h4>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="semas-card p-3">
      <h6 class="display-font mb-3">New Submission</h6>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label small">Category</label>
          <select name="category" class="form-select" required>
            <?php foreach (Suggestion::CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label small">Message</label>
          <textarea name="message" class="form-control" rows="5" required></textarea>
        </div>
        <button class="btn btn-semas-gold"><i class="bi bi-send me-1"></i> Submit</button>
      </form>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="semas-card p-3">
      <h6 class="display-font mb-3">My Past Submissions</h6>
      <?php if (!$mySubmissions): ?>
        <p class="text-muted small">You haven't submitted anything yet.</p>
      <?php else: foreach ($mySubmissions as $s): ?>
        <div class="border-bottom py-2">
          <div class="d-flex justify-content-between">
            <span class="badge badge-upcoming"><?= e($s['category']) ?></span>
            <span class="badge badge-<?= $s['status'] === 'Resolved' ? 'completed' : ($s['status'] === 'Archived' ? 'cancelled' : 'urgent') ?>"><?= e($s['status']) ?></span>
          </div>
          <div class="small mt-1"><?= e(mb_substr($s['message'], 0, 100)) ?></div>
          <?php if ($s['admin_reply']): ?>
            <div class="small mt-1 ps-2" style="border-left:3px solid var(--semas-gold);"><strong>Reply:</strong> <?= e($s['admin_reply']) ?></div>
          <?php endif; ?>
          <div class="text-muted" style="font-size:0.68rem;"><?= e($s['created_at']) ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
