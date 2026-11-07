<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\LedgerService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$upgrade = ($argv[1] ?? '') === 'upgrade';
$database = $upgrade ? 'csms_business_upgrade_20261021' : 'csms_business_fresh_20261021';
$configuration = DB::connection()->getConfig();
$pdo = new PDO('mysql:host='.$configuration['host'].';port='.$configuration['port'], $configuration['username'], $configuration['password']);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.connections.mysql.database' => $database, 'database.connections.mysql.url' => null, 'database.default' => 'mysql']);
DB::purge('mysql');
if (DB::getDatabaseName() !== $database || DB::select('SHOW TABLES')) throw new RuntimeException('This verification requires a new, empty isolated business database.');
$migrate = function (string $path): void {
    if (Artisan::call('migrate', ['--path' => $path, '--force' => true]) !== 0) throw new RuntimeException(Artisan::output());
};
foreach (glob(database_path('migrations/*.php')) as $file) {
    if (basename($file) < '2026_10_21_000011_company_accounts.php') $migrate('database/migrations/'.basename($file));
}
Artisan::call('db:seed', ['--class' => FoundationSeeder::class, '--force' => true]);
$snapshot = [];
$actor = null;
if ($upgrade) {
    $actor = User::factory()->create();
    $actor->roles()->attach(Role::where('name', 'Administrator')->value('id'));
    $scoped = User::factory()->create();
    $branch = DB::table('branches')->insertGetId(['code' => 'BUS-UPGRADE', 'name' => 'Upgrade branch']);
    DB::table('user_branches')->insert(['user_id' => $scoped->id, 'branch_id' => $branch]);
    $account = DB::table('accounts')->insertGetId(['code' => 'BUS-UPGRADE', 'name' => 'Upgrade cash', 'branch_id' => $branch, 'currency_id' => DB::table('company_settings')->value('base_currency_id')]);
    app(LedgerService::class)->post($actor, ['account_id' => $account, 'entry_type' => 'opening', 'amount' => '1234.56', 'rate_to_base' => '1', 'transaction_date' => '2026-01-01', 'description' => 'Isolated migration verification'], 'business-upgrade-opening');
    $category = DB::table('carpet_categories')->insertGetId(['name' => 'Upgrade carpet']);
    $weaver = DB::table('weavers')->insertGetId(['name' => 'Upgrade weaver', 'registration_date' => '2026-01-01']);
    $carpet = DB::table('carpets')->insertGetId(['carpet_code' => 'BUS-UPGRADE', 'name' => 'Upgrade carpet', 'registration_branch_id' => $branch, 'category_id' => $category, 'created_by' => $actor->id]);
    $purchase = DB::table('weaver_purchases')->insertGetId(['purchase_no' => 'BUS-UPGRADE', 'weaver_id' => $weaver, 'branch_id' => $branch,
        'currency_id' => DB::table('company_settings')->value('base_currency_id'), 'rate_to_base' => '1', 'purchase_on' => '2026-01-01', 'created_by' => $actor->id, 'created_at' => now()]);
    $item = DB::table('weaver_purchase_items')->insertGetId(['weaver_purchase_id' => $purchase, 'carpet_id' => $carpet, 'unit_price' => '789.12']);
    DB::table('stock_events')->insert(['carpet_id' => $carpet, 'sequence_no' => 1, 'event_type' => 'purchase', 'resulting_status' => 'purchased',
        'weaver_purchase_item_id' => $item, 'occurred_on' => '2026-01-01', 'handled_by' => $actor->id, 'created_at' => now()]);
    $manager = Role::where('name', 'Branch Manager')->firstOrFail();
    $manager->permissions()->detach(Permission::where('code', 'locations.create')->value('id'));
    $oldGrants = $manager->permissions()->where('module', 'locations')->pluck('code')->sort()->values()->all();
    $stock = (array) DB::table('v_current_stock')->where('id', $carpet)->first();
    foreach (DB::select('SHOW FULL TABLES') as $row) {
        [$table, $type] = array_values((array) $row);
        if ($type === 'BASE TABLE' && ! in_array($table, ['migrations', 'permissions', 'role_permissions'], true)) {
            $columns = Schema::getColumnListing($table);
            $rows = DB::table($table)->get($columns)->map(fn ($r) => json_encode($r))->sort()->values()->all();
            $snapshot[$table] = [$columns, hash('sha256', json_encode($rows))];
        }
    }
}
foreach (glob(database_path('migrations/*.php')) as $file) {
    if (basename($file) >= '2026_10_21_000011_company_accounts.php') $migrate('database/migrations/'.basename($file));
}
foreach ($snapshot as $table => [$columns, $hash]) {
    $rows = DB::table($table)->get($columns)->map(fn ($r) => json_encode($r))->sort()->values()->all();
    if (! hash_equals($hash, hash('sha256', json_encode($rows)))) throw new RuntimeException('Upgrade changed existing data in '.$table);
}
if ($upgrade) {
    if ((array) DB::table('v_current_stock')->where('id', $carpet)->first(array_keys($stock)) !== $stock
        || DB::table('v_account_balances')->where('account_id', $account)->value('balance') !== '1234.56'
        || ! DB::table('user_accounts')->where('user_id', $scoped->id)->where('account_id', $account)->exists()
        || $manager->permissions()->where('module', 'locations')->pluck('code')->sort()->values()->all() !== $oldGrants) {
        throw new RuntimeException('Upgrade failed to preserve scope, stock, balances or customized legacy grants.');
    }
}
echo ($upgrade ? 'Populated upgrade' : 'Fresh migration').' verified in '.$database.'. Existing data, balances and scope preserved.'.PHP_EOL;
