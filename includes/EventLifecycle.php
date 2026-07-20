<?php
declare(strict_types=1);

final class EventLifecycle
{
    /** Keep event status aligned with its configured date and time. */
    public static function sync(PDO $db): void
    {
        $db->exec(
            "UPDATE events
             SET status = 'Completed'
             WHERE status IN ('Scheduled', 'Ongoing')
               AND TIMESTAMP(event_date, end_time) < NOW()"
        );

        $db->exec(
            "UPDATE events
             SET status = 'Ongoing'
             WHERE status = 'Scheduled'
               AND NOW() BETWEEN TIMESTAMP(event_date, start_time) AND TIMESTAMP(event_date, end_time)"
        );
    }
}
