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
    public static function notify(int $userId, string $title, string $body, string $category = 'System', ?int $relatedAnnouncementId = null): void
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO notifications (user_id, title, body, category, related_announcement_id)
             VALUES (:uid, :title, :body, :category, :aid)'
        )->execute([
            'uid' => $userId, 'title' => $title, 'body' => $body,
            'category' => $category, 'aid' => $relatedAnnouncementId,
        ]);
    }

    public static function notifyMany(array $userIds, string $title, string $body, string $category = 'System', ?int $relatedAnnouncementId = null): void
    {
        foreach ($userIds as $uid) {
            self::notify((int) $uid, $title, $body, $category, $relatedAnnouncementId);
        }
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
            'SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim'
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
}
