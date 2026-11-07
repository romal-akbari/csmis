<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_workflow_defaults', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('branch_id')->unique();
            $t->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $t->foreignId('materials_location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->foreignId('finished_location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->unsignedInteger('facility_id');
            $t->foreign('facility_id')->references('id')->on('washing_facilities')->restrictOnDelete();
            $t->foreignId('facility_location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->string('payment_trigger', 20)->default('stock_acceptance');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE production_workflow_defaults ADD CONSTRAINT chk_workflow_trigger CHECK (payment_trigger IN ('completion','stock_acceptance'))");
        Schema::table('manager_tool_movements', function (Blueprint $t) {
            $t->foreignId('production_order_id')->nullable()->constrained('manager_production_orders')->restrictOnDelete();
        });
        Schema::create('supply_purchases', function (Blueprint $t) {
            $t->id();
            $t->string('purchase_no', 40)->unique();
            $t->unsignedInteger('branch_id');
            $t->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('currency_id');
            $t->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
            $t->decimal('rate_to_base', 20, 8);
            $t->date('purchased_on');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('supply_purchase_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained('supply_purchases')->restrictOnDelete();
            $t->foreignId('material_lot_id')->nullable()->unique()->constrained('material_lots')->restrictOnDelete();
            $t->foreignId('tool_lot_id')->nullable()->unique()->constrained('production_tool_lots')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE supply_purchases ADD CONSTRAINT chk_supply_rate CHECK (rate_to_base>0)');
        DB::statement('ALTER TABLE supply_purchase_lines ADD CONSTRAINT chk_supply_source CHECK ((material_lot_id IS NOT NULL)+(tool_lot_id IS NOT NULL)=1)');
        foreach (['supply_purchases', 'supply_purchase_lines'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Supply purchase facts are immutable'");
            DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Supply purchase facts are immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve production and supply history. Use a reviewed forward migration.');
    }
};
