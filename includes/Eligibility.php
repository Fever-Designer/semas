<?php
declare(strict_types=1);

/**
 * Eligibility
 * -----------
 * CAT/Exam eligibility is driven by how many classes a student missed
 * before the relevant cutoff:
 *   - CAT eligibility looks at sessions held BEFORE the module's cat_date.
 *   - Exam eligibility looks at sessions held BETWEEN cat_date (exclusive)
 *     and exam_date (exclusive) — i.e. "missed 2 times after CAT day".
 * "Missed" means the session has no attendance row for that student at
 * all, OR the row's status is 'Absent' (Late/Present both count as
 * attended). 2 or more missed sessions -> system decision 'Not Allowed'.
 *
 * The system's decision is only a recommendation: HOD must explicitly
 * generate the list (writing system_decision), and every row starts
 * hod_decision='Pending' until the HOD Approves (final = system) or
 * Overrides (final = HOD's explicit choice, with a reason) it.
 *
 * The system marks students Allowed when attendance is at least 70%
 * of the relevant sessions; otherwise Not Allowed.
 * Slips are only ever issued for rows where final_decision = 'Allowed'.
 */
final class Eligibility
{
    /**
     * (Re)generates the eligibility list for one module + exam type. Safe to
     * call repeatedly — it overwrites system_decision/absences_count for any
     * row that is still 'Pending' (an HOD's Approved/Overridden decision is
     * never silently recomputed away).
     */
    public static function generate(int $moduleId, string $examType): int
    {
        $db = Database::connection();

        $modStmt = $db->prepare('SELECT * FROM modules WHERE module_id = :id');
        $modStmt->execute(['id' => $moduleId]);
        $module = $modStmt->fetch();
        if (!$module) {
            return 0;
        }

        if ($examType === 'CAT') {
            $cutoffWhere = 'cs.session_date < :cat';
            $params = ['cat' => $module['cat_date']];
        } else {
            $cutoffWhere = 'cs.session_date > :cat AND cs.session_date < :exam';
            $params = ['cat' => $module['cat_date'], 'exam' => $module['exam_date']];
        }

        $totalSessionsStmt = $db->prepare("SELECT COUNT(*) FROM class_sessions cs WHERE cs.module_id = :mid AND $cutoffWhere");
        $totalSessionsStmt->execute(array_merge(['mid' => $moduleId], $params));
        $totalSessions = (int) $totalSessionsStmt->fetchColumn();

        $studentsStmt = $db->prepare('SELECT user_id FROM module_enrollments WHERE module_id = :mid');
        $studentsStmt->execute(['mid' => $moduleId]);
        $studentIds = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);

        $generated = 0;
        foreach ($studentIds as $userId) {
            $missedStmt = $db->prepare(
                "SELECT COUNT(*) FROM class_sessions cs
                 LEFT JOIN class_attendance_logs cal ON cal.session_id = cs.session_id AND cal.user_id = :uid AND cal.attendance_type = 'Sign In'
                 WHERE cs.module_id = :mid AND $cutoffWhere AND (cal.attendance_id IS NULL OR cal.status = 'Absent')"
            );
            $missedStmt->execute(array_merge(['uid' => $userId, 'mid' => $moduleId], $params));
            $missed = (int) $missedStmt->fetchColumn();

            $attendancePct = $totalSessions === 0 ? 100 : (int) round((($totalSessions - $missed) / max(1, $totalSessions)) * 100);
            $systemDecision = $attendancePct >= 70 ? 'Allowed' : 'Not Allowed';

            $existing = $db->prepare('SELECT hod_decision, final_decision FROM cat_exam_eligibility WHERE module_id = :mid AND user_id = :uid AND exam_type = :type');
            $existing->execute(['mid' => $moduleId, 'uid' => $userId, 'type' => $examType]);
            $existingRow = $existing->fetch();

            if ($existingRow && $existingRow['hod_decision'] !== 'Pending') {
                // Don't overwrite an HOD decision that's already Approved/Overridden —
                // just refresh the absence count for visibility.
                $db->prepare('UPDATE cat_exam_eligibility SET absences_count = :missed, system_decision = :sys WHERE module_id = :mid AND user_id = :uid AND exam_type = :type')
                   ->execute(['missed' => $missed, 'sys' => $systemDecision, 'mid' => $moduleId, 'uid' => $userId, 'type' => $examType]);
            } else {
                $db->prepare(
                    'INSERT INTO cat_exam_eligibility (module_id, user_id, exam_type, absences_count, system_decision, hod_decision, final_decision)
                     VALUES (:mid, :uid, :type, :missed, :sys, \'Pending\', :sys2)
                     ON DUPLICATE KEY UPDATE absences_count = :missed2, system_decision = :sys3, final_decision = :sys4'
                )->execute([
                    'mid' => $moduleId, 'uid' => $userId, 'type' => $examType, 'missed' => $missed, 'sys' => $systemDecision, 'sys2' => $systemDecision,
                    'missed2' => $missed, 'sys3' => $systemDecision, 'sys4' => $systemDecision,
                ]);
            }
            $generated++;
        }

        return $generated;
    }

    /** A student's final eligibility for a module + exam type, or null if never generated. */
    public static function statusFor(int $moduleId, int $userId, string $examType): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM cat_exam_eligibility WHERE module_id = :mid AND user_id = :uid AND exam_type = :type'
        );
        $stmt->execute(['mid' => $moduleId, 'uid' => $userId, 'type' => $examType]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
