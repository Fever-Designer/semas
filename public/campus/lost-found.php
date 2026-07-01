<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal', 'Dean', 'HOD', 'Lecturer', 'Student', 'Registrar', 'Coordinator']);

$pageTitle = 'Lost & Found';
$activeNav = 'lostfound';
$db = Database::connection();
$me = Auth::user();
$role = Auth::role();
$isAdmin = $role === 'Principal'; // view-only / stats, per design — Admin never reports, claims, or approves
$isDean = $role === 'Dean';
$isRegistrar = $role === 'Registrar'; // can report items but cannot claim
$isCoordinator = $role === 'Coordinator'; // same as Registrar — report only

$categories = ['Electronics', 'Documents/ID', 'Books/Stationery', 'Clothing/Accessories', 'Keys', 'Bag', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAdmin) {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $photoPath = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
            finfo_close($finfo);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (isset($allowed[$mime]) && $_FILES['photo']['size'] <= 2 * 1024 * 1024) {
                $filename = 'lf' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                $dest = __DIR__ . '/../uploads/profile_photos/' . $filename;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                    $photoPath = 'uploads/profile_photos/' . $filename;
                }
            }
        }

        $db->prepare(
            'INSERT INTO lost_found_items (item_type, title, description, category, location, photo_path, contact_info, reported_by)
             VALUES (:type, :title, :desc, :cat, :loc, :photo, :contact, :uid)'
        )->execute([
            'type' => $_POST['item_type'], 'title' => trim($_POST['title']), 'desc' => trim($_POST['description'] ?? '') ?: null,
            'cat' => $_POST['category'], 'loc' => trim($_POST['location'] ?? '') ?: null, 'photo' => $photoPath,
            'contact' => trim($_POST['contact_info'] ?? '') ?: $me['email'], 'uid' => $me['user_id'],
        ]);
        $itemId = (int) $db->lastInsertId();
        AuditLog::record(Auth::id(), 'LOST_FOUND_CREATE', 'lost_found_items', $itemId);
        flash('success', 'Posted to the Lost & Found board.');
    } elseif ($action === 'claim') {
        if ($role !== 'Student') {
            flash('error', 'Only students can submit ownership claims.');
            redirect('/campus/lost-found.php');
        }
        $itemId = (int) $_POST['item_id'];
        try {
            $db->prepare('INSERT INTO lost_found_claims (item_id, claimant_id, claim_message) VALUES (:item, :uid, :msg)')
               ->execute(['item' => $itemId, 'uid' => $me['user_id'], 'msg' => trim($_POST['claim_message'] ?? '')]);
            AuditLog::record(Auth::id(), 'LOST_FOUND_CLAIM_SUBMIT', 'lost_found_items', $itemId);
            flash('success', 'Your claim was submitted. The Dean will review it.');
        } catch (PDOException $e) {
            flash('error', 'You have already submitted a claim for this item.');
        }
    } elseif ($action === 'delete') {
        $itemId = (int) $_POST['item_id'];
        $stmt = $db->prepare('SELECT reported_by FROM lost_found_items WHERE item_id = :id');
        $stmt->execute(['id' => $itemId]);
        $reportedBy = (int) $stmt->fetchColumn();
        if ($reportedBy === (int) $me['user_id'] || $isDean) {
            $db->prepare('DELETE FROM lost_found_items WHERE item_id = :id')->execute(['id' => $itemId]);
            AuditLog::record(Auth::id(), 'LOST_FOUND_DELETE', 'lost_found_items', $itemId);
            flash('success', 'Item removed.');
        }
    }
    redirect('/campus/lost-found.php');
}

$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? 'Open';
$catFilter = $_GET['category'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($typeFilter !== '') { $where[] = 'li.item_type = :type'; $params['type'] = $typeFilter; }
if ($statusFilter !== '') { $where[] = 'li.status = :status'; $params['status'] = $statusFilter; }
if ($catFilter !== '') { $where[] = 'li.category = :cat'; $params['cat'] = $catFilter; }
if ($search !== '') { $where[] = '(li.title LIKE :q OR li.description LIKE :q OR li.location LIKE :q)'; $params['q'] = "%$search%"; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("SELECT li.*, u.full_name AS reporter_name FROM lost_found_items li JOIN users u ON u.user_id = li.reported_by $whereSql ORDER BY li.created_at DESC LIMIT 100");
$stmt->execute($params);
$items = $stmt->fetchAll();

$myClaims = [];
if (!$isAdmin) {
    $myClaimsStmt = $db->prepare('SELECT item_id, status FROM lost_found_claims WHERE claimant_id = :uid');
    $myClaimsStmt->execute(['uid' => $me['user_id']]);
    foreach ($myClaimsStmt->fetchAll() as $row) {
        $myClaims[(int) $row['item_id']] = $row['status'];
    }
}

if ($isAdmin) {
    $stats = [
        'open' => (int) $db->query("SELECT COUNT(*) FROM lost_found_items WHERE status = 'Open'")->fetchColumn(),
        'resolved' => (int) $db->query("SELECT COUNT(*) FROM lost_found_items WHERE status = 'Resolved'")->fetchColumn(),
        'pending_claims' => (int) $db->query("SELECT COUNT(*) FROM lost_found_claims WHERE status = 'Pending'")->fetchColumn(),
        'returned' => (int) $db->query("SELECT COUNT(*) FROM lost_found_claims WHERE status = 'Returned'")->fetchColumn(),
    ];
}

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div><h4 class="display-font mb-1">Lost &amp; Found</h4>
    <p class="text-muted small mb-0"><?= $isAdmin ? 'View-only statistics — claim approval is handled by the Dean.' : "Report a lost or found item, or claim something that's yours. The Dean reviews and approves every claim." ?></p></div>
  <?php if (!$isAdmin): ?><button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#postItemModal"><i class="bi bi-plus-circle me-1"></i> Report an Item</button><?php endif; ?>
</div>

<?php if ($isAdmin): ?>
  <div class="row g-3 mb-4">
    <div class="col-md-3"><div class="stat-card"><div class="stat-label">Open Items</div><div class="stat-value"><?= $stats['open'] ?></div></div></div>
    <div class="col-md-3"><div class="stat-card"><div class="stat-label">Resolved Items</div><div class="stat-value"><?= $stats['resolved'] ?></div></div></div>
    <div class="col-md-3"><div class="stat-card"><div class="stat-label">Pending Claims</div><div class="stat-value"><?= $stats['pending_claims'] ?></div></div></div>
    <div class="col-md-3"><div class="stat-card"><div class="stat-label">Items Returned</div><div class="stat-value"><?= $stats['returned'] ?></div></div></div>
  </div>
<?php endif; ?>

<?php if ($isDean): ?>
  <div class="semas-card p-3 mb-3"><a href="<?= APP_URL ?>/dean/lost-found-claims.php" class="btn btn-semas btn-sm"><i class="bi bi-clipboard-check me-1"></i> Review Ownership Claims</a></div>
<?php endif; ?>

<div class="semas-card p-3 mb-3">
  <form method="get" class="row g-2">
    <div class="col-md-4"><input name="q" class="form-control form-control-sm" value="<?= e($search) ?>"></div>
    <div class="col-md-2">
      <select name="type" class="form-select form-select-sm">
        <option value="">Lost &amp; Found</option>
        <option value="Lost" <?= $typeFilter === 'Lost' ? 'selected' : '' ?>>Lost</option>
        <option value="Found" <?= $typeFilter === 'Found' ? 'selected' : '' ?>>Found</option>
      </select>
    </div>
    <div class="col-md-3">
      <select name="category" class="form-select form-select-sm">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?><option <?= $catFilter === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="status" class="form-select form-select-sm">
        <option value="Open" <?= $statusFilter === 'Open' ? 'selected' : '' ?>>Open</option>
        <option value="Resolved" <?= $statusFilter === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
        <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
      </select>
    </div>
    <div class="col-md-1"><button class="btn btn-sm btn-semas w-100"><i class="bi bi-search"></i></button></div>
  </form>
</div>

<div class="row g-3">
  <?php foreach ($items as $it): $myClaimStatus = $myClaims[(int) $it['item_id']] ?? null; ?>
    <div class="col-md-4">
      <div class="semas-card p-3 h-100">
        <div class="d-flex justify-content-between align-items-start mb-1">
          <span class="badge <?= $it['item_type'] === 'Lost' ? 'badge-cancelled' : 'badge-completed' ?>"><?= e($it['item_type']) ?></span>
          <span class="badge bg-light text-dark border"><?= e($it['category']) ?></span>
        </div>
        <?php if ($it['photo_path']): ?>
          <img src="<?= APP_URL . '/' . e($it['photo_path']) ?>" class="img-fluid rounded mb-2" style="max-height:140px;object-fit:cover;width:100%;">
        <?php endif; ?>
        <h6 class="fw-semibold mb-1"><?= e($it['title']) ?></h6>
        <p class="text-muted small mb-1"><?= e($it['description'] ?? '') ?></p>
        <?php if ($it['location']): ?><p class="small mb-1"><i class="bi bi-geo-alt"></i> <?= e($it['location']) ?></p><?php endif; ?>
        <p class="small text-muted mb-2">Posted by <?= e($it['reporter_name']) ?> &middot; <?= e(date('d M Y', strtotime($it['created_at']))) ?></p>
        <?php if ($it['status'] === 'Resolved'): ?>
          <span class="badge bg-secondary">Resolved</span>
        <?php elseif (!$isAdmin): ?>
          <p class="small mb-2"><i class="bi bi-envelope"></i> Contact: <?= e($it['contact_info']) ?></p>
          <?php if ($myClaimStatus): ?>
            <span class="badge <?= $myClaimStatus === 'Approved' ? 'badge-completed' : ($myClaimStatus === 'Rejected' ? 'badge-cancelled' : 'badge-urgent') ?>">Your claim: <?= e($myClaimStatus) ?></span>
          <?php elseif ($role === 'Student' && (int) $it['reported_by'] !== (int) $me['user_id']): ?>
            <button class="btn btn-sm btn-semas-gold" data-bs-toggle="modal" data-bs-target="#claimModal-<?= (int) $it['item_id'] ?>">This is mine</button>
          <?php endif; ?>
          <?php if ((int) $it['reported_by'] === (int) $me['user_id']): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="item_id" value="<?= (int) $it['item_id'] ?>">
              <button class="btn btn-sm btn-outline-danger" name="action" value="delete" onclick="return confirm('Remove this post?');">Delete</button></form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!$isAdmin && !$myClaimStatus): ?>
    <div class="modal fade" id="claimModal-<?= (int) $it['item_id'] ?>" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="claim"><input type="hidden" name="item_id" value="<?= (int) $it['item_id'] ?>">
            <div class="modal-header"><h6 class="modal-title display-font">Claim "<?= e($it['title']) ?>"</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
              <label class="form-label small">Tell us why this is yours. Include your details below (required):</label>
              <textarea name="claim_message" class="form-control form-control-sm" rows="5" required>NAMES: <?= e($me['full_name'] ?? '') ?>
REG NUMBER: <?= e($me['reg_number'] ?? '') ?>
CONTACT: <?= e($me['email'] ?? '') ?>
</textarea>
            </div>
            <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Submit Claim</button></div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$items): ?><div class="col-12"><div class="semas-card p-4 text-center text-muted small">No items match your filters.</div></div><?php endif; ?>
</div>

<?php if (!$isAdmin): ?>
<div class="modal fade" id="postItemModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header"><h6 class="modal-title display-font">Report a Lost or Found Item</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small">This item is...</label>
            <select name="item_type" class="form-select form-select-sm" required>
              <option value="Lost">Lost / I lost something</option>
              <option value="Found">Found / I found something</option>
            </select>
          </div>
          <div class="mb-2"><label class="form-label small">Title</label><input name="title" class="form-control form-control-sm" required></div>
          <div class="mb-2"><label class="form-label small">Category</label>
            <select name="category" class="form-select form-select-sm">
              <?php foreach ($categories as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label small">Description</label><textarea name="description" class="form-control form-control-sm" rows="2"></textarea></div>
          <div class="mb-2"><label class="form-label small">Location</label><input name="location" class="form-control form-control-sm"></div>
          <div class="mb-2"><label class="form-label small">Contact Info (optional, defaults to your email)</label><input name="contact_info" class="form-control form-control-sm"></div>
          <div class="mb-2"><label class="form-label small">Photo (optional)</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm"></div>
        </div>
        <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Post</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
