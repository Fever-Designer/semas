<?php
declare(strict_types=1);

/**
 * Module
 * ------
 * Houses the "auto-complete a module once its exam date has passed" rule
 * so every page that lists modules (HOD, Lecturer, Student) applies it the
 * same way, lazily, on page load — there's no cron job in this stack, so
 * this runs as a cheap idempotent UPDATE at the top of those pages instead.
 */
final class Module
{
    public static function autoCompleteExpired(): void
    {
        Database::connection()->exec(
            "UPDATE modules SET status = 'Completed'
             WHERE status = 'Ongoing' AND exam_date IS NOT NULL AND exam_date < CURDATE()"
        );
    }
}
