<?php
declare(strict_types=1);

/**
 * AudienceResolver
 * ------------------
 * Single source of truth for "who gets this announcement/email/notification".
 * Every delivery path (admin/events.php announcement form, AI generator,
 * email distribution, event reminders) MUST go through here instead of
 * writing its own SELECT, so the no-cross-role-leakage rule is enforced in
 * exactly one place rather than re-implemented (and potentially
 * mis-implemented) in N places.
 */
final class AudienceResolver
{
    /**
     * @param string   $audience      One of the announcements.target_audience ENUM values.
     * @param int|null $departmentId  Required when $audience === 'Specific Department'.
     * @param int|null $facultyId     Required when $audience === 'Specific Faculty'.
     * @param int|null $eventId       Required when $audience === 'Event Participants'.
     * @return array<int,array>       Active users only — Pending/Deactivated accounts never receive anything.
     */
    public static function resolve(string $audience, ?int $departmentId = null, ?int $facultyId = null, ?int $eventId = null): array
    {
        $db = Database::connection();

        switch ($audience) {
            case 'Staff':
                $sql = "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                        WHERE r.role_name IN ('Administrator','Dean','HOD') AND u.status = 'Active'";
                return $db->query($sql)->fetchAll();

            case 'University Community':
                $sql = "SELECT * FROM users WHERE status = 'Active'";
                return $db->query($sql)->fetchAll();

            case 'Specific Department':
                if (!$departmentId) {
                    return [];
                }
                $stmt = $db->prepare(
                    "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                     WHERE r.role_name = 'Student' AND u.status = 'Active' AND u.department_id = :dept"
                );
                $stmt->execute(['dept' => $departmentId]);
                return $stmt->fetchAll();

            case 'Specific Faculty':
                if (!$facultyId) {
                    return [];
                }
                $stmt = $db->prepare(
                    "SELECT u.* FROM users u
                     JOIN roles r ON r.role_id = u.role_id
                     JOIN departments d ON d.department_id = u.department_id
                     WHERE r.role_name = 'Student' AND u.status = 'Active' AND d.faculty_id = :fac"
                );
                $stmt->execute(['fac' => $facultyId]);
                return $stmt->fetchAll();

            case 'Day Students':
            case 'Evening Students':
            case 'Weekend Students':
                $session = str_replace(' Students', '', $audience); // 'Day' | 'Evening' | 'Weekend'
                $stmt = $db->prepare(
                    "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                     WHERE r.role_name = 'Student' AND u.status = 'Active' AND u.session_type = :session"
                );
                $stmt->execute(['session' => $session]);
                return $stmt->fetchAll();

            case 'Event Participants':
                if (!$eventId) {
                    return [];
                }
                $stmt = $db->prepare(
                    "SELECT u.* FROM users u
                     JOIN event_registrations er ON er.user_id = u.user_id
                     WHERE er.event_id = :eid AND er.status != 'Cancelled' AND u.status = 'Active'"
                );
                $stmt->execute(['eid' => $eventId]);
                return $stmt->fetchAll();

            case 'First Year Students':
            case 'Final Year Students':
                // Not yet tracked by a dedicated year-of-study column; falls back to
                // All Students rather than silently sending to nobody or to staff.
            case 'All Students':
            default:
                $sql = "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                        WHERE r.role_name = 'Student' AND u.status = 'Active'";
                return $db->query($sql)->fetchAll();
        }
    }

    /** Human-readable description of the resolved scope, for confirmation UI before sending. */
    public static function describe(string $audience, ?int $departmentId, ?int $facultyId, ?int $eventId): string
    {
        $db = Database::connection();
        switch ($audience) {
            case 'Specific Department':
                $stmt = $db->prepare('SELECT department_name FROM departments WHERE department_id = :id');
                $stmt->execute(['id' => $departmentId]);
                return 'Department: ' . ($stmt->fetchColumn() ?: 'Unknown');
            case 'Specific Faculty':
                $stmt = $db->prepare('SELECT faculty_name FROM faculties WHERE faculty_id = :id');
                $stmt->execute(['id' => $facultyId]);
                return 'Faculty: ' . ($stmt->fetchColumn() ?: 'Unknown');
            case 'Event Participants':
                $stmt = $db->prepare('SELECT title FROM events WHERE event_id = :id');
                $stmt->execute(['id' => $eventId]);
                return 'Event: ' . ($stmt->fetchColumn() ?: 'Unknown');
            default:
                return $audience;
        }
    }
}
