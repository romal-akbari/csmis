<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class WashingApiTest extends HistoricalApiTestCase
{
    private User $admin;

    private int $branch;

    private int $other;

    private int $location;

    private int $destination;

    private int $facility;

    private int $facilityLocation;

    private int $currency;

    private int $account;

    private array $carpets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->actingAsAdmin();
        $this->branch = DB::table('branches')->insertGetId(['code' => 'WASH-A', 'name' => 'Washing branch']);
        $this->other = DB::table('branches')->insertGetId(['code' => 'WASH-B', 'name' => 'Other washing branch']);
        $this->location = DB::table('stock_locations')->insertGetId(['code' => 'WASH-L', 'name' => 'Washing return shelf', 'branch_id' => $this->branch, 'location_type' => 'shelf']);
        $this->destination = DB::table('stock_locations')->insertGetId(['code' => 'WASH-D', 'name' => 'Destination shelf', 'branch_id' => $this->other, 'location_type' => 'shelf']);
        $this->facility = DB::table('washing_facilities')->insertGetId(['name' => 'Washing test facility']);
        $this->facilityLocation = DB::table('stock_locations')->insertGetId(['code' => 'WASH-F', 'name' => 'Facility custody', 'washing_facility_id' => $this->facility, 'location_type' => 'washing']);
        $this->currency = DB::table('company_settings')->value('base_currency_id');
        $category = DB::table('carpet_categories')->insertGetId(['name' => 'Washing test category']);
        $employee = DB::table('employees')->insertGetId(['name' => 'Washing test manager', 'branch_id' => $this->branch]);
        $weaver = DB::table('weavers')->insertGetId(['name' => 'Washing test weaver', 'manager_employee_id' => $employee, 'registration_date' => '2026-01-01']);
        $this->account = DB::table('accounts')->insertGetId(['code' => 'WASH-CASH', 'name' => 'Washing cash', 'branch_id' => $this->branch, 'currency_id' => $this->currency]);
        $this->postJson('/api/foundation/ledger', ['account_id' => $this->account, 'entry_type' => 'opening', 'amount' => '1000', 'rate_to_base' => '1', 'transaction_date' => '2026-01-01', 'description' => 'Washing test opening'], ['Idempotency-Key' => 'washing-opening'])->assertCreated();
        $this->carpets = [];
        foreach (['WASH-001', 'WASH-002'] as $code) {
            $this->carpets[] = $this->postJson('/api/inventory/carpets', ['carpet_code' => $code, 'name' => 'Washing test carpet', 'registration_branch_id' => $this->branch, 'category_id' => $category], $this->key())->assertCreated()->json('data.id');
        }
        $this->postJson('/api/inventory/purchases', ['purchase_no' => 'WASH-P1', 'weaver_id' => $weaver, 'branch_id' => $this->branch,
            'purchase_on' => '2026-01-02', 'currency_id' => $this->currency, 'rate_to_base' => '1',
            'items' => array_map(fn ($id) => ['carpet_id' => $id, 'unit_price' => '100'], $this->carpets)], $this->key())->assertCreated();
    }

    private function key(?string $value = null): array
    {
        return ['Idempotency-Key' => $value ?? 'washing-'.bin2hex(random_bytes(8))];
    }

    private function command(string $path, array $data, ?string $key = null)
    {
        return $this->postJson('/api/washing/'.$path, $data, $this->key($key));
    }

    private function dispatchData(): array
    {
        return ['order_no' => 'WASH-O1', 'branch_id' => $this->branch, 'facility_id' => $this->facility,
            'facility_location_id' => $this->facilityLocation, 'sent_on' => '2026-01-03',
            'items' => array_map(fn ($id) => ['carpet_id' => $id, 'expected_sequence' => 1], $this->carpets)];
    }

    private function dispatch(): array
    {
        $id = $this->command('dispatch', $this->dispatchData())->assertCreated()->json('data.id');

        return [$id, DB::table('washing_order_items')->where('washing_order_id', $id)->orderBy('id')->pluck('id')->all()];
    }

    private function billData(array $visits): array
    {
        return ['expense_no' => 'WASH-E1', 'invoice_no' => 'FAC-INV-1', 'branch_id' => $this->branch,
            'facility_id' => $this->facility, 'currency_id' => $this->currency, 'rate_to_base' => '1', 'billed_on' => '2026-01-04', 'amount' => '30.30',
            'allocations' => [['washing_order_item_id' => $visits[0], 'amount' => '10.10'], ['washing_order_item_id' => $visits[1], 'amount' => '20.20']]];
    }

    private function paymentData(): array
    {
        return ['payment_no' => 'WASH-PAY1', 'account_id' => $this->account, 'amount' => '10.10', 'rate_to_base' => '1',
            'bill_units_per_payment_unit' => '1', 'payment_on' => '2026-01-05', 'reason' => 'Washing partial settlement'];
    }

    public function test_washing_references_are_automatic_while_the_facility_invoice_number_is_still_required(): void
    {
        $dispatch = $this->dispatchData(); unset($dispatch['order_no']);
        $id = $this->command('dispatch', $dispatch, 'auto-washing-dispatch')->assertCreated()->json('data.id');
        $this->command('dispatch', $dispatch, 'auto-washing-dispatch')->assertOk()->assertJsonPath('data.id', $id);
        $this->assertStringStartsWith('MWASH-', DB::table('washing_orders')->where('id', $id)->value('order_no'));
        $visits = DB::table('washing_order_items')->where('washing_order_id', $id)->orderBy('id')->pluck('id')->all();
        $data = $this->billData($visits); unset($data['expense_no'], $data['invoice_no']);
        $this->command('bills', $data)->assertUnprocessable()->assertJsonValidationErrors('invoice_no');
        $data['invoice_no'] = 'FAC-AUTO-INVOICE';
        $bill = $this->command('bills', $data)->assertCreated()->json('data.id');
        $this->assertStringStartsWith('WBILL-', DB::table('washing_bills')->where('id', $bill)->value('expense_no'));
        $payment = $this->paymentData(); unset($payment['payment_no']);
        $id = $this->command('bills/'.$bill.'/payments', $payment, 'auto-washing-payment')->assertCreated()->json('data.id');
        $this->command('bills/'.$bill.'/payments', $payment, 'auto-washing-payment')->assertOk()->assertJsonPath('data.id', $id);
        $this->assertStringStartsWith('WMPAY-', DB::table('washing_payments')->where('id', $id)->value('payment_no'));
        $this->assertSame('989.90', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
    }

    private function roleUser(string $role, ?int $branch = null, ?int $facility = null): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());
        if ($branch) {
            DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $branch]);
        }
        if ($facility) {
            DB::table('user_washing_facilities')->insert(['user_id' => $user->id, 'washing_facility_id' => $facility]);
        }

        return $user;
    }

    public function test_weaver_to_facility_partial_completion_return_and_scoped_stock_reconcile(): void
    {
        [$id, $visits] = $this->dispatch();
        $this->getJson('/api/washing/orders')->assertOk()->assertJsonPath('data.0.state', 'sent')->assertJsonPath('data.0.item_count', 2);
        $this->getJson('/api/washing/stock')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.stock_status', 'at_washing');
        $this->command('orders/'.$id.'/complete', ['completed_on' => '2026-01-04', 'items' => [['washing_order_item_id' => $visits[0], 'expected_sequence' => 2]]])->assertCreated();
        $this->getJson('/api/washing/orders/'.$id)->assertOk()->assertJsonPath('data.state', 'partially_completed')->assertJsonPath('data.items.0.state', 'completed');
        $this->command('orders/'.$id.'/return', ['returned_on' => '2026-01-05', 'location_id' => $this->location, 'items' => [['washing_order_item_id' => $visits[0], 'expected_sequence' => 3]]])->assertCreated();
        $this->getJson('/api/washing/orders?state=partially_returned')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/washing/stock')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertOk()->assertJsonPath('data.stock_status', 'in_stock')->assertJsonPath('data.location_name', 'Washing return shelf')
            ->assertJsonCount(4, 'data.events')->assertJsonPath('data.washing_visits.0.state', 'returned');
        $this->getJson('/api/washing/stock?format=csv')->assertOk()->assertDownload('washing-facility-stock.csv');
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'washing_orders', 'record_id' => $id]);
    }

    public function test_stock_first_transfer_then_washing_preserves_actual_sending_branch_and_acquisition(): void
    {
        $this->postJson('/api/inventory/receipts', ['receipt_no' => 'WASH-R1', 'location_id' => $this->location, 'received_on' => '2026-01-03',
            'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 1]]], $this->key())->assertCreated();
        $this->postJson('/api/inventory/transfers', ['transfer_no' => 'WASH-T1', 'from_location_id' => $this->location, 'to_location_id' => $this->destination,
            'transferred_on' => '2026-01-04', 'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 2]]], $this->key())->assertCreated();
        $data = $this->dispatchData();
        $data['branch_id'] = $this->other;
        $data['sent_on'] = '2026-01-05';
        $data['items'] = [['carpet_id' => $this->carpets[0], 'expected_sequence' => 3]];
        $this->command('dispatch', $data)->assertCreated();
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertOk()->assertJsonPath('data.custody_branch_id', $this->other);
        Sanctum::actingAs($this->roleUser('Stock User', $this->branch));
        $this->getJson('/api/washing/stock')->assertOk()->assertJsonPath('total', 0);
        Sanctum::actingAs($this->roleUser('Stock User', $this->other));
        $this->getJson('/api/washing/stock')->assertOk()->assertJsonPath('total', 1);
        $this->assertDatabaseHas('washing_order_items', ['carpet_id' => $this->carpets[0], 'source_location_id' => $this->destination]);
    }

    public function test_retries_stale_sequences_duplicate_visits_and_multiline_failure_are_atomic(): void
    {
        $data = $this->dispatchData();
        $id = $this->command('dispatch', $data, 'washing-dispatch-retry')->assertCreated()->json('data.id');
        $this->command('dispatch', $data, 'washing-dispatch-retry')->assertOk()->assertJsonPath('replayed', true);
        $data['notes'] = 'Changed';
        $this->command('dispatch', $data, 'washing-dispatch-retry')->assertConflict();
        $data['order_no'] = 'WASH-O2';
        $this->command('dispatch', $data)->assertConflict();
        $data['items'] = array_map(fn ($id) => ['carpet_id' => $id, 'expected_sequence' => 2], $this->carpets);
        $this->command('dispatch', $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $visits = DB::table('washing_order_items')->where('washing_order_id', $id)->pluck('id')->all();
        $this->command('orders/'.$id.'/complete', ['completed_on' => '2026-01-04', 'items' => [
            ['washing_order_item_id' => $visits[0], 'expected_sequence' => 2], ['washing_order_item_id' => $visits[1], 'expected_sequence' => 1]]])->assertConflict();
        $this->assertSame(0, DB::table('stock_events')->where('event_type', 'washing_complete')->count());
        $this->assertSame(1, DB::table('washing_orders')->count());
    }

    public function test_dates_wrong_locations_return_before_completion_and_nested_validation_rejected(): void
    {
        $data = $this->dispatchData();
        $data['facility_location_id'] = $this->location;
        $this->command('dispatch', $data)->assertUnprocessable()->assertJsonValidationErrors('facility_location_id');
        $data = $this->dispatchData();
        $data['sent_on'] = '2026-01-01';
        $this->command('dispatch', $data)->assertUnprocessable()->assertJsonValidationErrors('sent_on');
        $data['sent_on'] = '2099-01-01';
        $this->command('dispatch', $data)->assertUnprocessable();
        $data = $this->dispatchData();
        $data['items'][] = $data['items'][0];
        $this->command('dispatch', $data)->assertUnprocessable();
        [$id, $visits] = $this->dispatch();
        $items = [['washing_order_item_id' => $visits[0], 'expected_sequence' => 2]];
        $this->command('orders/'.$id.'/return', ['returned_on' => '2026-01-04', 'location_id' => $this->location, 'items' => $items])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->command('orders/'.$id.'/complete', ['completed_on' => '2026-01-02', 'items' => $items])->assertUnprocessable()->assertJsonValidationErrors('completed_on');
        $this->command('orders/'.$id.'/complete', ['completed_on' => '2026-01-04', 'items' => $items])->assertCreated();
        $items[0]['expected_sequence'] = 3;
        $this->command('orders/'.$id.'/return', ['returned_on' => '2026-01-05', 'location_id' => $this->destination, 'items' => $items])->assertUnprocessable()->assertJsonValidationErrors('location_id');
    }

    public function test_facility_user_only_sees_assigned_custody_and_can_complete_without_financial_access(): void
    {
        [$id, $visits] = $this->dispatch();
        $this->command('bills', $this->billData($visits))->assertCreated();
        $user = $this->roleUser('Washing Facility User', null, $this->facility);
        Sanctum::actingAs($user);
        $this->getJson('/api/washing/orders/'.$id)->assertOk()->assertJsonMissingPath('data.items.0.unit_price');
        $this->getJson('/api/washing/stock')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/washing/lookups')->assertOk()->assertJsonCount(0, 'data.accounts');
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertOk()->assertJsonMissingPath('data.washing_costs');
        $this->getJson('/api/washing/bills')->assertForbidden();
        $this->getJson('/api/washing/visits')->assertForbidden();
        $this->command('dispatch', $this->dispatchData())->assertForbidden();
        $items = [['washing_order_item_id' => $visits[0], 'expected_sequence' => 2]];
        $data = ['completed_on' => '2026-01-04', 'items' => $items];
        $this->command('orders/'.$id.'/complete', $data, 'facility-complete-retry')->assertCreated();
        $this->command('orders/'.$id.'/return', ['returned_on' => '2026-01-05', 'location_id' => $this->location, 'items' => $items])->assertForbidden();
        DB::table('user_washing_facilities')->where('user_id', $user->id)->delete();
        $this->getJson('/api/washing/orders/'.$id)->assertNotFound();
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertNotFound();
        $this->command('orders/'.$id.'/complete', $data, 'facility-complete-retry')->assertConflict();
    }

    public function test_historical_visit_access_does_not_expose_later_out_of_scope_custody(): void
    {
        [$originalOrder, $visits] = $this->dispatch();
        $this->command('orders/'.$originalOrder.'/complete', ['completed_on' => '2026-01-04',
            'items' => [['washing_order_item_id' => $visits[0], 'expected_sequence' => 2]]])->assertCreated();
        $this->command('orders/'.$originalOrder.'/return', ['returned_on' => '2026-01-05', 'location_id' => $this->location,
            'items' => [['washing_order_item_id' => $visits[0], 'expected_sequence' => 3]]])->assertCreated();
        $this->postJson('/api/inventory/transfers', ['transfer_no' => 'WASH-PRIVACY-T1', 'from_location_id' => $this->location,
            'to_location_id' => $this->destination, 'transferred_on' => '2026-01-06',
            'items' => [['carpet_id' => $this->carpets[0], 'expected_sequence' => 4]]], $this->key())->assertCreated();
        $otherFacility = DB::table('washing_facilities')->insertGetId(['name' => 'Other private washing facility']);
        $otherLocation = DB::table('stock_locations')->insertGetId(['code' => 'WASH-PRIVATE', 'name' => 'Other private custody',
            'washing_facility_id' => $otherFacility, 'location_type' => 'washing']);
        $next = $this->dispatchData();
        $next['order_no'] = 'WASH-PRIVACY-O2';
        $next['branch_id'] = $this->other;
        $next['facility_id'] = $otherFacility;
        $next['facility_location_id'] = $otherLocation;
        $next['sent_on'] = '2026-01-07';
        $next['items'] = [['carpet_id' => $this->carpets[0], 'expected_sequence' => 5]];
        $nextOrder = $this->command('dispatch', $next)->assertCreated()->json('data.id');
        $this->getJson('/api/washing/orders/'.$originalOrder)->assertOk()
            ->assertJsonPath('data.items.0.location_name', 'Other private custody')
            ->assertJsonPath('data.items.0.current_sequence', 6);

        foreach ([$this->roleUser('Washing Facility User', null, $this->facility), $this->roleUser('Branch Manager', $this->branch)] as $actor) {
            Sanctum::actingAs($actor);
            $this->getJson('/api/washing/orders/'.$originalOrder)->assertOk()
                ->assertJsonPath('data.items.0.state', 'returned')
                ->assertJsonPath('data.items.0.completed_on', '2026-01-04')
                ->assertJsonPath('data.items.0.returned_on', '2026-01-05')
                ->assertJsonPath('data.items.0.return_location_name', 'Washing return shelf')
                ->assertJsonPath('data.items.0.current_sequence', null)
                ->assertJsonPath('data.items.0.stock_status', null)
                ->assertJsonPath('data.items.0.location_id', null)
                ->assertJsonPath('data.items.0.location_name', null)
                ->assertJsonPath('data.items.1.stock_status', 'at_washing')
                ->assertJsonPath('data.items.1.location_name', 'Facility custody');
            $this->getJson('/api/washing/orders/'.$nextOrder)->assertNotFound();
            $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertNotFound();
        }

        Sanctum::actingAs($this->roleUser('Washing Facility User', null, $otherFacility));
        $this->getJson('/api/washing/orders/'.$nextOrder)->assertOk()
            ->assertJsonPath('data.items.0.stock_status', 'at_washing')
            ->assertJsonPath('data.items.0.location_name', 'Other private custody')
            ->assertJsonPath('data.items.0.current_sequence', 6);
    }

    public function test_cost_is_allocated_once_bill_does_not_move_cash_payment_retry_reversal_and_void_reconcile(): void
    {
        [, $visits] = $this->dispatch();
        $data = $this->billData($visits);
        $bill = $this->command('bills', $data, 'washing-bill-retry')->assertCreated()->json('data.id');
        $this->command('bills', $data, 'washing-bill-retry')->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame('1000.00', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertOk()->assertJsonPath('data.washing_costs.0.total_amount', '10.10');
        $payment = $this->command('bills/'.$bill.'/payments', $this->paymentData(), 'washing-payment-retry')->assertCreated()->json('data');
        $this->command('bills/'.$bill.'/payments', $this->paymentData(), 'washing-payment-retry')->assertOk();
        $this->getJson('/api/washing/bills/'.$bill)->assertOk()->assertJsonPath('data.paid_amount', '10.10')->assertJsonPath('data.remaining_amount', '20.20')->assertJsonCount(1, 'data.payments');
        $this->assertSame('989.90', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
        $this->command('bills/'.$bill.'/void', ['voided_on' => '2026-01-06', 'reason' => 'Incorrect allocation'])->assertUnprocessable();
        $this->command('payments/'.$payment['id'].'/reverse', ['reason' => 'Correct washing payment'], 'washing-reversal-retry')->assertCreated();
        $this->command('payments/'.$payment['id'].'/reverse', ['reason' => 'Correct washing payment'], 'washing-reversal-retry')->assertOk();
        $this->command('payments/'.$payment['id'].'/reverse', ['reason' => 'Another reversal'])->assertConflict();
        $this->command('bills/'.$bill.'/void', ['voided_on' => now('Asia/Kabul')->toDateString(), 'reason' => 'Incorrect allocation'])->assertCreated();
        $this->getJson('/api/washing/bills/'.$bill)->assertOk()->assertJsonPath('data.state', 'void')->assertJsonPath('data.remaining_amount', '0.00');
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertOk()->assertJsonCount(0, 'data.washing_costs');
        $this->assertSame('1000.00', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
        $data['expense_no'] = 'WASH-E2';
        $this->command('bills', $data)->assertUnprocessable()->assertJsonValidationErrors('invoice_no');
        $data['invoice_no'] = 'FAC-INV-1-CORRECTED';
        $this->command('bills', $data)->assertCreated();
        $this->getJson('/api/inventory/carpets/'.$this->carpets[0])->assertOk()->assertJsonPath('data.washing_costs.0.total_amount', '10.10');
    }

    public function test_duplicate_invoice_active_visit_cost_mismatched_total_overpayment_and_overdraft_rejected(): void
    {
        [, $visits] = $this->dispatch();
        $data = $this->billData($visits);
        $data['amount'] = '30.31';
        $this->command('bills', $data)->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $data = $this->billData($visits);
        $bill = $this->command('bills', $data)->assertCreated()->json('data.id');
        $data['expense_no'] = 'WASH-E2';
        $this->command('bills', $data)->assertUnprocessable()->assertJsonValidationErrors('invoice_no');
        $data['invoice_no'] = 'FAC-INV-2';
        $this->command('bills', $data)->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $payment = $this->paymentData();
        $payment['amount'] = '30.31';
        $this->command('bills/'.$bill.'/payments', $payment)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $payment['amount'] = '1';
        $payment['bill_units_per_payment_unit'] = '2';
        $this->command('bills/'.$bill.'/payments', $payment)->assertUnprocessable()->assertJsonValidationErrors('bill_units_per_payment_unit');
        $this->assertSame(0, DB::table('washing_payments')->count());
        $this->assertSame(1, DB::table('washing_bills')->count());
    }

    public function test_cross_currency_conversion_rounding_and_base_allocations_remain_exact(): void
    {
        [, $visits] = $this->dispatch();
        $foreign = DB::table('currencies')->where('id', '<>', $this->currency)->first();
        $data = $this->billData($visits);
        $data['currency_id'] = $foreign->id;
        $data['rate_to_base'] = '0.5';
        $data['amount'] = '2.02';
        $data['allocations'][0]['amount'] = $data['allocations'][1]['amount'] = '1.01';
        $bill = $this->command('bills', $data)->assertCreated()->json('data.id');
        $this->assertSame('1.01', DB::table('washing_bills')->where('id', $bill)->value('amount_base'));
        $this->assertSame('1.01', DB::table('washing_bill_allocations')->where('washing_bill_id', $bill)->sum('amount_base'));
        $payment = $this->paymentData();
        $payment['amount'] = '1.01';
        $payment['bill_units_per_payment_unit'] = '2';
        $this->command('bills/'.$bill.'/payments', $payment)->assertCreated();
        $this->getJson('/api/washing/bills/'.$bill)->assertOk()->assertJsonPath('data.remaining_amount', '0.00');
        $this->assertSame('998.99', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
    }

    public function test_washing_bill_currency_cannot_change_code_or_precision_without_an_account_or_purchase(): void
    {
        $currency = ['code' => 'ZWC', 'name' => 'Washing-only currency', 'symbol' => 'W', 'decimal_places' => 2, 'is_active' => true];
        $currencyId = $this->postJson('/api/foundation/currencies', $currency)->assertCreated()->json('data.id');
        [, $visits] = $this->dispatch();
        $bill = $this->billData($visits);
        $bill['currency_id'] = $currencyId;
        $this->command('bills', $bill)->assertCreated();
        $this->assertFalse(DB::table('accounts')->where('currency_id', $currencyId)->exists());
        $this->assertFalse(DB::table('weaver_purchases')->where('currency_id', $currencyId)->exists());
        $this->putJson('/api/foundation/currencies/'.$currencyId, [...$currency, 'decimal_places' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson('/api/foundation/currencies/'.$currencyId, [...$currency, 'code' => 'ZWD'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson('/api/foundation/currencies/'.$currencyId, [...$currency, 'name' => 'Renamed display currency'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed display currency')
            ->assertJsonPath('data.code', 'ZWC')->assertJsonPath('data.decimal_places', 2);
    }

    public function test_branch_financial_scope_and_ledger_reversal_permission_cannot_be_bypassed(): void
    {
        [, $visits] = $this->dispatch();
        $bill = $this->command('bills', $this->billData($visits))->assertCreated()->json('data.id');
        $payment = $this->command('bills/'.$bill.'/payments', $this->paymentData())->assertCreated()->json('data');
        Sanctum::actingAs($this->roleUser('Accountant', $this->other));
        $this->getJson('/api/washing/bills')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/washing/bills/'.$bill)->assertNotFound();
        $this->command('payments/'.$payment['id'].'/reverse', ['reason' => 'Outside branch'])->assertNotFound();
        $manager = $this->roleUser('Branch Manager', $this->branch);
        Sanctum::actingAs($manager);
        $this->getJson('/api/washing/bills/'.$bill)->assertOk();
        $this->command('bills/'.$bill.'/payments', $this->paymentData())->assertForbidden();
        $role = Role::create(['name' => 'Ledger reversal only']);
        $role->permissions()->attach(DB::table('permissions')->where('code', 'ledger.reverse')->value('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->branch]);
        DB::table('user_accounts')->insertOrIgnore(['user_id' => $user->id, 'account_id' => $this->account]);
        Sanctum::actingAs($user);
        $this->postJson('/api/foundation/ledger/'.$payment['account_transaction_id'].'/reverse', ['reason' => 'Try bypass washing'], $this->key())->assertForbidden();
    }

    public function test_washing_facts_cannot_be_edited_directly(): void
    {
        [$id] = $this->dispatch();
        try {
            DB::table('washing_orders')->where('id', $id)->update(['order_no' => 'EDITED']);
            $this->fail('Posted washing orders must be immutable.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('immutable', $error->getMessage());
        }
    }
}
