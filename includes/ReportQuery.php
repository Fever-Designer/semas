<?php
declare(strict_types=1);

/** Builds the filtered attendance dataset shared by export-pdf.php and export-excel.php,
 *  so the two export formats can never drift apart. */
function build_attendance_report_rows(PDO $db, array $filters): array
{
    $semester = Semester::requireActive($db);
    $where = ['e.semester_id = :report_semester_id'];
    $params = ['report_semester_id' => (int) $semester['id']];

    if (!empty($filters['event_id'])) {
        $where[] = 'e.event_id = :event_id';
        $params['event_id'] = (int) $filters['event_id'];
    }
    if (!empty($filters['department_id'])) {
        $where[] = 'u.department_id = :department_id';
        $params['department_id'] = (int) $filters['department_id'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'e.event_date >= :date_from';
        $params['date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'e.event_date <= :date_to';
        $params['date_to'] = $filters['date_to'];
    }
    if (!empty($filters['student_id'])) {
        $where[] = 'u.user_id = :student_id';
        $params['student_id'] = (int) $filters['student_id'];
    }
    // Role-based scoping: HOD only sees their own department; Dean only sees their faculty's departments.
    if (!empty($filters['scope_department_id'])) {
        $where[] = 'u.department_id = :scope_dept';
        $params['scope_dept'] = (int) $filters['scope_department_id'];
    }
    if (!empty($filters['scope_faculty_id'])) {
        $where[] = 'dd.faculty_id = :scope_faculty';
        $params['scope_faculty'] = (int) $filters['scope_faculty_id'];
    }

    $sql = "SELECT
                e.title AS event_title, e.venue, e.event_date, e.start_time,
                u.user_id AS student_id, u.reg_number, u.full_name, dd.department_name,
                u.phone_number, u.email, a.checkin_time,
                CASE WHEN a.attendance_id IS NOT NULL THEN 'Present' ELSE 'Absent' END AS attendance_status
            FROM events e
            JOIN event_registrations er ON er.event_id = e.event_id
            JOIN users u ON u.user_id = er.user_id
            LEFT JOIN departments dd ON dd.department_id = u.department_id
            LEFT JOIN attendance_logs a ON a.event_id = e.event_id AND a.user_id = u.user_id";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY e.event_date DESC, u.full_name';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
