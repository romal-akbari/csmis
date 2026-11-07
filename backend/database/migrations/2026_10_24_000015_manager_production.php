<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function actor(Blueprint $t): void
    {
        $t->unsignedBigInteger('created_by');
        $t->timestamp('created_at');
        $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::create('manager_production_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_no', 40)->unique();
            $t->foreignId('manager_id')->constrained('production_managers')->restrictOnDelete();
            $t->unsignedInteger('branch_id');
            $t->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $t->unsignedInteger('currency_id');
            $t->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
            $t->decimal('rate_to_base', 20, 8);
            $t->date('ordered_on');
            $t->date('due_on');
            $t->string('description', 1000);
            $t->string('payment_trigger', 20);
            $this->actor($t);
        });
        Schema::create('manager_production_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained('manager_production_orders')->restrictOnDelete();
            $t->string('name', 150);
            $t->unsignedInteger('category_id');
            $t->foreign('category_id')->references('id')->on('carpet_categories')->restrictOnDelete();
            $t->unsignedInteger('target_quantity');
            $t->decimal('length_cm', 10, 2)->nullable();
            $t->decimal('width_cm', 10, 2)->nullable();
            $t->string('pricing_method', 10);
            $t->decimal('rate', 18, 2);
            $t->string('manual_reason', 500)->nullable();
            $t->string('notes', 1000)->nullable();
            $this->actor($t);
        });
        Schema::create('manager_pricing_factors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained('manager_production_orders')->restrictOnDelete();
            $t->foreignId('line_id')->nullable()->constrained('manager_production_lines')->restrictOnDelete();
            $t->string('label', 150);
            $t->string('kind', 20);
            $t->string('operation', 10);
            $t->decimal('value', 20, 6);
            $t->decimal('quantity', 18, 6)->nullable();
            $t->unsignedSmallInteger('position');
            $this->actor($t);
        });
        Schema::create('manager_production_deliveries', function (Blueprint $t) {
            $t->id();
            $t->string('delivery_no', 40)->unique();
            $t->foreignId('production_order_id')->constrained('manager_production_orders')->restrictOnDelete();
            $t->date('delivered_on');
            $t->decimal('charge_amount', 18, 2);
            $t->decimal('material_cost_base', 18, 2);
            $t->decimal('total_cost_base', 18, 2);
            $this->actor($t);
        });
        Schema::create('manager_delivery_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('delivery_id')->constrained('manager_production_deliveries')->restrictOnDelete();
            $t->foreignId('production_order_id')->constrained('manager_production_orders')->restrictOnDelete();
            $t->foreignId('line_id')->constrained('manager_production_lines')->restrictOnDelete();
            $t->foreignId('carpet_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('length_cm', 10, 2);
            $t->decimal('width_cm', 10, 2);
            $t->decimal('area_m2', 18, 6);
            $t->decimal('charge_amount', 18, 2);
            $t->decimal('cost_base', 18, 2);
            $this->actor($t);
        });
        Schema::create('manager_delivery_materials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('delivery_id')->constrained('manager_production_deliveries')->restrictOnDelete();
            $t->foreignId('movement_id')->unique()->constrained('manager_material_movements')->restrictOnDelete();
            $this->actor($t);
        });
        Schema::create('manager_earned_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained('manager_production_orders')->restrictOnDelete();
            $t->foreignId('item_id')->unique()->constrained('manager_delivery_items')->restrictOnDelete();
            $t->date('earned_on');
            $t->decimal('amount', 18, 2);
            $this->actor($t);
        });
        Schema::create('manager_delivery_acceptances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->unique()->constrained('manager_delivery_items')->restrictOnDelete();
            $t->foreignId('washing_order_item_id')->unique()->constrained()->restrictOnDelete();
            $t->date('accepted_on');
            $this->actor($t);
        });
        Schema::create('manager_production_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained('manager_production_orders')->restrictOnDelete();
            $t->foreignId('account_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->string('payment_type', 12);
            $t->decimal('document_amount', 18, 2);
            $t->decimal('units_per_payment_unit', 20, 8);
            $this->actor($t);
        });
        Schema::create('manager_production_closures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->unique()->constrained('manager_production_orders')->restrictOnDelete();
            $t->date('closed_on');
            $t->string('reason', 500);
            $this->actor($t);
        });
        foreach ([
            'manager_production_orders' => "rate_to_base>0 AND due_on>=ordered_on AND payment_trigger IN ('completion','stock_acceptance')",
            'manager_production_lines' => "target_quantity>0 AND rate>=0 AND pricing_method IN ('area','piece','fixed','manual') AND (pricing_method<>'manual' OR (manual_reason IS NOT NULL AND CHAR_LENGTH(manual_reason)>=3)) AND ((length_cm IS NULL AND width_cm IS NULL) OR (length_cm IS NOT NULL AND width_cm IS NOT NULL AND length_cm>0 AND width_cm>0))",
            'manager_pricing_factors' => "value>=0 AND operation IN ('add','deduct') AND kind IN ('fixed','per_piece','per_area','rate_quantity','percentage','multiplier') AND (kind<>'rate_quantity' OR (quantity IS NOT NULL AND quantity>0)) AND (kind<>'multiplier' OR (operation='add' AND value>0))",
            'manager_production_deliveries' => 'charge_amount>=0 AND material_cost_base>=0 AND total_cost_base>0',
            'manager_delivery_items' => 'length_cm>0 AND width_cm>0 AND area_m2>0 AND charge_amount>=0 AND cost_base>=0',
            'manager_earned_charges' => 'amount>=0',
            'manager_production_payments' => "document_amount>0 AND units_per_payment_unit>0 AND payment_type IN ('production','advance')",
        ] as $table => $condition) {
            DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT chk_'.$table.' CHECK ('.$condition.')');
        }
        foreach (['manager_production_orders', 'manager_production_lines', 'manager_pricing_factors', 'manager_production_deliveries',
            'manager_delivery_items', 'manager_delivery_materials', 'manager_earned_charges', 'manager_delivery_acceptances',
            'manager_production_payments', 'manager_production_closures'] as $table) {
            DB::unprepared('CREATE TRIGGER '.$table.'_no_update BEFORE UPDATE ON '.$table." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'");
            DB::unprepared('CREATE TRIGGER '.$table.'_no_delete BEFORE DELETE ON '.$table." FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve production custody and cost history. Use a reviewed forward migration.');
    }
};
