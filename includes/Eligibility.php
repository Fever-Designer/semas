<?php
declare(strict_types=1);

/**
 * Attendance-driven CAT/Exam eligibility.
 *
 * CAT checks attendance days from module start up to the day before CAT.
 * Exam starts a fresh absence count after CAT and runs up to the day before
 * Exam. CAT date, Exam date, and HoD-entered holidays are excluded.
 *
 * 0-2 missed days => Allowed. The attendance-close workflow sends the
 * two-missed-days warning immediately.
 * 3+ missed days  => Not Allowed.
 *
 * Present requires both Sign In and Sign Out. Sign Out without Sign In is
 * Late. Missing both actions, or Sign In without Sign Out, is Absent.
 * Every two Late days add one effective absence for eligibility.
 */
final class Eligibility
{
    public const WARNING_MISSED_DAYS = 2;
    public const MAX_ALLOWED_MISSED_DAYS = 2;

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

        $assessmentDate = $examType === 'CAT' ? ($module['cat_date'] ?? null) : ($module['exam_date'] ?? null);
        if (!$assessmentDate) {
            return 0;
        }

        $expectedDates = self::expectedAttendanceDates($module, $examType);
        $totalSessions = count($expectedDates);

        $studentsStmt = $db->prepare(
            'SELECT user_id, registered_at FROM module_enrollments WHERE module_id = :mid'
        );
        $studentsStmt->execute(['mid' => $moduleId]);
        $students = $studentsStmt->fetchAll();

        $generated = 0;
        foreach ($students as $student) {
            $userId = (int) $student['user_id'];
            $enrolledAt = (string) $student['registered_at'];
            $studentDates = self::datesInEnrollmentCycle($db, $moduleId, $expectedDates, $enrolledAt);
            $totalSessions = count($studentDates);
            $metrics = self::attendanceMetricsForDates(
                $db,
                $moduleId,
                $userId,
                $studentDates,
                $enrolledAt
            );
            $present = $metrics['present'];
            $late = $metrics['late'];
            $leftEarly = $metrics['left_early'];
            $rawAbsences = max(0, $totalSessions - $present - $late);
            $missed = $rawAbsences + intdiv($late, 2);
            $attendancePct = $totalSessions === 0
                ? 100.0
                : round((max(0, $totalSessions - $missed) / max(1, $totalSessions)) * 100, 2);
            $requiresReview = false;
            $systemDecision = $missed <= self::MAX_ALLOWED_MISSED_DAYS ? 'Allowed' : 'Not Allowed';
            $hodDecision = 'Approved';
            $finalDecision = $systemDecision;

            $existing = $db->prepare(
                'SELECT hod_decision, absences_count FROM cat_exam_eligibility WHERE module_id = :mid AND user_id = :uid AND exam_type = :type'
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

            $generated++;
        }

        return $generated;
    }

    /**
     * A re-enrollment starts a new attendance cycle. On the enrollment date,
     * include the class only when its actual start time was after enrollment.
     *
     * @param string[] $expectedDates
     * @return string[]
     */
    private static function datesInEnrollmentCycle(
        PDO $db,
        int $moduleId,
        array $expectedDates,
        string $enrolledAt
    ): array {
        if (!$expectedDates) {
            return [];
        }

        $enrollmentDate = substr($enrolledAt, 0, 10);
        $dates = array_values(array_filter(
            $expectedDates,
            static fn(string $date): bool => $date >= $enrollmentDate
        ));
        if (!$dates) {
            return [];
        }

        $sameDayKey = array_search($enrollmentDate, $dates, true);
        if ($sameDayKey !== false) {
            $stmt = $db->prepare(
                'SELECT 1 FROM class_sessions
                 WHERE module_id = :mid AND session_date = :session_date
                   AND start_time >= :enrolled_at
                 LIMIT 1'
            );
            $stmt->execute([
                'mid' => $moduleId,
                'session_date' => $enrollmentDate,
                'enrolled_at' => $enrolledAt,
            ]);
            if (!$stmt->fetchColumn()) {
                unset($dates[$sameDayKey]);
                $dates = array_values($dates);
            }
        }

        return $dates;
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

    /**
     * Recalculate already-generated eligibility lists affected by a Public
     * Holiday change. Weekend modules are intentionally outside this rule.
     */
    public static function refreshForPublicHoliday(string $holidayDate): int
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT DISTINCT ce.module_id, ce.exam_type
             FROM cat_exam_eligibility ce
             JOIN modules m ON m.module_id = ce.module_id
             WHERE m.session_type IN ('Day','Evening')
               AND (m.start_date IS NULL OR m.start_date <= :holiday_date)
               AND (m.end_date IS NULL OR m.end_date >= :holiday_date2)"
        );
        $stmt->execute(['holiday_date' => $holidayDate, 'holiday_date2' => $holidayDate]);

        $refreshed = 0;
        foreach ($stmt->fetchAll() as $row) {
            self::generate((int) $row['module_id'], (string) $row['exam_type']);
            $refreshed++;
        }
        return $refreshed;
    }

    /**
     * Return the scheduled teaching dates that should count for this
     * assessment. Eligibility uses the same calendar as the attendance
     * register so students are not marked absent on non-teaching days.
     *
     * AttendanceSheet::expectedClassDates() already excludes CAT/Exam dates
     * and Public Holidays. A holiday may therefore remain visible as
     * "Holiday" in an attendance matrix while contributing neither a session
     * nor an absence to CAT/Exam eligibility.
     *
     * @return string[] YYYY-MM-DD dates that should count for this assessment.
     */
    private static function expectedAttendanceDates(array $module, string $examType): array
    {
        $moduleStart = $module['start_date'] ?? null;
        $catDate = $module['cat_date'] ?? null;
        $examDate = $module['exam_date'] ?? null;
        $assessmentDate = $examType === 'CAT' ? $catDate : $examDate;
        if (!$assessmentDate) {
            return [];
        }

        if ($examType === 'Exam' && $catDate) {
            $start = new DateTime($catDate);
            $start->modify('+1 day');
        } else {
            $start = new DateTime($moduleStart ?: $assessmentDate);
        }

        $end = new DateTime($assessmentDate);
        $end->modify('-1 day');

        $moduleEnd = $module['end_date'] ?? null;
        if ($moduleEnd) {
            $moduleEndDate = new DateTime($moduleEnd);
            if ($end > $moduleEndDate) {
                $end = $moduleEndDate;
            }
        }

        $today = new DateTime(date('Y-m-d'));
        if ($end > $today) {
            $end = $today;
        }
        if ($start > $end) {
            return [];
        }

        $dates = [];
        foreach (AttendanceSheet::expectedClassDates($module) as $date) {
            $classDate = new DateTime($date);
            if ($classDate >= $start && $classDate <= $end) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * @param string[] $expectedDates
     * @return array{present:int,late:int,left_early:int}
     */
    private static function attendanceMetricsForDates(
        PDO $db,
        int $moduleId,
        int $userId,
        array $expectedDates,
        string $enrolledAt
    ): array
    {
        if (!$expectedDates) {
            return ['present' => 0, 'late' => 0, 'left_early' => 0];
        }

        $placeholders = [];
        $params = [
            'mid' => $moduleId,
            'uid' => $userId,
            'uid2' => $userId,
            'enrolled_at' => $enrolledAt,
        ];
        foreach ($expectedDates as $i => $date) {
            $key = 'd' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $date;
        }

        $stmt = $db->prepare(
            "SELECT
                cs.session_date,
                MAX(CASE WHEN si.verification_method IN ('QR','Manual') AND so.verification_method IN ('QR','Manual') THEN 1 ELSE 0 END) AS has_present,
                MAX(CASE WHEN (si.attendance_id IS NULL OR si.verification_method NOT IN ('QR','Manual')) AND so.verification_method IN ('QR','Manual') THEN 1 ELSE 0 END) AS has_late,
                MAX(CASE WHEN si.verification_method IN ('QR','Manual') AND so.attendance_id IS NULL THEN 1 ELSE 0 END) AS has_left_early
             FROM class_sessions cs
             LEFT JOIN class_attendance_logs si ON si.session_id = cs.session_id AND si.user_id = :uid AND si.attendance_type = 'Sign In'
             LEFT JOIN class_attendance_logs so ON so.session_id = cs.session_id AND so.user_id = :uid2 AND so.attendance_type = 'Sign Out'
             WHERE cs.module_id = :mid
               AND cs.start_time >= :enrolled_at
               AND cs.session_date IN (" . implode(',', $placeholders) . ")
             GROUP BY cs.session_date"
        );
        $stmt->execute($params);

        $present = 0;
        $late = 0;
        $leftEarly = 0;
        foreach ($stmt->fetchAll() as $row) {
            if ((int) $row['has_present'] === 1) {
                $present++;
            } elseif ((int) $row['has_late'] === 1) {
                $late++;
            } elseif ((int) $row['has_left_early'] === 1) {
                $leftEarly++;
            }
        }

        return ['present' => $present, 'late' => $late, 'left_early' => $leftEarly];
    }

}
