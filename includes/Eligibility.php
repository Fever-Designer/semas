<?php
declare(strict_types=1);

/**
 * Attendance-driven CAT/Exam eligibility.
 *
 * CAT checks attendance days from module start up to the day before CAT.
 * Exam starts a fresh absence count after CAT and runs up to the day before
 * Exam. CAT date, Exam date, and HoD-entered holidays are excluded.
 *
 * 0-2 missed days => Allowed. Exactly 2 missed days sends a warning email.
 * 3+ missed days  => Not Allowed.
 *
 * Present and Late count as attended only when the student also signed out.
 * Missing sign-out is tracked as Left Early and counts as a missed day.
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

        $expectedDates = self::expectedAttendanceDates($db, $module, $examType);
        $totalSessions = count($expectedDates);

        $studentsStmt = $db->prepare('SELECT user_id FROM module_enrollments WHERE module_id = :mid');
        $studentsStmt->execute(['mid' => $moduleId]);
        $studentIds = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);

        $generated = 0;
        foreach ($studentIds as $userId) {
            $metrics = self::attendanceMetricsForDates($db, $moduleId, (int) $userId, $expectedDates);
            $present = $metrics['present'];
            $late = $metrics['late'];
            $leftEarly = $metrics['left_early'];
            $missed = max(0, $totalSessions - $present - $late);
            $attendancePct = $totalSessions === 0 ? 100.0 : round((($present + $late) / max(1, $totalSessions)) * 100, 2);
            $requiresReview = false;
            $systemDecision = $missed <= self::MAX_ALLOWED_MISSED_DAYS ? 'Allowed' : 'Not Allowed';
            $hodDecision = 'Approved';
            $finalDecision = $systemDecision;

            $existing = $db->prepare(
                'SELECT hod_decision, absences_count FROM cat_exam_eligibility WHERE module_id = :mid AND user_id = :uid AND exam_type = :type'
            );
            $existing->execute(['mid' => $moduleId, 'uid' => $userId, 'type' => $examType]);
            $existingRow = $existing->fetch();
            $previousMissed = $existingRow ? (int) ($existingRow['absences_count'] ?? -1) : -1;

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

            if ($missed === self::WARNING_MISSED_DAYS && $previousMissed !== self::WARNING_MISSED_DAYS) {
                self::notifyWarningLetter($db, (int) $userId, $module, $missed, $examType);
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

    /** @return string[] YYYY-MM-DD dates that should count for this assessment. */
    private static function expectedAttendanceDates(PDO $db, array $module, string $examType): array
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

        $today = new DateTime(date('Y-m-d'));
        if ($end > $today) {
            $end = $today;
        }
        if ($start > $end) {
            return [];
        }

        $holidayRows = $db->query("SELECT holiday_date FROM holidays WHERE holiday_type = 'Public Holiday'")->fetchAll(PDO::FETCH_COLUMN);
        $holidays = array_fill_keys(array_map('strval', $holidayRows), true);
        $excluded = [];
        if ($catDate) {
            $excluded[$catDate] = true;
        }
        if ($examDate) {
            $excluded[$examDate] = true;
        }

        $dates = [];
        for ($day = clone $start; $day <= $end; $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if (isset($holidays[$date]) || isset($excluded[$date])) {
                continue;
            }
            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * @param string[] $expectedDates
     * @return array{present:int,late:int,left_early:int}
     */
    private static function attendanceMetricsForDates(PDO $db, int $moduleId, int $userId, array $expectedDates): array
    {
        if (!$expectedDates) {
            return ['present' => 0, 'late' => 0, 'left_early' => 0];
        }

        $placeholders = [];
        $params = ['mid' => $moduleId, 'uid' => $userId, 'uid2' => $userId];
        foreach ($expectedDates as $i => $date) {
            $key = 'd' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $date;
        }

        $stmt = $db->prepare(
            "SELECT
                cs.session_date,
                MAX(CASE WHEN si.attendance_id IS NOT NULL AND si.verification_method IN ('QR','Manual') AND si.status = 'Present' AND so.attendance_id IS NOT NULL THEN 1 ELSE 0 END) AS has_present,
                MAX(CASE WHEN si.attendance_id IS NOT NULL AND si.verification_method IN ('QR','Manual') AND si.status = 'Late' AND so.attendance_id IS NOT NULL THEN 1 ELSE 0 END) AS has_late,
                MAX(CASE WHEN si.attendance_id IS NOT NULL AND si.verification_method IN ('QR','Manual') AND so.attendance_id IS NULL THEN 1 ELSE 0 END) AS has_left_early
             FROM class_sessions cs
             LEFT JOIN class_attendance_logs si ON si.session_id = cs.session_id AND si.user_id = :uid AND si.attendance_type = 'Sign In'
             LEFT JOIN class_attendance_logs so ON so.session_id = cs.session_id AND so.user_id = :uid2 AND so.attendance_type = 'Sign Out'
             WHERE cs.module_id = :mid AND cs.session_date IN (" . implode(',', $placeholders) . ")
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

    private static function notifyWarningLetter(PDO $db, int $userId, array $module, int $missedDays, string $examType): void
    {
        $userStmt = $db->prepare('SELECT * FROM users WHERE user_id = :id');
        $userStmt->execute(['id' => $userId]);
        $student = $userStmt->fetch();
        if (!$student) {
            return;
        }

        $title = $examType . ' attendance warning / ' . $module['module_title'];
        $body = 'You have missed ' . $missedDays . ' attendance day(s) for "' . $module['module_title'] . '" in the current ' . $examType . ' eligibility period. You are still eligible, but one more missed day will make you Not Allowed for the ' . $examType . '.';
        NotificationCenter::notify($userId, $title, $body, 'Attendance');

        if (!empty($student['email'])) {
            Mailer::send(
                $student['email'],
                $title,
                'attendance_warning',
                [
                    'full_name' => $student['full_name'],
                    'module_title' => $module['module_title'],
                    'exam_type' => $examType,
                    'missed_days' => $missedDays,
                    'body' => $body,
                ],
                $userId
            );
        }
        if (!empty($student['phone_number'])) {
            Sms::send($student['phone_number'], 'SEMAS: ' . $body, $userId);
            WhatsApp::send($student['phone_number'], 'SEMAS: ' . $body, $userId);
        }
    }
}
