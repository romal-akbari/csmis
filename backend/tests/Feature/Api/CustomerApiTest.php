<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Customer;

/**
 * §4 Customers module — validation matrix:
 * required, length, enum, date, regex, 404, 422, audit trail.
 */
class CustomerApiTest extends ApiTestCase
{
    // ------------------------------------------------------------------
    // Authentication guard
    // ------------------------------------------------------------------

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/customers', $this->validCustomer())
            ->assertUnauthorized();

        $this->assertDatabaseCount('customers', 0);
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    public function test_index_returns_empty_paginated_list(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/customers')->assertOk();

        $this->assertSame([], $response->json('data'));
        $response->assertJsonStructure([
            'current_page', 'data', 'per_page', 'total',
        ]);
    }

    public function test_index_returns_created_customers_newest_first(): void
    {
        $user = $this->actingAsAdmin();
        Customer::create(
            $this->validCustomer(['name' => 'Older Client'])
            + ['created_by' => $user->id],
        );
        Customer::create(
            $this->validCustomer(['name' => 'Newer Client'])
            + ['created_by' => $user->id],
        );

        $response = $this->getJson('/api/customers')->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(
            'Newer Client',
            $response->json('data.0.name'),
        );
    }

    public function test_show_returns_a_single_customer(): void
    {
        $user = $this->actingAsAdmin();
        $customer = Customer::create(
            $this->validCustomer() + ['created_by' => $user->id],
        );

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Zahra Ahmadi');
    }

    public function test_show_returns_404_for_unknown_customer(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/customers/999999')->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Create — happy paths
    // ------------------------------------------------------------------

    public function test_store_creates_customer_and_writes_audit_log(): void
    {
        $user = $this->actingAsAdmin();

        $response = $this->postJson(
            '/api/customers',
            $this->validCustomer(),
        )->assertCreated();

        $response->assertJsonPath('data.name', 'Zahra Ahmadi')
            ->assertJsonPath('data.customer_type', 'retail')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.created_by', $user->id);

        $this->assertDatabaseHas('customers', [
            'name' => 'Zahra Ahmadi',
            'phone' => '+93 70 123 4567',
        ]);

        $audit = AuditLog::where('action', 'create')
            ->where('table_name', 'customers')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(
            'Zahra Ahmadi',
            $audit->new_values['name'] ?? null,
        );
    }

    public function test_store_applies_defaults_when_optionals_omitted(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/customers', [
            'name' => 'Walk-in Buyer',
            'customer_type' => 'retail',
            'registration_date' => '2026-09-01',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.address', null);
    }

    // ------------------------------------------------------------------
    // Create — validation matrix
    // ------------------------------------------------------------------

    public function test_name_is_required(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer();
        unset($payload['name']);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_name_must_be_at_least_two_characters(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer(['name' => 'A']);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_name_must_not_exceed_150_characters(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer([
            'name' => str_repeat('x', 151),
        ]);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_customer_type_is_required(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer();
        unset($payload['customer_type']);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_type']);
    }

    public function test_customer_type_must_be_a_valid_enum(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer([
            'customer_type' => 'gold-member',
        ]);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_type']);
    }

    public function test_status_must_be_a_valid_enum(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer(['status' => 'pending']);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_registration_date_is_required(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer();
        unset($payload['registration_date']);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registration_date']);
    }

    public function test_registration_date_must_be_a_real_date(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer([
            'registration_date' => 'not-a-date',
        ]);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registration_date']);
    }

    public function test_registration_date_cannot_be_in_the_future(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer([
            'registration_date' => now()->addDay()->toDateString(),
        ]);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registration_date']);
    }

    public function test_phone_must_match_the_allowed_format(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer(['phone' => 'call-me-maybe']);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_phone_must_not_exceed_30_characters(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer([
            'phone' => str_repeat('9', 31),
        ]);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_address_must_not_exceed_255_characters(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer([
            'address' => str_repeat('a', 256),
        ]);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address']);
    }

    public function test_notes_must_not_exceed_2000_characters(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validCustomer([
            'notes' => str_repeat('n', 2001),
        ]);

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_invalid_payload_creates_nothing(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/customers', [
            'name' => '',
            'customer_type' => 'invalid',
            'registration_date' => 'tomorrow-ish',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('customers', 0);
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_changes_fields_and_audits_both_states(): void
    {
        $user = $this->actingAsAdmin();
        $customer = Customer::create(
            $this->validCustomer() + ['created_by' => $user->id],
        );

        $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'Zahra A. Khan',
            'customer_type' => 'vip',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Zahra A. Khan')
            ->assertJsonPath('data.customer_type', 'vip');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Zahra A. Khan',
            'customer_type' => 'vip',
        ]);

        $audit = AuditLog::where('action', 'update')
            ->where('record_id', $customer->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('Zahra Ahmadi', $audit->old_values['name']);
        $this->assertSame('Zahra A. Khan', $audit->new_values['name']);
    }

    public function test_update_rejects_invalid_enum(): void
    {
        $user = $this->actingAsAdmin();
        $customer = Customer::create(
            $this->validCustomer() + ['created_by' => $user->id],
        );

        $this->putJson("/api/customers/{$customer->id}", [
            'customer_type' => 'gold-member',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_type']);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'customer_type' => 'retail',
        ]);
    }

    public function test_update_rejects_a_blank_name(): void
    {
        $user = $this->actingAsAdmin();
        $customer = Customer::create(
            $this->validCustomer() + ['created_by' => $user->id],
        );

        $this->putJson("/api/customers/{$customer->id}", ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_update_rejects_a_future_registration_date(): void
    {
        $user = $this->actingAsAdmin();
        $customer = Customer::create(
            $this->validCustomer() + ['created_by' => $user->id],
        );

        $this->putJson("/api/customers/{$customer->id}", [
            'registration_date' => now()->addDays(3)->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['registration_date']);
    }

    public function test_update_returns_404_for_unknown_customer(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/customers/999999', ['name' => 'Nobody'])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Delete (soft)
    // ------------------------------------------------------------------

    public function test_destroy_soft_deletes_and_audits(): void
    {
        $user = $this->actingAsAdmin();
        $customer = Customer::create(
            $this->validCustomer() + ['created_by' => $user->id],
        );

        $this->deleteJson("/api/customers/{$customer->id}")
            ->assertOk();

        // Gone from the API …
        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/customers/{$customer->id}")
            ->assertNotFound();

        // … but still in the table (soft delete) with an audit row.
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete',
            'table_name' => 'customers',
            'record_id' => $customer->id,
            'user_id' => $user->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    public function test_index_filters_by_status(): void
    {
        $user = $this->actingAsAdmin();
        Customer::create(
            $this->validCustomer(['status' => 'active'])
            + ['created_by' => $user->id],
        );
        Customer::create(
            $this->validCustomer([
                'name' => 'Dormant Client',
                'status' => 'inactive',
            ]) + ['created_by' => $user->id],
        );

        $response = $this->getJson(
            '/api/customers?status=inactive',
        )->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Dormant Client');
    }

    public function test_index_search_matches_name_or_phone(): void
    {
        $user = $this->actingAsAdmin();
        Customer::create(
            $this->validCustomer(['name' => 'Kabul Rugs Co'])
            + ['created_by' => $user->id],
        );
        Customer::create(
            $this->validCustomer([
                'name' => 'Herat Trader',
                'phone' => '+93 79 555 0001',
            ]) + ['created_by' => $user->id],
        );

        $this->getJson('/api/customers?search=Kabul')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Kabul Rugs Co');

        $this->getJson('/api/customers?search=555')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Herat Trader');
    }

    public function test_index_rejects_unknown_filter_values(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/customers?status=banned')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/customers?customer_type=gold')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_type']);

        $this->getJson('/api/customers?per_page=3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_health_endpoint_stays_public(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }
}
