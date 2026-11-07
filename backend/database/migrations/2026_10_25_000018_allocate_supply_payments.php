<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['material_lot_payments' => 'material', 'production_tool_payments' => 'tool'] as $table => $name) {
            // Existing standalone payments retain NULL and read their cash amount from the ledger.
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('amount_in_payment_currency', 18, 2)->nullable();
            });
            // The foreign key must keep a leading ledger index when its old unique index is replaced.
            DB::statement("ALTER TABLE {$table} ADD INDEX idx_{$name}_payment_ledger (account_transaction_id)");
            DB::statement("ALTER TABLE {$table} DROP INDEX {$table}_account_transaction_id_unique, ADD UNIQUE KEY uq_{$name}_lot_payment (lot_id, account_transaction_id)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$name}_payment_amount CHECK (amount_in_payment_currency IS NULL OR amount_in_payment_currency>=0)");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve supply payment allocations. Use a reviewed forward migration.');
    }
};
