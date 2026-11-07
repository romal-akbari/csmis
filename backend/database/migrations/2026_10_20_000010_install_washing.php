<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['washing_order_items', 'washing_bills', 'washing_bill_allocations', 'washing_bill_voids', 'washing_payments'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("Review pre-existing {$table} before washing migration.");
            }
        }
        if (Schema::hasTable('washing_orders')) {
            $legacy = Schema::hasColumns('washing_orders', ['carpet_id', 'washing_facility_id', 'sent_date'])
                && ! Schema::hasColumn('washing_orders', 'branch_id');
            $incoming = DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('REFERENCED_TABLE_NAME', 'washing_orders')->exists();
            if (! $legacy || DB::table('washing_orders')->exists() || $incoming || Schema::hasTable('legacy_washing_orders')) {
                throw new RuntimeException('Review pre-existing washing orders and their references before migration.');
            }
            // Preserve the original unused structure and its legacy-carpet foreign keys.
            Schema::rename('washing_orders', 'legacy_washing_orders');
        }
        Schema::create('washing_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_no', 40)->unique();
            $t->unsignedInteger('branch_id');
            $t->unsignedInteger('facility_id');
            $t->foreignId('facility_location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->date('sent_on');
            $t->string('notes', 500)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            foreach (['branch_id' => 'branches', 'facility_id' => 'washing_facilities', 'created_by' => 'users'] as $col => $parent) {
                $t->foreign($col)->references('id')->on($parent)->restrictOnDelete();
            }
        });
        Schema::create('washing_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('washing_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('carpet_id')->constrained()->restrictOnDelete();
            $t->foreignId('acquisition_item_id')->constrained('weaver_purchase_items')->restrictOnDelete();
            $t->foreignId('source_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $t->unique(['washing_order_id', 'carpet_id'], 'uq_washing_order_carpet');
        });
        Schema::create('washing_bills', function (Blueprint $t) {
            $t->id();
            $t->string('expense_no', 40)->unique();
            $t->string('invoice_no', 80);
            $t->unsignedInteger('branch_id');
            $t->unsignedInteger('facility_id');
            $t->unsignedInteger('currency_id');
            $t->decimal('rate_to_base', 20, 8);
            $t->date('billed_on');
            $t->decimal('amount', 18, 2);
            $t->decimal('amount_base', 18, 2);
            $t->string('cost_classification', 20)->default('inventory');
            $t->string('notes', 500)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->unique(['facility_id', 'invoice_no'], 'uq_washing_facility_invoice');
            foreach (['branch_id' => 'branches', 'facility_id' => 'washing_facilities', 'currency_id' => 'currencies', 'created_by' => 'users'] as $col => $parent) {
                $t->foreign($col)->references('id')->on($parent)->restrictOnDelete();
            }
        });
        Schema::create('washing_bill_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('washing_bill_id')->constrained()->restrictOnDelete();
            $t->foreignId('washing_order_item_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 18, 2);
            $t->decimal('amount_base', 18, 2);
            $t->unique(['washing_bill_id', 'washing_order_item_id'], 'uq_washing_bill_visit');
        });
        Schema::create('washing_bill_voids', function (Blueprint $t) {
            $t->id();
            $t->foreignId('washing_bill_id')->unique()->constrained()->restrictOnDelete();
            $t->date('voided_on');
            $t->string('reason', 500);
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('washing_payments', function (Blueprint $t) {
            $t->id();
            $t->string('payment_no', 40)->unique();
            $t->foreignId('washing_bill_id')->constrained()->restrictOnDelete();
            $t->foreignId('account_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('amount_in_bill_currency', 18, 2);
            $t->decimal('bill_units_per_payment_unit', 20, 8);
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE washing_bills ADD CONSTRAINT washing_bill_amount CHECK (amount>0 AND amount_base>=0 AND rate_to_base>0 AND cost_classification='inventory')");
        DB::statement('ALTER TABLE washing_bill_allocations ADD CONSTRAINT washing_allocation_amount CHECK (amount>0 AND amount_base>=0)');
        DB::statement('ALTER TABLE washing_payments ADD CONSTRAINT washing_payment_amount CHECK (amount_in_bill_currency>0 AND bill_units_per_payment_unit>0)');
        Schema::table('stock_events', function (Blueprint $t) {
            $t->foreignId('washing_order_item_id')->nullable()->constrained()->restrictOnDelete();
            $t->unique(['event_type', 'washing_order_item_id'], 'uq_washing_visit_event');
        });
        DB::statement("ALTER TABLE stock_events MODIFY event_type ENUM('purchase','receipt','transfer','purchase_void','adjustment','weaver_return','washing_dispatch','washing_complete','washing_return') NOT NULL, MODIFY resulting_status ENUM('purchased','in_stock','registered','missing','returned','at_washing','washing_completed') NOT NULL, DROP CONSTRAINT correction_event_source");
        DB::statement("ALTER TABLE stock_events ADD CONSTRAINT washing_event_source CHECK (sequence_no>0 AND
            ((weaver_purchase_item_id IS NOT NULL)+(stock_receipt_id IS NOT NULL)+(stock_transfer_id IS NOT NULL)+(purchase_void_id IS NOT NULL)+(stock_adjustment_id IS NOT NULL)+(weaver_return_item_id IS NOT NULL)+(washing_order_item_id IS NOT NULL)=1) AND (
            (event_type='purchase' AND weaver_purchase_item_id IS NOT NULL AND resulting_status='purchased' AND resulting_location_id IS NULL) OR
            (event_type='receipt' AND stock_receipt_id IS NOT NULL AND resulting_status='in_stock' AND resulting_location_id IS NOT NULL) OR
            (event_type='transfer' AND stock_transfer_id IS NOT NULL AND resulting_status='in_stock' AND resulting_location_id IS NOT NULL) OR
            (event_type='purchase_void' AND purchase_void_id IS NOT NULL AND resulting_status='registered' AND resulting_location_id IS NULL) OR
            (event_type='adjustment' AND stock_adjustment_id IS NOT NULL AND resulting_status IN ('in_stock','missing') AND resulting_location_id IS NOT NULL) OR
            (event_type='weaver_return' AND weaver_return_item_id IS NOT NULL AND resulting_status='returned' AND resulting_location_id IS NULL) OR
            (event_type='washing_dispatch' AND washing_order_item_id IS NOT NULL AND resulting_status='at_washing' AND resulting_location_id IS NOT NULL) OR
            (event_type='washing_complete' AND washing_order_item_id IS NOT NULL AND resulting_status='washing_completed' AND resulting_location_id IS NOT NULL) OR
            (event_type='washing_return' AND washing_order_item_id IS NOT NULL AND resulting_status='in_stock' AND resulting_location_id IS NOT NULL)))");
        DB::unprepared('CREATE OR REPLACE VIEW v_current_stock AS SELECT c.*, COALESCE(e.sequence_no,0) AS sequence_no, '
            ."COALESCE(e.resulting_status,'registered') AS stock_status, e.resulting_location_id AS location_id, "
            ."CASE WHEN e.resulting_status='registered' THEN c.registration_branch_id ELSE COALESCE(l.branch_id,wo.branch_id,p.branch_id,c.registration_branch_id) END AS custody_branch_id, e.occurred_on, i.id AS acquisition_item_id "
            .'FROM carpets c LEFT JOIN stock_events e ON e.carpet_id=c.id AND NOT EXISTS '
            .'(SELECT 1 FROM stock_events newer WHERE newer.carpet_id=c.id AND newer.sequence_no>e.sequence_no) '
            .'LEFT JOIN stock_locations l ON l.id=e.resulting_location_id '
            .'LEFT JOIN washing_order_items wi ON wi.id=e.washing_order_item_id LEFT JOIN washing_orders wo ON wo.id=wi.washing_order_id '
            .'LEFT JOIN weaver_purchase_items i ON i.carpet_id=c.id AND NOT EXISTS '
            .'(SELECT 1 FROM weaver_purchase_items newer_i WHERE newer_i.carpet_id=c.id AND newer_i.id>i.id) '
            .'LEFT JOIN weaver_purchases p ON p.id=i.weaver_purchase_id');
        DB::unprepared('CREATE VIEW v_washing_bill_balances AS SELECT b.id AS bill_id, b.amount AS total_amount, '
            ."IF(v.id IS NULL,'posted','void') AS state, COALESCE(p.paid,0) AS paid_amount, "
            .'IF(v.id IS NULL,b.amount,0)-COALESCE(p.paid,0) AS remaining_amount FROM washing_bills b '
            .'LEFT JOIN washing_bill_voids v ON v.washing_bill_id=b.id '
            .'LEFT JOIN (SELECT w.washing_bill_id,SUM(w.amount_in_bill_currency) AS paid FROM washing_payments w '
            .'WHERE NOT EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=w.account_transaction_id) '
            .'GROUP BY w.washing_bill_id) p ON p.washing_bill_id=b.id');
        foreach (['washing_orders', 'washing_order_items', 'washing_bills', 'washing_bill_allocations', 'washing_bill_voids', 'washing_payments'] as $table) {
            DB::unprepared('CREATE TRIGGER '.$table.'_no_update BEFORE UPDATE ON '.$table." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'");
            DB::unprepared('CREATE TRIGGER '.$table.'_no_delete BEFORE DELETE ON '.$table." FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'; END IF; END");
        }
        // Upgrade grants are additive: preserve existing customized role permissions.
        $modules = ['washing' => ['view', 'send', 'complete', 'return', 'export'],
            'washing-costs' => ['view', 'create', 'void', 'pay', 'reverse']];
        $permissions = [];
        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                $code = $module.'.'.$action;
                $permissions[$code] = Permission::firstOrCreate(['code' => $code],
                    ['module' => $module, 'action' => $action, 'description' => 'Allow '.$action.' on '.$module])->id;
            }
        }
        $grants = [
            'Administrator' => array_keys($permissions),
            'Branch Manager' => ['washing.view', 'washing.send', 'washing.complete', 'washing.return', 'washing.export', 'washing-costs.view'],
            'Stock User' => ['washing.view', 'washing.send', 'washing.return', 'washing.export'],
            'Sales User' => ['washing.view'],
            'Purchase User' => ['washing.view', 'washing.send'],
            'Accountant' => ['washing.view', 'washing-costs.view', 'washing-costs.create', 'washing-costs.void', 'washing-costs.pay', 'washing-costs.reverse'],
            'Washing Facility User' => ['washing.view', 'washing.complete'],
        ];
        foreach ($grants as $name => $codes) {
            $role = Role::firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching(array_map(fn ($code) => $permissions[$code], $codes));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve washing history. Use a reviewed forward migration or verified backup.');
    }
};
