<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ManagerMaterialsService;
use App\Services\ManagerToolsService;
use App\Services\ProductionService;
use App\Services\WorkerAssistanceService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class ManagerResourcesApiTest extends ApiTestCase
{
    private User $admin;

    private int $branch;

    private int $location;

    private int $currency;

    private int $account;

    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->actingAsAdmin();
        $this->branch = DB::table('branches')->insertGetId(['code' => 'RES-B', 'name' => 'Resources Kabul']);
        $this->location = DB::table('stock_locations')->insertGetId(['code' => 'RES-L', 'name' => 'Resource store', 'branch_id' => $this->branch, 'location_type' => 'warehouse']);
        $this->currency = DB::table('company_settings')->value('base_currency_id');
        $this->account = $this->postJson('/api/foundation/accounts', ['code' => 'RES-CASH', 'name' => 'Resource cash', 'currency_id' => $this->currency,
            'account_type' => 'cash', 'status' => 'active', 'opening_amount' => '10000', 'opening_on' => '2026-01-01', 'opening_rate' => '1'])->assertCreated()->json('data.id');
        $this->manager = $this->p('managers', $this->managerData())->assertCreated()->json('data.id');
    }

    private function p(string $path, array $data, ?string $key = null)
    {
        if ($path === 'tool-receipts') return $this->historicalFixture(fn () =>
            $this->postJson('/api/production/'.$path, $data, ['Idempotency-Key' => $key ?? 'resource-'.bin2hex(random_bytes(12))]));
        return $this->postJson('/api/production/'.$path, $data, ['Idempotency-Key' => $key ?? 'resource-'.bin2hex(random_bytes(12))]);
    }

    private function managerData(): array
    {
        return ['code' => 'RES-M', 'name' => 'Responsible production manager', 'branch_id' => $this->branch, 'status' => 'active'];
    }

    private function payment(string $amount, string $date = '2026-01-05'): array
    {
        return ['account_id' => $this->account, 'amount' => $amount, 'rate_to_base' => '1', 'units_per_payment_unit' => '1', 'payment_on' => $date, 'reason' => 'Document manager resources'];
    }

    private function cash(): string
    {
        return (string) DB::table('v_account_balances')->where('account_id', $this->account)->value('balance');
    }

    private function toolLot(bool $pay = false): int
    {
        $tool = $this->p('tools', ['code' => 'RES-TOOL', 'name' => 'Carpet loom', 'status' => 'active'])->assertCreated()->json('data.id');
        $data = ['tool_id' => $tool, 'branch_id' => $this->branch, 'location_id' => $this->location, 'quantity' => 5,
            'unit_cost' => '100', 'currency_id' => $this->currency, 'rate_to_base' => '1', 'received_on' => '2026-01-02'];
        if ($pay) {
            $data['payment'] = $this->payment('200');
        }

        return $this->p('tool-receipts', $data)->assertCreated()->json('data.id');
    }

    private function assistance(string $type = 'advance'): int
    {
        $data = ['branch_id' => $this->branch, 'manager_id' => $this->manager, 'beneficiary_name' => 'Zahra, weaving household',
            'assistance_type' => $type, 'currency_id' => $this->currency, 'rate_to_base' => '1', 'amount' => '1000', 'assistance_on' => '2026-01-02', 'reason' => 'Household worker support'];
        if ($type === 'advance') {
            $data += ['responsible_party' => 'worker', 'recovery_terms' => 'Repay by recorded cash receipts in agreed installments'];
        }

        return $this->p('assistance', $data)->assertCreated()->json('data.id');
    }

    private function order(): int
    {
        $category = DB::table('carpet_categories')->insertGetId(['name' => 'Resource design']);

        return $this->p('orders', ['manager_id' => $this->manager, 'branch_id' => $this->branch, 'currency_id' => $this->currency,
            'rate_to_base' => '1', 'ordered_on' => '2026-01-02', 'due_on' => '2026-02-01', 'description' => 'Company funded resource production', 'payment_trigger' => 'completion',
            'lines' => [['name' => 'Wool carpet', 'category_id' => $category, 'target_quantity' => 1, 'pricing_method' => 'piece', 'rate' => '100']], 'factors' => []])->assertCreated()->json('data.id');
    }

    public function test_catalog_retries_are_idempotent_and_used_manager_identity_is_preserved(): void
    {
        $data = ['code' => 'RES-SECOND', 'name' => 'Second manager', 'branch_id' => $this->branch, 'status' => 'active'];
        $first = $this->p('managers', $data, 'resource-manager-retry')->assertCreated()->json('data.id');
        $this->p('managers', $data, 'resource-manager-retry')->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $first);
        $this->p('managers', $data)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->p('managers', array_replace($data, ['name' => 'Different request']), 'resource-manager-retry')->assertConflict();
        $this->assistance();
        $otherBranch = DB::table('branches')->insertGetId(['code' => 'RES-OTHER', 'name' => 'Other resource branch']);
        $this->patchJson('/api/production/managers/'.$this->manager, array_replace($this->managerData(), ['branch_id' => $otherBranch]), ['Idempotency-Key' => 'resource-manager-identity'])->assertUnprocessable();
        $this->patchJson('/api/production/managers/'.$this->manager, $this->managerData() + ['phone' => '+93 700 111 222'], ['Idempotency-Key' => 'resource-manager-contact'])->assertCreated();
        $this->assertDatabaseHas('production_managers', ['id' => $this->manager, 'phone' => '+93 700 111 222', 'branch_id' => $this->branch]);
    }

    public function test_tool_receiving_payment_and_custody_separate_good_returns_from_damage_and_loss(): void
    {
        $lot = $this->toolLot(true);
        $this->assertSame('9800.00', $this->cash());
        $this->getJson('/api/production/tool-receipts/'.$lot)->assertOk()->assertJsonPath('data.remaining_amount', '300.00')->assertJsonPath('data.warehouse_quantity', 5);
        $this->p('tool-receipts/'.$lot.'/pay', $this->payment('301'))->assertUnprocessable();
        $move = ['lot_id' => $lot, 'movement_type' => 'issue', 'quantity' => 4, 'moved_on' => '2026-01-06', 'condition' => 'good', 'reason' => 'Issue working looms to manager'];
        $this->p('managers/'.$this->manager.'/tools', $move, 'resource-tool-issue')->assertCreated();
        $this->p('managers/'.$this->manager.'/tools', $move, 'resource-tool-issue')->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, ManagerToolsService::warehouse($lot));
        $this->assertSame(4, ManagerToolsService::held($this->manager, $lot));
        $this->p('managers/'.$this->manager.'/tools', array_replace($move, ['movement_type' => 'return', 'quantity' => 1, 'condition' => 'damaged']))->assertUnprocessable()->assertJsonValidationErrors('condition');
        foreach (['damage', 'loss', 'return'] as $kind) {
            $this->p('managers/'.$this->manager.'/tools', array_replace($move, ['movement_type' => $kind, 'quantity' => 1]))->assertCreated();
        }
        $this->assertSame(2, ManagerToolsService::warehouse($lot));
        $this->assertSame(1, ManagerToolsService::held($this->manager, $lot));
        $this->p('managers/'.$this->manager.'/tools', array_replace($move, ['movement_type' => 'return', 'quantity' => 2]))->assertUnprocessable();
        $this->getJson('/api/production/managers/'.$this->manager.'/statement')->assertOk()->assertJsonPath('data.tools.0.held_quantity', '1')->assertJsonPath('data.tools.0.damaged_quantity', '1')->assertJsonPath('data.tools.0.lost_quantity', '1');
        $this->assertSame('9800.00', $this->cash());
    }

    public function test_tool_receipt_with_invalid_immediate_payment_rolls_back_and_respects_currency_precision(): void
    {
        $tool = $this->p('tools', ['code' => 'RES-ATOMIC', 'name' => 'Weaving comb', 'status' => 'active'])->assertCreated()->json('data.id');
        $data = ['tool_id' => $tool, 'branch_id' => $this->branch, 'location_id' => $this->location, 'quantity' => 2,
            'unit_cost' => '10', 'currency_id' => $this->currency, 'rate_to_base' => '1', 'received_on' => '2026-01-02', 'payment' => $this->payment('20.01')];
        $this->p('tool-receipts', $data)->assertUnprocessable();
        $this->assertSame(0, DB::table('production_tool_lots')->where('tool_id', $tool)->count());
        $this->assertSame('10000.00', $this->cash());
        $this->assertSame(1, DB::table('account_transactions')->where('account_id', $this->account)->count());
        $foreign = DB::table('currencies')->insertGetId(['code' => 'RSC', 'name' => 'Resource zero precision currency', 'symbol' => 'R', 'decimal_places' => 0, 'is_active' => true]);
        $data['currency_id'] = $foreign;
        $data['rate_to_base'] = '2';
        $data['unit_cost'] = '10.01';
        unset($data['payment']);
        $this->p('tool-receipts', $data)->assertUnprocessable()->assertJsonValidationErrors('unit_cost');
        $data['unit_cost'] = '10';
        $lot = $this->p('tool-receipts', $data)->assertCreated()->json('data.id');
        $this->p('tool-receipts/'.$lot.'/pay', array_replace($this->payment('20'), ['units_per_payment_unit' => '0.5']))->assertCreated();
        $this->getJson('/api/production/tool-receipts/'.$lot)->assertOk()->assertJsonPath('data.remaining_amount', '10.00');
        $this->assertSame('9980.00', $this->cash());
    }

    public function test_material_custody_consumption_and_legacy_movements_share_one_lot_and_total_cost(): void
    {
        $order = $this->order();
        $material = DB::table('materials')->insertGetId(['code' => 'RES-WOOL', 'name' => 'Wool yarn', 'unit' => 'kg', 'created_by' => $this->admin->id, 'created_at' => now()]);
        $lot = DB::table('material_lots')->insertGetId(['lot_no' => 'RES-MAT', 'material_id' => $material, 'location_id' => $this->location, 'branch_id' => $this->branch,
            'currency_id' => $this->currency, 'rate_to_base' => '1', 'quantity' => '10', 'unit_cost' => '100', 'total_cost' => '1000', 'total_cost_base' => '1000', 'received_on' => '2026-01-01', 'created_by' => $this->admin->id, 'created_at' => now()]);
        $move = ['lot_id' => $lot, 'movement_type' => 'issue', 'quantity' => '6', 'moved_on' => '2026-01-03', 'reason' => 'Issue wool into manager custody'];
        $this->p('managers/'.$this->manager.'/materials', $move)->assertCreated();
        $this->assertSame('4.000', ProductionService::warehouse($lot));
        $this->p('managers/'.$this->manager.'/materials', array_replace($move, ['movement_type' => 'consume', 'quantity' => '3']))->assertUnprocessable()->assertJsonValidationErrors('production_order_id');
        $this->p('managers/'.$this->manager.'/materials', array_replace($move, ['movement_type' => 'consume', 'quantity' => '3', 'production_order_id' => $order]))->assertCreated();
        $this->p('managers/'.$this->manager.'/materials', array_replace($move, ['movement_type' => 'waste', 'quantity' => '1', 'production_order_id' => $order]))->assertCreated();
        $this->p('managers/'.$this->manager.'/materials', array_replace($move, ['movement_type' => 'return', 'quantity' => '2']))->assertCreated();
        $this->assertSame('0.000', ManagerMaterialsService::held($this->manager, $lot));
        $this->assertSame('6.000', ProductionService::warehouse($lot));
        $this->assertSame('400.00', (string) DB::table('manager_material_movements')->where('lot_id', $lot)->sum('cost_base'));
        $this->p('managers/'.$this->manager.'/materials', array_replace($move, ['quantity' => '6.001']))->assertUnprocessable();
        $this->p('managers/'.$this->manager.'/materials', array_replace($move, ['quantity' => '6', 'moved_on' => '2025-12-31']))->assertUnprocessable();
        $employee = DB::table('employees')->insertGetId(['name' => 'Legacy manager', 'branch_id' => $this->branch]);
        $weaver = DB::table('weavers')->insertGetId(['name' => 'Legacy weaver', 'manager_employee_id' => $employee, 'registration_date' => '2026-01-01']);
        $legacy = DB::table('production_orders')->insertGetId(['order_no' => 'RES-LEGACY', 'model' => 'company_funded', 'weaver_id' => $weaver, 'branch_id' => $this->branch,
            'currency_id' => $this->currency, 'rate_to_base' => '1', 'ordered_on' => '2026-01-02', 'due_on' => '2026-02-01', 'description' => 'Legacy production', 'created_by' => $this->admin->id, 'created_at' => now()]);
        foreach (['issue', 'consume'] as $kind) {
            $this->historicalFixture(fn () => $this->postJson('/api/business/production/'.$legacy.'/move', ['lot_id' => $lot, 'movement_type' => $kind,
                'quantity' => '6', 'moved_on' => '2026-01-04', 'reason' => 'Legacy share of material lot'], ['Idempotency-Key' => 'resource-legacy-'.$kind])->assertCreated());
        }
        $this->assertSame('0.000', ProductionService::warehouse($lot));
        $this->assertSame('600.00', (string) DB::table('material_movements')->where('lot_id', $lot)->sum('cost_base'));
    }

    public function test_worker_advance_partial_funding_delivery_returns_and_recovery_reconcile_cash_once(): void
    {
        $id = $this->assistance();
        $this->p('assistance/'.$id.'/deliver', ['amount' => '1', 'delivered_on' => '2026-01-05', 'acknowledgement' => 'Worker confirms receipt'])->assertUnprocessable();
        $this->p('assistance/'.$id.'/fund', $this->payment('1000'), 'resource-assistance-funding')->assertCreated();
        $this->p('assistance/'.$id.'/fund', $this->payment('1000'), 'resource-assistance-funding')->assertOk();
        $this->assertSame('9000.00', $this->cash());
        $delivery = ['amount' => '600', 'delivered_on' => '2026-01-06', 'acknowledgement' => 'Worker confirms six hundred received'];
        $this->p('assistance/'.$id.'/deliver', $delivery, 'resource-assistance-delivery')->assertCreated();
        $this->p('assistance/'.$id.'/deliver', $delivery, 'resource-assistance-delivery')->assertOk();
        $this->assertSame('9000.00', $this->cash());
        $this->p('assistance/'.$id.'/return', $this->payment('401', '2026-01-07'))->assertUnprocessable();
        $this->p('assistance/'.$id.'/return', $this->payment('400', '2026-01-07'))->assertCreated();
        $this->p('assistance/'.$id.'/recover', $this->payment('601', '2026-01-08'))->assertUnprocessable();
        $this->p('assistance/'.$id.'/recover', $this->payment('200', '2026-01-08'))->assertCreated();
        $this->assertSame('9600.00', $this->cash());
        $this->getJson('/api/production/assistance/'.$id)->assertOk()->assertJsonPath('data.funded_amount', '1000.00')
            ->assertJsonPath('data.delivered_amount', '600.00')->assertJsonPath('data.returned_amount', '400.00')->assertJsonPath('data.with_manager_amount', '0.00')->assertJsonPath('data.advance_outstanding', '400.00');
        $this->p('assistance/'.$id.'/fund', $this->payment('1', '2026-01-04'))->assertUnprocessable();
        $this->p('assistance/'.$id.'/deliver', array_replace($delivery, ['delivered_on' => '2999-01-01']))->assertUnprocessable();
    }

    public function test_bonus_is_not_recoverable_debt_and_advance_responsibility_must_be_explicit(): void
    {
        $base = ['branch_id' => $this->branch, 'manager_id' => $this->manager, 'beneficiary_name' => 'Worker household', 'assistance_type' => 'advance',
            'currency_id' => $this->currency, 'rate_to_base' => '1', 'amount' => '100', 'assistance_on' => '2026-01-02', 'reason' => 'Record explicit support terms'];
        $this->p('assistance', $base)->assertUnprocessable()->assertJsonValidationErrors(['responsible_party', 'recovery_terms']);
        $id = $this->assistance('bonus');
        $this->p('assistance/'.$id.'/fund', $this->payment('100'))->assertCreated();
        $this->p('assistance/'.$id.'/deliver', ['amount' => '100', 'delivered_on' => '2026-01-05', 'acknowledgement' => 'Worker confirms bonus received'])->assertCreated();
        $this->assertSame('0.00', WorkerAssistanceService::balances($id)['advance_outstanding']);
        $this->p('assistance/'.$id.'/recover', $this->payment('1'))->assertUnprocessable();
        $this->p('assistance', array_replace($base, ['assistance_type' => 'bonus', 'responsible_party' => 'worker', 'recovery_terms' => 'Unwanted deduction']))->assertUnprocessable();
    }

    public function test_assistance_reversals_respect_delivery_and_replacement_funding_dependencies(): void
    {
        $id = $this->assistance();
        $this->p('assistance/'.$id.'/fund', $this->payment('1000'))->assertCreated();
        $fund = DB::table('worker_assistance_fundings')->where('assistance_id', $id)->value('account_transaction_id');
        $this->p('assistance/'.$id.'/deliver', ['amount' => '600', 'delivered_on' => '2026-01-05', 'acknowledgement' => 'Worker confirms receipt'])->assertCreated();
        $this->postJson('/api/foundation/ledger/'.$fund.'/reverse', ['reason' => 'Funding has been delivered'], ['Idempotency-Key' => 'resource-used-funding-reverse'])->assertUnprocessable();
        $this->p('assistance/'.$id.'/return', $this->payment('400'))->assertCreated();
        $returned = DB::table('worker_assistance_returns')->where('assistance_id', $id)->value('account_transaction_id');
        $this->p('assistance/'.$id.'/fund', $this->payment('400'))->assertCreated();
        $this->postJson('/api/foundation/ledger/'.$returned.'/reverse', ['reason' => 'Replacement funds already sent'], ['Idempotency-Key' => 'resource-replaced-return-reverse'])->assertUnprocessable();
        $this->p('assistance/'.$id.'/recover', $this->payment('100'))->assertCreated();
        $recovered = DB::table('worker_assistance_recoveries')->where('assistance_id', $id)->value('account_transaction_id');
        $this->postJson('/api/foundation/ledger/'.$recovered.'/reverse', ['reason' => 'Incorrect recovery corrected'], ['Idempotency-Key' => 'resource-recovery-reverse'])->assertCreated();
        $this->assertSame('600.00', WorkerAssistanceService::balances($id)['outstanding']);
        $this->assertSame('9000.00', $this->cash());
    }

    public function test_branch_and_permission_scopes_hide_other_manager_resources_and_payment_accounts(): void
    {
        $lot = $this->toolLot();
        $assistance = $this->assistance();
        $otherBranch = DB::table('branches')->insertGetId(['code' => 'RES-OUT', 'name' => 'Out of scope branch']);
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Resource reader '.$user->id]);
        $role->permissions()->sync(Permission::whereIn('code', ['production.view', 'production.manage', 'production.pay', 'materials.view', 'materials.move'])->pluck('id'));
        $user->roles()->attach($role);
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $otherBranch]);
        Sanctum::actingAs($user);
        foreach (['managers', 'tool-receipts', 'assistance'] as $resource) {
            $this->getJson('/api/production/'.$resource.'?search=RES')->assertOk()->assertJsonPath('total', 0);
        }
        foreach (['managers/'.$this->manager.'/statement', 'tool-receipts/'.$lot, 'assistance/'.$assistance] as $path) {
            $this->getJson('/api/production/'.$path)->assertNotFound();
        }
        $this->p('assistance/'.$assistance.'/fund', $this->payment('10'))->assertNotFound();
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $this->branch]);
        $this->p('assistance/'.$assistance.'/fund', $this->payment('10'))->assertNotFound();
        $this->getJson('/api/production/assistance/'.$assistance)->assertOk()->assertJsonPath('data.fundings', []);
        $this->getJson('/api/production/managers/'.$this->manager.'/statement')->assertOk()->assertJsonCount(1, 'data.assistance');
        $this->p('tools', ['code' => 'NO-CREATE', 'name' => 'Forbidden loom', 'status' => 'active'])->assertForbidden();
        $this->assertSame('10000.00', $this->cash());
    }
}
