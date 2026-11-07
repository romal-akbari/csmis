<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Private fingerprints verify identity/customer preservation without copying secrets.
$report = [];
foreach (['users', 'customers', 'provinces', 'districts', 'areas', 'weavers', 'suppliers'] as $table) {
    if (! Schema::hasTable($table)) {
        $report[$table] = ['rows' => 0, 'ids_sha256' => hash('sha256', '')];

        continue;
    }
    $ids = DB::table($table)->orderBy('id')->pluck('id')->all();
    $report[$table] = ['rows' => count($ids), 'ids_sha256' => hash('sha256', implode(',', $ids))];
    if (in_array($table, ['users', 'customers'], true)) {
        $rows = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $report[$table]['content_sha256'] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
$path = storage_path('app/private/backups/phase2-before.json');
if (($argv[1] ?? '') === 'capture') {
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Private pre-upgrade fingerprints captured.\n";
} elseif (($argv[1] ?? '') === 'verify') {
    if (! is_file($path) || json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) !== $report) {
        throw new RuntimeException('Directory identities or existing users/customers changed. Review migration evidence.');
    }
    file_put_contents(storage_path('app/private/backups/phase2-preservation.json'),
        json_encode(['verified_at' => now()->toIso8601String(), 'tables' => $report], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Upgrade verified: existing IDs and user/customer content fingerprints match.\n";
} else {
    throw new InvalidArgumentException('Use capture before migration, then verify after migration.');
}
