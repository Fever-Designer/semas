<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal']);

$pageTitle = 'Audit Log';
$activeNav = 'auditlog';
$db = Database::connection();

$search = trim($_GET['q'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(u.full_name LIKE :q OR al.details LIKE :q OR al.entity_type LIKE :q)';
    $params['q'] = "%$search%";
}
if ($actionFilter !== '') {
    $where[] = 'al.action = :action';
    $params['action'] = $actionFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON u.user_id = al.user_id $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT al.*, u.full_name, r.role_name FROM audit_logs al
     LEFT JOIN users u ON u.user_id = al.user_id
     LEFT JOIN roles r ON r.role_id = u.role_id
     $whereSql ORDER BY al.created_at DESC LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue('lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue('off', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$actions = $db->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$totalPages = max(1, (int) ceil($total / $perPage));

function audit_badge(string $action): string
{
    if (strpos($action, 'DEACTIVATE') !== false || strpos($action, 'DELETE') !== false) return 'cancelled';
    if (strpos($action, 'ACTIVATE') !== false || strpos($action, 'CREATE') !== false) return 'completed';
    return 'upcoming';
}

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Audit Log</h4>

<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2">
    <div class="col-md-6"><input name="q" class="form-control form-control-sm" value="<?= e($search) ?>"></div>
    <div class="col-md-4">
      <select name="action" class="form-select form-select-sm">
        <option value="">All Actions</option>
        <?php foreach ($actions as $a): ?><option <?= $actionFilter === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-sm btn-semas w-100"><i class="bi bi-search"></i> Filter</button></div>
  </form>
</div>

<div class="semas-card p-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>When</th><th>User</th><th>Role</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="text-nowrap small"><?= e($l['created_at']) ?></td>
            <td class="small"><?= e($l['full_name'] ?? 'System') ?></td>
            <td class="small"><?= e($l['role_name'] ?? '/') ?></td>
            <td><span class="badge badge-<?= audit_badge($l['action']) ?>"><?= e($l['action']) ?></span></td>
            <td class="small"><?= e($l['entity_type'] ?? '') ?> <?= $l['entity_id'] ? '#' . (int) $l['entity_id'] : '' ?></td>
            <td class="small text-muted"><?= e($l['details'] ?? '') ?></td>
            <td class="small text-muted"><?= e($l['ip_address'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="7" class="text-muted small text-center py-3">No audit entries match your filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
  <nav class="d-flex justify-content-center mt-3">
    <ul class="pagination pagination-sm">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $p]))) ?>"><?= $p ?></a></li>
      <?php endfor; ?>
    </ul>
  </nav>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
