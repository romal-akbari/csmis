<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class InventoryCorrectionApiTest extends HistoricalApiTestCase
{
    private int $branch;

    private int $location;

    private int $secondLocation;

    private int $currency;

    private int $weaver;

    private int $account;

    private array $carpets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->branch = DB::table('branches')->insertGetId(['code' => 'COR-B', 'name' => 'Correction branch']);
        $this->location = DB::table('stock_locations')->insertGetId(['code' => 'COR-L', 'name' => 'Correction shelf', 'branch_id' => $this->branch, 'location_type' => 'shelf']);
        $this->secondLocation = DB::table('stock_locations')->insertGetId(['code' => 'COR-L2', 'name' => 'Second shelf', 'branch_id' => $this->branch, 'location_type' => 'shelf']);
        $this->currency = DB::table('company_settings')->value('base_currency_id');
        $category = DB::table('carpet_categories')->insertGetId(['name' => 'Corrections wool']);
        $manager = DB::table('employees')->insertGetId(['name' => 'Corrections manager', 'branch_id' => $this->branch]);
        $this->weaver = DB::table('weavers')->insertGetId(['name' => 'Corrections weaver', 'manager_employee_id' => $manager, 'registration_date' => '2026-01-01']);
        $this->account = DB::table('accounts')->insertGetId(['code' => 'COR-CASH', 'name' => 'Corrections cash', 'branch_id' => $this->branch, 'currency_id' => $this->currency]);
        $this->postJson('/api/foundation/ledger', ['account_id' => $this->account, 'entry_type' => 'opening', 'amount' => '5000', 'rate_to_base' => '1', 'transaction_date' => '2026-01-01', 'description' => 'Corrections opening'], ['Idempotency-Key' => 'corrections-opening'])->assertCreated();
        $this->carpets = [];
        foreach ([1, 2] as $n) {
            $this->carpets[] = $this->command('carpets', ['carpet_code' => 'COR-C'.$n, 'name' => 'Correction carpet', 'registration_branch_id' => $this->branch, 'category_id' => $category])->assertCreated()->json('data.id');
        }
    }

    private function command(string $path, array $data, ?string $key = null)
    {
        return $this->postJson('/api/inventory/'.$path, $data, ['Idempotency-Key' => $key ?? 'cor-'.bin2hex(random_bytes(12))]);
    }

    private function purchaseData(): array
    {
        return ['purchase_no' => 'COR-P', 'weaver_id' => $this->weaver, 'branch_id' => $this->branch, 'purchase_on' => '2026-01-02',
            'currency_id' => $this->currency, 'rate_to_base' => '1', 'items' => [['carpet_id' => $this->carpets[0], 'unit_price' => '100.10'], ['carpet_id' => $this->carpets[1], 'unit_price' => '200.20']]];
    }

    private function purchase(): int
    {
        return $this->command('purchases', $this->purchaseData())->assertCreated()->json('data.id');
    }

    private function receive(): void
    {
        $this->command('receipts', ['receipt_no' => 'COR-R', 'location_id' => $this->location, 'received_on' => '2026-01-03',
            'items' => $this->items(1)])->assertCreated();
    }

    private function items(int $sequence): array
    {
        return array_map(fn ($id) => ['carpet_id' => $id, 'expected_sequence' => $sequence], $this->carpets);
    }

    private function pay(int $purchase, string $amount = '100.05'): void
    {
        $this->command('payments', ['payment_no' => 'COR-PAY', 'weaver_id' => $this->weaver, 'branch_id' => $this->branch, 'account_id' => $this->account,
            'payment_type' => 'purchase', 'amount' => $amount, 'rate_to_base' => '1', 'payment_on' => '2026-01-03', 'reason' => 'Genuine weaver payment',
            'allocations' => [['weaver_purchase_id' => $purchase, 'amount_in_payment_currency' => $amount, 'purchase_units_per_payment_unit' => '1']]])->assertCreated();
    }

    private function balance(int $id): object
    {
        return DB::table('v_weaver_purchase_balances')->where('purchase_id', $id)->first();
    }

    private function refundData(int $purchase, string $amount): array
    {
        return ['refund_no' => 'COR-REF', 'weaver_purchase_id' => $purchase, 'account_id' => $this->account, 'amount' => $amount,
            'rate_to_base' => '1', 'purchase_units_per_refund_unit' => '1', 'refunded_on' => '2026-01-05', 'reason' => 'Actual weaver refund'];
    }

    public function test_partial_draft_can_be_edited_then_posted_once_and_final_snapshot_is_immutable(): void
    {
        $draft = $this->command('corrections/drafts', ['purchase_no' => 'COR-D', 'branch_id' => $this->branch, 'items' => []], 'draft-save-retry')->assertCreated()->json('data');
        $this->assertSame(0, DB::table('stock_events')->count());
        $this->command('corrections/drafts', ['purchase_no' => 'COR-D', 'branch_id' => $this->branch, 'items' => []], 'draft-save-retry')->assertOk()->assertJsonPath('replayed', true);
        $this->command('corrections/drafts/'.$draft['id'].'/post', ['version' => 1])->assertUnprocessable();
        $data = $this->purchaseData();
        $data['purchase_no'] = 'COR-D';
        $data['version'] = 1;
        $this->putJson('/api/inventory/corrections/drafts/'.$draft['id'], $data, ['Idempotency-Key' => 'draft-edit-test'])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/inventory/corrections/drafts/'.$draft['id'], $data, ['Idempotency-Key' => 'draft-stale-test'])->assertConflict();
        $posted = $this->command('corrections/drafts/'.$draft['id'].'/post', ['version' => 2], 'draft-post-test')->assertOk()->assertJsonPath('data.state', 'posted')->json('data.posted_purchase_id');
        $this->command('corrections/drafts/'.$draft['id'].'/post', ['version' => 2], 'draft-post-test')->assertOk()->assertJsonPath('replayed', true);
        $this->command('corrections/drafts/'.$draft['id'].'/post', ['version' => 2])->assertConflict();
        $this->assertSame('300.30', $this->balance($posted)->remaining_amount);
        $this->getJson('/api/inventory/corrections/drafts/'.$draft['id'])->assertOk()->assertJsonCount(2, 'data.items');
        $this->expectException(QueryException::class);
        DB::table('purchase_draft_items')->where('purchase_draft_id', $draft['id'])->delete();
    }

    public function test_cancelled_draft_does_not_acquire_stock_or_create_debt(): void
    {
        $draft = $this->command('corrections/drafts', $this->purchaseData())->assertCreated()->json('data');
        $this->command('purchases', $this->purchaseData())->assertUnprocessable()->assertJsonValidationErrors('purchase_no');
        $this->command('corrections/drafts/'.$draft['id'].'/cancel', ['version' => 1, 'reason' => 'Draft no longer needed'], 'cancel-draft-test')->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->command('corrections/drafts/'.$draft['id'].'/cancel', ['version' => 1, 'reason' => 'Draft no longer needed'], 'cancel-draft-test')->assertOk();
        $this->command('corrections/drafts/'.$draft['id'].'/post', ['version' => 2])->assertConflict();
        $this->assertSame(0, DB::table('weaver_purchases')->count());
        $this->assertSame(0, DB::table('stock_events')->count());
    }

    public function test_void_releases_unreceived_carpets_and_allows_reacquisition_without_erasing_history(): void
    {
        $purchase = $this->purchase();
        $void = ['voided_on' => '2026-01-03', 'reason' => 'Wrong purchase document', 'items' => $this->items(1)];
        $this->command('purchases/'.$purchase.'/void', $void, 'void-purchase-test')->assertCreated();
        $this->command('purchases/'.$purchase.'/void', $void, 'void-purchase-test')->assertOk();
        $this->assertSame('300.30', $this->balance($purchase)->total_amount);
        $this->assertSame('0.00', $this->balance($purchase)->remaining_amount);
        $data = $this->purchaseData();
        $data['purchase_no'] = 'COR-P2';
        $this->command('purchases', $data)->assertUnprocessable()->assertJsonValidationErrors('purchase_on');
        $data['purchase_on'] = '2026-01-04';
        $new = $this->command('purchases', $data)->assertCreated()->json('data.id');
        $this->assertNotEquals($purchase, $new);
        $this->getJson('/api/inventory/carpets')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertOk()->assertJsonCount(3, 'data.events');
        $this->getJson('/api/inventory/purchases')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_paid_void_creates_credit_without_fake_cash_reversal_and_refunds_reconcile(): void
    {
        $purchase = $this->purchase();
        $this->pay($purchase);
        $this->command('purchases/'.$purchase.'/void', ['voided_on' => '2026-01-04', 'reason' => 'Cancel before receiving', 'items' => $this->items(1)])->assertCreated();
        $this->assertSame('-100.05', $this->balance($purchase)->remaining_amount);
        $this->assertSame('4899.95', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
        $this->command('corrections/refunds', $this->refundData($purchase, '100.06'))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $refund = $this->command('corrections/refunds', $this->refundData($purchase, '100.05'), 'refund-retry-test')->assertCreated()->json('data');
        $this->getJson('/api/inventory/corrections/refunds/'.$refund['id'])->assertOk()->assertJsonPath('data.amount', '100.05')->assertJsonPath('data.purchase_no', 'COR-P');
        $this->command('corrections/refunds', $this->refundData($purchase, '100.05'), 'refund-retry-test')->assertOk();
        $this->assertSame('0.00', $this->balance($purchase)->remaining_amount);
        $this->assertSame('5000.00', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
        $this->postJson('/api/foundation/ledger/'.$refund['account_transaction_id'].'/reverse', ['reason' => 'Correct refund mistake'], ['Idempotency-Key' => 'reverse-refund-test'])->assertCreated();
        $this->assertSame('-100.05', $this->balance($purchase)->remaining_amount);
        $this->getJson('/api/inventory/corrections/refunds')->assertOk()->assertJsonPath('data.0.reversed', 1);
    }

    public function test_partial_return_reduces_cost_and_debt_once_and_returned_carpet_can_be_reacquired(): void
    {
        $purchase = $this->purchase();
        $this->pay($purchase, '300.30');
        $this->receive();
        $this->command('purchases/'.$purchase.'/void', ['voided_on' => '2026-01-04', 'reason' => 'Invalid void', 'items' => $this->items(2)])->assertUnprocessable();
        $this->assertDatabaseMissing('purchase_voids', ['weaver_purchase_id' => $purchase]);
        $data = ['return_no' => 'COR-RET', 'weaver_purchase_id' => $purchase, 'from_location_id' => $this->location, 'returned_on' => '2026-01-04', 'reason' => 'Defective weave', 'items' => [$this->items(2)[0]]];
        $return = $this->command('corrections/returns', $data, 'return-retry-test')->assertCreated()->json('data');
        $this->command('corrections/returns', $data, 'return-retry-test')->assertOk();
        $this->assertSame('200.20', $this->balance($purchase)->net_amount);
        $this->assertSame('-100.10', $this->balance($purchase)->remaining_amount);
        $this->getJson('/api/inventory/corrections/returns/'.$return['id'])->assertOk()->assertJsonCount(1, 'data.items');
        $this->command('corrections/refunds', $this->refundData($purchase, '100.10'))->assertCreated();
        $this->assertSame('0.00', $this->balance($purchase)->remaining_amount);
        $new = $this->purchaseData();
        $new['purchase_no'] = 'COR-P2';
        $new['purchase_on'] = '2026-01-05';
        $new['items'] = [$new['items'][0]];
        $this->command('purchases', $new)->assertCreated();
        $this->assertSame('100.10', $this->balance($purchase)->returned_amount);
        $this->assertSame(4, DB::table('stock_events')->where('carpet_id', $this->carpets[0])->count());
    }

    public function test_missing_found_and_relocation_append_history_without_creating_money_or_new_acquisitions(): void
    {
        $purchase = $this->purchase();
        $this->receive();
        $data = ['adjustment_no' => 'COR-A1', 'branch_id' => $this->branch, 'adjustment_type' => 'missing', 'adjusted_on' => '2026-01-04', 'reason' => 'Physical stock count', 'items' => [$this->items(2)[0]]];
        $this->command('corrections/adjustments', $data)->assertCreated();
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertOk()->assertJsonPath('data.stock_status', 'missing');
        $data['adjustment_no'] = 'COR-A2';
        $data['adjustment_type'] = 'found';
        $data['location_id'] = $this->secondLocation;
        $data['items'][0]['expected_sequence'] = 2;
        $this->command('corrections/adjustments', $data)->assertConflict();
        $data['items'][0]['expected_sequence'] = 3;
        $this->command('corrections/adjustments', $data)->assertCreated();
        $data['adjustment_no'] = 'COR-A3';
        $data['adjustment_type'] = 'relocate';
        $data['location_id'] = $this->location;
        $data['items'][0]['expected_sequence'] = 4;
        $this->command('corrections/adjustments', $data)->assertCreated();
        $this->assertSame('300.30', $this->balance($purchase)->remaining_amount);
        $this->assertSame(1, DB::table('account_transactions')->where('account_id', $this->account)->count());
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertOk()->assertJsonCount(5, 'data.events')->assertJsonPath('data.location_id', $this->location);
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'stock_adjustments']);
    }

    public function test_corrections_require_exact_permissions_and_branch_scope(): void
    {
        $purchase = $this->purchase();
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'Branch Manager')->firstOrFail());
        Sanctum::actingAs($user);
        $this->getJson('/api/inventory/corrections/drafts')->assertOk()->assertJsonPath('total', 0);
        $this->command('corrections/drafts', ['purchase_no' => 'SCOPED-D', 'branch_id' => $this->branch, 'items' => []])->assertNotFound();
        $this->command('purchases/'.$purchase.'/void', ['voided_on' => '2026-01-04', 'reason' => 'Attempt outside branch', 'items' => $this->items(1)])->assertNotFound();
        $sales = User::factory()->create();
        $sales->roles()->attach(Role::where('name', 'Sales User')->firstOrFail());
        Sanctum::actingAs($sales);
        foreach (['drafts', 'returns', 'refunds', 'adjustments'] as $resource) {
            $this->command('corrections/'.$resource, [])->assertForbidden();
        }
        $this->command('purchases/'.$purchase.'/void', [])->assertForbidden();
    }

    public function test_invalid_inputs_and_multiline_correction_failure_leave_no_partial_facts(): void
    {
        foreach (['drafts', 'returns', 'refunds', 'adjustments'] as $resource) {
            $this->command('corrections/'.$resource, [])->assertUnprocessable();
        }
        $this->purchase();
        $this->receive();
        $items = $this->items(2);
        $items[1]['expected_sequence'] = 1;
        $beforeAudit = DB::table('audit_logs')->count();
        $this->command('corrections/adjustments', ['adjustment_no' => 'COR-FAIL', 'branch_id' => $this->branch, 'adjustment_type' => 'missing', 'adjusted_on' => '2026-01-04', 'reason' => 'Counting both carpets', 'items' => $items])->assertConflict();
        $this->assertDatabaseMissing('stock_adjustments', ['adjustment_no' => 'COR-FAIL']);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        $this->assertSame(4, DB::table('stock_events')->count());
    }

    public function test_unreceived_items_can_be_cancelled_individually_after_another_item_was_received(): void
    {
        $purchase = $this->purchase();
        $this->command('receipts', ['receipt_no' => 'COR-PARTIAL', 'location_id' => $this->location, 'received_on' => '2026-01-03', 'items' => [$this->items(1)[0]]])->assertCreated();
        $this->command('corrections/returns', ['return_no' => 'COR-UNRECEIVED', 'weaver_purchase_id' => $purchase, 'from_location_id' => null,
            'returned_on' => '2026-01-04', 'reason' => 'Cancel the unreceived item', 'items' => [$this->items(1)[1]]])->assertCreated();
        $this->assertSame('100.10', $this->balance($purchase)->remaining_amount);
        $this->getJson('/api/inventory/carpets/'.$this->carpets[1])->assertOk()->assertJsonPath('data.stock_status', 'returned');
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertOk()->assertJsonPath('data.stock_status', 'in_stock');
    }

    public function test_foreign_currency_refund_snapshot_rounds_exactly_and_never_exceeds_credit(): void
    {
        $purchase = $this->purchase();
        $this->pay($purchase);
        $this->command('purchases/'.$purchase.'/void', ['voided_on' => '2026-01-04', 'reason' => 'Cancel purchased carpets', 'items' => $this->items(1)])->assertCreated();
        $foreign = DB::table('currencies')->where('id', '<>', $this->currency)->value('id');
        $account = DB::table('accounts')->insertGetId(['code' => 'COR-FX', 'name' => 'Foreign refund cash', 'branch_id' => $this->branch, 'currency_id' => $foreign]);
        $data = $this->refundData($purchase, '7000');
        $data['account_id'] = $account;
        $data['rate_to_base'] = $data['purchase_units_per_refund_unit'] = '0.01428571';
        $result = $this->command('corrections/refunds', $data)->assertCreated()->json('data');
        $this->assertSame('100.00', $result['amount_in_purchase_currency']);
        $this->assertSame('-0.05', $this->balance($purchase)->remaining_amount);
        $this->assertSame('7000.00', DB::table('v_account_balances')->where('account_id', $account)->value('balance'));
        $data['refund_no'] = 'COR-FX-OVER';
        $data['amount'] = '4';
        $this->command('corrections/refunds', $data)->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_refund_reverse_requires_its_own_action_permission_and_available_cash(): void
    {
        $purchase = $this->purchase();
        $this->pay($purchase);
        $this->command('purchases/'.$purchase.'/void', ['voided_on' => '2026-01-04', 'reason' => 'Void and refund', 'items' => $this->items(1)])->assertCreated();
        $refund = $this->command('corrections/refunds', $this->refundData($purchase, '100.05'))->assertCreated()->json('data');
        $role = DB::table('roles')->insertGetId(['name' => 'Corrections generic reverser']);
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', 'ledger.reverse')->value('id')]);
        $user = User::factory()->create();
        $user->roles()->attach($role);
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->branch]);
        DB::table('user_accounts')->insertOrIgnore(['user_id' => $user->id, 'account_id' => $this->account]);
        Sanctum::actingAs($user);
        $this->postJson('/api/foundation/ledger/'.$refund['account_transaction_id'].'/reverse', ['reason' => 'Bypass refund permission'], ['Idempotency-Key' => 'refund-bypass-test'])->assertForbidden();
        $this->actingAsAdmin();
        $this->postJson('/api/foundation/ledger', ['account_id' => $this->account, 'entry_type' => 'expense', 'amount' => '5000', 'rate_to_base' => '1', 'transaction_date' => '2026-01-05', 'description' => 'Spend available cash'], ['Idempotency-Key' => 'spend-refund-cash'])->assertCreated();
        $this->postJson('/api/foundation/ledger/'.$refund['account_transaction_id'].'/reverse', ['reason' => 'Insufficient cash reversal'], ['Idempotency-Key' => 'refund-no-cash-test'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame('0.00', $this->balance($purchase)->remaining_amount);
    }

    public function test_saved_draft_does_not_reserve_stock_and_stale_draft_post_rolls_back(): void
    {
        $draftData = $this->purchaseData();
        $draftData['purchase_no'] = 'COR-DRAFT';
        $draft = $this->command('corrections/drafts', $draftData)->assertCreated()->json('data');
        $this->purchase();
        $this->command('corrections/drafts/'.$draft['id'].'/post', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseHas('purchase_drafts', ['id' => $draft['id'], 'state' => 'draft', 'version' => 1]);
        $this->assertDatabaseMissing('weaver_purchases', ['purchase_no' => 'COR-DRAFT']);
        $this->assertSame(2, DB::table('stock_events')->count());
    }
}
