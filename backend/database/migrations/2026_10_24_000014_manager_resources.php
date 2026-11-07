<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function actor(Blueprint $t): void
    {
        $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
        $t->timestamp('created_at');
    }

    private function branch(Blueprint $t): void
    {
        $t->unsignedInteger('branch_id');
        $t->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
    }

    private function currency(Blueprint $t): void
    {
        $t->unsignedInteger('currency_id');
        $t->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
        $t->decimal('rate_to_base', 20, 8);
    }

    public function up(): void
    {
        Schema::create('production_managers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 150);
            $t->string('phone', 30)->nullable();
            $t->string('address', 500)->nullable();
            $this->branch($t);
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $t->string('status', 10)->default('active');
            $this->actor($t);
        });
        Schema::create('manager_material_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('manager_id')->constrained('production_managers')->restrictOnDelete();
            $t->foreignId('lot_id')->constrained('material_lots')->restrictOnDelete();
            // Added as a foreign key after the manager-production schema is installed.
            $t->unsignedBigInteger('production_order_id')->nullable()->index();
            $t->string('movement_type', 12);
            $t->decimal('quantity', 18, 3);
            $t->decimal('cost_base', 18, 2);
            $t->date('moved_on');
            $t->string('reason', 500);
            $t->index(['manager_id', 'lot_id']);
            $t->index(['lot_id', 'movement_type']);
            $this->actor($t);
        });
        Schema::create('production_tools', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 150);
            $t->string('status', 10)->default('active');
            $this->actor($t);
        });
        Schema::create('production_tool_lots', function (Blueprint $t) {
            $t->id();
            $t->string('lot_no', 40)->unique();
            $t->foreignId('tool_id')->constrained('production_tools')->restrictOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $this->branch($t);
            $this->currency($t);
            $t->unsignedBigInteger('quantity');
            $t->decimal('unit_cost', 18, 2);
            $t->decimal('total_cost', 18, 2);
            $t->decimal('total_cost_base', 18, 2);
            $t->date('received_on');
            $this->actor($t);
        });
        Schema::create('production_tool_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_id')->constrained('production_tool_lots')->restrictOnDelete();
            $t->foreignId('account_transaction_id')->unique()->constrained('account_transactions')->restrictOnDelete();
            $t->decimal('document_amount', 18, 2);
            $t->decimal('units_per_payment_unit', 20, 8);
            $this->actor($t);
        });
        Schema::create('manager_tool_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('manager_id')->constrained('production_managers')->restrictOnDelete();
            $t->foreignId('lot_id')->constrained('production_tool_lots')->restrictOnDelete();
            $t->string('movement_type', 12);
            $t->unsignedBigInteger('quantity');
            $t->string('condition', 255);
            $t->string('reason', 500);
            $t->date('moved_on');
            $t->index(['manager_id', 'lot_id']);
            $this->actor($t);
        });
        Schema::create('worker_assistance', function (Blueprint $t) {
            $t->id();
            $t->string('assistance_no', 40)->unique();
            $this->branch($t);
            $t->foreignId('manager_id')->constrained('production_managers')->restrictOnDelete();
            $t->string('beneficiary_name', 150);
            $t->string('beneficiary_phone', 30)->nullable();
            $t->string('assistance_type', 12);
            $t->string('responsible_party', 12)->nullable();
            $t->string('recovery_terms', 1000)->nullable();
            $this->currency($t);
            $t->decimal('amount', 18, 2);
            $t->date('assistance_on');
            $t->string('reason', 500);
            $this->actor($t);
        });
        Schema::create('worker_assistance_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assistance_id')->constrained('worker_assistance')->restrictOnDelete();
            $t->decimal('amount', 18, 2);
            $t->date('delivered_on');
            $t->string('acknowledgement', 500);
            $this->actor($t);
        });
        foreach (['fundings', 'returns', 'recoveries'] as $suffix) {
            Schema::create('worker_assistance_'.$suffix, function (Blueprint $t) {
                $t->id();
                $t->foreignId('assistance_id')->constrained('worker_assistance')->restrictOnDelete();
                $t->foreignId('account_transaction_id')->unique()->constrained('account_transactions')->restrictOnDelete();
                $t->decimal('document_amount', 18, 2);
                $t->decimal('units_per_payment_unit', 20, 8);
                $this->actor($t);
            });
        }
        $checks = [
            'production_managers' => "status IN ('active','inactive')",
            'production_tools' => "status IN ('active','inactive')",
            'manager_material_movements' => "quantity>0 AND cost_base>=0 AND movement_type IN ('issue','consume','return','waste') AND ((movement_type IN ('consume','waste') AND production_order_id IS NOT NULL) OR (movement_type IN ('issue','return') AND cost_base=0))",
            'production_tool_lots' => 'quantity>0 AND unit_cost>0 AND total_cost>0 AND total_cost_base>=0 AND rate_to_base>0',
            'manager_tool_movements' => "quantity>0 AND movement_type IN ('issue','return','damage','loss')",
            'worker_assistance' => "amount>0 AND rate_to_base>0 AND ((assistance_type='advance' AND responsible_party IN ('worker','manager') AND responsible_party IS NOT NULL AND recovery_terms IS NOT NULL AND CHAR_LENGTH(recovery_terms)>=3) OR (assistance_type='bonus' AND responsible_party IS NULL AND recovery_terms IS NULL))",
            'worker_assistance_deliveries' => 'amount>0',
        ];
        foreach (['production_tool_payments', 'worker_assistance_fundings', 'worker_assistance_returns', 'worker_assistance_recoveries'] as $table) {
            $checks[$table] = 'document_amount>0 AND units_per_payment_unit>0';
        }
        foreach ($checks as $table => $condition) {
            DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT chk_'.$table.' CHECK ('.$condition.')');
        }
        foreach (array_diff(array_keys($checks), ['production_managers', 'production_tools']) as $table) {
            DB::unprepared('CREATE TRIGGER '.$table.'_no_update BEFORE UPDATE ON '.$table." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'");
            DB::unprepared('CREATE TRIGGER '.$table.'_no_delete BEFORE DELETE ON '.$table." FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve manager custody and financial history. Use a reviewed forward migration.');
    }
};
