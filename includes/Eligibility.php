<?php
declare(strict_types=1);

/**
 * Attendance-driven CAT/Exam eligibility.
 *
 * 75%+    => automatically Allowed.
 * 65-74%  => Not Allowed by the system, Pending for HoD review.
 * Below 65% => automatically Not Allowed.
 *
 * Present and Late count as attended only when the student also signed out.
 * Missing sign-out is tracked as Left Early and does not count as attended.
 */
final class Eligibility
{
    public const AUTO_ELIGIBLE_PERCENT = 75.0;
    public const REVIEW_PERCENT = 65.0;

    private static function ensureMetricsColumns(PDO $db): void
    {
        $columns = [
            'attendance_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER absences_count',
            'total_sessions INT NOT NULL DEFAULT 0 AFTER attendance_percent',
            'present_count INT NOT NULL DEFAULT 0 AFTER total_sessions',
            'late_count INT NOT NULL DEFAULT 0 AFTER present_count',
            'left_early_count INT NOT NULL DEFAULT 0 AFTER late_count',
            'requires_review TINYINT(1) NOT NULL DEFAULT 0 AFTER left_early_count',
        ];
        foreach ($columns as $definition) {
            try {
                $db->exec('ALTER TABLE cat_exam_eligibility ADD COLUMN ' . $definition);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1060) {
                    throw $e;
                }
            }
        }
    }

    public static function generate(int $moduleId, string $examType): int
    {
        $db = Database::connection();
        self::ensureMetricsColumns($db);

        $modStmt = $db->prepare('SELECT * FROM modules WHERE module_id = :id');
        $modStmt->execute(['id' => $moduleId]);
        $module = $modStmt->fetch();
        if (!$module) {
            return 0;
        }

        $cutoffDate = $examType === 'CAT' ? ($module['cat_date'] ?? null) : ($module['exam_date'] ?? null);
        if (!$cutoffDate) {
            return 0;
        }

        $cutoffWhere = 'cs.session_date <= :cutoff AND cs.session_date <= CURDATE()';
        $params = ['cutoff' => $cutoffDate];

        $totalSessionsStmt = $db->prepare(
            "SELECT COUNT(*) FROM class_sessions cs WHERE cs.module_id = :mid AND $cutoffWhere"
        );
        $totalSessionsStmt->execute(['mid' => $moduleId] + $params);
        $totalSessions = (int) $totalSessionsStmt->fetchColumn();

        $studentsStmt = $db->prepare('SELECT user_id FROM module_enrollments WHERE module_id = :mid');
        $studentsStmt->execute(['mid' => $moduleId]);
        $studentIds = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);

        $generated = 0;
        foreach ($studentIds as $userId) {
            $metricsStmt = $db->prepare(
                "SELECT
                    SUM(CASE WHEN si.attendance_id IS NOT NULL AND si.verification_method <> 'Auto' AND si.status = 'Present' AND so.attendance_id IS NOT NULL THEN 1 ELSE 0 END) AS present_count,
                    SUM(CASE WHEN si.attendance_id IS NOT NULL AND si.verification_method <> 'Auto' AND si.status = 'Late' AND so.attendance_id IS NOT NULL THEN 1 ELSE 0 END) AS late_count,
                    SUM(CASE WHEN si.attendance_id IS NOT NULL AND si.verification_method <> 'Auto' AND so.attendance_id IS NULL THEN 1 ELSE 0 END) AS left_early_count
                 FROM class_sessions cs
                 LEFT JOIN class_attendance_logs si ON si.session_id = cs.session_id AND si.user_id = :uid AND si.attendance_type = 'Sign In'
                 LEFT JOIN class_attendance_logs so ON so.session_id = cs.session_id AND so.user_id = :uid2 AND so.attendance_type = 'Sign Out'
                 WHERE cs.module_id = :mid AND $cutoffWhere"
            );
            $metricsStmt->execute(['uid' => $userId, 'uid2' => $userId, 'mid' => $moduleId] + $params);
            $metrics = $metricsStmt->fetch() ?: [];

            $present = (int) ($metrics['present_count'] ?? 0);
            $late = (int) ($metrics['late_count'] ?? 0);
            $leftEarly = (int) ($metrics['left_early_count'] ?? 0);
            $missed = max(0, $totalSessions - $present - $late);
            $attendancePct = $totalSessions === 0 ? 100.0 : round((($present + $late) / max(1, $totalSessions)) * 100, 2);
            $requiresReview = $attendancePct >= self::REVIEW_PERCENT && $attendancePct < self::AUTO_ELIGIBLE_PERCENT;
            $systemDecision = $attendancePct >= self::AUTO_ELIGIBLE_PERCENT ? 'Allowed' : 'Not Allowed';
            $hodDecision = $requiresReview ? 'Pending' : 'Approved';
            $finalDecision = $systemDecision;

            $existing = $db->prepare(
                'SELECT hod_decision FROM cat_exam_eligibility WHERE module_id = :mid AND user_id = :uid AND exam_type = :type'
            );
            $existing->execute(['mid' => $moduleId, 'uid' => $userId, 'type' => $examType]);
            $existingRow = $existing->fetch();

            if ($existingRow && $existingRow['hod_decision'] === 'Overridden') {
                $db->prepare(
                    'UPDATE cat_exam_eligibility
                     SET absences_count=:missed, attendance_percent=:pct, total_sessions=:total,
                         present_count=:present, late_count=:late, left_early_count=:left_early,
                         requires_review=:review, system_decision=:sys
                     WHERE module_id=:mid AND user_id=:uid AND exam_type=:type'
                )->execute([
                    'missed' => $missed,
                    'pct' => $attendancePct,
                    'total' => $totalSessions,
                    'present' => $present,
                    'late' => $late,
                    'left_early' => $leftEarly,
                    'review' => $requiresReview ? 1 : 0,
                    'sys' => $systemDecision,
                    'mid' => $moduleId,
                    'uid' => $userId,
                    'type' => $examType,
                ]);
            } else {
                $db->prepare(
                    'INSERT INTO cat_exam_eligibility
                        (module_id, user_id, exam_type, absences_count, attendance_percent, total_sessions,
                         present_count, late_count, left_early_count, requires_review,
                         system_decision, hod_decision, final_decision)
                     VALUES
                        (:mid, :uid, :type, :missed, :pct, :total, :present, :late, :left_early, :review,
                         :sys, :hod, :final)
                     ON DUPLICATE KEY UPDATE
                         absences_count=:missed2, attendance_percent=:pct2, total_sessions=:total2,
                         present_count=:present2, late_count=:late2, left_early_count=:left_early2,
                         requires_review=:review2, system_decision=:sys2, hod_decision=:hod2, final_decision=:final2'
                )->execute([
                    'mid' => $moduleId,
                    'uid' => $userId,
                    'type' => $examType,
                    'missed' => $missed,
                    'pct' => $attendancePct,
                    'total' => $totalSessions,
                    'present' => $present,
                    'late' => $late,
                    'left_early' => $leftEarly,
                    'review' => $requiresReview ? 1 : 0,
                    'sys' => $systemDecision,
                    'hod' => $hodDecision,
                    'final' => $finalDecision,
                    'missed2' => $missed,
                    'pct2' => $attendancePct,
                    'total2' => $totalSessions,
                    'present2' => $present,
                    'late2' => $late,
                    'left_early2' => $leftEarly,
                    'review2' => $requiresReview ? 1 : 0,
                    'sys2' => $systemDecision,
                    'hod2' => $hodDecision,
                    'final2' => $finalDecision,
                ]);
            }

            if ($attendancePct < self::AUTO_ELIGIBLE_PERCENT) {
                self::notifyLowAttendance($db, (int) $userId, $module, $attendancePct, $examType);
            }
            $generated++;
        }

        return $generated;
    }

    public static function statusFor(int $moduleId, int $userId, string $examType): ?array
    {
        $db = Database::connection();
        self::ensureMetricsColumns($db);
        $stmt = $db->prepare(
            'SELECT * FROM cat_exam_eligibility WHERE module_id = :mid AND user_id = :uid AND exam_type = :type'
        );
        $stmt->execute(['mid' => $moduleId, 'uid' => $userId, 'type' => $examType]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function notifyLowAttendance(PDO $db, int $userId, array $module, float $attendancePct, string $examType): void
    {
        $userStmt = $db->prepare('SELECT * FROM users WHERE user_id = :id');
        $userStmt->execute(['id' => $userId]);
        $student = $userStmt->fetch();
        if (!$student) {
            return;
        }

        $title = 'Attendance below 75% / ' . $module['module_title'];
        $body = 'Your attendance for "' . $module['module_title'] . '" is ' . number_format($attendancePct, 1) . '%. This may affect your ' . $examType . ' eligibility. Contact your HoD if you have a documented reason.';
        NotificationCenter::notify($userId, $title, $body, 'Attendance');

        if (!empty($student['email'])) {
            Mailer::send(
                $student['email'],
                $student['full_name'],
                $title,
                '<p>Dear ' . htmlspecialchars($student['full_name']) . ',</p><p>' . htmlspecialchars($body) . '</p>'
            );
        }
        if (!empty($student['phone_number']) && ($student['sms_opt_in'] ?? 1)) {
            Sms::send($student['phone_number'], 'SEMAS: ' . $body, $userId);
        }
    }
}
