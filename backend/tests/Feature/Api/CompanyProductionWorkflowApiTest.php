<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class CompanyProductionWorkflowApiTest extends ApiTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $actor = $this->actingAsAdmin();
        $branch = DB::table('branches')->insertGetId(['code' => 'COMPANY-FLOW', 'name' => 'Company production']);
        $location = DB::table('stock_locations')->insertGetId(['code' => 'COMPANY-RAW', 'name' => 'Supply store', 'branch_id' => $branch, 'location_type' => 'warehouse']);
        $currency = DB::table('company_settings')->value('base_currency_id');
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'Rahimi Wool and Tools', 'phone' => '0700000010', 'status' => 'active']);
        $material = DB::table('materials')->insertGetId(['code' => 'COMPANY-WOOL', 'name' => 'Wool', 'unit' => 'kg', 'created_by' => $actor->id, 'created_at' => now()]);
        $tool = DB::table('production_tools')->insertGetId(['code' => 'COMPANY-COMB', 'name' => 'Weaving comb', 'created_by' => $actor->id, 'created_at' => now()]);
        $account = $this->postJson('/api/foundation/accounts', ['code' => 'COMPANY-CASH', 'name' => 'Supply cash', 'currency_id' => $currency,
            'account_type' => 'cash', 'status' => 'active', 'opening_amount' => '10000', 'opening_on' => '2026-01-01', 'opening_rate' => '1'])->assertCreated()->json('data.id');
        $this->f = compact('actor', 'branch', 'location', 'currency', 'supplier', 'material', 'tool', 'account');
    }

    private function purchase(): array
    {
        return ['branch_id' => $this->f['branch'], 'location_id' => $this->f['location'], 'supplier_id' => $this->f['supplier'],
            'currency_id' => $this->f['currency'], 'rate_to_base' => '1', 'purchased_on' => '2026-01-02',
            'items' => [['kind' => 'material', 'item_id' => $this->f['material'], 'quantity' => '20', 'unit_cost' => '40'],
                ['kind' => 'tool', 'item_id' => $this->f['tool'], 'quantity' => '2', 'unit_cost' => '75']]];
    }

    private function payment(string $amount): array
    {
        return ['account_id' => $this->f['account'], 'amount' => $amount, 'rate_to_base' => '1', 'units_per_payment_unit' => '1',
            'payment_on' => '2026-01-03', 'reason' => 'Supplier payment'];
    }

    private function command(string $path, array $data, ?string $key = null)
    {
        return $this->postJson('/api/'.$path, $data, ['Idempotency-Key' => $key ?? 'company-flow-'.bin2hex(random_bytes(10))]);
    }

    public function test_retired_workflows_cannot_create_or_change_business_records_but_history_is_readable(): void
    {
        $before = [];
        foreach (['carpets', 'weaver_purchases', 'purchase_drafts', 'weaver_returns', 'weaver_refunds', 'weaver_payments', 'production_orders', 'weavers', 'stock_events', 'audit_logs'] as $table) $before[$table] = DB::table($table)->count();
        foreach (['business/purchases', 'business/purchases/1/pay', 'business/production', 'business/production/1/receive', 'business/partnerships',
            'business/partnerships/1/settle', 'inventory/carpets', 'inventory/purchases', 'inventory/purchases/1/void', 'inventory/receipts', 'inventory/payments',
            'inventory/corrections/drafts', 'inventory/corrections/drafts/1/post', 'inventory/corrections/returns', 'inventory/corrections/refunds',
            'directories/weavers', 'business/material-receipts', 'business/material-receipts/1/create', 'production/tool-receipts',
            'business/%70urchases', 'inventory/%63arpets', 'directories/weav%65rs', 'business/purchases/'] as $path) {
            $this->command($path, [])->assertStatus(409)->assertJsonPath('code', 'workflow_read_only');
        }
        $this->putJson('/api/inventory/corrections/drafts/1', [])->assertStatus(409);
        $this->putJson('/api/directories/weavers/1', [])->assertStatus(409);
        $this->deleteJson('/api/directories/weavers/1')->assertStatus(409);
        foreach ($before as $table => $count) $this->assertSame($count, DB::table($table)->count(), $table.' changed');
        foreach (['business/purchases', 'business/production', 'business/partnerships', 'inventory/corrections/drafts', 'inventory/corrections/returns',
            'inventory/corrections/refunds', 'inventory/payments', 'inventory/receipts', 'directories/weavers'] as $path) $this->getJson('/api/'.$path)->assertOk();
    }

    public function test_supplier_statement_counts_combined_receipts_and_cash_once_and_reversal_restores_the_balance(): void
    {
        $purchase = $this->purchase();
        $purchase['payment'] = $this->payment('250');
        $id = $this->command('production/supply-purchases', $purchase, 'company-supply')->assertCreated()->json('data.id');
        $this->command('production/supply-purchases', $purchase, 'company-supply')->assertOk()->assertJsonPath('replayed', true);
        $this->getJson('/api/production/supplier-balances')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.total_amount', '950.00')
            ->assertJsonPath('data.0.paid_amount', '250.00')->assertJsonPath('data.0.remaining_amount', '700.00');
        $path = '/api/production/suppliers/'.$this->f['supplier'].'/statement';
        $statement = $this->getJson($path)->assertOk()->assertJsonCount(1, 'data.purchases.data')->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.purchases.data.0.document_kind', 'supply')->assertJsonPath('data.payments.0.amount', '250.00')->json('data');
        $this->assertSame('9750.00', DB::table('v_account_balances')->where('account_id', $this->f['account'])->value('balance'));
        $this->command('production/supply-purchases/'.$id.'/pay', $this->payment('701'))->assertUnprocessable();
        $this->command('production/supply-purchases/'.$id.'/pay', $this->payment('700'), 'company-rest')->assertCreated();
        $this->command('production/supply-purchases/'.$id.'/pay', $this->payment('700'), 'company-rest')->assertOk();
        $this->getJson($path)->assertOk()->assertJsonPath('data.balances.0.owed', '0.00');
        $this->command('business/payments/'.$statement['payments'][0]['id'].'/reverse', ['reason' => 'Reverse incorrect supplier payment'])->assertOk();
        $this->getJson($path)->assertOk()->assertJsonPath('data.balances.0.owed', '250.00');
        $this->assertSame('9300.00', DB::table('v_account_balances')->where('account_id', $this->f['account'])->value('balance'));
    }

    public function test_supplier_is_required_and_invalid_supply_lines_roll_back_every_receipt(): void
    {
        $data = $this->purchase(); unset($data['supplier_id']);
        $this->command('production/supply-purchases', $data)->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        $data = $this->purchase(); $data['items'][1]['item_id'] = 999999999;
        $this->command('production/supply-purchases', $data)->assertUnprocessable();
        $this->assertSame(0, DB::table('supply_purchases')->count());
        $this->assertSame(0, DB::table('material_lots')->count());
        $this->assertSame(0, DB::table('production_tool_lots')->count());
        DB::table('suppliers')->where('id', $this->f['supplier'])->update(['status' => 'inactive']);
        $this->command('production/supply-purchases', $this->purchase())->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    }

    public function test_historical_supply_receipts_remain_in_balances_and_can_be_settled_without_creating_new_stock(): void
    {
        $lot = DB::table('production_tool_lots')->insertGetId(['lot_no' => 'OLD-TOOLS', 'tool_id' => $this->f['tool'], 'branch_id' => $this->f['branch'],
            'location_id' => $this->f['location'], 'supplier_id' => $this->f['supplier'], 'currency_id' => $this->f['currency'], 'rate_to_base' => '1',
            'quantity' => 2, 'unit_cost' => '75', 'total_cost' => '150', 'total_cost_base' => '150', 'received_on' => '2026-01-02',
            'created_by' => $this->f['actor']->id, 'created_at' => now()]);
        $this->command('production/supply-purchases', $this->purchase())->assertCreated();
        $path = '/api/production/suppliers/'.$this->f['supplier'].'/statement';
        $this->getJson($path)->assertOk()->assertJsonCount(2, 'data.purchases.data')->assertJsonPath('data.balances.0.amount', '1100.00');
        $this->command('production/tool-receipts/'.$lot.'/pay', $this->payment('150'))->assertCreated();
        $this->getJson($path)->assertOk()->assertJsonPath('data.balances.0.owed', '950.00');
        $this->assertSame(2, DB::table('production_tool_lots')->count());
    }

    public function test_supplier_balances_keep_currencies_separate_and_enforce_permissions_and_branch_scope(): void
    {
        $this->command('production/supply-purchases', $this->purchase())->assertCreated();
        $other = $this->purchase();
        $other['currency_id'] = DB::table('currencies')->where('id', '<>', $this->f['currency'])->value('id');
        $other['rate_to_base'] = '2';
        $this->command('production/supply-purchases', $other)->assertCreated();
        $this->getJson('/api/production/supplier-balances')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/production/suppliers/'.$this->f['supplier'].'/statement')->assertOk()->assertJsonCount(2, 'data.balances');
        $role = Role::create(['name' => 'Company supply reader']);
        $role->permissions()->sync(DB::table('permissions')->whereIn('code', ['suppliers.view', 'materials.view'])->pluck('id'));
        $reader = User::factory()->create(); $reader->roles()->attach($role); Sanctum::actingAs($reader);
        $this->getJson('/api/production/supplier-balances')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/production/suppliers/'.$this->f['supplier'].'/statement')->assertNotFound();
        DB::table('user_branches')->insert(['user_id' => $reader->id, 'branch_id' => $this->f['branch']]);
        $this->getJson('/api/production/suppliers/'.$this->f['supplier'].'/statement')->assertOk()->assertJsonPath('data.payments', []);
        $role->permissions()->sync(DB::table('permissions')->where('code', 'suppliers.view')->pluck('id'));
        $this->getJson('/api/production/supplier-balances')->assertForbidden();
    }

    public function test_dashboard_uses_supply_purchases_instead_of_finished_carpet_acquisitions(): void
    {
        $this->command('production/supply-purchases', $this->purchase())->assertCreated();
        $data = $this->getJson('/api/dashboard/overview')->assertOk()->json('data');
        $this->assertSame('950.00', $data['business']['purchases'][0]['amount']);
        $this->assertSame('950.00', $data['finance']['supplier_payables'][0]['owed']);
        $this->assertSame([], $data['finance']['manager_payables']);
        $this->assertSame(0, $data['metrics']['at_washing']);
        $this->assertSame(0, $data['metrics']['managers']);
    }

    public function test_dashboard_tracks_production_completion_washing_stock_acceptance_and_manager_earnings(): void
    {
        $actor = $this->f['actor'];
        $date = \App\Services\FoundationCatalog::today();
        $facility = DB::table('washing_facilities')->insertGetId(['name' => 'Company washing']);
        $yard = DB::table('stock_locations')->insertGetId(['code' => 'COMPANY-WASH', 'name' => 'Washing yard', 'washing_facility_id' => $facility, 'location_type' => 'washing']);
        $manager = DB::table('production_managers')->insertGetId(['code' => 'COMPANY-MGR', 'name' => 'Company manager', 'branch_id' => $this->f['branch'], 'created_by' => $actor->id, 'created_at' => now()]);
        $category = DB::table('carpet_categories')->insertGetId(['name' => 'Company wool carpets']);
        $this->command('production/setup', ['branch_id' => $this->f['branch'], 'materials_location_id' => $this->f['location'], 'finished_location_id' => $this->f['location'],
            'facility_id' => $facility, 'facility_location_id' => $yard, 'payment_trigger' => 'stock_acceptance'])->assertCreated();
        $order = $this->command('production/orders', ['branch_id' => $this->f['branch'], 'manager_id' => $manager, 'currency_id' => $this->f['currency'],
            'rate_to_base' => '1', 'ordered_on' => $date, 'due_on' => $date, 'description' => 'Current production dashboard', 'payment_trigger' => 'stock_acceptance',
            'lines' => [['name' => 'Chobi', 'category_id' => $category, 'target_quantity' => 1, 'pricing_method' => 'piece', 'rate' => '100']], 'factors' => []])->assertCreated()->json('data.id');
        $line = DB::table('manager_production_lines')->where('production_order_id', $order)->value('id');
        $this->command('production/orders/'.$order.'/receive', ['delivered_on' => $date, 'items' => [['line_id' => $line, 'length_cm' => '300', 'width_cm' => '200']],
            'usages' => [], 'no_materials_used' => true])->assertCreated();
        $overview = $this->getJson('/api/dashboard/overview?days=7')->assertOk()->assertJsonPath('data.metrics.at_washing', 1)->assertJsonPath('data.metrics.managers', 1)->json('data');
        $this->assertSame(1, $overview['stock']['trend'][6]['produced']);
        $this->assertSame(0, $overview['stock']['trend'][6]['received']);
        $items = $this->getJson('/api/production/orders/'.$order.'/workflow')->assertOk()->json('data.washing_items');
        $this->command('production/orders/'.$order.'/accept', ['returned_on' => $date, 'confirmed_received' => true, 'record_cost_later' => false, 'washing_amount' => '10',
            'items' => array_map(fn ($item) => ['washing_order_item_id' => $item['washing_order_item_id'], 'expected_sequence' => $item['expected_sequence']], $items)])->assertCreated();
        $overview = $this->getJson('/api/dashboard/overview?days=7')->assertOk()->assertJsonPath('data.metrics.at_washing', 0)->assertJsonPath('data.metrics.available', 1)->json('data');
        $this->assertSame(1, $overview['stock']['trend'][6]['received']);
        $this->assertSame('100.00', $overview['finance']['manager_payables'][0]['owed']);
        $this->assertSame('10.00', $overview['finance']['washing_payables'][0]['owed']);
    }
}
