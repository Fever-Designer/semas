<?php
declare(strict_types=1);

/** Shared announcement option lists used by event and announcement forms. */
final class NotificationGenerator
{
    public const CATEGORIES = [
        'Academic',
        'Examination',
        'Event',
        'Registration',
        'Scholarship',
        'Sports',
        'General Notice',
        'Emergency',
        'Workshop',
        'Career Opportunity',
    ];

    public const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent'];

    public const AUDIENCES = [
        'All Students',
        'First Year Students',
        'Final Year Students',
        'Specific Department',
        'Specific Faculty',
        'Staff',
        'Event Participants',
        'University Community',
        'Day Students',
        'Evening Students',
        'Weekend Students',
    ];
}
