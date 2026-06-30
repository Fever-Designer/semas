<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}
csrf_verify();

$db         = Database::connection();
$me         = Auth::user();
$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$action     = $_POST['action'] ?? ''; // sign_in | sign_out | submit | search | preview

// Resolve invigilator's lecturer_id
$lecStmt = $db->prepare('SELECT lecturer_id FROM lecturers WHERE user_id = :uid');
$lecStmt->execute(['uid' => $me['user_id']]);
$lecturer = $lecStmt->fetch();
if (!$lecturer) {
    echo json_encode(['ok' => false, 'message' => 'Lecturer profile not found.']);
    exit;
}

// Load and authorise schedule
$schedStmt = $db->prepare(
    "SELECT cs.*, m.module_title, m.module_id, m.session_type, m.department_id,
            u.full_name AS invigilator_name
     FROM cat_exam_schedules cs
     JOIN modules m ON m.module_id = cs.module_id
     JOIN lecturers l ON l.lecturer_id = cs.invigilator_id
     JOIN users u ON u.user_id = l.user_id
     WHERE cs.schedule_id = :id"
);
$schedStmt->execute(['id' => $scheduleId]);
$schedule = $schedStmt->fetch();

if (!$schedule || (int) $schedule['invigilator_id'] !== (int) $lecturer['lecturer_id']) {
    echo json_encode(['ok' => false, 'message' => 'Schedule not found or you are not the assigned invigilator.']);
    exit;
}

// Block all changes once submitted
$submittedStmt = $db->prepare('SELECT submission_id FROM cat_exam_submissions WHERE schedule_id = :id');
$submittedStmt->execute(['id' => $scheduleId]);
$alreadySubmitted = (bool) $submittedStmt->fetch();

// ── Student lookup (search + preview in one call) ────────────────────────
if ($action === 'lookup') {
    $regNum = trim($_POST['reg_number'] ?? '');
    if ($regNum === '') { echo json_encode(['ok' => false, 'message' => 'Enter a registration number.']); exit; }

    // Try exact reg number match first (fastest path)
    $exact = $db->prepare(
        "SELECT u.user_id FROM users u
         JOIN roles r ON r.role_id = u.role_id
         JOIN module_enrollments me ON me.user_id = u.user_id AND me.module_id = :mid
         WHERE r.role_name = 'Student' AND u.reg_number = :reg LIMIT 1"
    );
    $exact->execute(['mid' => $schedule['module_id'], 'reg' => $regNum]);
    $exactRow = $exact->fetch();
    if ($exactRow) {
        $payload = cat_student_payload($db, $schedule, $scheduleId, (int) $exactRow['user_id']);
        $payload['single'] = true;
        echo json_encode($payload);
        exit;
    }

    // Partial match fallback
    $partial = $db->prepare(
        "SELECT u.user_id, u.full_name, u.reg_number FROM users u
         JOIN roles r ON r.role_id = u.role_id
         JOIN module_enrollments me ON me.user_id = u.user_id AND me.module_id = :mid
         WHERE r.role_name = 'Student' AND (u.full_name LIKE :q OR u.reg_number LIKE :q) LIMIT 10"
    );
    $partial->execute(['mid' => $schedule['module_id'], 'q' => "%$regNum%"]);
    $results = $partial->fetchAll();

    if (count($results) === 0) {
        echo json_encode(['ok' => false, 'message' => "No enrolled student found matching \"$regNum\"."]);
        exit;
    }
    if (count($results) === 1) {
        $payload = cat_student_payload($db, $schedule, $scheduleId, (int) $results[0]['user_id']);
        $payload['single'] = true;
        echo json_encode($payload);
        exit;
    }
    echo json_encode(['ok' => true, 'single' => false, 'results' => $results]);
    exit;
}

// ── Student search (name / reg, returns list only) ────────────────────────
if ($action === 'search') {
    $q = trim($_POST['q'] ?? '');
    if ($q === '') { echo json_encode(['ok' => true, 'results' => []]); exit; }
    $stmt = $db->prepare(
        "SELECT u.user_id, u.full_name, u.reg_number FROM users u
         JOIN roles r ON r.role_id = u.role_id
         JOIN module_enrollments me ON me.user_id = u.user_id AND me.module_id = :mid
         WHERE r.role_name = 'Student' AND (u.full_name LIKE :q OR u.reg_number LIKE :q) LIMIT 10"
    );
    $stmt->execute(['mid' => $schedule['module_id'], 'q' => "%$q%"]);
    echo json_encode(['ok' => true, 'results' => $stmt->fetchAll()]);
    exit;
}

// ── Student preview ───────────────────────────────────────────────────────
if ($action === 'preview') {
    $studentUserId = (int) ($_POST['user_id'] ?? 0);
    echo json_encode(cat_student_payload($db, $schedule, $scheduleId, $studentUserId));
    exit;
}

// ── Sign In ───────────────────────────────────────────────────────────────
if ($action === 'sign_in') {
    if ($alreadySubmitted) { echo json_encode(['ok' => false, 'message' => 'Attendance has already been submitted.']); exit; }
    $studentUserId = (int) ($_POST['user_id'] ?? 0);

    // Verify enrolled + eligible
    $eligCheck = cat_verify_student($db, $schedule, $studentUserId);
    if (!$eligCheck['ok']) { echo json_encode($eligCheck); exit; }

    // Block if already signed in
    $existing = $db->prepare("SELECT cat_attendance_id FROM cat_exam_attendance_logs WHERE schedule_id = :s AND user_id = :u AND attendance_type = 'Sign In'");
    $existing->execute(['s' => $scheduleId, 'u' => $studentUserId]);
    if ($existing->fetch()) {
        echo json_encode(['ok' => false, 'message' => $eligCheck['student']['full_name'] . ' is already signed in.']);
        exit;
    }

    $db->prepare(
        "INSERT INTO cat_exam_attendance_logs (schedule_id, user_id, attendance_type, recorded_at, recorded_by, status)
         VALUES (:sid, :uid, 'Sign In', NOW(), :by, 'Present')"
    )->execute(['sid' => $scheduleId, 'uid' => $studentUserId, 'by' => $me['user_id']]);

    AuditLog::record(Auth::id(), 'CAT_EXAM_SIGNIN', 'cat_exam_schedules', $scheduleId, "student_user_id=$studentUserId");
    NotificationCenter::notify($studentUserId, 'Signed in to ' . $schedule['exam_type'], 'You have been signed in for "' . $schedule['module_title'] . '" ' . $schedule['exam_type'] . '.', 'Attendance');
    echo json_encode(['ok' => true, 'message' => $eligCheck['student']['full_name'] . ' signed in.', 'student' => $eligCheck['student']]);
    exit;
}

// ── Sign Out ──────────────────────────────────────────────────────────────
if ($action === 'sign_out') {
    if ($alreadySubmitted) { echo json_encode(['ok' => false, 'message' => 'Attendance has already been submitted.']); exit; }
    $studentUserId = (int) ($_POST['user_id'] ?? 0);

    // Must have signed in first
    $signInRow = $db->prepare("SELECT recorded_at FROM cat_exam_attendance_logs WHERE schedule_id = :s AND user_id = :u AND attendance_type = 'Sign In'");
    $signInRow->execute(['s' => $scheduleId, 'u' => $studentUserId]);
    $sinRecord = $signInRow->fetch();
    if (!$sinRecord) {
        $nameRow = $db->prepare('SELECT full_name FROM users WHERE user_id = :id');
        $nameRow->execute(['id' => $studentUserId]);
        $name = $nameRow->fetchColumn() ?: 'This student';
        echo json_encode(['ok' => false, 'message' => "$name has not signed in yet."]);
        exit;
    }

    // Block duplicate sign-out
    $existOut = $db->prepare("SELECT cat_attendance_id FROM cat_exam_attendance_logs WHERE schedule_id = :s AND user_id = :u AND attendance_type = 'Sign Out'");
    $existOut->execute(['s' => $scheduleId, 'u' => $studentUserId]);
    if ($existOut->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'This student has already signed out.']);
        exit;
    }

    $db->prepare(
        "INSERT INTO cat_exam_attendance_logs (schedule_id, user_id, attendance_type, recorded_at, recorded_by, status)
         VALUES (:sid, :uid, 'Sign Out', NOW(), :by, 'Present')"
    )->execute(['sid' => $scheduleId, 'uid' => $studentUserId, 'by' => $me['user_id']]);

    $nameRow = $db->prepare('SELECT full_name FROM users WHERE user_id = :id');
    $nameRow->execute(['id' => $studentUserId]);
    $name = $nameRow->fetchColumn() ?: 'Student';

    AuditLog::record(Auth::id(), 'CAT_EXAM_SIGNOUT', 'cat_exam_schedules', $scheduleId, "student_user_id=$studentUserId");
    NotificationCenter::notify($studentUserId, 'Signed out from ' . $schedule['exam_type'], 'You have been signed out from "' . $schedule['module_title'] . '" ' . $schedule['exam_type'] . '. You may now generate your Attendance Slip.', 'Attendance');
    echo json_encode(['ok' => true, 'message' => "$name signed out."]);
    exit;
}

// ── Submit full attendance list ───────────────────────────────────────────
if ($action === 'submit') {
    if ($alreadySubmitted) { echo json_encode(['ok' => false, 'message' => 'Attendance for this schedule has already been submitted.']); exit; }

    // Collect reasons for students who signed in but have no sign-out
    $missingSignouts = (array) json_decode($_POST['missing_signouts'] ?? '[]', true);
    // Format: [{ user_id, reason, notes }, ...]

    $db->beginTransaction();
    try {
        // For each missing sign-out provided, insert an 'Absent' sign-out with reason
        foreach ($missingSignouts as $ms) {
            $msUserId = (int) ($ms['user_id'] ?? 0);
            $msReason = in_array($ms['reason'] ?? '', ['Cheating', 'Sickness', 'Other'], true) ? $ms['reason'] : 'Other';
            $msNotes  = substr(trim($ms['notes'] ?? ''), 0, 500);
            if (!$msUserId) continue;

            // Only insert if they have a sign-in but no sign-out
            $chk = $db->prepare("SELECT 1 FROM cat_exam_attendance_logs WHERE schedule_id = :s AND user_id = :u AND attendance_type = 'Sign Out'");
            $chk->execute(['s' => $scheduleId, 'u' => $msUserId]);
            if ($chk->fetch()) continue; // already signed out

            $db->prepare(
                "INSERT IGNORE INTO cat_exam_attendance_logs (schedule_id, user_id, attendance_type, recorded_at, recorded_by, status, missed_reason, missed_notes)
                 VALUES (:sid, :uid, 'Sign Out', NOW(), :by, 'Absent', :reason, :notes)"
            )->execute(['sid' => $scheduleId, 'uid' => $msUserId, 'by' => $me['user_id'], 'reason' => $msReason, 'notes' => $msNotes]);
        }

        // Record the formal submission
        $db->prepare(
            "INSERT INTO cat_exam_submissions (schedule_id, submitted_by, notes)
             VALUES (:sid, :by, :notes)"
        )->execute(['sid' => $scheduleId, 'by' => $me['user_id'], 'notes' => trim($_POST['submission_notes'] ?? '')]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // If this was an Exam, automatically mark the module as Completed
    if ($schedule['exam_type'] === 'Exam') {
        $db->prepare("UPDATE modules SET status = 'Completed' WHERE module_id = :mid AND status = 'Ongoing'")
           ->execute(['mid' => $schedule['module_id']]);
    }

    AuditLog::record(Auth::id(), 'CAT_EXAM_SUBMIT', 'cat_exam_schedules', $scheduleId);
    echo json_encode(['ok' => true, 'message' => 'Attendance list submitted to HOD successfully.']);
    exit;
}

// ── Live roster ───────────────────────────────────────────────────────────
if ($action === 'roster') {
    $roster = $db->prepare(
        "SELECT u.user_id, u.full_name, u.reg_number,
                sin.recorded_at AS signin_time,
                sout.recorded_at AS signout_time,
                sout.status AS signout_status,
                sout.missed_reason
         FROM module_enrollments e
         JOIN users u ON u.user_id = e.user_id
         LEFT JOIN cat_exam_attendance_logs sin  ON sin.schedule_id  = :sid  AND sin.user_id  = e.user_id AND sin.attendance_type  = 'Sign In'
         LEFT JOIN cat_exam_attendance_logs sout ON sout.schedule_id = :sid2 AND sout.user_id = e.user_id AND sout.attendance_type = 'Sign Out'
         WHERE e.module_id = :mid
         ORDER BY u.full_name"
    );
    $roster->execute(['sid' => $scheduleId, 'sid2' => $scheduleId, 'mid' => $schedule['module_id']]);
    echo json_encode(['ok' => true, 'roster' => $roster->fetchAll(), 'submitted' => $alreadySubmitted]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action.']);

// ── Helpers ───────────────────────────────────────────────────────────────
function cat_verify_student(PDO $db, array $schedule, int $userId): array
{
    $stmt = $db->prepare(
        "SELECT u.*, d.department_name FROM users u
         LEFT JOIN departments d ON d.department_id = u.department_id
         JOIN roles r ON r.role_id = u.role_id
         JOIN module_enrollments me ON me.user_id = u.user_id AND me.module_id = :mid
         WHERE u.user_id = :id AND r.role_name = 'Student'"
    );
    $stmt->execute(['mid' => $schedule['module_id'], 'id' => $userId]);
    $student = $stmt->fetch();
    if (!$student) {
        return ['ok' => false, 'message' => 'This student is not registered for "' . $schedule['module_title'] . '".'];
    }
    $eligStmt = $db->prepare(
        "SELECT final_decision, hod_decision FROM cat_exam_eligibility
         WHERE module_id = :mid AND user_id = :uid AND exam_type = :type"
    );
    $eligStmt->execute(['mid' => $schedule['module_id'], 'uid' => $userId, 'type' => $schedule['exam_type']]);
    $elig = $eligStmt->fetch();
    $allowed  = $elig && $elig['hod_decision'] !== 'Pending' && $elig['final_decision'] === 'Allowed';
    $photoUrl = $student['photo_path']
        ? APP_URL . '/' . $student['photo_path']
        : 'https://ui-avatars.com/api/?name=' . urlencode($student['full_name']) . '&background=1E2A52&color=fff';
    return [
        'ok'       => true,
        'student'  => [
            'user_id'    => (int) $student['user_id'],
            'full_name'  => $student['full_name'],
            'reg_number' => $student['reg_number'],
            'department' => $student['department_name'],
            'photo_url'  => $photoUrl,
        ],
        'eligible' => $allowed,
        'elig_status' => $elig ? $elig['final_decision'] : 'Not generated',
    ];
}

function cat_student_payload(PDO $db, array $schedule, int $scheduleId, int $userId): array
{
    $result = cat_verify_student($db, $schedule, $userId);
    if (!$result['ok']) return $result;

    $sinRow = $db->prepare("SELECT recorded_at FROM cat_exam_attendance_logs WHERE schedule_id = :s AND user_id = :u AND attendance_type = 'Sign In'");
    $sinRow->execute(['s' => $scheduleId, 'u' => $userId]);
    $sin = $sinRow->fetch();

    $soutRow = $db->prepare("SELECT recorded_at FROM cat_exam_attendance_logs WHERE schedule_id = :s AND user_id = :u AND attendance_type = 'Sign Out'");
    $soutRow->execute(['s' => $scheduleId, 'u' => $userId]);
    $sout = $soutRow->fetch();

    $result['signed_in']   = (bool) $sin;
    $result['signed_out']  = (bool) $sout;
    $result['signin_time'] = $sin  ? date('h:i A', strtotime($sin['recorded_at']))  : null;
    $result['signout_time']= $sout ? date('h:i A', strtotime($sout['recorded_at'])) : null;
    return $result;
}
