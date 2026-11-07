<?php

/** User-authorized, additive Dari practice records through the authenticated API pipeline. */
use App\Models\User;
use App\Services\BusinessSupport;
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
$options = getopt('', ['database:', 'rehearse', 'apply']);
$database = $options['database'] ?? '';
if (! in_array($database, ['carpet_sales', 'csms_phase1_fresh'], true)) throw new RuntimeException('Name the intended database explicitly.');
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database, 'database.connections.mysql.url' => null]);
DB::purge('mysql');
if (DB::getDatabaseName() !== $database) throw new RuntimeException('Database identity mismatch.');
$prefix = 'DRI261104';
$apply = array_key_exists('apply', $options);
$rehearse = array_key_exists('rehearse', $options);
if ($apply && $rehearse) throw new RuntimeException('Choose apply or rehearsal, never both.');
if (! $apply && ! $rehearse) {
    echo json_encode(['mode' => 'read-only plan', 'database' => $database, 'prefix' => $prefix,
        'adds' => 'Dari AFN setup, one complete two-carpet workflow, a pending order, sales, supplier payments, assistance, expenses and stock corrections.',
        'expected_cash' => '92100.00', 'training_data' => true], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit;
}

$directory = storage_path('app/private/dari-workflow/'.$database.'/'.$prefix);
$securityTables = ['users', 'roles', 'permissions', 'user_roles', 'role_permissions', 'user_branches', 'user_washing_facilities', 'user_accounts'];
$securitySnapshot = function () use ($securityTables): array {
    $snapshot = [];
    foreach ($securityTables as $table) $snapshot[$table] = hash('sha256', DB::table($table)->get()->toJson());
    return $snapshot;
};
$calls = []; $checkpoints = [];
try {
    $result = app(SystemWriteLock::class)->run(function () use ($app, $database, $prefix, $apply, $directory, $securitySnapshot, &$calls, &$checkpoints) {
        if (DB::table('branches')->where('code', $prefix.'-KBL')->exists()) throw new RuntimeException('These practice records already exist. Refusing duplicate creation.');
        foreach (['branches', 'currencies', 'accounts', 'customers', 'suppliers', 'carpets', 'supply_purchases', 'manager_production_orders', 'trade_sales', 'operating_expenses', 'account_transactions'] as $table) {
            if (DB::table($table)->exists()) throw new RuntimeException('This fresh-start practice requires an empty business database: '.$table);
        }
        $beforeSecurity = $securitySnapshot();
        $backup = $apply && $database === 'carpet_sales' ? app(DatabaseBackup::class)->create() : null;
        DB::beginTransaction();
        try {
            $actor = User::where('username', 'admin')->where('is_active', true)->firstOrFail();
            if (! App\Services\FoundationAccess::administrator($actor)) throw new RuntimeException('An existing administrator is required.');
            $token = $actor->createToken('authorized-dari-practice', ['*'], now()->addMinutes(20));
            $kernel = $app->make(HttpKernel::class); $counter = 0;
            $call = function (string $method, string $path, array $data = [], ?string $key = null, ?int $expected = null) use ($app, $kernel, $token, $prefix, &$counter, &$calls): array {
                $app->make('auth')->forgetGuards();
                $request = Request::create('/api'.$path, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token->plainTextToken, 'HTTP_IDEMPOTENCY_KEY' => $key ?? $prefix.':'.(++$counter),
                    'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Authorized Dari workflow practice'], json_encode($data, JSON_THROW_ON_ERROR));
                $response = $kernel->handle($request); $status = $response->getStatusCode();
                $body = json_decode($response->getContent(), true) ?? [];
                $calls[] = ['method' => $method, 'path' => $path, 'status' => $status, 'expected' => $expected ?? '2xx'];
                if ($expected ? $status !== $expected : ($status < 200 || $status >= 300)) {
                    throw new RuntimeException($method.' '.$path.' failed: '.json_encode(['status' => $status, 'message' => $body['message'] ?? '', 'errors' => $body['errors'] ?? []], JSON_UNESCAPED_UNICODE));
                }
                return $body;
            };
            $create = fn (string $path, array $data) => $call('POST', $path, $data)['data'];
            $today = FoundationCatalog::today(); $ids = []; $refs = [];
            $assert = function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException('Reconciliation failed: '.$message); };
            $cash = fn () => (string) DB::table('v_account_balances')->where('account_id', $ids['account'] ?? 0)->value('balance');

            $currency = $create('/foundation/currencies', ['code' => 'AFN', 'name' => 'افغانی', 'symbol' => '؋', 'decimal_places' => 2, 'is_active' => true]);
            $ids['currency'] = $currency['id'];
            $call('PUT', '/foundation/settings', ['base_currency_id' => $ids['currency'], 'default_locale' => 'fa', 'timezone' => 'Asia/Kabul', 'reminder_days_before' => 3]);
            $ids['branch'] = $create('/foundation/branches', ['code' => $prefix.'-KBL', 'name' => 'شعبه کابل ـ آموزشی', 'location' => 'کابل؛ آدرس نمونه برای آموزش سیستم', 'is_main' => true, 'status' => 'active'])['id'];
            foreach (['materials_store' => ['RAW', 'گدام مواد اولیه کابل ـ آموزشی', 'warehouse'], 'finished_store' => ['FIN', 'گدام قالین آماده کابل ـ آموزشی', 'warehouse'], 'showroom' => ['SHOW', 'نمایشگاه کابل ـ آموزشی', 'showroom']] as $key => [$suffix, $name, $type]) {
                $ids[$key] = $create('/foundation/locations', ['code' => $prefix.'-'.$suffix, 'name' => $name, 'branch_id' => $ids['branch'], 'location_type' => $type, 'status' => 'active'])['id'];
            }
            $ids['facility'] = $create('/foundation/facilities', ['name' => 'مرکز شستشوی صفا ـ آموزشی', 'location' => 'کابل؛ نشانی آموزشی', 'contact_name' => 'محمد صادق ـ آموزشی', 'status' => 'active'])['id'];
            $badLocation = ['code' => $prefix.'-BAD', 'name' => 'محل نادرست آموزشی', 'branch_id' => $ids['branch'], 'washing_facility_id' => $ids['facility'], 'location_type' => 'washing', 'status' => 'active'];
            $call('POST', '/foundation/locations', $badLocation, null, 422);
            $ids['washing_location'] = $create('/foundation/locations', ['code' => $prefix.'-WASH', 'name' => 'محوطه شستشوی صفا ـ آموزشی', 'washing_facility_id' => $ids['facility'], 'location_type' => 'washing', 'status' => 'active'])['id'];
            $ids['category'] = $create('/foundation/categories', ['name' => 'قالین چوبی پشمی ـ آموزشی', 'name_fa' => 'قالین چوبی پشمی ـ آموزشی', 'status' => 'active'])['id'];
            $ids['expense_category'] = $create('/foundation/expense-categories', ['name' => 'کرایه انتقال ـ آموزشی', 'name_fa' => 'کرایه انتقال ـ آموزشی', 'status' => 'active'])['id'];
            $ids['supplier'] = $create('/directories/suppliers', ['name' => 'عرضه‌کننده پشم آریانا ـ آموزشی', 'address' => 'کابل؛ معلومات فرضی برای آموزش', 'notes' => 'رکورد آموزشی؛ معلومات تأییدشدۀ عرضه‌کننده واقعی نیست.', 'status' => 'active'])['id'];
            $ids['manager'] = $create('/production/managers', ['code' => $prefix.'-MGR', 'name' => 'احمد رحیمی ـ آموزشی', 'branch_id' => $ids['branch'], 'address' => 'کابل؛ نشانی آموزشی', 'status' => 'active'])['id'];
            $ids['material'] = $create('/business/materials', ['code' => $prefix.'-WOOL', 'name' => 'پشم طبیعی قالین ـ آموزشی', 'unit' => 'kg', 'color' => 'رنگ طبیعی'])['id'];
            $ids['tool'] = $create('/production/tools', ['code' => $prefix.'-COMB', 'name' => 'شانه قالین‌بافی ـ آموزشی', 'status' => 'active'])['id'];
            $ids['customer'] = $create('/customers', ['name' => 'عبدالرحمان احمدی ـ آموزشی', 'customer_type' => 'retail', 'registration_date' => $today, 'status' => 'active', 'address' => 'کابل؛ آدرس آموزشی', 'notes' => 'مشتری نمونه برای آزمایش فروش و دریافت پول.'])['id'];
            $ids['account'] = $create('/foundation/accounts', ['code' => $prefix.'-CASH', 'name' => 'صندوق افغانی کابل ـ آموزشی', 'currency_id' => $ids['currency'], 'account_type' => 'cash', 'status' => 'active', 'opening_amount' => '100000', 'opening_on' => $today, 'opening_rate' => '1'])['id'];
            $cash = fn () => (string) DB::table('v_account_balances')->where('account_id', $ids['account'])->value('balance');
            $checkpoint = function (string $step, string $expected) use ($cash, $assert, &$checkpoints): void {
                $actual = $cash(); $assert($actual === $expected, $step.' cash '.$actual.' != '.$expected);
                $checkpoints[] = ['step' => $step, 'cash' => $actual];
            };
            $checkpoint('موجودی ابتدایی صندوق', '100000.00');
            $payment = fn (string $amount, string $reason) => ['account_id' => $ids['account'], 'amount' => $amount, 'rate_to_base' => '1', 'units_per_payment_unit' => '1', 'payment_on' => $today, 'reason' => $reason];
            $call('POST', '/production/setup', ['branch_id' => $ids['branch'], 'materials_location_id' => $ids['materials_store'], 'finished_location_id' => $ids['finished_store'], 'facility_id' => $ids['facility'], 'facility_location_id' => $ids['washing_location'], 'payment_trigger' => 'stock_acceptance']);

            $supply = ['supplier_id' => $ids['supplier'], 'branch_id' => $ids['branch'], 'location_id' => $ids['materials_store'], 'currency_id' => $ids['currency'], 'rate_to_base' => '1', 'purchased_on' => $today,
                'items' => [['kind' => 'material', 'item_id' => $ids['material'], 'quantity' => '20', 'unit_cost' => '400'], ['kind' => 'tool', 'item_id' => $ids['tool'], 'quantity' => '2', 'unit_cost' => '750']],
                'payment' => $payment('5000', 'پرداخت اول خرید پشم و شانه؛ آموزشی')];
            $bad = $supply; unset($bad['supplier_id']); $call('POST', '/production/supply-purchases', $bad, null, 422);
            $supplyDocument = $call('POST', '/production/supply-purchases', $supply, $prefix.':supplies')['data'];
            $ids['supply_purchase'] = $supplyDocument['id']; $refs['supply_purchase'] = $supplyDocument['purchase_no'];
            $replay = $call('POST', '/production/supply-purchases', $supply, $prefix.':supplies', 200);
            $assert($replay['data']['id'] === $ids['supply_purchase'] && $replay['replayed'], 'Supply retry must replay the same document.');
            $changed = $supply; $changed['items'][0]['quantity'] = '21'; $call('POST', '/production/supply-purchases', $changed, $prefix.':supplies', 409);
            $checkpoint('پرداخت اول خرید مواد و ابزار', '95000.00');
            $supplierStatement = $call('GET', '/production/suppliers/'.$ids['supplier'].'/statement')['data'];
            $assert($supplierStatement['balances'][0]['owed'] === '4500.00', 'Supplier partial balance.');
            $call('POST', '/production/supply-purchases/'.$ids['supply_purchase'].'/pay', $payment('4501', 'آزمایش رد اضافه‌پرداخت'), null, 422);
            $call('POST', '/production/supply-purchases/'.$ids['supply_purchase'].'/pay', $payment('4500', 'تصفیه خرید پشم و ابزار؛ آموزشی'));
            $checkpoint('تصفیه حساب عرضه‌کننده', '90500.00');

            $orderData = ['manager_id' => $ids['manager'], 'branch_id' => $ids['branch'], 'currency_id' => $ids['currency'], 'rate_to_base' => '1', 'ordered_on' => $today, 'due_on' => $today,
                'description' => 'بافت دو تخته قالین چوبی گلدار؛ سناریوی کامل آموزشی', 'payment_trigger' => 'stock_acceptance',
                'lines' => [['name' => 'چوبی گلدار سه در دو ـ آموزشی', 'category_id' => $ids['category'], 'target_quantity' => 2, 'pricing_method' => 'area', 'rate' => '600']],
                'factors' => [['label' => 'اجرت اضافی تکمیل حاشیه', 'kind' => 'fixed', 'operation' => 'add', 'value' => '1200']]];
            $order = $create('/production/orders', $orderData); $ids['order'] = $order['id']; $refs['order'] = $order['order_no'];
            $path = '/production/orders/'.$ids['order']; $line = $call('GET', $path)['data']['lines'][0]['id'];
            $issue = ['movement_type' => 'issue', 'moved_on' => $today, 'materials' => [['material_id' => $ids['material'], 'quantity' => '16']], 'tools' => [['tool_id' => $ids['tool'], 'quantity' => 2]], 'advance' => $payment('2000', 'پیش‌پرداخت اجرت تولید؛ آموزشی'), 'reason' => 'تحویل پشم و دو شانه به مدیر تولید؛ آموزشی'];
            $bad = $issue; $bad['materials'][0]['quantity'] = '21'; $call('POST', $path.'/issue', $bad, null, 422);
            $call('POST', $path.'/issue', $issue); $checkpoint('تحویل مواد و پیش‌پرداخت تولید', '88500.00');
            $ids['carpets'] = []; $refs['carpets'] = [];
            foreach (['0', '1'] as $index => $waste) {
                $receive = ['delivered_on' => $today, 'items' => [['line_id' => $line, 'carpet_code' => $prefix.'-C'.($index + 1), 'length_cm' => '300', 'width_cm' => '200']],
                    'usages' => [['material_id' => $ids['material'], 'used_quantity' => '7', 'waste_quantity' => $waste]]];
                if ($index === 0) { $bad = $receive; $bad['usages'][0]['used_quantity'] = '17'; $call('POST', $path.'/receive', $bad, null, 422); }
                $call('POST', $path.'/receive', $receive);
                $document = $call('GET', $path)['data'];
                $assert($document['earned_amount'] === ($index === 0 ? '0.00' : '4200.00'), 'Stock-acceptance milestone must not earn unaccepted output.');
                $carpet = DB::table('carpets')->where('carpet_code', $prefix.'-C'.($index + 1))->first();
                $ids['carpets'][] = $carpet->id; $refs['carpets'][] = $carpet->carpet_code;
                $assert(DB::table('v_current_stock')->where('id', $carpet->id)->value('stock_status') === 'at_washing', 'Carpet must first be at washing.');
                $washing = $call('GET', $path.'/workflow')['data']['washing_items'];
                $accept = ['returned_on' => $today, 'confirmed_received' => true, 'record_cost_later' => false, 'washing_amount' => '500', 'payment' => $payment('500', 'اجرت شستشوی یک تخته قالین؛ آموزشی'),
                    'items' => array_map(fn ($item) => ['washing_order_item_id' => $item['washing_order_item_id'], 'expected_sequence' => $item['expected_sequence']], $washing)];
                if ($index === 0) { $bad = $accept; $bad['confirmed_received'] = false; $call('POST', $path.'/accept', $bad, null, 422); }
                $call('POST', $path.'/accept', $accept); $checkpoint('دریافت قالین '.($index + 1).' از شستشو', $index === 0 ? '88000.00' : '87500.00');
            }
            $call('POST', $path.'/settle', $payment('6401', 'آزمایش رد پرداخت بیشتر از اجرت'), null, 422);
            $settlement = $payment('6400', 'تصفیه اجرت دو تخته قالین پس از کسر پیش‌پرداخت؛ آموزشی');
            $call('POST', $path.'/settle', $settlement, $prefix.':production-settlement');
            $call('POST', $path.'/settle', $settlement, $prefix.':production-settlement', 200);
            $checkpoint('تصفیه اجرت تولید', '81100.00');
            $call('POST', $path.'/issue', ['movement_type' => 'return', 'moved_on' => $today, 'materials' => [['material_id' => $ids['material'], 'quantity' => '1']], 'tools' => [['tool_id' => $ids['tool'], 'quantity' => 2]], 'reason' => 'بازگشت یک کیلو پشم باقی‌مانده و دو شانه سالم؛ آموزشی']);

            $support = $create('/production/managers/'.$ids['manager'].'/assistance', ['branch_id' => $ids['branch'], 'manager_id' => $ids['manager'], 'beneficiary_name' => 'حبیب‌الله نوری ـ آموزشی', 'assistance_type' => 'advance', 'responsible_party' => 'worker', 'recovery_terms' => 'بازپرداخت نقدی در قسط‌های توافق‌شده؛ مستقل از اجرت تولید',
                'currency_id' => $ids['currency'], 'rate_to_base' => '1', 'amount' => '3000', 'assistance_on' => $today, 'reason' => 'مساعدت خانوادگی از طریق مدیر؛ رکورد آموزشی',
                'payment' => $payment('3000', 'تأمین مساعدت کارگر نزد مدیر؛ آموزشی'), 'delivery' => ['amount' => '2500', 'delivered_on' => $today, 'acknowledgement' => 'مدیر دریافت مبلغ توسط کارگر را برای آموزش تأیید کرد.']]);
            $ids['assistance'] = $support['id'];
            $call('POST', '/production/assistance/'.$support['id'].'/return', $payment('500', 'مدیر باقی‌مانده مساعدت را به صندوق برگرداند؛ آموزشی'));
            $call('POST', '/production/assistance/'.$support['id'].'/recover', $payment('1000', 'بازپرداخت قسط اول مساعدت کارگر؛ آموزشی'));
            $checkpoint('مساعدت کارگر پس از برگشت و بازپرداخت', '79600.00');

            $expenseData = ['branch_id' => $ids['branch'], 'currency_id' => $ids['currency'], 'rate_to_base' => '1', 'expense_on' => $today, 'expense_category_id' => $ids['expense_category'], 'payee' => 'راننده انتقال شهری ـ آموزشی', 'description' => 'کرایه انتقال مواد و امور اداری؛ آموزشی', 'amount' => '1000', 'payment' => $payment('300', 'پرداخت اول کرایه انتقال؛ آموزشی')];
            $expense = $create('/business/expenses', $expenseData); $ids['expense'] = $expense['id']; $refs['expense'] = $expense['expense_no'];
            $call('POST', '/business/expenses/'.$expense['id'].'/pay', $payment('701', 'آزمایش جلوگیری از اضافه‌پرداخت مصرف'), null, 422);
            $call('POST', '/business/expenses/'.$expense['id'].'/pay', $payment('700', 'باقی‌مانده کرایه انتقال؛ آموزشی'));
            $checkpoint('تصفیه مصرف انتقال', '78600.00');

            // Correct a deliberately mistaken voucher: reverse its payment before voiding it.
            $mistake = $expenseData; $mistake['description'] = 'سند تکراری برای تمرین اصلاح اشتباه؛ آموزشی'; $mistake['amount'] = '500'; $mistake['payment'] = $payment('100', 'پرداخت اشتباه برای تمرین برگشت؛ آموزشی');
            $wrongExpense = $create('/business/expenses', $mistake); $ids['void_expense'] = $wrongExpense['id']; $refs['void_expense'] = $wrongExpense['expense_no'];
            $void = ['voided_on' => $today, 'reason' => 'این سند در تمرین اشتباهاً تکراری ثبت شده است.'];
            $call('POST', '/business/expenses/'.$wrongExpense['id'].'/void', $void, null, 422);
            $wrongPayment = DB::table('operating_expense_payments')->where('expense_id', $wrongExpense['id'])->value('account_transaction_id');
            $call('POST', '/business/payments/'.$wrongPayment.'/reverse', ['reason' => 'برگشت پرداخت سند تکراری؛ آموزشی']);
            $call('POST', '/business/expenses/'.$wrongExpense['id'].'/void', $void);
            $checkpoint('اصلاح و لغو سند تکراری بدون تغییر خالص پول', '78600.00');

            $stockItem = fn (int $carpet) => ['carpet_id' => $carpet, 'expected_sequence' => (int) DB::table('v_current_stock')->where('id', $carpet)->value('sequence_no')];
            $saleData = ['customer_id' => $ids['customer'], 'branch_id' => $ids['branch'], 'currency_id' => $ids['currency'], 'rate_to_base' => '1', 'sold_on' => $today, 'due_on' => $today, 'notes' => 'فروش آموزشی قالین چوبی گلدار؛ مبلغ خالص پس از تخفیف ۱۳۵۰۰ افغانی',
                'items' => [$stockItem($ids['carpets'][0]) + ['unit_price' => '14000', 'discount' => '500']], 'payment' => $payment('5000', 'دریافت اول از مشتری؛ آموزشی')];
            $bad = $saleData; $bad['items'][0]['discount'] = '14000'; $call('POST', '/business/sales', $bad, null, 422);
            $sale = $create('/business/sales', $saleData); $ids['sale'] = $sale['id']; $refs['sale'] = $sale['sale_no'];
            $call('POST', '/business/sales', $saleData, null, 409);
            $call('POST', '/business/sales/'.$sale['id'].'/collect', $payment('8501', 'آزمایش جلوگیری از دریافت اضافی'), null, 422);
            $call('POST', '/business/sales/'.$sale['id'].'/collect', $payment('8500', 'دریافت باقی‌مانده بل فروش؛ آموزشی'));
            $checkpoint('تصفیه بل فروش مشتری', '92100.00');

            $transfer = ['transfer_no' => $prefix.'-TR1', 'transferred_on' => $today, 'from_location_id' => $ids['finished_store'], 'to_location_id' => $ids['showroom'], 'notes' => 'انتقال قالین دوم برای نمایش و فروش؛ آموزشی', 'items' => [$stockItem($ids['carpets'][1])]];
            $bad = $transfer; $bad['to_location_id'] = $bad['from_location_id']; $call('POST', '/inventory/transfers', $bad, null, 422);
            $document = $create('/inventory/transfers', $transfer); $ids['transfer'] = $document['id']; $refs['transfer'] = $transfer['transfer_no'];
            $missing = ['adjustment_no' => $prefix.'-MISS', 'branch_id' => $ids['branch'], 'adjustment_type' => 'missing', 'adjusted_on' => $today, 'reason' => 'آزمایش ثبت مفقودی هنگام شمارش؛ قالین برای تمرین دوباره یافت می‌شود.', 'items' => [$stockItem($ids['carpets'][1])]];
            $stale = $missing; $stale['items'][0]['expected_sequence']--; $call('POST', '/inventory/corrections/adjustments', $stale, null, 409);
            $ids['missing'] = $create('/inventory/corrections/adjustments', $missing)['id'];
            $found = ['adjustment_no' => $prefix.'-FOUND', 'branch_id' => $ids['branch'], 'adjustment_type' => 'found', 'location_id' => $ids['showroom'], 'adjusted_on' => $today, 'reason' => 'قالین در شمارش دوباره نمایشگاه یافت شد؛ آموزشی', 'items' => [$stockItem($ids['carpets'][1])]];
            $ids['found'] = $create('/inventory/corrections/adjustments', $found)['id'];
            $refs['missing'] = $missing['adjustment_no']; $refs['found'] = $found['adjustment_no'];

            $pending = $orderData; $pending['description'] = 'سفارش باز برای تمرین بعدی شما؛ هنوز مواد تحویل نشده است.';
            $pending['due_on'] = now('Asia/Kabul')->addDays(30)->toDateString();
            $pending['lines'] = [['name' => 'قالین چوبی کوچک ـ تمرین بعدی', 'category_id' => $ids['category'], 'target_quantity' => 1, 'pricing_method' => 'piece', 'rate' => '2500']]; $pending['factors'] = [];
            $pendingOrder = $create('/production/orders', $pending); $ids['pending_order'] = $pendingOrder['id']; $refs['pending_order'] = $pendingOrder['order_no'];

            $call('POST', '/business/purchases', [], null, 409);
            $call('POST', '/inventory/corrections/drafts', [], null, 409);
            $finalOrder = $call('GET', $path)['data'];
            $finalSupport = $call('GET', '/production/assistance/'.$ids['assistance'])['data'];
            $finalSupplier = $call('GET', '/production/suppliers/'.$ids['supplier'].'/statement')['data'];
            $finalSale = $call('GET', '/business/sales/'.$ids['sale'])['data'];
            $context = $call('GET', $path.'/workflow')['data'];
            $stock = DB::table('v_current_stock')->whereIn('id', $ids['carpets'])->get(['id', 'carpet_code', 'stock_status', 'location_id', 'sequence_no']);
            $costs = array_map(fn ($id) => BusinessSupport::costBase($id), $ids['carpets']);
            $assert($finalOrder['state'] === 'completed' && $finalOrder['earned_amount'] === '8400.00' && $finalOrder['balance_due'] === '0.00', 'Completed order earnings/balance.');
            $assert($finalSupplier['balances'][0]['owed'] === '0.00' && $finalSale['remaining_amount'] === '0.00', 'Supplier/customer remaining balances.');
            $assert($finalSupport['advance_outstanding'] === '1500.00' && $finalSupport['with_manager_amount'] === '0.00', 'Separate assistance outstanding/custody.');
            $assert((float) $context['materials'][0]['warehouse_quantity'] === 5.0 && (float) $context['materials'][0]['held_quantity'] === 0.0 && (int) $context['tools'][0]['warehouse_quantity'] === 2, 'Materials/tools stock.');
            $assert($stock->where('stock_status', 'sold')->count() === 1 && $stock->where('stock_status', 'in_stock')->where('location_id', $ids['showroom'])->count() === 1, 'Sold/available stock and showroom.');
            $assert($costs === ['7500.00', '7900.00'], 'Individual carpet costs.');
            $checkpoint('کنترل نهایی', '92100.00');
            $overview = $call('GET', '/dashboard/overview')['data'];
            $token->accessToken->delete();
            $assert($beforeSecurity === $securitySnapshot(), 'Existing users/passwords/roles/permission assignments must be unchanged.');
            $result = ['committed' => $apply, 'database' => $database, 'prefix' => $prefix, 'date' => $today, 'training_data' => true,
                'currency' => 'AFN', 'ids' => $ids, 'references' => $refs, 'cash_balance' => $cash(), 'checkpoints' => $checkpoints,
                'production' => ['state' => $finalOrder['state'], 'earned' => $finalOrder['earned_amount'], 'paid' => $finalOrder['paid_amount'], 'remaining' => $finalOrder['balance_due']],
                'supplier_remaining' => $finalSupplier['balances'][0]['owed'], 'customer_remaining' => $finalSale['remaining_amount'],
                'worker_advance_outstanding' => $finalSupport['advance_outstanding'], 'wool_in_store_kg' => '5.000', 'tools_in_store' => 2,
                'stock' => $stock, 'carpet_costs' => $costs, 'sale_net' => '13500.00', 'sale_gross_margin' => '6000.00',
                'security_preserved' => true, 'backup' => $backup, 'api_checks' => $calls, 'dashboard' => $overview];
            if ($apply) DB::commit(); else DB::rollBack();
            return $result;
        } catch (Throwable $error) {
            while (DB::transactionLevel() > 0) DB::rollBack();
            throw $error;
        }
    });
    if ($apply) {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) throw new RuntimeException('Could not save the private practice manifest.');
        file_put_contents($directory.'/records.json', json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        chmod($directory.'/records.json', 0600);
    }
    echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    if ($calls) fwrite(STDERR, 'Last checks: '.json_encode(array_slice($calls, -3), JSON_UNESCAPED_UNICODE).PHP_EOL);
    exit(1);
}
