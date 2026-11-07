<?php

use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$upgrade = ($argv[1] ?? '') === 'upgrade';
$database = $upgrade ? 'csms_manager_upgrade_20261024' : 'csms_manager_fresh_20261024';
$configuration = DB::connection()->getConfig();
$pdo = new PDO('mysql:host='.$configuration['host'].';port='.$configuration['port'], $configuration['username'], $configuration['password']);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.connections.mysql.database' => $database, 'database.connections.mysql.url' => null, 'database.default' => 'mysql']);
DB::purge('mysql');
if (DB::getDatabaseName() !== $database || DB::select('SHOW TABLES')) {
    throw new RuntimeException('This check requires a new, empty isolated manager-verification database.');
}

$migrate = function (string $file): void {
    if (Artisan::call('migrate', ['--path' => 'database/migrations/'.basename($file), '--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
};
foreach (glob(database_path('migrations/*.php')) as $file) {
    if (basename($file) < '2026_10_24_000014_manager_resources.php') {
        $migrate($file);
    }
}
Artisan::call('db:seed', ['--class' => FoundationSeeder::class, '--force' => true]);
$fingerprint = function (string $table, array $columns): string {
    $rows = DB::table($table)->get($columns)->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();

    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
};
$before = [];
$stock = null;
if ($upgrade) {
    $user = User::factory()->create();
    $branch = DB::table('branches')->insertGetId(['code' => 'MGR-UP', 'name' => 'Upgrade branch']);
    $location = DB::table('stock_locations')->insertGetId(['code' => 'MGR-UP', 'name' => 'Upgrade warehouse', 'branch_id' => $branch, 'location_type' => 'warehouse']);
    $weaver = DB::table('weavers')->insertGetId(['name' => 'Existing weaver', 'registration_date' => '2026-01-01']);
    $category = DB::table('carpet_categories')->insertGetId(['name' => 'Existing wool carpets']);
    $carpet = DB::table('carpets')->insertGetId(['carpet_code' => 'MGR-UP', 'name' => 'Existing carpet', 'registration_branch_id' => $branch, 'category_id' => $category, 'created_by' => $user->id]);
    $currency = DB::table('company_settings')->value('base_currency_id');
    $purchase = DB::table('weaver_purchases')->insertGetId(['purchase_no' => 'MGR-UP', 'weaver_id' => $weaver, 'branch_id' => $branch, 'currency_id' => $currency, 'rate_to_base' => '1', 'purchase_on' => '2026-01-01', 'created_by' => $user->id, 'created_at' => now()]);
    $item = DB::table('weaver_purchase_items')->insertGetId(['weaver_purchase_id' => $purchase, 'carpet_id' => $carpet, 'unit_price' => '2500.00']);
    DB::table('stock_events')->insert(['carpet_id' => $carpet, 'sequence_no' => 1, 'event_type' => 'purchase', 'resulting_status' => 'purchased', 'weaver_purchase_item_id' => $item, 'occurred_on' => '2026-01-01', 'handled_by' => $user->id, 'created_at' => now()]);
    $receipt = DB::table('stock_receipts')->insertGetId(['receipt_no' => 'MGR-UP', 'location_id' => $location, 'received_on' => '2026-01-02', 'created_by' => $user->id, 'created_at' => now()]);
    DB::table('stock_events')->insert(['carpet_id' => $carpet, 'sequence_no' => 2, 'event_type' => 'receipt', 'resulting_status' => 'in_stock', 'stock_receipt_id' => $receipt, 'resulting_location_id' => $location, 'occurred_on' => '2026-01-02', 'handled_by' => $user->id, 'created_at' => now()]);
    $stock = (array) DB::table('v_current_stock')->where('id', $carpet)->first();
    foreach (DB::select('SHOW FULL TABLES') as $table) {
        [$name, $type] = array_values((array) $table);
        if ($type === 'BASE TABLE' && $name !== 'migrations') {
            $columns = Schema::getColumnListing($name);
            $before[$name] = [$columns, $fingerprint($name, $columns)];
        }
    }
}
foreach (glob(database_path('migrations/*.php')) as $file) {
    if (basename($file) >= '2026_10_24_000014_manager_resources.php') {
        $migrate($file);
    }
}
foreach ($before as $table => [$columns, $hash]) {
    if (! hash_equals($hash, $fingerprint($table, $columns))) {
        throw new RuntimeException('Migration changed existing records in '.$table);
    }
}
if ($stock && (array) DB::table('v_current_stock')->where('id', $stock['id'])->first(array_keys($stock)) !== $stock) {
    throw new RuntimeException('Existing stock projection changed.');
}
echo ($upgrade ? 'Populated upgrade' : 'Fresh install').': passed in '.$database.'; '.count($before)." original tables preserved.\n";
