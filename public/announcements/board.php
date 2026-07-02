<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal', 'Dean', 'HOD', 'Lecturer', 'Student', 'Registrar', 'Coordinator']);
Announcement::purgeExpired();

$pageTitle = 'Announcement Board';
$activeNav = 'announcements';
$db = Database::connection();
$me = Auth::user();

if (Auth::role() === 'Student') {
    Announcement::backfillForNewStudent(
        (int) $me['user_id'],
        !empty($me['department_id']) ? (int) $me['department_id'] : null,
        $me['session_type'] ?? null,
        !empty($me['year_of_study']) ? (int) $me['year_of_study'] : null
    );
}

$search = trim($_GET['q'] ?? '');
$category = $_GET['category'] ?? '';
$priority = $_GET['priority'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Scoped: a viewer only ever sees announcements where they're a recorded
// recipient, OR ones they sent themselves. This is what stops, say, the
// Principal from seeing a lecturer's assignment announcement to one
// module's students, or a Dean from seeing student-only sends that never
// targeted them.
$where = [
    "a.status = 'Published'",
    "EXISTS (SELECT 1 FROM announcement_recipients ar WHERE ar.announcement_id = a.announcement_id AND ar.user_id = :me)",
];
$params = ['me' => $me['user_id']];
if ($search !== '') {
    $where[] = '(a.title LIKE :q_title OR a.message LIKE :q_message)';
    $params['q_title'] = "%$search%";
    $params['q_message'] = "%$search%";
}
if ($category !== '') {
    $where[] = 'a.category = :cat';
    $params['cat'] = $category;
}
if ($priority !== '') {
    $where[] = 'a.priority = :pri';
    $params['pri'] = $priority;
}
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM announcements a WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $db->prepare("SELECT a.* FROM announcements a WHERE $whereSql ORDER BY a.posted_at DESC LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue('lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue('off', $offset, PDO::PARAM_INT);
$stmt->execute();
$announcements = $stmt->fetchAll();

$categories = ['Academic', 'Examination', 'Event', 'Registration', 'Scholarship', 'Sports', 'General Notice', 'Emergency', 'Workshop', 'Career Opportunity'];
$totalPages = max(1, (int) ceil($total / $perPage));

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div><h4 class="display-font mb-1">Announcement Board</h4></div>
</div>

<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2">
    <div class="col-md-6"><input name="q" class="form-control form-control-sm" value="<?= e($search) ?>"></div>
    <div class="col-md-3">
      <select name="category" class="form-select form-select-sm">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?><option <?= $category === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="priority" class="form-select form-select-sm">
        <option value="">All Priorities</option>
        <?php foreach (['Low', 'Medium', 'High', 'Urgent'] as $p): ?><option <?= $priority === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-1"><button class="btn btn-sm btn-semas w-100"><i class="bi bi-search"></i></button></div>
  </form>
</div>

<?php if (!$announcements): ?>
  <div class="semas-card p-4 text-center text-muted small">No announcements addressed to you match your filters.</div>
<?php else: foreach ($announcements as $a): include __DIR__ . '/../partials/announcement_card.php'; endforeach; endif; ?>

<?php if ($totalPages > 1): ?>
  <nav class="d-flex justify-content-center mt-3">
    <ul class="pagination pagination-sm">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $p]))) ?>"><?= $p ?></a>
        </li>
      <?php endfor; ?>
    </ul>
  </nav>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
