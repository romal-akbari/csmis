<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

// Restore a generated backup ONLY into a newly created isolated database.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$path = realpath($argv[1] ?? '');
$directory = realpath(storage_path('app/private/backups'));
if (! $path || ! $directory || ! str_starts_with($path, $directory.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Specify a backup inside the private backup directory.');
}
if (preg_match('/^\s*(USE|CREATE DATABASE)\b/im', file_get_contents($path))) {
    throw new RuntimeException('Backup must not select or create another database.');
}
$config = DB::connection()->getConfig();
$restore = 'csms_phase1_restore_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
$pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'],
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$restore.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$binary = env('MYSQL_BINARY', PHP_OS_FAMILY === 'Windows'
    ? 'C:/xampp/mysql/bin/mysql.exe' : 'mysql');
$process = new Process([$binary, '--host='.$config['host'], '--port='.$config['port'],
    '--user='.$config['username'], '--default-character-set=utf8mb4', $restore],
    null, ['MYSQL_PWD' => $config['password'] ?? '']);
$stream = fopen($path, 'rb');
$process->setInput($stream);
$process->setTimeout(120);
$process->mustRun();
fclose($stream);

$fingerprint = function (string $database) use ($pdo): array {
    $tables = $pdo->query('SHOW FULL TABLES FROM `'.$database.'`')->fetchAll(PDO::FETCH_NUM);
    $result = [];
    foreach ($tables as [$table, $type]) {
        $rows = $pdo->query('SELECT * FROM `'.$database.'`.`'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $serialized = array_map(fn ($row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $rows);
        sort($serialized, SORT_STRING);
        $result[$table] = ['type' => $type, 'rows' => count($rows),
            'sha256' => hash('sha256', implode("\n", $serialized))];
    }
    ksort($result);

    return $result;
};
$source = $fingerprint($config['database']);
$restored = $fingerprint($restore);
if ($source !== $restored) {
    throw new RuntimeException('Restored data differs. Check whether the source changed during verification.');
}
$report = ['verified_at' => now()->toIso8601String(), 'source_database' => $config['database'],
    'restore_database' => $restore, 'backup_sha256' => hash_file('sha256', $path),
    'matched_objects' => count($source), 'tables_and_views' => $source];
$reportPath = storage_path('app/private/backups/restore-verification.json');
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'Restore verified: '.count($source).' tables/views match; isolated database '.$restore.PHP_EOL;
echo $reportPath.PHP_EOL;
