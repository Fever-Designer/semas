<?php
/**
 * partials/announcement_card.php
 * SEMAS / Student Event Management and Announcement System
 * Safe + production-ready announcement card renderer
 */

// Prevent undefined variable errors
if (!isset($a) || !is_array($a)) {
    return;
}

// Safe defaults
$title      = $a['title'] ?? 'No Title';
$message    = $a['message'] ?? '';
$priority   = $a['priority'] ?? 'Normal';
$category   = $a['category'] ?? 'General';
$senderName = $a['sender_name'] ?? '/';
$postedAt   = $a['posted_at'] ?? null;

// Safe timestamp handling
$posted = $postedAt ? strtotime($postedAt) : time();
?>
<div class="semas-card p-3 mb-3 announcement-card shadow-sm">

  <!-- University Header -->
  <div class="text-center text-muted"
       style="font-size:0.72rem;letter-spacing:.04em;text-transform:uppercase;">
    <?= e(Settings::get('university_name', 'SMART EDUCATION MANAGEMENT SYSTEM')) ?>
  </div>

  <!-- Title + Badges -->
  <div class="d-flex justify-content-between align-items-start mt-2 mb-1">
    <h6 class="display-font mb-0">
      <?= e($title) ?>
    </h6>

    <div class="text-nowrap">

      <?php if (in_array($priority, ['Urgent', 'High'], true)): ?>
        <span class="badge badge-urgent">
          <?= e($priority) ?>
        </span>
      <?php else: ?>
        <span class="badge bg-secondary">
          <?= e($priority) ?>
        </span>
      <?php endif; ?>

      <span class="badge bg-light text-dark border">
        <?= e($category) ?>
      </span>
    </div>
  </div>

  <!-- Message -->
  <p class="mb-3" style="white-space:pre-wrap;">
    <?= e($message) ?>
  </p>

  <hr class="my-2">

  <!-- Footer Info -->
  <div class="row small gy-1">

    <div class="col-6 col-md-4">
      <span class="text-muted">Sent by:</span><br>
      <strong><?= e($senderName) ?></strong>
    </div>

    <div class="col-6 col-md-4">
      <span class="text-muted">Date:</span><br>
      <?= e(date('d M Y', $posted)) ?>
    </div>

    <div class="col-6 col-md-4">
      <span class="text-muted">Time:</span><br>
      <?= e(date('h:i A', $posted)) ?>
    </div>

  </div>
</div>
