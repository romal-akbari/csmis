<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use App\Services\ProductionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class BusinessWorkflowApiTest extends HistoricalApiTestCase
{
    private int $branch;

    private int $location;

    private int $currency;

    private int $weaver;

    private int $supplier;

    private int $customer;

    private int $category;

    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->branch = DB::table('branches')->insertGetId(['code' => 'BUS-A', 'name' => 'Business Kabul']);
        $this->location = DB::table('stock_locations')->insertGetId(['code' => 'BUS-L', 'name' => 'Main warehouse', 'branch_id' => $this->branch, 'location_type' => 'warehouse']);
        $manager = DB::table('employees')->insertGetId(['name' => 'Business manager', 'branch_id' => $this->branch]);
        $this->weaver = DB::table('weavers')->insertGetId(['name' => 'Business weaver', 'manager_employee_id' => $manager, 'registration_date' => '2026-01-01']);
        $this->supplier = DB::table('suppliers')->insertGetId(['name' => 'Business supplier']);
        $this->customer = $this->postJson('/api/customers', $this->validCustomer())->assertCreated()->json('data.id');
        $this->category = DB::table('carpet_categories')->insertGetId(['name' => 'Business carpets']);
        $this->currency = DB::table('company_settings')->value('base_currency_id');
        $this->account = $this->postJson('/api/foundation/accounts', $this->accountData())->assertCreated()->json('data.id');
    }

    private function accountData(): array
    {
        return ['code' => 'BUS-CASH', 'name' => 'Company cash', 'currency_id' => $this->currency,
            'account_type' => 'cash', 'status' => 'active', 'opening_amount' => '50000', 'opening_on' => '2026-01-01', 'opening_rate' => '1'];
    }

    private function businessPost(string $path, array $data, ?string $key = null)
    {
        return $this->postJson('/api/business/'.$path, $data, ['Idempotency-Key' => $key ?? 'business-'.bin2hex(random_bytes(12))]);
    }

    private function carpet(string $suffix): int
    {
        return $this->postJson('/api/inventory/carpets', ['carpet_code' => 'BUS-'.$suffix, 'name' => 'Business carpet '.$suffix,
            'registration_branch_id' => $this->branch, 'category_id' => $this->category], ['Idempotency-Key' => 'carpet-'.bin2hex(random_bytes(12))])->assertCreated()->json('data.id');
    }

    private function payment(string $amount, string $date = '2026-01-20'): array
    {
        return ['account_id' => $this->account, 'amount' => $amount, 'rate_to_base' => '1', 'units_per_payment_unit' => '1',
            'payment_on' => $date, 'reason' => 'Business settlement'];
    }

    private function purchaseData(array $carpets): array
    {
        return ['seller_type' => 'weaver', 'weaver_id' => $this->weaver, 'branch_id' => $this->branch,
            'location_id' => $this->location, 'purchase_on' => '2026-01-02', 'currency_id' => $this->currency, 'rate_to_base' => '1',
            'items' => array_map(fn ($id) => ['carpet_id' => $id, 'unit_price' => '1000'], $carpets)];
    }

    private function balance(): string
    {
        return (string) DB::table('v_account_balances')->where('account_id', $this->account)->value('balance');
    }

    private function lot(string $quantity = '10', string $unit = '100'): int
    {
        $material = $this->businessPost('materials', ['code' => 'BUS-WOOL', 'name' => 'Natural wool', 'unit' => 'kg'])->assertCreated()->json('data.id');

        return $this->businessPost('material-receipts', ['material_id' => $material, 'supplier_id' => $this->supplier, 'branch_id' => $this->branch,
            'location_id' => $this->location, 'received_on' => '2026-01-02', 'quantity' => $quantity, 'unit_cost' => $unit,
            'currency_id' => $this->currency, 'rate_to_base' => '1'])->assertCreated()->json('data.id');
    }

    private function order(array $carpets, bool $partner = false, string $basis = 'net_cost_recovery'): int
    {
        $data = ['model' => $partner ? 'partnership' : 'company_funded', 'weaver_id' => $this->weaver, 'branch_id' => $this->branch,
            'currency_id' => $this->currency, 'rate_to_base' => '1', 'ordered_on' => '2026-01-03', 'due_on' => '2026-02-01',
            'description' => 'Hand knotted wool carpets', 'items' => array_map(fn ($id) => ['carpet_id' => $id, 'cost_weight' => '1'], $carpets)];
        if ($partner) {
            $data += ['share_basis' => $basis, 'company_share' => '60', 'company_loss_share' => '60', 'agreement_terms' => 'Company provides wool; partner contributes labor. Support offsets final settlement.'];
        }

        return $this->businessPost($partner ? 'partnerships' : 'production', $data)->assertCreated()->json('data.id');
    }

    private function move(int $order, int $lot, string $type, string $quantity, bool $partner = false)
    {
        return $this->businessPost(($partner ? 'partnerships' : 'production').'/'.$order.'/move', ['lot_id' => $lot,
            'movement_type' => $type, 'quantity' => $quantity, 'moved_on' => '2026-01-05', 'reason' => 'Document material movement']);
    }

    private function complete(int $order, bool $partner = false): void
    {
        $path = ($partner ? 'partnerships' : 'production').'/'.$order;
        $this->businessPost($path.'/progress', ['progress_on' => '2026-01-08', 'percent' => 100, 'notes' => 'Carpets finished and checked'])->assertCreated();
        $this->businessPost($path.'/receive', ['location_id' => $this->location, 'received_on' => '2026-01-09'])->assertCreated();
    }

    private function sell(array $carpets, string $price = '1000', ?string $paid = null): int
    {
        $data = ['customer_id' => $this->customer, 'branch_id' => $this->branch, 'currency_id' => $this->currency, 'rate_to_base' => '1',
            'sold_on' => '2026-01-10', 'due_on' => '2026-01-30', 'items' => array_map(function ($id) use ($price) {
                return ['carpet_id' => $id, 'expected_sequence' => (int) DB::table('v_current_stock')->where('id', $id)->value('sequence_no'), 'unit_price' => $price, 'discount' => '0'];
            }, $carpets)];
        if ($paid) {
            $data['payment'] = $this->payment($paid);
        }

        return $this->businessPost('sales', $data)->assertCreated()->json('data.id');
    }

    public function test_account_opening_is_atomic_company_wide_and_derived_from_ledger(): void
    {
        $this->assertDatabaseHas('accounts', ['id' => $this->account, 'branch_id' => null]);
        $this->assertSame('50000.00', $this->balance());
        $this->assertSame(1, DB::table('account_transactions')->where('account_id', $this->account)->count());
        $data = $this->accountData();
        $data['code'] = 'BUS-FAILED';
        $data['opening_rate'] = '2';
        $this->postJson('/api/foundation/accounts', $data)->assertUnprocessable();
        $this->assertDatabaseMissing('accounts', ['code' => 'BUS-FAILED']);
        $data = $this->accountData();
        unset($data['opening_amount'], $data['opening_on'], $data['opening_rate']);
        $data['name'] = 'Company cash renamed';
        $this->putJson('/api/foundation/accounts/'.$this->account, $data)->assertOk();
        $this->assertSame('50000.00', $this->balance());
    }

    public function test_purchase_receipt_payment_and_retry_are_one_transaction_with_locations(): void
    {
        $carpets = [$this->carpet('A'), $this->carpet('B')];
        $data = $this->purchaseData($carpets);
        $data['payment'] = $this->payment('500');
        $id = $this->businessPost('purchases', $data, 'combined-purchase')->assertCreated()->json('data.id');
        $this->businessPost('purchases', $data, 'combined-purchase')->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('replayed', true);
        $this->assertSame('49500.00', $this->balance());
        $this->assertSame('1500.00', DB::table('v_weaver_purchase_balances')->where('purchase_id', $id)->value('remaining_amount'));
        $this->assertSame(2, DB::table('v_current_stock')->whereIn('id', $carpets)->where('stock_status', 'in_stock')->where('location_id', $this->location)->count());
        $this->getJson('/api/business/purchases/'.$id)->assertOk()->assertJsonPath('data.items.0.location_name', 'Main warehouse');
        $this->assertStringStartsWith('PUR-', DB::table('weaver_purchases')->where('id', $id)->value('purchase_no'));
        $data['notes'] = 'Changed';
        $this->businessPost('purchases', $data, 'combined-purchase')->assertConflict();
    }

    public function test_failed_payment_rolls_back_purchase_receipt_stock_and_number(): void
    {
        $carpet = $this->carpet('FAIL');
        $data = $this->purchaseData([$carpet]);
        $data['payment'] = $this->payment('2000');
        $before = DB::table('document_sequences')->where('kind', 'PUR')->value('last_number');
        $this->businessPost('purchases', $data)->assertUnprocessable();
        $this->assertSame('registered', DB::table('v_current_stock')->where('id', $carpet)->value('stock_status'));
        $this->assertSame('50000.00', $this->balance());
        $this->assertEquals($before, DB::table('document_sequences')->where('kind', 'PUR')->value('last_number'));
        $this->assertSame(0, DB::table('weaver_purchase_items')->where('carpet_id', $carpet)->count());
    }

    public function test_automatic_draft_purchase_and_receipt_require_stock_access_and_commit_once(): void
    {
        $carpet = $this->carpet('DRAFT');
        $data = $this->purchaseData([$carpet]);
        unset($data['seller_type'], $data['location_id']);
        $draft = $this->postJson('/api/inventory/corrections/drafts', $data, ['Idempotency-Key' => 'automatic-draft-create'])
            ->assertCreated()->json('data');
        $this->assertNotEmpty($draft['purchase_no']);
        $this->assertSame('registered', DB::table('v_current_stock')->where('id', $carpet)->value('stock_status'));

        $user = User::factory()->create();
        $role = Role::create(['name' => 'Draft receiver '.$user->id]);
        $role->permissions()->attach(DB::table('permissions')->whereIn('code', ['weaver-purchases.draft', 'weaver-purchases.create'])->pluck('id'));
        $user->roles()->attach($role);
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->branch]);
        Sanctum::actingAs($user);
        $path = '/api/inventory/corrections/drafts/'.$draft['id'].'/post';
        $request = ['version' => 1, 'location_id' => $this->location];
        $this->postJson($path, $request, ['Idempotency-Key' => 'automatic-draft-receive'])->assertForbidden();
        $this->assertDatabaseHas('purchase_drafts', ['id' => $draft['id'], 'state' => 'draft', 'version' => 1]);
        $this->assertSame(0, DB::table('weaver_purchase_items')->where('carpet_id', $carpet)->count());
        $this->assertSame(0, DB::table('stock_events')->where('carpet_id', $carpet)->count());

        $role->permissions()->attach(DB::table('permissions')->where('code', 'stock.receive')->value('id'));
        $purchase = $this->postJson($path, $request, ['Idempotency-Key' => 'automatic-draft-receive'])
            ->assertOk()->assertJsonPath('data.state', 'posted')->json('data.posted_purchase_id');
        $this->postJson($path, $request, ['Idempotency-Key' => 'automatic-draft-receive'])
            ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.posted_purchase_id', $purchase);
        $this->assertDatabaseHas('weaver_purchases', ['id' => $purchase, 'purchase_no' => $draft['purchase_no']]);
        $this->assertDatabaseHas('v_current_stock', ['id' => $carpet, 'stock_status' => 'in_stock', 'location_id' => $this->location]);
        $this->assertSame(1, DB::table('weaver_purchase_items')->where('carpet_id', $carpet)->count());
        $this->assertSame(2, DB::table('stock_events')->where('carpet_id', $carpet)->count());
        $this->assertSame('1000.00', DB::table('v_weaver_purchase_balances')->where('purchase_id', $purchase)->value('remaining_amount'));
        $this->assertSame('50000.00', $this->balance());
    }

    public function test_supplier_purchase_payment_and_reversal_reconcile(): void
    {
        $data = $this->purchaseData([$this->carpet('SUP')]);
        unset($data['weaver_id']);
        $data['seller_type'] = 'supplier';
        $data['supplier_id'] = $this->supplier;
        $data['payment'] = $this->payment('250');
        $id = $this->businessPost('purchases', $data)->assertCreated()->json('data.id');
        $this->getJson('/api/business/purchases')->assertOk()->assertJsonPath('data.0.seller_name', 'Business supplier')->assertJsonPath('data.0.remaining_amount', '750.00');
        $payment = DB::table('supplier_carpet_payments')->where('purchase_id', $id)->first();
        $this->businessPost('payments/'.$payment->account_transaction_id.'/reverse', ['reason' => 'Correct supplier payment'])->assertOk();
        $this->assertSame('50000.00', $this->balance());
        $this->assertSame('1000.00', DB::table('v_weaver_purchase_balances')->where('purchase_id', $id)->value('remaining_amount'));
    }

    public function test_expenses_support_partial_payment_reversal_and_safe_void(): void
    {
        $id = $this->businessPost('expenses', ['branch_id' => $this->branch, 'currency_id' => $this->currency, 'rate_to_base' => '1',
            'expense_on' => '2026-01-02', 'expense_category_id' => DB::table('expense_categories')->value('id'), 'payee' => 'Office landlord',
            'description' => 'January office rent', 'amount' => '1000', 'payment' => $this->payment('300')])->assertCreated()->json('data.id');
        $this->assertSame('49700.00', $this->balance());
        $this->getJson('/api/business/expenses')->assertOk()->assertJsonPath('data.0.remaining_amount', '700.00');
        $this->businessPost('expenses/'.$id.'/pay', $this->payment('701'))->assertUnprocessable();
        $this->businessPost('expenses/'.$id.'/void', ['voided_on' => '2026-01-20', 'reason' => 'Incorrect rent voucher'])->assertUnprocessable();
        $transaction = DB::table('operating_expense_payments')->where('expense_id', $id)->value('account_transaction_id');
        $this->businessPost('payments/'.$transaction.'/reverse', ['reason' => 'Correct rent payment'])->assertOk();
        $this->businessPost('expenses/'.$id.'/void', ['voided_on' => '2026-01-20', 'reason' => 'Incorrect rent voucher'])->assertCreated();
        $this->businessPost('expenses/'.$id.'/pay', $this->payment('10'))->assertUnprocessable();
        $this->assertSame('50000.00', $this->balance());
    }

    public function test_company_production_tracks_material_custody_actual_cost_labor_and_receiving(): void
    {
        $carpets = [$this->carpet('P1'), $this->carpet('P2')];
        $lot = $this->lot();
        $order = $this->order($carpets);
        $this->move($order, $lot, 'issue', '11')->assertUnprocessable();
        $this->move($order, $lot, 'issue', '6')->assertCreated();
        $this->move($order, $lot, 'consume', '7')->assertUnprocessable();
        $this->move($order, $lot, 'consume', '5')->assertCreated();
        $this->move($order, $lot, 'return', '1')->assertCreated();
        $this->businessPost('production/'.$order.'/receive', ['location_id' => $this->location, 'received_on' => '2026-01-09'])->assertUnprocessable();
        $this->businessPost('production/'.$order.'/charge', ['earned_on' => '2026-01-07', 'amount' => '500', 'description' => 'Completed weaving labor'])->assertCreated();
        $this->businessPost('production/'.$order.'/pay', $this->payment('100') + ['payment_type' => 'advance'])->assertCreated();
        $this->businessPost('production/'.$order.'/pay', $this->payment('200') + ['payment_type' => 'labor'])->assertCreated();
        $this->complete($order);
        $this->assertSame('49700.00', $this->balance());
        $this->assertSame('5.000', ProductionService::warehouse($lot));
        $this->assertSame('1000.00', DB::table('production_receipts')->where('production_order_id', $order)->value('total_cost_base'));
        $this->assertSame(['500.00', '500.00'], DB::table('production_receipt_items')->whereIn('production_carpet_id', DB::table('production_carpets')->where('production_order_id', $order)->select('id'))->pluck('cost_base')->all());
        $this->assertSame(2, DB::table('v_current_stock')->whereIn('id', $carpets)->where('business_model', 'company_funded')->where('stock_status', 'in_stock')->count());
        $this->businessPost('production/'.$order.'/charge', ['earned_on' => '2026-01-20', 'amount' => '10', 'description' => 'Cannot alter received costs'])->assertUnprocessable();
        $this->businessPost('purchases', $this->purchaseData([$carpets[0]]))->assertUnprocessable();
    }

    public function test_material_receiving_payment_and_duplicate_retry_do_not_duplicate_quantity(): void
    {
        $data = ['code' => 'RETRY-WOOL', 'name' => 'Wool retry', 'unit' => 'kg'];
        $this->businessPost('materials', $data, 'material-idempotent')->assertCreated();
        $this->businessPost('materials', $data, 'material-idempotent')->assertOk();
        $lot = $this->lot();
        $this->businessPost('material-receipts/'.$lot.'/pay', $this->payment('1000'))->assertCreated();
        $this->businessPost('material-receipts/'.$lot.'/pay', $this->payment('1'))->assertUnprocessable();
        $this->assertSame('49000.00', $this->balance());
        $this->getJson('/api/business/material-receipts/'.$lot)->assertOk()->assertJsonPath('data.warehouse_quantity', '10.000');
    }

    public function test_sales_partial_collection_prevent_double_sale_and_overpayment(): void
    {
        $carpet = $this->carpet('SALE');
        $this->businessPost('purchases', $this->purchaseData([$carpet]))->assertCreated();
        $sale = $this->sell([$carpet], '1500', '500');
        $this->assertSame('50500.00', $this->balance());
        $this->getJson('/api/business/sales')->assertOk()->assertJsonPath('data.0.remaining_amount', '1000.00');
        $this->businessPost('sales/'.$sale.'/collect', $this->payment('1001'))->assertUnprocessable();
        $this->businessPost('sales/'.$sale.'/collect', $this->payment('1000'))->assertCreated();
        $this->assertSame('51500.00', $this->balance());
        $this->assertSame('sold', DB::table('v_current_stock')->where('id', $carpet)->value('stock_status'));
        $data = ['customer_id' => $this->customer, 'branch_id' => $this->branch, 'currency_id' => $this->currency, 'rate_to_base' => '1', 'sold_on' => '2026-01-20', 'due_on' => '2026-01-30',
            'items' => [['carpet_id' => $carpet, 'expected_sequence' => 2, 'unit_price' => '1000', 'discount' => '0']]];
        $this->businessPost('sales', $data)->assertConflict();
        $this->assertSame(1, DB::table('trade_sale_items')->where('carpet_id', $carpet)->count());
    }

    public function test_partnership_recovers_costs_and_offsets_support_only_after_collection(): void
    {
        $carpet = $this->carpet('PART');
        $lot = $this->lot('2');
        $order = $this->order([$carpet], true);
        $this->move($order, $lot, 'issue', '2', true)->assertCreated();
        $this->move($order, $lot, 'consume', '2', true)->assertCreated();
        $this->businessPost('partnerships/'.$order.'/contribution', ['contributed_on' => '2026-01-06', 'amount' => '100', 'description' => 'Partner weaving contribution'])->assertCreated();
        $support = $this->businessPost('partnerships/'.$order.'/pay', $this->payment('50') + ['payment_type' => 'support'])->assertCreated()->json('data.id');
        $this->complete($order, true);
        $sale = $this->sell([$carpet]);
        $this->businessPost('partnerships/'.$order.'/settle', ['settled_on' => '2026-01-20'])->assertUnprocessable();
        $this->businessPost('sales/'.$sale.'/collect', $this->payment('1000'))->assertCreated();
        $this->getJson('/api/business/partnerships/'.$order)->assertOk()->assertJsonPath('data.settlement_preview.company_entitlement_base', '620.00')->assertJsonPath('data.settlement_preview.balance_due_base', '330.00');
        $settlement = $this->businessPost('partnerships/'.$order.'/settle', ['settled_on' => '2026-01-20'], 'final-partnership-settlement')->assertCreated()->json('data.id');
        $this->businessPost('partnerships/'.$order.'/settle', ['settled_on' => '2026-01-20'], 'final-partnership-settlement')->assertOk()->assertJsonPath('data.id', $settlement);
        $this->businessPost('partnerships/'.$order.'/settlement-pay', $this->payment('330'))->assertCreated();
        $this->businessPost('partnerships/'.$order.'/settlement-pay', $this->payment('1'))->assertUnprocessable();
        $this->assertSame('50620.00', $this->balance());
        $transaction = DB::table('production_payments')->where('id', $support)->value('account_transaction_id');
        $this->businessPost('payments/'.$transaction.'/reverse', ['reason' => 'Cannot change final settlement'])->assertUnprocessable();
        $receipt = DB::table('trade_customer_receipts')->where('sale_id', $sale)->value('account_transaction_id');
        $this->businessPost('payments/'.$receipt.'/reverse', ['reason' => 'Cannot invalidate settlement'])->assertUnprocessable();
    }

    public function test_company_accounts_require_explicit_access_instead_of_branch_membership(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Business scope '.$user->id]);
        $role->permissions()->attach(DB::table('permissions')->whereIn('code', ['purchasing.view', 'purchasing.pay', 'accounts.view', 'ledger.view', 'ledger.create', 'weaver-payments.create'])->pluck('id'));
        $user->roles()->attach($role);
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->branch]);
        $carpet = $this->carpet('SCOPE');
        $purchase = $this->businessPost('purchases', $this->purchaseData([$carpet]))->assertCreated()->json('data.id');
        Sanctum::actingAs($user);
        $this->getJson('/api/foundation/accounts')->assertOk()->assertJsonPath('total', 0);
        $this->businessPost('purchases/'.$purchase.'/pay', $this->payment('10'))->assertNotFound();
        DB::table('user_accounts')->insert(['user_id' => $user->id, 'account_id' => $this->account]);
        $this->businessPost('purchases/'.$purchase.'/pay', $this->payment('10'))->assertCreated();
        $this->assertSame('49990.00', $this->balance());
        $this->getJson('/api/foundation/accounts')->assertOk()->assertJsonPath('total', 1);
    }

    public static function partnershipCases(): array
    {
        return [['gross', '1000', '350.00', '50600.00'], ['net_cost_recovery', '100', '-30.00', '50080.00']];
    }

    #[DataProvider('partnershipCases')]
    public function test_gross_sharing_and_loss_recovery_follow_the_saved_contract(string $basis, string $revenue, string $due, string $cash): void
    {
        $carpet = $this->carpet('TERMS');
        $lot = $this->lot('2');
        $order = $this->order([$carpet], true, $basis);
        $this->move($order, $lot, 'issue', '2', true)->assertCreated();
        $this->move($order, $lot, 'consume', '2', true)->assertCreated();
        $this->businessPost('partnerships/'.$order.'/contribution', ['contributed_on' => '2026-01-06', 'amount' => '100', 'description' => 'Agreed partner labor input'])->assertCreated();
        $this->businessPost('partnerships/'.$order.'/pay', $this->payment('50') + ['payment_type' => 'support'])->assertCreated();
        $this->complete($order, true);
        $this->sell([$carpet], $revenue, $revenue);
        $this->getJson('/api/business/partnerships/'.$order)->assertOk()->assertJsonPath('data.settlement_preview.balance_due_base', $due);
        $this->businessPost('partnerships/'.$order.'/settle', ['settled_on' => '2026-01-20'])->assertCreated();
        $this->businessPost('partnerships/'.$order.'/settlement-pay', $this->payment(ltrim($due, '-')))->assertCreated();
        $this->assertSame($cash, $this->balance());
        $this->getJson('/api/business/partnerships/'.$order)->assertOk()->assertJsonPath('data.settlement.settlement_remaining_base', '0.00');
    }

    public function test_material_rounding_and_finished_carpet_allocations_reconcile_to_the_receipt(): void
    {
        $carpets = [$this->carpet('R1'), $this->carpet('R2'), $this->carpet('R3')];
        $lot = $this->lot('3', '0.01');
        $order = $this->order($carpets);
        $this->move($order, $lot, 'issue', '3')->assertCreated();
        foreach (['1.001', '1.001', '0.998'] as $quantity) {
            $this->move($order, $lot, 'consume', $quantity)->assertCreated();
        }
        $this->complete($order);
        $this->assertSame('0.03', (string) DB::table('material_movements')->where('production_order_id', $order)->sum('cost_base'));
        $receipt = DB::table('production_receipts')->where('production_order_id', $order)->first();
        $this->assertSame('0.03', $receipt->total_cost_base);
        $this->assertSame('0.03', (string) DB::table('production_receipt_items')->where('production_receipt_id', $receipt->id)->sum('cost_base'));
        $this->assertSame('0.000', ProductionService::held($lot, $order));
        $this->assertSame('0.000', ProductionService::warehouse($lot));
    }

    public function test_produced_carpets_enter_washing_and_sold_costs_cannot_be_changed(): void
    {
        $carpet = $this->carpet('WASH');
        $lot = $this->lot('2');
        $order = $this->order([$carpet]);
        $this->move($order, $lot, 'issue', '2')->assertCreated();
        $this->move($order, $lot, 'consume', '2')->assertCreated();
        $this->complete($order);
        $facility = DB::table('washing_facilities')->insertGetId(['name' => 'Business washer']);
        $yard = DB::table('stock_locations')->insertGetId(['code' => 'BUS-WASH', 'name' => 'Washing yard', 'washing_facility_id' => $facility, 'location_type' => 'washing']);
        $this->getJson('/api/washing/eligible-carpets?branch_id='.$this->branch)->assertOk()->assertJsonPath('data.0.id', $carpet);
        $wash = $this->postJson('/api/washing/dispatch', ['order_no' => 'BUS-WASH', 'branch_id' => $this->branch, 'facility_id' => $facility, 'facility_location_id' => $yard,
            'sent_on' => '2026-01-10', 'items' => [['carpet_id' => $carpet, 'expected_sequence' => 1]]], ['Idempotency-Key' => 'produced-carpet-washing'])->assertCreated()->json('data.id');
        $visit = DB::table('washing_order_items')->where('washing_order_id', $wash)->value('id');
        $this->postJson('/api/washing/orders/'.$wash.'/complete', ['completed_on' => '2026-01-11', 'items' => [['washing_order_item_id' => $visit, 'expected_sequence' => 2]]], ['Idempotency-Key' => 'produced-wash-complete'])->assertCreated();
        $this->postJson('/api/washing/orders/'.$wash.'/return', ['returned_on' => '2026-01-12', 'location_id' => $this->location, 'items' => [['washing_order_item_id' => $visit, 'expected_sequence' => 3]]], ['Idempotency-Key' => 'produced-wash-return'])->assertCreated();
        $bill = $this->postJson('/api/washing/bills', ['expense_no' => 'BUS-WB', 'invoice_no' => 'BUS-WASH-INVOICE', 'branch_id' => $this->branch, 'facility_id' => $facility, 'currency_id' => $this->currency,
            'rate_to_base' => '1', 'billed_on' => '2026-01-12', 'amount' => '50', 'allocations' => [['washing_order_item_id' => $visit, 'amount' => '50']]], ['Idempotency-Key' => 'produced-wash-bill'])->assertCreated()->json('data.id');
        $sale = $this->businessPost('sales', ['customer_id' => $this->customer, 'branch_id' => $this->branch, 'currency_id' => $this->currency, 'rate_to_base' => '1', 'sold_on' => '2026-01-13', 'due_on' => '2026-01-30',
            'items' => [['carpet_id' => $carpet, 'expected_sequence' => 4, 'unit_price' => '500', 'discount' => '0']]])->assertCreated()->json('data.id');
        $this->assertSame('250.00', DB::table('trade_sale_items')->where('sale_id', $sale)->value('cost_base'));
        $this->postJson('/api/washing/bills/'.$bill.'/void', ['voided_on' => '2026-01-20', 'reason' => 'Must retain sold inventory cost'], ['Idempotency-Key' => 'sold-washing-cost-void'])->assertUnprocessable();
    }

    public function test_branch_access_and_module_reversal_permissions_cannot_be_bypassed(): void
    {
        $data = ['branch_id' => $this->branch, 'currency_id' => $this->currency, 'rate_to_base' => '1', 'expense_on' => '2026-01-02',
            'expense_category_id' => DB::table('expense_categories')->value('id'), 'payee' => 'Office landlord', 'description' => 'January rent', 'amount' => '100', 'payment' => $this->payment('50')];
        $expense = $this->businessPost('expenses', $data)->assertCreated()->json('data.id');
        $other = DB::table('branches')->insertGetId(['code' => 'BUS-OTHER', 'name' => 'Other branch']);
        $data['branch_id'] = $other;
        unset($data['payment']);
        $hidden = $this->businessPost('expenses', $data)->assertCreated()->json('data.id');
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Business limited '.$user->id]);
        $role->permissions()->attach(DB::table('permissions')->whereIn('code', ['expenses.view', 'expenses.pay', 'ledger.reverse'])->pluck('id'));
        $user->roles()->attach($role);
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->branch]);
        DB::table('user_accounts')->insert(['user_id' => $user->id, 'account_id' => $this->account]);
        Sanctum::actingAs($user);
        $this->getJson('/api/business/expenses')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/business/expenses/'.$hidden)->assertNotFound();
        $this->businessPost('expenses/'.$hidden.'/pay', $this->payment('10'))->assertNotFound();
        $transaction = DB::table('operating_expense_payments')->where('expense_id', $expense)->value('account_transaction_id');
        $this->postJson('/api/foundation/ledger/'.$transaction.'/reverse', ['reason' => 'Attempt reversal bypass'], ['Idempotency-Key' => 'business-bypass-reversal'])->assertForbidden();
        $this->assertSame('49950.00', $this->balance());
    }

    public function test_expense_receipts_are_validated_private_and_scoped(): void
    {
        Storage::fake('local');
        $expense = $this->businessPost('expenses', ['branch_id' => $this->branch, 'currency_id' => $this->currency, 'rate_to_base' => '1', 'expense_on' => '2026-01-02',
            'expense_category_id' => DB::table('expense_categories')->value('id'), 'payee' => 'Landlord', 'description' => 'Receipt verification', 'amount' => '100'])->assertCreated()->json('data.id');
        $this->postJson('/api/business/expenses/'.$expense.'/attachments', ['file' => UploadedFile::fake()->create('unsafe.html', 1, 'text/html')])->assertUnprocessable();
        $image = UploadedFile::fake()->createWithContent('receipt.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0ioAAAAASUVORK5CYII='));
        $id = $this->postJson('/api/business/expenses/'.$expense.'/attachments', ['file' => $image])->assertCreated()->json('data.id');
        $this->get('/api/business/expenses/'.$expense.'/attachments/'.$id)->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Receipt scope '.$user->id]);
        $role->permissions()->attach(DB::table('permissions')->where('code', 'expenses.view')->value('id'));
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
        $this->getJson('/api/business/expenses/'.$expense.'/attachments/'.$id)->assertNotFound();
    }

    public function test_automatic_references_skip_existing_manual_numbers_and_weaver_activity_reconciles(): void
    {
        DB::table('document_sequences')->updateOrInsert(['kind' => 'PUR'], ['last_number' => 0]);
        $reserved = 'PUR-'.now()->format('Y').'-00000001';
        $old = $this->purchaseData([$this->carpet('NUMBER1')]);
        unset($old['seller_type'], $old['location_id']);
        $this->postJson('/api/inventory/purchases', $old + ['purchase_no' => $reserved], ['Idempotency-Key' => 'reserved-purchase-reference'])->assertCreated();
        $data = $this->purchaseData([$this->carpet('NUMBER2')]);
        $data['payment'] = $this->payment('250');
        $new = $this->businessPost('purchases', $data)->assertCreated()->json('data.purchase_no');
        $this->assertNotSame($reserved, $new);
        $this->getJson('/api/business/weavers/'.$this->weaver.'/statement')->assertOk()->assertJsonCount(2, 'data.purchases')
            ->assertJsonPath('data.purchases.0.remaining_amount', '750.00')->assertJsonCount(1, 'data.transactions')->assertJsonPath('data.transactions.0.amount', '250.00');
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Weaver statement scope '.$user->id]);
        $role->permissions()->attach(DB::table('permissions')->where('code', 'weavers.view')->value('id'));
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
        $this->getJson('/api/business/weavers/'.$this->weaver.'/statement')->assertNotFound();
    }

    public function test_foreign_currency_settlement_preserves_both_currency_balances(): void
    {
        $foreign = DB::table('currencies')->where('id', '<>', $this->currency)->where('is_active', true)->value('id');
        $account = $this->postJson('/api/foundation/accounts', ['code' => 'BUS-FX', 'name' => 'Foreign cash', 'currency_id' => $foreign, 'account_type' => 'cash', 'status' => 'active',
            'opening_amount' => '7000', 'opening_on' => '2026-01-01', 'opening_rate' => '0.01428571'])->assertCreated()->json('data.id');
        $data = $this->purchaseData([$this->carpet('FX')]);
        $data['seller_type'] = 'supplier';
        unset($data['weaver_id']);
        $data['supplier_id'] = $this->supplier;
        $data['items'][0]['unit_price'] = '100';
        $purchase = $this->businessPost('purchases', $data)->assertCreated()->json('data.id');
        $payment = array_replace($this->payment('3500'), ['account_id' => $account, 'rate_to_base' => '0.01428571', 'units_per_payment_unit' => '0.01428571']);
        $this->businessPost('purchases/'.$purchase.'/pay', $payment)->assertCreated();
        $this->assertSame('50.00', DB::table('v_weaver_purchase_balances')->where('purchase_id', $purchase)->value('remaining_amount'));
        $this->assertSame('3500.00', DB::table('v_account_balances')->where('account_id', $account)->value('balance'));
        $this->assertSame('50000.00', $this->balance());
        $payment['units_per_payment_unit'] = '0.02';
        $this->businessPost('purchases/'.$purchase.'/pay', $payment)->assertUnprocessable()->assertJsonValidationErrors('units_per_payment_unit');
    }
}
