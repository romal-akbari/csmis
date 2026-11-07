<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD UNIQUE KEY users_employee_unique (employee_id)');
        DB::statement('ALTER TABLE account_transactions MODIFY exchange_rate DECIMAL(20,8) NULL');
        DB::statement('ALTER TABLE account_transactions ADD CONSTRAINT ledger_required_rate '
            .'CHECK (rate_to_base IS NOT NULL AND rate_to_base > 0)');
        DB::statement('ALTER TABLE account_transactions ADD CONSTRAINT ledger_entry_shape CHECK ('
            ."(entry_type = 'reversal' AND reversal_of_id IS NOT NULL) OR "
            ."(entry_type IN ('opening', 'income', 'expense') AND reversal_of_id IS NULL))");
        DB::statement('ALTER TABLE account_transactions ADD CONSTRAINT ledger_direction CHECK ('
            ."(entry_type IN ('opening', 'income') AND direction = 'in') OR "
            ."(entry_type = 'expense' AND direction = 'out') OR entry_type = 'reversal')");
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward migration to preserve financial history.');
    }
};
