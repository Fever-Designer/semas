<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Administrator']);

/**
 * Pure-PHP SQL backup: SELECTs every table and writes plain INSERT
 * statements. No mysqldump/shell_exec dependency, since shared hosting
 * often disables shell access — at the cost of being slower and not
 * including indexes/triggers. For a production-scale database, run
 * `mysqldump` directly on the server instead and skip this page.
 */
$db = Database::connection();
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

AuditLog::record(Auth::id(), 'SYSTEM_BACKUP_DOWNLOAD', 'system_settings', null);

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="semas-backup-' . date('Y-m-d_His') . '.sql"');

echo "-- SEMAS database backup — generated " . date('Y-m-d H:i:s') . "\n";
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
