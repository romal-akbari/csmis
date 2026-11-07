<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adopt only EMPTY legacy inventory. Never guess conversions of business facts.
        foreach (['carpets', 'weaver_payments', 'carpet_purchases', 'carpet_purchase_items', 'stock_movements'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Reconcile legacy {$table} before inventory migration; no data was changed.");
            }
            if (in_array($table, ['carpets', 'weaver_payments'], true) && Schema::hasTable($table) && Schema::hasTable('legacy_'.$table)) {
                throw new RuntimeException("Review existing legacy_{$table} before migrating.");
            }
        }
        foreach (['inventory_commands', 'carpet_images', 'weaver_purchases', 'weaver_purchase_items',
            'stock_receipts', 'stock_transfers', 'stock_events', 'weaver_payment_allocations'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("Review pre-existing {$table} before inventory migration.");
            }
        }
        // Keep the old empty structures and their incoming FKs intact for later migration review.
        foreach (['carpets', 'weaver_payments'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::rename($table, 'legacy_'.$table);
            }
        }
        Schema::create('inventory_commands', function (Blueprint $t) {
            $t->string('idempotency_key', 100)->primary();
            $t->unsignedBigInteger('actor_id');
            $t->string('operation', 80);
            $t->char('request_hash', 64);
            $t->json('response');
            $t->timestamp('created_at');
            $t->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('carpets', function (Blueprint $t) {
            $t->id();
            $t->string('carpet_code', 40)->unique();
            $t->string('name', 150);
            $t->unsignedInteger('registration_branch_id');
            $t->unsignedInteger('category_id');
            $t->string('size_label', 60)->nullable();
            $t->decimal('length_cm', 10, 2)->nullable();
            $t->decimal('width_cm', 10, 2)->nullable();
            $t->string('color', 60)->nullable();
            $t->string('material', 100)->nullable();
            $t->string('notes', 1000)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->foreign('registration_branch_id')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('category_id')->references('id')->on('carpet_categories')->restrictOnDelete();
            foreach (['created_by', 'updated_by'] as $column) {
                $t->foreign($column)->references('id')->on('users')->restrictOnDelete();
            }
        });
        DB::statement('ALTER TABLE carpets ADD CONSTRAINT carpet_dimensions CHECK '
            .'((length_cm IS NULL AND width_cm IS NULL) OR (length_cm > 0 AND width_cm > 0))');
        Schema::create('carpet_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('carpet_id')->constrained()->restrictOnDelete();
            $t->string('storage_path', 500)->unique();
            $t->string('mime_type', 30);
            $t->string('alt_text', 255)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('weaver_purchases', function (Blueprint $t) {
            $t->id();
            $t->string('purchase_no', 40)->unique();
            $t->foreignId('weaver_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('branch_id');
            $t->date('purchase_on');
            $t->unsignedInteger('currency_id');
            $t->decimal('rate_to_base', 20, 8);
            $t->string('notes', 1000)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            foreach (['branch_id' => 'branches', 'currency_id' => 'currencies', 'created_by' => 'users'] as $col => $parent) {
                $t->foreign($col)->references('id')->on($parent)->restrictOnDelete();
            }
        });
        Schema::create('weaver_purchase_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('weaver_purchase_id')->constrained()->restrictOnDelete();
            $t->foreignId('carpet_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('unit_price', 18, 2);
        });
        Schema::create('stock_receipts', function (Blueprint $t) {
            $t->id();
            $t->string('receipt_no', 40)->unique();
            $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->date('received_on');
            $t->string('notes', 500)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('stock_transfers', function (Blueprint $t) {
            $t->id();
            $t->string('transfer_no', 40)->unique();
            foreach (['from_location_id', 'to_location_id'] as $col) {
                $t->foreignId($col)->constrained('stock_locations')->restrictOnDelete();
            }
            $t->date('transferred_on');
            $t->string('notes', 500)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('stock_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('carpet_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('sequence_no');
            $t->enum('event_type', ['purchase', 'receipt', 'transfer']);
            $t->enum('resulting_status', ['purchased', 'in_stock']);
            $t->foreignId('resulting_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $t->foreignId('weaver_purchase_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('stock_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('stock_transfer_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('occurred_on');
            $t->unsignedBigInteger('handled_by');
            $t->timestamp('created_at');
            $t->foreign('handled_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['carpet_id', 'sequence_no']);
            $t->unique(['stock_receipt_id', 'carpet_id']);
            $t->unique(['stock_transfer_id', 'carpet_id']);
        });
        Schema::create('weaver_payments', function (Blueprint $t) {
            $t->id();
            $t->string('payment_no', 40)->unique();
            $t->foreignId('weaver_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('branch_id');
            $t->foreignId('account_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->enum('payment_type', ['purchase', 'additional']);
            $t->unsignedInteger('expense_category_id')->nullable();
            $t->string('reason', 255);
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            foreach (['branch_id' => 'branches', 'expense_category_id' => 'expense_categories', 'created_by' => 'users'] as $col => $parent) {
                $t->foreign($col)->references('id')->on($parent)->restrictOnDelete();
            }
        });
        Schema::create('weaver_payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('weaver_payment_id')->constrained()->restrictOnDelete();
            $t->foreignId('weaver_purchase_id')->constrained()->restrictOnDelete();
            $t->decimal('amount_in_payment_currency', 18, 2);
            $t->decimal('purchase_units_per_payment_unit', 20, 8);
            $t->decimal('amount_in_purchase_currency', 18, 2);
            $t->unique(['weaver_payment_id', 'weaver_purchase_id'], 'uq_weaver_payment_purchase');
        });
        DB::statement('ALTER TABLE weaver_purchases ADD CONSTRAINT purchase_rate CHECK (rate_to_base > 0)');
        DB::statement('ALTER TABLE weaver_purchase_items ADD CONSTRAINT purchase_price CHECK (unit_price >= 0)');
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT distinct_locations CHECK (from_location_id <> to_location_id)');
        DB::statement("ALTER TABLE weaver_payments ADD CONSTRAINT bonus_category CHECK (payment_type <> 'additional' OR expense_category_id IS NOT NULL)");
        DB::statement('ALTER TABLE weaver_payment_allocations ADD CONSTRAINT positive_allocation CHECK '
            .'(amount_in_payment_currency > 0 AND amount_in_purchase_currency > 0 AND purchase_units_per_payment_unit > 0)');
        DB::statement("ALTER TABLE stock_events ADD CONSTRAINT event_source CHECK (sequence_no > 0 AND (
            (event_type = 'purchase' AND resulting_status = 'purchased' AND resulting_location_id IS NULL AND weaver_purchase_item_id IS NOT NULL AND stock_receipt_id IS NULL AND stock_transfer_id IS NULL) OR
            (event_type = 'receipt' AND resulting_status = 'in_stock' AND resulting_location_id IS NOT NULL AND weaver_purchase_item_id IS NULL AND stock_receipt_id IS NOT NULL AND stock_transfer_id IS NULL) OR
            (event_type = 'transfer' AND resulting_status = 'in_stock' AND resulting_location_id IS NOT NULL AND weaver_purchase_item_id IS NULL AND stock_receipt_id IS NULL AND stock_transfer_id IS NOT NULL)))");
        DB::unprepared('CREATE VIEW v_current_stock AS SELECT c.*, COALESCE(e.sequence_no,0) AS sequence_no, '
            ."COALESCE(e.resulting_status,'registered') AS stock_status, e.resulting_location_id AS location_id, "
            .'COALESCE(l.branch_id,p.branch_id,c.registration_branch_id) AS custody_branch_id, e.occurred_on '
            .'FROM carpets c LEFT JOIN stock_events e ON e.carpet_id=c.id AND NOT EXISTS '
            .'(SELECT 1 FROM stock_events newer WHERE newer.carpet_id=c.id AND newer.sequence_no>e.sequence_no) '
            .'LEFT JOIN stock_locations l ON l.id=e.resulting_location_id '
            .'LEFT JOIN weaver_purchase_items i ON i.carpet_id=c.id LEFT JOIN weaver_purchases p ON p.id=i.weaver_purchase_id');
        DB::unprepared('CREATE VIEW v_weaver_purchase_balances AS SELECT p.id AS purchase_id, '
            .'COALESCE(i.total,0) AS total_amount, COALESCE(a.paid,0) AS paid_amount, '
            .'COALESCE(i.total,0)-COALESCE(a.paid,0) AS remaining_amount FROM weaver_purchases p '
            .'LEFT JOIN (SELECT weaver_purchase_id,SUM(unit_price) total FROM weaver_purchase_items GROUP BY weaver_purchase_id) i ON i.weaver_purchase_id=p.id '
            .'LEFT JOIN (SELECT x.weaver_purchase_id,SUM(x.amount_in_purchase_currency) paid FROM weaver_payment_allocations x '
            .'JOIN weaver_payments w ON w.id=x.weaver_payment_id WHERE NOT EXISTS '
            .'(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=w.account_transaction_id) '
            .'GROUP BY x.weaver_purchase_id) a ON a.weaver_purchase_id=p.id');
        foreach (['inventory_commands', 'weaver_purchases', 'weaver_purchase_items', 'stock_receipts',
            'stock_transfers', 'stock_events', 'weaver_payments', 'weaver_payment_allocations'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $verb) {
                DB::unprepared('CREATE TRIGGER '.$table.'_no_'.strtolower($verb).' BEFORE '.$verb.' ON '.$table
                    ." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Inventory facts are preserved. Restore a verified backup or use a reviewed forward migration.');
    }
};
