<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * §17 — one administrator account so the API can be used immediately.
 * Existing credentials are preserved. Fresh installs use an explicit secret.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $existing = User::where('username', 'admin')->first();
        $password = env('FOUNDATION_ADMIN_PASSWORD');
        if (! $existing && (! is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72)) {
            throw new \RuntimeException('Set FOUNDATION_ADMIN_PASSWORD to 12 or more characters, within 72 UTF-8 bytes.');
        }
        $admin = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'System Administrator',
                'email' => 'admin@csms.local',
                'password_hash' => $existing?->password_hash ?? Hash::make($password),
                'locale' => 'en',
                'is_active' => true,
            ],
        );

        $role = DB::table('roles')
            ->where('name', 'Administrator')
            ->first();

        if ($role) {
            DB::table('user_roles')->updateOrInsert([
                'user_id' => $admin->id,
                'role_id' => $role->id,
            ]);
        }
    }
}
