<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$path = storage_path('app/private/backups/phase3-before.json');
$tables = DB::select('SHOW FULL TABLES');
$names = [];
foreach ($tables as $table) {
    $values = array_values((array) $table);
    if ($values[1] === 'BASE TABLE' && ! in_array($values[0], ['migrations', 'permissions', 'role_permissions', 'cache', 'cache_locks'], true)) {
        $names[] = $values[0];
    }
}
$fingerprint = function (string $table): array {
    $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->all();
    sort($rows, SORT_STRING);

    return ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
};
if (($argv[1] ?? '') === 'capture') {
    $result = [];
    foreach ($names as $table) {
        $result[$table] = $fingerprint($table);
    }
    file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo count($result)." private table fingerprints captured.\n";
} elseif (($argv[1] ?? '') === 'verify') {
    $before = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ($before as $table => $expected) {
        $actualTable = in_array($table, ['carpets', 'weaver_payments'], true) ? 'legacy_'.$table : $table;
        if ($fingerprint($actualTable) !== $expected) {
            throw new RuntimeException("Existing data changed: {$table}");
        }
    }
    file_put_contents(storage_path('app/private/backups/phase3-preservation.json'), json_encode([
        'verified_at' => now()->toIso8601String(), 'matched_tables' => count($before), 'tables' => $before,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo 'Preserved all '.count($before)." pre-existing business tables (including users, passwords, accounts and audit).\n";
} else {
    throw new InvalidArgumentException('Use capture before migration, then verify after migration.');
}
