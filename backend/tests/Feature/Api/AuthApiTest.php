<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * §17 / §24 — token authentication (Sanctum) validation matrix.
 */
class AuthApiTest extends ApiTestCase
{
    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(
            $overrides + ['password_hash' => Hash::make('secret-1234')],
        );
    }

    public function test_login_returns_a_usable_token(): void
    {
        $this->makeUser(['username' => 'farida']);

        $response = $this->postJson('/api/login', [
            'username' => 'farida',
            'password' => 'secret-1234',
        ])->assertOk();

        $response->assertJsonStructure(['token', 'user' => ['id', 'username']]);
        $this->assertSame('farida', $response->json('user.username'));

        $token = $response->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'farida');
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        $this->makeUser(['username' => 'farida']);

        $this->postJson('/api/login', [
            'username' => 'farida',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_login_rejects_an_unknown_username(): void
    {
        $this->postJson('/api/login', [
            'username' => 'ghost',
            'password' => 'whatever-123',
        ])->assertUnauthorized();
    }

    public function test_login_requires_username_and_password(): void
    {
        $this->postJson('/api/login', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_login_rejects_an_inactive_account(): void
    {
        $this->makeUser([
            'username' => 'suspended',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'username' => 'suspended',
            'password' => 'secret-1234',
        ])->assertForbidden();
    }

    public function test_successful_login_is_audited(): void
    {
        $user = $this->makeUser(['username' => 'audited']);

        $this->postJson('/api/login', [
            'username' => 'audited',
            'password' => 'secret-1234',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'login',
            'table_name' => 'users',
            'record_id' => $user->id,
        ]);
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $this->makeUser(['username' => 'signout']);

        $token = $this->postJson('/api/login', [
            'username' => 'signout',
            'password' => 'secret-1234',
        ])->assertOk()->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk();

        // Simulate a fresh request cycle: within a single test the auth
        // guard instance would otherwise remember the old user/token.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_audit_log_records_logout_for_the_user(): void
    {
        $user = $this->makeUser(['username' => 'byebye']);
        Sanctum::actingAs($user);

        $this->postJson('/api/logout')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'logout',
            'table_name' => 'users',
            'record_id' => $user->id,
        ]);
    }
}
