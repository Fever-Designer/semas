<?php
declare(strict_types=1);

/** Builds the data behind the blank, printable backup attendance sheet / for
 *  hand-signing when QR scanning isn't usable. Shared by the lecturer and HOD
 *  "Class Attendance" pages (print + Excel export). */
final class AttendanceSheet
{
    public static function students(PDO $db, int $moduleId): array
    {
        $stmt = $db->prepare(
            "SELECT u.user_id, u.full_name, u.reg_number, u.phone_number
             FROM users u JOIN module_enrollments e ON e.user_id = u.user_id
             WHERE e.module_id = :mid AND u.status = 'Active' ORDER BY u.full_name"
        );
        $stmt->execute(['mid' => $moduleId]);
        return $stmt->fetchAll();
    }

    public static function windowMatchesModule(array $module, string $windowName): bool
    {
        $sessionType = (string) ($module['session_type'] ?? '');
        $slot = (string) ($module['weekend_slot'] ?? '');
        if ($sessionType === 'Day') {
            return $windowName === 'Day';
        }
        if ($sessionType === 'Evening') {
            return $windowName === 'Evening';
        }
        if ($sessionType === 'Weekend') {
            if ($slot === 'Morning') {
                return in_array($windowName, ['WeekendMorning', 'UmugandaMorning'], true);
            }
            if ($slot === 'Afternoon') {
                return in_array($windowName, ['WeekendAfternoon', 'UmugandaAfternoon'], true);
            }
            return in_array($windowName, ['WeekendMorning', 'WeekendAfternoon', 'UmugandaMorning', 'UmugandaAfternoon'], true);
        }
        return false;
    }

    public static function currentMetrics(PDO $db, int $moduleId, array $module): array
    {
        $today = ClassAttendance::now()->format('Y-m-d');
        $startDate = !empty($module['start_date']) ? (string) $module['start_date'] : '1000-01-01';
        $excludeDates = array_values(array_filter([$module['cat_date'] ?? null, $module['exam_date'] ?? null]));

        $sessStmt = $db->prepare(
            "SELECT session_id, session_date, window_name
             FROM class_sessions
             WHERE module_id = :mid AND session_date BETWEEN :start_date AND :today
             ORDER BY session_date ASC, start_time ASC"
        );
        $sessStmt->execute(['mid' => $moduleId, 'start_date' => $startDate, 'today' => $today]);
        $holidayRows = $db->query("SELECT holiday_date FROM holidays WHERE holiday_type = 'Public Holiday'")->fetchAll(PDO::FETCH_COLUMN);
        $publicHolidays = array_fill_keys(array_map('strval', $holidayRows), true);
        $sessions = array_values(array_filter($sessStmt->fetchAll(), function ($s) use ($module, $excludeDates, $publicHolidays) {
            return !isset($publicHolidays[$s['session_date']])
                && !in_array($s['session_date'], $excludeDates, true)
                && self::windowMatchesModule($module, (string) $s['window_name']);
        }));

        $metrics = [];
        $students = self::students($db, $moduleId);
        foreach ($students as $student) {
            $metrics[(int) $student['user_id']] = [
                'present' => 0,
                'late' => 0,
                'absent' => 0,
                'total' => 0,
                'percent' => 0.0,
            ];
        }
        if (!$sessions) {
            return $metrics;
        }

        $sessionIds = array_map(static function ($s) {
            return (int) $s['session_id'];
        }, $sessions);
        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $logStmt = $db->prepare(
            "SELECT session_id, user_id, attendance_type, status, verification_method
             FROM class_attendance_logs
             WHERE session_id IN ($placeholders)"
        );
        $logStmt->execute($sessionIds);
        $logs = [];
        foreach ($logStmt->fetchAll() as $log) {
            $logs[(int) $log['session_id']][(int) $log['user_id']][$log['attendance_type']] = $log;
        }

        foreach ($metrics as $userId => &$row) {
            foreach ($sessions as $session) {
                $sid = (int) $session['session_id'];
                $signIn = $logs[$sid][$userId]['Sign In'] ?? null;
                $signOut = $logs[$sid][$userId]['Sign Out'] ?? null;
                $isReal = $signIn && in_array((string) $signIn['verification_method'], ['QR', 'Manual'], true);
                if ($isReal && $signIn['status'] === 'Present' && $signOut) {
                    $row['present']++;
                } elseif ($isReal && $signIn['status'] === 'Late' && $signOut) {
                    $row['late']++;
                } else {
                    $row['absent']++;
                }
            }
            $row['total'] = $row['present'] + $row['late'] + $row['absent'];
            $row['percent'] = $row['total'] > 0 ? round((($row['present'] + $row['late']) / $row['total']) * 100, 1) : 0.0;
        }
        unset($row);

        return $metrics;
    }

    /** Every expected class day between the module's start and end date,
     *  computed automatically / Day/Evening modules get every Monday/Friday,
     *  Weekend modules get every Saturday/Sunday in the range. CAT/Exam dates
     *  are skipped (covered by CAT/Exam Attendance instead). This is the only
     *  place that logic lives / the printed/exported sheet itself carries no
     *  explanatory text, it just shows the resulting dates.
     *  @return string[] Y-m-d dates, ascending. */
    public static function expectedClassDates(array $module): array
    {
        if (empty($module['start_date']) || empty($module['end_date'])) {
            return [];
        }
        $exclude = array_filter([$module['cat_date'] ?? null, $module['exam_date'] ?? null]);
        $isWeekendSession = ($module['session_type'] ?? '') === 'Weekend';
        $cursor = new DateTime($module['start_date']);
        $end    = new DateTime($module['end_date']);
        $today  = new DateTime('today');
        $effectiveEnd = $end < $today ? $end : $today;
        $holidayRows = Database::connection()
            ->query("SELECT holiday_date FROM holidays WHERE holiday_type = 'Public Holiday'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $publicHolidays = array_fill_keys(array_map('strval', $holidayRows), true);
        $dates  = [];
        while ($cursor <= $effectiveEnd) {
            $dateStr      = $cursor->format('Y-m-d');
            $isWeekendDay = ((int) $cursor->format('N')) >= 6;
            $dayMatches   = $isWeekendSession ? $isWeekendDay : !$isWeekendDay;
            if ($dayMatches && !isset($publicHolidays[$dateStr]) && !in_array($dateStr, $exclude, true)) {
                $dates[] = $dateStr;
            }
            $cursor->modify('+1 day');
        }
        return $dates;
    }

    public static function sessionLabel(array $module): string
    {
        $label = $module['session_type'] ?? '/';
        if ($label === 'Weekend' && !empty($module['weekend_slot'])) {
            $label .= ' / ' . $module['weekend_slot'];
        }
        return $label;
    }
}
