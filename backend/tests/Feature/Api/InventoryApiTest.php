<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

class InventoryApiTest extends HistoricalApiTestCase
{
    private int $branch;

    private int $other;

    private int $location;

    private int $destination;

    private int $currency;

    private int $weaver;

    private int $category;

    private int $account;

    private array $carpets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->branch = DB::table('branches')->insertGetId(['code' => 'INV-A', 'name' => 'Inventory A']);
        $this->other = DB::table('branches')->insertGetId(['code' => 'INV-B', 'name' => 'Inventory B']);
        $this->location = DB::table('stock_locations')->insertGetId(['code' => 'INV-L1', 'name' => 'Shelf A', 'branch_id' => $this->branch, 'location_type' => 'shelf']);
        $this->destination = DB::table('stock_locations')->insertGetId(['code' => 'INV-L2', 'name' => 'Shelf B', 'branch_id' => $this->other, 'location_type' => 'shelf']);
        $this->currency = DB::table('company_settings')->value('base_currency_id');
        $this->category = DB::table('carpet_categories')->insertGetId(['name' => 'Inventory category']);
        $manager = DB::table('employees')->insertGetId(['name' => 'Inventory manager', 'branch_id' => $this->branch]);
        $this->weaver = DB::table('weavers')->insertGetId(['name' => 'Inventory weaver', 'manager_employee_id' => $manager, 'registration_date' => '2026-01-01']);
        $this->account = DB::table('accounts')->insertGetId(['code' => 'INV-CASH', 'name' => 'Inventory cash', 'branch_id' => $this->branch, 'currency_id' => $this->currency]);
        $this->postJson('/api/foundation/ledger', ['account_id' => $this->account, 'entry_type' => 'opening', 'amount' => '5000.00', 'rate_to_base' => '1', 'transaction_date' => '2026-01-01', 'description' => 'Inventory testing'], ['Idempotency-Key' => 'inventory-opening'])->assertCreated();
        $this->carpets = [];
        foreach (['INV-001', 'INV-002'] as $code) {
            $this->carpets[] = $this->postCommand('carpets', $this->carpetData($code))->assertCreated()->json('data.id');
        }
    }

    private function carpetData(string $code = 'INV-003'): array
    {
        return ['carpet_code' => $code, 'name' => 'Hand woven carpet', 'registration_branch_id' => $this->branch,
            'category_id' => $this->category, 'length_cm' => '200', 'width_cm' => '150', 'material' => 'Wool'];
    }

    private function postCommand(string $resource, array $data, ?string $key = null)
    {
        return $this->postJson('/api/inventory/'.$resource, $data, ['Idempotency-Key' => $key ?? 'test-'.bin2hex(random_bytes(12))]);
    }

    private function purchaseData(): array
    {
        return ['purchase_no' => 'INV-P1', 'weaver_id' => $this->weaver, 'branch_id' => $this->branch,
            'purchase_on' => '2026-01-02', 'currency_id' => $this->currency, 'rate_to_base' => '1',
            'items' => [['carpet_id' => $this->carpets[0], 'unit_price' => '100.10'], ['carpet_id' => $this->carpets[1], 'unit_price' => '200.20']]];
    }

    private function purchase(): int
    {
        return $this->postCommand('purchases', $this->purchaseData())->assertCreated()->json('data.id');
    }

    private function receive(): void
    {
        $this->postCommand('receipts', ['receipt_no' => 'INV-R1', 'location_id' => $this->location,
            'received_on' => '2026-01-03', 'items' => array_map(fn ($id) => ['carpet_id' => $id, 'expected_sequence' => 1], $this->carpets)])->assertCreated();
    }

    private function paymentData(int $purchase): array
    {
        return ['payment_no' => 'INV-PAY1', 'weaver_id' => $this->weaver, 'branch_id' => $this->branch,
            'account_id' => $this->account, 'payment_type' => 'purchase', 'amount' => '100.05', 'rate_to_base' => '1',
            'payment_on' => '2026-01-04', 'reason' => 'Partial purchase settlement',
            'allocations' => [['weaver_purchase_id' => $purchase, 'amount_in_payment_currency' => '100.05', 'purchase_units_per_payment_unit' => '1']]];
    }

    public function test_multi_carpet_purchase_receipt_transfer_and_history_are_exact_and_immutable(): void
    {
        $purchase = $this->purchase();
        $this->getJson('/api/inventory/purchases')->assertOk()->assertJsonPath('data.0.total_amount', '300.30')->assertJsonPath('data.0.remaining_amount', '300.30');
        $this->getJson('/api/inventory/purchases/'.$purchase)->assertOk()->assertJsonCount(2, 'data.items');
        $this->receive();
        $transfer = $this->postCommand('transfers', ['transfer_no' => 'INV-T1', 'from_location_id' => $this->location,
            'to_location_id' => $this->destination, 'transferred_on' => '2026-01-04',
            'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 2]]])->assertCreated()->json('data.id');
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertOk()->assertJsonPath('data.sequence_no', '3')
            ->assertJsonPath('data.location_id', $this->destination)->assertJsonCount(3, 'data.events');
        $this->getJson('/api/inventory/receipts')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/inventory/transfers/'.$transfer)->assertOk()->assertJsonCount(1, 'data.items');
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'weaver_purchases', 'record_id' => $purchase]);
        try {
            DB::table('stock_events')->where('carpet_id', $this->carpets[0])->update(['sequence_no' => 99]);
            $this->fail('Stock events must be immutable.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('immutable', $error->getMessage());
        }
    }

    public function test_retry_and_changed_payload_keys_never_duplicate_facts(): void
    {
        $data = $this->purchaseData();
        $id = $this->postCommand('purchases', $data, 'purchase-retry')->assertCreated()->json('data.id');
        $this->postCommand('purchases', $data, 'purchase-retry')->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $id);
        $data['notes'] = 'Different request';
        $this->postCommand('purchases', $data, 'purchase-retry')->assertConflict();
        $this->assertSame(2, DB::table('stock_events')->whereIn('carpet_id', $this->carpets)->count());
        $data['purchase_no'] = 'INV-P2';
        $this->postCommand('purchases', $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseMissing('weaver_purchases', ['purchase_no' => 'INV-P2']);
    }

    public function test_stale_transfer_rejected_and_multiline_failure_rolls_everything_back(): void
    {
        $this->purchase();
        $this->receive();
        $data = ['transfer_no' => 'INV-T1', 'from_location_id' => $this->location, 'to_location_id' => $this->destination,
            'transferred_on' => '2026-01-04', 'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 2], ['carpet_id' => $this->carpets[1], 'expected_sequence' => 1]]];
        $this->postCommand('transfers', $data)->assertConflict();
        $this->assertDatabaseMissing('stock_transfers', ['transfer_no' => 'INV-T1']);
        $this->assertSame(0, DB::table('stock_events')->where('event_type', 'transfer')->count());
        $data['items'] = [$data['items'][0]];
        $this->postCommand('transfers', $data, 'transfer-retry')->assertCreated();
        $this->postCommand('transfers', $data, 'transfer-retry')->assertOk();
        $data['transfer_no'] = 'INV-T2';
        $this->postCommand('transfers', $data)->assertConflict();
    }

    public function test_partial_payment_bonus_and_reversal_reconcile_without_changing_cost(): void
    {
        $purchase = $this->purchase();
        $data = $this->paymentData($purchase);
        $payment = $this->postCommand('payments', $data, 'payment-retry')->assertCreated()->json('data');
        $this->postCommand('payments', $data, 'payment-retry')->assertOk()->assertJsonPath('replayed', true);
        $this->getJson('/api/inventory/purchases')->assertOk()->assertJsonPath('data.0.paid_amount', '100.05')->assertJsonPath('data.0.remaining_amount', '200.25');
        $this->getJson('/api/foundation/ledger?account_id='.$this->account)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('balance', '4899.95');
        $accounts = $this->getJson('/api/foundation/accounts')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertSame('4899.95', collect($accounts)->firstWhere('id', $this->account)['balance']);
        $bonus = array_merge($data, ['payment_no' => 'INV-BONUS', 'payment_type' => 'additional', 'amount' => '10.00',
            'expense_category_id' => DB::table('expense_categories')->value('id'), 'allocations' => [], 'reason' => 'Quality bonus']);
        $this->postCommand('payments', $bonus)->assertCreated();
        $this->assertSame('4889.95', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
        $this->assertSame('200.25', DB::table('v_weaver_purchase_balances')->where('purchase_id', $purchase)->value('remaining_amount'));
        $this->postJson('/api/foundation/ledger/'.$payment['account_transaction_id'].'/reverse', ['reason' => 'Payment correction'], ['Idempotency-Key' => 'reverse-weaver-payment'])->assertCreated();
        $this->getJson('/api/inventory/purchases')->assertOk()->assertJsonPath('data.0.total_amount', '300.30')->assertJsonPath('data.0.paid_amount', '0.00');
        $this->assertSame('4990.00', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
        $this->getJson('/api/inventory/payments/'.$payment['id'])->assertOk()->assertJsonCount(1, 'data.allocations');
    }

    public function test_overpayment_insufficient_cash_invalid_bonus_and_allocation_mismatch_are_atomic(): void
    {
        $purchase = $this->purchase();
        $data = $this->paymentData($purchase);
        $data['amount'] = $data['allocations'][0]['amount_in_payment_currency'] = '300.31';
        $this->postCommand('payments', $data)->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $data = $this->paymentData($purchase);
        $data['amount'] = '99';
        $this->postCommand('payments', $data)->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $data = array_merge($data, ['payment_type' => 'additional', 'allocations' => [], 'amount' => '6000', 'expense_category_id' => DB::table('expense_categories')->value('id')]);
        $this->postCommand('payments', $data)->assertUnprocessable()->assertJsonValidationErrors('amount');
        unset($data['expense_category_id']);
        $this->postCommand('payments', $data)->assertUnprocessable()->assertJsonValidationErrors('expense_category_id');
        $this->assertDatabaseMissing('weaver_payments', ['payment_no' => 'INV-PAY1']);
        $this->assertSame('5000.00', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
    }

    public function test_cross_currency_payment_preserves_explicit_conversion_snapshot(): void
    {
        $afn = DB::table('currencies')->where('id', '<>', $this->currency)->first();
        $purchase = $this->purchaseData();
        $purchase['currency_id'] = $afn->id;
        $purchase['rate_to_base'] = '0.01428571';
        $id = $this->postCommand('purchases', $purchase)->assertCreated()->json('data.id');
        $data = $this->paymentData($id);
        $data['amount'] = $data['allocations'][0]['amount_in_payment_currency'] = '1.01';
        $data['allocations'][0]['purchase_units_per_payment_unit'] = '70.125';
        $this->postCommand('payments', $data)->assertCreated();
        $this->assertDatabaseHas('weaver_payment_allocations', ['weaver_purchase_id' => $id,
            'amount_in_purchase_currency' => '70.83', 'purchase_units_per_payment_unit' => '70.12500000']);
        $this->assertSame('229.47', DB::table('v_weaver_purchase_balances')->where('purchase_id', $id)->value('remaining_amount'));
        $this->assertSame('1.01', InventoryService::converted('1.005', '1', 2));
    }

    public function test_server_validation_rejects_bad_dates_dimensions_duplicates_and_imprecise_money(): void
    {
        foreach (['carpets', 'purchases', 'receipts', 'transfers', 'payments'] as $resource) {
            $this->postCommand($resource, [])->assertUnprocessable();
        }
        $data = $this->carpetData();
        $data['width_cm'] = null;
        $this->postCommand('carpets', $data)->assertUnprocessable()->assertJsonValidationErrors('width_cm');
        $data = $this->purchaseData();
        $data['purchase_on'] = '2099-01-01';
        $data['items'][1]['carpet_id'] = $data['items'][0]['carpet_id'];
        $data['items'][0]['unit_price'] = '1.001';
        $this->postCommand('purchases', $data)->assertUnprocessable()->assertJsonValidationErrors(['purchase_on', 'items.0.carpet_id', 'items.0.unit_price']);
        $data = $this->purchaseData();
        $data['rate_to_base'] = '2';
        $this->postCommand('purchases', $data)->assertUnprocessable()->assertJsonValidationErrors('rate_to_base');
        $this->getJson('/api/inventory/carpets?per_page=1000')->assertUnprocessable();
        $this->getJson('/api/inventory/not-a-table')->assertNotFound();
    }

    public function test_branch_scope_actions_and_custody_after_transfer_are_enforced(): void
    {
        $this->purchase();
        $this->receive();
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'Stock User')->firstOrFail());
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->branch]);
        DB::table('user_accounts')->insertOrIgnore(['user_id' => $user->id, 'account_id' => $this->account]);
        Sanctum::actingAs($user);
        $this->getJson('/api/inventory/carpets')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/inventory/purchases')->assertForbidden();
        $this->postCommand('payments', [])->assertForbidden();
        $this->postCommand('transfers', ['transfer_no' => 'INV-T1', 'from_location_id' => $this->location,
            'to_location_id' => $this->destination, 'transferred_on' => '2026-01-04', 'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 2]]])->assertNotFound();
        $this->actingAsAdmin();
        $this->postCommand('transfers', ['transfer_no' => 'INV-T1', 'from_location_id' => $this->location,
            'to_location_id' => $this->destination, 'transferred_on' => '2026-01-04', 'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 2]]])->assertCreated();
        Sanctum::actingAs($user);
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertNotFound();
        $this->getJson('/api/inventory/carpets')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_optimistic_editing_and_posted_carpet_identity_are_protected(): void
    {
        $id = $this->carpets[0];
        $data = $this->carpetData('INV-001') + ['version' => 1];
        $this->putJson('/api/inventory/carpets/'.$id, $data, ['Idempotency-Key' => 'edit-carpet-1'])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/inventory/carpets/'.$id, $data, ['Idempotency-Key' => 'edit-carpet-2'])->assertConflict();
        $this->purchase();
        $data['version'] = 2;
        $data['carpet_code'] = 'CHANGED';
        $this->putJson('/api/inventory/carpets/'.$id, $data, ['Idempotency-Key' => 'edit-carpet-3'])->assertUnprocessable()->assertJsonValidationErrors('carpet_code');
    }

    public function test_private_image_upload_read_delete_and_scope_validation(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0ioAAAAASUVORK5CYII=');
        $file = UploadedFile::fake()->createWithContent('carpet.png', $png);
        $url = '/api/inventory/carpets/'.$this->carpets[0].'/images';
        $id = $this->post($url, ['image' => $file, 'alt_text' => 'Wool carpet'], ['Accept' => 'application/json', 'Idempotency-Key' => 'image-upload-test'])->assertCreated()->assertJsonMissingPath('data.storage_path')->json('data.id');
        $this->get($url.'/'.$id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post($url, ['image' => UploadedFile::fake()->createWithContent('x.svg', '<svg/>')], ['Accept' => 'application/json', 'Idempotency-Key' => 'image-upload-svg'])->assertUnprocessable();
        $this->deleteJson($url.'/'.$id, [], ['Idempotency-Key' => 'image-delete-test'])->assertOk();
        $this->get($url.'/'.$id)->assertNotFound();
    }

    public function test_used_locations_and_purchase_currency_cannot_rewrite_historical_ownership_or_precision(): void
    {
        $this->purchase();
        $this->receive();
        $location = ['code' => 'INV-L1', 'name' => 'Shelf A', 'branch_id' => $this->other,
            'washing_facility_id' => null, 'location_type' => 'shelf', 'status' => 'active'];
        $this->putJson('/api/foundation/locations/'.$this->location, $location)->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $location['branch_id'] = $this->branch;
        $location['status'] = 'inactive';
        $this->putJson('/api/foundation/locations/'.$this->location, $location)->assertUnprocessable()->assertJsonValidationErrors('status');
        $currency = DB::table('currencies')->find($this->currency);
        $this->putJson('/api/foundation/currencies/'.$this->currency, ['code' => $currency->code, 'name' => $currency->name,
            'symbol' => $currency->symbol, 'decimal_places' => 0, 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_payment_action_permission_also_applies_to_generic_ledger_reversal(): void
    {
        $purchase = $this->purchase();
        $payment = $this->postCommand('payments', $this->paymentData($purchase))->assertCreated()->json('data');
        $roleId = DB::table('roles')->insertGetId(['name' => 'Generic ledger reverser']);
        DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => DB::table('permissions')->where('code', 'ledger.reverse')->value('id')]);
        $user = User::factory()->create();
        $user->roles()->attach($roleId);
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->branch]);
        DB::table('user_accounts')->insertOrIgnore(['user_id' => $user->id, 'account_id' => $this->account]);
        Sanctum::actingAs($user);
        $this->postJson('/api/foundation/ledger/'.$payment['account_transaction_id'].'/reverse', ['reason' => 'Attempt bypass'],
            ['Idempotency-Key' => 'reverse-bypass-test'])->assertForbidden();
        $this->assertDatabaseMissing('account_transactions', ['reversal_of_id' => $payment['account_transaction_id']]);
    }

    public function test_wrong_weaver_cross_branch_dates_and_duplicate_payment_allocations_fail(): void
    {
        $purchase = $this->purchase();
        $data = $this->paymentData($purchase);
        $data['allocations'][] = $data['allocations'][0];
        $this->postCommand('payments', $data)->assertUnprocessable()->assertJsonValidationErrors('allocations.0.weaver_purchase_id');
        $data = $this->paymentData($purchase);
        $data['weaver_id'] = DB::table('weavers')->insertGetId(['name' => 'Other weaver', 'registration_date' => '2026-01-01']);
        $this->postCommand('payments', $data)->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $data = $this->paymentData($purchase);
        $data['branch_id'] = $this->other;
        $this->postCommand('payments', $data)->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $data = $this->paymentData($purchase);
        $data['payment_on'] = '2026-01-01';
        $this->postCommand('payments', $data)->assertUnprocessable()->assertJsonValidationErrors('payment_on');
        $this->postCommand('receipts', ['receipt_no' => 'INV-R1', 'location_id' => $this->location,
            'received_on' => '2026-01-01', 'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseMissing('stock_receipts', ['receipt_no' => 'INV-R1']);
    }

    public function test_purchase_snapshot_fixes_currency_precision_even_without_an_account_in_that_currency(): void
    {
        $currency = DB::table('currencies')->where('id', '<>', $this->currency)->first();
        $this->assertFalse(DB::table('accounts')->where('currency_id', $currency->id)->exists());
        $data = $this->purchaseData();
        $data['currency_id'] = $currency->id;
        $data['rate_to_base'] = '0.01428571';
        $this->postCommand('purchases', $data)->assertCreated();
        $this->putJson('/api/foundation/currencies/'.$currency->id, ['code' => $currency->code, 'name' => $currency->name,
            'symbol' => $currency->symbol, 'decimal_places' => 0, 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_new_custodian_can_edit_metadata_without_access_to_the_original_registration_branch(): void
    {
        $this->purchase();
        $this->receive();
        $this->postCommand('transfers', ['transfer_no' => 'INV-T1', 'from_location_id' => $this->location,
            'to_location_id' => $this->destination, 'transferred_on' => '2026-01-04',
            'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 2]]])->assertCreated();
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'Stock User')->firstOrFail());
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->other]);
        Sanctum::actingAs($user);
        $data = $this->carpetData('INV-001') + ['version' => 1];
        $data['name'] = 'Corrected carpet description';
        $this->putJson('/api/inventory/carpets/'.$this->carpets[0], $data, ['Idempotency-Key' => 'custodian-edit-test'])
            ->assertOk()->assertJsonPath('data.name', 'Corrected carpet description');
        $this->getJson('/api/inventory/lookups')->assertOk()->assertJsonCount(1, 'data.branches');
        $this->getJson('/api/inventory/carpets/'.$this->carpets[1])->assertNotFound();
    }

    public function test_database_rejects_an_incomplete_dimension_pair_independently_of_http_validation(): void
    {
        $this->expectException(QueryException::class);
        DB::table('carpets')->where('id', $this->carpets[0])->update(['width_cm' => null]);
    }
}
