<?php
declare(strict_types=1);

final class EventLifecycle
{
    /** Apply event-wide consistency rules without changing lifecycle status. */
    public static function sync(PDO $db): void
    {
        // Campus events have unlimited attendance; release any registrations
        // left on the legacy waiting list.
        $db->exec("UPDATE event_registrations SET status = 'Registered' WHERE status = 'Waitlisted'");

        // Keep the Dean-controlled start action, but do not leave expired
        // events permanently available for attendance. The extra 30 minutes
        // matches the staff-confirmation grace window.
        $db->exec(
            "UPDATE events
             SET status = 'Completed'
             WHERE status IN ('Scheduled', 'Ongoing')
               AND TIMESTAMP(event_date, end_time) + INTERVAL 30 MINUTE < NOW()"
        );
    }
}
