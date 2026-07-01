<?php
declare(strict_types=1);

/** Builds the data behind the blank, printable backup attendance sheet — for
 *  hand-signing when QR scanning isn't usable. Shared by the lecturer and HOD
 *  "Class Attendance" pages (print + Excel export). */
final class AttendanceSheet
{
    public static function students(PDO $db, int $moduleId): array
    {
        $stmt = $db->prepare(
            "SELECT u.full_name, u.reg_number, u.phone_number
             FROM users u JOIN module_enrollments e ON e.user_id = u.user_id
             WHERE e.module_id = :mid AND u.status = 'Active' ORDER BY u.full_name"
        );
        $stmt->execute(['mid' => $moduleId]);
        return $stmt->fetchAll();
    }

    /** Every expected class day between the module's start and end date,
     *  computed automatically — Day/Evening modules get every Monday–Friday,
     *  Weekend modules get every Saturday–Sunday in the range. CAT/Exam dates
     *  are skipped (covered by CAT/Exam Attendance instead). This is the only
     *  place that logic lives — the printed/exported sheet itself carries no
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
        $dates  = [];
        while ($cursor <= $effectiveEnd) {
            $dateStr      = $cursor->format('Y-m-d');
            $isWeekendDay = ((int) $cursor->format('N')) >= 6;
            $dayMatches   = $isWeekendSession ? $isWeekendDay : !$isWeekendDay;
            if ($dayMatches && !in_array($dateStr, $exclude, true)) {
                $dates[] = $dateStr;
            }
            $cursor->modify('+1 day');
        }
        return $dates;
    }

    public static function sessionLabel(array $module): string
    {
        $label = $module['session_type'] ?? '—';
        if ($label === 'Weekend' && !empty($module['weekend_slot'])) {
            $label .= ' – ' . $module['weekend_slot'];
        }
        return $label;
    }
}
