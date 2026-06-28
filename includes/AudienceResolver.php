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
    /** Year-of-study considered "final year" for the 'Final Year Students' audience (see resolve()). */
    private const FINAL_YEAR_THRESHOLD = 4;

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
                        WHERE r.role_name IN ('Principal','Dean','HOD') AND u.status = 'Active'";
                return $db->query($sql)->fetchAll();

            case 'All Staff':
                $sql = "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                        WHERE r.role_name IN ('Principal','Dean','HOD','Lecturer','Registrar','Coordinator')
                          AND u.status = 'Active'";
                return $db->query($sql)->fetchAll();

            case 'Registrar':
                $sql = "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                        WHERE r.role_name = 'Registrar' AND u.status = 'Active'";
                return $db->query($sql)->fetchAll();

            case 'Coordinator':
                $sql = "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                        WHERE r.role_name = 'Coordinator' AND u.status = 'Active'";
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
                $stmt = $db->prepare(
                    "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                     WHERE r.role_name = 'Student' AND u.status = 'Active' AND u.year_of_study = 1"
                );
                $stmt->execute();
                return $stmt->fetchAll();

            case 'Final Year Students':
                // "Final year" varies by programme length; FINAL_YEAR_THRESHOLD is a configurable
                // approximation (most bachelor's programmes here run 3-4 years) rather than a perfect
                // per-programme rule. Adjust the constant below if your programmes differ.
                $stmt = $db->prepare(
                    "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                     WHERE r.role_name = 'Student' AND u.status = 'Active' AND u.year_of_study >= :threshold"
                );
                $stmt->execute(['threshold' => self::FINAL_YEAR_THRESHOLD]);
                return $stmt->fetchAll();

            case 'All Students':
            default:
                $sql = "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
                        WHERE r.role_name = 'Student' AND u.status = 'Active'";
                return $db->query($sql)->fetchAll();
        }
    }

    /**
     * Role-scoped resolver used by HOD and Dean announcement/poll pages.
     * Unlike resolve(), every filter here is ANDed and the caller (the
     * HOD/Dean send page) is responsible for hard-coding department_id /
     * faculty_id to the sender's OWN scope — never from user input — so a
     * HOD can never reach another department and a Dean can never reach
     * another faculty.
     *
     * @param array{department_id?:int|null, faculty_id?:int|null, session_type?:string|null, year_of_study?:int|null} $filters
     * @return array<int,array>
     */
    public static function resolveStudentsScoped(array $filters): array
    {
        $db = Database::connection();
        $where = ["r.role_name = 'Student'", "u.status = 'Active'"];
        $params = [];

        if (!empty($filters['department_id'])) {
            $where[] = 'u.department_id = :dept';
            $params['dept'] = (int) $filters['department_id'];
        }
        if (!empty($filters['faculty_id'])) {
            $where[] = 'd.faculty_id = :fac';
            $params['fac'] = (int) $filters['faculty_id'];
        }
        if (!empty($filters['session_type'])) {
            $where[] = 'u.session_type = :session';
            $params['session'] = $filters['session_type'];
        }
        if (!empty($filters['year_of_study'])) {
            $where[] = 'u.year_of_study = :year';
            $params['year'] = (int) $filters['year_of_study'];
        }

        $sql = "SELECT u.* FROM users u
                JOIN roles r ON r.role_id = u.role_id
                LEFT JOIN departments d ON d.department_id = u.department_id
                WHERE " . implode(' AND ', $where);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
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
