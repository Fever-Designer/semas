<?php
declare(strict_types=1);

/** Sends the two-missed-days warning when a lecturer closes attendance. */
final class AttendanceWarning
{
    public const THRESHOLD = 2;

    public static function processClosedModule(PDO $db, int $moduleId, int $sessionId): int
    {
        self::ensureDeliveryTable($db);
        $moduleStmt = $db->prepare(
            'SELECT m.module_id, m.module_title, m.cat_date, cs.session_date
             FROM modules m
             JOIN class_sessions cs ON cs.session_id = :sid AND cs.module_id = m.module_id
             WHERE m.module_id = :mid'
        );
        $moduleStmt->execute(['mid' => $moduleId, 'sid' => $sessionId]);
        $module = $moduleStmt->fetch();
        if (!$module) return 0;
        if (!empty($module['cat_date']) && $module['session_date'] >= $module['cat_date']) return 0;

        $students = $db->prepare(
            'SELECT u.user_id, u.full_name, u.email FROM module_enrollments me
             JOIN users u ON u.user_id = me.user_id WHERE me.module_id = :mid'
        );
        $students->execute(['mid' => $moduleId]);
        $sent = 0;

        foreach ($students->fetchAll() as $student) {
            $missedDays = self::effectiveMissedDays(
                $db,
                $moduleId,
                (int) $student['user_id'],
                $module['cat_date'] ?: null
            );
            if ($missedDays < self::THRESHOLD) continue;

            $claim = $db->prepare(
                'INSERT IGNORE INTO attendance_warning_deliveries
                    (module_id, user_id, warning_threshold, missed_days, created_at)
                 VALUES (:mid, :uid, :threshold, :missed, NOW())'
            );
            $claim->execute([
                'mid' => $moduleId, 'uid' => $student['user_id'],
                'threshold' => self::THRESHOLD, 'missed' => $missedDays,
            ]);
            if ($claim->rowCount() === 1) {
                $title = 'Attendance Warning / ' . $module['module_title'];
                $body = 'You have missed ' . $missedDays . ' attendance day(s) for "' . $module['module_title']
                    . '". You have only one absence remaining before you may become Not Allowed for the CAT/Exam.';
                NotificationCenter::notify((int) $student['user_id'], $title, $body, 'Attendance');
                if (!empty($student['email'])) {
                    Mailer::enqueue($student['email'], $title, 'attendance_warning', [
                        'full_name' => $student['full_name'],
                        'module_title' => $module['module_title'],
                        'exam_type' => 'CAT/Exam',
                        'missed_days' => $missedDays,
                        'body' => $body,
                    ], (int) $student['user_id']);
                }
                $sent++;
            }
            if ($missedDays >= Module::DISCIPLINARY_ABSENCE_THRESHOLD) {
                $blocked = Module::applyDisciplinaryBlock(
                    $db,
                    $moduleId,
                    (int) $student['user_id'],
                    $missedDays,
                    $sessionId
                );
                if ($blocked) {
                    $disciplinaryTitle = 'Removed from Module Attendance / Disciplinary Notice';
                    $disciplinaryBody = 'You have been automatically removed from the module and attendance list for "'
                        . $module['module_title'] . '" because of repeated missed classes and/or excessive lateness. '
                        . 'Your attendance record reached ' . $missedDays . ' pre-CAT effective absences. '
                        . 'Under the attendance disciplinary rule, you cannot be registered again for this module. '
                        . 'Please contact the Academic Office if you need clarification about this decision.';
                    NotificationCenter::notify((int) $student['user_id'], $disciplinaryTitle, $disciplinaryBody, 'Attendance');
                    if (!empty($student['email'])) {
                        Mailer::enqueue($student['email'], $disciplinaryTitle, 'announcement_notification', [
                            'full_name' => $student['full_name'],
                            'announcement' => [
                                'title' => $disciplinaryTitle,
                                'category' => 'Academic',
                                'message' => $disciplinaryBody,
                            ],
                        ], (int) $student['user_id']);
                    }
                    AuditLog::record(
                        null,
                        'MODULE_DISCIPLINARY_AUTO_DEREGISTER',
                        'modules',
                        $moduleId,
                        'user_id=' . $student['user_id'] . ';effective_absences=' . $missedDays . ';session_id=' . $sessionId
                    );
                }
            }
        }
        return $sent;
    }

    private static function effectiveMissedDays(PDO $db, int $moduleId, int $userId, ?string $catDate): int
    {
        $stmt = $db->prepare(
            "SELECT attendance_day, MAX(has_present) AS has_present, MAX(has_late) AS has_late
             FROM (
                 SELECT cs.session_date AS attendance_day,
                    CASE WHEN si.verification_method IN ('QR','Manual')
                               AND so.verification_method IN ('QR','Manual') THEN 1 ELSE 0 END AS has_present,
                    CASE WHEN (si.attendance_id IS NULL OR si.verification_method NOT IN ('QR','Manual'))
                               AND so.verification_method IN ('QR','Manual') THEN 1 ELSE 0 END AS has_late
                 FROM class_sessions cs
                 LEFT JOIN class_attendance_logs si ON si.session_id = cs.session_id
                    AND si.user_id = :uid AND si.attendance_type = 'Sign In'
                 LEFT JOIN class_attendance_logs so ON so.session_id = cs.session_id
                    AND so.user_id = :uid2 AND so.attendance_type = 'Sign Out'
                 WHERE cs.module_id = :mid AND cs.status = 'Closed'
                   AND cs.session_date < :cat_cutoff
                   AND NOT EXISTS (
                       SELECT 1 FROM holidays h
                       WHERE h.holiday_date = cs.session_date
                         AND h.holiday_type = 'Public Holiday'
                   )
             ) attendance GROUP BY attendance_day"
        );
        $stmt->execute([
            'uid' => $userId,
            'uid2' => $userId,
            'mid' => $moduleId,
            'cat_cutoff' => $catDate ?: '9999-12-31',
        ]);
        $absent = 0;
        $late = 0;
        foreach ($stmt->fetchAll() as $day) {
            if ((int) $day['has_present'] === 1) continue;
            if ((int) $day['has_late'] === 1) $late++; else $absent++;
        }
        return $absent + intdiv($late, 2);
    }

    private static function ensureDeliveryTable(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS attendance_warning_deliveries (
                delivery_id INT AUTO_INCREMENT PRIMARY KEY,
                module_id INT NOT NULL, user_id INT NOT NULL,
                warning_threshold INT NOT NULL, missed_days INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_attendance_warning (module_id, user_id, warning_threshold),
                FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
