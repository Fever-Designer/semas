<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

$db = Database::connection();
$me = Auth::user();

$lecStmt = $db->prepare('SELECT * FROM lecturers WHERE user_id = :uid');
$lecStmt->execute(['uid' => $me['user_id']]);
$lecturer = $lecStmt->fetch();

$moduleId = (int) ($_GET['module_id'] ?? 0);

$modStmt = $db->prepare(
    "SELECT m.*, u.full_name AS lecturer_name
     FROM modules m
     LEFT JOIN lecturers lt ON lt.lecturer_id = m.lecturer_id
     LEFT JOIN users u ON u.user_id = lt.user_id
     WHERE m.module_id = :id AND m.lecturer_id = :lec"
);
$modStmt->execute(['id' => $moduleId, 'lec' => $lecturer['lecturer_id'] ?? 0]);
$module = $modStmt->fetch();

if (!$module) {
    http_response_code(403);
    die('Module not found or not assigned to you.');
}

$students   = AttendanceSheet::students($db, $moduleId);
$classDates = AttendanceSheet::expectedClassDates($module, true);
$sessLabel  = AttendanceSheet::sessionLabel($module);
$metrics    = AttendanceSheet::currentMetrics($db, $moduleId, $module);
$decisions  = AttendanceSheet::decisionsByDate($db, $moduleId, $module, $classDates);
$effectiveEnd = empty($classDates) ? date('Y-m-d') : end($classDates);
reset($classDates);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Attendance Sheet');
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);

$sheet->setCellValue('A1', 'MODULE NAME:');
$sheet->setCellValue('B1', $module['module_title']);
$sheet->setCellValue('D1', 'START DATE:');
$sheet->setCellValue('E1', $module['start_date'] ? date('d M Y', strtotime($module['start_date'])) : '');

$sheet->setCellValue('A2', 'SESSION:');
$sheet->setCellValue('B2', $sessLabel);

$sheet->setCellValue('A3', 'LECTURER:');
$sheet->setCellValue('B3', $module['lecturer_name'] ?? '');

$sheet->getStyle('A1:A3')->getFont()->setBold(true);
$sheet->getStyle('D1')->getFont()->setBold(true);

$headerRow = 5;
$colIdx = 1;
foreach (['NO', 'STUDENT NAME', 'REG NUMBER', 'PHONE NUMBER', 'P', 'L', 'A', 'TOT', '%'] as $c) {
    $sheet->setCellValueByColumnAndRow($colIdx, $headerRow, $c);
    $colIdx++;
}
foreach ($classDates as $d) {
    $sheet->setCellValueByColumnAndRow($colIdx, $headerRow, date('d-M', strtotime($d)));
    $colIdx++;
}
$lastCol = $colIdx - 1;

$hStyle = $sheet->getStyleByColumnAndRow(1, $headerRow, $lastCol, $headerRow);
$hStyle->getFont()->setBold(true);
$hStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

$row = $headerRow + 1;
$no  = 1;
foreach ($students as $s) {
    $sheet->setCellValueByColumnAndRow(1, $row, $no++);
    $sheet->setCellValueByColumnAndRow(2, $row, $s['full_name']);
    $sheet->setCellValueByColumnAndRow(3, $row, $s['reg_number'] ?? '');
    $sheet->setCellValueByColumnAndRow(4, $row, $s['phone_number'] ?? '');
    $m = $metrics[(int) $s['user_id']] ?? ['present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0, 'percent' => 0];
    $sheet->setCellValueByColumnAndRow(5, $row, (int) $m['present']);
    $sheet->setCellValueByColumnAndRow(6, $row, (int) $m['late']);
    $sheet->setCellValueByColumnAndRow(7, $row, (int) $m['absent']);
    $sheet->setCellValueByColumnAndRow(8, $row, (int) $m['total']);
    $sheet->setCellValueByColumnAndRow(9, $row, number_format((float) $m['percent'], 1) . '%');
    $dateCol = 10;
    foreach ($classDates as $date) {
        $sheet->setCellValueByColumnAndRow($dateCol++, $row, $decisions[(int) $s['user_id']][$date] ?? 'Absent');
    }
    $sheet->getStyleByColumnAndRow(1, $row, $lastCol, $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
}
for ($extra = 0; $extra < 5; $extra++) {
    $sheet->setCellValueByColumnAndRow(1, $row, $no++);
    $sheet->getStyleByColumnAndRow(1, $row, $lastCol, $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
}

for ($col = 1; $col <= $lastCol; $col++) {
    $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
}
foreach (range('A', 'D') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = 'attendance-sheet-' . preg_replace('/[^A-Za-z0-9]+/', '-', $module['module_title']) . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
AuditLog::record(Auth::id(), 'ATTENDANCE_SHEET_EXPORT_EXCEL', 'modules', $moduleId);
(new Xlsx($spreadsheet))->save('php://output');
