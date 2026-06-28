<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal', 'Dean', 'HOD', 'Registrar', 'Coordinator']);

$pageTitle = 'Suggestion Box';
$activeNav = 'suggestions';
$db = Database::connection();
$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) $_POST['suggestion_id'];
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'reply':
            Suggestion::reply($id, trim($_POST['reply'] ?? ''), Auth::id());
            AuditLog::record(Auth::id(), 'SUGGESTION_REPLIED', 'suggestions', $id);
            // The submitter's identity is never read by this admin page, but the
            // system itself still knows it (suggestions.submitted_by_user_id) and
            // can deliver the reply to the right inbox without exposing who it was.
            $submitterStmt = $db->prepare('SELECT submitted_by_user_id FROM suggestions WHERE suggestion_id = :id');
            $submitterStmt->execute(['id' => $id]);
            $submitterId = $submitterStmt->fetchColumn();
            if ($submitterId) {
                NotificationCenter::notify((int) $submitterId, 'Staff replied to your suggestion', 'Your anonymous submission received a reply. Check the Suggestion Box for details.', 'System');
            }
            flash('success', 'Reply saved.');
            break;
        case 'resolve':
            Suggestion::setStatus($id, 'Resolved');
            AuditLog::record(Auth::id(), 'SUGGESTION_RESOLVED', 'suggestions', $id);
            flash('success', 'Marked as resolved.');
            break;
        case 'archive':
            Suggestion::setStatus($id, 'Archived');
            AuditLog::record(Auth::id(), 'SUGGESTION_ARCHIVED', 'suggestions', $id);
            flash('success', 'Archived.');
            break;
        case 'delete':
            Suggestion::delete($id);
            AuditLog::record(Auth::id(), 'SUGGESTION_DELETED', 'suggestions', $id);
            flash('success', 'Deleted.');
            break;
    }
    redirect('/admin/suggestions.php');
}

// HOD only sees suggestions from their own department; Principal and Dean see all
// (Dean scoping to faculty would require joining departments — kept simple here since
// suggestions are meant to surface broadly; tighten to faculty scope if you prefer).
$scopeDept = Auth::role() === 'HOD' ? $user['department_id'] : null;
$suggestions = Suggestion::adminList($scopeDept);

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Suggestion Box</h4>
<p class="text-muted small mb-4">
  Messages are shown anonymously by design — category, department, and content only. No name, email, or
  account is ever displayed here, even though the system can trace abuse internally if legally required.
</p>

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
      <div class="text-muted" style="font-size:0.72rem;"><?= e($s['department_name'] ?? 'University-wide')  ?> &middot; <?= e($s['created_at']) ?></div>
    </div>
    <p class="mb-2"><?= nl2br(e($s['message'])) ?></p>

    <?php if ($s['admin_reply']): ?>
      <div class="border-start ps-3 mb-2" style="border-color:var(--semas-gold) !important;border-left-width:3px;">
        <div class="text-muted small fw-semibold">Staff reply (<?= e($s['replied_at']) ?>)</div>
        <div class="small"><?= nl2br(e($s['admin_reply'])) ?></div>
      </div>
    <?php endif; ?>

    <div class="d-flex gap-2 flex-wrap mt-2">
      <button class="btn btn-sm btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#reply-<?= (int) $s['suggestion_id'] ?>">Reply</button>
      <form method="post" class="d-inline">
        <?= csrf_field() ?><input type="hidden" name="suggestion_id" value="<?= (int) $s['suggestion_id'] ?>">
        <button class="btn btn-sm btn-outline-dark" name="action" value="resolve">Mark Resolved</button>
      </form>
      <form method="post" class="d-inline">
        <?= csrf_field() ?><input type="hidden" name="suggestion_id" value="<?= (int) $s['suggestion_id'] ?>">
        <button class="btn btn-sm btn-outline-dark" name="action" value="archive">Archive</button>
      </form>
      <form method="post" class="d-inline" onsubmit="return confirm('Delete this submission permanently?');">
        <?= csrf_field() ?><input type="hidden" name="suggestion_id" value="<?= (int) $s['suggestion_id'] ?>">
        <button class="btn btn-sm btn-outline-danger" name="action" value="delete">Delete</button>
      </form>
    </div>

    <div class="collapse mt-2" id="reply-<?= (int) $s['suggestion_id'] ?>">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="suggestion_id" value="<?= (int) $s['suggestion_id'] ?>">
        <input type="hidden" name="action" value="reply">
        <textarea name="reply" class="form-control form-control-sm mb-2" rows="2" placeholder="Write a reply..."><?= e($s['admin_reply'] ?? '') ?></textarea>
        <button class="btn btn-sm btn-semas">Save Reply</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
