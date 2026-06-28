<?php
declare(strict_types=1);

/**
 * ClassAttendance
 * ----------------
 * Class attendance runs on FIXED, real-world session windows (not
 * "whenever someone happens to click Start"), evaluated in Africa/Cairo
 * time regardless of the application's own default timezone (Africa/
 * Kigali, set in config/config.php) — every method below builds its own
 * DateTime in the Cairo zone explicitly so this never silently drifts if
 * the server's default timezone changes.
 *
 * Both a student (self-scan, Sign In then later Sign Out — see
 * api/student-attendance-scan.php) and a lecturer (manual roster search,
 * or scanning the student's personal QR — see api/class-scan-confirm.php)
 * can record an entry; both paths funnel through statusFor() below so the
 * Present/Late/Absent rule is always identical either way.
 *
 *   Day Session:             08:00 – 11:30
 *   Evening Session:         18:00 – 20:00
 *   Weekend Morning Session: 08:30 – 14:00
 *   Weekend Afternoon:       14:30 – 20:30
 *
 * Weekday (Mon–Fri) -> Day/Evening windows apply.
 * Weekend (Sat/Sun)  -> Weekend Morning/Afternoon windows apply, UNLESS
 * today is a registered Umuganda date, in which case its own override
 * hours apply instead (see currentWindow()). A Public Holiday disables
 * attendance entirely for the day.
 *
 * Within whichever window is currently active: first 10 minutes of the
 * window's official start -> Present; next 10 minutes -> Late; beyond
 * that, attendance can still be recorded (e.g. a lecturer marking an
 * explicit Absence) but a window must be open at all for the attendance
 * page to allow taking/recording attendance in the first place.
 */
final class ClassAttendance
{
    public const PRESENT_WINDOW_MINUTES = 10;
    public const LATE_WINDOW_MINUTES = 20;
    private const TZ = 'Africa/Cairo';

    /** @return array<string,array{start:string,end:string}> 24h HH:MM strings */
    private static function windowDefinitions(): array
    {
        return [
            'Day'              => ['start' => '08:00', 'end' => '11:30'],
            'Evening'          => ['start' => '18:00', 'end' => '20:00'],
            'WeekendMorning'   => ['start' => '08:30', 'end' => '14:00'],
            'WeekendAfternoon' => ['start' => '14:30', 'end' => '20:30'],
        ];
    }

    /** Today's holiday row (Cairo date), if any — for UI messaging on pages that show "no active window". */
    public static function holidayToday(): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM holidays WHERE holiday_date = :d');
        $stmt->execute(['d' => self::now()->format('Y-m-d')]);
        return $stmt->fetch() ?: null;
    }

    public static function now(): DateTime
    {
        return new DateTime('now', new DateTimeZone(self::TZ));
    }

    /** Whether $now (Cairo time) falls on a weekend day there. */
    private static function isWeekend(DateTime $now): bool
    {
        $dow = (int) $now->format('N'); // 1 (Mon) .. 7 (Sun)
        return $dow >= 6;
    }

    /**
     * Returns the currently active session window, or null if no window is
     * open right now (Cairo time) — including because today is a Public
     * Holiday (attendance is fully disabled) or because today is an
     * Umuganda day with its own override hours instead of the normal
     * weekend windows.
     */
    public static function currentWindow(): ?array
    {
        $now = self::now();
        $today = $now->format('Y-m-d');

        $holidayStmt = Database::connection()->prepare('SELECT * FROM holidays WHERE holiday_date = :d');
        $holidayStmt->execute(['d' => $today]);
        $holiday = $holidayStmt->fetch();

        if ($holiday && $holiday['holiday_type'] === 'Public Holiday') {
            return null; // No scanning allowed at all on a public holiday.
        }

        if ($holiday && $holiday['holiday_type'] === 'Umuganda') {
            $candidates = [
                'UmugandaMorning'   => ['start' => substr($holiday['override_morning_start'] ?? '13:30', 0, 5), 'end' => substr($holiday['override_morning_end'] ?? '16:30', 0, 5)],
                'UmugandaAfternoon' => ['start' => substr($holiday['override_afternoon_start'] ?? '17:00', 0, 5), 'end' => substr($holiday['override_afternoon_end'] ?? '20:30', 0, 5)],
            ];
            foreach ($candidates as $name => $def) {
                $start = DateTime::createFromFormat('Y-m-d H:i', $today . ' ' . $def['start'], new DateTimeZone(self::TZ));
                $end = DateTime::createFromFormat('Y-m-d H:i', $today . ' ' . $def['end'], new DateTimeZone(self::TZ));
                if ($now >= $start && $now <= $end) {
                    return ['name' => $name, 'start' => $start, 'end' => $end];
                }
            }
            return null;
        }

        $candidates = self::isWeekend($now)
            ? ['WeekendMorning', 'WeekendAfternoon']
            : ['Day', 'Evening'];

        foreach ($candidates as $name) {
            $def = self::windowDefinitions()[$name];
            $start = DateTime::createFromFormat('Y-m-d H:i', $today . ' ' . $def['start'], new DateTimeZone(self::TZ));
            $end = DateTime::createFromFormat('Y-m-d H:i', $today . ' ' . $def['end'], new DateTimeZone(self::TZ));
            if ($now >= $start && $now <= $end) {
                return ['name' => $name, 'start' => $start, 'end' => $end];
            }
        }
        return null;
    }

    /** Human label for nav/page copy, e.g. "Day Session (08:00–11:30)". */
    public static function describeWindow(array $window): string
    {
        $labels = [
            'Day' => 'Day Session', 'Evening' => 'Evening Session',
            'WeekendMorning' => 'Weekend Morning Session', 'WeekendAfternoon' => 'Weekend Afternoon Session',
            'UmugandaMorning' => 'Umuganda Morning Session', 'UmugandaAfternoon' => 'Umuganda Afternoon Session',
        ];
        return ($labels[$window['name']] ?? $window['name']) . ' (' . $window['start']->format('H:i') . '–' . $window['end']->format('H:i') . ')';
    }

    /** @return string 'Present'|'Late'|'Absent' based on minutes elapsed since the window's official start. */
    public static function statusFor(string $sessionStartDateTime): string
    {
        $start = new DateTime($sessionStartDateTime, new DateTimeZone(self::TZ));
        $elapsedMinutes = (self::now()->getTimestamp() - $start->getTimestamp()) / 60;
        if ($elapsedMinutes <= self::PRESENT_WINDOW_MINUTES) {
            return 'Present';
        }
        if ($elapsedMinutes <= self::LATE_WINDOW_MINUTES) {
            return 'Late';
        }
        return 'Absent';
    }
}
