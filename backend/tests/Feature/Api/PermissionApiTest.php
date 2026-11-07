<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * §17 — RBAC: /me exposes permission codes, and every customer
 * route is gated by its own permission code.
 */
class PermissionApiTest extends ApiTestCase
{
    public function test_me_returns_roles_and_permission_codes(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/me');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'username', 'locale',
                    'roles', 'permissions',
                ],
            ]);

        $this->assertContains(
            'customers.view',
            $response->json('data.permissions'),
        );
        $this->assertContains(
            'Administrator',
            $response->json('data.roles'),
        );
    }

    public function test_user_without_roles_is_denied(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.permissions', []);

        $this->getJson('/api/customers')->assertForbidden();

        $this->postJson('/api/customers', $this->validCustomer())
            ->assertForbidden();
    }

    public function test_view_only_role_can_list_but_not_write(): void
    {
        $customer = Customer::create(['name' => 'Permission Probe']);

        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['name' => 'Restricted Viewer'],
            ['description' => 'Test-only read role'],
        );
        $user->roles()->attach($role);
        $role->permissions()->attach(
            Permission::where('code', 'customers.view')->firstOrFail(),
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/customers')->assertOk();

        $this->postJson('/api/customers', $this->validCustomer())
            ->assertForbidden();

        $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'Should Not Save',
        ])->assertForbidden();

        $this->deleteJson("/api/customers/{$customer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Permission Probe',
        ]);
    }

    public function test_update_denied_before_validation(): void
    {
        $customer = Customer::create(['name' => 'Validation Shield']);

        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Lister']);
        $user->roles()->attach($role);
        $role->permissions()->attach(
            Permission::where('code', 'customers.view')->firstOrFail(),
        );

        Sanctum::actingAs($user);

        // Blank payload: 403 permission beats 422 validation.
        $this->putJson("/api/customers/{$customer->id}", [])
            ->assertForbidden();
    }
}
