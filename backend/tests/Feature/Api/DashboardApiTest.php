<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\WorkerAssistanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class DashboardApiTest extends HistoricalApiTestCase
{
    private int $branch;

    private int $other;

    private int $location;

    private int $destination;

    private int $weaver;

    private int $account;

    private int $currency;

    private int $foreign;

    private int $purchase;

    private array $carpets;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->today = now(DB::table('company_settings')->value('timezone'))->toDateString();
        $this->branch = DB::table('branches')->insertGetId(['code' => 'OV-A', 'name' => 'Overview A']);
        $this->other = DB::table('branches')->insertGetId(['code' => 'OV-B', 'name' => 'Overview B']);
        $this->location = DB::table('stock_locations')->insertGetId([
            'code' => 'OV-L1', 'name' => 'Shelf A', 'branch_id' => $this->branch,
            'location_type' => 'shelf',
        ]);
        $this->destination = DB::table('stock_locations')->insertGetId([
            'code' => 'OV-L2', 'name' => 'Shelf B', 'branch_id' => $this->other,
            'location_type' => 'shelf',
        ]);
        $this->currency = DB::table('company_settings')->value('base_currency_id');
        $this->foreign = DB::table('currencies')->where('id', '<>', $this->currency)->value('id');
        foreach ([$this->branch, $this->other] as $branch) {
            $manager = DB::table('employees')->insertGetId([
                'name' => 'Overview manager', 'branch_id' => $branch,
            ]);
            $weaver = DB::table('weavers')->insertGetId([
                'name' => 'Overview weaver', 'manager_employee_id' => $manager,
                'registration_date' => $this->today,
            ]);
            if ($branch === $this->branch) {
                $this->weaver = $weaver;
            }
        }
        $this->account = $this->account('OV-CASH', $this->branch, $this->currency, '5000.00');
        $this->account('OV-OTHER', $this->other, $this->currency, '900.00');
        $this->account('OV-FX', $this->branch, $this->foreign, '123.45');
        $category = DB::table('carpet_categories')->insertGetId(['name' => 'Overview wool']);
        $this->carpets = [];
        foreach ([1, 2] as $n) {
            $this->carpets[] = $this->command('carpets', [
                'carpet_code' => 'OV-C'.$n, 'name' => 'Overview carpet',
                'registration_branch_id' => $this->branch, 'category_id' => $category,
            ])->assertCreated()->json('data.id');
        }
        $this->purchase = $this->command('purchases', [
            'purchase_no' => 'OV-P', 'branch_id' => $this->branch,
            'weaver_id' => $this->weaver, 'currency_id' => $this->currency,
            'rate_to_base' => '1', 'purchase_on' => $this->today,
            'items' => [['carpet_id' => $this->carpets[0], 'unit_price' => '100.10'],
                ['carpet_id' => $this->carpets[1], 'unit_price' => '200.20']],
        ])->assertCreated()->json('data.id');
        $this->command('receipts', ['receipt_no' => 'OV-R',
            'location_id' => $this->location, 'received_on' => $this->today,
            'items' => array_map(fn ($id) => ['carpet_id' => $id,
                'expected_sequence' => 1], $this->carpets),
        ])->assertCreated();
    }

    private function account(string $code, int $branch, int $currency, string $amount): int
    {
        $id = DB::table('accounts')->insertGetId(['code' => $code, 'name' => $code,
            'branch_id' => $branch, 'currency_id' => $currency]);
        $this->postJson('/api/foundation/ledger', [
            'account_id' => $id, 'entry_type' => 'opening', 'amount' => $amount,
            'rate_to_base' => '1', 'transaction_date' => $this->today,
            'description' => 'Overview test opening',
        ], ['Idempotency-Key' => 'overview-opening-'.$code])->assertCreated();

        return $id;
    }

    private function command(string $path, array $data)
    {
        return $this->postJson('/api/inventory/'.$path, $data,
            ['Idempotency-Key' => 'overview-'.bin2hex(random_bytes(12))]);
    }

    private function scoped(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Overview scoped role '.$user->id]);
        $role->permissions()->sync(Permission::whereIn('code', $permissions)->pluck('id'));
        $user->roles()->attach($role);
        DB::table('user_branches')->insert(['user_id' => $user->id,
            'branch_id' => $this->branch]);
        foreach (DB::table('accounts')->where('branch_id', $this->branch)->pluck('id') as $account) {
            DB::table('user_accounts')->insert(['user_id' => $user->id, 'account_id' => $account]);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_overview_reconciles_counts_trend_and_exact_separate_currency_balances(): void
    {
        $this->postJson('/api/customers', $this->validCustomer())->assertCreated();
        $this->postJson('/api/customers', $this->validCustomer([
            'name' => 'Inactive customer', 'status' => 'inactive',
        ]))->assertCreated();
        $this->pay('100.05');
        $result = $this->getJson('/api/dashboard/overview?days=7')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.days', 7)
            ->assertJsonPath('data.metrics.available', 2)
            ->assertJsonPath('data.metrics.awaiting_receipt', 0)
            ->assertJsonPath('data.metrics.customers', 1)
            ->assertJsonPath('data.metrics.weavers', 2)
            ->assertJsonCount(7, 'data.stock.trend')->json('data');
        $this->assertSame(['date' => $this->today, 'purchased' => 2, 'produced' => 0, 'received' => 2],
            $result['stock']['trend'][6]);
        $this->assertSame(4, count($result['stock']['recent']));
        $code = DB::table('currencies')->where('id', $this->currency)->value('code');
        $cash = collect($result['finance']['cash'])->keyBy('currency');
        $this->assertCount(2, $cash);
        $this->assertSame('5799.95', $cash[$code]['balance']);
        $this->assertSame('200.25', $result['finance']['payables'][0]['owed']);
        $this->assertSame('0.00', $result['finance']['payables'][0]['credit']);
    }

    private function pay(string $amount): void
    {
        $this->command('payments', ['payment_no' => 'OV-PAY',
            'branch_id' => $this->branch, 'weaver_id' => $this->weaver,
            'account_id' => $this->account, 'payment_type' => 'purchase',
            'amount' => $amount, 'rate_to_base' => '1', 'payment_on' => $this->today,
            'reason' => 'Overview settlement', 'allocations' => [[
                'weaver_purchase_id' => $this->purchase,
                'amount_in_payment_currency' => $amount,
                'purchase_units_per_payment_unit' => '1',
            ]],
        ])->assertCreated();
    }

    public function test_transfer_moves_visible_stock_but_purchase_balances_keep_original_branch_scope(): void
    {
        $this->command('transfers', ['transfer_no' => 'OV-T',
            'from_location_id' => $this->location, 'to_location_id' => $this->destination,
            'transferred_on' => $this->today,
            'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 2]],
        ])->assertCreated();
        $this->scoped(['dashboard.view', 'stock.view', 'weavers.view',
            'accounts.view', 'ledger.view', 'weaver-purchases.view']);
        $result = $this->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.metrics.available', 1)
            ->assertJsonPath('data.metrics.weavers', 1)
            ->assertJsonPath('data.metrics.customers', null)
            ->assertJsonCount(2, 'data.stock.recent')->json('data');
        $this->assertSame(1, $result['stock']['trend'][29]['purchased']);
        $this->assertSame('300.30', $result['finance']['payables'][0]['owed']);
        $code = DB::table('currencies')->where('id', $this->currency)->value('code');
        $this->assertSame('5000.00', collect($result['finance']['cash'])
            ->firstWhere('currency', $code)['balance']);
    }

    public function test_returns_refunds_and_reversals_change_credit_and_cash_once(): void
    {
        $this->pay('300.30');
        $this->command('corrections/returns', ['return_no' => 'OV-RET',
            'weaver_purchase_id' => $this->purchase, 'from_location_id' => $this->location,
            'returned_on' => $this->today, 'reason' => 'Defective carpet',
            'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 2]],
        ])->assertCreated();
        $this->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.metrics.available', 1)
            ->assertJsonPath('data.stock.counts.returned', 1)
            ->assertJsonPath('data.finance.payables.0.owed', '0.00')
            ->assertJsonPath('data.finance.payables.0.credit', '100.10');
        $refund = $this->command('corrections/refunds', ['refund_no' => 'OV-RF',
            'weaver_purchase_id' => $this->purchase, 'account_id' => $this->account,
            'amount' => '50.00', 'rate_to_base' => '1',
            'purchase_units_per_refund_unit' => '1', 'refunded_on' => $this->today,
            'reason' => 'Actual partial refund',
        ])->assertCreated()->json('data.account_transaction_id');
        $this->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.finance.payables.0.credit', '50.10');
        $this->postJson('/api/foundation/ledger/'.$refund.'/reverse',
            ['reason' => 'Correct refund'], ['Idempotency-Key' => 'overview-refund-reverse'])
            ->assertCreated();
        $result = $this->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.finance.payables.0.credit', '100.10')->json('data');
        $code = DB::table('currencies')->where('id', $this->currency)->value('code');
        $this->assertSame('5599.70', collect($result['finance']['cash'])
            ->firstWhere('currency', $code)['balance']);
    }

    public function test_dashboard_permission_alone_cannot_disclose_module_data(): void
    {
        $this->scoped(['dashboard.view']);
        $this->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.metrics.available', null)
            ->assertJsonPath('data.metrics.customers', null)
            ->assertJsonPath('data.metrics.weavers', null)
            ->assertJsonPath('data.finance.cash', null)
            ->assertJsonPath('data.finance.payables', null);
    }

    public function test_financial_modules_require_their_exact_permissions(): void
    {
        $this->scoped(['dashboard.view', 'stock.view', 'accounts.view']);
        $this->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.metrics.available', 2)
            ->assertJsonPath('data.finance.cash', null)
            ->assertJsonPath('data.finance.payables', null);
    }

    public function test_period_filters_use_company_days_zero_fill_and_do_not_filter_current_totals(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(8));
        try {
            $short = $this->getJson('/api/dashboard/overview?days=7')->assertOk()
                ->assertJsonPath('data.metrics.available', 2)->json('data.stock.trend');
            $this->assertSame(0, array_sum(array_column($short, 'purchased')));
            $long = $this->getJson('/api/dashboard/overview?days=30')->assertOk()
                ->assertJsonCount(30, 'data.stock.trend')->json('data.stock.trend');
            $this->assertSame(2, array_sum(array_column($long, 'purchased')));
            $this->assertSame(2, array_sum(array_column($long, 'received')));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_invalid_periods_are_rejected_and_overview_reads_do_not_post_facts(): void
    {
        foreach (['0', '8', '365', '-7', 'hello', '7.5', ''] as $days) {
            $this->getJson('/api/dashboard/overview?days='.$days)
                ->assertUnprocessable()->assertJsonValidationErrors('days');
        }
        $before = array_map(fn ($table) => DB::table($table)->count(),
            ['stock_events', 'audit_logs', 'account_transactions']);
        $this->getJson('/api/dashboard/overview?days=90')->assertOk()
            ->assertJsonCount(90, 'data.stock.trend');
        $this->assertSame($before, array_map(fn ($table) => DB::table($table)->count(),
            ['stock_events', 'audit_logs', 'account_transactions']));
    }

    public function test_dashboard_access_is_required_even_with_stock_permission(): void
    {
        $this->scoped(['stock.view']);
        $this->getJson('/api/dashboard/overview')->assertForbidden();
    }

    public function test_unassigned_branch_users_get_honest_empty_stock_and_financial_results(): void
    {
        $user = $this->scoped(['dashboard.view', 'stock.view', 'accounts.view',
            'ledger.view', 'weaver-purchases.view', 'weavers.view']);
        DB::table('user_branches')->where('user_id', $user->id)->delete();
        DB::table('user_accounts')->where('user_id', $user->id)->delete();
        $result = $this->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.metrics.available', 0)
            ->assertJsonPath('data.metrics.awaiting_receipt', 0)
            ->assertJsonPath('data.metrics.weavers', 0)
            ->assertJsonPath('data.stock.recent', [])
            ->assertJsonPath('data.finance.cash', [])
            ->assertJsonPath('data.finance.payables', [])->json('data.stock.trend');
        $this->assertSame(0, array_sum(array_column($result, 'purchased')));
        $this->assertSame(0, array_sum(array_column($result, 'received')));
    }

    public function test_business_summaries_keep_account_and_branch_data_scoped_and_permission_safe(): void
    {
        $this->scoped(['dashboard.view', 'accounts.view', 'ledger.view', 'weaver-purchases.view', 'branches.view', 'stock.view']);
        $data = $this->getJson('/api/dashboard/overview')->assertOk()->json('data.business');
        $this->assertSame('300.30', $data['legacy_purchases'][0]['amount']);
        $this->assertSame(2, $data['accounts']['total']);
        $this->assertCount(2, $data['accounts']['items']);
        $this->assertSame([$this->branch], array_column($data['branches']['items'], 'id'));
        $this->assertSame(2, (int) $data['branches']['items'][0]['available']);
        $this->assertNotContains('OV-OTHER', array_column($data['accounts']['items'], 'name'));
        $this->assertNotContains('OV-OTHER', array_column($data['recent_transactions'], 'account_name'));

        $this->scoped(['dashboard.view']);
        $this->getJson('/api/dashboard/overview')->assertOk()->assertJsonPath('data.business', [
            'purchases' => null, 'legacy_purchases' => null, 'expenses' => null, 'accounts' => null,
            'recent_transactions' => null, 'branches' => null, 'sales' => null,
        ]);
        $this->scoped(['dashboard.view', 'branches.view']);
        $branch = $this->getJson('/api/dashboard/overview')->assertOk()->json('data.business.branches.items.0');
        $this->assertArrayNotHasKey('available', $branch);
    }

    public function test_purchase_and_expense_totals_exclude_credits_inventory_settlements_and_reversed_expenses(): void
    {
        $this->pay('100.05');
        $expense = function (string $amount, string $key): int {
            return $this->postJson('/api/foundation/ledger', ['account_id' => $this->account,
                'entry_type' => 'expense', 'amount' => $amount, 'rate_to_base' => '1',
                'transaction_date' => $this->today, 'description' => 'Overview operating expense'],
                ['Idempotency-Key' => $key])->assertCreated()->json('data.id');
        };
        $expense('40.40', 'overview-expense');
        $reversed = $expense('5.05', 'overview-expense-reversed');
        $this->postJson('/api/foundation/ledger/'.$reversed.'/reverse', ['reason' => 'Correct expense'],
            ['Idempotency-Key' => 'overview-expense-reversal'])->assertCreated();
        $category = DB::table('expense_categories')->insertGetId(['name' => 'Overview bonus']);
        $this->command('payments', ['payment_no' => 'OV-BONUS', 'branch_id' => $this->branch,
            'weaver_id' => $this->weaver, 'account_id' => $this->account, 'payment_type' => 'additional',
            'amount' => '7.07', 'rate_to_base' => '1', 'payment_on' => $this->today,
            'expense_category_id' => $category, 'reason' => 'Overview weaver bonus', 'allocations' => []])->assertCreated();
        $facility = DB::table('washing_facilities')->insertGetId(['name' => 'Overview washing']);
        $location = DB::table('stock_locations')->insertGetId(['code' => 'OV-WASH', 'name' => 'Washing yard',
            'washing_facility_id' => $facility, 'location_type' => 'washing']);
        $order = $this->postJson('/api/washing/dispatch', ['order_no' => 'OV-WASH', 'branch_id' => $this->branch,
            'facility_id' => $facility, 'facility_location_id' => $location, 'sent_on' => $this->today,
            'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 2]]],
            ['Idempotency-Key' => 'overview-washing-dispatch'])->assertCreated()->json('data.id');
        $visit = DB::table('washing_order_items')->where('washing_order_id', $order)->value('id');
        $bill = $this->postJson('/api/washing/bills', ['expense_no' => 'OV-WASH-BILL', 'invoice_no' => 'OV-INVOICE',
            'branch_id' => $this->branch, 'facility_id' => $facility, 'currency_id' => $this->currency,
            'rate_to_base' => '1', 'billed_on' => $this->today, 'amount' => '10.10',
            'allocations' => [['washing_order_item_id' => $visit, 'amount' => '10.10']]],
            ['Idempotency-Key' => 'overview-washing-bill'])->assertCreated()->json('data.id');
        $this->postJson('/api/washing/bills/'.$bill.'/payments', ['payment_no' => 'OV-WASH-PAY',
            'account_id' => $this->account, 'amount' => '4.05', 'rate_to_base' => '1',
            'bill_units_per_payment_unit' => '1', 'payment_on' => $this->today, 'reason' => 'Overview washing payment'],
            ['Idempotency-Key' => 'overview-washing-payment'])->assertCreated();
        $this->command('corrections/returns', ['return_no' => 'OV-CREDIT', 'weaver_purchase_id' => $this->purchase,
            'from_location_id' => $this->location, 'returned_on' => $this->today, 'reason' => 'Overview return credit',
            'items' => [['carpet_id' => $this->carpets[1], 'expected_sequence' => 2]]])->assertCreated();

        $code = DB::table('currencies')->where('id', $this->currency)->value('code');
        $data = $this->getJson('/api/dashboard/overview')->assertOk()->json('data.business');
        $this->assertSame('100.10', collect($data['legacy_purchases'])->firstWhere('currency', $code)['amount']);
        $this->assertSame('47.47', collect($data['expenses'])->firstWhere('currency', $code)['amount']);
        $this->assertSame('4848.43', collect($data['accounts']['items'])->firstWhere('id', $this->account)['balance']);
        $this->assertLessThanOrEqual(5, count($data['recent_transactions']));
        $this->assertSame('4.05', $data['recent_transactions'][0]['amount']);
        $this->assertSame(1, (int) collect($data['recent_transactions'])->firstWhere('id', $reversed)['reversed']);
    }

    private function support(string $suffix, int $branch, int $account, string $type, string $funded, string $delivered, ?int $currency = null): int
    {
        $post = fn ($path, $data) => $this->postJson('/api/production/'.$path, $data, ['Idempotency-Key' => 'bonus-'.bin2hex(random_bytes(10))]);
        $manager = $post('managers', ['code' => 'OV-M-'.$suffix, 'name' => 'Overview support manager '.$suffix, 'branch_id' => $branch, 'status' => 'active'])->assertCreated()->json('data.id');
        $currency ??= $this->currency;
        $data = ['branch_id' => $branch, 'manager_id' => $manager, 'beneficiary_name' => 'Worker household '.$suffix,
            'assistance_type' => $type, 'currency_id' => $currency, 'rate_to_base' => $currency === $this->currency ? '1' : '2',
            'amount' => '500', 'assistance_on' => $this->today, 'reason' => 'Overview worker support'];
        if ($type === 'advance') {
            $data += ['responsible_party' => 'worker', 'recovery_terms' => 'Repay through recorded installments'];
        }
        $id = $post('assistance', $data)->assertCreated()->json('data.id');
        $post('assistance/'.$id.'/fund', ['account_id' => $account, 'amount' => $funded, 'rate_to_base' => '1',
            'units_per_payment_unit' => $currency === $this->currency ? '1' : '0.5', 'payment_on' => $this->today, 'reason' => 'Fund support through manager'])->assertCreated();
        if (bccomp($delivered, '0', 2) > 0) {
            $post('assistance/'.$id.'/deliver', ['amount' => $delivered, 'delivered_on' => $this->today, 'acknowledgement' => 'Worker confirms receiving support'])->assertCreated();
        }

        return $id;
    }

    public function test_worker_bonuses_are_expenses_only_on_confirmed_delivery_in_the_document_currency(): void
    {
        $bonus = $this->support('BONUS', $this->branch, $this->account, 'bonus', '100', '40');
        $this->support('ADVANCE', $this->branch, $this->account, 'advance', '100', '40');
        $this->support('CUSTODY', $this->branch, $this->account, 'bonus', '100', '0');
        $this->support('FOREIGN', $this->branch, $this->account, 'bonus', '100', '30', $this->foreign);
        $this->postJson('/api/foundation/ledger', ['account_id' => $this->account, 'entry_type' => 'expense', 'amount' => '5', 'rate_to_base' => '1',
            'transaction_date' => $this->today, 'description' => 'Ordinary operating cost'], ['Idempotency-Key' => 'overview-bonus-operating'])->assertCreated();
        $baseCode = DB::table('currencies')->where('id', $this->currency)->value('code');
        $foreignCode = DB::table('currencies')->where('id', $this->foreign)->value('code');
        $expenses = collect($this->getJson('/api/dashboard/overview')->assertOk()->json('data.business.expenses'))->keyBy('currency');
        $this->assertSame('45.00', $expenses[$baseCode]['amount']);
        $this->assertSame('30.00', $expenses[$foreignCode]['amount']);
        $this->assertSame('4595.00', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
        $this->postJson('/api/production/assistance/'.$bonus.'/return', ['account_id' => $this->account, 'amount' => '50', 'rate_to_base' => '1', 'units_per_payment_unit' => '1',
            'payment_on' => $this->today, 'reason' => 'Return undelivered bonus custody'], ['Idempotency-Key' => 'overview-bonus-return'])->assertCreated();
        $this->assertSame('45.00', collect($this->getJson('/api/dashboard/overview')->assertOk()->json('data.business.expenses'))->firstWhere('currency', $baseCode)['amount']);
    }

    public function test_bonus_expenses_require_production_access_branch_scope_and_every_active_funding_account(): void
    {
        $admin = auth()->user();
        $otherAccount = DB::table('accounts')->where('code', 'OV-OTHER')->value('id');
        $this->support('VISIBLE', $this->branch, $this->account, 'bonus', '50', '20');
        $this->support('OTHER-BRANCH', $this->other, $this->account, 'bonus', '50', '30');
        $this->support('HIDDEN-ACCOUNT', $this->branch, $otherAccount, 'bonus', '50', '40');
        $mixed = $this->support('MIXED', $this->branch, $this->account, 'bonus', '100', '40');
        $this->postJson('/api/production/assistance/'.$mixed.'/fund', ['account_id' => $otherAccount, 'amount' => '100', 'rate_to_base' => '1', 'units_per_payment_unit' => '1',
            'payment_on' => $this->today, 'reason' => 'Additional funding from another account'], ['Idempotency-Key' => 'overview-bonus-hidden-funding'])->assertCreated();
        $hidden = DB::table('worker_assistance_fundings as f')->join('account_transactions as t', 't.id', '=', 'f.account_transaction_id')
            ->where('f.assistance_id', $mixed)->where('t.account_id', $otherAccount)->value('t.id');
        $this->scoped(['dashboard.view', 'accounts.view', 'ledger.view']);
        $this->getJson('/api/dashboard/overview')->assertOk()->assertJsonPath('data.business.expenses', []);
        $reader = $this->scoped(['dashboard.view', 'accounts.view', 'ledger.view', 'production.view']);
        $this->getJson('/api/dashboard/overview')->assertOk()->assertJsonPath('data.business.expenses.0.amount', '20.00');
        Sanctum::actingAs($admin);
        $this->postJson('/api/foundation/ledger/'.$hidden.'/reverse', ['reason' => 'Correct unused hidden funding'], ['Idempotency-Key' => 'overview-bonus-hidden-reversal'])->assertCreated();
        Sanctum::actingAs($reader);
        $this->getJson('/api/dashboard/overview')->assertOk()->assertJsonPath('data.business.expenses.0.amount', '60.00');
        $this->assertSame('0.00', WorkerAssistanceService::balances($mixed)['advance_outstanding']);
    }
}
