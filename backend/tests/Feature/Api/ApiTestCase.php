<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * All API tests run against the real MariaDB test database
 * (csms_phase1_test) and are rolled back after every test.
 */
abstract class ApiTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('csms_phase1_test',
            DB::selectOne('SELECT DATABASE() AS name')->name,
            'Refusing to run fixture mutations against a non-test database.');
    }

    /** Authenticate the request as a freshly created active admin. */
    protected function actingAsAdmin(): User
    {
        $user = User::factory()->create();

        // §17 — tests need the role that holds every permission.
        $role = Role::firstOrCreate(
            ['name' => 'Administrator'],
            ['description' => 'Full system access'],
        );
        $user->roles()->attach($role);

        Sanctum::actingAs($user);

        return $user;
    }

    /** Arrange an earlier receipt without reopening its retired HTTP entry point in the application. */
    protected function historicalFixture(callable $fixture): mixed
    {
        $this->withoutMiddleware(\App\Http\Middleware\EnsureProductionWorkflow::class);
        try {
            return $fixture();
        } finally {
            $this->withMiddleware(\App\Http\Middleware\EnsureProductionWorkflow::class);
        }
    }

    /**
     * A payload that passes every customer rule.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validCustomer(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Zahra Ahmadi',
            'phone' => '+93 70 123 4567',
            'address' => 'Kart-e-Parwan, Kabul',
            'customer_type' => 'retail',
            'registration_date' => '2026-09-01',
            'notes' => 'Prefers hand-knotted wool rugs.',
            'status' => 'active',
        ], $overrides);
    }
}
