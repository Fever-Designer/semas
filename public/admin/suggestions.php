<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal', 'Dean', 'HOD', 'Registrar', 'Coordinator']);

$pageTitle = 'Suggestion Box';
$activeNav = 'suggestions';
$db = Database::connection();
$user = Auth::user();
$viewerRole = Auth::role();
$isPrincipal = $viewerRole === 'Principal';
$canReply = in_array($viewerRole, ['Principal', 'Dean', 'HOD', 'Registrar', 'Coordinator'], true);

// One-time migration: add resolved_by / resolved_at columns if missing
try { $db->exec('ALTER TABLE suggestions ADD COLUMN resolved_by INT NULL'); } catch (PDOException $e) {}
try { $db->exec('ALTER TABLE suggestions ADD COLUMN resolved_at DATETIME NULL'); } catch (PDOException $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'submit') {
        $category = $_POST['category'] ?? '';
        $message = trim($_POST['message'] ?? '');
        if (in_array($category, Suggestion::CATEGORIES, true) && $message !== '') {
            Suggestion::submit($category, $message, null, Auth::id());
            AuditLog::record(Auth::id(), 'STAFF_SUGGESTION_SUBMITTED', 'suggestions', null, "category=$category");
            flash('success', 'Your suggestion has been sent to the Principal.');
        } else {
            flash('error', 'Choose a category and write a message.');
        }
        redirect('/admin/suggestions.php');
    }

    $id = (int) $_POST['suggestion_id'];
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'reply':
            if (!$canReply) { flash('error', 'Your role cannot reply to suggestions.'); break; }
            Suggestion::reply($id, trim($_POST['reply'] ?? ''), Auth::id());
            AuditLog::record(Auth::id(), 'SUGGESTION_REPLIED', 'suggestions', $id);
            $submitterStmt = $db->prepare('SELECT submitted_by_user_id FROM suggestions WHERE suggestion_id = :id');
            $submitterStmt->execute(['id' => $id]);
            $submitterId = $submitterStmt->fetchColumn();
            if ($submitterId) {
                NotificationCenter::notify((int) $submitterId, 'Staff replied to your suggestion', 'Your anonymous submission received a reply. Check the Suggestion Box for details.', 'System');
            }
            flash('success', 'Reply saved.');
            break;

    }
    $redirectTab = ($_POST['tab'] ?? '') === 'staff' ? '?tab=staff' : '';
    redirect('/admin/suggestions.php' . $redirectTab);
}

$scopeDept = Auth::role() === 'HOD' ? $user['department_id'] : null;
$suggestions = Suggestion::adminList($scopeDept, $viewerRole);
$mySuggestions = !$isPrincipal ? Suggestion::mySubmissions((int) $user['user_id']) : [];

// Principal sees both Student and Staff submissions mixed together / split them
// into two tabs so it's clear who each suggestion came from. Other roles only
// ever see Student submissions (adminList() already filters those out for them).
$tab = ($_GET['tab'] ?? 'student') === 'staff' ? 'staff' : 'student';
if ($isPrincipal) {
    $studentSuggestions = array_values(array_filter($suggestions, fn($s) => $s['submitter_type'] === 'Student'));
    $staffSuggestions   = array_values(array_filter($suggestions, fn($s) => $s['submitter_type'] === 'Staff'));
    $suggestions = $tab === 'staff' ? $staffSuggestions : $studentSuggestions;
}

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Suggestion Box</h4>

<?php if (!$isPrincipal): ?>
<div class="semas-card p-3 mb-3">
  <form method="post" class="row g-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="submit">
    <div class="col-md-3">
      <select name="category" class="form-select form-select-sm" required>
        <?php foreach (Suggestion::CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-7">
      <textarea name="message" class="form-control form-control-sm" rows="1" required></textarea>
    </div>
    <div class="col-md-2">
      <button class="btn btn-sm btn-semas-gold w-100"><i class="bi bi-send me-1"></i>Send</button>
    </div>
  </form>
</div>

<?php if ($mySuggestions): ?>
<div class="semas-card p-3 mb-3">
  <h6 class="display-font mb-3">My Suggestions to the Principal</h6>
  <?php foreach ($mySuggestions as $ms): ?>
    <div class="border-bottom py-2">
      <div class="d-flex justify-content-between align-items-start">
        <span class="badge badge-upcoming"><?= e($ms['category']) ?></span>
        <span class="badge badge-<?= $ms['status'] === 'Resolved' ? 'completed' : ($ms['status'] === 'Archived' ? 'cancelled' : 'urgent') ?>"><?= e($ms['status']) ?></span>
      </div>
      <div class="small mt-1"><?= nl2br(e($ms['message'])) ?></div>
      <?php if ($ms['admin_reply']): ?>
        <div class="small mt-2 ps-2" style="border-left:3px solid var(--semas-gold);">
          <strong>Reply<?= $ms['replied_by_name'] ? ' / ' . e($ms['replied_by_name']) . ' (' . e($ms['replied_by_role'] ?? '') . ')' : '' ?>:</strong>
          <?= nl2br(e($ms['admin_reply'])) ?>
        </div>
      <?php endif; ?>
      <?php if ($ms['status'] === 'Resolved'): ?>
        <div class="text-muted small mt-1"><i class="bi bi-check-circle-fill text-success me-1"></i>Resolved<?= $ms['resolved_at'] ? ' · ' . e($ms['resolved_at']) : '' ?></div>
      <?php endif; ?>
      <div class="text-muted" style="font-size:0.68rem;"><?= e($ms['created_at']) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($isPrincipal): ?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'student' ? 'active' : '' ?>" href="?tab=student">Students (<?= count($studentSuggestions) ?>)</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'staff' ? 'active' : '' ?>" href="?tab=staff">Staff (<?= count($staffSuggestions) ?>)</a></li>
</ul>
<?php endif; ?>

<?php if (!$suggestions): ?>
  <div class="semas-card p-4 text-center text-muted small">No suggestions submitted yet.</div>
<?php endif; ?>

<?php foreach ($suggestions as $s): ?>
  <div class="semas-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-start mb-2">
      <div>
        <span class="badge badge-upcoming me-2"><?= e($s['category']) ?></span>
        <span class="badge badge-<?= $s['status'] === 'Resolved' ? 'completed' : ($s['status'] === 'Archived' ? 'cancelled' : 'urgent') ?>"><?= e($s['status']) ?></span>
      </div>
      <div class="text-muted" style="font-size:0.72rem;"><?= e($s['department_name'] ?? 'University-wide') ?> &middot; <?= e($s['created_at']) ?></div>
    </div>
    <p class="mb-2"><?= nl2br(e($s['message'])) ?></p>

    <?php if ($s['admin_reply']): ?>
      <div class="border-start ps-3 mb-2" style="border-color:var(--semas-gold) !important;border-left-width:3px;">
        <div class="text-muted small fw-semibold">
          Staff reply
          <?php if ($s['replied_by_name']): ?>
            / <strong><?= e($s['replied_by_name']) ?></strong> <span class="text-muted">(<?= e($s['replied_by_role'] ?? '') ?>)</span>
          <?php endif; ?>
          <?php if ($s['replied_at']): ?>
            &middot; <?= e($s['replied_at']) ?>
          <?php endif; ?>
        </div>
        <div class="small"><?= nl2br(e($s['admin_reply'])) ?></div>
      </div>
    <?php endif; ?>

    <?php if ($s['status'] === 'Resolved' && $s['resolved_by_name']): ?>
      <div class="text-muted small mb-2">
        <i class="bi bi-check-circle-fill text-success me-1"></i>
        Resolved by <strong><?= e($s['resolved_by_name']) ?></strong>
        <span class="text-muted">(<?= e($s['resolved_by_role'] ?? '') ?>)</span>
        <?php if ($s['resolved_at']): ?>&middot; <?= e($s['resolved_at']) ?><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($canReply && !$s['admin_reply']): ?>
    <div class="d-flex gap-2 flex-wrap mt-2">
      <button class="btn btn-sm btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#reply-<?= (int) $s['suggestion_id'] ?>">Reply</button>

    </div>

    <div class="collapse mt-2" id="reply-<?= (int) $s['suggestion_id'] ?>">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="suggestion_id" value="<?= (int) $s['suggestion_id'] ?>">
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <textarea name="reply" class="form-control form-control-sm mb-2" rows="2"></textarea>
        <button class="btn btn-sm btn-semas">Save Reply</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
