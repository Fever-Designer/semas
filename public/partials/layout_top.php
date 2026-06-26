<?php
/**
 * partials/layout_top.php
 * Bootstrap 5 + Bootstrap Icons + the "Indigo Ink / Campus Gold" SEMAS theme.
 * Include at the top of any authenticated page, after setting:
 *   $pageTitle   (string)  - shown in the top bar and <title>
 *   $activeNav   (string)  - one of: dashboard, events, ai, attendance, reports, users
 * Then include partials/layout_bottom.php at the end of the page.
 */
$user = Auth::user();
$roleLabel = Auth::role();

$db = Database::connection();
$unread = 0;
if ($user) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0');
    $stmt->execute(['uid' => $user['user_id']]);
    $unread = (int) $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? APP_NAME) ?> - SEMAS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<script>window.SEMAS_BASE_URL = "<?= APP_URL ?>";</script>
<div class="semas-shell">
  <aside class="semas-sidebar" id="semasSidebar">
    <div class="brand">SEM<span>AS</span></div>
    <nav class="nav flex-column py-2">
      <a class="nav-link <?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= APP_URL ?>/dashboard.php">
        <i class="bi bi-grid-1x2-fill"></i> Dashboard
      </a>
      <?php if ($roleLabel === 'Administrator'): ?>
        <a class="nav-link <?= ($activeNav ?? '') === 'events' ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/events.php">
          <i class="bi bi-calendar-event-fill"></i> Events &amp; Announcements
        </a>
        <a class="nav-link <?= ($activeNav ?? '') === 'ai' ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/ai_generator.php">
          <i class="bi bi-robot"></i> AI Notification Generator
        </a>
        <a class="nav-link <?= ($activeNav ?? '') === 'attendance' ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/qr.php">
          <i class="bi bi-qr-code-scan"></i> Attendance &amp; QR Codes
        </a>
      <?php endif; ?>
      <?php if (in_array($roleLabel, ['Administrator', 'Dean', 'HOD'], true)): ?>
        <a class="nav-link" href="<?= APP_URL ?>/admin/event-participants.php"><i class="bi bi-people-fill"></i> Event Participants</a>
        <a class="nav-link" href="<?= APP_URL ?>/admin/scan-student.php"><i class="bi bi-person-check-fill"></i> Scan / Mark Attendance</a>
      <?php endif; ?>
      <?php if (in_array($roleLabel, ['Administrator', 'Dean', 'HOD'], true)): ?>
        <a class="nav-link <?= ($activeNav ?? '') === 'reports' ? 'active' : '' ?>" href="<?= APP_URL ?>/reports/index.php">
          <i class="bi bi-clipboard-check-fill"></i> Compliance Reports
        </a>
      <?php endif; ?>
      <?php if ($roleLabel === 'Student'): ?>
        <a class="nav-link <?= ($activeNav ?? '') === 'events' ? 'active' : '' ?>" href="<?= APP_URL ?>/student/events.php"><i class="bi bi-calendar-event-fill"></i> Events</a>
        <a class="nav-link" href="<?= APP_URL ?>/student/my-qr.php"><i class="bi bi-qr-code"></i> My QR Code</a>
        <a class="nav-link" href="<?= APP_URL ?>/student/scan.php"><i class="bi bi-camera-fill"></i> Scan to Check In</a>
        <a class="nav-link <?= ($activeNav ?? '') === 'suggestions' ? 'active' : '' ?>" href="<?= APP_URL ?>/student/suggestions.php"><i class="bi bi-chat-left-text-fill"></i> Suggestion Box</a>
      <?php endif; ?>
      <?php if (in_array($roleLabel, ['Administrator', 'Dean', 'HOD'], true)): ?>
        <a class="nav-link <?= ($activeNav ?? '') === 'suggestions' ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/suggestions.php"><i class="bi bi-chat-left-text-fill"></i> Suggestion Box</a>
      <?php endif; ?>
      <?php if (in_array($roleLabel, ['Administrator', 'Dean', 'HOD'], true)): ?>
        <a class="nav-link <?= ($activeNav ?? '') === 'users' ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/users.php">
          <i class="bi bi-people-fill"></i> <?= $roleLabel === 'Administrator' ? 'Users &amp; Roles' : 'Manage Students' ?>
        </a>
      <?php endif; ?>
      <a class="nav-link" href="<?= APP_URL ?>/profile.php"><i class="bi bi-person-circle"></i> Profile</a>
      <a class="nav-link" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
  </aside>
  <div class="semas-main">
    <header class="semas-topbar">
      <div class="d-flex align-items-center gap-3">
        <button id="sidebarToggle" class="btn btn-sm btn-outline-secondary d-md-none"><i class="bi bi-list"></i></button>
        <h5 class="mb-0 display-font"><?= e($pageTitle ?? 'Dashboard') ?></h5>
      </div>
      <div class="d-flex align-items-center gap-4">
        <div class="position-relative">
          <div id="notifBell" class="notif-bell">
            <i class="bi bi-bell-fill"></i>
            <span id="notifCount" class="notif-count" style="<?= $unread > 0 ? '' : 'display:none;' ?>"><?= $unread > 99 ? '99+' : $unread ?></span>
          </div>
          <div id="notifPanel" class="notif-panel">
            <div class="p-2 border-bottom d-flex justify-content-between align-items-center">
              <span class="fw-semibold small">Notifications</span>
              <button id="notifMarkAllRead" class="btn btn-link btn-sm p-0" style="font-size:0.72rem;">Mark all read</button>
            </div>
            <div id="notifList"><div class="p-3 text-muted small text-center">Loading...</div></div>
          </div>
        </div>
        <div class="dropdown">
          <a href="#" class="d-flex align-items-center gap-2 text-decoration-none text-dark" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle fs-4"></i>
            <span class="d-none d-md-inline small fw-semibold"><?= e($user['full_name'] ?? '') ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><span class="dropdown-item-text small text-muted"><?= e($roleLabel ?? '') ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </header>
    <div class="semas-content">
      <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success small"><?= e($msg) ?></div>
      <?php endif; ?>
      <?php if ($msg = flash('error')): ?>
        <div class="alert alert-danger small"><?= e($msg) ?></div>
      <?php endif; ?>
