<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

class Phase2CustomerApiTest extends ApiTestCase
{
    public function test_dates_are_exact_real_business_dates_with_a_mysql_safe_minimum(): void
    {
        $this->actingAsAdmin();
        DB::table('company_settings')->where('id', 1)->update(['timezone' => 'Asia/Kabul']);
        $this->travelTo(Carbon::parse('2026-10-16 22:00:00', 'UTC'));

        foreach (['2026-02-30', '2025-02-29', '26-01-01', '2026-1-01',
            '2026-01-01T00:00:00Z', '0999-12-31', '2026-10-18'] as $date) {
            $this->postJson('/api/customers', $this->validCustomer(['registration_date' => $date]))
                ->assertUnprocessable()->assertJsonValidationErrors('registration_date');
        }
        foreach (['1000-01-01', '2024-02-29', '2026-10-17'] as $date) {
            $this->postJson('/api/customers', $this->validCustomer(['registration_date' => $date]))
                ->assertCreated()->assertJsonPath('data.registration_date', $date);
        }
        $this->getJson('/api/customers')->assertOk()
            ->assertJsonPath('company_timezone', 'Asia/Kabul')
            ->assertJsonPath('company_today', '2026-10-17');
    }

    public function test_partial_updates_reject_null_required_values_and_preserve_omitted_fields(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create($this->validCustomer());
        foreach (['name', 'customer_type', 'registration_date', 'status'] as $field) {
            $this->patchJson("/api/customers/{$customer->id}", [$field => null])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson("/api/customers/{$customer->id}", ['notes' => null])
            ->assertOk()->assertJsonPath('data.name', 'Zahra Ahmadi')->assertJsonPath('data.notes', null);
    }

    public function test_unicode_lengths_and_server_owned_attributes_cannot_be_overridden(): void
    {
        $user = $this->actingAsAdmin();
        $this->postJson('/api/customers', $this->validCustomer(['name' => str_repeat('م', 151)]))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $response = $this->postJson('/api/customers', $this->validCustomer([
            'name' => str_repeat('🙂', 150), 'created_by' => 999999,
            'id' => 999999, 'deleted_at' => now()->toDateTimeString(),
        ]))->assertCreated()->assertJsonPath('data.created_by', $user->id)
            ->assertJsonPath('data.deleted_at', null);
        $id = $response->json('data.id');
        $this->assertNotSame(999999, $id);
        $this->patchJson("/api/customers/{$id}", ['created_by' => 999999, 'id' => 999999])
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.created_by', $user->id);
    }

    public function test_archive_retains_id_and_history_and_restore_keeps_the_customer_inactive(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create($this->validCustomer());
        $this->deleteJson("/api/customers/{$customer->id}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $customer->id, 'status' => 'inactive']);
        $this->getJson('/api/customers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/customers?archived=only')->assertOk()
            ->assertJsonPath('data.0.id', $customer->id)->assertJsonPath('data.0.status', 'inactive');
        $this->patchJson("/api/customers/{$customer->id}", ['name' => 'Hidden'])->assertNotFound();
        $this->postJson("/api/customers/{$customer->id}/restore")->assertOk()
            ->assertJsonPath('data.id', $customer->id)->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.deleted_at', null);
        $this->postJson("/api/customers/{$customer->id}/restore")->assertNotFound();
        $audit = AuditLog::where('table_name', 'customers')->where('record_id', $customer->id)
            ->where('action', 'update')->firstOrFail();
        $this->assertNotNull($audit->old_values['deleted_at']);
        $this->assertNull($audit->new_values['deleted_at']);
        $this->assertSame('Zahra Ahmadi', $audit->new_values['name']);
    }

    public function test_search_status_type_and_pagination_are_combined_without_showing_archived_rows(): void
    {
        $this->actingAsAdmin();
        for ($i = 1; $i <= 7; $i++) {
            Customer::create($this->validCustomer(['name' => "Matching Client {$i}", 'customer_type' => 'vip', 'status' => 'inactive']));
        }
        Customer::create($this->validCustomer(['name' => 'Matching Active Client', 'customer_type' => 'vip']));
        $archived = Customer::create($this->validCustomer(['name' => 'Matching Archived Client', 'customer_type' => 'vip', 'status' => 'inactive']));
        $archived->delete();
        $this->getJson('/api/customers?search=Matching&status=inactive&customer_type=vip&per_page=5&page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 7)
            ->assertJsonPath('current_page', 2)->assertJsonPath('last_page', 2);
        foreach (['page=0', 'page=-1', 'page=no', 'archived=all'] as $invalid) {
            $this->getJson('/api/customers?'.$invalid)->assertUnprocessable();
        }
    }

    public function test_every_customer_action_requires_its_exact_permission_even_for_shared_directory_rows(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Customer view only '.uniqid()]);
        $role->permissions()->attach(Permission::where('code', 'customers.view')->firstOrFail());
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
        $customer = Customer::create($this->validCustomer());
        $this->getJson('/api/customers')->assertOk()->assertJsonPath('data.0.id', $customer->id);
        $this->getJson("/api/customers/{$customer->id}")->assertOk();
        $this->postJson('/api/customers', $this->validCustomer())->assertForbidden();
        $this->patchJson("/api/customers/{$customer->id}", ['name' => 'Forbidden'])->assertForbidden();
        $this->deleteJson("/api/customers/{$customer->id}")->assertForbidden();
        $customer->delete();
        $this->postJson("/api/customers/{$customer->id}/restore")->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_customer_mutations_roll_back_if_the_required_audit_write_fails(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create($this->validCustomer());
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditLog::class, function () {
            throw new RuntimeException('Simulated audit storage failure');
        });
        try {
            foreach (['create', 'update', 'archive'] as $operation) {
                try {
                    match ($operation) {
                        'create' => $this->postJson('/api/customers', $this->validCustomer()),
                        'update' => $this->patchJson("/api/customers/{$customer->id}", ['name' => 'Must roll back']),
                        'archive' => $this->deleteJson("/api/customers/{$customer->id}"),
                    };
                    $this->fail('Mutation succeeded without an audit row.');
                } catch (RuntimeException $error) {
                    $this->assertSame('Simulated audit storage failure', $error->getMessage());
                }
                $this->assertDatabaseCount('customers', 1);
                $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Zahra Ahmadi', 'status' => 'active', 'deleted_at' => null]);
                $this->assertDatabaseCount('audit_logs', 0);
            }
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }
    }
}
