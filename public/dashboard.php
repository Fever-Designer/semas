<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireRole(['Administrator', 'Dean', 'HOD', 'Student']);

$db = Database::connection();
$role = Auth::role();
$user = Auth::user();
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

if ($role === 'Administrator') {
    $stats = [
        'total_students'  => (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student'")->fetchColumn(),
        'active_students' => (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student' AND u.status='Active'")->fetchColumn(),
        'total_events'    => (int) $db->query("SELECT COUNT(*) FROM events")->fetchColumn(),
        'total_announce'  => (int) $db->query("SELECT COUNT(*) FROM announcements")->fetchColumn(),
    ];
    $totalCheckins = (int) $db->query("SELECT COUNT(*) FROM attendance_logs")->fetchColumn();
    $totalRegs = (int) $db->query("SELECT COUNT(*) FROM event_registrations")->fetchColumn();
    $attendanceRate = $totalRegs > 0 ? round($totalCheckins / $totalRegs * 100, 1) : 0.0;

    $recentEvents = $db->query("SELECT * FROM events ORDER BY created_at DESC LIMIT 5")->fetchAll();
    $recentAnnouncements = $db->query("SELECT * FROM announcements ORDER BY posted_at DESC LIMIT 5")->fetchAll();
    $pendingSuggestions = (int) $db->query("SELECT COUNT(*) FROM suggestions WHERE status = 'New'")->fetchColumn();
    $todaysEvents = $db->query("SELECT * FROM events WHERE event_date = CURDATE() ORDER BY start_time")->fetchAll();

} elseif ($role === 'Dean') {
    $deptStmt = $db->prepare(
        "SELECT d.department_id, d.department_name,
            (SELECT COUNT(*) FROM attendance_logs al JOIN events e2 ON e2.event_id=al.event_id WHERE e2.department_id=d.department_id) AS checkins,
            (SELECT COUNT(*) FROM users u2 WHERE u2.department_id=d.department_id) AS student_count
         FROM departments d
         JOIN faculties f ON f.faculty_id = d.faculty_id AND f.dean_user_id = :uid"
    );
    $deptStmt->execute(['uid' => $user['user_id']]);
    $deptRows = $deptStmt->fetchAll();

} elseif ($role === 'HOD') {
    $deptStudents = $db->prepare(
        "SELECT user_id, full_name, reg_number, status FROM users WHERE department_id = :dept AND role_id = (SELECT role_id FROM roles WHERE role_name='Student') ORDER BY full_name"
    );
    $deptStudents->execute(['dept' => $user['department_id']]);
    $students = $deptStudents->fetchAll();
    $activeCount = count(array_filter($students, function ($s) { return $s['status'] === 'Active'; }));

} else { // Student
    $upcoming = $db->prepare("SELECT * FROM events WHERE status IN ('Scheduled','Ongoing') ORDER BY event_date LIMIT 10");
    $upcoming->execute();
    $upcomingEvents = $upcoming->fetchAll();

    $feedStmt = $db->prepare("SELECT a.*, u.full_name AS poster FROM announcements a JOIN users u ON u.user_id=a.posted_by ORDER BY a.posted_at DESC LIMIT 6");
    $feedStmt->execute();
    $feed = $feedStmt->fetchAll();

    $unreadStmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0');
    $unreadStmt->execute(['uid' => $user['user_id']]);
    $unreadCount = (int) $unreadStmt->fetchColumn();

    $myEventsStmt = $db->prepare(
        "SELECT e.*, (a.attendance_id IS NOT NULL) AS attended
         FROM event_registrations er JOIN events e ON e.event_id = er.event_id
         LEFT JOIN attendance_logs a ON a.event_id = e.event_id AND a.user_id = er.user_id
         WHERE er.user_id = :uid"
    );
    $myEventsStmt->execute(['uid' => $user['user_id']]);
    $myEvents = $myEventsStmt->fetchAll();
    $totalRegistered = count($myEvents);
    $totalAttended = count(array_filter($myEvents, function ($e) { return (int) $e['attended'] === 1; }));
    $myAttendanceRate = $totalRegistered > 0 ? round($totalAttended / $totalRegistered * 100, 1) : 0.0;
}

require __DIR__ . '/partials/layout_top.php';
?>

<?php if ($role === 'Administrator'): ?>
  <div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3">
      <div class="stat-card"><i class="bi bi-mortarboard-fill stat-icon"></i>
        <div class="stat-label">Total Students</div>
        <div class="stat-value"><?= $stats['total_students'] ?></div>
      </div>
    </div>
    <div class="col-md-6 col-lg-3">
      <div class="stat-card"><i class="bi bi-person-check-fill stat-icon"></i>
        <div class="stat-label">Active Students</div>
        <div class="stat-value"><?= $stats['active_students'] ?></div>
      </div>
    </div>
    <div class="col-md-6 col-lg-3">
      <div class="stat-card"><i class="bi bi-calendar-event-fill stat-icon"></i>
        <div class="stat-label">Total Events</div>
        <div class="stat-value"><?= $stats['total_events'] ?></div>
      </div>
    </div>
    <div class="col-md-6 col-lg-3">
      <div class="stat-card"><i class="bi bi-megaphone-fill stat-icon"></i>
        <div class="stat-label">Announcements</div>
        <div class="stat-value"><?= $stats['total_announce'] ?></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-label">Overall Attendance Rate</div>
        <div class="stat-value"><?= $attendanceRate ?>%</div>
        <div class="progress mt-2" style="height:6px;">
          <div class="progress-bar" style="width:<?= $attendanceRate ?>%;background-color:var(--semas-gold);"></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-label">Today's Events</div>
        <div class="stat-value"><?= count($todaysEvents) ?></div>
        <div class="text-muted small mt-1"><?= $todaysEvents ? e($todaysEvents[0]['title']) . ($todaysEvents[0]['start_time'] ? ' @ ' . e($todaysEvents[0]['start_time']) : '') : 'None scheduled' ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-label">Pending Suggestions</div>
        <div class="stat-value"><?= $pendingSuggestions ?></div>
        <a href="<?= APP_URL ?>/admin/suggestions.php" class="small">Review &rarr;</a>
      </div>
    </div>
  </div>

  <div class="semas-card p-3 mb-4">
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= APP_URL ?>/admin/events.php" class="btn btn-semas btn-sm"><i class="bi bi-plus-circle me-1"></i> New Event</a>
      <a href="<?= APP_URL ?>/admin/ai_generator.php" class="btn btn-semas-gold btn-sm"><i class="bi bi-robot me-1"></i> AI Notification Generator</a>
      <a href="<?= APP_URL ?>/admin/qr.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-qr-code-scan me-1"></i> Attendance &amp; QR Codes</a>
      <a href="<?= APP_URL ?>/admin/event-participants.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-people me-1"></i> Event Participants</a>
      <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-people me-1"></i> Manage Users</a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Recent Events</h6>
        <?php if (!$recentEvents): ?>
          <p class="text-muted small">No events yet.</p>
        <?php else: foreach ($recentEvents as $ev): ?>
          <div class="d-flex justify-content-between align-items-center border-bottom py-2">
            <div>
              <div class="fw-semibold small"><?= e($ev['title']) ?></div>
              <div class="text-muted" style="font-size:0.75rem;"><?= e($ev['event_date']) ?> &middot; <?= e($ev['venue']) ?></div>
            </div>
            <span class="badge badge-<?= strtolower($ev['status']) === 'completed' ? 'completed' : (strtolower($ev['status']) === 'cancelled' ? 'cancelled' : 'upcoming') ?>"><?= e($ev['status']) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Recent Announcements</h6>
        <?php if (!$recentAnnouncements): ?>
          <p class="text-muted small">No announcements yet.</p>
        <?php else: foreach ($recentAnnouncements as $a): ?>
          <div class="border-bottom py-2">
            <div class="fw-semibold small">
              <?php if ($a['priority'] === 'Urgent' || $a['priority'] === 'High'): ?><span class="badge badge-urgent me-1"><?= e($a['priority']) ?></span><?php endif; ?>
              <?= e($a['title']) ?>
            </div>
            <div class="text-muted" style="font-size:0.78rem;"><?= e(mb_substr($a['message'], 0, 90)) ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

<?php elseif ($role === 'Dean'): ?>
  <div class="semas-card p-3 mb-4">
    <h6 class="display-font mb-3">Department Comparison</h6>
    <table class="table table-sm align-middle">
      <thead><tr><th>Department</th><th>Students</th><th>Total Check-ins</th></tr></thead>
      <tbody>
        <?php foreach ($deptRows as $d): ?>
          <tr><td><?= e($d['department_name']) ?></td><td><?= (int) $d['student_count'] ?></td><td><?= (int) $d['checkins'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$deptRows): ?><tr><td colspan="3" class="text-muted small">No departments are currently assigned to your faculty record.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <a href="<?= APP_URL ?>/reports/index.php" class="btn btn-semas"><i class="bi bi-clipboard-check-fill me-1"></i> Go to Compliance Reports</a>

<?php elseif ($role === 'HOD'): ?>
  <div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3">
      <div class="stat-card"><i class="bi bi-mortarboard-fill stat-icon"></i>
        <div class="stat-label">Department Students</div>
        <div class="stat-value"><?= count($students) ?></div>
      </div>
    </div>
    <div class="col-md-6 col-lg-3">
      <div class="stat-card"><i class="bi bi-person-check-fill stat-icon"></i>
        <div class="stat-label">Active Students</div>
        <div class="stat-value"><?= $activeCount ?></div>
      </div>
    </div>
  </div>
  <div class="semas-card p-3">
    <h6 class="display-font mb-3">Department Students</h6>
    <table class="table table-sm align-middle">
      <thead><tr><th>Name</th><th>Reg. Number</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($students as $s): ?>
          <tr>
            <td><?= e($s['full_name']) ?></td>
            <td><?= e($s['reg_number']) ?></td>
            <td><span class="badge badge-<?= $s['status'] === 'Active' ? 'completed' : 'cancelled' ?>"><?= e($s['status']) ?></span></td>
            <td>
              <form method="post" action="<?= APP_URL ?>/hod/toggle-status.php" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int) $s['user_id'] ?>">
                <button class="btn btn-sm btn-outline-dark"><?= $s['status'] === 'Active' ? 'Deactivate' : 'Activate' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php else: /* Student */ ?>
  <div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3">
      <div class="stat-card"><i class="bi bi-calendar-event-fill stat-icon"></i>
        <div class="stat-label">Upcoming Events</div>
        <div class="stat-value"><?= count($upcomingEvents) ?></div>
      </div>
    </div>
    <div class="col-md-6 col-lg-3">
      <div class="stat-card"><i class="bi bi-bell-fill stat-icon"></i>
        <div class="stat-label">Unread Notifications</div>
        <div class="stat-value"><?= $unreadCount ?></div>
      </div>
    </div>
    <div class="col-md-6 col-lg-3">
      <div class="stat-card"><i class="bi bi-clipboard-check-fill stat-icon"></i>
        <div class="stat-label">My Attendance Rate</div>
        <div class="stat-value"><?= $myAttendanceRate ?>%</div>
      </div>
    </div>
    <div class="col-md-6 col-lg-3">
      <div class="stat-card"><i class="bi bi-megaphone-fill stat-icon"></i>
        <div class="stat-label">Announcements</div>
        <div class="stat-value"><?= count($feed) ?></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">My Registered Events</h6>
        <?php if (!$myEvents): ?>
          <p class="text-muted small">You haven't registered for any events yet. <a href="<?= APP_URL ?>/student/events.php">Browse events</a>.</p>
        <?php else: ?>
          <table class="table table-sm align-middle">
            <thead><tr><th>Event</th><th>Date</th><th>Attendance</th></tr></thead>
            <tbody>
              <?php foreach ($myEvents as $myEv): ?>
                <tr>
                  <td><?= e($myEv['title']) ?></td>
                  <td><?= e($myEv['event_date']) ?></td>
                  <td><?= (int) $myEv['attended'] === 1 ? '<span class="badge badge-completed">Attended</span>' : '<span class="badge badge-cancelled">Not yet</span>' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="display-font mb-0">Upcoming Events</h6>
          <a href="<?= APP_URL ?>/student/scan.php" class="small">Scan to check in</a>
        </div>
        <?php if (!$upcomingEvents): ?>
          <p class="text-muted small">No upcoming events right now.</p>
        <?php else: foreach ($upcomingEvents as $ev): ?>
          <div class="d-flex justify-content-between align-items-center border-bottom py-2">
            <div>
              <div class="fw-semibold small"><?= e($ev['title']) ?></div>
              <div class="text-muted" style="font-size:0.75rem;"><?= e($ev['event_date']) ?>, <?= e($ev['start_time']) ?> &middot; <?= e($ev['venue']) ?></div>
            </div>
            <span class="badge badge-upcoming"><?= e($ev['status']) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Recent Announcements</h6>
        <?php if (!$feed): ?>
          <p class="text-muted small">No announcements yet.</p>
        <?php else: foreach ($feed as $a): ?>
          <div class="border-bottom py-2">
            <div class="fw-semibold small">
              <?php if ($a['priority'] === 'Urgent' || $a['priority'] === 'High'): ?><span class="badge badge-urgent me-1"><?= e($a['priority']) ?></span><?php endif; ?>
              <?= e($a['title']) ?>
            </div>
            <div class="text-muted" style="font-size:0.78rem;"><?= e(mb_substr($a['message'], 0, 90)) ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout_bottom.php'; ?>
