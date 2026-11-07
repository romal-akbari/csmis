<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$label = $argv[2] ?? '';
if (! preg_match('/^[a-z0-9_-]{8,60}$/', $label)) {
    throw new InvalidArgumentException('Use a descriptive preservation label.');
}
$path = storage_path('app/private/backups/'.$label.'-before.json');
$fingerprint = function (string $table, array $columns): array {
    $rows = DB::table($table)->get($columns)->map(fn ($row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->all();
    sort($rows, SORT_STRING);

    return ['columns' => $columns, 'rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
};
if (($argv[1] ?? '') === 'capture') {
    $data = [];
    foreach (DB::select('SHOW FULL TABLES') as $row) {
        [$table, $type] = array_values((array) $row);
        if ($type !== 'BASE TABLE' || in_array($table, ['migrations', 'permissions', 'role_permissions', 'cache', 'cache_locks'], true)) {
            continue;
        }
        $data[$table] = $fingerprint($table, Schema::getColumnListing($table));
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo count($data)." table fingerprints captured privately.\n";
} elseif (($argv[1] ?? '') === 'verify') {
    $before = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ($before as $table => $expected) {
        if ($fingerprint($table, $expected['columns']) !== $expected) {
            throw new RuntimeException("Existing data changed in {$table}.");
        }
    }
    file_put_contents(storage_path('app/private/backups/'.$label.'-preservation.json'), json_encode([
        'verified_at' => now()->toIso8601String(), 'matched_tables' => count($before), 'tables' => $before,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo 'Verified: '.count($before)." existing tables and their original column values are unchanged.\n";
} else {
    throw new InvalidArgumentException('Use capture or verify.');
}
