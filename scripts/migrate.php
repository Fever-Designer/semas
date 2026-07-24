<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/Database.php';

$db = Database::connection();
$db->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(100) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$migrationFiles = glob(__DIR__ . '/../database/migration_*.sql') ?: [];
sort($migrationFiles, SORT_NATURAL);

$baseline = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--baseline=(\d{3})$/', $argument, $match)) {
        $baseline = (int) $match[1];
    }
}

if ($baseline !== null) {
    $record = $db->prepare(
        'INSERT IGNORE INTO schema_migrations (migration) VALUES (:migration)'
    );
    foreach ($migrationFiles as $file) {
        if (preg_match('/migration_(\d{3})\.sql$/', basename($file), $match)
            && (int) $match[1] <= $baseline) {
            $record->execute(['migration' => basename($file)]);
        }
    }
    echo "Recorded existing migrations through {$baseline}.\n";
}

$applied = $db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
if (!is_file($mysql)) {
    $mysql = 'mysql';
}

foreach ($migrationFiles as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    echo "Applying {$name}... ";
    $command = [
        $mysql,
        '--host=' . DB_HOST,
        '--port=' . (string) DB_PORT,
        '--user=' . DB_USER,
        '--database=' . DB_NAME,
        '--default-character-set=' . DB_CHARSET,
    ];
    $environment = getenv();
    if (DB_PASS !== '') {
        $environment['MYSQL_PWD'] = DB_PASS;
    }
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__),
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Could not start the MySQL client for {$name}.");
    }
    fwrite($pipes[0], (string) file_get_contents($file));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        echo "FAILED\n";
        if ($stdout !== '') {
            fwrite(STDERR, $stdout);
        }
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
        }
        exit($exitCode);
    }

    $record = $db->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
    $record->execute(['migration' => $name]);
    echo "done\n";
}

echo "Database migrations are current.\n";
