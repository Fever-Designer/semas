<?php
declare(strict_types=1);

/**
 * ClassAttendance
 * ----------------
 * Class attendance runs on FIXED, real-world session windows (not
 * "whenever someone happens to click Start"), evaluated in Africa/Kigali
 * time / every method below builds its own DateTime in the Kigali zone
 * explicitly so this never silently drifts if the server timezone changes.
 *
 * Both a student (self-scan, Sign In then later Sign Out / see
 * api/student-attendance-scan.php) and a lecturer (manual roster search,
 * or scanning the student's personal QR / see api/class-scan-confirm.php)
 * can record an entry. The final decision is resolved from the Sign In and
 * Sign Out pair by AttendanceSheet::decisionForLogs().
 *
 *   Day Session:             08:00 / 11:30
 *   Evening Session:         18:00 / 20:00
 *   Weekend Morning Session: 08:30 / 14:00
 *   Weekend Afternoon:       14:30 / 20:30
 *
 * Weekday (Mon/Fri) -> Day/Evening windows apply.
 * Weekend (Sat/Sun)  -> Weekend Morning/Afternoon windows apply, UNLESS
 * today is a registered Umuganda date, in which case its own override
 * hours apply instead (see currentWindow()). A Public Holiday disables
 * attendance entirely for the day.
 *
 * Within whichever window is currently active, Sign In remains available
 * until its cutoff. Late is not based on elapsed minutes: it means the
 * student missed Sign In and recorded Sign Out only. Student
 * sign-out opens only after the official end time, and closes 10 minutes
 * later. Lecturers may manage attendance from class start until that same
 * 10-minute post-class grace period.
 */
final class ClassAttendance
{
    public const PRESENT_WINDOW_MINUTES = 10;
    public const LATE_WINDOW_MINUTES = 20;
    public const SIGN_IN_CLOSE_MINUTES = 20;
    public const SIGN_OUT_AFTER_END_MINUTES = 10;
    public const LECTURER_AFTER_END_MINUTES = 10;
    private const TZ = 'Africa/Kigali';

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

    /** Today's holiday row (Cairo date), if any / for UI messaging on pages that show "no active window". */
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

    /**
     * Returns a user-facing error when a date falls outside a module's
     * configured attendance period, or null when recording is allowed.
     * Start and end dates are inclusive. When an Exam date is configured,
     * regular module attendance ends on the preceding calendar day.
     */
    public static function moduleDateRangeError(array $module, ?string $date = null): ?string
    {
        $date = $date ?: self::now()->format('Y-m-d');
        $startDate = trim((string) ($module['start_date'] ?? ''));
        $endDate = trim((string) ($module['end_date'] ?? ''));
        $examDate = trim((string) ($module['exam_date'] ?? ''));

        if ($examDate !== '') {
            $dayBeforeExam = date('Y-m-d', strtotime($examDate . ' -1 day'));
            if ($endDate === '' || $dayBeforeExam < $endDate) {
                $endDate = $dayBeforeExam;
            }
        }

        if ($startDate !== '' && $date < $startDate) {
            return 'Attendance recording starts on ' . date('d M Y', strtotime($startDate)) . '.';
        }
        if ($endDate !== '' && $date > $endDate) {
            return $examDate !== '' && $endDate === date('Y-m-d', strtotime($examDate . ' -1 day'))
                ? 'Module attendance was completed on ' . date('d M Y', strtotime($endDate)) . ', the day before the Exam.'
                : 'Attendance recording ended on ' . date('d M Y', strtotime($endDate)) . '.';
        }
        return null;
    }

    public static function moduleDateAllowsAttendance(array $module, ?string $date = null): bool
    {
        return self::moduleDateRangeError($module, $date) === null;
    }

    /** Ensure the optional lecturer-controlled demo workflow columns exist. */
    public static function ensureManualControlColumns(PDO $db): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        $existing = array_flip($db->query('SHOW COLUMNS FROM class_sessions')->fetchAll(PDO::FETCH_COLUMN));
        $columns = [
            'attendance_phase' => "attendance_phase ENUM('Inactive','SignIn','SignOut') NOT NULL DEFAULT 'Inactive' AFTER status",
            'demo_controlled' => "demo_controlled TINYINT(1) NOT NULL DEFAULT 0 AFTER attendance_phase",
            'phase_started_at' => 'phase_started_at DATETIME NULL AFTER demo_controlled',
            'phase_closed_at' => 'phase_closed_at DATETIME NULL AFTER phase_started_at',
        ];
        foreach ($columns as $name => $definition) {
            if (isset($existing[$name])) {
                continue;
            }
            try {
                $db->exec('ALTER TABLE class_sessions ADD COLUMN ' . $definition);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1060) {
                    throw $e;
                }
            }
        }
        $ready = true;
    }

    /** Whether $now (Cairo time) falls on a weekend day there. */
    private static function isWeekend(DateTime $now): bool
    {
        $dow = (int) $now->format('N'); // 1 (Mon) .. 7 (Sun)
        return $dow >= 6;
    }

    /**
     * Returns the currently active session window, or null if no window is
     * open right now (Cairo time) / including because today is a weekday
     * Public Holiday (Day/Evening attendance is disabled) or because today is an
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

        if ($holiday && $holiday['holiday_type'] === 'Public Holiday' && !self::isWeekend($now)) {
            return null; // Public Holidays do not cancel Weekend modules.
        }

        if ($holiday && $holiday['holiday_type'] === 'Umuganda' && $now->format('N') === '6') {
            $candidates = [
                'UmugandaMorning'   => ['start' => '13:30', 'end' => '16:30'],
                'UmugandaAfternoon' => ['start' => '17:00', 'end' => '20:30'],
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

    /**
     * Create a closed attendance column for every affected Day/Evening module.
     * The holidays table is the actual Holiday mark for every enrolled student;
     * no Absent rows are inserted. Weekend modules are deliberately excluded.
     */
    public static function markPublicHolidaySessions(PDO $db, string $holidayDate, int $createdBy): int
    {
        $date = DateTime::createFromFormat('Y-m-d', $holidayDate);
        if (!$date || $date->format('Y-m-d') !== $holidayDate || (int) $date->format('N') >= 6) {
            return 0;
        }

        $modulesStmt = $db->prepare(
            "SELECT module_id, session_type
             FROM modules
             WHERE session_type IN ('Day','Evening')
               AND status IN ('Scheduled','Ongoing')
               AND (start_date IS NULL OR start_date <= :holiday_date)
               AND (end_date IS NULL OR end_date >= :holiday_date2)"
        );
        $modulesStmt->execute(['holiday_date' => $holidayDate, 'holiday_date2' => $holidayDate]);

        $insert = $db->prepare(
            "INSERT IGNORE INTO class_sessions
                (module_id, session_date, window_name, start_time, end_time, qr_secret, status, created_by)
             VALUES (:mid, :date, :window, :start_time, :end_time, :secret, 'Closed', :created_by)"
        );
        $close = $db->prepare(
            "UPDATE class_sessions SET status = 'Closed'
             WHERE module_id = :mid AND session_date = :date AND window_name = :window"
        );
        $removeAutomaticAbsences = $db->prepare(
            "DELETE cal FROM class_attendance_logs cal
             JOIN class_sessions cs ON cs.session_id = cal.session_id
             WHERE cs.module_id = :mid AND cs.session_date = :date
               AND cs.window_name = :window AND cal.verification_method = 'Auto'"
        );

        $affected = 0;
        foreach ($modulesStmt->fetchAll() as $module) {
            $isEvening = $module['session_type'] === 'Evening';
            $window = $isEvening ? 'Evening' : 'Day';
            $start = $isEvening ? '18:00:00' : '08:00:00';
            $end = $isEvening ? '20:00:00' : '11:30:00';
            $params = ['mid' => (int) $module['module_id'], 'date' => $holidayDate, 'window' => $window];

            $insert->execute($params + [
                'start_time' => $holidayDate . ' ' . $start,
                'end_time' => $holidayDate . ' ' . $end,
                'secret' => QrService::generateSecret(),
                'created_by' => $createdBy,
            ]);
            $close->execute($params);
            $removeAutomaticAbsences->execute($params);
            $affected++;
        }

        return $affected;
    }

    /** Remove empty Holiday placeholders if an HOD removes the holiday. */
    public static function unmarkPublicHolidaySessions(PDO $db, string $holidayDate): void
    {
        $stmt = $db->prepare(
            "DELETE cs FROM class_sessions cs
             JOIN modules m ON m.module_id = cs.module_id
             WHERE cs.session_date = :date
               AND m.session_type IN ('Day','Evening')
               AND NOT EXISTS (
                   SELECT 1 FROM class_attendance_logs cal
                   WHERE cal.session_id = cs.session_id
                     AND cal.verification_method IN ('QR','Manual')
               )"
        );
        $stmt->execute(['date' => $holidayDate]);
    }

    /** Human label for nav/page copy, e.g. "Day Session (08:00/11:30)". */
    public static function describeWindow(array $window): string
    {
        $labels = [
            'Day' => 'Day Session', 'Evening' => 'Evening Session',
            'WeekendMorning' => 'Weekend Morning Session', 'WeekendAfternoon' => 'Weekend Afternoon Session',
            'UmugandaMorning' => 'Umuganda Morning Session', 'UmugandaAfternoon' => 'Umuganda Afternoon Session',
        ];
        return ($labels[$window['name']] ?? $window['name']) . ' (' . $window['start']->format('H:i') . '/' . $window['end']->format('H:i') . ')';
    }

    /** Sign In itself is Present while open; the final paired decision may later become Absent. */
    public static function statusFor(string $sessionStartDateTime): string
    {
        $start = new DateTime($sessionStartDateTime, new DateTimeZone(self::TZ));
        $elapsedMinutes = intdiv(max(0, self::now()->getTimestamp() - $start->getTimestamp()), 60);
        if ($elapsedMinutes <= self::SIGN_IN_CLOSE_MINUTES) {
            return 'Present';
        }
        return 'Absent';
    }

    public static function canSelfSignIn(string $sessionStartDateTime): bool
    {
        $start = new DateTime($sessionStartDateTime, new DateTimeZone(self::TZ));
        $elapsedSeconds = self::now()->getTimestamp() - $start->getTimestamp();
        $elapsedMinutes = intdiv(max(0, $elapsedSeconds), 60);
        return $elapsedSeconds >= 0 && $elapsedMinutes <= self::SIGN_IN_CLOSE_MINUTES;
    }

    public static function canSelfScan(string $sessionStartDateTime): bool
    {
        return self::canSelfSignIn($sessionStartDateTime);
    }

    public static function isStudentSignOutOpen(string $sessionEndDateTime): bool
    {
        $end = new DateTime($sessionEndDateTime, new DateTimeZone(self::TZ));
        $afterEndSeconds = self::now()->getTimestamp() - $end->getTimestamp();
        $afterEndMinutes = intdiv(max(0, $afterEndSeconds), 60);
        return $afterEndSeconds >= 0 && $afterEndMinutes <= self::SIGN_OUT_AFTER_END_MINUTES;
    }

    public static function isLecturerAttendanceOpen(string $sessionStartDateTime, string $sessionEndDateTime): bool
    {
        $now = self::now();
        $start = new DateTime($sessionStartDateTime, new DateTimeZone(self::TZ));
        $end = new DateTime($sessionEndDateTime, new DateTimeZone(self::TZ));
        $end->modify('+' . self::LECTURER_AFTER_END_MINUTES . ' minutes');
        return $now >= $start && $now <= $end;
    }
}
