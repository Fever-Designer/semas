<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Dean']);

$pageTitle = 'Lost & Found Claims';
$activeNav = 'lostfound-claims';
$db = Database::connection();
$me = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $claimId = (int) ($_POST['claim_id'] ?? 0);

    if ($action === 'approve') {
        $db->prepare("UPDATE lost_found_claims SET status='Approved', decided_by=:uid, decided_at=NOW(), verification_notes=:notes WHERE claim_id=:id")
           ->execute(['uid' => $me['user_id'], 'notes' => trim($_POST['verification_notes'] ?? ''), 'id' => $claimId]);
        AuditLog::record(Auth::id(), 'LOST_FOUND_CLAIM_APPROVE', 'lost_found_claims', $claimId);
        flash('success', 'Claim approved. Record the handover when the item is collected.');
    } elseif ($action === 'reject') {
        $db->prepare("UPDATE lost_found_claims SET status='Rejected', decided_by=:uid, decided_at=NOW(), verification_notes=:notes WHERE claim_id=:id")
           ->execute(['uid' => $me['user_id'], 'notes' => trim($_POST['verification_notes'] ?? ''), 'id' => $claimId]);
        AuditLog::record(Auth::id(), 'LOST_FOUND_CLAIM_REJECT', 'lost_found_claims', $claimId);
        flash('success', 'Claim rejected.');
    } elseif ($action === 'mark_returned') {
        $db->prepare(
            "UPDATE lost_found_claims SET status='Returned', receiver_name=:rn, receiver_reg_number=:rr, verification_notes=:notes, decided_by=:uid, decided_at=NOW() WHERE claim_id=:id"
        )->execute([
            'rn' => trim($_POST['receiver_name']), 'rr' => trim($_POST['receiver_reg_number'] ?? ''),
            'notes' => trim($_POST['verification_notes'] ?? ''), 'uid' => $me['user_id'], 'id' => $claimId,
        ]);
        $itemStmt = $db->prepare('SELECT item_id FROM lost_found_claims WHERE claim_id = :id');
        $itemStmt->execute(['id' => $claimId]);
        $itemId = (int) $itemStmt->fetchColumn();
        $db->prepare("UPDATE lost_found_items SET status='Resolved', resolved_by=:uid, resolved_at=NOW() WHERE item_id=:id")
           ->execute(['uid' => $me['user_id'], 'id' => $itemId]);
        AuditLog::record(Auth::id(), 'LOST_FOUND_CLAIM_RETURNED', 'lost_found_claims', $claimId, "receiver={$_POST['receiver_name']}");
        flash('success', 'Item marked as returned. Handover record saved.');
    }
    redirect('/dean/lost-found-claims.php');
}

$statusFilter = $_GET['status'] ?? 'Pending';
$where = $statusFilter !== '' ? 'WHERE c.status = :status' : '';
$stmt = $db->prepare(
    "SELECT c.*, i.title AS item_title, i.item_type, i.category, u.full_name AS claimant_name, u.reg_number AS claimant_reg
     FROM lost_found_claims c JOIN lost_found_items i ON i.item_id = c.item_id JOIN users u ON u.user_id = c.claimant_id
     $where ORDER BY c.created_at DESC"
);
if ($statusFilter !== '') { $stmt->execute(['status' => $statusFilter]); } else { $stmt->execute(); }
$claims = $stmt->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Lost &amp; Found — Ownership Claims</h4>
<p class="text-muted small mb-4">Review claims, verify the claimant, and record who actually collected the item. This creates an audit trail of every handover.</p>

<div class="semas-card p-3 mb-3">
  <div class="d-flex gap-2">
    <a href="?status=Pending" class="btn btn-sm <?= $statusFilter === 'Pending' ? 'btn-semas' : 'btn-outline-dark' ?>">Pending</a>
    <a href="?status=Approved" class="btn btn-sm <?= $statusFilter === 'Approved' ? 'btn-semas' : 'btn-outline-dark' ?>">Approved</a>
    <a href="?status=Returned" class="btn btn-sm <?= $statusFilter === 'Returned' ? 'btn-semas' : 'btn-outline-dark' ?>">Returned</a>
    <a href="?status=Rejected" class="btn btn-sm <?= $statusFilter === 'Rejected' ? 'btn-semas' : 'btn-outline-dark' ?>">Rejected</a>
    <a href="?status=" class="btn btn-sm <?= $statusFilter === '' ? 'btn-semas' : 'btn-outline-dark' ?>">All</a>
  </div>
</div>

<?php foreach ($claims as $c): ?>
  <div class="semas-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h6 class="display-font mb-1"><?= e($c['item_title']) ?> <span class="badge bg-light text-dark border"><?= e($c['item_type']) ?></span></h6>
        <p class="text-muted small mb-1">Claimed by <?= e($c['claimant_name']) ?> (<?= e($c['claimant_reg'] ?? '—') ?>)</p>
        <p class="small mb-1"><?= e($c['claim_message'] ?? '') ?></p>
      </div>
      <span class="badge <?= $c['status'] === 'Returned' ? 'badge-completed' : ($c['status'] === 'Rejected' ? 'badge-cancelled' : 'badge-urgent') ?>"><?= e($c['status']) ?></span>
    </div>
    <?php if ($c['status'] === 'Pending'): ?>
      <form method="post" class="mt-2">
        <?= csrf_field() ?><input type="hidden" name="claim_id" value="<?= (int) $c['claim_id'] ?>">
        <textarea name="verification_notes" class="form-control form-control-sm mb-2" rows="2" placeholder="Verification notes (optional)"></textarea>
        <button class="btn btn-sm btn-semas-gold" name="action" value="approve">Approve</button>
        <button class="btn btn-sm btn-outline-danger" name="action" value="reject">Reject</button>
      </form>
    <?php elseif ($c['status'] === 'Approved'): ?>
      <form method="post" class="mt-2 row g-2 align-items-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="mark_returned"><input type="hidden" name="claim_id" value="<?= (int) $c['claim_id'] ?>">
        <div class="col-md-4"><label class="form-label small mb-0">Receiver's Name</label><input name="receiver_name" class="form-control form-control-sm" required value="<?= e($c['claimant_name']) ?>"></div>
        <div class="col-md-3"><label class="form-label small mb-0">Reg/Staff ID</label><input name="receiver_reg_number" class="form-control form-control-sm" value="<?= e($c['claimant_reg'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label small mb-0">Notes</label><input name="verification_notes" class="form-control form-control-sm"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-semas w-100">Mark Returned</button></div>
      </form>
    <?php else: ?>
      <p class="text-muted small mb-0 mt-2">
        Decided <?= e($c['decided_at']) ?><?= $c['receiver_name'] ? ' — received by ' . e($c['receiver_name']) . ($c['receiver_reg_number'] ? ' (' . e($c['receiver_reg_number']) . ')' : '') : '' ?>
        <?= $c['verification_notes'] ? ' — ' . e($c['verification_notes']) : '' ?>
      </p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$claims): ?><div class="semas-card p-4 text-center text-muted small">No claims in this view.</div><?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
