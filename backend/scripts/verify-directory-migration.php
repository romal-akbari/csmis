<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Creates NEW, explicitly isolated databases. Never resets or migrates the business database.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection()->getConfig();
$pdo = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'],
    $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$suffix = date('Ymd_His').'_'.bin2hex(random_bytes(3));
$databases = [];
$select = function (string $kind) use ($pdo, $suffix, &$databases): string {
    $name = 'csms_phase2_'.$kind.'_'.$suffix;
    if (! preg_match('/\Acsms_phase2_(fresh|adopt)_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}\z/', $name)) {
        throw new RuntimeException('Unexpected verification database name.');
    }
    $pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    config(['database.connections.mysql.database' => $name, 'database.connections.mysql.url' => null, 'database.default' => 'mysql']);
    DB::purge('mysql');
    $databases[$kind] = $name;

    return $name;
};
$check = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$select('fresh');
Artisan::call('migrate', ['--force' => true]);
$check(Schema::hasTable('weavers') && Schema::hasTable('suppliers') && Schema::hasTable('areas'), 'Fresh database is missing directory tables.');
$check(! Schema::hasColumn('weavers', 'province_id') && Schema::hasColumn('weavers', 'manager_employee_id'), 'Fresh schema is not normalized.');
$select('adopt');
$foundation = array_map(fn ($file) => 'database/migrations/'.$file, [
    '0001_01_01_000000_create_users_table.php', '0001_01_01_000001_create_cache_table.php',
    '0001_01_01_000002_create_jobs_table.php', '2026_10_17_000001_install_foundation.php',
    '2026_10_17_000002_harden_foundation_constraints.php',
]);
Artisan::call('migrate', ['--force' => true, '--path' => $foundation]);
$legacy = file_get_contents(base_path('../docs/database/schema.sql'));
foreach (['provinces', 'districts', 'areas', 'weavers', 'suppliers'] as $table) {
    $check((bool) preg_match('/CREATE TABLE '.preg_quote($table, '/').'\s*\(.*?\) ENGINE=InnoDB;/s', $legacy, $match), 'Missing legacy DDL for '.$table);
    DB::unprepared($match[0]);
}
DB::table('branches')->insert(['id' => 10, 'code' => 'MIGRATE', 'name' => 'Migration Branch']);
DB::table('employees')->insert(['id' => 41, 'name' => 'Retained Manager', 'branch_id' => 10]);
DB::table('provinces')->insert(['id' => 11, 'name' => 'Retained Province', 'name_fa' => 'ولایت']);
DB::table('districts')->insert([
    ['id' => 21, 'province_id' => 11, 'name' => 'Retained District'],
    ['id' => 22, 'province_id' => 11, 'name' => 'Different District'],
]);
DB::table('areas')->insert(['id' => 31, 'district_id' => 21, 'name' => 'Retained Area']);
DB::table('weavers')->insert(['id' => 202, 'name' => 'Retained Weaver', 'phone' => '+93 701234567',
    'province_id' => 11, 'district_id' => 22, 'area_id' => 31, 'manager_id' => 41,
    'registration_date' => '2025-09-01', 'notes' => 'Preserve all facts']);
DB::table('suppliers')->insert(['id' => 303, 'name' => 'Retained Supplier', 'address' => 'Kabul', 'notes' => 'Legacy note']);
$migration = require database_path('migrations/2026_10_17_000003_install_directories.php');
$rejected = false;
try {
    $migration->up();
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), 'inconsistent');
}
$check($rejected, 'Ambiguous legacy geography was not rejected.');
$check(Schema::hasColumn('weavers', 'province_id') && Schema::hasColumn('weavers', 'manager_id')
    && ! Schema::hasColumn('provinces', 'created_at'), 'Rejected adoption changed DDL before preflight completed.');
DB::table('weavers')->where('id', 202)->update(['district_id' => 21]);
$before = (array) DB::table('weavers')->find(202);
Artisan::call('migrate', ['--force' => true, '--path' => 'database/migrations/2026_10_17_000003_install_directories.php']);
$after = (array) DB::table('weavers')->find(202);
unset($before['province_id'], $before['district_id']);
$before['manager_employee_id'] = $before['manager_id'];
unset($before['manager_id']);
ksort($before);
ksort($after);
$check($before === $after, 'Legacy weaver facts or identifiers changed during adoption.');
$check(DB::table('suppliers')->where('id', 303)->where('notes', 'Legacy note')->exists(), 'Legacy supplier was not retained.');
foreach ([['districts', 'province_id'], ['areas', 'district_id'], ['weavers', 'area_id'], ['weavers', 'manager_employee_id']] as [$table, $column]) {
    $keys = collect(Schema::getForeignKeys($table))->filter(fn ($key) => in_array($column, $key['columns'], true));
    $check($keys->count() === 1 && strtolower($keys->first()['on_delete']) === 'restrict', 'Expected a restrictive FK on '.$table.'.'.$column);
}
$report = ['verified_at' => now()->toIso8601String(), 'databases' => $databases,
    'checks' => ['fresh_migrations' => true, 'ambiguous_preflight_no_ddl' => true,
        'legacy_ids_and_facts_preserved' => true, 'normalized_weaver_geography' => true, 'restrictive_foreign_keys' => true]];
$path = storage_path('app/private/directory-migration-verification.json');
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'Directory migrations verified: fresh install, rejected ambiguous adoption, preserved legacy facts and restrictive foreign keys.'.PHP_EOL;
echo $path.PHP_EOL;
