<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Administrator', 'Dean', 'HOD']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;

$db = Database::connection();
$currentUser = Auth::user();
$filters = scoped_report_filters($_GET, $currentUser);
$rows = build_attendance_report_rows($db, $filters);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Attendance Report');

$headers = ['Event Title', 'Venue', 'Date', 'Student ID', 'Registration Number', 'Full Name',
            'Department', 'Phone Number', 'Email', 'Attendance Time', 'Attendance Status'];
$sheet->fromArray($headers, null, 'A1');

$headerStyle = $sheet->getStyle('A1:K1');
$headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E2A52');

$rowNum = 2;
foreach ($rows as $r) {
    $sheet->fromArray([
        $r['event_title'], $r['venue'], $r['event_date'], $r['student_id'], $r['reg_number'],
        $r['full_name'], $r['department_name'], $r['phone_number'], $r['email'],
        $r['checkin_time'], $r['attendance_status'],
    ], null, 'A' . $rowNum);
    $rowNum++;
}

foreach (range('A', 'K') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

AuditLog::record(Auth::id(), 'EXPORT_EXCEL_REPORT', null, null, json_encode($filters));

$filename = 'semas-attendance-report-' . date('Ymd-His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
