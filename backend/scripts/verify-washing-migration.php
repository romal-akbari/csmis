<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$legacy = ($argv[1] ?? '') === 'legacy';
$database = $legacy ? 'csms_phase4_legacy_fresh' : 'csms_phase4_fresh';
$connection = DB::connection()->getConfig();
$pdo = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'], $connection['username'], $connection['password']);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.connections.mysql.database' => $database,
    'database.connections.mysql.url' => null, 'database.default' => 'mysql']);
DB::purge('mysql');
if (DB::getDatabaseName() !== $database || DB::select('SHOW TABLES')) {
    throw new RuntimeException('This check requires the empty, isolated csms_phase4_fresh database. No existing data was changed.');
}
$target = '2026_10_20_000010_install_washing.php';
foreach (glob(database_path('migrations/*.php')) as $file) {
    if (basename($file) >= $target) {
        continue;
    }
    if (Artisan::call('migrate', ['--path' => 'database/migrations/'.basename($file), '--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
}
Artisan::call('db:seed', ['--class' => FoundationSeeder::class, '--force' => true]);
$manager = Role::where('name', 'Branch Manager')->firstOrFail();
$manager->permissions()->detach(Permission::where('code', 'locations.create')->value('id'));
$custom = Permission::create(['code' => 'custom.migration-check', 'module' => 'custom', 'action' => 'migration-check']);
$manager->permissions()->attach($custom);
$legacyGrants = fn () => $manager->permissions()->where('code', 'not like', 'washing.%')
    ->where('code', 'not like', 'washing-costs.%')->orderBy('code')->pluck('code')->all();
$beforeGrants = $legacyGrants();
$actor = User::factory()->create();
$branch = DB::table('branches')->insertGetId(['code' => 'UPGRADE', 'name' => 'Migration fixture']);
$category = DB::table('carpet_categories')->insertGetId(['name' => 'Migration fixture']);
$weaver = DB::table('weavers')->insertGetId(['name' => 'Migration fixture', 'registration_date' => '2026-01-01']);
$carpet = DB::table('carpets')->insertGetId(['carpet_code' => 'UPGRADE-001', 'name' => 'Migration fixture',
    'registration_branch_id' => $branch, 'category_id' => $category, 'created_by' => $actor->id]);
$purchase = DB::table('weaver_purchases')->insertGetId(['purchase_no' => 'UPGRADE-001', 'branch_id' => $branch,
    'weaver_id' => $weaver, 'currency_id' => DB::table('company_settings')->value('base_currency_id'),
    'rate_to_base' => '1', 'purchase_on' => '2026-01-01', 'created_by' => $actor->id, 'created_at' => now()]);
$item = DB::table('weaver_purchase_items')->insertGetId(['weaver_purchase_id' => $purchase,
    'carpet_id' => $carpet, 'unit_price' => '100.10']);
DB::table('stock_events')->insert(['carpet_id' => $carpet, 'sequence_no' => 1, 'event_type' => 'purchase',
    'resulting_status' => 'purchased', 'weaver_purchase_item_id' => $item, 'occurred_on' => '2026-01-01',
    'handled_by' => $actor->id, 'created_at' => now()]);
$beforeStock = (array) DB::table('v_current_stock')->where('id', $carpet)->first();
if ($legacy) {
    Schema::create('washing_orders', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('carpet_id');
        $table->unsignedInteger('washing_facility_id');
        $table->date('sent_date');
    });
}
if (Artisan::call('migrate', ['--path' => 'database/migrations/'.$target, '--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
if ($beforeGrants !== $legacyGrants() || $beforeStock !== (array) DB::table('v_current_stock')->where('id', $carpet)->first()
    || DB::table('stock_events')->count() !== 1 || ! $manager->permissions()->where('code', 'washing.send')->exists()) {
    throw new RuntimeException('Washing upgrade failed to preserve stock and customized legacy grants.');
}
if ($legacy && (! Schema::hasColumns('legacy_washing_orders', ['carpet_id', 'washing_facility_id', 'sent_date'])
    || DB::table('legacy_washing_orders')->exists())) {
    throw new RuntimeException('Legacy washing structure preservation failed.');
}
echo "Fresh schema and populated upgrade passed; existing stock and customized grants preserved.\n";
