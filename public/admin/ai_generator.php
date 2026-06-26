<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Administrator']);

$pageTitle = 'AI Notification Generator';
$activeNav = 'ai';
$db = Database::connection();
$generated = null;
$justSavedId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    csrf_verify();
    $generated = NotificationGenerator::generate([
        'title'           => $_POST['title'] ?? '',
        'category'        => $_POST['category'] ?? '',
        'priority'        => $_POST['priority'] ?? '',
        'target_audience' => $_POST['target_audience'] ?? '',
        'date'            => $_POST['event_date'] ?: null,
        'venue'           => $_POST['venue'] ?: null,
        'deadline'        => $_POST['deadline'] ?: null,
        'content'         => $_POST['content'] ?? '',
    ]);
    $justSavedId = NotificationGenerator::save($generated, $_POST['title'] ?? '', Auth::id());
    flash('success', 'Notification generated and saved as draft #' . $justSavedId . '. Review it below, then publish.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish') {
    csrf_verify();
    NotificationGenerator::publish((int) $_POST['notification_id'], (int) Auth::id());
    flash('success', 'Notification published as an announcement and queued for email delivery.');
    redirect('/admin/ai_generator.php');
}

$recent = $db->query('SELECT * FROM ai_notifications ORDER BY notification_id DESC LIMIT 10')->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Generate an Announcement &amp; Email</h4>
<p class="text-muted small mb-4">Fill in a short request; the generator returns database-ready content following the official SEMAS notification rules.</p>

<div class="semas-card p-3 mb-4">
  <h6 class="display-font mb-3">Request</h6>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="generate">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label small">Title</label><input name="title" class="form-control" required></div>
      <div class="col-md-2">
        <label class="form-label small">Category</label>
        <select name="category" class="form-select">
          <?php foreach (NotificationGenerator::CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small">Priority</label>
        <select name="priority" class="form-select">
          <?php foreach (NotificationGenerator::PRIORITIES as $p): ?><option><?= e($p) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small">Audience</label>
        <select name="target_audience" class="form-select">
          <?php foreach (NotificationGenerator::AUDIENCES as $a): ?><option><?= e($a) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><label class="form-label small">Event Date (optional)</label><input type="date" name="event_date" class="form-control"></div>
      <div class="col-md-4"><label class="form-label small">Venue (optional)</label><input name="venue" class="form-control"></div>
      <div class="col-md-4"><label class="form-label small">Deadline (optional)</label><input name="deadline" class="form-control" placeholder="e.g. 2026-07-09"></div>
      <div class="col-12"><label class="form-label small">Message Content</label><textarea name="content" class="form-control" rows="3" required></textarea></div>
    </div>
    <button class="btn btn-semas-gold mt-3"><i class="bi bi-robot me-1"></i> Generate</button>
  </form>
</div>

<?php if ($generated): ?>
<div class="semas-card p-3 mb-4">
  <h6 class="display-font mb-3">Generated JSON (database-ready)</h6>
  <pre class="code-preview"><?= e(json_encode($generated, JSON_PRETTY_PRINT)) ?></pre>
</div>
<div class="semas-card p-3 mb-4">
  <h6 class="display-font mb-3">Email Preview</h6>
  <div class="email-preview">
    <div class="email-meta">
      <div><strong>Subject:</strong> <?= e($generated['email_subject']) ?></div>
      <div><strong>Audience:</strong> <?= e($generated['target_audience']) ?></div>
    </div>
    <div class="email-body"><?= e($generated['email_body']) ?></div>
  </div>
  <form method="post" class="mt-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="publish">
    <input type="hidden" name="notification_id" value="<?= (int) $justSavedId ?>">
    <button class="btn btn-semas"><i class="bi bi-send me-1"></i> Publish Now (creates announcement + sends emails)</button>
  </form>
</div>
<?php endif; ?>

<div class="semas-card p-3">
  <h6 class="display-font mb-3">Recent Generated Notifications</h6>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Title</th><th>Category</th><th>Priority</th><th>Audience</th><th>Status</th><th>Generated</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $n): ?>
          <tr>
            <td class="fw-semibold"><?= e($n['title']) ?></td>
            <td><?= e($n['category']) ?></td>
            <td><span class="badge badge-urgent"><?= e($n['priority']) ?></span></td>
            <td><?= e($n['target_audience']) ?></td>
            <td><?= $n['published_announcement_id'] ? '<span class="badge badge-completed">Published</span>' : '<span class="badge badge-upcoming">Draft</span>' ?></td>
            <td><?= e($n['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
