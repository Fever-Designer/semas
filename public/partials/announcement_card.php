<?php
/**
 * partials/announcement_card.php
 * Renders one announcement exactly per the spec's "Announcement Display"
 * format (university header, message, Sent by / Role / Date / Time).
 * Expects $a (an announcements row, optionally with sender_name/sender_role/
 * sender_scope columns) to be set by the including page.
 */
$posted = strtotime($a['posted_at']);
?>
<div class="semas-card p-3 mb-3 announcement-card">
  <div class="text-center text-muted" style="font-size:0.72rem;letter-spacing:.04em;text-transform:uppercase;"><?= e(Settings::get('university_name', 'University of Kigali')) ?></div>
  <div class="d-flex justify-content-between align-items-start mt-2 mb-1">
    <h6 class="display-font mb-0"><?= e($a['title']) ?></h6>
    <div class="text-nowrap">
      <?php if (in_array($a['priority'], ['Urgent', 'High'], true)): ?>
        <span class="badge badge-urgent"><?= e($a['priority']) ?></span>
      <?php endif; ?>
      <span class="badge bg-light text-dark border"><?= e($a['category']) ?></span>
    </div>
  </div>
  <p class="mb-3" style="white-space:pre-wrap;"><?= e($a['message']) ?></p>
  <hr class="my-2">
  <div class="row small gy-1">
    <div class="col-6 col-md-3"><span class="text-muted">Sent by:</span><br><strong><?= e($a['sender_name'] ?? '—') ?></strong></div>
    <div class="col-6 col-md-3"><span class="text-muted">Role:</span><br><strong><?= e($a['sender_role'] ?? '—') ?></strong></div>
    <div class="col-6 col-md-3"><span class="text-muted">Date:</span><br><?= e(date('d F Y', $posted)) ?></div>
    <div class="col-6 col-md-3"><span class="text-muted">Time:</span><br><?= e(date('h:i A', $posted)) ?></div>
  </div>
</div>
