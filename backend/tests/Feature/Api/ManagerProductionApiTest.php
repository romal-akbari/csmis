<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseBackup;
use App\Services\ManagerProductionPricing;
use App\Services\ManagerProductionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

class ManagerProductionApiTest extends ApiTestCase
{
    private User $admin;

    private int $branch;

    private int $location;

    private int $manager;

    private int $category;

    private int $currency;

    private int $account;

    private int $facility;

    private int $yard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->actingAsAdmin();
        $this->branch = DB::table('branches')->insertGetId(['code' => 'MGR-B', 'name' => 'Manager Kabul']);
        $this->location = DB::table('stock_locations')->insertGetId(['code' => 'MGR-L', 'name' => 'Finished carpet warehouse', 'branch_id' => $this->branch, 'location_type' => 'warehouse']);
        $this->manager = DB::table('production_managers')->insertGetId(['code' => 'MGR-M', 'name' => 'Responsible manager', 'branch_id' => $this->branch, 'created_by' => $this->admin->id, 'created_at' => now()]);
        $this->category = DB::table('carpet_categories')->insertGetId(['name' => 'Manager designs']);
        $this->currency = DB::table('company_settings')->value('base_currency_id');
        $this->account = $this->postJson('/api/foundation/accounts', ['code' => 'MGR-CASH', 'name' => 'Manager test cash', 'currency_id' => $this->currency, 'account_type' => 'cash', 'status' => 'active', 'opening_amount' => '50000', 'opening_on' => '2026-01-01', 'opening_rate' => '1'])->assertCreated()->json('data.id');
        $this->facility = DB::table('washing_facilities')->insertGetId(['name' => 'Production washer']);
        $this->yard = DB::table('stock_locations')->insertGetId(['code' => 'MGR-WASH', 'name' => 'Washing custody', 'washing_facility_id' => $this->facility, 'location_type' => 'washing']);
    }

    private function productionPost(string $path, array $data, ?string $key = null)
    {
        return $this->postJson('/api/production/'.$path, $data, ['Idempotency-Key' => $key ?? 'manager-'.bin2hex(random_bytes(10))]);
    }

    private function orderData(string $method = 'area', string $rate = '100', int $target = 2, string $trigger = 'stock_acceptance'): array
    {
        return ['manager_id' => $this->manager, 'branch_id' => $this->branch, 'currency_id' => $this->currency, 'rate_to_base' => '1',
            'ordered_on' => '2026-01-02', 'due_on' => '2026-02-01', 'description' => 'Manager coordinates the agreed carpet designs', 'payment_trigger' => $trigger,
            'lines' => [['name' => 'Chobi design', 'category_id' => $this->category, 'target_quantity' => $target, 'pricing_method' => $method, 'rate' => $rate]], 'factors' => []];
    }

    private function order(array $data = []): array
    {
        $id = $this->productionPost('orders', $data ?: $this->orderData())->assertCreated()->json('data.id');
        $line = DB::table('manager_production_lines')->where('production_order_id', $id)->value('id');

        return [$id, $line];
    }

    private function item(int $line, string $length = '200', string $width = '150'): array
    {
        return ['line_id' => $line, 'length_cm' => $length, 'width_cm' => $width];
    }

    private function delivery(int $line, array $materials = []): array
    {
        return ['delivered_on' => '2026-01-05', 'facility_id' => $this->facility, 'facility_location_id' => $this->yard,
            'items' => [$this->item($line)], 'material_movement_ids' => $materials];
    }

    private function payment(string $amount, string $type = 'production'): array
    {
        return ['payment_type' => $type, 'account_id' => $this->account, 'amount' => $amount, 'rate_to_base' => '1', 'units_per_payment_unit' => '1', 'payment_on' => '2026-01-10', 'reason' => 'Settle manager output'];
    }

    private function accept(int $washing): void
    {
        $items = DB::table('washing_order_items')->where('washing_order_id', $washing)->get()->map(fn ($i) => ['washing_order_item_id' => $i->id, 'expected_sequence' => 2])->all();
        $this->postJson('/api/washing/orders/'.$washing.'/complete', ['completed_on' => '2026-01-06', 'items' => $items], ['Idempotency-Key' => 'mgr-wash-complete-'.$washing])->assertCreated();
        $items = array_map(fn ($i) => array_replace($i, ['expected_sequence' => 3]), $items);
        $this->postJson('/api/washing/orders/'.$washing.'/return', ['returned_on' => '2026-01-07', 'location_id' => $this->location, 'items' => $items], ['Idempotency-Key' => 'mgr-wash-return-'.$washing])->assertCreated();
    }

    private function material(int $order, string $cost = '500'): int
    {
        $material = DB::table('materials')->insertGetId(['code' => 'MGR-WOOL-'.$order, 'name' => 'Wool', 'unit' => 'kg', 'created_by' => $this->admin->id, 'created_at' => now()]);
        $lot = DB::table('material_lots')->insertGetId(['lot_no' => 'MGR-MAT-'.$order, 'material_id' => $material, 'location_id' => $this->location, 'branch_id' => $this->branch,
            'currency_id' => $this->currency, 'rate_to_base' => '1', 'received_on' => '2026-01-01', 'quantity' => '10', 'unit_cost' => '100', 'total_cost' => '1000', 'total_cost_base' => '1000', 'created_by' => $this->admin->id, 'created_at' => now()]);
        DB::table('manager_material_movements')->insert(['manager_id' => $this->manager, 'lot_id' => $lot, 'production_order_id' => $order, 'movement_type' => 'issue', 'quantity' => '6', 'cost_base' => '0', 'moved_on' => '2026-01-03', 'reason' => 'Company material custody', 'created_by' => $this->admin->id, 'created_at' => now()]);

        return DB::table('manager_material_movements')->insertGetId(['manager_id' => $this->manager, 'lot_id' => $lot, 'production_order_id' => $order, 'movement_type' => 'consume', 'quantity' => '5', 'cost_base' => $cost, 'moved_on' => '2026-01-04', 'reason' => 'Actual wool consumed', 'created_by' => $this->admin->id, 'created_at' => now()]);
    }

    public function test_partial_delivery_goes_directly_to_washing_then_earns_on_stock_acceptance_once(): void
    {
        [$id, $line] = $this->order();
        $movement = $this->material($id);
        $request = $this->delivery($line, [$movement]);
        $first = $this->productionPost('orders/'.$id.'/deliveries', $request, 'manager-first-delivery')->assertCreated()->assertJsonPath('data.charge_amount', '300.00')->assertJsonPath('data.total_cost_base', '800.00')->json('data');
        $this->productionPost('orders/'.$id.'/deliveries', $request, 'manager-first-delivery')->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $first['id']);
        $carpet = DB::table('manager_delivery_items')->where('delivery_id', $first['id'])->value('carpet_id');
        $this->assertSame(['production_complete', 'washing_dispatch'], DB::table('stock_events')->where('carpet_id', $carpet)->orderBy('sequence_no')->pluck('event_type')->all());
        $this->assertDatabaseHas('v_current_stock', ['id' => $carpet, 'stock_status' => 'at_washing', 'location_id' => $this->yard, 'manager_production_order_id' => $id]);
        $this->assertSame('0.00', ManagerProductionService::earned($id));
        $this->productionPost('orders/'.$id.'/pay', $this->payment('1'))->assertUnprocessable();
        $this->productionPost('orders/'.$id.'/pay', $this->payment('100', 'advance'))->assertCreated();
        $this->accept($first['washing_order_id']);
        $this->assertSame('300.00', ManagerProductionService::earned($id));
        $this->assertDatabaseHas('manager_delivery_acceptances', ['item_id' => DB::table('manager_delivery_items')->where('carpet_id', $carpet)->value('id'), 'accepted_on' => '2026-01-07']);
        $this->productionPost('orders/'.$id.'/pay', $this->payment('200'))->assertCreated();
        $this->productionPost('orders/'.$id.'/pay', $this->payment('0.01'))->assertUnprocessable();
        $this->productionPost('orders/'.$id.'/close', ['closed_on' => '2026-01-11', 'reason' => 'Cannot close unfinished target'])->assertUnprocessable();
        $second = $this->productionPost('orders/'.$id.'/deliveries', $this->delivery($line))->assertCreated()->json('data');
        $this->accept($second['washing_order_id']);
        $this->productionPost('orders/'.$id.'/pay', $this->payment('300'))->assertCreated();
        $this->productionPost('orders/'.$id.'/close', ['closed_on' => '2026-01-11', 'reason' => 'All output accepted and settled'])->assertCreated();
        $this->getJson('/api/production/orders/'.$id)->assertOk()->assertJsonPath('data.state', 'closed')->assertJsonPath('data.accepted_quantity', 2)->assertJsonPath('data.balance_due', '0.00');
        $this->assertSame('49400.00', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
        $this->productionPost('orders/'.$id.'/pay', $this->payment('1', 'advance'))->assertUnprocessable();
        $transaction = DB::table('manager_production_payments')->where('production_order_id', $id)->value('account_transaction_id');
        $this->postJson('/api/foundation/ledger/'.$transaction.'/reverse', ['reason' => 'Cannot change a closed settlement'], ['Idempotency-Key' => 'mgr-closed-reverse'])->assertUnprocessable();
    }

    public function test_cumulative_pricing_allocates_flat_factors_once_and_rounding_reconciles(): void
    {
        $data = $this->orderData('fixed', '100', 3, 'completion');
        $data['lines'][0]['factors'] = [['label' => 'Complex design', 'kind' => 'fixed', 'operation' => 'add', 'value' => '20'], ['label' => 'Rate times quantity', 'kind' => 'rate_quantity', 'operation' => 'add', 'value' => '5', 'quantity' => '2']];
        $data['factors'] = [['label' => 'Handling', 'kind' => 'fixed', 'operation' => 'add', 'value' => '10'], ['label' => 'Agreed premium', 'kind' => 'percentage', 'operation' => 'add', 'value' => '10'], ['label' => 'Quality multiplier', 'kind' => 'multiplier', 'operation' => 'add', 'value' => '1.5'], ['label' => 'Discount', 'kind' => 'percentage', 'operation' => 'deduct', 'value' => '10']];
        [$id, $line] = $this->order($data);
        $charges = [];
        foreach ([1, 2, 3] as $n) {
            $charges[] = $this->productionPost('orders/'.$id.'/deliveries', $this->delivery($line))->assertCreated()->json('data.charge_amount');
        }
        $this->assertSame(['69.30', '69.30', '69.30'], $charges);
        $this->assertSame('207.90', ManagerProductionService::earned($id));
        $this->assertSame('207.90', (string) DB::table('manager_delivery_items')->where('production_order_id', $id)->sum('cost_base'));
        $this->productionPost('orders/'.$id.'/deliveries', $this->delivery($line))->assertUnprocessable();
        $this->assertSame(3, DB::table('manager_delivery_items')->where('production_order_id', $id)->count());
        $this->assertSame('50000.00', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
        $lines = [(object) ['id' => 1, 'target_quantity' => 3, 'pricing_method' => 'manual', 'rate' => '1']];
        $preview = ManagerProductionPricing::delivery($lines, [], [], array_fill(0, 3, $this->item(1)), 2);
        $this->assertSame(['0.33', '0.34', '0.33'], array_column($preview['items'], 'charge_amount'));
        $this->assertSame('1.00', $preview['charge_amount']);
    }

    public function test_invalid_terms_and_failed_washing_roll_back_all_generated_facts(): void
    {
        $data = $this->orderData('manual', '500', 1);
        $this->productionPost('orders', $data)->assertUnprocessable();
        $this->assertSame(0, DB::table('manager_production_orders')->where('manager_id', $this->manager)->count());
        $data['lines'][0]['manual_reason'] = 'Agreed exceptional size price';
        [$id, $line] = $this->order($data);
        $request = $this->delivery($line);
        $request['facility_location_id'] = $this->location;
        $this->productionPost('orders/'.$id.'/deliveries', $request)->assertUnprocessable();
        $this->assertSame(0, DB::table('manager_production_deliveries')->where('production_order_id', $id)->count());
        $this->assertSame(0, DB::table('manager_delivery_items')->where('production_order_id', $id)->count());
        $this->assertSame(0, DB::table('carpets')->where('registration_branch_id', $this->branch)->count());
        $request = $this->delivery($line);
        $request['items'][] = $this->item($line);
        $this->productionPost('orders/'.$id.'/deliveries', $request)->assertUnprocessable();
        $request = $this->delivery($line);
        $request['material_movement_ids'] = [999999];
        $this->productionPost('orders/'.$id.'/deliveries', $request)->assertUnprocessable();
    }

    public function test_branch_account_permissions_and_material_reuse_are_enforced(): void
    {
        [$id, $line] = $this->order($this->orderData('piece', '100', 2, 'completion'));
        $movement = $this->material($id);
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Manager scoped operator '.$user->id]);
        $role->permissions()->attach(DB::table('permissions')->whereIn('code', ['production.view', 'production.receive', 'production.pay'])->pluck('id'));
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
        $this->getJson('/api/production/orders/'.$id)->assertNotFound();
        $this->getJson('/api/production/orders')->assertOk()->assertJsonPath('total', 0);
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->branch]);
        $this->getJson('/api/production/orders/'.$id)->assertOk()->assertJsonCount(0, 'data.unallocated_material_movements');
        $this->productionPost('orders/'.$id.'/deliveries', $this->delivery($line, [$movement]))->assertForbidden();
        $role->permissions()->attach(DB::table('permissions')->where('code', 'washing.send')->value('id'));
        $this->productionPost('orders/'.$id.'/deliveries', $this->delivery($line, [$movement]))->assertCreated();
        $this->productionPost('orders/'.$id.'/deliveries', $this->delivery($line, [$movement]))->assertUnprocessable();
        $this->productionPost('orders/'.$id.'/pay', $this->payment('100'))->assertNotFound();
        DB::table('user_accounts')->insert(['user_id' => $user->id, 'account_id' => $this->account]);
        $this->productionPost('orders/'.$id.'/pay', $this->payment('100'))->assertCreated();
        $this->getJson('/api/production/orders/'.$id)->assertOk()->assertJsonCount(0, 'data.payments')->assertJsonPath('data.paid_amount', '100.00');
        $role->permissions()->attach(DB::table('permissions')->whereIn('code', ['accounts.view', 'ledger.view'])->pluck('id'));
        $this->getJson('/api/production/orders/'.$id)->assertOk()->assertJsonCount(1, 'data.payments');
        DB::table('user_accounts')->where('user_id', $user->id)->delete();
        $this->getJson('/api/production/orders/'.$id)->assertOk()->assertJsonCount(0, 'data.payments');
        $role->permissions()->detach(DB::table('permissions')->whereIn('code', ['accounts.view', 'ledger.view'])->pluck('id'));
        $this->getJson('/api/production/lookups')->assertOk()->assertJsonMissingPath('data.accounts.0.balance');
        $this->assertSame(1, DB::table('manager_delivery_materials')->where('movement_id', $movement)->count());
        $this->expectException(QueryException::class);
        DB::table('manager_production_lines')->where('id', $line)->update(['rate' => '1']);
    }

    public function test_final_delivery_cannot_omit_actual_costs_and_factor_only_pricing_is_supported(): void
    {
        $data = $this->orderData('piece', '0', 1, 'completion');
        $data['lines'][0]['factors'] = [['label' => 'Design area', 'kind' => 'per_area', 'operation' => 'add', 'value' => '10'], ['label' => 'Per carpet allowance', 'kind' => 'per_piece', 'operation' => 'add', 'value' => '20']];
        [$id, $line] = $this->order($data);
        $movement = $this->material($id);
        $this->postJson('/api/production/orders/'.$id.'/preview', ['items' => [$this->item($line)]])
            ->assertOk()->assertJsonPath('data.charge_amount', '50.00')->assertJsonPath('data.items.0.area_m2', '3.000000');
        $this->productionPost('orders/'.$id.'/deliveries', $this->delivery($line))->assertUnprocessable()->assertJsonValidationErrors('material_movement_ids');
        $this->assertSame(0, DB::table('manager_delivery_items')->where('production_order_id', $id)->count());
        $this->productionPost('orders/'.$id.'/deliveries', $this->delivery($line, [$movement]))->assertCreated()->assertJsonPath('data.charge_amount', '50.00')->assertJsonPath('data.total_cost_base', '550.00');
        $this->assertSame('50.00', ManagerProductionService::earned($id));
        $lot = DB::table('manager_material_movements')->where('id', $movement)->value('lot_id');
        $this->productionPost('managers/'.$this->manager.'/materials', ['lot_id' => $lot, 'production_order_id' => $id,
            'movement_type' => 'consume', 'quantity' => '1', 'moved_on' => '2026-01-06', 'reason' => 'Cannot hide late cost after final output'])
            ->assertUnprocessable()->assertJsonValidationErrors('production_order_id');

        $other = $this->orderData('manual', '0', 1);
        $other['lines'][0]['manual_reason'] = 'Company supplies materials; no production fee';
        [$order, $otherLine] = $this->order($other);
        $otherMovement = $this->material($order, '500');
        $this->productionPost('orders/'.$order.'/deliveries', $this->delivery($otherLine, [$otherMovement]))->assertCreated()->assertJsonPath('data.charge_amount', '0.00')->assertJsonPath('data.total_cost_base', '500.00');
    }

    public function test_finished_manager_carpet_sale_carries_material_labor_and_washing_cost_without_counting_advances_twice(): void
    {
        [$id, $line] = $this->order($this->orderData('area', '100', 1));
        $movement = $this->material($id);
        $delivery = $this->productionPost('orders/'.$id.'/deliveries', $this->delivery($line, [$movement]))->assertCreated()->json('data');
        $carpet = DB::table('manager_delivery_items')->where('delivery_id', $delivery['id'])->value('carpet_id');
        $customer = $this->postJson('/api/customers', $this->validCustomer())->assertCreated()->json('data.id');
        $sale = ['branch_id' => $this->branch, 'customer_id' => $customer, 'currency_id' => $this->currency, 'rate_to_base' => '1',
            'sold_on' => '2026-01-10', 'due_on' => '2026-01-10', 'items' => [['carpet_id' => $carpet, 'expected_sequence' => 2, 'unit_price' => '1200', 'discount' => '0']]];
        $postSale = fn ($data) => $this->postJson('/api/business/sales', $data, ['Idempotency-Key' => 'manager-sale-'.bin2hex(random_bytes(8))]);
        $postSale($sale)->assertConflict();
        $this->productionPost('orders/'.$id.'/pay', $this->payment('100', 'advance'))->assertCreated();
        $this->accept($delivery['washing_order_id']);
        $sale['items'][0]['expected_sequence'] = 4;
        $postSale($sale)->assertUnprocessable()->assertJsonValidationErrors('items');
        $visit = DB::table('washing_order_items')->where('washing_order_id', $delivery['washing_order_id'])->value('id');
        $this->postJson('/api/washing/bills', ['expense_no' => 'MANAGER-WASH-COST', 'invoice_no' => 'MANAGER-WASH-INVOICE',
            'branch_id' => $this->branch, 'facility_id' => $this->facility, 'currency_id' => $this->currency, 'rate_to_base' => '1', 'billed_on' => '2026-01-08',
            'amount' => '10', 'allocations' => [['washing_order_item_id' => $visit, 'amount' => '10']]], ['Idempotency-Key' => 'manager-sale-washing-cost'])->assertCreated();
        $saleId = $postSale($sale)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('trade_sale_items', ['sale_id' => $saleId, 'carpet_id' => $carpet, 'cost_base' => '810.00']);
        $this->assertDatabaseHas('v_current_stock', ['id' => $carpet, 'stock_status' => 'sold']);
        $this->assertSame('49900.00', DB::table('v_account_balances')->where('account_id', $this->account)->value('balance'));
        $this->getJson('/api/production/orders/'.$id)->assertOk()->assertJsonPath('data.deliveries.0.facility_name', 'Production washer');
    }

    public function test_reset_removes_populated_manager_production_resource_and_assistance_history_preserving_auth(): void
    {
        Storage::fake('local');
        config(['system_reset.backup_root' => storage_path('framework/testing/manager-reset-'.bin2hex(random_bytes(6)))]);
        $this->admin->forceFill(['password_hash' => Hash::make('ManagerResetTest!42')])->save();
        $data = $this->orderData('piece', '100', 1, 'completion');
        $data['factors'] = [['label' => 'Accepted design premium', 'kind' => 'fixed', 'operation' => 'add', 'value' => '10']];
        [$id, $line] = $this->order($data);
        $movement = $this->material($id);
        $delivery = $this->productionPost('orders/'.$id.'/deliveries', $this->delivery($line, [$movement]))->assertCreated()->json('data');
        $this->accept($delivery['washing_order_id']);
        $this->productionPost('orders/'.$id.'/pay', $this->payment('110'))->assertCreated();
        $this->productionPost('orders/'.$id.'/close', ['closed_on' => '2026-01-11', 'reason' => 'Complete reset history fixture'])->assertCreated();
        $tool = $this->productionPost('tools', ['code' => 'MGR-RESET-TOOL', 'name' => 'Reusable weaving comb', 'status' => 'active'])->assertCreated()->json('data.id');
        $payment = $this->payment('10');
        unset($payment['payment_type']);
        $lot = $this->historicalFixture(fn () => $this->productionPost('tool-receipts', ['tool_id' => $tool, 'branch_id' => $this->branch, 'location_id' => $this->location,
            'quantity' => 1, 'unit_cost' => '10', 'currency_id' => $this->currency, 'rate_to_base' => '1', 'received_on' => '2026-01-02', 'payment' => $payment])->assertCreated()->json('data.id'));
        $this->productionPost('managers/'.$this->manager.'/tools', ['lot_id' => $lot, 'movement_type' => 'issue', 'quantity' => 1,
            'moved_on' => '2026-01-10', 'condition' => 'good', 'reason' => 'Trace tool custody during reset'])->assertCreated();
        $assistance = $this->productionPost('assistance', ['branch_id' => $this->branch, 'manager_id' => $this->manager, 'beneficiary_name' => 'Worker household',
            'assistance_type' => 'advance', 'responsible_party' => 'worker', 'recovery_terms' => 'Cash repayment recorded separately',
            'currency_id' => $this->currency, 'rate_to_base' => '1', 'amount' => '100', 'assistance_on' => '2026-01-02', 'reason' => 'Reset support history fixture'])->assertCreated()->json('data.id');
        $payment['amount'] = '100';
        $this->productionPost('assistance/'.$assistance.'/fund', $payment)->assertCreated();
        $this->productionPost('assistance/'.$assistance.'/deliver', ['amount' => '60', 'delivered_on' => '2026-01-10', 'acknowledgement' => 'Worker received the advance'])->assertCreated();
        $payment['amount'] = '40';
        $this->productionPost('assistance/'.$assistance.'/return', $payment)->assertCreated();
        $payment['amount'] = '20';
        $this->productionPost('assistance/'.$assistance.'/recover', $payment)->assertCreated();
        $tables = ['production_managers', 'manager_material_movements', 'production_tools', 'production_tool_lots', 'production_tool_payments', 'manager_tool_movements',
            'worker_assistance', 'worker_assistance_deliveries', 'worker_assistance_fundings', 'worker_assistance_returns', 'worker_assistance_recoveries',
            'manager_production_orders', 'manager_production_lines', 'manager_pricing_factors', 'manager_production_deliveries', 'manager_delivery_items', 'manager_delivery_materials',
            'manager_earned_charges', 'manager_delivery_acceptances', 'manager_production_payments', 'manager_production_closures'];
        foreach ($tables as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), 'Reset must exercise populated '.$table);
        }
        $auth = [];
        foreach (['users', 'roles', 'permissions', 'user_roles', 'role_permissions'] as $table) {
            $auth[$table] = DB::table($table)->get()->toArray();
        }
        $this->mock(DatabaseBackup::class)->shouldReceive('create')->once()->andReturnUsing(function ($directory) {
            mkdir($directory, 0700, true);
            $path = $directory.'/fixture.sql';
            file_put_contents($path, '-- Isolated reset; physical restore verified separately.');

            return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
        });
        $preview = $this->getJson('/api/system/reset-preview')->assertOk()->json('data');
        $this->postJson('/api/system/reset', ['password' => 'ManagerResetTest!42', 'confirmation' => 'RESET ALL DATA', 'preview_token' => $preview['preview_token']])->assertOk();
        foreach ($tables as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        foreach ($auth as $table => $before) {
            $this->assertEquals($before, DB::table($table)->get()->toArray(), $table);
        }
        $this->assertSame(1, (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled);
        $this->assertNull(DB::selectOne('SELECT @csms_system_reset AS flag')->flag);
    }
}
