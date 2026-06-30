<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;

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
$classDates = AttendanceSheet::expectedClassDates($module);
$sessLabel  = AttendanceSheet::sessionLabel($module);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Attendance Sheet');

$sheet->setCellValue('A1', 'MODULE NAME:');
$sheet->setCellValue('B1', $module['module_title']);
$sheet->setCellValue('D1', 'START DATE:');
$sheet->setCellValue('E1', $module['start_date'] ? date('d M Y', strtotime($module['start_date'])) : '');

$sheet->setCellValue('A2', 'SESSION:');
$sheet->setCellValue('B2', $sessLabel);
$sheet->setCellValue('D2', 'END DATE:');
$sheet->setCellValue('E2', $module['end_date'] ? date('d M Y', strtotime($module['end_date'])) : '');

$sheet->setCellValue('A3', 'LECTURER:');
$sheet->setCellValue('B3', $module['lecturer_name'] ?? '');

$sheet->getStyle('A1:A3')->getFont()->setBold(true);
$sheet->getStyle('D1:D2')->getFont()->setBold(true);

$headerRow = 5;
$colIdx = 1;
foreach (['NO', 'STUDENT NAME', 'REG NUMBER', 'PHONE NUMBER'] as $c) {
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
    $sheet->getStyleByColumnAndRow(1, $row, $lastCol, $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
}
for ($extra = 0; $extra < 5; $extra++) {
    $sheet->setCellValueByColumnAndRow(1, $row, $no++);
    $sheet->getStyleByColumnAndRow(1, $row, $lastCol, $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
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
