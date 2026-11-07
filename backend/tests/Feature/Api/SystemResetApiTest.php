<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BrandingImages;
use App\Services\BusinessSupport;
use App\Services\DatabaseBackup;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

class SystemResetApiTest extends ApiTestCase
{
    private const PASSWORD = 'IsolatedResetTest!42';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['system_reset.backup_root' => storage_path(
            'framework/testing/system-reset-'.bin2hex(random_bytes(6)))]);
    }

    private function administrator(): User
    {
        $admin = $this->actingAsAdmin();
        $admin->forceFill(['password_hash' => Hash::make(self::PASSWORD)])->save();

        return $admin;
    }

    private function preview(): array
    {
        return $this->getJson('/api/system/reset-preview')->assertOk()->json('data');
    }

    private function payload(array $preview): array
    {
        return ['password' => self::PASSWORD, 'confirmation' => 'RESET ALL DATA',
            'preview_token' => $preview['preview_token']];
    }

    private function backup(bool $fail = false): void
    {
        $this->mock(DatabaseBackup::class)->shouldReceive('create')->once()
            ->andReturnUsing(function (string $directory) use ($fail) {
                if ($fail) {
                    throw new RuntimeException('Simulated backup failure');
                }
                mkdir($directory, 0700, true);
                $path = $directory.'/fixture.sql';
                file_put_contents($path, '-- API isolation: actual dumps are tested separately.');

                return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
            });
    }

    private function business(User $actor): void
    {
        $branch = DB::table('branches')->insertGetId(['code' => 'RESET-A', 'name' => 'Reset branch']);
        $store = DB::table('stock_locations')->insertGetId(['code' => 'RESET-RAW', 'name' => 'Reset materials store', 'branch_id' => $branch, 'location_type' => 'warehouse']);
        $facility = DB::table('washing_facilities')->insertGetId(['name' => 'Reset washing']);
        $yard = DB::table('stock_locations')->insertGetId(['code' => 'RESET-YARD', 'name' => 'Reset washing yard', 'washing_facility_id' => $facility, 'location_type' => 'washing']);
        app(\App\Services\ManagerWorkflowService::class)->setup($actor, ['branch_id' => $branch, 'materials_location_id' => $store, 'finished_location_id' => $store, 'facility_id' => $facility, 'facility_location_id' => $yard, 'payment_trigger' => 'stock_acceptance']);
        $material = BusinessSupport::insert($actor, 'materials', ['code' => 'RESET-WOOL', 'name' => 'Reset wool', 'unit' => 'kg']);
        $settings = DB::table('company_settings')->first();
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'Reset supply vendor', 'status' => 'active']);
        app(\App\Services\SupplyPurchaseService::class)->create($actor, ['supplier_id' => $supplier, 'branch_id' => $branch, 'currency_id' => $settings->base_currency_id, 'rate_to_base' => '1', 'purchased_on' => '2026-01-01', 'items' => [['kind' => 'material', 'item_id' => $material, 'quantity' => '1', 'unit_cost' => '1']]], $settings, 'reset-supply-fixture');
        $employee = DB::table('employees')->insertGetId(['name' => 'Reset employee', 'branch_id' => $branch]);
        DB::table('users')->where('id', $actor->id)->update(['employee_id' => $employee]);
        DB::table('user_branches')->insert(['user_id' => $actor->id, 'branch_id' => $branch]);
        $account = DB::table('accounts')->insertGetId(['code' => 'RESET-CASH',
            'name' => 'Reset cash', 'branch_id' => $branch,
            'currency_id' => DB::table('company_settings')->value('base_currency_id')]);
        $this->postJson('/api/foundation/ledger', ['account_id' => $account,
            'entry_type' => 'opening', 'amount' => '120.30', 'rate_to_base' => '1',
            'transaction_date' => '2026-01-01', 'description' => 'Reset fixture'],
            ['Idempotency-Key' => 'reset-opening'])->assertCreated();
        $this->postJson('/api/customers', $this->validCustomer())->assertCreated();
        $actor->createToken('reset-test-session');
        Storage::disk('local')->put('carpets/reset-image.png', 'Isolated image content');
        Storage::disk('local')->put($this->brandingPath(), 'Isolated branding content');
        Storage::disk('local')->put('branding/another-database/keep.png', 'Another database image');
        $expense = DB::table('operating_expenses')->insertGetId(['expense_no' => 'RESET-EXP', 'branch_id' => $branch,
            'currency_id' => DB::table('company_settings')->value('base_currency_id'), 'rate_to_base' => '1',
            'expense_category_id' => DB::table('expense_categories')->value('id'), 'expense_on' => '2026-01-01',
            'payee' => 'Reset payee', 'description' => 'Reset expense fixture', 'amount' => '1', 'created_by' => $actor->id, 'created_at' => now()]);
        Storage::disk('local')->put(BusinessSupport::receiptDirectory().'/reset-receipt.pdf', 'Isolated receipt content');
        DB::table('expense_attachments')->insert(['expense_id' => $expense, 'storage_path' => BusinessSupport::receiptDirectory().'/reset-receipt.pdf',
            'original_name' => 'reset-receipt.pdf', 'mime_type' => 'application/pdf', 'created_by' => $actor->id, 'created_at' => now()]);
        DB::table('branding_images')->insert([
            'slot' => 'logo_light', 'version' => '00000000-0000-4000-8000-000000000001',
            'storage_path' => $this->brandingPath(), 'mime_type' => 'image/png',
            'width' => 1, 'height' => 1, 'bytes' => 25, 'updated_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function brandingPath(): string
    {
        return BrandingImages::directory().'/00000000-0000-4000-8000-000000000001.png';
    }

    public function test_reset_endpoints_require_authentication(): void
    {
        $this->getJson('/api/system/reset-preview')->assertUnauthorized();
        $this->postJson('/api/system/reset', [])->assertUnauthorized();
    }

    public function test_settings_permission_does_not_give_non_administrators_reset_access(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Reset settings manager']);
        $role->permissions()->sync(Permission::whereIn('code',
            ['settings.view', 'settings.update'])->pluck('id'));
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
        $this->getJson('/api/system/reset-preview')->assertForbidden();
        $this->postJson('/api/system/reset', [])->assertForbidden();
    }

    public function test_confirmation_password_and_signed_review_are_required_without_side_effects(): void
    {
        $actor = $this->administrator();
        $this->business($actor);
        $preview = $this->preview();
        $this->assertSame(DB::table('users')->count(), $preview['kept']['users']);
        $this->postJson('/api/system/reset', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['password', 'confirmation', 'preview_token']);
        $bad = $this->payload($preview);
        $bad['confirmation'] = 'reset all data';
        $this->postJson('/api/system/reset', $bad)->assertUnprocessable();
        $bad = $this->payload($preview);
        $bad['password'] = 'Incorrect password';
        $this->postJson('/api/system/reset', $bad)->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $bad = $this->payload($preview);
        $bad['preview_token'] = 'forged-review';
        $this->postJson('/api/system/reset', $bad)->assertUnprocessable()
            ->assertJsonValidationErrors('preview_token');
        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame('120.30', DB::table('v_account_balances')->value('balance'));
        Storage::disk('local')->assertExists('carpets/reset-image.png');
    }

    public function test_changed_data_and_expired_or_other_user_reviews_cannot_reset(): void
    {
        $this->administrator();
        $preview = $this->preview();
        $this->postJson('/api/customers', $this->validCustomer())->assertCreated();
        $this->postJson('/api/system/reset', $this->payload($preview))->assertConflict();
        $preview = $this->preview();
        $this->travel(6)->minutes();
        $this->postJson('/api/system/reset', $this->payload($preview))->assertConflict();
        $this->travelBack();
        $preview = $this->preview();
        $this->administrator();
        $this->postJson('/api/system/reset', $this->payload($preview))->assertConflict();
        $this->assertSame(1, DB::table('customers')->count());
    }

    public function test_review_requests_do_not_consume_the_reset_submission_limit(): void
    {
        $this->administrator();
        for ($i = 0; $i < 6; $i++) {
            $preview = $this->preview();
        }
        $data = $this->payload($preview);
        $data['password'] = 'Incorrect password';
        $this->postJson('/api/system/reset', $data)->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_reset_clears_all_business_rows_preserves_authentication_and_rejects_replay(): void
    {
        $actor = $this->administrator();
        $this->business($actor);
        DB::table('users')->where('id', $actor->id)->update(['updated_at' => '2020-01-02 03:04:05']);
        $preservedUser = (array) DB::table('users')->find($actor->id);
        $preservedUser['employee_id'] = null;
        $password = DB::table('users')->where('id', $actor->id)->value('password_hash');
        $assignments = DB::table('user_roles')->get()->toArray();
        $grants = DB::table('role_permissions')->get()->toArray();
        $migrations = DB::table('migrations')->count();
        $preview = $this->preview();
        $this->backup();
        $result = $this->postJson('/api/system/reset', $this->payload($preview))
            ->assertOk()->assertJsonPath('data.backup_created', true)->json('data');
        foreach (['customers', 'currencies', 'employees', 'branches', 'accounts',
            'account_transactions', 'audit_logs', 'personal_access_tokens',
            'user_branches', 'user_washing_facilities', 'user_accounts', 'operating_expenses', 'expense_attachments', 'supply_purchases', 'supply_purchase_lines', 'production_workflow_defaults'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame($password, DB::table('users')->where('id', $actor->id)->value('password_hash'));
        $this->assertNull(DB::table('users')->where('id', $actor->id)->value('employee_id'));
        $this->assertSame($preservedUser, (array) DB::table('users')->find($actor->id));
        $this->assertEquals($assignments, DB::table('user_roles')->get()->toArray());
        $this->assertEquals($grants, DB::table('role_permissions')->get()->toArray());
        $this->assertSame($migrations, DB::table('migrations')->count());
        $this->assertNull(DB::table('company_settings')->value('base_currency_id'));
        $this->assertSame(1, (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS active')->active);
        $this->assertNull(DB::selectOne('SELECT @csms_system_reset AS flag')->flag);
        Storage::disk('local')->assertMissing('carpets/reset-image.png');
        Storage::disk('local')->assertMissing($this->brandingPath());
        Storage::disk('local')->assertExists('branding/another-database/keep.png');
        $this->assertDatabaseCount('branding_images', 0);
        $directory = config('system_reset.backup_root').'/'.$result['reset_id'];
        $this->assertSame('Isolated image content', file_get_contents($directory.'/carpets/reset-image.png'));
        $this->assertSame('Isolated receipt content', file_get_contents($directory.'/expense-receipts/reset-receipt.pdf'));
        Storage::disk('local')->assertMissing(BusinessSupport::receiptDirectory().'/reset-receipt.pdf');
        $this->assertSame('Isolated branding content', file_get_contents(
            $directory.'/branding/00000000-0000-4000-8000-000000000001.png'));
        $this->assertFileExists($directory.'/reset.json');
        $this->postJson('/api/system/reset', $this->payload($preview))->assertConflict();
        $this->postJson('/api/login', ['username' => $actor->username,
            'password' => self::PASSWORD])->assertOk();
    }

    public function test_backup_failure_leaves_business_data_and_images_in_place(): void
    {
        $actor = $this->administrator();
        $this->business($actor);
        $preview = $this->preview();
        $this->backup(true);
        $this->postJson('/api/system/reset', $this->payload($preview))->assertStatus(500);
        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame('120.30', DB::table('v_account_balances')->value('balance'));
        Storage::disk('local')->assertExists('carpets/reset-image.png');
        Storage::disk('local')->assertExists($this->brandingPath());
        $this->assertDatabaseCount('branding_images', 1);
    }

    public function test_unexpected_account_change_aborts_reset_and_restores_business_data(): void
    {
        $actor = $this->administrator();
        $this->business($actor);
        $before = (array) DB::table('users')->find($actor->id);
        $preview = $this->preview();
        $this->backup();
        DB::listen(function (QueryExecuted $event) use ($actor) {
            if ($event->sql === 'delete from `customers`') {
                DB::table('users')->where('id', $actor->id)->update(['name' => 'Unexpected account change']);
            }
        });

        $this->postJson('/api/system/reset', $this->payload($preview))->assertStatus(500);

        $this->assertSame($before, (array) DB::table('users')->find($actor->id));
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame('120.30', DB::table('v_account_balances')->value('balance'));
        Storage::disk('local')->assertExists('carpets/reset-image.png');
        Storage::disk('local')->assertExists($this->brandingPath());
        $this->assertSame(1, (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS active')->active);
        $this->assertNull(DB::selectOne('SELECT @csms_system_reset AS flag')->flag);
    }

    public function test_failure_after_deletion_rolls_back_money_and_restores_images_and_guards(): void
    {
        $actor = $this->administrator();
        $this->business($actor);
        $preview = $this->preview();
        $this->backup();
        DB::listen(function (QueryExecuted $event) {
            if ($event->sql === 'delete from `customers`') {
                throw new RuntimeException('Simulated failure after a deletion');
            }
        });
        $this->postJson('/api/system/reset', $this->payload($preview))->assertStatus(500);
        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame('120.30', DB::table('v_account_balances')->value('balance'));
        Storage::disk('local')->assertExists('carpets/reset-image.png');
        Storage::disk('local')->assertExists($this->brandingPath());
        Storage::disk('local')->assertExists('branding/another-database/keep.png');
        $this->assertDatabaseCount('branding_images', 1);
        $this->assertSame(1, (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS active')->active);
        $this->assertNull(DB::selectOne('SELECT @csms_system_reset AS flag')->flag);
        $this->expectException(QueryException::class);
        DB::table('account_transactions')->delete();
    }
}
