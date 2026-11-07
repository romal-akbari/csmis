<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use App\Services\ManagerMaterialsService;
use App\Services\ManagerToolsService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class ManagerWorkflowApiTest extends ApiTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->actingAsAdmin();
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'Simple supplies supplier', 'status' => 'active']);
        $branch = DB::table('branches')->insertGetId(['code' => 'SIMPLE-KBL', 'name' => 'Simple Kabul']);
        $store = DB::table('stock_locations')->insertGetId(['code' => 'SIMPLE-RAW', 'name' => 'Materials store', 'branch_id' => $branch, 'location_type' => 'warehouse']);
        $finished = DB::table('stock_locations')->insertGetId(['code' => 'SIMPLE-FIN', 'name' => 'Finished store', 'branch_id' => $branch, 'location_type' => 'warehouse']);
        $facility = DB::table('washing_facilities')->insertGetId(['name' => 'Simple washing']);
        $yard = DB::table('stock_locations')->insertGetId(['code' => 'SIMPLE-YARD', 'name' => 'Washing yard', 'washing_facility_id' => $facility, 'location_type' => 'washing']);
        $manager = DB::table('production_managers')->insertGetId(['code' => 'SIMPLE-M', 'name' => 'Simple manager', 'branch_id' => $branch, 'created_by' => $user->id, 'created_at' => now()]);
        $currency = DB::table('company_settings')->value('base_currency_id');
        $category = DB::table('carpet_categories')->insertGetId(['name' => 'Simple carpets']);
        $material = DB::table('materials')->insertGetId(['code' => 'SIMPLE-WOOL', 'name' => 'Wool', 'unit' => 'kg', 'created_by' => $user->id, 'created_at' => now()]);
        $tool = DB::table('production_tools')->insertGetId(['code' => 'SIMPLE-TOOL', 'name' => 'Weaving comb', 'created_by' => $user->id, 'created_at' => now()]);
        $account = $this->postJson('/api/foundation/accounts', ['code' => 'SIMPLE-CASH', 'name' => 'Workflow cash', 'currency_id' => $currency,
            'account_type' => 'cash', 'status' => 'active', 'opening_amount' => '100000', 'opening_on' => '2026-01-01', 'opening_rate' => '1'])->assertCreated()->json('data.id');
        $this->f = compact('branch', 'store', 'finished', 'facility', 'yard', 'manager', 'currency', 'category', 'material', 'tool', 'account', 'supplier');
        $this->workflowPost('setup', ['branch_id' => $branch, 'materials_location_id' => $store, 'finished_location_id' => $finished,
            'facility_id' => $facility, 'facility_location_id' => $yard, 'payment_trigger' => 'stock_acceptance'])->assertCreated();
    }

    private function workflowPost(string $path, array $data, ?string $key = null)
    {
        return $this->postJson('/api/production/'.$path, $data, ['Idempotency-Key' => $key ?? 'simple-'.bin2hex(random_bytes(12))]);
    }

    private function payment(string $amount): array
    {
        return ['account_id' => $this->f['account'], 'amount' => $amount, 'rate_to_base' => '1', 'units_per_payment_unit' => '1', 'payment_on' => '2026-01-10', 'reason' => 'Actual workflow payment'];
    }

    private function supply(bool $pay = true): array
    {
        $data = ['supplier_id' => $this->f['supplier'], 'branch_id' => $this->f['branch'], 'currency_id' => $this->f['currency'], 'rate_to_base' => '1', 'purchased_on' => '2026-01-02',
            'items' => [['kind' => 'material', 'item_id' => $this->f['material'], 'quantity' => '20', 'unit_cost' => '400'],
                ['kind' => 'tool', 'item_id' => $this->f['tool'], 'quantity' => '2', 'unit_cost' => '750']]];
        if ($pay) $data['payment'] = $this->payment('9500');
        return $data;
    }

    private function order(): array
    {
        $id = $this->workflowPost('orders', ['branch_id' => $this->f['branch'], 'manager_id' => $this->f['manager'], 'currency_id' => $this->f['currency'],
            'rate_to_base' => '1', 'ordered_on' => '2026-01-02', 'due_on' => '2026-02-01', 'description' => 'Simple Chobi order', 'payment_trigger' => 'stock_acceptance',
            'lines' => [['name' => 'Chobi', 'category_id' => $this->f['category'], 'target_quantity' => 2, 'pricing_method' => 'area', 'rate' => '600']],
            'factors' => [['label' => 'Border finishing', 'kind' => 'fixed', 'operation' => 'add', 'value' => '1200']]])->assertCreated()->json('data.id');
        return [$id, DB::table('manager_production_lines')->where('production_order_id', $id)->value('id')];
    }

    private function issue(): array
    {
        return ['moved_on' => '2026-01-10', 'movement_type' => 'issue', 'materials' => [['material_id' => $this->f['material'], 'quantity' => '16']],
            'tools' => [['tool_id' => $this->f['tool'], 'quantity' => 2]], 'advance' => $this->payment('2000'), 'reason' => 'Give supplies to manager'];
    }

    private function receive(int $line, string $waste = '0'): array
    {
        return ['delivered_on' => '2026-01-10', 'items' => [['line_id' => $line, 'length_cm' => '300', 'width_cm' => '200']],
            'usages' => [['material_id' => $this->f['material'], 'used_quantity' => '7', 'waste_quantity' => $waste]]];
    }

    private function accept(int $order): array
    {
        $items = $this->getJson('/api/production/orders/'.$order.'/workflow')->assertOk()->json('data.washing_items');
        return ['returned_on' => '2026-01-10', 'confirmed_received' => true, 'record_cost_later' => false, 'washing_amount' => '500', 'payment' => $this->payment('500'),
            'items' => array_map(fn ($i) => ['washing_order_item_id' => $i['washing_order_item_id'], 'expected_sequence' => $i['expected_sequence']], $items)];
    }

    public function test_stock_overview_tracks_tool_custody_and_carpet_locations_through_the_current_workflow(): void
    {
        $supply = $this->supply(false); $supply['items'][1]['quantity'] = '5';
        $this->workflowPost('supply-purchases', $supply)->assertCreated();
        [$order, $line] = $this->order();
        $path = '/api/inventory/stock-overview/';
        $store = $path.$this->f['store']; $yard = $path.$this->f['yard']; $finished = $path.$this->f['finished'];
        $this->getJson($store)->assertOk()->assertJsonPath('data.location.tool_quantity', '5')->assertJsonPath('data.tools.data.0.quantity', '5')
            ->assertJsonPath('data.carpets.total', 0);
        $issue = $this->issue(); $issue['tools'][0]['quantity'] = 4;
        $this->workflowPost('orders/'.$order.'/issue', $issue)->assertCreated();
        $this->getJson($store)->assertOk()->assertJsonPath('data.location.tool_quantity', '1');
        $lot = DB::table('production_tool_lots')->where('tool_id', $this->f['tool'])->value('id');
        foreach (['damage', 'loss', 'return'] as $kind) $this->workflowPost('managers/'.$this->f['manager'].'/tools', [
            'lot_id' => $lot, 'movement_type' => $kind, 'quantity' => 1, 'moved_on' => '2026-01-10', 'condition' => 'good', 'reason' => 'Record actual tool custody'])->assertCreated();
        $this->getJson($store)->assertOk()->assertJsonPath('data.location.tool_quantity', '2')->assertJsonPath('data.tools.data.0.quantity', '2');
        $delivery = $this->receive($line); $delivery['items'][] = $delivery['items'][0];
        $this->workflowPost('orders/'.$order.'/receive', $delivery)->assertCreated();
        $this->getJson($yard.'?per_page=1')->assertOk()->assertJsonPath('data.location.carpet_quantity', 2)->assertJsonPath('data.carpets.total', 2)
            ->assertJsonPath('data.carpets.last_page', 2)->assertJsonPath('data.carpets.data.0.stock_status', 'at_washing');
        $this->workflowPost('orders/'.$order.'/accept', $this->accept($order))->assertCreated();
        $this->getJson($yard)->assertOk()->assertJsonPath('data.location.carpet_quantity', 0);
        $this->getJson($finished)->assertOk()->assertJsonPath('data.location.carpet_quantity', 2)->assertJsonPath('data.carpets.total', 2);
        $carpets = DB::table('v_current_stock')->where('location_id', $this->f['finished'])->orderBy('id')->get();
        $item = fn ($carpet) => ['carpet_id' => $carpet->id, 'expected_sequence' => $carpet->sequence_no];
        $this->postJson('/api/inventory/transfers', ['from_location_id' => $this->f['finished'], 'to_location_id' => $this->f['store'],
            'transferred_on' => '2026-01-10', 'items' => [$item($carpets[0])]], ['Idempotency-Key' => 'auto-stock-transfer'])->assertCreated();
        $this->assertStringStartsWith('TRF-', DB::table('stock_transfers')->value('transfer_no'));
        $this->getJson($finished)->assertOk()->assertJsonPath('data.location.carpet_quantity', 1);
        $customer = $this->postJson('/api/customers', $this->validCustomer())->assertCreated()->json('data.id');
        $this->postJson('/api/business/sales', ['customer_id' => $customer, 'branch_id' => $this->f['branch'], 'currency_id' => $this->f['currency'],
            'rate_to_base' => '1', 'sold_on' => '2026-01-10', 'due_on' => '2026-01-11',
            'items' => [$item($carpets[1]) + ['unit_price' => '6000', 'discount' => '0']]], ['Idempotency-Key' => 'stock-overview-sale'])->assertCreated();
        $this->getJson($finished)->assertOk()->assertJsonPath('data.location.carpet_quantity', 0)->assertJsonPath('data.carpets.total', 0);
        $carpet = DB::table('v_current_stock')->where('id', $carpets[0]->id)->first();
        $this->postJson('/api/inventory/corrections/adjustments', ['branch_id' => $this->f['branch'], 'adjustment_type' => 'missing',
            'adjusted_on' => '2026-01-10', 'reason' => 'Cannot find carpet during count', 'items' => [$item($carpet)]],
            ['Idempotency-Key' => 'auto-stock-adjustment'])->assertCreated();
        $this->assertStringStartsWith('ADJ-', DB::table('stock_adjustments')->value('adjustment_no'));
        $this->getJson($store)->assertOk()->assertJsonPath('data.location.carpet_quantity', 0)->assertJsonPath('data.carpets.total', 0);
        $this->getJson('/api/inventory/stock-overview?search=Materials&per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.tool_quantity', '2');
        $this->getJson($store.'?tools_page=0')->assertUnprocessable();
    }

    public function test_stock_overview_enforces_permissions_branch_and_facility_scope(): void
    {
        $role = Role::create(['name' => 'Stock overview reader']);
        $reader = User::factory()->create(); $reader->roles()->attach($role); Sanctum::actingAs($reader);
        $path = '/api/inventory/stock-overview';
        $this->getJson($path)->assertForbidden();
        $role->permissions()->sync(DB::table('permissions')->where('code', 'materials.view')->pluck('id'));
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($path.'/'.$this->f['store'])->assertNotFound();
        DB::table('user_branches')->insert(['user_id' => $reader->id, 'branch_id' => $this->f['branch']]);
        $this->getJson($path)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($path.'/'.$this->f['store'])->assertOk()->assertJsonPath('data.carpets', null)->assertJsonPath('data.location.carpet_quantity', null);
        $this->getJson($path.'/'.$this->f['yard'])->assertNotFound();
        $role->permissions()->sync(DB::table('permissions')->where('code', 'washing.view')->pluck('id'));
        DB::table('user_branches')->where('user_id', $reader->id)->delete();
        DB::table('user_washing_facilities')->insert(['user_id' => $reader->id, 'washing_facility_id' => $this->f['facility']]);
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($path.'/'.$this->f['yard'])->assertOk()->assertJsonPath('data.tools', null)->assertJsonPath('data.location.tool_quantity', null);
        $this->getJson($path.'/'.$this->f['finished'])->assertNotFound();
    }

    public function test_one_order_flow_records_supply_issue_consumption_washing_cost_and_settlement_once(): void
    {
        $supply = $this->workflowPost('supply-purchases', $this->supply(), 'simple-supply')->assertCreated()->assertJsonPath('data.total_amount', '9500.00')->json('data');
        $this->workflowPost('supply-purchases', $this->supply(), 'simple-supply')->assertOk()->assertJsonPath('replayed', true);
        $this->getJson('/api/production/supply-purchases')->assertOk()->assertJsonPath('data.0.remaining_amount', '0.00');
        [$order, $line] = $this->order();
        $this->workflowPost('orders/'.$order.'/issue', $this->issue(), 'simple-issue')->assertCreated();
        $this->workflowPost('orders/'.$order.'/issue', $this->issue(), 'simple-issue')->assertOk()->assertJsonPath('replayed', true);
        foreach (['0', '1'] as $index => $waste) {
            $request = $this->receive($line, $waste);
            $this->workflowPost('orders/'.$order.'/receive', $request, 'simple-receive-'.$index)->assertCreated()->assertJsonPath('data.charge_amount', '4200.00');
            $this->workflowPost('orders/'.$order.'/receive', $request, 'simple-receive-'.$index)->assertOk()->assertJsonPath('replayed', true);
            $accept = $this->accept($order);
            $this->workflowPost('orders/'.$order.'/accept', $accept, 'simple-accept-'.$index)->assertCreated();
            $this->workflowPost('orders/'.$order.'/accept', $accept, 'simple-accept-'.$index)->assertOk()->assertJsonPath('replayed', true);
        }
        $this->workflowPost('orders/'.$order.'/settle', $this->payment('6400'))->assertCreated();
        $this->getJson('/api/production/orders/'.$order)->assertOk()->assertJsonPath('data.state', 'completed')->assertJsonPath('data.balance_due', '0.00');
        $this->assertSame('81100.00', DB::table('v_account_balances')->where('account_id', $this->f['account'])->value('balance'));
        $this->assertSame(['7000.00', '7400.00'], DB::table('manager_delivery_items')->where('production_order_id', $order)->orderBy('id')->pluck('cost_base')->all());
        $this->assertSame(2, DB::table('v_current_stock')->where('manager_production_order_id', $order)->where('location_id', $this->f['finished'])->where('stock_status', 'in_stock')->count());
        $this->assertSame(2, DB::table('washing_bill_allocations')->count());
        $this->assertSame('1000.00', (string) DB::table('washing_bill_allocations')->sum('amount'));
        $this->assertSame(1, DB::table('supply_purchases')->where('id', $supply['id'])->count());
    }

    public function test_invalid_stock_or_money_rolls_back_every_part_of_combined_commands(): void
    {
        $bad = $this->supply(); $bad['payment'] = $this->payment('100000.01');
        $this->workflowPost('supply-purchases', $bad)->assertUnprocessable();
        $this->assertSame(0, DB::table('supply_purchases')->count());
        $this->assertSame(0, DB::table('material_lots')->where('branch_id', $this->f['branch'])->count());
        $this->workflowPost('supply-purchases', $this->supply())->assertCreated();
        [$order, $line] = $this->order();
        $bad = $this->issue(); $bad['tools'][0]['quantity'] = 3;
        $this->workflowPost('orders/'.$order.'/issue', $bad)->assertUnprocessable();
        $this->assertSame(0, DB::table('manager_material_movements')->where('production_order_id', $order)->count());
        $this->workflowPost('orders/'.$order.'/issue', $this->issue())->assertCreated();
        $bad = $this->receive($line); $bad['facility_location_id'] = $this->f['store'];
        $this->workflowPost('orders/'.$order.'/receive', $bad)->assertUnprocessable();
        $this->assertSame(0, DB::table('manager_material_movements')->where('production_order_id', $order)->where('movement_type', 'consume')->count());
        $this->workflowPost('orders/'.$order.'/receive', $this->receive($line))->assertCreated();
        $bad = $this->accept($order); $bad['payment'] = $this->payment('501');
        $this->workflowPost('orders/'.$order.'/accept', $bad)->assertUnprocessable();
        $this->assertSame(0, DB::table('manager_delivery_acceptances')->count());
        $this->assertSame(0, DB::table('washing_bills')->where('branch_id', $this->f['branch'])->count());
        $this->assertSame('at_washing', DB::table('v_current_stock')->where('manager_production_order_id', $order)->value('stock_status'));
    }

    public function test_setup_and_combined_actions_preserve_ownership_permissions_and_quantity_precision(): void
    {
        $this->workflowPost('setup', ['branch_id' => $this->f['branch'], 'materials_location_id' => $this->f['yard'], 'finished_location_id' => $this->f['finished'],
            'facility_id' => $this->f['facility'], 'facility_location_id' => $this->f['yard'], 'payment_trigger' => 'stock_acceptance'])->assertNotFound();
        $bad = $this->supply(false); $bad['items'][1]['quantity'] = '1.5';
        $this->workflowPost('supply-purchases', $bad)->assertUnprocessable();
        $this->workflowPost('supply-purchases', $this->supply())->assertCreated();
        [$order] = $this->order();
        $user = User::factory()->create(); $role = Role::create(['name' => 'Workflow receiver '.$user->id]);
        $role->permissions()->attach(DB::table('permissions')->whereIn('code', ['production.view', 'production.receive'])->pluck('id'));
        $user->roles()->attach($role); Sanctum::actingAs($user);
        $this->getJson('/api/production/orders/'.$order.'/workflow')->assertNotFound();
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->f['branch']]);
        $this->getJson('/api/production/orders/'.$order.'/workflow')->assertOk()->assertJsonCount(0, 'data.materials');
        $this->workflowPost('orders/'.$order.'/issue', $this->issue())->assertForbidden();
    }

    public function test_supply_balances_allow_partial_and_final_payment_but_reject_overpayment_and_fractional_tools(): void
    {
        $bad = $this->supply(false); $bad['items'][1]['quantity'] = '1000000001';
        $this->workflowPost('supply-purchases', $bad)->assertUnprocessable();
        $id = $this->workflowPost('supply-purchases', $this->supply(false))->assertCreated()->json('data.id');
        $this->workflowPost('supply-purchases/'.$id.'/pay', $this->payment('1900'), 'simple-supply-partial')->assertCreated();
        $this->workflowPost('supply-purchases/'.$id.'/pay', $this->payment('1900'), 'simple-supply-partial')->assertOk()->assertJsonPath('replayed', true);
        $this->getJson('/api/production/supply-purchases')->assertOk()->assertJsonPath('data.0.remaining_amount', '7600.00');
        $this->workflowPost('supply-purchases/'.$id.'/pay', $this->payment('7600.01'))->assertUnprocessable();
        $this->workflowPost('supply-purchases/'.$id.'/pay', $this->payment('7600'))->assertCreated();
        $this->workflowPost('supply-purchases/'.$id.'/pay', $this->payment('1'))->assertUnprocessable();
        $this->getJson('/api/production/supply-purchases')->assertOk()->assertJsonPath('data.0.remaining_amount', '0.00');
        $this->assertSame('90500.00', DB::table('v_account_balances')->where('account_id', $this->f['account'])->value('balance'));
    }

    public function test_combined_foreign_currency_payment_keeps_exact_lot_and_account_balances(): void
    {
        $currency = DB::table('currencies')->insertGetId(['code' => 'WFX', 'name' => 'Workflow foreign currency', 'symbol' => 'W', 'decimal_places' => 2]);
        $account = $this->postJson('/api/foundation/accounts', ['code' => 'WFX-CASH', 'name' => 'Foreign cash', 'currency_id' => $currency,
            'account_type' => 'cash', 'status' => 'active', 'opening_amount' => '20000', 'opening_on' => '2026-01-01', 'opening_rate' => '2'])->assertCreated()->json('data.id');
        $data = $this->supply(false); $data['payment'] = array_replace($this->payment('4750'), ['account_id' => $account, 'rate_to_base' => '2', 'units_per_payment_unit' => '2']);
        $this->workflowPost('supply-purchases', $data)->assertCreated();
        $this->getJson('/api/production/supply-purchases')->assertOk()->assertJsonPath('data.0.remaining_amount', '0.00');
        $this->assertSame('15250.00', DB::table('v_account_balances')->where('account_id', $account)->value('balance'));
        $this->assertSame('100000.00', DB::table('v_account_balances')->where('account_id', $this->f['account'])->value('balance'));
    }

    private function foreignAccount(string $rate, int $precision = 2): int
    {
        $currency = DB::table('currencies')->insertGetId(['code' => 'WFR', 'name' => 'Workflow rounding currency', 'symbol' => 'R', 'decimal_places' => $precision]);
        return $this->postJson('/api/foundation/accounts', ['code' => 'WFR-CASH', 'name' => 'Rounding cash', 'currency_id' => $currency,
            'account_type' => 'cash', 'status' => 'active', 'opening_amount' => '20000', 'opening_on' => '2026-01-01', 'opening_rate' => $rate])->assertCreated()->json('data.id');
    }

    public function test_supply_payment_rounding_uses_one_cash_entry_and_exact_document_allocations(): void
    {
        $account = $this->foreignAccount('1.5');
        $data = $this->supply(false);
        $data['items'] = [['kind' => 'material', 'item_id' => $this->f['material'], 'quantity' => '1', 'unit_cost' => '100'],
            ['kind' => 'material', 'item_id' => $this->f['material'], 'quantity' => '1', 'unit_cost' => '100']];
        $purchase = $this->workflowPost('supply-purchases', $data)->assertCreated()->json('data.id');
        $payment = array_replace($this->payment('100.02'), ['account_id' => $account, 'rate_to_base' => '1.5', 'units_per_payment_unit' => '1.5']);
        $result = $this->workflowPost('supply-purchases/'.$purchase.'/pay', $payment, 'supply-rounding-once')->assertCreated()->json('data');
        $this->workflowPost('supply-purchases/'.$purchase.'/pay', $payment, 'supply-rounding-once')->assertOk()->assertJsonPath('replayed', true);
        $this->getJson('/api/production/supply-purchases')->assertOk()->assertJsonPath('data.0.paid_amount', '150.03')->assertJsonPath('data.0.remaining_amount', '49.97');
        $cash = DB::table('account_transactions')->where('account_id', $account)->where('direction', 'out')->get();
        $this->assertCount(1, $cash);
        $this->assertSame('100.02', $cash[0]->amount);
        $allocations = DB::table('material_lot_payments')->where('account_transaction_id', $cash[0]->id)->orderBy('id')->get();
        $this->assertSame(['75.02', '75.01'], $allocations->pluck('document_amount')->all());
        $this->assertSame(['50.01', '50.01'], $allocations->pluck('amount_in_payment_currency')->all());
        $this->assertSame('150.03', \App\Services\InventoryService::converted($cash[0]->amount, $cash[0]->rate_to_base, 2));
        foreach ($allocations as $allocation) $this->getJson('/api/business/material-receipts/'.$allocation->lot_id)->assertOk()->assertJsonPath('data.payments.0.amount', '50.01');
        $this->assertSame('19899.98', DB::table('v_account_balances')->where('account_id', $account)->value('balance'));
        $this->assertSame($cash[0]->id, $result['account_transaction_id']);
        $this->postJson('/api/foundation/ledger/'.$cash[0]->id.'/reverse', ['reason' => 'Reverse the complete supply payment'], ['Idempotency-Key' => 'supply-rounding-reverse'])->assertCreated();
        $this->getJson('/api/production/supply-purchases')->assertOk()->assertJsonPath('data.0.remaining_amount', '200.00');
        $this->assertSame('20000.00', DB::table('v_account_balances')->where('account_id', $account)->value('balance'));
    }

    public function test_supply_full_settlement_handles_unrepresentable_individual_fx_lot_balances(): void
    {
        $account = $this->foreignAccount('2');
        $data = $this->supply(false);
        $data['items'] = [['kind' => 'material', 'item_id' => $this->f['material'], 'quantity' => '1', 'unit_cost' => '100.01'],
            ['kind' => 'tool', 'item_id' => $this->f['tool'], 'quantity' => '1', 'unit_cost' => '100.01']];
        $data['payment'] = array_replace($this->payment('100.01'), ['account_id' => $account, 'rate_to_base' => '2', 'units_per_payment_unit' => '2']);
        $this->workflowPost('supply-purchases', $data)->assertCreated();
        $this->getJson('/api/production/supply-purchases')->assertOk()->assertJsonPath('data.0.paid_amount', '200.02')->assertJsonPath('data.0.remaining_amount', '0.00');
        $cash = DB::table('account_transactions')->where('account_id', $account)->where('direction', 'out')->get();
        $this->assertCount(1, $cash);
        $material = DB::table('material_lot_payments')->where('account_transaction_id', $cash[0]->id)->first();
        $tool = DB::table('production_tool_payments')->where('account_transaction_id', $cash[0]->id)->first();
        $this->assertSame('100.01', $material->document_amount);
        $this->assertSame('100.01', $tool->document_amount);
        $this->getJson('/api/business/material-receipts/'.$material->lot_id)->assertOk()->assertJsonPath('data.payments.0.amount', '50.01');
        $this->getJson('/api/production/tool-receipts/'.$tool->lot_id)->assertOk()->assertJsonPath('data.payments.0.amount', '50.00');
        $this->assertSame('19899.99', DB::table('v_account_balances')->where('account_id', $account)->value('balance'));
    }

    public function test_a_whole_currency_cash_payment_can_allocate_document_cost_to_multiple_lots(): void
    {
        $account = $this->foreignAccount('200', 0);
        $data = $this->supply(false);
        $data['items'] = [['kind' => 'material', 'item_id' => $this->f['material'], 'quantity' => '1', 'unit_cost' => '100'],
            ['kind' => 'tool', 'item_id' => $this->f['tool'], 'quantity' => '1', 'unit_cost' => '100']];
        $data['payment'] = array_replace($this->payment('1'), ['account_id' => $account, 'rate_to_base' => '200', 'units_per_payment_unit' => '200']);
        $this->workflowPost('supply-purchases', $data)->assertCreated();
        $this->getJson('/api/production/supply-purchases')->assertOk()->assertJsonPath('data.0.remaining_amount', '0.00');
        $cash = DB::table('account_transactions')->where('account_id', $account)->where('direction', 'out')->get();
        $this->assertCount(1, $cash);
        $this->assertSame('1.00', DB::table('material_lot_payments')->where('account_transaction_id', $cash[0]->id)->value('amount_in_payment_currency'));
        $this->assertSame('0.00', DB::table('production_tool_payments')->where('account_transaction_id', $cash[0]->id)->value('amount_in_payment_currency'));
        $this->assertSame('19999.00', DB::table('v_account_balances')->where('account_id', $account)->value('balance'));
    }

    public function test_large_supply_payments_do_not_lose_precision_in_proportional_allocations(): void
    {
        $account = $this->postJson('/api/foundation/accounts', ['code' => 'LARGE-CASH', 'name' => 'Large balance cash', 'currency_id' => $this->f['currency'],
            'account_type' => 'cash', 'status' => 'active', 'opening_amount' => '9000000000000000', 'opening_on' => '2026-01-01', 'opening_rate' => '1'])->assertCreated()->json('data.id');
        $data = $this->supply(false);
        $data['items'] = [['kind' => 'material', 'item_id' => $this->f['material'], 'quantity' => '1', 'unit_cost' => '3000000000000000.01'],
            ['kind' => 'material', 'item_id' => $this->f['material'], 'quantity' => '1', 'unit_cost' => '5999999999999999.99']];
        $data['payment'] = array_replace($this->payment('6000000000000000.01'), ['account_id' => $account]);
        $this->workflowPost('supply-purchases', $data)->assertCreated();
        $this->getJson('/api/production/supply-purchases')->assertOk()->assertJsonPath('data.0.remaining_amount', '2999999999999999.99');
        $allocations = DB::table('material_lot_payments')->orderBy('id')->get();
        $this->assertSame(['2000000000000000.01', '4000000000000000.00'], $allocations->pluck('document_amount')->all());
        $this->assertSame($allocations->pluck('document_amount')->all(), $allocations->pluck('amount_in_payment_currency')->all());
        $this->assertSame('2999999999999999.99', DB::table('v_account_balances')->where('account_id', $account)->value('balance'));
    }

    public function test_standalone_lot_payments_keep_their_original_cash_history(): void
    {
        $this->workflowPost('supply-purchases', $this->supply(false))->assertCreated();
        $material = DB::table('material_lots')->where('material_id', $this->f['material'])->value('id');
        $tool = DB::table('production_tool_lots')->where('tool_id', $this->f['tool'])->value('id');
        $this->postJson('/api/business/material-receipts/'.$material.'/pay', $this->payment('100'), ['Idempotency-Key' => 'standalone-material-pay'])->assertCreated();
        $this->workflowPost('tool-receipts/'.$tool.'/pay', $this->payment('200'), 'standalone-tool-pay')->assertCreated();
        $this->assertNull(DB::table('material_lot_payments')->where('lot_id', $material)->value('amount_in_payment_currency'));
        $this->assertNull(DB::table('production_tool_payments')->where('lot_id', $tool)->value('amount_in_payment_currency'));
        $this->getJson('/api/business/material-receipts/'.$material)->assertOk()->assertJsonPath('data.payments.0.amount', '100.00');
        $this->getJson('/api/production/tool-receipts/'.$tool)->assertOk()->assertJsonPath('data.payments.0.amount', '200.00');
        $this->getJson('/api/production/supply-purchases')->assertOk()->assertJsonPath('data.0.remaining_amount', '9200.00');
    }

    public function test_reversing_an_allocated_payment_checks_every_linked_lot_branch(): void
    {
        $data = $this->supply();
        $data['items'] = [['kind' => 'material', 'item_id' => $this->f['material'], 'quantity' => '1', 'unit_cost' => '100'],
            ['kind' => 'material', 'item_id' => $this->f['material'], 'quantity' => '1', 'unit_cost' => '100']];
        $data['payment'] = $this->payment('200');
        $this->workflowPost('supply-purchases', $data)->assertCreated();
        $transaction = DB::table('material_lot_payments')->orderBy('id')->value('account_transaction_id');
        $otherBranch = DB::table('branches')->insertGetId(['code' => 'SIMPLE-OTHER', 'name' => 'Other branch']);
        $otherLocation = DB::table('stock_locations')->insertGetId(['code' => 'SIMPLE-OTHER-RAW', 'name' => 'Other materials store', 'branch_id' => $otherBranch, 'location_type' => 'warehouse']);
        $actor = auth()->user();
        $otherLot = DB::table('material_lots')->insertGetId(['lot_no' => 'OTHER-MAT', 'branch_id' => $otherBranch, 'material_id' => $this->f['material'], 'location_id' => $otherLocation,
            'currency_id' => $this->f['currency'], 'rate_to_base' => '1', 'received_on' => '2026-01-01', 'quantity' => '1', 'unit_cost' => '1', 'total_cost' => '1', 'total_cost_base' => '1', 'created_by' => $actor->id, 'created_at' => now()]);
        // Imported references must not make a multi-document reversal bypass branch access.
        DB::table('material_lot_payments')->insert(['lot_id' => $otherLot, 'account_transaction_id' => $transaction, 'document_amount' => '1',
            'units_per_payment_unit' => '1', 'amount_in_payment_currency' => '0', 'created_by' => $actor->id, 'created_at' => now()]);
        $user = User::factory()->create(); $role = Role::create(['name' => 'Scoped supply reverser '.$user->id]);
        $role->permissions()->attach(DB::table('permissions')->whereIn('code', ['ledger.reverse', 'materials.reverse'])->pluck('id'));
        $user->roles()->attach($role);
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->f['branch']]);
        DB::table('user_accounts')->insert(['user_id' => $user->id, 'account_id' => $this->f['account']]);
        Sanctum::actingAs($user);
        $this->postJson('/api/foundation/ledger/'.$transaction.'/reverse', ['reason' => 'Cannot access every allocated lot'], ['Idempotency-Key' => 'supply-rounding-scoped-reverse'])->assertNotFound();
        $this->assertSame(0, DB::table('account_transactions')->where('reversal_of_id', $transaction)->count());
    }

    public function test_material_confirmation_overconsumption_stale_receipts_and_overdelivery_are_validated(): void
    {
        $this->workflowPost('supply-purchases', $this->supply())->assertCreated(); [$order, $line] = $this->order();
        $this->workflowPost('orders/'.$order.'/issue', $this->issue())->assertCreated();
        $bad = $this->receive($line); $bad['usages'][0]['used_quantity'] = '17';
        $this->workflowPost('orders/'.$order.'/receive', $bad)->assertUnprocessable();
        $this->assertSame(0, DB::table('manager_material_movements')->where('movement_type', 'consume')->count());
        $bad = $this->receive($line); $bad['usages'] = [];
        $this->workflowPost('orders/'.$order.'/receive', $bad)->assertUnprocessable()->assertJsonValidationErrors('usages');
        $bad = $this->receive($line); $bad['no_materials_used'] = true;
        $this->workflowPost('orders/'.$order.'/receive', $bad)->assertUnprocessable();
        $this->workflowPost('orders/'.$order.'/receive', $this->receive($line))->assertCreated();
        $bad = $this->accept($order); $bad['confirmed_received'] = false;
        $this->workflowPost('orders/'.$order.'/accept', $bad)->assertUnprocessable()->assertJsonValidationErrors('confirmed_received');
        $bad = $this->accept($order); $bad['items'][0]['expected_sequence']++;
        $this->workflowPost('orders/'.$order.'/accept', $bad)->assertConflict();
        $this->assertSame(0, DB::table('manager_delivery_acceptances')->count());
        $accept = $this->accept($order); $accept['record_cost_later'] = true; unset($accept['washing_amount'], $accept['payment']);
        $this->workflowPost('orders/'.$order.'/accept', $accept)->assertCreated();
        $this->assertSame(0, DB::table('washing_bills')->where('branch_id', $this->f['branch'])->count());
        $this->workflowPost('orders/'.$order.'/receive', $this->receive($line))->assertCreated();
        $this->workflowPost('orders/'.$order.'/receive', $this->receive($line))->assertUnprocessable();
        $this->assertSame('2.000', (string) ManagerMaterialsService::held($this->f['manager'], DB::table('material_lots')->where('material_id', $this->f['material'])->value('id')));
    }

    public function test_combined_commands_split_stock_across_lots_and_return_supplies_without_changing_cost(): void
    {
        $this->workflowPost('supply-purchases', $this->supply(false))->assertCreated();
        $this->workflowPost('supply-purchases', $this->supply(false))->assertCreated(); [$order, $line] = $this->order();
        $issue = $this->issue(); $issue['materials'][0]['quantity'] = '30'; unset($issue['advance']);
        $this->workflowPost('orders/'.$order.'/issue', $issue)->assertCreated();
        $receive = $this->receive($line, '1'); $receive['usages'][0]['used_quantity'] = '24';
        $this->workflowPost('orders/'.$order.'/receive', $receive)->assertCreated();
        $this->assertSame('14200.00', DB::table('manager_delivery_items')->where('production_order_id', $order)->value('cost_base'));
        $this->workflowPost('orders/'.$order.'/issue', ['movement_type' => 'return', 'moved_on' => '2026-01-10', 'reason' => 'Return unused supplies',
            'materials' => [['material_id' => $this->f['material'], 'quantity' => '5']], 'tools' => [['tool_id' => $this->f['tool'], 'quantity' => 2]]])->assertCreated();
        $context = $this->getJson('/api/production/orders/'.$order.'/workflow')->assertOk()->json('data');
        $this->assertSame(15.0, array_sum(array_column($context['materials'], 'warehouse_quantity')));
        $this->assertSame(0.0, array_sum(array_column($context['materials'], 'held_quantity')));
        $this->assertSame('14200.00', DB::table('manager_delivery_items')->where('production_order_id', $order)->value('cost_base'));
    }

    public function test_worker_support_can_be_funded_and_acknowledged_in_one_action_without_double_cash(): void
    {
        $data = ['branch_id' => $this->f['branch'], 'manager_id' => $this->f['manager'], 'currency_id' => $this->f['currency'], 'rate_to_base' => '1',
            'assistance_type' => 'advance', 'beneficiary_name' => 'Worker household', 'responsible_party' => 'worker', 'recovery_terms' => 'Repay in agreed cash instalments',
            'amount' => '3000', 'assistance_on' => '2026-01-10', 'reason' => 'Personal family support', 'payment' => $this->payment('3000'),
            'delivery' => ['amount' => '2500', 'delivered_on' => '2026-01-10', 'acknowledgement' => 'Manager confirms delivery to the worker']];
        $this->workflowPost('managers/'.$this->f['manager'].'/assistance', $data, 'simple-worker-support')->assertCreated();
        $this->workflowPost('managers/'.$this->f['manager'].'/assistance', $data, 'simple-worker-support')->assertOk();
        $this->assertSame('97000.00', DB::table('v_account_balances')->where('account_id', $this->f['account'])->value('balance'));
        $this->assertSame(1, DB::table('worker_assistance_fundings')->count());
        $this->assertSame(1, DB::table('worker_assistance_deliveries')->count());
    }

    public function test_worker_support_errors_identify_the_nested_funding_or_handover_fields(): void
    {
        $data = ['branch_id' => $this->f['branch'], 'manager_id' => (string) $this->f['manager'], 'currency_id' => $this->f['currency'], 'rate_to_base' => '1',
            'assistance_type' => 'advance', 'beneficiary_name' => 'Worker household', 'responsible_party' => 'worker', 'recovery_terms' => 'Repay in agreed cash instalments',
            'amount' => '300', 'assistance_on' => '2026-01-10', 'reason' => 'Personal family support', 'payment' => $this->payment('301')];
        $path = 'managers/'.$this->f['manager'].'/assistance';
        $this->workflowPost($path, $data)->assertUnprocessable()->assertJsonValidationErrors('payment.amount');
        $data['payment'] = $this->payment('100');
        $data['delivery'] = ['amount' => '101', 'delivered_on' => '2026-01-10', 'acknowledgement' => 'Manager confirms handover'];
        $this->workflowPost($path, $data)->assertUnprocessable()->assertJsonValidationErrors('delivery.amount');
        $this->assertSame(0, DB::table('worker_assistance')->count());
        $this->assertSame('100000.00', DB::table('v_account_balances')->where('account_id', $this->f['account'])->value('balance'));
    }
}
