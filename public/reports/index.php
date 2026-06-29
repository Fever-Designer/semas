<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Dean']);

$pageTitle = 'Compliance Reports';
$activeNav = 'reports';
$db = Database::connection();

$events = $db->query('SELECT event_id, title FROM events ORDER BY event_date DESC')->fetchAll();
$departments = $db->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Attendance &amp; Compliance Reports</h4>

<div class="semas-card p-3">
  <form method="get" action="<?= APP_URL ?>/reports/export-pdf.php" target="_blank">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label small">Event</label>
        <select name="event_id" class="form-select">
          <option value="">All Events</option>
          <?php foreach ($events as $ev): ?><option value="<?= (int) $ev['event_id'] ?>"><?= e($ev['title']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small">Department</label>
        <select name="department_id" class="form-select">
          <option value="">All Departments</option>
          <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label small">From Date</label><input type="date" name="date_from" class="form-control"></div>
      <div class="col-md-3"><label class="form-label small">To Date</label><input type="date" name="date_to" class="form-control"></div>
    </div>
    <div class="mt-3">
      <button class="btn btn-semas" type="submit"><i class="bi bi-file-earmark-pdf-fill me-1"></i> Export PDF</button>
      <button class="btn btn-semas-gold" type="submit" formaction="<?= APP_URL ?>/reports/export-excel.php"><i class="bi bi-file-earmark-excel-fill me-1"></i> Export Excel</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
