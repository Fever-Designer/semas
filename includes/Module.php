<?php
declare(strict_types=1);

/**
 * Module
 * ------
 * Keeps module/class attendance lifecycle state synchronized lazily on page
 * load (this stack has no cron job). Reaching the module end date closes class
 * attendance. A module becomes Completed only after its final Exam attendance
 * list has been formally submitted to the HOD.
 */
final class Module
{
    public const MAX_ONGOING_ENROLLMENTS = 3;
    public const DISCIPLINARY_ABSENCE_THRESHOLD = 4;

    public static function isDisciplinarilyBlocked(PDO $db, int $moduleId, int $userId): bool
    {
        self::ensureDisciplinaryBlocksTable($db);
        $stmt = $db->prepare(
            'SELECT 1 FROM module_disciplinary_blocks
             WHERE module_id = :mid AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['mid' => $moduleId, 'uid' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function disciplinaryBlockMessage(string $studentName): string
    {
        return $studentName . ' cannot be registered for this module because a permanent disciplinary block was issued after '
            . self::DISCIPLINARY_ABSENCE_THRESHOLD . ' pre-CAT effective absences.';
    }

    public static function activeDisciplinaryModule(PDO $db, int $userId): ?array
    {
        self::ensureDisciplinaryBlocksTable($db);
        $stmt = $db->prepare(
            "SELECT m.module_id, m.module_title, b.blocked_at
             FROM module_disciplinary_blocks b
             JOIN modules m ON m.module_id = b.module_id
             WHERE b.user_id = :uid AND m.status = 'Ongoing'
             ORDER BY b.blocked_at DESC
             LIMIT 1"
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetch() ?: null;
    }

    public static function activeDisciplinaryEnrollmentMessage(string $studentName, array $blockedModule): string
    {
        return $studentName . ' cannot be registered for another module while the disciplinary module "'
            . ($blockedModule['module_title'] ?? 'Unknown Module')
            . '" is still Ongoing. Registration becomes available after that module is Completed.';
    }

    public static function isStudentRegistrationOpen(array $module, ?DateTimeInterface $today = null): bool
    {
        if (empty($module['start_date'])) {
            return true;
        }
        $today = $today ?: new DateTimeImmutable('today');
        $deadline = (new DateTimeImmutable((string) $module['start_date']))->modify('+2 days');
        return $today <= $deadline;
    }

    public static function lateRegistrationMessage(array $module): string
    {
        $deadline = !empty($module['start_date'])
            ? (new DateTimeImmutable((string) $module['start_date']))->modify('+2 days')->format('d M Y')
            : '';
        return 'The student registration period for "' . ($module['module_title'] ?? 'this module')
            . '" has closed' . ($deadline !== '' ? ' on ' . $deadline : '')
            . '. Only the HOD can register a student after the two-day registration window.';
    }

    public static function applyDisciplinaryBlock(PDO $db, int $moduleId, int $userId, int $missedDays, int $sessionId): bool
    {
        self::ensureDisciplinaryBlocksTable($db);
        $db->beginTransaction();
        try {
            $insert = $db->prepare(
                'INSERT IGNORE INTO module_disciplinary_blocks
                    (module_id, user_id, missed_days, triggered_session_id, reason, blocked_at)
                 VALUES (:mid, :uid, :missed, :sid, :reason, NOW())'
            );
            $insert->execute([
                'mid' => $moduleId,
                'uid' => $userId,
                'missed' => $missedDays,
                'sid' => $sessionId,
                'reason' => 'Automatically de-registered after reaching four pre-CAT effective absences.',
            ]);
            $created = $insert->rowCount() === 1;
            if (!$created) {
                // An existing disciplinary record may have been overridden by
                // an HOD/Coordinator re-enrollment. Do not de-register again
                // for the same already-recorded disciplinary incident.
                $db->commit();
                return false;
            }
            $db->prepare('DELETE FROM cat_exam_eligibility WHERE module_id = :mid AND user_id = :uid')
               ->execute(['mid' => $moduleId, 'uid' => $userId]);
            $db->prepare('DELETE FROM module_enrollments WHERE module_id = :mid AND user_id = :uid')
               ->execute(['mid' => $moduleId, 'uid' => $userId]);
            $db->commit();
            return $created;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Prepare a student for an authorised HOD re-enrollment. Attendance logs
     * remain available for audit, while the new enrollment timestamp becomes
     * the start of a fresh attendance cycle.
     */
    public static function resetDisciplinaryEnrollmentCycle(PDO $db, int $moduleId, int $userId): void
    {
        self::ensureDisciplinaryBlocksTable($db);
        $db->prepare(
            'DELETE FROM module_disciplinary_blocks WHERE module_id = :mid AND user_id = :uid'
        )->execute(['mid' => $moduleId, 'uid' => $userId]);
        $db->prepare(
            'DELETE FROM attendance_warning_deliveries WHERE module_id = :mid AND user_id = :uid'
        )->execute(['mid' => $moduleId, 'uid' => $userId]);
        $db->prepare(
            'DELETE FROM cat_exam_eligibility WHERE module_id = :mid AND user_id = :uid'
        )->execute(['mid' => $moduleId, 'uid' => $userId]);
    }

    private static function ensureDisciplinaryBlocksTable(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS module_disciplinary_blocks (
                block_id INT AUTO_INCREMENT PRIMARY KEY,
                module_id INT NOT NULL,
                user_id INT NOT NULL,
                missed_days INT NOT NULL,
                triggered_session_id INT NULL,
                reason VARCHAR(255) NOT NULL,
                blocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_module_disciplinary_block (module_id, user_id),
                FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                FOREIGN KEY (triggered_session_id) REFERENCES class_sessions(session_id) ON DELETE SET NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function autoCompleteExpired(): void
    {
        $db = Database::connection();
        $db->exec(
            "UPDATE class_sessions cs
             JOIN modules m ON m.module_id = cs.module_id
             SET cs.status = 'Closed'
             WHERE cs.status = 'Open'
               AND (
                   (m.exam_date IS NOT NULL AND m.exam_date <= CURDATE())
                   OR (m.end_date IS NOT NULL AND m.end_date < CURDATE())
               )"
        );
        $db->exec(
            "UPDATE modules m
             SET m.status = 'Completed'
             WHERE m.status = 'Ongoing'
               AND EXISTS (
                   SELECT 1
                   FROM cat_exam_schedules ces
                   JOIN cat_exam_submissions sub ON sub.schedule_id = ces.schedule_id
                   WHERE ces.module_id = m.module_id
                     AND ces.exam_type = 'Exam'
               )"
        );
    }

    public static function ongoingEnrollmentCount(int $userId, int $excludeModuleId = 0): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*)
             FROM module_enrollments e
             JOIN modules m ON m.module_id = e.module_id
             WHERE e.user_id = :uid
               AND m.status = 'Ongoing'
               AND m.module_id != :exclude_mid"
        );
        $stmt->execute(['uid' => $userId, 'exclude_mid' => $excludeModuleId]);
        return (int) $stmt->fetchColumn();
    }

    public static function canAddOngoingEnrollment(int $userId, int $moduleId): bool
    {
        $stmt = Database::connection()->prepare('SELECT status FROM modules WHERE module_id = :mid');
        $stmt->execute(['mid' => $moduleId]);
        if ($stmt->fetchColumn() !== 'Ongoing') {
            return true;
        }

        return self::ongoingEnrollmentCount($userId, $moduleId) < self::MAX_ONGOING_ENROLLMENTS;
    }

    public static function removeEnrollment(PDO $db, int $moduleId, int $userId): void
    {
        $db->beginTransaction();
        try {
            $db->prepare(
                "DELETE cal FROM class_attendance_logs cal
                 JOIN class_sessions cs ON cs.session_id = cal.session_id
                 WHERE cs.module_id = :mid AND cal.user_id = :uid"
            )->execute(['mid' => $moduleId, 'uid' => $userId]);

            $db->prepare(
                "DELETE ceal FROM cat_exam_attendance_logs ceal
                 JOIN cat_exam_schedules ces ON ces.schedule_id = ceal.schedule_id
                 WHERE ces.module_id = :mid AND ceal.user_id = :uid"
            )->execute(['mid' => $moduleId, 'uid' => $userId]);

            $db->prepare('DELETE FROM cat_exam_eligibility WHERE module_id = :mid AND user_id = :uid')
               ->execute(['mid' => $moduleId, 'uid' => $userId]);

            $db->prepare('DELETE FROM module_enrollments WHERE module_id = :mid AND user_id = :uid')
               ->execute(['mid' => $moduleId, 'uid' => $userId]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function deleteModule(PDO $db, int $moduleId): void
    {
        $db->beginTransaction();
        try {
            $scheduleIds = [];
            try {
                $scheduleStmt = $db->prepare('SELECT schedule_id FROM cat_exam_schedules WHERE module_id = :mid');
                $scheduleStmt->execute(['mid' => $moduleId]);
                $scheduleIds = array_map('intval', $scheduleStmt->fetchAll(PDO::FETCH_COLUMN));
            } catch (PDOException $e) {
                $scheduleIds = [];
            }

            if ($scheduleIds) {
                $placeholders = implode(',', array_fill(0, count($scheduleIds), '?'));
                try {
                    $stmt = $db->prepare("DELETE FROM cat_exam_attendance_logs WHERE schedule_id IN ($placeholders)");
                    $stmt->execute($scheduleIds);
                } catch (PDOException $e) {
                    // Older installs may not have CAT/Exam attendance yet.
                }
            }

            $guardedDeletes = [
                'DELETE cal FROM class_attendance_logs cal JOIN class_sessions cs ON cs.session_id = cal.session_id WHERE cs.module_id = :mid',
                'DELETE FROM class_sessions WHERE module_id = :mid',
                'DELETE FROM cat_exam_schedules WHERE module_id = :mid',
                'DELETE FROM cat_exam_eligibility WHERE module_id = :mid',
                'DELETE FROM module_attendance_submissions WHERE module_id = :mid',
                'DELETE FROM assignment_submissions WHERE assignment_id IN (SELECT assignment_id FROM assignments WHERE module_id = :mid)',
                'DELETE FROM assignments WHERE module_id = :mid',
                'DELETE FROM module_intakes WHERE module_id = :mid',
                'DELETE FROM module_departments WHERE module_id = :mid',
                'DELETE FROM module_enrollments WHERE module_id = :mid',
            ];

            foreach ($guardedDeletes as $sql) {
                try {
                    $db->prepare($sql)->execute(['mid' => $moduleId]);
                } catch (PDOException $e) {
                    // The final modules delete/cascades remain the source of truth.
                }
            }

            $db->prepare('DELETE FROM modules WHERE module_id = :mid')->execute(['mid' => $moduleId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function ongoingEnrollmentLimitMessage(string $studentName): string
    {
        return $studentName . ' already has ' . self::MAX_ONGOING_ENROLLMENTS . ' ongoing modules. Complete one module before enrolling in another.';
    }

    public static function sessionLabel(array $module): string
    {
        $sessionType = (string) ($module['session_type'] ?? '');
        $weekendSlot = (string) ($module['weekend_slot'] ?? '');
        if ($sessionType === 'Weekend' && $weekendSlot !== '') {
            return 'Weekend ' . $weekendSlot;
        }
        return $sessionType !== '' ? $sessionType : 'Any Session';
    }

    public static function studentOngoingSessionConflict(PDO $db, int $userId, int $moduleId): ?array
    {
        $targetStmt = $db->prepare('SELECT module_id, module_title, session_type, weekend_slot, status FROM modules WHERE module_id = :mid');
        $targetStmt->execute(['mid' => $moduleId]);
        $target = $targetStmt->fetch();
        if (!$target || ($target['status'] ?? '') !== 'Ongoing' || empty($target['session_type'])) {
            return null;
        }

        $sql = "SELECT m.module_id, m.module_title, m.session_type, m.weekend_slot
                FROM modules m
                JOIN module_enrollments e ON e.module_id = m.module_id
                WHERE e.user_id = :uid
                  AND m.status = 'Ongoing'
                  AND m.module_id != :mid
                  AND m.session_type = :session";
        $params = [
            'uid' => $userId,
            'mid' => $moduleId,
            'session' => $target['session_type'],
        ];
        if ($target['session_type'] === 'Weekend') {
            $sql .= " AND (COALESCE(m.weekend_slot, '') = :slot OR COALESCE(m.weekend_slot, '') = '')";
            $params['slot'] = (string) ($target['weekend_slot'] ?? '');
        }
        $sql .= ' LIMIT 1';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function lecturerOngoingSessionConflict(PDO $db, int $lecturerId, string $sessionType, ?string $weekendSlot, int $excludeModuleId = 0): ?array
    {
        $sql = "SELECT module_id, module_title, session_type, weekend_slot
                FROM modules
                WHERE lecturer_id = :lec
                  AND session_type = :session
                  AND status = 'Ongoing'
                  AND module_id != :mid";
        $params = [
            'lec' => $lecturerId,
            'session' => $sessionType,
            'mid' => $excludeModuleId,
        ];
        if ($sessionType === 'Weekend') {
            $sql .= " AND (COALESCE(weekend_slot, '') = :slot OR COALESCE(weekend_slot, '') = '')";
            $params['slot'] = (string) $weekendSlot;
        }
        $sql .= ' LIMIT 1';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
