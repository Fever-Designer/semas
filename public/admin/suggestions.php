<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal', 'Dean', 'HOD', 'Registrar', 'Coordinator']);

$pageTitle = 'Suggestion Box';
$activeNav = 'suggestions';
$db = Database::connection();
$user = Auth::user();
$isPrincipal = Auth::role() === 'Principal';

// One-time migration: add resolved_by / resolved_at columns if missing
try { $db->exec('ALTER TABLE suggestions ADD COLUMN resolved_by INT NULL'); } catch (PDOException $e) {}
try { $db->exec('ALTER TABLE suggestions ADD COLUMN resolved_at DATETIME NULL'); } catch (PDOException $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) $_POST['suggestion_id'];
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'reply':
            if (!$isPrincipal) { flash('error', 'Only the Principal can reply to suggestions.'); break; }
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

        case 'archive':
            if (!$isPrincipal) { flash('error', 'Only the Principal can archive suggestions.'); break; }
            Suggestion::setStatus($id, 'Archived');
            AuditLog::record(Auth::id(), 'SUGGESTION_ARCHIVED', 'suggestions', $id);
            flash('success', 'Archived.');
            break;

        case 'unarchive':
            if (!$isPrincipal) { flash('error', 'Only the Principal can unarchive suggestions.'); break; }
            Suggestion::setStatus($id, 'New');
            AuditLog::record(Auth::id(), 'SUGGESTION_UNARCHIVED', 'suggestions', $id);
            flash('success', 'Removed from archive.');
            break;
    }
    redirect('/admin/suggestions.php');
}

$scopeDept = Auth::role() === 'HOD' ? $user['department_id'] : null;
$suggestions = Suggestion::adminList($scopeDept);

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Suggestion Box</h4>

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

    <?php if ($isPrincipal): ?>
    <div class="d-flex gap-2 flex-wrap mt-2">
      <button class="btn btn-sm btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#reply-<?= (int) $s['suggestion_id'] ?>">Reply</button>

      <?php if ($s['status'] === 'Archived'): ?>
        <form method="post" class="d-inline">
          <?= csrf_field() ?><input type="hidden" name="suggestion_id" value="<?= (int) $s['suggestion_id'] ?>">
          <button class="btn btn-sm btn-outline-secondary" name="action" value="unarchive">Remove Archive</button>
        </form>
      <?php else: ?>
        <form method="post" class="d-inline">
          <?= csrf_field() ?><input type="hidden" name="suggestion_id" value="<?= (int) $s['suggestion_id'] ?>">
          <button class="btn btn-sm btn-outline-dark" name="action" value="archive">Archive</button>
        </form>
      <?php endif; ?>
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
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
