<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the minimum data the system needs to boot.
     */
    public function run(): void
    {
        $this->call([
            FoundationSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
