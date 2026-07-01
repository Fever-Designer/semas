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
<h4 class="display-font mb-1">Lost &amp; Found / Ownership Claims</h4>

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
  <div class="semas-card p-3 mb-3 claim-card"
       data-item-title="<?= e($c['item_title']) ?>"
       data-item-type="<?= e($c['item_type']) ?>"
       data-claimant-name="<?= e($c['claimant_name']) ?>"
       data-claimant-reg="<?= e($c['claimant_reg'] ?? 'N/A') ?>">
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
        <?= csrf_field() ?>
        <input type="hidden" name="claim_id" value="<?= (int) $c['claim_id'] ?>">
        <input type="hidden" name="action" value="">
        <textarea name="verification_notes" class="form-control form-control-sm mb-2" rows="2"></textarea>
        <button type="button" class="btn btn-sm btn-semas-gold claim-confirm-btn" data-claim-action="approve">Approve</button>
        <button type="button" class="btn btn-sm btn-outline-danger claim-confirm-btn" data-claim-action="reject">Reject</button>
      </form>
    <?php elseif ($c['status'] === 'Approved'): ?>
      <form method="post" class="mt-2 row g-2 align-items-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="mark_returned"><input type="hidden" name="claim_id" value="<?= (int) $c['claim_id'] ?>">
        <div class="col-md-4"><label class="form-label small mb-0">Receiver's Name</label><input name="receiver_name" class="form-control form-control-sm" required value="<?= e($c['claimant_name']) ?>"></div>
        <div class="col-md-3"><label class="form-label small mb-0">Reg/Staff ID</label><input name="receiver_reg_number" class="form-control form-control-sm" value="<?= e($c['claimant_reg'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label small mb-0">Notes</label><input name="verification_notes" class="form-control form-control-sm"></div>
        <div class="col-md-2"><button type="button" class="btn btn-sm btn-semas w-100 claim-confirm-btn" data-claim-action="mark_returned">Mark Returned</button></div>
      </form>
    <?php else: ?>
      <p class="text-muted small mb-0 mt-2">
        Decided <?= e($c['decided_at']) ?><?= $c['receiver_name'] ? ' / received by ' . e($c['receiver_name']) . ($c['receiver_reg_number'] ? ' (' . e($c['receiver_reg_number']) . ')' : '') : '' ?>
        <?= $c['verification_notes'] ? ' / ' . e($c['verification_notes']) : '' ?>
      </p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$claims): ?><div class="semas-card p-4 text-center text-muted small">No claims in this view.</div><?php endif; ?>

<div class="modal fade" id="claimConfirmModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title display-font" id="claimConfirmTitle">Confirm Claim Action</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="claimConfirmAlert" class="alert alert-warning small py-2 px-3 mb-3"></div>
        <div class="d-flex gap-3 align-items-start">
          <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:54px;height:54px;background:#f8f9fa;border:2px solid var(--semas-gold);">
            <i class="bi bi-search-heart fs-4" style="color:var(--semas-ink);"></i>
          </div>
          <div class="small">
            <div class="fw-semibold" id="claimConfirmItem"></div>
            <div class="text-muted" id="claimConfirmType"></div>
            <div class="mt-2"><strong>Claimant:</strong> <span id="claimConfirmClaimant"></span></div>
            <div><strong>Reg/Staff ID:</strong> <span id="claimConfirmReg"></span></div>
            <div id="claimConfirmReceiverWrap" class="mt-2" style="display:none;">
              <div><strong>Receiver:</strong> <span id="claimConfirmReceiver"></span></div>
              <div><strong>Receiver ID:</strong> <span id="claimConfirmReceiverReg"></span></div>
            </div>
            <div id="claimConfirmNotesWrap" class="mt-2" style="display:none;">
              <strong>Notes:</strong> <span id="claimConfirmNotes"></span>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-semas-gold btn-sm" id="claimConfirmSubmitBtn">
          <i class="bi bi-check2 me-1"></i><span id="claimConfirmSubmitText">Confirm</span>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  let pendingForm = null;
  let pendingAction = '';
  const modalEl = document.getElementById('claimConfirmModal');
  const modal = modalEl ? new bootstrap.Modal(modalEl) : null;

  const actionText = {
    approve: 'Approve Claim',
    reject: 'Reject Claim',
    mark_returned: 'Confirm Handover',
  };
  const alertText = {
    approve: 'Approve this ownership claim after checking the claimant details.',
    reject: 'Reject this ownership claim. The claimant will remain without ownership approval.',
    mark_returned: 'Confirm that the item has been handed over to the receiver below.',
  };

  function textFrom(form, selector) {
    const el = form.querySelector(selector);
    return el ? (el.value || '').trim() : '';
  }

  document.querySelectorAll('.claim-confirm-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const form = btn.closest('form');
      if (!form || !form.reportValidity()) return;

      const card = btn.closest('.claim-card');
      pendingForm = form;
      pendingAction = btn.dataset.claimAction || form.querySelector('[name="action"]')?.value || '';

      document.getElementById('claimConfirmTitle').textContent = actionText[pendingAction] || 'Confirm Claim Action';
      document.getElementById('claimConfirmSubmitText').textContent = actionText[pendingAction] || 'Confirm';
      document.getElementById('claimConfirmAlert').textContent = alertText[pendingAction] || 'Confirm this claim action.';
      document.getElementById('claimConfirmItem').textContent = card?.dataset.itemTitle || '';
      document.getElementById('claimConfirmType').textContent = card?.dataset.itemType || '';
      document.getElementById('claimConfirmClaimant').textContent = card?.dataset.claimantName || '';
      document.getElementById('claimConfirmReg').textContent = card?.dataset.claimantReg || 'N/A';

      const notes = textFrom(form, '[name="verification_notes"]');
      const notesWrap = document.getElementById('claimConfirmNotesWrap');
      document.getElementById('claimConfirmNotes').textContent = notes;
      notesWrap.style.display = notes ? '' : 'none';

      const receiverWrap = document.getElementById('claimConfirmReceiverWrap');
      if (pendingAction === 'mark_returned') {
        document.getElementById('claimConfirmReceiver').textContent = textFrom(form, '[name="receiver_name"]');
        document.getElementById('claimConfirmReceiverReg').textContent = textFrom(form, '[name="receiver_reg_number"]') || 'N/A';
        receiverWrap.style.display = '';
      } else {
        receiverWrap.style.display = 'none';
      }

      modal?.show();
    });
  });

  document.getElementById('claimConfirmSubmitBtn')?.addEventListener('click', function () {
    if (!pendingForm) return;
    const actionInput = pendingForm.querySelector('[name="action"]');
    if (actionInput) actionInput.value = pendingAction;
    this.disabled = true;
    document.getElementById('claimConfirmSubmitText').textContent = 'Processing...';
    pendingForm.submit();
  });

  modalEl?.addEventListener('hidden.bs.modal', function () {
    const btn = document.getElementById('claimConfirmSubmitBtn');
    btn.disabled = false;
    document.getElementById('claimConfirmSubmitText').textContent = 'Confirm';
  });
})();
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
