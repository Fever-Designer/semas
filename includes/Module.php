<?php
declare(strict_types=1);

/**
 * Module
 * ------
 * Houses the "auto-complete a module once its exam date has passed" rule
 * so every page that lists modules (HOD, Lecturer, Student) applies it the
 * same way, lazily, on page load — there's no cron job in this stack, so
 * this runs as a cheap idempotent UPDATE at the top of those pages instead.
 */
final class Module
{
    public const MAX_ONGOING_ENROLLMENTS = 3;

    public static function autoCompleteExpired(): void
    {
        Database::connection()->exec(
            "UPDATE modules SET status = 'Completed'
             WHERE status = 'Ongoing' AND exam_date IS NOT NULL AND exam_date < CURDATE()"
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
