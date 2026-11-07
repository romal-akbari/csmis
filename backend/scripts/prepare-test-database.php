<?php

use Database\Seeders\FoundationSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Deliberately isolated. Never use migrate:fresh against the configured live DB.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = $argv[1] ?? 'csms_phase1_test';
if (! in_array($database, ['csms_phase1_test', 'csms_phase1_browser', 'csms_phase1_fresh', 'csms_phase3_fresh', 'csms_corrections_fresh', 'csms_reset_browser'], true)) {
    throw new RuntimeException('Only explicitly isolated test databases are allowed.');
}
$config = DB::connection()->getConfig();
$pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'],
    $config['username'], $config['password']);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.connections.mysql.database' => $database,
    'database.connections.mysql.url' => null, 'database.default' => 'mysql']);
DB::purge('mysql');
$exit = Artisan::call('migrate', ['--force' => true]);
echo Artisan::output();
if ($exit !== 0) {
    exit($exit);
}
Artisan::call('db:seed', [
    '--class' => FoundationSeeder::class, '--force' => true,
]);
echo Artisan::output();
echo 'Isolated database ready: '.$database.PHP_EOL;
