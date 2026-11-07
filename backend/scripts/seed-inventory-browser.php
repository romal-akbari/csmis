<?php

use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'csms_phase1_browser', 'database.connections.mysql.url' => null]);
DB::purge('mysql');
if (DB::getDatabaseName() !== 'csms_phase1_browser') {
    throw new RuntimeException('Browser database only.');
}
$actor = User::where('username', 'browser_admin')->firstOrFail();
auth()->setUser($actor);
$suffix = strtoupper(bin2hex(random_bytes(5)));
$ids = DB::transaction(function () use ($actor, $suffix) {
    DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
    $branch = DB::table('branches')->insertGetId(['code' => 'I-A-'.$suffix, 'name' => 'Inventory Kabul '.$suffix]);
    $other = DB::table('branches')->insertGetId(['code' => 'I-B-'.$suffix, 'name' => 'Inventory Herat '.$suffix]);
    $location = DB::table('stock_locations')->insertGetId(['code' => 'I-L1-'.$suffix, 'name' => 'Inventory shelf '.$suffix, 'branch_id' => $branch, 'location_type' => 'shelf']);
    $destination = DB::table('stock_locations')->insertGetId(['code' => 'I-L2-'.$suffix, 'name' => 'Inventory destination '.$suffix, 'branch_id' => $other, 'location_type' => 'warehouse']);
    $category = DB::table('carpet_categories')->insertGetId(['name' => 'Inventory wool '.$suffix, 'name_fa' => 'قالین پشمی '.$suffix]);
    $province = DB::table('provinces')->insertGetId(['name' => 'Inventory province '.$suffix]);
    $district = DB::table('districts')->insertGetId(['name' => 'Inventory district '.$suffix, 'province_id' => $province]);
    $area = DB::table('areas')->insertGetId(['name' => 'Inventory area '.$suffix, 'district_id' => $district]);
    $manager = DB::table('employees')->insertGetId(['name' => 'Inventory manager '.$suffix, 'branch_id' => $branch]);
    $weaver = DB::table('weavers')->insertGetId(['name' => 'Inventory weaver '.$suffix, 'area_id' => $area, 'manager_employee_id' => $manager, 'registration_date' => '2026-01-01']);
    $currency = DB::table('company_settings')->value('base_currency_id');
    $account = DB::table('accounts')->insertGetId(['code' => 'I-CASH-'.$suffix, 'name' => 'Inventory cash '.$suffix, 'branch_id' => $branch, 'currency_id' => $currency]);
    app(LedgerService::class)->post($actor, ['account_id' => $account, 'entry_type' => 'opening', 'amount' => '5000.00', 'rate_to_base' => '1', 'transaction_date' => '2026-01-01', 'description' => 'Inventory browser fixture'], 'inventory-open-'.$suffix);
    $expenseCategory = DB::table('expense_categories')->where('status', 'active')->value('id');

    return compact('suffix', 'branch', 'other', 'location', 'destination', 'category', 'weaver', 'currency', 'account', 'expenseCategory');
});
echo json_encode($ids, JSON_THROW_ON_ERROR).PHP_EOL;
