<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

class AutomaticCodesApiTest extends ApiTestCase
{
    private function account(array $overrides = []): array
    {
        return $overrides + ['name' => 'Office cash', 'currency_id' => DB::table('company_settings')->value('base_currency_id'),
            'account_type' => 'cash', 'status' => 'active', 'opening_amount' => '10000', 'opening_on' => '2026-01-10'];
    }

    public function test_account_generates_a_unique_code_and_posts_its_opening_balance_without_a_rate(): void
    {
        $this->actingAsAdmin();
        DB::table('document_sequences')->updateOrInsert(['kind' => 'ACC'], ['last_number' => 0]);
        $code = 'ACC-'.now()->format('Y').'-00000001';
        DB::table('accounts')->insert(['code' => $code, 'name' => 'Existing manual code', 'currency_id' => $this->account()['currency_id']]);
        $first = $this->postJson('/api/foundation/accounts', $this->account())->assertCreated()->json('data');
        $second = $this->postJson('/api/foundation/accounts', $this->account(['opening_amount' => '0', 'code' => null]))->assertCreated()->json('data');
        $this->assertNotSame($code, $first['code']);
        $this->assertNotSame($first['code'], $second['code']);
        $this->assertMatchesRegularExpression('/^ACC-\d{4}-\d{8}$/', $first['code']);
        $this->assertDatabaseHas('account_transactions', ['account_id' => $first['id'], 'amount' => '10000', 'rate_to_base' => '1']);
        $this->assertSame('10000.00', DB::table('v_account_balances')->where('account_id', $first['id'])->value('balance'));
        $this->assertSame(0, DB::table('account_transactions')->where('account_id', $second['id'])->count());
    }

    public function test_foreign_opening_uses_the_latest_saved_direct_or_inverse_rate_at_the_opening_date(): void
    {
        $this->actingAsAdmin();
        $base = $this->account()['currency_id'];
        $foreign = DB::table('currencies')->insertGetId(['code' => 'ACX', 'name' => 'Account test currency', 'symbol' => 'X', 'is_active' => true]);
        foreach (['2026-01-01' => '60', '2026-01-09' => '70', '2026-01-11' => '80'] as $date => $rate) {
            DB::table('exchange_rates')->insert(['base_currency_id' => $foreign, 'quote_currency_id' => $base, 'rate' => $rate, 'effective_date' => $date]);
        }
        $id = $this->postJson('/api/foundation/accounts', $this->account(['currency_id' => $foreign, 'opening_amount' => '100']))->assertCreated()->json('data.id');
        $this->assertDatabaseHas('account_transactions', ['account_id' => $id, 'amount' => '100', 'rate_to_base' => '70']);
        DB::table('exchange_rates')->insert(['base_currency_id' => $base, 'quote_currency_id' => $foreign, 'rate' => '0.02', 'effective_date' => '2026-01-10']);
        $id = $this->postJson('/api/foundation/accounts', $this->account(['currency_id' => $foreign, 'opening_amount' => '100']))->assertCreated()->json('data.id');
        $this->assertDatabaseHas('account_transactions', ['account_id' => $id, 'rate_to_base' => '50']);
    }

    public function test_missing_rate_or_invalid_opening_rolls_back_the_account_and_sequence(): void
    {
        $this->actingAsAdmin();
        $foreign = DB::table('currencies')->insertGetId(['code' => 'ACY', 'name' => 'No rate currency', 'symbol' => 'Y', 'is_active' => true]);
        $before = DB::table('accounts')->count();
        $sequence = DB::table('document_sequences')->where('kind', 'ACC')->value('last_number');
        $this->postJson('/api/foundation/accounts', $this->account(['currency_id' => $foreign]))->assertUnprocessable()->assertJsonValidationErrors('currency_id');
        foreach (['-1', '1.001', '1e3'] as $amount) $this->postJson('/api/foundation/accounts', $this->account(['opening_amount' => $amount]))->assertUnprocessable();
        $this->postJson('/api/foundation/accounts', $this->account(['opening_rate' => '2']))->assertUnprocessable();
        $this->assertSame($before, DB::table('accounts')->count());
        $this->assertSame($sequence, DB::table('document_sequences')->where('kind', 'ACC')->value('last_number'));
    }

    public function test_foundation_manager_material_and_tool_codes_are_optional_but_supplied_codes_are_checked(): void
    {
        $this->actingAsAdmin();
        $branch = $this->postJson('/api/foundation/branches', ['name' => 'Auto branch', 'is_main' => false, 'status' => 'active'])->assertCreated()->json('data');
        $this->assertStringStartsWith('BRN-', $branch['code']);
        $location = $this->postJson('/api/foundation/locations', ['name' => 'Auto store', 'branch_id' => $branch['id'], 'location_type' => 'warehouse', 'status' => 'active'])->assertCreated()->json('data');
        $this->assertStringStartsWith('LOC-', $location['code']);
        foreach ([['/production/managers', 'production_managers', 'MGR', ['branch_id' => $branch['id']]],
            ['/production/tools', 'production_tools', 'TOOL', []], ['/business/materials', 'materials', 'ITEM', ['unit' => 'kg']]] as [$path, $table, $prefix, $extra]) {
            $data = $extra + ['name' => 'Auto '.$prefix, 'status' => 'active'];
            $headers = ['Idempotency-Key' => 'auto-code-'.$prefix];
            $id = $this->postJson('/api'.$path, $data, $headers)->assertCreated()->json('data.id');
            $this->postJson('/api'.$path, $data, $headers)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('replayed', true);
            $this->assertStringStartsWith($prefix.'-', DB::table($table)->where('id', $id)->value('code'));
            $this->postJson('/api'.$path, $data + ['code' => 'bad code'], ['Idempotency-Key' => 'bad-code-'.$prefix])->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->postJson('/api/foundation/currencies', ['name' => 'Currency needs ISO code', 'symbol' => 'A', 'decimal_places' => 2, 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('code');
    }
}
