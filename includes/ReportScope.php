<?php
declare(strict_types=1);

function scoped_report_filters(array $getParams, array $currentUser): array
{
    $filters = [
        'event_id'      => $getParams['event_id'] ?? null,
        'department_id' => $getParams['department_id'] ?? null,
        'date_from'     => $getParams['date_from'] ?? null,
        'date_to'       => $getParams['date_to'] ?? null,
        'student_id'    => $getParams['student_id'] ?? null,
    ];

    if (Auth::role() === 'HOD') {
        $filters['scope_department_id'] = $currentUser['department_id'];
    } elseif (Auth::role() === 'Dean') {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT faculty_id FROM faculties WHERE dean_user_id = :uid');
        $stmt->execute(['uid' => $currentUser['user_id']]);
        $filters['scope_faculty_id'] = $stmt->fetchColumn() ?: null;
    }
    // Administrator: no extra scoping — sees everything matched by the explicit filters above.

    return $filters;
}
