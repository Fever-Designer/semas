<?php
declare(strict_types=1);

/**
 * Settings
 * --------
 * Thin wrapper around the `system_settings` key-value table. Used for
 * University Branding (name, logo, theme colors) and a few general
 * settings (academic year, current semester). Deliberately NOT used for
 * mail/SMS credentials — those stay in .env/config/config.php exactly as
 * before, so this page can never accidentally break a working integration
 * by storing a half-typed API key in the database instead.
 */
final class Settings
{
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $stmt = Database::connection()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :k');
        $stmt->execute(['k' => $key]);
        $value = $stmt->fetchColumn();
        $value = $value === false ? $default : $value;
        self::$cache[$key] = $value;
        return $value;
    }

    public static function set(string $key, ?string $value, ?int $updatedBy = null): void
    {
        $db = Database::connection();
        $hasUpdatedBy = false;
        try {
            $col = $db->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'system_settings'
                   AND COLUMN_NAME = 'updated_by'"
            );
            $col->execute();
            $hasUpdatedBy = (int) $col->fetchColumn() > 0;
        } catch (Throwable $e) {
            $hasUpdatedBy = false;
        }

        if ($hasUpdatedBy) {
            $db->prepare(
                'INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
                 ON DUPLICATE KEY UPDATE setting_value = :v2, updated_by = :u2'
            )->execute(['k' => $key, 'v' => $value, 'u' => $updatedBy, 'v2' => $value, 'u2' => $updatedBy]);
        } else {
            $db->prepare(
                'INSERT INTO system_settings (setting_key, setting_value) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE setting_value = :v2'
            )->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
        }
        self::$cache[$key] = $value;
    }

    /** All settings as an associative array, for rendering a settings form in one go. */
    public static function all(): array
    {
        $rows = Database::connection()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['setting_key']] = $r['setting_value'];
        }
        return $out;
    }
}
