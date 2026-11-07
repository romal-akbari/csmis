<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class FoundationApiTest extends ApiTestCase
{
    private function branch(string $code = 'TEST'): int
    {
        return DB::table('branches')->insertGetId(['code' => $code, 'name' => 'Test Branch']);
    }

    private function account(int $branch): int
    {
        return DB::table('accounts')->insertGetId([
            'code' => 'TEST-CASH', 'name' => 'Test Cash', 'branch_id' => $branch,
            'currency_id' => DB::table('currencies')->where('code', 'USD')->value('id'),
        ]);
    }

    private function entry(int $account, array $overrides = []): array
    {
        return $overrides + [
            'account_id' => $account, 'entry_type' => 'opening', 'amount' => '100.10',
            'rate_to_base' => '1', 'transaction_date' => now()->toDateString(),
            'description' => 'Opening balance test',
        ];
    }

    public function test_catalog_crud_audit_and_unique_validation(): void
    {
        $this->actingAsAdmin();
        $data = ['code' => 'KBL', 'name' => 'Kabul Branch', 'location' => 'Kabul',
            'phone' => '+93 701234567', 'is_main' => true, 'status' => 'active'];
        $id = $this->postJson('/api/foundation/branches', $data)
            ->assertCreated()->json('data.id');
        $this->putJson('/api/foundation/branches/'.$id, $data + ['ignored' => 'value'])
            ->assertOk();
        $this->postJson('/api/foundation/branches', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->getJson('/api/foundation/branches?search=Kabul')
            ->assertOk()->assertJsonPath('total', 1);
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'branches', 'record_id' => $id]);
        $this->postJson('/api/foundation/not-a-table', [])->assertNotFound();
    }

    public function test_resource_validation_matrix_and_invalid_lookup(): void
    {
        $this->actingAsAdmin();
        foreach (['branches', 'currencies', 'employees', 'categories', 'accounts',
            'locations', 'users', 'roles', 'exchange-rates', 'facilities', 'expense-categories'] as $resource) {
            $this->postJson('/api/foundation/'.$resource, [])->assertUnprocessable();
        }
        $this->postJson('/api/foundation/employees', [
            'name' => 'Employee', 'branch_id' => 999999, 'status' => 'active',
            'email' => 'invalid', 'joining_date' => '2099-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['branch_id', 'email', 'joining_date']);
        $this->getJson('/api/foundation/branches?per_page=1000')->assertUnprocessable();
        $this->postJson('/api/foundation/employees', [
            'name' => 'Ancient Date', 'branch_id' => $this->branch(),
            'status' => 'active', 'joining_date' => '0001-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('joining_date');
    }

    public function test_valid_create_and_update_for_each_foundation_directory(): void
    {
        $this->actingAsAdmin();
        $branch = $this->branch();
        $usd = DB::table('currencies')->where('code', 'USD')->value('id');
        $afn = DB::table('currencies')->where('code', 'AFN')->value('id');
        $cases = [
            'currencies' => ['code' => 'EUR', 'name' => 'Euro', 'symbol' => 'EUR', 'decimal_places' => 2, 'is_active' => true],
            'categories' => ['name' => 'Traditional', 'name_fa' => 'سنتی', 'description' => 'Wool', 'status' => 'active'],
            'expense-categories' => ['name' => 'Test travel', 'name_fa' => 'سفر', 'status' => 'active'],
            'employees' => ['name' => 'Test Employee', 'branch_id' => $branch, 'status' => 'active', 'phone' => '+93 701234567'],
            'accounts' => ['code' => 'CASH-USD', 'name' => 'Cash USD', 'branch_id' => $branch, 'currency_id' => $usd, 'account_type' => 'cash', 'status' => 'active'],
            'locations' => ['code' => 'SHELF-1', 'name' => 'Shelf One', 'branch_id' => $branch, 'washing_facility_id' => null, 'location_type' => 'shelf', 'status' => 'active'],
            'facilities' => ['name' => 'Test Washing', 'phone' => '+93 701234567', 'status' => 'active'],
            'roles' => ['name' => 'Custom Role', 'permission_ids' => [DB::table('permissions')->where('code', 'branches.view')->value('id')]],
            'exchange-rates' => ['base_currency_id' => $usd, 'quote_currency_id' => $afn, 'rate' => '70.12345678', 'effective_date' => now()->toDateString()],
        ];
        foreach ($cases as $resource => $data) {
            $id = $this->postJson('/api/foundation/'.$resource, $data)
                ->assertCreated()->json('data.id');
            $this->putJson('/api/foundation/'.$resource.'/'.$id, $data)->assertOk();
        }
    }

    public function test_location_owner_and_type_validation(): void
    {
        $this->actingAsAdmin();
        $branch = $this->branch();
        $facility = DB::table('washing_facilities')->insertGetId(['name' => 'Washing']);
        $base = ['code' => 'LOC', 'name' => 'Location', 'location_type' => 'washing', 'status' => 'active'];
        $this->postJson('/api/foundation/locations', $base)
            ->assertUnprocessable()->assertJsonValidationErrors('washing_facility_id')->assertJsonMissingValidationErrors('location_type');
        $this->postJson('/api/foundation/locations', $base + [
            'branch_id' => $branch, 'washing_facility_id' => $facility,
        ])->assertUnprocessable();
        $this->postJson('/api/foundation/locations', $base + ['branch_id' => $branch])
            ->assertUnprocessable()->assertJsonValidationErrors('location_type');
        $this->postJson('/api/foundation/locations', $base + ['washing_facility_id' => $facility])
            ->assertCreated()->assertJsonPath('data.branch_id', null);
        $warehouse = array_replace($base, ['code' => 'LOC-WH', 'location_type' => 'warehouse']);
        $this->postJson('/api/foundation/locations', $warehouse)
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id')->assertJsonMissingValidationErrors('location_type');
        $this->postJson('/api/foundation/locations', $warehouse + ['washing_facility_id' => $facility])
            ->assertUnprocessable()->assertJsonValidationErrors('location_type');
        $this->postJson('/api/foundation/locations', $warehouse + ['branch_id' => $branch])
            ->assertCreated()->assertJsonPath('data.washing_facility_id', null);
    }

    public function test_scoped_user_cannot_read_or_write_other_branch_or_admin_settings(): void
    {
        $first = $this->branch('FIRST');
        $second = $this->branch('SECOND');
        $account = $this->account($second);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'Accountant')->firstOrFail());
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $first]);
        Sanctum::actingAs($user);
        $this->getJson('/api/foundation/branches')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/foundation/accounts')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/foundation/ledger?account_id='.$account)->assertNotFound();
        $this->postJson('/api/foundation/ledger', $this->entry($account), ['Idempotency-Key' => 'scope-key'])
            ->assertNotFound();
        $this->postJson('/api/foundation/accounts', [
            'code' => 'COMPANY', 'name' => 'Company account',
            'currency_id' => DB::table('currencies')->value('id'),
            'account_type' => 'cash', 'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.branch_id', null);
        $this->getJson('/api/foundation/accounts')->assertOk()->assertJsonPath('total', 1);
        $this->postJson('/api/foundation/users', [])->assertForbidden();
        $this->getJson('/api/foundation/audit')->assertForbidden();
    }

    public function test_facility_users_only_receive_their_own_facility_locations(): void
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'Branch Manager')->firstOrFail();
        $user->roles()->attach($role);
        $facility = DB::table('washing_facilities')->insertGetId(['name' => 'Assigned']);
        $other = DB::table('washing_facilities')->insertGetId(['name' => 'Other']);
        DB::table('user_washing_facilities')->insert(['user_id' => $user->id, 'washing_facility_id' => $facility]);
        foreach ([$facility, $other] as $id) {
            DB::table('stock_locations')->insert(['code' => 'WASH-'.$id, 'name' => 'Wash',
                'washing_facility_id' => $id, 'location_type' => 'washing']);
        }
        Sanctum::actingAs($user);
        $this->getJson('/api/foundation/locations')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/foundation/lookups')->assertOk()->assertJsonCount(1, 'data.facilities');
    }

    public function test_user_management_hashes_secret_and_sets_scope_without_audit_leak(): void
    {
        $this->actingAsAdmin();
        $branch = $this->branch();
        $role = Role::where('name', 'Accountant')->value('id');
        $data = ['name' => 'New Accountant', 'username' => 'new_accountant',
            'password' => 'StrongTestPass!42', 'locale' => 'fa', 'is_active' => true,
            'role_ids' => [$role], 'branch_ids' => [$branch], 'facility_ids' => []];
        $id = $this->postJson('/api/foundation/users', $data)->assertCreated()
            ->assertJsonMissingPath('data.password_hash')->json('data.id');
        $this->assertDatabaseHas('user_branches', ['user_id' => $id, 'branch_id' => $branch]);
        $this->assertNotSame($data['password'], User::find($id)->password_hash);
        $audit = DB::table('audit_logs')->where('table_name', 'users')->where('record_id', $id)->first();
        $this->assertStringNotContainsString('password', $audit->new_values);
        $this->postJson('/api/foundation/users', array_replace($data, [
            'username' => 'bad_password', 'password' => 'short',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/foundation/users', array_replace($data, [
            'username' => 'oversized_password', 'password' => str_repeat('ش', 37),
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_self_lockout_is_rejected_and_inactive_tokens_stop_working(): void
    {
        $user = $this->actingAsAdmin();
        $data = ['name' => $user->name, 'username' => $user->username,
            'email' => $user->email, 'locale' => 'en', 'is_active' => false,
            'role_ids' => $user->roles()->pluck('roles.id')->all(), 'branch_ids' => [], 'facility_ids' => []];
        $this->putJson('/api/foundation/users/'.$user->id, $data)
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $user->update(['is_active' => false]);
        $this->getJson('/api/foundation/lookups')->assertForbidden();
    }

    public function test_ledger_decimal_idempotency_reversal_and_base_currency_lock(): void
    {
        $this->actingAsAdmin();
        $account = $this->account($this->branch());
        $data = $this->entry($account);
        $headers = ['Idempotency-Key' => 'opening-unique'];
        $id = $this->postJson('/api/foundation/ledger', $data, $headers)
            ->assertCreated()->json('data.id');
        $this->postJson('/api/foundation/ledger', $data, $headers)
            ->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/foundation/ledger', $this->entry($account, ['amount' => '101']), $headers)
            ->assertConflict();
        $this->postJson('/api/foundation/ledger', $this->entry($account, ['entry_type' => 'income', 'amount' => '0.20']),
            ['Idempotency-Key' => 'income-unique'])->assertCreated();
        $this->getJson('/api/foundation/ledger?account_id='.$account)
            ->assertOk()->assertJsonPath('balance', '100.30');
        $this->putJson('/api/foundation/settings', [
            'base_currency_id' => DB::table('currencies')->where('code', 'AFN')->value('id'),
            'default_locale' => 'en', 'timezone' => 'Asia/Kabul', 'reminder_days_before' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrors('base_currency_id');
        $reversal = ['Idempotency-Key' => 'reversal-unique'];
        $reversed = $this->postJson('/api/foundation/ledger/'.$id.'/reverse', ['reason' => 'Correct opening'], $reversal)
            ->assertCreated()->json('data.id');
        $this->postJson('/api/foundation/ledger/'.$id.'/reverse', ['reason' => 'Correct opening'], $reversal)
            ->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/foundation/ledger/'.$id.'/reverse', ['reason' => 'Duplicate reversal'],
            ['Idempotency-Key' => 'another-reversal'])->assertConflict();
        $this->postJson('/api/foundation/ledger/'.$reversed.'/reverse', ['reason' => 'Reverse reversal'],
            ['Idempotency-Key' => 'reversal-twice'])->assertUnprocessable();
        $this->getJson('/api/foundation/ledger?account_id='.$account)->assertJsonPath('balance', '0.20');
        $this->assertSame(3, DB::table('account_transactions')->count());
        $this->assertSame(3, DB::table('audit_logs')->where('table_name', 'account_transactions')->count());
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($v) => [$v], ['0', '-1', '1.001', '1e3', 'NaN', '99999999999999999', '']);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_ledger_amounts_never_write(string $amount): void
    {
        $this->actingAsAdmin();
        $account = $this->account($this->branch());
        $this->postJson('/api/foundation/ledger', $this->entry($account, ['amount' => $amount]),
            ['Idempotency-Key' => 'bad-amount-key'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('account_transactions', 0);
    }

    public function test_ledger_precision_rate_opening_and_inactive_guards(): void
    {
        $this->actingAsAdmin();
        $account = $this->account($this->branch());
        $this->postJson('/api/foundation/ledger', $this->entry($account), [])->assertUnprocessable();
        $this->postJson('/api/foundation/ledger', $this->entry($account, ['rate_to_base' => '2']),
            ['Idempotency-Key' => 'wrong-base-rate'])->assertUnprocessable();
        $this->postJson('/api/foundation/ledger', $this->entry($account),
            ['Idempotency-Key' => 'good-open-key'])->assertCreated();
        $this->postJson('/api/foundation/ledger', $this->entry($account),
            ['Idempotency-Key' => 'second-open-key'])->assertUnprocessable();
        DB::table('accounts')->where('id', $account)->update(['status' => 'inactive']);
        $this->postJson('/api/foundation/ledger', $this->entry($account, ['entry_type' => 'income']),
            ['Idempotency-Key' => 'inactive-key'])->assertUnprocessable();
    }

    public function test_database_itself_blocks_posted_ledger_mutation(): void
    {
        $this->actingAsAdmin();
        $account = $this->account($this->branch());
        $id = $this->postJson('/api/foundation/ledger', $this->entry($account),
            ['Idempotency-Key' => 'immutable-entry'])->assertCreated()->json('data.id');
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('account_transactions')->where('id', $id);
                $operation === 'update' ? $query->update(['amount' => '5']) : $query->delete();
                $this->fail('Database accepted a ledger mutation.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('immutable', $error->getMessage());
            }
        }
    }

    public function test_valid_settings_and_locale_changes_are_persistent_and_audited(): void
    {
        $user = $this->actingAsAdmin();
        $this->putJson('/api/foundation/settings', [
            'base_currency_id' => DB::table('currencies')->where('code', 'USD')->value('id'),
            'default_locale' => 'fa', 'timezone' => 'Asia/Kabul', 'reminder_days_before' => 5,
        ])->assertOk()->assertJsonPath('data.default_locale', 'fa');
        $this->patchJson('/api/profile/locale', ['locale' => 'fa'])->assertOk()
            ->assertJsonPath('data.locale', 'fa');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'locale' => 'fa']);
        $this->putJson('/api/foundation/settings', [
            'base_currency_id' => 9999, 'default_locale' => 'bad',
            'timezone' => 'Moon/Base', 'reminder_days_before' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'base_currency_id', 'default_locale', 'timezone', 'reminder_days_before',
        ]);
    }

    public function test_overdraft_and_unsafe_account_changes_never_write(): void
    {
        $this->actingAsAdmin();
        $branch = $this->branch();
        $account = $this->account($branch);
        $id = $this->postJson('/api/foundation/ledger', $this->entry($account),
            ['Idempotency-Key' => 'guard-opening'])->assertCreated()->json('data.id');
        $this->postJson('/api/foundation/ledger', $this->entry($account, [
            'entry_type' => 'expense', 'amount' => '100.11',
        ]), ['Idempotency-Key' => 'guard-overdraft'])->assertUnprocessable();
        $this->assertDatabaseCount('account_transactions', 1);
        $data = ['code' => 'TEST-CASH', 'name' => 'Cash', 'branch_id' => $branch,
            'currency_id' => DB::table('currencies')->where('code', 'USD')->value('id'),
            'account_type' => 'cash', 'status' => 'active'];
        $this->putJson('/api/foundation/accounts/'.$account,
            array_replace($data, ['status' => 'inactive']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->putJson('/api/foundation/accounts/'.$account, array_replace($data, [
            'currency_id' => DB::table('currencies')->where('code', 'AFN')->value('id'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('currency_id');
        $this->postJson('/api/foundation/ledger/'.$id.'/reverse', ['reason' => 'Zero account'],
            ['Idempotency-Key' => 'guard-reverse'])->assertCreated();
        $this->putJson('/api/foundation/accounts/'.$account,
            array_replace($data, ['status' => 'inactive']))->assertOk();
    }

    public function test_fx_precision_and_idempotency_survive_a_date_change(): void
    {
        $this->actingAsAdmin();
        $account = $this->account($this->branch());
        DB::table('accounts')->where('id', $account)->update([
            'currency_id' => DB::table('currencies')->where('code', 'AFN')->value('id'),
        ]);
        $id = $this->postJson('/api/foundation/ledger', $this->entry($account, [
            'rate_to_base' => '123456789012.12345678',
        ]), ['Idempotency-Key' => 'large-fx-test'])->assertCreated()
            ->assertJsonPath('data.rate_to_base', '123456789012.12345678')->json('data.id');
        $this->postJson('/api/foundation/ledger/'.$id.'/reverse', ['reason' => 'FX correction'],
            ['Idempotency-Key' => 'fx-reversal-test'])->assertCreated();
        $this->travel(2)->days();
        $this->postJson('/api/foundation/ledger/'.$id.'/reverse', ['reason' => 'FX correction'],
            ['Idempotency-Key' => 'fx-reversal-test'])->assertOk()->assertJsonPath('replayed', true);
        $this->travelBack();
        $this->assertDatabaseCount('account_transactions', 2);
    }

    public function test_audit_failure_rolls_back_the_ledger_transaction(): void
    {
        $actor = $this->actingAsAdmin();
        $account = $this->account($this->branch());
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'insert into `audit_logs`')) {
                throw new \RuntimeException('Simulated audit storage failure');
            }
        });
        try {
            app(LedgerService::class)->post($actor,
                $this->entry($account), 'rollback-entry-test');
            $this->fail('Transaction should fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Simulated', $error->getMessage());
        }
        $this->assertDatabaseCount('account_transactions', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('table_name', 'account_transactions')->count());
    }

    public function test_user_updates_revoke_existing_sessions_and_login_tokens_expire(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create();
        $role = Role::where('name', 'Accountant')->value('id');
        $target->createToken('old-session');
        $this->putJson('/api/foundation/users/'.$target->id, [
            'name' => $target->name, 'username' => $target->username,
            'locale' => 'en', 'is_active' => true, 'role_ids' => [$role],
            'branch_ids' => [$this->branch()], 'facility_ids' => [],
        ])->assertOk();
        $this->assertSame(0, $target->tokens()->count());
        auth()->forgetGuards();
        $target->update(['password_hash' => Hash::make('ExpiryTestPassword!42')]);
        $this->postJson('/api/login', ['username' => $target->username,
            'password' => 'ExpiryTestPassword!42'])->assertOk();
        $expiry = $target->tokens()->firstOrFail()->expires_at;
        $this->assertTrue($expiry->between(now()->addHours(11), now()->addHours(13)));
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $username = 'throttle_'.bin2hex(random_bytes(4));
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['username' => $username, 'password' => 'wrong'])
                ->assertUnauthorized();
        }
        $this->postJson('/api/login', ['username' => $username, 'password' => 'wrong'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }
}
