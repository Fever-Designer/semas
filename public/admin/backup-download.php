<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal']);

$backupError = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $pageTitle = 'Confirm Database Backup';
    $activeNav = 'settings';
    require __DIR__ . '/../partials/layout_top.php';
    ?>
    <div class="mx-auto" style="max-width:520px;">
      <h4 class="display-font mb-2">Confirm Database Backup</h4>
      <p class="text-muted small">Enter your current password before downloading the complete SQL backup.</p>
      <form method="post" class="semas-card p-3">
        <?= csrf_field() ?>
        <label class="form-label small fw-semibold">Current Password</label>
        <input type="password" name="current_password" class="form-control mb-3" required autocomplete="current-password">
        <div class="d-flex gap-2">
          <button class="btn btn-semas"><i class="bi bi-download me-1"></i>Confirm &amp; Download</button>
          <a href="<?= APP_URL ?>/admin/settings.php" class="btn btn-outline-dark">Cancel</a>
        </div>
      </form>
    </div>
    <?php
    require __DIR__ . '/../partials/layout_bottom.php';
    exit;
}

csrf_verify();
$currentPassword = (string) ($_POST['current_password'] ?? '');
$passwordStmt = Database::connection()->prepare('SELECT password_hash FROM users WHERE user_id = :user_id');
$passwordStmt->execute(['user_id' => Auth::id()]);
$passwordHash = (string) ($passwordStmt->fetchColumn() ?: '');
if ($passwordHash === '' || !password_verify($currentPassword, $passwordHash)) {
    flash('error', 'The current password was incorrect. Backup download was cancelled.');
    redirect('/admin/settings.php');
}

/**
 * Pure-PHP SQL backup: SELECTs every table and writes plain INSERT
 * statements. No mysqldump/shell_exec dependency, since shared hosting
 * often disables shell access / at the cost of being slower and not
 * including indexes/triggers. For a production-scale database, run
 * `mysqldump` directly on the server instead and skip this page.
 */
$db = Database::connection();
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

AuditLog::record(Auth::id(), 'SYSTEM_BACKUP_DOWNLOAD', 'system_settings', null);

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="semas-backup-' . date('Y-m-d_His') . '.sql"');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

echo "-- SEMAS database backup / generated " . date('Y-m-d H:i:s') . "\n";
echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    echo "-- Table: $table\n";
    $rows = $db->query("SELECT * FROM `$table`")->fetchAll();
    foreach ($rows as $row) {
        $cols = array_keys($row);
        $vals = array_map(function ($v) use ($db) {
            return $v === null ? 'NULL' : $db->quote((string) $v);
        }, array_values($row));
        echo "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
    }
    echo "\n";
}

echo "SET FOREIGN_KEY_CHECKS=1;\n";
