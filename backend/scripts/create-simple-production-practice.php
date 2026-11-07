<?php

/** Add one labelled practice order through the normal validated API. Existing business records are preserved. */
use App\Models\User;
use App\Services\DatabaseBackup;
use App\Services\FoundationCatalog;
use App\Services\SystemWriteLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$options = getopt('', ['database:', 'apply']);
$database = $options['database'] ?? '';
if (! in_array($database, ['carpet_sales', 'csms_phase1_browser'], true)) throw new RuntimeException('Specify the intended live or isolated browser database explicitly.');
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database, 'database.connections.mysql.url' => null]);
DB::purge('mysql');
if (DB::getDatabaseName() !== $database) throw new RuntimeException('Database identity mismatch.');
$prefix = 'PRACTICE-20261024';
$currency = DB::table('currencies')->where('id', DB::table('company_settings')->value('base_currency_id'))->where('is_active', true)->first();
if (! $currency) throw new RuntimeException('Configure an active base currency first.');
if (! array_key_exists('apply', $options)) {
    echo json_encode(['mode' => 'read-only', 'database' => $database, 'prefix' => $prefix, 'currency' => $currency->code,
        'adds' => 'One production order, two finished carpets and linked practice supplies, stores, manager, cash account and worker support. Existing records and settings are preserved.'], JSON_PRETTY_PRINT).PHP_EOL;
    exit;
}
if (DB::table('branches')->where('code', 'PRAC261024')->exists()) throw new RuntimeException('Practice data already exists; refusing duplicate creation.');
$snapshot = function (): array {
    $data = [];
    foreach (DB::select('SHOW FULL TABLES') as $row) {
        [$table, $type] = array_values((array) $row);
        if ($type !== 'BASE TABLE' || $table === 'document_sequences') continue; // Number counters advance normally.
        $data[$table] = DB::table($table)->get()->map(fn ($item) => hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)))->countBy()->all();
    }
    return $data;
};
$directory = storage_path('app/private/simple-production-practice/'.$database);
if (! is_dir($directory) && ! mkdir($directory, 0700, true)) throw new RuntimeException('Could not create the private manifest folder.');
try {
    $result = app(SystemWriteLock::class)->run(function () use ($app, $database, $prefix, $currency, $snapshot, $directory) {
        $backup = $database === 'carpet_sales' ? app(DatabaseBackup::class)->create() : null;
        $before = $snapshot();
        return DB::transaction(function () use ($app, $database, $prefix, $currency, $snapshot, $before, $backup) {
            $actor = User::where('is_active', true)->whereHas('roles', fn ($query) => $query->where('name', 'Administrator'))->orderBy('id')->firstOrFail();
            $token = $actor->createToken('authorized-simple-production-practice', ['*'], now()->addMinutes(15));
            $kernel = $app->make(HttpKernel::class); $calls = []; $counter = 0;
            $call = function (string $method, string $path, array $data = [], ?string $key = null, int $expected = 0) use ($app, $kernel, $token, $prefix, &$counter, &$calls): array {
                $app->make('auth')->forgetGuards();
                $request = Request::create('/api'.$path, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token->plainTextToken, 'HTTP_IDEMPOTENCY_KEY' => $key ?? $prefix.':'.(++$counter),
                    'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Authorized CSMS practice workflow'], json_encode($data, JSON_THROW_ON_ERROR));
                $response = $kernel->handle($request); $status = $response->getStatusCode();
                $body = json_decode($response->getContent(), true); $calls[] = compact('method', 'path', 'status');
                if ($expected ? $status !== $expected : ($status < 200 || $status >= 300)) throw new RuntimeException($method.' '.$path.' failed: '.json_encode(['status' => $status, 'message' => $body['message'] ?? '', 'errors' => $body['errors'] ?? []]));
                return $body ?? [];
            };
            $create = fn (string $path, array $data) => $call('POST', $path, $data)['data'];
            $today = FoundationCatalog::today(); $ids = [];
            $ids['branch'] = $create('/foundation/branches', ['code' => 'PRAC261024', 'name' => 'PRACTICE - Simple Kabul Production', 'location' => 'Practice records only', 'is_main' => false, 'status' => 'active'])['id'];
            $ids['materials_store'] = $create('/foundation/locations', ['code' => $prefix.'-RAW', 'name' => 'PRACTICE - Materials store', 'branch_id' => $ids['branch'], 'location_type' => 'warehouse', 'status' => 'active'])['id'];
            $ids['finished_store'] = $create('/foundation/locations', ['code' => $prefix.'-FIN', 'name' => 'PRACTICE - Finished carpets', 'branch_id' => $ids['branch'], 'location_type' => 'warehouse', 'status' => 'active'])['id'];
            $ids['facility'] = $create('/foundation/facilities', ['name' => 'PRACTICE - Safa Carpet Washing', 'contact_person' => 'Practice washing contact', 'status' => 'active'])['id'];
            $ids['washing_location'] = $create('/foundation/locations', ['code' => $prefix.'-WASH', 'name' => 'PRACTICE - Washing yard', 'washing_facility_id' => $ids['facility'], 'location_type' => 'washing', 'status' => 'active'])['id'];
            $ids['category'] = $create('/foundation/categories', ['name' => 'PRACTICE - Chobi wool', 'status' => 'active'])['id'];
            $ids['manager'] = $create('/production/managers', ['code' => $prefix.'-M', 'name' => 'PRACTICE - Ahmad Rahimi', 'branch_id' => $ids['branch'], 'status' => 'active'])['id'];
            $ids['material'] = $create('/business/materials', ['code' => $prefix.'-WOOL', 'name' => 'PRACTICE - Natural wool', 'unit' => 'kg'])['id'];
            $ids['tool'] = $create('/production/tools', ['code' => $prefix.'-COMB', 'name' => 'PRACTICE - Weaving comb', 'status' => 'active'])['id'];
            $ids['account'] = $create('/foundation/accounts', ['code' => $prefix.'-CASH', 'name' => 'PRACTICE - Production cash', 'currency_id' => $currency->id, 'account_type' => 'cash', 'status' => 'active', 'opening_amount' => '100000', 'opening_on' => $today, 'opening_rate' => '1'])['id'];
            $call('POST', '/production/setup', ['branch_id' => $ids['branch'], 'materials_location_id' => $ids['materials_store'], 'finished_location_id' => $ids['finished_store'], 'facility_id' => $ids['facility'], 'facility_location_id' => $ids['washing_location'], 'payment_trigger' => 'stock_acceptance']);
            $payment = fn (string $amount, string $reason) => ['account_id' => $ids['account'], 'amount' => $amount, 'rate_to_base' => '1', 'units_per_payment_unit' => '1', 'payment_on' => $today, 'reason' => 'PRACTICE - '.$reason];
            $supply = ['branch_id' => $ids['branch'], 'currency_id' => $currency->id, 'rate_to_base' => '1', 'purchased_on' => $today,
                'items' => [['kind' => 'material', 'item_id' => $ids['material'], 'quantity' => '20', 'unit_cost' => '400'], ['kind' => 'tool', 'item_id' => $ids['tool'], 'quantity' => '2', 'unit_cost' => '750']], 'payment' => $payment('9500', 'Supplies purchase')];
            $ids['supply_purchase'] = $call('POST', '/production/supply-purchases', $supply, $prefix.':supplies')['data']['id'];
            $call('POST', '/production/supply-purchases', $supply, $prefix.':supplies', 200);
            $order = $create('/production/orders', ['manager_id' => $ids['manager'], 'branch_id' => $ids['branch'], 'currency_id' => $currency->id, 'rate_to_base' => '1', 'ordered_on' => $today, 'due_on' => $today,
                'description' => 'PRACTICE - Two Chobi carpets, complete simple production workflow', 'payment_trigger' => 'stock_acceptance',
                'lines' => [['name' => 'PRACTICE - Chobi floral', 'category_id' => $ids['category'], 'target_quantity' => 2, 'pricing_method' => 'area', 'rate' => '600']],
                'factors' => [['label' => 'Agreed border finishing', 'kind' => 'fixed', 'operation' => 'add', 'value' => '1200']]]);
            $ids['order'] = $order['id']; $path = '/production/orders/'.$order['id'];
            $line = $call('GET', $path)['data']['lines'][0]['id'];
            $issue = ['movement_type' => 'issue', 'moved_on' => $today, 'materials' => [['material_id' => $ids['material'], 'quantity' => '16']], 'tools' => [['tool_id' => $ids['tool'], 'quantity' => 2]], 'advance' => $payment('2000', 'Production advance'), 'reason' => 'PRACTICE - Give wool, reusable combs and advance'];
            $bad = $issue; $bad['materials'][0]['quantity'] = '21'; $call('POST', $path.'/issue', $bad, null, 422);
            $call('POST', $path.'/issue', $issue);
            foreach (['0', '1'] as $index => $waste) {
                $receive = ['delivered_on' => $today, 'items' => [['line_id' => $line, 'carpet_code' => 'PRACTICE-261024-'.($index + 1), 'length_cm' => '300', 'width_cm' => '200']],
                    'usages' => [['material_id' => $ids['material'], 'used_quantity' => '7', 'waste_quantity' => $waste]]];
                $call('POST', $path.'/receive', $receive);
                $items = $call('GET', $path.'/workflow')['data']['washing_items'];
                $accept = ['returned_on' => $today, 'confirmed_received' => true, 'record_cost_later' => false, 'washing_amount' => '500', 'payment' => $payment('500', 'Actual washing charge'),
                    'items' => array_map(fn ($item) => ['washing_order_item_id' => $item['washing_order_item_id'], 'expected_sequence' => $item['expected_sequence']], $items)];
                $bad = $accept; $bad['confirmed_received'] = false; $call('POST', $path.'/accept', $bad, null, 422);
                $call('POST', $path.'/accept', $accept);
            }
            $call('POST', $path.'/settle', $payment('6401', 'Reject excess payment'), null, 422);
            $call('POST', $path.'/settle', $payment('6400', 'Remaining production earnings'));
            $call('POST', $path.'/issue', ['movement_type' => 'return', 'moved_on' => $today, 'materials' => [['material_id' => $ids['material'], 'quantity' => '1']], 'tools' => [['tool_id' => $ids['tool'], 'quantity' => 2]], 'reason' => 'PRACTICE - Return unused wool and both usable combs']);
            $assistance = $create('/production/managers/'.$ids['manager'].'/assistance', ['branch_id' => $ids['branch'], 'manager_id' => $ids['manager'], 'beneficiary_name' => 'PRACTICE - Abdul Rahman', 'assistance_type' => 'advance', 'responsible_party' => 'worker', 'recovery_terms' => 'Practice cash instalments by agreement',
                'currency_id' => $currency->id, 'rate_to_base' => '1', 'amount' => '3000', 'assistance_on' => $today, 'reason' => 'PRACTICE - Personal family support, separate from production earnings',
                'payment' => $payment('3000', 'Worker assistance via manager'), 'delivery' => ['amount' => '2500', 'delivered_on' => $today, 'acknowledgement' => 'PRACTICE - Manager confirms worker received 2500']]);
            $ids['assistance'] = $assistance['id'];
            $call('POST', '/production/assistance/'.$assistance['id'].'/return', $payment('500', 'Manager returns unused assistance funds'));
            $call('POST', '/production/assistance/'.$assistance['id'].'/recover', $payment('1000', 'Worker cash repayment'));
            $final = $call('GET', $path)['data'];
            $support = $call('GET', '/production/assistance/'.$assistance['id'])['data'];
            $balance = DB::table('v_account_balances')->where('account_id', $ids['account'])->value('balance');
            $stock = DB::table('v_current_stock')->where('manager_production_order_id', $ids['order'])->get(['id', 'carpet_code', 'location_id', 'stock_status']);
            $context = $call('GET', $path.'/workflow')['data'];
            if ($final['state'] !== 'completed' || $final['balance_due'] !== '0.00' || $balance !== '79600.00' || $stock->count() !== 2
                || $stock->where('stock_status', 'in_stock')->count() !== 2 || $support['with_manager_amount'] !== '0.00' || $support['advance_outstanding'] !== '1500.00'
                || (float) $context['materials'][0]['warehouse_quantity'] !== 5.0 || (float) $context['materials'][0]['held_quantity'] !== 0.0 || (int) $context['tools'][0]['warehouse_quantity'] !== 2) throw new RuntimeException('Practice reconciliation failed; all practice changes will roll back.');
            $token->accessToken->delete();
            $after = $snapshot();
            foreach ($before as $table => $hashes) foreach ($hashes as $hash => $count) if (($after[$table][$hash] ?? 0) < $count) throw new RuntimeException('An existing record changed: '.$table);
            return ['database' => $database, 'prefix' => $prefix, 'date' => $today, 'currency' => $currency->code, 'ids' => $ids, 'order_no' => $final['order_no'],
                'earned' => $final['earned_amount'], 'production_paid' => $final['paid_amount'], 'production_balance' => $final['balance_due'], 'cash_balance' => $balance, 'worker_assistance_outstanding' => $support['advance_outstanding'],
                'stock' => $stock, 'materials_in_store' => '5 kg', 'tools_in_store' => 2, 'original_tables_preserved' => count($before), 'backup' => $backup, 'api_checks' => $calls];
        });
    });
    file_put_contents($directory.'/records.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    chmod($directory.'/records.json', 0600);
    echo json_encode(['committed' => true, 'manifest' => $directory.'/records.json'] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1);
}
