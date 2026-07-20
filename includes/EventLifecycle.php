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

        // Event dates are informational. A Dean explicitly starts and
        // completes an event, so an Ongoing event remains live regardless of
        // its scheduled date and time.
    }
}
