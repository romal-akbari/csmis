<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ([['USD', 'US Dollar', '$'], ['AFN', 'Afghan Afghani', '؋']] as $row) {
                DB::table('currencies')->insertOrIgnore([
                    'code' => $row[0], 'name' => $row[1], 'symbol' => $row[2],
                    'decimal_places' => 2, 'is_active' => true,
                ]);
            }
            $base = DB::table('currencies')->where('is_default', true)->value('id')
                ?? DB::table('currencies')->where('code', 'USD')->value('id');
            DB::table('company_settings')->insertOrIgnore([
                'id' => 1, 'base_currency_id' => $base, 'default_locale' => 'en',
                'timezone' => 'Asia/Kabul', 'reminder_days_before' => 3,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $base = DB::table('company_settings')->where('id', 1)->value('base_currency_id');
            DB::table('currencies')->update(['is_default' => false]);
            DB::table('currencies')->where('id', $base)->update(['is_default' => true]);
            foreach (['Rent', 'Electricity', 'Transportation', 'Washing expenses',
                'Maintenance', 'Office expenses', 'Salary-related', 'Other'] as $name) {
                DB::table('expense_categories')->insertOrIgnore(['name' => $name]);
            }
        });
        $this->call(PermissionSeeder::class);
    }
}
