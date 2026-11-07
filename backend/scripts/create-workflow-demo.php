<?php

/** Explicit, additive demonstration data; uses the real validated API pipeline. */
use App\Models\User;
use App\Services\DatabaseBackup;
use App\Services\FoundationCatalog;
use App\Services\SystemWriteLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$options = getopt('', ['database:', 'apply']);
$database = $options['database'] ?? '';
if (! in_array($database, ['carpet_sales', 'csms_phase1_browser'], true)) {
    throw new RuntimeException('Specify --database=carpet_sales or --database=csms_phase1_browser.');
}
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database,
    'database.connections.mysql.url' => null]);
if ($database !== 'carpet_sales') {
    config(['filesystems.disks.local.root' => storage_path('app/workflow-demo-test')]);
}
DB::purge('mysql');
if (DB::getDatabaseName() !== $database) {
    throw new RuntimeException('Database identity did not match the explicit target.');
}
$prefix = 'DEMO-20261019';
$apply = array_key_exists('apply', $options);
if (! $apply) {
    echo json_encode(['mode' => 'read-only plan', 'database' => $database, 'prefix' => $prefix,
        'adds' => '2 branches, 2 accounts, directories, scoped user/role, 9 carpets and linked workflow examples',
        'preserves' => 'All existing records and settings',
        'run' => 'Add --apply only when creation in this database is intended.'], JSON_PRETTY_PRINT).PHP_EOL;
    exit;
}
if (DB::table('branches')->whereIn('code', ['DEMO-KBL-261019', 'DEMO-HRT-261019'])->exists()) {
    throw new RuntimeException('This demonstration already exists. Refusing duplicate creation or modification.');
}
$directory = storage_path('app/private/workflow-demo/'.$database.'/'.$prefix);
if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
    throw new RuntimeException('Could not prepare the private demonstration handover folder.');
}
$password = 'Demo!'.bin2hex(random_bytes(10));
$username = 'demo_workflow_261019';
$calls = [];
$files = [];
$committed = false;
$snapshot = function (): array {
    $data = [];
    foreach (DB::select('SHOW FULL TABLES') as $row) {
        [$table, $type] = array_values((array) $row);
        if ($type !== 'BASE TABLE') {
            continue;
        }
        $data[$table] = DB::table($table)->get()->map(fn ($item) => hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)))->countBy()->all();
    }

    return $data;
};
$before = $snapshot();
try {
    $result = app(SystemWriteLock::class)->run(function () use ($app, $database, $directory,
        $prefix, $password, $username, &$calls, &$files, $before, $snapshot) {
        $backup = $database === 'carpet_sales' ? app(DatabaseBackup::class)->create($directory.'/before') : null;

        return DB::transaction(function () use ($app, $database, $directory, $prefix, $password,
            $username, &$calls, &$files, $before, $snapshot, $backup) {
            $actor = User::where('is_active', true)->whereHas('roles', fn ($query) => $query->where('name', 'Administrator'))->orderBy('id')->firstOrFail();
            $tokenModel = $actor->createToken('workflow-demo-creation', ['*'], now()->addMinutes(30));
            $token = $tokenModel->plainTextToken;
            $kernel = $app->make(HttpKernel::class);
            $counter = 0;
            $call = function (string $method, string $path, array $body = [], ?string $key = null,
                ?int $expected = null, ?string $bearer = null, array $uploads = []) use ($app, $kernel,
                $token, $prefix, &$counter, &$calls): array {
                $app->make('auth')->forgetGuards();
                $headers = ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.($bearer ?? $token),
                    'HTTP_IDEMPOTENCY_KEY' => $key ?? $prefix.':step:'.(++$counter),
                    'HTTP_USER_AGENT' => 'CSMS authorized workflow demonstration', 'REMOTE_ADDR' => '127.0.0.1'];
                if (! $uploads) {
                    $headers['CONTENT_TYPE'] = 'application/json';
                }
                $request = Request::create('/api'.$path, $method, $uploads ? $body : [], [], $uploads,
                    $headers, $uploads ? null : json_encode($body, JSON_THROW_ON_ERROR));
                $response = $kernel->handle($request);
                $status = $response->getStatusCode();
                $decoded = json_decode($response->getContent(), true);
                $calls[] = ['method' => $method, 'path' => $path, 'status' => $status];
                if ($expected !== null ? $status !== $expected : ($status < 200 || $status >= 300)) {
                    throw new RuntimeException($method.' '.$path.' failed ('.$status.'): '.json_encode([
                        'message' => $decoded['message'] ?? 'Invalid response', 'errors' => $decoded['errors'] ?? [],
                    ], JSON_THROW_ON_ERROR));
                }

                return $decoded ?? [];
            };
            $create = fn (string $path, array $body): array => $call('POST', $path, $body)['data'];
            $today = FoundationCatalog::today();
            $base = DB::table('company_settings')->value('base_currency_id');
            $usd = DB::table('currencies')->where('code', 'USD')->where('is_active', true)->firstOrFail();
            $afn = DB::table('currencies')->where('code', 'AFN')->where('is_active', true)->firstOrFail();
            if ((int) $base !== (int) $usd->id) {
                throw new RuntimeException('This USD example requires the existing USD base currency; settings will not be changed.');
            }
            $ids = ['usd' => $usd->id, 'afn' => $afn->id];
            foreach (['kabul' => ['DEMO-KBL-261019', 'DEMO Kabul'],
                'herat' => ['DEMO-HRT-261019', 'DEMO Herat']] as $name => [$code, $label]) {
                $ids[$name] = $create('/foundation/branches', ['code' => $code, 'name' => $label,
                    'location' => 'Fictional training branch; not real business stock', 'is_main' => false, 'status' => 'active'])['id'];
            }
            $ids['facility'] = $create('/foundation/facilities', ['name' => 'DEMO Washing Facility',
                'location' => 'Training reference only; washing operations are a future phase',
                'contact_name' => 'DEMO Contact', 'status' => 'active'])['id'];
            foreach (['shelf_a' => ['DEMO-KBL-A-261019', 'DEMO Kabul Shelf A', $ids['kabul'], 'shelf'],
                'shelf_b' => ['DEMO-KBL-B-261019', 'DEMO Kabul Shelf B', $ids['kabul'], 'shelf'],
                'showroom' => ['DEMO-HRT-S-261019', 'DEMO Herat Showroom', $ids['herat'], 'showroom']] as $name => [$code, $label, $branch, $type]) {
                $ids[$name] = $create('/foundation/locations', ['code' => $code, 'name' => $label,
                    'branch_id' => $branch, 'washing_facility_id' => null, 'location_type' => $type, 'status' => 'active'])['id'];
            }
            $ids['wash_location'] = $create('/foundation/locations', ['code' => 'DEMO-WASH-261019',
                'name' => 'DEMO Washing Holding Area', 'branch_id' => null, 'washing_facility_id' => $ids['facility'],
                'location_type' => 'washing', 'status' => 'active'])['id'];
            $ids['category'] = $create('/foundation/categories', ['name' => 'DEMO Handwoven Wool',
                'description' => 'Fictional training carpets', 'status' => 'active'])['id'];
            $ids['expense_category'] = $create('/foundation/expense-categories', ['name' => 'DEMO Weaver Bonus', 'status' => 'active'])['id'];
            $ids['manager'] = $create('/foundation/employees', ['name' => 'DEMO Manager', 'position' => 'Training manager',
                'branch_id' => $ids['kabul'], 'joining_date' => $today, 'status' => 'active'])['id'];
            $ids['province'] = $create('/directories/provinces', ['name' => 'DEMO Province', 'status' => 'active'])['id'];
            $ids['district'] = $create('/directories/districts', ['name' => 'DEMO District', 'province_id' => $ids['province'], 'status' => 'active'])['id'];
            $ids['area'] = $create('/directories/areas', ['name' => 'DEMO Area', 'district_id' => $ids['district'], 'status' => 'active'])['id'];
            $ids['weaver'] = $create('/directories/weavers', ['name' => 'DEMO Weaver', 'area_id' => $ids['area'],
                'manager_employee_id' => $ids['manager'], 'registration_date' => $today,
                'notes' => 'Fictional training record; no real person or payment.', 'status' => 'active'])['id'];
            $ids['supplier'] = $create('/directories/suppliers', ['name' => 'DEMO Wool Supplier',
                'address' => 'Fictional training address', 'notes' => 'Directory demonstration only.', 'status' => 'active'])['id'];
            $customer = ['name' => 'DEMO Retail Customer', 'customer_type' => 'retail', 'registration_date' => $today,
                'address' => 'Fictional training address', 'notes' => 'No real customer or sale.', 'status' => 'active'];
            $ids['customer'] = $create('/customers', $customer)['id'];
            $customer['name'] = 'DEMO Archive Customer';
            $ids['archived_customer'] = $create('/customers', $customer)['id'];
            $call('DELETE', '/customers/'.$ids['archived_customer']);
            $call('POST', '/customers/'.$ids['archived_customer'].'/restore');
            $call('DELETE', '/customers/'.$ids['archived_customer']);
            $roleIds = DB::table('roles')->whereIn('name', ['Branch Manager', 'Accountant'])->pluck('id');
            $permissionIds = DB::table('permissions')->whereIn('id', DB::table('role_permissions')->whereIn('role_id', $roleIds)->select('permission_id'))
                ->where('code', 'not like', 'users.%')->where('code', 'not like', 'settings.%')->pluck('id')->all();
            $ids['role'] = $create('/foundation/roles', ['name' => 'DEMO - Workflow Operator',
                'description' => 'Training role for the two DEMO branches; no system administration.', 'permission_ids' => $permissionIds])['id'];
            $ids['user'] = $create('/foundation/users', ['name' => 'DEMO Workflow Operator', 'username' => $username,
                'password' => $password, 'employee_id' => $ids['manager'], 'locale' => 'en', 'is_active' => true,
                'role_ids' => [$ids['role']], 'branch_ids' => [$ids['kabul'], $ids['herat']], 'facility_ids' => [$ids['facility']]])['id'];
            foreach (['usd_cash' => ['DEMO-USD-261019', 'DEMO USD Cash', $usd->id],
                'afn_cash' => ['DEMO-AFN-261019', 'DEMO AFN Cash', $afn->id]] as $name => [$code, $label, $currency]) {
                $ids[$name] = $create('/foundation/accounts', ['code' => $code, 'name' => $label,
                    'branch_id' => $ids['kabul'], 'currency_id' => $currency, 'account_type' => 'cash', 'status' => 'active'])['id'];
            }
            $rateDate = now()->subDays(7)->toDateString();
            $existingRate = DB::table('exchange_rates')->where('base_currency_id', $usd->id)->where('quote_currency_id', $afn->id)
                ->where('effective_date', $rateDate)->first();
            if (! $existingRate) {
                $ids['exchange_rate'] = $create('/foundation/exchange-rates', ['base_currency_id' => $usd->id,
                    'quote_currency_id' => $afn->id, 'rate' => '70.00000000', 'effective_date' => $rateDate])['id'];
            }
            $ledger = fn (int $account, string $type, string $amount, string $rate, string $description): array => $create('/foundation/ledger', ['account_id' => $account, 'entry_type' => $type, 'amount' => $amount,
                'rate_to_base' => $rate, 'transaction_date' => $today, 'description' => 'DEMO ONLY: '.$description]);
            $ledger($ids['usd_cash'], 'opening', '10000.00', '1', 'fictional opening cash');
            $ledger($ids['afn_cash'], 'opening', '14000.00', '0.01428571', 'fictional AFN opening; illustrative rate only');
            $ledger($ids['usd_cash'], 'income', '250.00', '1', 'sample other income');
            $wrongExpense = $ledger($ids['usd_cash'], 'expense', '40.00', '1', 'incorrect expense to reverse');
            $call('POST', '/foundation/ledger/'.$wrongExpense['id'].'/reverse', ['reason' => 'DEMO correction of sample expense']);
            $carpets = [];
            $names = ['Transferred wool carpet', 'Corrected location carpet', 'Returned carpet', 'Voided purchase carpet',
                'Practice draft carpet', 'Cancelled draft carpet', 'Awaiting receipt carpet', 'Missing carpet', 'AFN purchase carpet'];
            foreach ($names as $index => $name) {
                $number = $index + 1;
                $carpets[$number] = $create('/inventory/carpets', ['carpet_code' => $prefix.'-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                    'name' => 'DEMO '.$name, 'registration_branch_id' => $ids['kabul'], 'category_id' => $ids['category'],
                    'length_cm' => '200.00', 'width_cm' => '150.00', 'size_label' => '2m x 1.5m',
                    'color' => 'Navy and ivory', 'material' => 'Wool', 'notes' => 'Fictional training carpet. No real stock.'])['id'];
            }
            $imagePath = dirname(__DIR__, 2).'/frontend/public/images/collection-herati.png';
            $upload = $call('POST', '/inventory/carpets/'.$carpets[1].'/images',
                ['alt_text' => 'DEMO sample illustration, reused project artwork; not a photograph of actual stock'], null, null, null,
                ['image' => new UploadedFile($imagePath, 'demo-carpet.png', 'image/png', null, true)]);
            $files[] = DB::table('carpet_images')->where('id', $upload['data']['id'])->value('storage_path');
            $purchase = function (string $ref, array $lines, ?int $currency = null, string $rate = '1') use ($create, $ids, $today, $prefix): array {
                return $create('/inventory/purchases', ['purchase_no' => $prefix.'-'.$ref, 'weaver_id' => $ids['weaver'],
                    'branch_id' => $ids['kabul'], 'purchase_on' => $today, 'currency_id' => $currency ?? $ids['usd'],
                    'rate_to_base' => $rate, 'notes' => 'DEMO ONLY: fictional purchase', 'items' => $lines]);
            };
            $item = fn (int $number): array => ['carpet_id' => $carpets[$number],
                'expected_sequence' => (int) DB::table('v_current_stock')->where('id', $carpets[$number])->value('sequence_no')];
            $receive = fn (string $ref, array $numbers): array => $create('/inventory/receipts', ['receipt_no' => $prefix.'-'.$ref,
                'received_on' => $today, 'location_id' => $ids['shelf_a'], 'notes' => 'DEMO physical arrival', 'items' => array_map($item, $numbers)]);
            $payment = function (string $ref, int $purchaseId, string $amount, string $conversion = '1') use ($create, $prefix, $ids, $today): array {
                return $create('/inventory/payments', ['payment_no' => $prefix.'-'.$ref, 'weaver_id' => $ids['weaver'],
                    'branch_id' => $ids['kabul'], 'account_id' => $ids['usd_cash'], 'payment_type' => 'purchase',
                    'amount' => $amount, 'rate_to_base' => '1', 'payment_on' => $today, 'reason' => 'DEMO fictional purchase payment',
                    'allocations' => [['weaver_purchase_id' => $purchaseId, 'amount_in_payment_currency' => $amount,
                        'purchase_units_per_payment_unit' => $conversion]]]);
            };
            $refund = fn (string $ref, int $purchaseId, string $amount): array => $create('/inventory/corrections/refunds', [
                'refund_no' => $prefix.'-'.$ref, 'weaver_purchase_id' => $purchaseId, 'account_id' => $ids['usd_cash'],
                'amount' => $amount, 'rate_to_base' => '1', 'purchase_units_per_refund_unit' => '1',
                'refunded_on' => $today, 'reason' => 'DEMO fictional money received from weaver']);
            $purchases = [];
            $purchases['MAIN'] = $purchase('MAIN', [['carpet_id' => $carpets[1], 'unit_price' => '100.10'],
                ['carpet_id' => $carpets[2], 'unit_price' => '200.20']])['id'];
            $receive('RECEIPT-MAIN', [1, 2]);
            $transferBody = ['transfer_no' => $prefix.'-TRANSFER', 'transferred_on' => $today,
                'from_location_id' => $ids['shelf_a'], 'to_location_id' => $ids['showroom'],
                'notes' => 'DEMO branch transfer', 'items' => [$item(1)]];
            $transfer = $call('POST', '/inventory/transfers', $transferBody, $prefix.':transfer-once');
            $replay = $call('POST', '/inventory/transfers', $transferBody, $prefix.':transfer-once', 200);
            if (! $replay['replayed'] || $replay['data']['id'] !== $transfer['data']['id']) {
                throw new RuntimeException('Duplicate transfer did not replay the original document.');
            }
            foreach (['relocate', 'missing', 'found'] as $type) {
                $create('/inventory/corrections/adjustments', ['adjustment_no' => $prefix.'-'.strtoupper($type),
                    'branch_id' => $ids['kabul'], 'adjustment_type' => $type,
                    ...($type === 'missing' ? [] : ['location_id' => $ids['shelf_b']]),
                    'adjusted_on' => $today, 'reason' => 'DEMO stock correction: '.$type, 'items' => [$item(2)]]);
            }
            $payment('PAY-PART', $purchases['MAIN'], '100.05');
            $wrongPayment = $payment('PAY-WRONG', $purchases['MAIN'], '20.00');
            $call('POST', '/foundation/ledger/'.$wrongPayment['account_transaction_id'].'/reverse', ['reason' => 'DEMO incorrect payment reversed']);
            $payment('PAY-FINAL', $purchases['MAIN'], '200.25');
            $create('/inventory/payments', ['payment_no' => $prefix.'-BONUS', 'weaver_id' => $ids['weaver'],
                'branch_id' => $ids['kabul'], 'account_id' => $ids['usd_cash'], 'payment_type' => 'additional',
                'amount' => '25.00', 'rate_to_base' => '1', 'payment_on' => $today,
                'expense_category_id' => $ids['expense_category'], 'reason' => 'DEMO additional weaver bonus', 'allocations' => []]);
            $purchases['RETURN'] = $purchase('RETURN', [['carpet_id' => $carpets[3], 'unit_price' => '150.00']])['id'];
            $receive('RECEIPT-RETURN', [3]);
            $payment('PAY-RETURN', $purchases['RETURN'], '150.00');
            $create('/inventory/corrections/returns', ['return_no' => $prefix.'-RETURN-01', 'weaver_purchase_id' => $purchases['RETURN'],
                'from_location_id' => $ids['shelf_a'], 'returned_on' => $today, 'reason' => 'DEMO carpet returned to weaver', 'items' => [$item(3)]]);
            $wrongRefund = $refund('REFUND-WRONG', $purchases['RETURN'], '25.00');
            $call('POST', '/foundation/ledger/'.$wrongRefund['account_transaction_id'].'/reverse', ['reason' => 'DEMO incorrect refund reversed']);
            $refund('REFUND-PART', $purchases['RETURN'], '100.00');
            $draftBody = ['purchase_no' => $prefix.'-VOID', 'branch_id' => $ids['kabul'], 'weaver_id' => $ids['weaver'],
                'purchase_on' => $today, 'currency_id' => $ids['usd'], 'rate_to_base' => '1',
                'notes' => 'DEMO draft save, edit and post', 'items' => [['carpet_id' => $carpets[4], 'unit_price' => '110.00']]];
            $draft = $create('/inventory/corrections/drafts', $draftBody);
            $draftBody['items'][0]['unit_price'] = '120.00';
            $draftBody['version'] = $draft['version'];
            $edited = $call('PUT', '/inventory/corrections/drafts/'.$draft['id'], $draftBody)['data'];
            $posted = $call('POST', '/inventory/corrections/drafts/'.$draft['id'].'/post', ['version' => $edited['version']])['data'];
            $purchases['VOID'] = $posted['posted_purchase_id'];
            $payment('PAY-VOID', $purchases['VOID'], '40.00');
            $call('POST', '/inventory/purchases/'.$purchases['VOID'].'/void', ['reason' => 'DEMO unreceived purchase cancelled',
                'voided_on' => $today, 'items' => [$item(4)]]);
            $refund('REFUND-VOID', $purchases['VOID'], '40.00');
            $drafts = ['posted_void' => $draft['id']];
            foreach ([5 => ['PRACTICE', '95.00'], 6 => ['CANCEL', '80.00']] as $number => [$ref, $price]) {
                $body = $draftBody;
                unset($body['version']);
                $body['purchase_no'] = $prefix.'-'.$ref;
                $body['notes'] = 'DEMO practice draft; no payable or stock yet';
                $body['items'] = [['carpet_id' => $carpets[$number], 'unit_price' => $price]];
                $saved = $create('/inventory/corrections/drafts', $body);
                $drafts[$ref] = $saved['id'];
                if ($ref === 'CANCEL') {
                    $call('POST', '/inventory/corrections/drafts/'.$saved['id'].'/cancel', ['version' => $saved['version'], 'reason' => 'DEMO abandoned draft']);
                }
            }
            $purchases['WAIT'] = $purchase('WAIT', [['carpet_id' => $carpets[7], 'unit_price' => '80.00']])['id'];
            $purchases['MISSING'] = $purchase('MISSING', [['carpet_id' => $carpets[8], 'unit_price' => '90.00']])['id'];
            $receive('RECEIPT-MISSING', [8]);
            $create('/inventory/corrections/adjustments', ['adjustment_no' => $prefix.'-MISSING-08', 'branch_id' => $ids['kabul'],
                'adjustment_type' => 'missing', 'adjusted_on' => $today, 'reason' => 'DEMO leave missing for practice finding', 'items' => [$item(8)]]);
            $purchases['FX'] = $purchase('FX', [['carpet_id' => $carpets[9], 'unit_price' => '700.00']], $ids['afn'], '0.01428571')['id'];
            $receive('RECEIPT-FX', [9]);
            $payment('PAY-FX', $purchases['FX'], '10.00', '70');
            $call('POST', '/customers', ['name' => 'X'], null, 422);
            $badMove = $transferBody;
            $badMove['transfer_no'] = $prefix.'-STALE';
            $call('POST', '/inventory/transfers', $badMove, null, 409);
            $badPayment = ['payment_no' => $prefix.'-OVERPAY', 'weaver_id' => $ids['weaver'], 'branch_id' => $ids['kabul'],
                'account_id' => $ids['usd_cash'], 'payment_type' => 'purchase', 'amount' => '1.00', 'rate_to_base' => '1',
                'payment_on' => $today, 'reason' => 'DEMO reject overpayment', 'allocations' => [['weaver_purchase_id' => $purchases['MAIN'],
                    'amount_in_payment_currency' => '1.00', 'purchase_units_per_payment_unit' => '1']]];
            $call('POST', '/inventory/payments', $badPayment, null, 422);
            $login = $call('POST', '/login', ['username' => $username, 'password' => $password]);
            $profile = $call('GET', '/me', [], null, 200, $login['token'])['data'];
            if ($profile['is_administrator'] || count($profile['branch_ids']) !== 2) {
                throw new RuntimeException('Training account scope verification failed.');
            }
            $call('GET', '/system/reset-preview', [], null, 403, $login['token']);
            $call('POST', '/logout', [], null, 200, $login['token']);
            $tokenModel->accessToken->delete();
            $balances = DB::table('v_account_balances')->whereIn('account_id', [$ids['usd_cash'], $ids['afn_cash']])->pluck('balance', 'account_id');
            if ($balances[$ids['usd_cash']] !== '9864.70' || $balances[$ids['afn_cash']] !== '14000.00') {
                throw new RuntimeException('Demonstration cash did not reconcile: '.json_encode($balances));
            }
            $expected = ['MAIN' => '0.00', 'RETURN' => '-50.00', 'VOID' => '0.00', 'WAIT' => '80.00', 'MISSING' => '90.00', 'FX' => '0.00'];
            foreach ($expected as $ref => $amount) {
                if (DB::table('v_weaver_purchase_balances')->where('purchase_id', $purchases[$ref])->value('remaining_amount') !== $amount) {
                    throw new RuntimeException('Purchase reconciliation failed: '.$ref);
                }
            }
            $stock = DB::table('v_current_stock')->whereIn('id', $carpets)->get(['id', 'carpet_code', 'name', 'stock_status', 'location_id', 'sequence_no']);
            $counts = $stock->countBy('stock_status')->all();
            ksort($counts);
            if ($counts !== ['in_stock' => 3, 'missing' => 1, 'purchased' => 1, 'registered' => 3, 'returned' => 1]) {
                throw new RuntimeException('Stock state reconciliation failed.');
            }
            $after = $snapshot();
            foreach ($before as $table => $hashes) {
                foreach ($hashes as $hash => $count) {
                    if (($after[$table][$hash] ?? 0) < $count) {
                        throw new RuntimeException('Existing data changed: '.$table);
                    }
                }
            }
            $credentials = ['url' => 'http://localhost:3100', 'username' => $username, 'password' => $password,
                'scope' => 'Only the two DEMO branches; no system administration', 'created_for' => $database];
            file_put_contents($directory.'/credentials.json', json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            chmod($directory.'/credentials.json', 0600);

            return ['database' => $database, 'prefix' => $prefix, 'date' => $today,
                'actor_id' => $actor->id, 'ids' => $ids, 'carpet_ids' => $carpets, 'purchase_ids' => $purchases,
                'draft_ids' => $drafts, 'stock' => $stock->all(), 'stock_counts' => $counts,
                'cash' => ['USD' => $balances[$ids['usd_cash']], 'AFN' => $balances[$ids['afn_cash']]],
                'purchase_remaining' => $expected, 'original_tables_preserved' => count($before),
                'original_rows_preserved' => array_sum(array_map('array_sum', $before)),
                'backup' => $backup ? ['path' => $backup['path'], 'sha256' => $backup['sha256']] : null,
                'api_calls' => $calls, 'username' => $username, 'credentials_file' => $directory.'/credentials.json'];
        });
    });
    $committed = true;
    $result['committed'] = true;
    file_put_contents($directory.'/records.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    chmod($directory.'/records.json', 0600);
    echo json_encode(['committed' => true, 'database' => $database, 'prefix' => $prefix,
        'carpets' => count($result['carpet_ids']), 'cash' => $result['cash'], 'stock_counts' => $result['stock_counts'],
        'original_tables_preserved' => $result['original_tables_preserved'], 'api_requests_verified' => count($calls),
        'manifest' => $directory.'/records.json', 'credentials_file' => $result['credentials_file']], JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if (! $committed) {
        foreach ($files as $file) {
            if (is_string($file) && preg_match('~^carpets/[a-f0-9-]+\.(png|jpg|webp)$~', $file)) {
                Storage::disk('local')->delete($file);
            }
        }
    }
}
if (isset($failure)) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}
