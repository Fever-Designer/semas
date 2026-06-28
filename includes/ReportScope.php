<?php
declare(strict_types=1);

/**
 * scoped_report_filters
 * -----------------------
 * Event/attendance compliance reports are part of "Event Management", which
 * (as of the HOD-centralization increment) is accessible only to HOD and
 * Dean — and both now see EVERY department/faculty, not just their own:
 * Dean became university-wide in the previous increment, and HOD became
 * the central academic authority across all departments in this one. So
 * neither role gets extra row-level scoping here anymore; the explicit
 * $getParams filters (event_id, department_id, dates, student_id) are the
 * only filtering, same as Principal used to get.
 */
function scoped_report_filters(array $getParams, array $currentUser): array
{
    return [
        'event_id'      => $getParams['event_id'] ?? null,
        'department_id' => $getParams['department_id'] ?? null,
        'date_from'     => $getParams['date_from'] ?? null,
        'date_to'       => $getParams['date_to'] ?? null,
        'student_id'    => $getParams['student_id'] ?? null,
    ];
}
