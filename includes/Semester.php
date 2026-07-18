<?php
declare(strict_types=1);

/** Registrar calendar is the single source of truth for academic time. */
final class Semester
{
    public const NO_ACTIVE_MESSAGE = 'No active semester is currently available. Academic operations are temporarily disabled. Please wait for the Registrar to set the current semester calendar.';
    private static ?array $active = null;
    private static bool $resolved = false;

    public static function active(PDO $db): ?array
    {
        if (self::$resolved) return self::$active;
        self::$resolved = true;
        $stmt = $db->query(
            "SELECT * FROM semester_calendars
             WHERE start_date <= CURDATE() AND end_date >= CURDATE()
             ORDER BY start_date DESC, id DESC
             LIMIT 1"
        );
        self::$active = $stmt->fetch() ?: null;
        return self::$active;
    }

    public static function requireActive(PDO $db): array
    {
        $semester = self::active($db);
        if (!$semester) throw new RuntimeException(self::NO_ACTIVE_MESSAGE);
        return $semester;
    }

    public static function enforceAcademicWrite(PDO $db): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || self::active($db)) return;

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $isApi = str_contains(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/api/')
            || str_contains($accept, 'application/json');
        http_response_code(409);
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => self::NO_ACTIVE_MESSAGE]);
        } else {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['flash']['error'] = self::NO_ACTIVE_MESSAGE;
            }
            $uri = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/dashboard'), '?') ?: '/dashboard';
            header('Location: ' . $uri);
        }
        exit;
    }

    public static function label(?array $semester): string
    {
        if (!$semester) return 'No active semester';
        return trim((string) $semester['semester_name']) . ' / ' . trim((string) $semester['academic_year']);
    }
}
