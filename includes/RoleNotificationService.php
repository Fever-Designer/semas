<?php
declare(strict_types=1);

/**
 * Creates data-driven notifications that belong to a role rather than to
 * one transaction. Transactional notifications remain addressed directly
 * to the affected student/staff member through NotificationCenter.
 */
final class RoleNotificationService
{
    public static function generateFor(int $userId, string $role): void
    {
        if ($role !== 'Principal') {
            return;
        }

        self::generatePrincipalNotifications($userId);
    }

    private static function generatePrincipalNotifications(int $userId): void
    {
        $db = Database::connection();

        self::activeSemester($db, $userId);
        self::assessmentSchedules($db, $userId);
        self::dailyReports($db, $userId);
        self::weeklyReport($db, $userId);
        self::monthlyReport($db, $userId);
        self::administrativeActions($db, $userId);
    }

    private static function activeSemester(PDO $db, int $userId): void
    {
        $row = $db->query(
            'SELECT id, semester_name, academic_year, start_date, end_date
             FROM semester_calendars
             WHERE start_date <= CURDATE() AND end_date >= CURDATE()
             ORDER BY start_date DESC, id DESC LIMIT 1'
        )->fetch();
        if (!$row) {
            return;
        }

        NotificationCenter::notifyOnce(
            $userId,
            'New Semester Alert: ' . $row['semester_name'],
            $row['semester_name'] . ' (' . $row['academic_year'] . ') runs from '
                . date('d M Y', strtotime($row['start_date'])) . ' to '
                . date('d M Y', strtotime($row['end_date'])) . '.',
            'System',
            '/admin/module-reports.php'
        );
    }

    private static function assessmentSchedules(PDO $db, int $userId): void
    {
        $rows = $db->query(
            "SELECT ces.schedule_id, ces.exam_type, ces.scheduled_date, ces.start_time,
                    ces.room, m.module_title
             FROM cat_exam_schedules ces
             JOIN modules m ON m.module_id = ces.module_id
             WHERE ces.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY ces.scheduled_date, ces.start_time, m.module_title"
        )->fetchAll();

        foreach ($rows as $row) {
            $time = $row['start_time'] ? ' at ' . date('h:i A', strtotime($row['start_time'])) : '';
            NotificationCenter::notifyOnce(
                $userId,
                $row['exam_type'] . ' Scheduled: ' . $row['module_title'] . ' / ' . $row['scheduled_date'],
                $row['module_title'] . ' ' . $row['exam_type'] . ' is scheduled for '
                    . date('d M Y', strtotime($row['scheduled_date'])) . $time
                    . ' in ' . ($row['room'] ?: 'an unassigned room') . '.',
                'Event',
                '/admin/module-reports.php?report=assessment&assessment_type='
                    . rawurlencode($row['exam_type']) . '&date=' . rawurlencode($row['scheduled_date'])
            );
        }
    }

    private static function dailyReports(PDO $db, int $userId): void
    {
        $rows = $db->query(
            "SELECT session_date, COUNT(*) AS session_count
             FROM class_sessions
             WHERE status = 'Closed'
               AND session_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND CURDATE()
             GROUP BY session_date
             ORDER BY session_date"
        )->fetchAll();

        foreach ($rows as $row) {
            NotificationCenter::notifyOnce(
                $userId,
                'Daily Attendance Report Ready: ' . $row['session_date'],
                (int) $row['session_count'] . ' attendance session(s) are complete. '
                    . 'The daily report for ' . date('d M Y', strtotime($row['session_date'])) . ' is ready to view.',
                'Attendance',
                '/admin/module-reports.php?report=class&period=daily&date=' . rawurlencode($row['session_date'])
            );
        }
    }

    private static function weeklyReport(PDO $db, int $userId): void
    {
        $from = date('Y-m-d', strtotime('monday last week'));
        $to = date('Y-m-d', strtotime('sunday last week'));
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM class_sessions
             WHERE status = 'Closed' AND session_date BETWEEN :date_from AND :date_to"
        );
        $stmt->execute(['date_from' => $from, 'date_to' => $to]);
        $count = (int) $stmt->fetchColumn();
        if ($count === 0) {
            return;
        }

        NotificationCenter::notifyOnce(
            $userId,
            'Weekly Attendance Report Ready: ' . $from . ' to ' . $to,
            $count . ' completed attendance session(s) are included in the weekly report, ready to view.',
            'Attendance',
            '/admin/module-reports.php?report=class&period=weekly&date=' . rawurlencode($to)
        );
    }

    private static function monthlyReport(PDO $db, int $userId): void
    {
        $from = date('Y-m-01', strtotime('first day of last month'));
        $to = date('Y-m-t', strtotime('last day of last month'));
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM class_sessions
             WHERE status = 'Closed' AND session_date BETWEEN :date_from AND :date_to"
        );
        $stmt->execute(['date_from' => $from, 'date_to' => $to]);
        $count = (int) $stmt->fetchColumn();
        if ($count === 0) {
            return;
        }

        NotificationCenter::notifyOnce(
            $userId,
            'Monthly Attendance Report Ready: ' . date('F Y', strtotime($from)),
            $count . ' completed attendance session(s) are included in the monthly report, ready to view.',
            'Attendance',
            '/admin/module-reports.php?report=class&period=monthly&date=' . rawurlencode($to)
        );
    }

    private static function administrativeActions(PDO $db, int $userId): void
    {
        $pendingUsers = (int) $db->query("SELECT COUNT(*) FROM users WHERE status = 'Pending'")->fetchColumn();
        if ($pendingUsers > 0) {
            NotificationCenter::notifyOnce(
                $userId,
                'Administrative Action: Pending Accounts (' . $pendingUsers . ')',
                $pendingUsers . ' user account(s) are waiting for verification or activation.',
                'System',
                '/admin/users.php'
            );
        }

        $pendingSubmissions = (int) $db->query(
            "SELECT COUNT(*) FROM module_attendance_submissions WHERE status = 'Pending'"
        )->fetchColumn();
        if ($pendingSubmissions > 0) {
            NotificationCenter::notifyOnce(
                $userId,
                'Attendance Submissions Awaiting Review (' . $pendingSubmissions . ')',
                $pendingSubmissions . ' module attendance submission(s) are pending academic review.',
                'Attendance',
                '/admin/module-reports.php?report=assessment'
            );
        }
    }
}
