<?php
declare(strict_types=1);

/**
 * NotificationCenter
 * --------------------
 * Thin helper around the `notifications` table (already in the schema).
 * Adds the `category` grouping column from migration_002.sql and provides
 * the queries used by the AJAX bell (api/notifications.php) and by every
 * module that fires a notification (events, announcements, attendance,
 * suggestions, system/auth actions).
 */
final class NotificationCenter
{
    public static function notify(
        int $userId,
        string $title,
        string $body,
        string $category = 'System',
        ?int $relatedAnnouncementId = null,
        ?string $actionUrl = null
    ): void
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO notifications (user_id, title, body, category, related_announcement_id, action_url)
             VALUES (:uid, :title, :body, :category, :aid, :action_url)'
        )->execute([
            'uid' => $userId, 'title' => $title, 'body' => $body,
            'category' => $category, 'aid' => $relatedAnnouncementId,
            'action_url' => self::internalActionUrl($actionUrl),
        ]);
    }

    public static function notifyMany(array $userIds, string $title, string $body, string $category = 'System', ?int $relatedAnnouncementId = null): void
    {
        foreach ($userIds as $uid) {
            self::notify((int) $uid, $title, $body, $category, $relatedAnnouncementId);
        }
    }

    /** Adds a generated notification once per user/title. */
    public static function notifyOnce(
        int $userId,
        string $title,
        string $body,
        string $category = 'System',
        ?string $actionUrl = null
    ): bool
    {
        $db = Database::connection();
        $exists = $db->prepare(
            'SELECT 1 FROM notifications WHERE user_id = :uid AND title = :title LIMIT 1'
        );
        $exists->execute(['uid' => $userId, 'title' => $title]);
        if ($exists->fetchColumn()) {
            $safeActionUrl = self::internalActionUrl($actionUrl);
            if ($safeActionUrl !== null) {
                $db->prepare(
                    'UPDATE notifications
                     SET action_url = :action_url
                     WHERE user_id = :uid AND title = :title AND action_url IS NULL'
                )->execute([
                    'action_url' => $safeActionUrl,
                    'uid' => $userId,
                    'title' => $title,
                ]);
            }
            return false;
        }

        self::notify($userId, $title, $body, $category, null, $actionUrl);
        return true;
    }

    public static function unreadCount(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0');
        $stmt->execute(['uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function recent(int $userId, int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT n.*,
                    COALESCE(n.action_url,
                        CASE WHEN n.related_announcement_id IS NOT NULL
                             THEN '/announcements/board.php' ELSE NULL END
                    ) AS action_url
             FROM notifications n
             WHERE n.user_id = :uid
             ORDER BY n.created_at DESC LIMIT :lim"
        );
        $stmt->bindValue('uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function markRead(int $userId, int $notificationId): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE notification_id = :id AND user_id = :uid'
        );
        return $stmt->execute(['id' => $notificationId, 'uid' => $userId]);
    }

    public static function markUnread(int $userId, int $notificationId): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE notifications SET is_read = 0 WHERE notification_id = :id AND user_id = :uid'
        );
        return $stmt->execute(['id' => $notificationId, 'uid' => $userId]);
    }

    public static function markAllRead(int $userId): void
    {
        Database::connection()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid')
            ->execute(['uid' => $userId]);
    }

    public static function delete(int $userId, int $notificationId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM notifications WHERE notification_id = :id AND user_id = :uid'
        );
        return $stmt->execute(['id' => $notificationId, 'uid' => $userId]);
    }

    public static function deleteAll(int $userId): void
    {
        Database::connection()->prepare('DELETE FROM notifications WHERE user_id = :uid')
            ->execute(['uid' => $userId]);
    }

    private static function internalActionUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // Notification links must stay inside SEMAS.
        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : null;
    }
}
