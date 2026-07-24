<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/Database.php';

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (!$file->isFile() || $file->getExtension() !== 'php'
        || str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $command = [PHP_BINARY, '-l', $path];
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $output = '';
    if (is_resource($process)) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
    } else {
        $exitCode = 1;
    }
    $assert($exitCode === 0, 'PHP syntax failed: ' . $path . ' ' . trim($output));

    $source = (string) file_get_contents($path);
    $assert(
        !preg_match('/LIKE\s+:([A-Za-z_][A-Za-z0-9_]*)[\s\S]{0,240}LIKE\s+:\1\b/i', $source),
        'Repeated native-PDO LIKE placeholder: ' . $path
    );
    $assert(
        !preg_match('~<(?:script|link)\b[^>]+https?://(?:cdn\.jsdelivr\.net|unpkg\.com)~i', $source),
        'Runtime CDN dependency: ' . $path
    );
}

$requiredAssets = [
    'public/assets/vendor/bootstrap/bootstrap.min.css',
    'public/assets/vendor/bootstrap/bootstrap.bundle.min.js',
    'public/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css',
    'public/assets/vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff2',
    'public/assets/vendor/nprogress/nprogress.css',
    'public/assets/vendor/nprogress/nprogress.min.js',
    'public/assets/vendor/html5-qrcode/html5-qrcode.min.js',
];
foreach ($requiredAssets as $asset) {
    $assert(is_file($root . '/' . $asset) && filesize($root . '/' . $asset) > 1000, "Missing asset: {$asset}");
}

try {
    $db = Database::connection();
    $assert((bool) $db->query('SELECT 1')->fetchColumn(), 'Database connectivity failed.');
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['users', 'events', 'attendance_logs', 'semester_calendars', 'schema_migrations', 'login_attempts'] as $table) {
        $assert(in_array($table, $tables, true), "Missing database table: {$table}");
    }
    $index = $db->query(
        "SHOW INDEX FROM attendance_logs WHERE Key_name = 'uniq_attendance_event_user'"
    )->fetchAll();
    $assert((bool) $index, 'Missing event attendance uniqueness index.');
    $duplicates = (int) $db->query(
        'SELECT COUNT(*) FROM (
            SELECT event_id, user_id, COUNT(*) total
            FROM attendance_logs GROUP BY event_id, user_id HAVING total > 1
         ) duplicate_groups'
    )->fetchColumn();
    $assert($duplicates === 0, "Duplicate event attendance groups: {$duplicates}");
    $staleEvents = (int) $db->query(
        "SELECT COUNT(*) FROM events
         WHERE status IN ('Scheduled', 'Ongoing')
           AND TIMESTAMP(event_date, end_time) + INTERVAL 30 MINUTE < NOW()"
    )->fetchColumn();
    $assert($staleEvents === 0, "Stale Scheduled/Ongoing events: {$staleEvents}");

    $auditSearch = $db->prepare(
        'SELECT COUNT(*) FROM audit_logs al
         LEFT JOIN users u ON u.user_id = al.user_id
         WHERE u.full_name LIKE :q_name
            OR al.details LIKE :q_details
            OR al.entity_type LIKE :q_entity'
    );
    $auditSearch->execute([
        'q_name' => '%system-check-no-match%',
        'q_details' => '%system-check-no-match%',
        'q_entity' => '%system-check-no-match%',
    ]);
    $assert($auditSearch->fetchColumn() !== false, 'Audit search SQL did not execute.');

    $userSearch = $db->prepare(
        'SELECT COUNT(*) FROM users u
         WHERE u.full_name LIKE :q_name
            OR u.email LIKE :q_email
            OR u.reg_number LIKE :q_reg'
    );
    $userSearch->execute([
        'q_name' => '%system-check-no-match%',
        'q_email' => '%system-check-no-match%',
        'q_reg' => '%system-check-no-match%',
    ]);
    $assert($userSearch->fetchColumn() !== false, 'User search SQL did not execute.');

    $studentLookup = $db->prepare(
        "SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE r.role_name = 'Student'
           AND (u.full_name LIKE :q_name OR u.reg_number LIKE :q_reg)"
    );
    $studentLookup->execute([
        'q_name' => '%system-check-no-match%',
        'q_reg' => '%system-check-no-match%',
    ]);
    $assert($studentLookup->fetchColumn() !== false, 'Student lookup SQL did not execute.');

    $files = glob($root . '/database/migration_*.sql') ?: [];
    $recorded = $db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($files as $file) {
        $assert(in_array(basename($file), $recorded, true), 'Unapplied migration: ' . basename($file));
    }
} catch (Throwable $exception) {
    $failures[] = 'Database checks failed: ' . $exception->getMessage();
}

$full_name = 'System Check';
$announcement = [
    'title' => 'Template Check',
    'category' => 'Academic',
    'message' => 'Template data is complete.',
];
ob_start();
include $root . '/templates/emails/announcement_notification.php';
$emailHtml = (string) ob_get_clean();
$assert(
    str_contains($emailHtml, 'Normal') && str_contains($emailHtml, 'Template Check'),
    'Announcement email fallback rendering failed.'
);

if ($failures) {
    fwrite(STDERR, "System check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "System check passed ({$checks} assertions).\n";
