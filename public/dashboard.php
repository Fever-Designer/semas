<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireRole(['Principal', 'Dean', 'HOD', 'Lecturer', 'Student', 'Registrar', 'Coordinator']);

$db = Database::connection();
$role = Auth::role();
$user = Auth::user();
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

if ($role === 'Principal') {
    // Principal's scope is USER MANAGEMENT + SYSTEM CONFIG ONLY.
    // No events/attendance/modules/exam data is queried or shown here by design.
    $stats = [
        'total_users'     => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'pending_users'   => (int) $db->query("SELECT COUNT(*) FROM users WHERE status = 'Pending'")->fetchColumn(),
        'active_users'    => (int) $db->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn(),
        'total_students'  => (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student'")->fetchColumn(),
        'total_lecturers' => (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Lecturer'")->fetchColumn(),
        'total_hods'      => (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='HOD'")->fetchColumn(),
        'total_deans'     => (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Dean'")->fetchColumn(),
        'total_departments' => (int) $db->query('SELECT COUNT(*) FROM departments')->fetchColumn(),
    ];
    $recentStaff = $db->query(
        "SELECT u.full_name, u.email, r.role_name, u.created_at FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE r.role_name IN ('HOD','Dean','Lecturer') ORDER BY u.created_at DESC LIMIT 8"
    )->fetchAll();
    $recentLogins = $db->query(
        "SELECT u.full_name, r.role_name, u.last_login_at FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE u.last_login_at IS NOT NULL ORDER BY u.last_login_at DESC LIMIT 8"
    )->fetchAll();
    $recentAnnouncementsAdmin = $db->query('SELECT * FROM announcements ORDER BY posted_at DESC LIMIT 5')->fetchAll();
    $pendingSuggestions = (int) $db->query("SELECT COUNT(*) FROM suggestions WHERE status = 'New'")->fetchColumn();
    $usersByRole = $db->query("SELECT r.role_name, COUNT(*) AS c FROM users u JOIN roles r ON r.role_id = u.role_id GROUP BY r.role_name ORDER BY c DESC")->fetchAll();
    $newAccounts14d = (int) $db->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)")->fetchColumn();

    // Storage usage: real disk space where the app's upload directory lives.
    $uploadsPath = __DIR__ . '/uploads';
    $diskFree = @disk_free_space($uploadsPath) ?: 0;
    $diskTotal = @disk_total_space($uploadsPath) ?: 0;
    $diskUsedPct = $diskTotal > 0 ? round((1 - $diskFree / $diskTotal) * 100) : 0;

    // System status: simple DB connectivity check (we're already connected if we got this far).
    $systemStatus = 'Operational';

} elseif ($role === 'Dean') {
    $stats = [
        'total_students'  => (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student'")->fetchColumn(),
        'active_students' => (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student' AND u.status='Active'")->fetchColumn(),
        'pending_students'=> (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student' AND u.status='Pending'")->fetchColumn(),
        'total_events'    => (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn(),
    ];
    $recentAnnouncements = $db->query("SELECT * FROM announcements ORDER BY posted_at DESC LIMIT 5")->fetchAll();
    $highAttendanceEvents = $db->query(
        "SELECT e.event_id, e.title, e.event_date, e.venue,
                COUNT(DISTINCT al.user_id) AS attendee_count
         FROM events e
         JOIN attendance_logs al ON al.event_id = e.event_id
         GROUP BY e.event_id, e.title, e.event_date, e.venue
         ORDER BY attendee_count DESC, e.event_date DESC
         LIMIT 5"
    )->fetchAll();
    $eventCheckins7d = (int) $db->query("SELECT COUNT(*) FROM attendance_logs WHERE checkin_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetchColumn();

} elseif ($role === 'HOD') {
    Module::autoCompleteExpired();
    $thisMonthCond = "YEAR(COALESCE(start_date, created_at)) = YEAR(CURDATE()) AND MONTH(COALESCE(start_date, created_at)) = MONTH(CURDATE())";
    $stats = [
        'total_modules'     => (int) $db->query("SELECT COUNT(*) FROM modules WHERE $thisMonthCond")->fetchColumn(),
        'ongoing_modules'   => (int) $db->query("SELECT COUNT(*) FROM modules WHERE status='Ongoing' AND $thisMonthCond")->fetchColumn(),
        'completed_modules' => (int) $db->query("SELECT COUNT(*) FROM modules WHERE status='Completed' AND $thisMonthCond")->fetchColumn(),
        'total_departments' => (int) $db->query('SELECT COUNT(*) FROM departments')->fetchColumn(),
        'total_lecturers'   => (int) $db->query('SELECT COUNT(*) FROM lecturers')->fetchColumn(),
    ];
    $recentModules = $db->query(
        "SELECT m.*, d.department_name, u.full_name AS lecturer_name FROM modules m
         LEFT JOIN departments d ON d.department_id = m.department_id
         LEFT JOIN lecturers l ON l.lecturer_id = m.lecturer_id LEFT JOIN users u ON u.user_id = l.user_id
         ORDER BY m.created_at DESC LIMIT 8"
    )->fetchAll();

    // CAT/Exam Schedules / upcoming dates across every module.
    $upcomingCatExam = $db->query(
        "SELECT module_id, module_title, cat_date, exam_date FROM modules
         WHERE (cat_date IS NOT NULL AND cat_date >= CURDATE()) OR (exam_date IS NOT NULL AND exam_date >= CURDATE())
         ORDER BY COALESCE(cat_date, exam_date) ASC LIMIT 8"
    )->fetchAll();

    // Student Registration Summary / enrollments per department.
    $registrationSummary = $db->query(
        "SELECT d.department_name, COUNT(e.enrollment_id) AS enrollments
         FROM departments d LEFT JOIN modules m ON m.department_id = d.department_id
         LEFT JOIN module_enrollments e ON e.module_id = m.module_id
         GROUP BY d.department_id ORDER BY enrollments DESC LIMIT 8"
    )->fetchAll();

    // Lecturer Performance Overview / sessions run + average attendance rate per lecturer.
    $lecturerPerformance = $db->query(
        "SELECT u.full_name,
            COUNT(DISTINCT cs.session_id) AS sessions_run,
            SUM(CASE WHEN cal.status IN ('Present','Late') AND cal.attendance_type='Sign In' THEN 1 ELSE 0 END) AS attended,
            COUNT(DISTINCT CASE WHEN cal.attendance_type='Sign In' THEN cal.attendance_id END) AS total_signins
         FROM lecturers l JOIN users u ON u.user_id = l.user_id
         LEFT JOIN modules m ON m.lecturer_id = l.lecturer_id
         LEFT JOIN class_sessions cs ON cs.module_id = m.module_id
         LEFT JOIN class_attendance_logs cal ON cal.session_id = cs.session_id
         GROUP BY l.lecturer_id ORDER BY sessions_run DESC LIMIT 8"
    )->fetchAll();

    $modulesWithoutLecturer = 0; // schema requires lecturer_id NOT NULL, kept for symmetry with the alerts card

    $classAttendanceStatus = $db->query("SELECT status, COUNT(*) AS c FROM class_attendance_logs WHERE attendance_type='Sign In' GROUP BY status")->fetchAll();
    $studentStatusBreakdown = $db->query("SELECT status, COUNT(*) AS c FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Student' GROUP BY status")->fetchAll();
    $modulesByDept = $db->query(
        "SELECT d.department_name, COUNT(m.module_id) AS c FROM departments d
         LEFT JOIN modules m ON m.department_id = d.department_id GROUP BY d.department_id ORDER BY c DESC LIMIT 10"
    )->fetchAll();

} elseif ($role === 'Lecturer') {
    $lecStmt = $db->prepare('SELECT * FROM lecturers WHERE user_id = :uid');
    $lecStmt->execute(['uid' => $user['user_id']]);
    $lecturer = $lecStmt->fetch();
    $ongoingModules = [];
    $completedModules = [];
    if ($lecturer) {
        $modStmt = $db->prepare(
            "SELECT m.*, (SELECT COUNT(*) FROM module_enrollments e WHERE e.module_id = m.module_id) AS student_count
             FROM modules m WHERE m.lecturer_id = :lec ORDER BY m.created_at DESC"
        );
        $modStmt->execute(['lec' => $lecturer['lecturer_id']]);
        $allModules = $modStmt->fetchAll();
        $ongoingModules = array_values(array_filter($allModules, function ($m) { return $m['status'] === 'Ongoing'; }));
        $completedModules = array_values(array_filter($allModules, function ($m) { return $m['status'] === 'Completed'; }));
    }
    $lecClassAttendanceStatus = [];
    $lecSessions14d = 0;
    if ($lecturer) {
        $lecStatusStmt = $db->prepare(
            "SELECT cal.status, COUNT(*) AS c FROM class_attendance_logs cal
             JOIN class_sessions cs ON cs.session_id = cal.session_id
             JOIN modules m ON m.module_id = cs.module_id
             WHERE m.lecturer_id = :lec AND cal.attendance_type = 'Sign In' GROUP BY cal.status"
        );
        $lecStatusStmt->execute(['lec' => $lecturer['lecturer_id']]);
        $lecClassAttendanceStatus = $lecStatusStmt->fetchAll();

        $lecSessionsStmt = $db->prepare(
            "SELECT COUNT(*) FROM class_sessions cs JOIN modules m ON m.module_id = cs.module_id
             WHERE m.lecturer_id = :lec AND cs.start_time >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)"
        );
        $lecSessionsStmt->execute(['lec' => $lecturer['lecturer_id']]);
        $lecSessions14d = (int) $lecSessionsStmt->fetchColumn();
    }

} elseif ($role === 'Registrar') {
    // Redirect Registrar to their own dashboard
    redirect('/registrar/dashboard.php');

} elseif ($role === 'Coordinator') {
    // Redirect Coordinator to their own dashboard
    redirect('/coordinator/dashboard.php');

} else { // Student
    $upcoming = $db->query("SELECT * FROM events WHERE status IN ('Scheduled','Ongoing') ORDER BY event_date LIMIT 6");
    $upcomingEvents = $upcoming->fetchAll();

    $feedStmt = $db->query('SELECT * FROM announcements ORDER BY posted_at DESC LIMIT 6');
    $feed = $feedStmt->fetchAll();

    $unreadStmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0');
    $unreadStmt->execute(['uid' => $user['user_id']]);
    $unreadCount = (int) $unreadStmt->fetchColumn();

    $activeStudentModulesStmt = $db->prepare(
        "SELECT m.*, (SELECT COUNT(*) FROM assignments a WHERE a.module_id = m.module_id AND a.deadline > NOW()
            AND NOT EXISTS (SELECT 1 FROM assignment_submissions s WHERE s.assignment_id = a.assignment_id AND s.user_id = :uid2)) AS pending_assignments
         FROM modules m JOIN module_enrollments e ON e.module_id = m.module_id
         WHERE e.user_id = :uid AND m.status = 'Ongoing'"
    );
    $activeStudentModulesStmt->execute(['uid' => $user['user_id'], 'uid2' => $user['user_id']]);
    $activeStudentModules = $activeStudentModulesStmt->fetchAll();
    $pendingAssignmentsTotal = array_sum(array_column($activeStudentModules, 'pending_assignments'));

    $completedStudentModulesStmt = $db->prepare(
        "SELECT m.*
         FROM modules m
         JOIN module_enrollments e ON e.module_id = m.module_id
         WHERE e.user_id = :uid AND m.status = 'Completed'
         ORDER BY m.end_date DESC, m.module_title"
    );
    $completedStudentModulesStmt->execute(['uid' => $user['user_id']]);
    $completedStudentModules = $completedStudentModulesStmt->fetchAll();
}

require __DIR__ . '/partials/layout_top.php';
?>

<?php if ($role === 'Principal'): ?>
  <h4 class="display-font mb-1">Analytics Dashboard</h4>
  <div class="row g-3 mb-3">
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-mortarboard-fill stat-icon"></i><div class="stat-label">Total Students</div><div class="stat-value"><?= $stats['total_students'] ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-person-workspace stat-icon"></i><div class="stat-label">Total Lecturers</div><div class="stat-value"><?= $stats['total_lecturers'] ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-mortarboard stat-icon"></i><div class="stat-label">Total Heads Of Department</div><div class="stat-value"><?= $stats['total_hods'] ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-person-badge-fill stat-icon"></i><div class="stat-label">Total Deans</div><div class="stat-value"><?= $stats['total_deans'] ?></div></div></div>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-md-6 col-lg-4"><div class="stat-card"><i class="bi bi-building stat-icon"></i><div class="stat-label">Total Departments</div><div class="stat-value"><?= $stats['total_departments'] ?></div></div></div>
    <div class="col-md-6 col-lg-4"><div class="stat-card"><i class="bi bi-person-check-fill stat-icon"></i><div class="stat-label">Active Users</div><div class="stat-value"><?= $stats['active_users'] ?></div></div></div>
    <div class="col-md-6 col-lg-4">
      <a href="<?= APP_URL ?>/admin/suggestions.php" class="text-decoration-none text-reset">
        <div class="stat-card"><i class="bi bi-chat-left-text stat-icon"></i><div class="stat-label">Pending Suggestions</div><div class="stat-value"><?= $pendingSuggestions ?></div></div>
      </a>
    </div>
  </div>
  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="stat-card">
        <div class="stat-label">Storage Usage (uploads volume)</div>
        <div class="stat-value"><?= $diskUsedPct ?>%</div>
        <div class="progress mt-2" style="height:6px;"><div class="progress-bar" style="width:<?= $diskUsedPct ?>%;background-color:var(--semas-gold);"></div></div>
        <div class="text-muted small mt-1"><?= round($diskFree / 1073741824, 1) ?> GB free of <?= round($diskTotal / 1073741824, 1) ?> GB</div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="stat-card"><i class="bi bi-broadcast stat-icon" style="color:var(--semas-success);"></i><div class="stat-label">System Status</div><div class="stat-value" style="font-size:1.3rem;"><?= e($systemStatus) ?></div></div>
    </div>
  </div>

  <div class="semas-card p-3 mb-4">
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-semas-gold btn-sm"><i class="bi bi-people me-1"></i> Manage Users</a>
      <a href="<?= APP_URL ?>/admin/audit-log.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-clock-history me-1"></i> Audit Log</a>
      <a href="<?= APP_URL ?>/admin/settings.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-gear me-1"></i> Settings &amp; Branding</a>
      <a href="<?= APP_URL ?>/admin/suggestions.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-chat-left-text me-1"></i> Suggestion Box (<?= $pendingSuggestions ?>)</a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-2">Users by Role</h6>
        <?php foreach ($usersByRole as $r): ?>
          <div class="d-flex justify-content-between border-bottom py-1 small"><span><?= e(role_display_name($r['role_name'])) ?></span><span class="fw-semibold"><?= (int) $r['c'] ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="semas-card p-3 h-100"><div class="stat-label">New Accounts / Last 14 Days</div><div class="stat-value"><?= $newAccounts14d ?></div></div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Recent Logins</h6>
        <?php if (!$recentLogins): ?><p class="text-muted small mb-0">No logins recorded yet.</p><?php else: ?>
          <table class="table table-sm align-middle">
            <thead><tr><th>Name</th><th>Role</th><th>Last Login</th></tr></thead>
            <tbody><?php foreach ($recentLogins as $l): ?>
              <tr><td><?= e($l['full_name']) ?></td><td><span class="badge bg-light text-dark border"><?= e(role_display_name($l['role_name'])) ?></span></td><td><?= e(date('d M Y H:i', strtotime($l['last_login_at']))) ?></td></tr>
            <?php endforeach; ?></tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Recent Announcements (system-wide visibility)</h6>
        <?php if (!$recentAnnouncementsAdmin): ?><p class="text-muted small mb-0">No announcements yet.</p><?php else: foreach ($recentAnnouncementsAdmin as $a): ?>
          <div class="border-bottom py-2"><div class="fw-semibold small"><?= e($a['title']) ?></div><div class="text-muted" style="font-size:0.75rem;">By <?= e($a['sender_name'] ?? '/') ?> (<?= e($a['sender_role'] ?? '/') ?>) &middot; <?= e($a['target_audience']) ?></div></div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <div class="semas-card p-3 mt-3">
    <h6 class="display-font mb-3">Recently Created Staff Accounts</h6>
    <?php if (!$recentStaff): ?><p class="text-muted small mb-0">No staff accounts created yet.</p><?php else: ?>
      <table class="table table-sm align-middle">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th></tr></thead>
        <tbody><?php foreach ($recentStaff as $s): ?>
          <tr><td><?= e($s['full_name']) ?></td><td><?= e($s['email']) ?></td><td><span class="badge bg-light text-dark border"><?= e(role_display_name($s['role_name'])) ?></span></td><td><?= e(date('d M Y', strtotime($s['created_at']))) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table>
    <?php endif; ?>
  </div>

<?php elseif ($role === 'Dean'): ?>
  <h4 class="display-font mb-1">Dashboard</h4>
  <div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-mortarboard-fill stat-icon"></i><div class="stat-label">Total Students</div><div class="stat-value"><?= $stats['total_students'] ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-person-check-fill stat-icon"></i><div class="stat-label">Active Students</div><div class="stat-value"><?= $stats['active_students'] ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-hourglass-split stat-icon"></i><div class="stat-label">Pending Students</div><div class="stat-value"><?= $stats['pending_students'] ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-calendar-event-fill stat-icon"></i><div class="stat-label">Total Events</div><div class="stat-value"><?= $stats['total_events'] ?></div></div></div>
  </div>
  <div class="semas-card p-3 mb-4">
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= APP_URL ?>/dean/announcements.php" class="btn btn-semas-gold btn-sm"><i class="bi bi-megaphone-fill me-1"></i> Send Student Announcement</a>
      <a href="<?= APP_URL ?>/admin/events.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-calendar-event me-1"></i> Event Management</a>
    </div>
  </div>
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-2">Highly Attended Events</h6>
        <?php if (!$highAttendanceEvents): ?>
          <p class="text-muted small mb-0">No event attendance has been recorded yet.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>Event</th><th>Date</th><th class="text-end">Attendees</th></tr></thead>
              <tbody>
                <?php foreach ($highAttendanceEvents as $event): ?>
                  <tr>
                    <td><strong><?= e($event['title']) ?></strong><div class="text-muted small"><?= e($event['venue'] ?: '-') ?></div></td>
                    <td class="text-nowrap"><?= e(date('d M Y', strtotime($event['event_date']))) ?></td>
                    <td class="text-end fw-semibold"><?= (int) $event['attendee_count'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-lg-6"><div class="semas-card p-3 h-100"><div class="stat-label">Event Check-ins / Last 7 Days</div><div class="stat-value"><?= $eventCheckins7d ?></div></div></div>
    <div class="col-12">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Recent Announcements</h6>
        <?php if (!$recentAnnouncements): ?><p class="text-muted small mb-0">No announcements yet.</p><?php else: foreach ($recentAnnouncements as $a): ?>
          <div class="border-bottom py-2"><div class="fw-semibold small"><?= e($a['title']) ?></div><div class="text-muted" style="font-size:0.78rem;"><?= e(mb_substr($a['message'], 0, 90)) ?></div></div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

<?php elseif ($role === 'HOD'): ?>
  <h4 class="display-font mb-1">Dashboard</h4>
  <div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-journal-bookmark-fill stat-icon"></i><div class="stat-label">Total Modules <small class="text-muted d-block" style="font-size:.7rem;">This Month</small></div><div class="stat-value"><?= $stats['total_modules'] ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-play-circle-fill stat-icon"></i><div class="stat-label">Ongoing Modules <small class="text-muted d-block" style="font-size:.7rem;">This Month</small></div><div class="stat-value"><?= $stats['ongoing_modules'] ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-check-circle-fill stat-icon"></i><div class="stat-label">Completed Modules <small class="text-muted d-block" style="font-size:.7rem;">This Month</small></div><div class="stat-value"><?= $stats['completed_modules'] ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-building stat-icon"></i><div class="stat-label">Departments / Lecturers</div><div class="stat-value"><?= $stats['total_departments'] ?> / <?= $stats['total_lecturers'] ?></div></div></div>
  </div>


  <div class="semas-card p-3 mb-4">
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= APP_URL ?>/hod/modules.php" class="btn btn-semas-gold btn-sm"><i class="bi bi-journal-plus me-1"></i> Manage Modules</a>
      <a href="<?= APP_URL ?>/hod/eligibility.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-clipboard2-check me-1"></i> CAT/Exam Eligibility</a>
      <a href="<?= APP_URL ?>/hod/holidays.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-calendar-x me-1"></i> Holidays &amp; Umuganda</a>
      <a href="<?= APP_URL ?>/hod/announcements.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-megaphone me-1"></i> Academic Announcements</a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-2">Class Attendance Breakdown (University-wide)</h6>
        <?php if (!$classAttendanceStatus): ?><p class="text-muted small mb-0">No attendance recorded yet.</p><?php endif; ?>
        <?php foreach ($classAttendanceStatus as $r): ?>
          <div class="d-flex justify-content-between border-bottom py-1 small"><span><?= e($r['status']) ?></span><span class="fw-semibold"><?= (int) $r['c'] ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-2">Student Account Status</h6>
        <?php foreach ($studentStatusBreakdown as $r): ?>
          <div class="d-flex justify-content-between border-bottom py-1 small"><span><?= e($r['status']) ?></span><span class="fw-semibold"><?= (int) $r['c'] ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-12">
      <div class="semas-card p-3">
        <h6 class="display-font mb-2">Modules by Department</h6>
        <?php if (!$modulesByDept): ?><p class="text-muted small mb-0">No departments yet.</p><?php endif; ?>
        <div class="row g-2">
          <?php foreach ($modulesByDept as $r): ?>
            <div class="col-md-3 col-6"><div class="d-flex justify-content-between border-bottom py-1 small"><span><?= e($r['department_name']) ?></span><span class="fw-semibold"><?= (int) $r['c'] ?></span></div></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">CAT / Exam Schedules (Upcoming)</h6>
        <?php if (!$upcomingCatExam): ?><p class="text-muted small mb-0">No CAT/Exam dates scheduled in the near future.</p><?php else: ?>
          <table class="table table-sm">
            <thead><tr><th>Module</th><th>CAT</th><th>Exam</th></tr></thead>
            <tbody><?php foreach ($upcomingCatExam as $row): ?>
              <tr><td><?= e($row['module_title']) ?></td><td><?= e($row['cat_date'] ?? '/') ?></td><td><?= e($row['exam_date'] ?? '/') ?></td></tr>
            <?php endforeach; ?></tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Student Registration Summary</h6>
        <?php if (!$registrationSummary): ?><p class="text-muted small mb-0">No module registrations yet.</p><?php else: ?>
          <table class="table table-sm">
            <thead><tr><th>Department</th><th>Enrollments</th></tr></thead>
            <tbody><?php foreach ($registrationSummary as $row): ?>
              <tr><td><?= e($row['department_name']) ?></td><td><?= (int) $row['enrollments'] ?></td></tr>
            <?php endforeach; ?></tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Lecturer Performance Overview</h6>
        <?php if (!$lecturerPerformance): ?><p class="text-muted small mb-0">No lecturer activity yet.</p><?php else: ?>
          <table class="table table-sm align-middle">
            <thead><tr><th>Lecturer</th><th>Sessions Run</th><th>Attendance Rate</th></tr></thead>
            <tbody><?php foreach ($lecturerPerformance as $row): $rate = (int) $row['total_signins'] > 0 ? round((int) $row['attended'] / (int) $row['total_signins'] * 100) : 0; ?>
              <tr><td><?= e($row['full_name']) ?></td><td><?= (int) $row['sessions_run'] ?></td><td><span class="badge <?= $rate >= 75 ? 'badge-completed' : 'badge-urgent' ?>"><?= $rate ?>%</span></td></tr>
            <?php endforeach; ?></tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Recently Created Modules</h6>
        <?php if (!$recentModules): ?><p class="text-muted small mb-0">No modules yet. <a href="<?= APP_URL ?>/hod/modules.php">Create one</a>.</p><?php else: ?>
          <table class="table table-sm align-middle">
            <thead><tr><th>Module</th><th>Department</th><th>Lecturer</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($recentModules as $m): ?>
              <tr><td><?= e($m['module_title']) ?></td><td><?= e($m['department_name'] ?? '/') ?></td><td><?= e($m['lecturer_name'] ?? '/') ?></td><td><span class="badge <?= $m['status'] === 'Ongoing' ? 'badge-completed' : 'bg-secondary' ?>"><?= e($m['status']) ?></span></td></tr>
            <?php endforeach; ?></tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php elseif ($role === 'Lecturer'): ?>
  <h4 class="display-font mb-1">Dashboard</h4>
  <div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-play-circle-fill stat-icon"></i><div class="stat-label">Ongoing Modules</div><div class="stat-value"><?= count($ongoingModules) ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-check-circle-fill stat-icon"></i><div class="stat-label">Completed Modules</div><div class="stat-value"><?= count($completedModules) ?></div></div></div>
  </div>
  <div class="semas-card p-3 mb-4">
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= APP_URL ?>/lecturer/modules.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-journal-bookmark me-1"></i> All My Modules</a>
    </div>
  </div>

  <h6 class="display-font mb-2">Ongoing Modules</h6>
  <div class="row g-3 mb-4">
    <?php foreach ($ongoingModules as $m): ?>
      <div class="col-md-4">
        <div class="semas-card p-3 h-100">
          <h6 class="fw-semibold mb-1"><?= e($m['module_title']) ?></h6>
          <p class="text-muted small mb-2"><?= (int) $m['student_count'] ?> student(s) registered &middot; <?= e($m['session_type'] ?? 'Any') ?></p>
          <div class="d-flex flex-wrap gap-1">
            <a href="<?= APP_URL ?>/lecturer/live-session.php?module_id=<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-semas">Manage Attendance</a>
            <a href="<?= APP_URL ?>/lecturer/announcements.php?module_id=<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-outline-dark">Announce</a>
            <a href="<?= APP_URL ?>/lecturer/assignments.php?module_id=<?= (int) $m['module_id'] ?>" class="btn btn-sm btn-outline-dark">Assignments</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$ongoingModules): ?><div class="col-12"><div class="semas-card p-4 text-center text-muted small">No ongoing modules assigned to you yet.</div></div><?php endif; ?>
  </div>

  <h6 class="display-font mb-2">Completed Modules</h6>
  <div class="row g-3 mb-4">
    <?php foreach ($completedModules as $m): ?>
      <div class="col-md-4">
        <div class="semas-card p-3 h-100">
          <h6 class="fw-semibold mb-1"><?= e($m['module_title']) ?></h6>
          <p class="text-muted small mb-0"><?= (int) $m['student_count'] ?> student(s) registered</p>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$completedModules): ?><div class="col-12"><div class="semas-card p-4 text-center text-muted small">No completed modules yet.</div></div><?php endif; ?>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-2">My Class Attendance Breakdown</h6>
        <?php if (!$lecClassAttendanceStatus): ?><p class="text-muted small mb-0">No attendance recorded yet.</p><?php endif; ?>
        <?php foreach ($lecClassAttendanceStatus as $r): ?>
          <div class="d-flex justify-content-between border-bottom py-1 small"><span><?= e($r['status']) ?></span><span class="fw-semibold"><?= (int) $r['c'] ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-lg-6"><div class="semas-card p-3 h-100"><div class="stat-label">Sessions Run / Last 14 Days</div><div class="stat-value"><?= $lecSessions14d ?></div></div></div>
  </div>

<?php else: /* Student */ ?>
  <h4 class="display-font mb-1">Dashboard</h4>
  <div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-journal-bookmark-fill stat-icon"></i><div class="stat-label">Completed Modules</div><div class="stat-value"><?= count($completedStudentModules) ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-file-earmark-text-fill stat-icon"></i><div class="stat-label">Pending Assignments</div><div class="stat-value"><?= (int) $pendingAssignmentsTotal ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-calendar-event-fill stat-icon"></i><div class="stat-label">Upcoming Events</div><div class="stat-value"><?= count($upcomingEvents) ?></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="stat-card"><i class="bi bi-bell-fill stat-icon"></i><div class="stat-label">Unread Notifications</div><div class="stat-value"><?= $unreadCount ?></div></div></div>
  </div>

  <div class="semas-card p-3 mb-4">
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= APP_URL ?>/student/modules.php" class="btn btn-semas-gold btn-sm"><i class="bi bi-journal-plus me-1"></i> Module Registration</a>
      <a href="<?= APP_URL ?>/student/events.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-calendar-event me-1"></i> Browse Events</a>
      <a href="<?= APP_URL ?>/student/attendance.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-qr-code-scan me-1"></i> Class Attendance</a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Completed Modules</h6>
        <?php if (!$completedStudentModules): ?>
          <p class="text-muted small mb-0">You have no completed modules yet.</p>
        <?php else: ?>
          <table class="table table-sm align-middle">
            <thead><tr><th>Module</th><th>Session</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($completedStudentModules as $m): ?>
              <tr>
                <td><?= e($m['module_title']) ?></td>
                <td><?= e($m['session_type'] ?? '-') ?></td>
                <td><span class="badge badge-completed">Completed</span></td>
              </tr>
            <?php endforeach; ?></tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Upcoming Events</h6>
        <?php if (!$upcomingEvents): ?><p class="text-muted small">No upcoming events right now.</p>
        <?php else: foreach ($upcomingEvents as $ev): ?>
          <div class="d-flex justify-content-between align-items-center border-bottom py-2">
            <div><div class="fw-semibold small"><?= e($ev['title']) ?></div><div class="text-muted" style="font-size:0.75rem;"><?= e($ev['event_date']) ?>, <?= e($ev['start_time']) ?> &middot; <?= e($ev['venue']) ?></div></div>
            <span class="badge badge-upcoming"><?= e($ev['status']) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="semas-card p-3">
        <h6 class="display-font mb-3">Recent Announcements</h6>
        <?php if (!$feed): ?><p class="text-muted small">No announcements yet.</p>
        <?php else: foreach ($feed as $a): ?>
          <div class="border-bottom py-2">
            <div class="fw-semibold small"><?php if (in_array($a['priority'], ['Urgent', 'High'], true)): ?><span class="badge badge-urgent me-1"><?= e($a['priority']) ?></span><?php endif; ?><?= e($a['title']) ?></div>
            <div class="text-muted" style="font-size:0.78rem;"><?= e(mb_substr($a['message'], 0, 90)) ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout_bottom.php'; ?>
