<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal']);

$pageTitle = 'Roles & Permissions';
$activeNav = 'roles';

// This is a REFERENCE table, not a dynamic permission editor — permissions are
// enforced in code via Auth::requireRole() at the top of every page, not read
// from a database table. Presenting this as if it were live-editable would be
// misleading, so it's deliberately read-only and documents the actual rules.
$matrix = [
    ['feature' => 'Create/manage HOD, Dean, Lecturer accounts', 'Principal' => true, 'HOD' => false, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
    ['feature' => 'Create Dean accounts', 'Principal' => true, 'HOD' => true, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
    ['feature' => 'Manage departments', 'Principal' => true, 'HOD' => 'View only', 'Dean' => false, 'Lecturer' => false, 'Student' => false],
    ['feature' => 'Create/assign modules', 'Principal' => false, 'HOD' => true, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
    ['feature' => 'Manage students (own/university scope)', 'Principal' => false, 'HOD' => 'Own dept.', 'Dean' => 'University-wide', 'Lecturer' => false, 'Student' => false],
    ['feature' => 'Academic announcements (students/lecturers)', 'Principal' => false, 'HOD' => true, 'Dean' => false, 'Lecturer' => 'Module only', 'Student' => false],
    ['feature' => 'System-wide announcements', 'Principal' => true, 'HOD' => false, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
    ['feature' => 'Student announcements (general)', 'Principal' => false, 'HOD' => false, 'Dean' => true, 'Lecturer' => false, 'Student' => false],
    ['feature' => 'Event Management', 'Principal' => false, 'HOD' => false, 'Dean' => true, 'Lecturer' => false, 'Student' => false],
    ['feature' => 'CAT/Exam eligibility decisions', 'Principal' => false, 'HOD' => true, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
    ['feature' => 'Holidays & Umuganda', 'Principal' => false, 'HOD' => true, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
    ['feature' => 'Take/record class attendance', 'Principal' => false, 'HOD' => false, 'Dean' => false, 'Lecturer' => true, 'Student' => 'Self-scan'],
    ['feature' => 'Register for modules', 'Principal' => false, 'HOD' => false, 'Dean' => false, 'Lecturer' => false, 'Student' => true],
    ['feature' => 'Lost & Found: report/claim', 'Principal' => false, 'HOD' => true, 'Dean' => true, 'Lecturer' => true, 'Student' => true],
    ['feature' => 'Lost & Found: approve claims', 'Principal' => false, 'HOD' => false, 'Dean' => true, 'Lecturer' => false, 'Student' => false],
    ['feature' => 'Lost & Found: view statistics', 'Principal' => true, 'HOD' => false, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
    ['feature' => 'Audit log', 'Principal' => true, 'HOD' => false, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
];

function perm_cell($v): string
{
    if ($v === true) return '<span class="text-success"><i class="bi bi-check-circle-fill"></i></span>';
    if ($v === false) return '<span class="text-muted"><i class="bi bi-dash"></i></span>';
    return '<span class="badge bg-light text-dark border">' . e($v) . '</span>';
}

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Roles &amp; Permissions</h4>

<div class="semas-card p-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Feature</th><th class="text-center">Principal</th><th class="text-center">HOD</th><th class="text-center">Dean</th><th class="text-center">Lecturer</th><th class="text-center">Student</th></tr></thead>
      <tbody>
        <?php foreach ($matrix as $row): ?>
          <tr>
            <td><?= e($row['feature']) ?></td>
            <td class="text-center"><?= perm_cell($row['Principal']) ?></td>
            <td class="text-center"><?= perm_cell($row['HOD']) ?></td>
            <td class="text-center"><?= perm_cell($row['Dean']) ?></td>
            <td class="text-center"><?= perm_cell($row['Lecturer']) ?></td>
            <td class="text-center"><?= perm_cell($row['Student']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
