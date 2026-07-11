<?php
declare(strict_types=1);

/**
 * Suggestion
 * -----------
 * The submitter's user_id IS stored (suggestions.submitted_by_user_id) so
 * abuse can be traced administratively/legally if ever required, but no
 * method on this class / and no query anywhere else in the codebase /
 * selects that column for display. adminList()/adminFind() explicitly
 * project only the columns the spec allows an admin to see.
 */
final class Suggestion
{
    public const CATEGORIES = ['Suggestion', 'Complaint', 'Bug Report', 'Feedback', 'Request'];

    public static function submit(string $category, string $message, ?int $departmentId, ?int $submittedByUserId): int
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO suggestions (category, message, department_id, submitted_by_user_id, status)
             VALUES (:cat, :msg, :dept, :uid, :status)'
        )->execute([
            'cat' => $category, 'msg' => $message, 'dept' => $departmentId,
            'uid' => $submittedByUserId, 'status' => 'New',
        ]);
        return (int) $db->lastInsertId();
    }

    /** Admin-safe listing / NEVER includes submitted_by_user_id. */
    public static function adminList(?int $scopeDepartmentId = null, string $viewerRole = ''): array
    {
        $db = Database::connection();
        $sql = "SELECT s.suggestion_id, s.category, s.message, s.status, s.admin_reply,
                       s.replied_at, s.created_at, s.resolved_at, d.department_name,
                       ru.full_name AS replied_by_name, rr.role_name AS replied_by_role,
                       resv.full_name AS resolved_by_name, resr.role_name AS resolved_by_role,
                       CASE WHEN sr.role_name = 'Student' THEN 'Student' ELSE 'Staff' END AS submitter_type
                FROM suggestions s
                LEFT JOIN departments d    ON d.department_id    = s.department_id
                LEFT JOIN users ru         ON ru.user_id         = s.replied_by
                LEFT JOIN roles rr         ON rr.role_id         = ru.role_id
                LEFT JOIN users resv       ON resv.user_id       = s.resolved_by
                LEFT JOIN roles resr       ON resr.role_id       = resv.role_id
                LEFT JOIN users su         ON su.user_id         = s.submitted_by_user_id
                LEFT JOIN roles sr         ON sr.role_id         = su.role_id";
        $params = [];
        // Once staff replies, remove the item from every staff/admin work
        // queue. It remains available only in the original submitter's private
        // mySubmissions() history together with the reply and final status.
        $where = ['s.admin_reply IS NULL'];
        if ($viewerRole !== 'Principal') {
            $where[] = "sr.role_name = 'Student'";
        }
        if ($scopeDepartmentId) {
            $where[] = 's.department_id = :dept';
            $params['dept'] = $scopeDepartmentId;
        }
        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY s.created_at DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** A user's own past submissions (with reply/status), for the submitter to track their own progress. */
    public static function mySubmissions(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT s.suggestion_id, s.category, s.message, s.status, s.admin_reply,
                    s.replied_at, s.created_at, s.resolved_at,
                    ru.full_name AS replied_by_name, rr.role_name AS replied_by_role
             FROM suggestions s
             LEFT JOIN users ru ON ru.user_id = s.replied_by
             LEFT JOIN roles rr ON rr.role_id = ru.role_id
             WHERE s.submitted_by_user_id = :uid
             ORDER BY s.created_at DESC"
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    /** Admin-safe single lookup / NEVER includes submitted_by_user_id. */
    public static function adminFind(int $suggestionId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT s.suggestion_id, s.category, s.message, s.status, s.admin_reply,
                    s.replied_at, s.created_at, d.department_name
             FROM suggestions s
             LEFT JOIN departments d ON d.department_id = s.department_id
             WHERE s.suggestion_id = :id"
        );
        $stmt->execute(['id' => $suggestionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function reply(int $suggestionId, string $reply, int $repliedByUserId): void
    {
        Database::connection()->prepare(
            "UPDATE suggestions SET admin_reply = :reply, status = 'Replied', replied_by = :uid, replied_at = NOW()
             WHERE suggestion_id = :id"
        )->execute(['reply' => $reply, 'uid' => $repliedByUserId, 'id' => $suggestionId]);
    }

    public static function setStatus(int $suggestionId, string $status, ?int $byUserId = null): void
    {
        $db = Database::connection();
        if ($status === 'Resolved' && $byUserId) {
            $db->prepare(
                'UPDATE suggestions SET status=:status, resolved_by=:uid, resolved_at=NOW() WHERE suggestion_id=:id'
            )->execute(['status' => $status, 'uid' => $byUserId, 'id' => $suggestionId]);
        } else {
            $db->prepare('UPDATE suggestions SET status=:status WHERE suggestion_id=:id')
               ->execute(['status' => $status, 'id' => $suggestionId]);
        }
    }

    public static function delete(int $suggestionId): void
    {
        Database::connection()->prepare('DELETE FROM suggestions WHERE suggestion_id = :id')
            ->execute(['id' => $suggestionId]);
    }
}
