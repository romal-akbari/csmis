<?php

use App\Models\Permission;
use App\Models\Role;
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

    private function currency(Blueprint $t): void
    {
        $t->unsignedInteger('currency_id');
        $t->decimal('rate_to_base', 20, 8);
        $t->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
    }

    private function branch(Blueprint $t): void
    {
        $t->unsignedInteger('branch_id');
        $t->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
    }

    public function up(): void
    {
        DB::statement('ALTER TABLE weaver_purchases MODIFY weaver_id BIGINT UNSIGNED NULL');
        Schema::table('weaver_purchases', function (Blueprint $t) {
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('seller_invoice', 80)->nullable();
        });
        DB::statement('ALTER TABLE weaver_purchases ADD CONSTRAINT acquisition_one_seller CHECK ((weaver_id IS NOT NULL)+(supplier_id IS NOT NULL)=1)');
        Schema::create('supplier_carpet_payments', function (Blueprint $t) {
            $t->id();
            $t->string('payment_no', 40)->unique();
            $t->foreignId('purchase_id')->constrained('weaver_purchases')->restrictOnDelete();
            $t->foreignId('account_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('document_amount', 18, 2);
            $t->decimal('units_per_payment_unit', 20, 8);
            $this->actor($t);
        });
        Schema::create('operating_expenses', function (Blueprint $t) {
            $t->id();
            $t->string('expense_no', 40)->unique();
            $this->branch($t);
            $this->currency($t);
            $t->unsignedInteger('expense_category_id');
            $t->foreign('expense_category_id')->references('id')->on('expense_categories')->restrictOnDelete();
            $t->date('expense_on');
            $t->string('payee', 150);
            $t->string('description', 500);
            $t->decimal('amount', 18, 2);
            $t->string('invoice_no', 80)->nullable();
            $this->actor($t);
        });
        Schema::create('operating_expense_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_id')->constrained('operating_expenses')->restrictOnDelete();
            $t->foreignId('account_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('document_amount', 18, 2);
            $t->decimal('units_per_payment_unit', 20, 8);
            $this->actor($t);
        });
        Schema::create('business_voids', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_id')->unique()->constrained('operating_expenses')->restrictOnDelete();
            $t->date('voided_on');
            $t->string('reason', 500);
            $this->actor($t);
        });
        Schema::create('expense_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_id')->constrained('operating_expenses')->restrictOnDelete();
            $t->string('storage_path');
            $t->string('original_name', 150);
            $t->string('mime_type', 60);
            $this->actor($t);
        });
        Schema::create('materials', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 150);
            $t->string('unit', 20);
            $t->string('color', 60)->nullable();
            $t->string('status', 10)->default('active');
            $this->actor($t);
        });
        Schema::create('material_lots', function (Blueprint $t) {
            $t->id();
            $t->string('lot_no', 40)->unique();
            $t->foreignId('material_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $this->branch($t);
            $this->currency($t);
            $t->date('received_on');
            $t->decimal('quantity', 18, 3);
            $t->decimal('unit_cost', 18, 2);
            $t->decimal('total_cost', 18, 2);
            $t->decimal('total_cost_base', 18, 2);
            $this->actor($t);
        });
        Schema::create('material_lot_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_id')->constrained('material_lots')->restrictOnDelete();
            $t->foreignId('account_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('document_amount', 18, 2);
            $t->decimal('units_per_payment_unit', 20, 8);
            $this->actor($t);
        });
        Schema::create('production_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_no', 40)->unique();
            $t->string('model', 20);
            $t->foreignId('weaver_id')->constrained()->restrictOnDelete();
            $this->branch($t);
            $this->currency($t);
            $t->date('ordered_on');
            $t->date('due_on');
            $t->string('description', 500);
            $t->string('share_basis', 20)->nullable();
            $t->decimal('company_share', 5, 2)->nullable();
            $t->decimal('company_loss_share', 5, 2)->nullable();
            $t->string('agreement_terms', 2000)->nullable();
            $this->actor($t);
        });
        Schema::create('production_carpets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('carpet_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('cost_weight', 12, 3);
        });
        Schema::create('material_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_id')->constrained('material_lots')->restrictOnDelete();
            $t->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $t->string('movement_type', 12);
            $t->date('moved_on');
            $t->decimal('quantity', 18, 3);
            $t->decimal('cost_base', 18, 2);
            $t->string('reason', 500);
            $this->actor($t);
        });
        Schema::create('production_progress', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $t->date('progress_on');
            $t->unsignedTinyInteger('percent');
            $t->string('notes', 500);
            $this->actor($t);
        });
        Schema::create('production_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $t->date('earned_on');
            $t->decimal('amount', 18, 2);
            $t->string('description', 500);
            $this->actor($t);
        });
        Schema::create('production_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('account_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->string('payment_type', 12);
            $t->decimal('document_amount', 18, 2);
            $t->decimal('units_per_payment_unit', 20, 8);
            $this->actor($t);
        });
        Schema::create('partnership_contributions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $t->date('contributed_on');
            $t->decimal('amount', 18, 2);
            $t->string('description', 500);
            $this->actor($t);
        });
        Schema::create('production_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->date('received_on');
            $t->decimal('total_cost_base', 18, 2);
            $this->actor($t);
        });
        Schema::create('production_receipt_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_receipt_id')->constrained()->restrictOnDelete();
            $t->foreignId('production_carpet_id')->unique()->constrained('production_carpets')->restrictOnDelete();
            $t->decimal('cost_base', 18, 2);
        });
        Schema::create('trade_sales', function (Blueprint $t) {
            $t->id();
            $t->string('sale_no', 40)->unique();
            $t->unsignedBigInteger('customer_id');
            $t->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $this->branch($t);
            $this->currency($t);
            $t->date('sold_on');
            $t->date('due_on');
            $t->string('notes', 500)->nullable();
            $this->actor($t);
        });
        Schema::create('trade_sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained('trade_sales')->restrictOnDelete();
            $t->foreignId('carpet_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('unit_price', 18, 2);
            $t->decimal('discount', 18, 2);
            $t->decimal('cost_base', 18, 2);
        });
        Schema::create('trade_customer_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained('trade_sales')->restrictOnDelete();
            $t->foreignId('account_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('document_amount', 18, 2);
            $t->decimal('units_per_payment_unit', 20, 8);
            $this->actor($t);
        });
        Schema::create('partnership_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->unique()->constrained()->restrictOnDelete();
            $t->date('settled_on');
            foreach (['revenue_base','company_cost_base','partner_cost_base','company_entitlement_base','partner_entitlement_base','partner_paid_base','balance_due_base'] as $column) {
                $t->decimal($column, 18, 2);
            }
            $this->actor($t);
        });
        Schema::create('partnership_settlement_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('settlement_id')->constrained('partnership_settlements')->restrictOnDelete();
            $t->foreignId('account_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('amount_base', 18, 2);
            $this->actor($t);
        });
        Schema::table('washing_order_items', fn (Blueprint $t) => $t->unsignedBigInteger('acquisition_item_id')->nullable()->change());
        Schema::table('stock_events', function (Blueprint $t) {
            $t->foreignId('production_receipt_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('trade_sale_item_id')->nullable()->unique()->constrained('trade_sale_items')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE stock_events DROP CONSTRAINT washing_event_source, MODIFY event_type ENUM('purchase','receipt','transfer','purchase_void','adjustment','weaver_return','washing_dispatch','washing_complete','washing_return','production_receive','sale') NOT NULL, MODIFY resulting_status ENUM('purchased','in_stock','registered','missing','returned','at_washing','washing_completed','sold') NOT NULL");
        DB::statement("ALTER TABLE stock_events ADD CONSTRAINT business_event_source CHECK (sequence_no>0 AND ((weaver_purchase_item_id IS NOT NULL)+(stock_receipt_id IS NOT NULL)+(stock_transfer_id IS NOT NULL)+(purchase_void_id IS NOT NULL)+(stock_adjustment_id IS NOT NULL)+(weaver_return_item_id IS NOT NULL)+(washing_order_item_id IS NOT NULL)+(production_receipt_item_id IS NOT NULL)+(trade_sale_item_id IS NOT NULL)=1) AND (
            (event_type='purchase' AND weaver_purchase_item_id IS NOT NULL AND resulting_status='purchased' AND resulting_location_id IS NULL) OR
            (event_type='receipt' AND stock_receipt_id IS NOT NULL AND resulting_status='in_stock' AND resulting_location_id IS NOT NULL) OR
            (event_type='transfer' AND stock_transfer_id IS NOT NULL AND resulting_status='in_stock' AND resulting_location_id IS NOT NULL) OR
            (event_type='purchase_void' AND purchase_void_id IS NOT NULL AND resulting_status='registered' AND resulting_location_id IS NULL) OR
            (event_type='adjustment' AND stock_adjustment_id IS NOT NULL AND resulting_status IN ('in_stock','missing') AND resulting_location_id IS NOT NULL) OR
            (event_type='weaver_return' AND weaver_return_item_id IS NOT NULL AND resulting_status='returned' AND resulting_location_id IS NULL) OR
            (event_type='washing_dispatch' AND washing_order_item_id IS NOT NULL AND resulting_status='at_washing' AND resulting_location_id IS NOT NULL) OR
            (event_type='washing_complete' AND washing_order_item_id IS NOT NULL AND resulting_status='washing_completed' AND resulting_location_id IS NOT NULL) OR
            (event_type='washing_return' AND washing_order_item_id IS NOT NULL AND resulting_status='in_stock' AND resulting_location_id IS NOT NULL) OR
            (event_type='production_receive' AND production_receipt_item_id IS NOT NULL AND resulting_status='in_stock' AND resulting_location_id IS NOT NULL) OR
            (event_type='sale' AND trade_sale_item_id IS NOT NULL AND resulting_status='sold' AND resulting_location_id IS NULL)))");
        DB::unprepared("CREATE OR REPLACE VIEW v_current_stock AS SELECT c.*,COALESCE(e.sequence_no,0) sequence_no,COALESCE(e.resulting_status,'registered') stock_status,e.resulting_location_id location_id,CASE WHEN e.resulting_status='registered' THEN c.registration_branch_id ELSE COALESCE(l.branch_id,wo.branch_id,ts.branch_id,p.branch_id,po.branch_id,c.registration_branch_id) END custody_branch_id,e.occurred_on,i.id acquisition_item_id,pc.production_order_id,COALESCE(po.model,'buy_resell') business_model FROM carpets c LEFT JOIN stock_events e ON e.carpet_id=c.id AND NOT EXISTS(SELECT 1 FROM stock_events n WHERE n.carpet_id=c.id AND n.sequence_no>e.sequence_no) LEFT JOIN stock_locations l ON l.id=e.resulting_location_id LEFT JOIN washing_order_items wi ON wi.id=e.washing_order_item_id LEFT JOIN washing_orders wo ON wo.id=wi.washing_order_id LEFT JOIN weaver_purchase_items i ON i.carpet_id=c.id AND NOT EXISTS(SELECT 1 FROM weaver_purchase_items ni WHERE ni.carpet_id=c.id AND ni.id>i.id) LEFT JOIN weaver_purchases p ON p.id=i.weaver_purchase_id LEFT JOIN production_carpets pc ON pc.carpet_id=c.id LEFT JOIN production_orders po ON po.id=pc.production_order_id LEFT JOIN trade_sale_items si ON si.id=e.trade_sale_item_id LEFT JOIN trade_sales ts ON ts.id=si.sale_id");
        DB::unprepared("CREATE OR REPLACE VIEW v_weaver_purchase_balances AS SELECT p.id purchase_id,COALESCE(i.total,0) total_amount,IF(v.id IS NULL,0,COALESCE(i.total,0)) cancelled_amount,COALESCE(ret.total,0) returned_amount,IF(v.id IS NULL,COALESCE(i.total,0)-COALESCE(ret.total,0),0) net_amount,COALESCE(a.paid,0)+COALESCE(sp.paid,0) paid_amount,COALESCE(f.refunded,0) refunded_amount,IF(v.id IS NULL,COALESCE(i.total,0)-COALESCE(ret.total,0),0)-COALESCE(a.paid,0)-COALESCE(sp.paid,0)+COALESCE(f.refunded,0) remaining_amount FROM weaver_purchases p LEFT JOIN purchase_voids v ON v.weaver_purchase_id=p.id LEFT JOIN(SELECT weaver_purchase_id,SUM(unit_price) total FROM weaver_purchase_items GROUP BY weaver_purchase_id)i ON i.weaver_purchase_id=p.id LEFT JOIN(SELECT x.weaver_purchase_id,SUM(x.amount_in_purchase_currency) paid FROM weaver_payment_allocations x JOIN weaver_payments w ON w.id=x.weaver_payment_id WHERE NOT EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=w.account_transaction_id) GROUP BY x.weaver_purchase_id)a ON a.weaver_purchase_id=p.id LEFT JOIN(SELECT ri.weaver_purchase_id,SUM(ri.unit_price) total FROM weaver_return_items r JOIN weaver_purchase_items ri ON ri.id=r.weaver_purchase_item_id GROUP BY ri.weaver_purchase_id)ret ON ret.weaver_purchase_id=p.id LEFT JOIN(SELECT w.weaver_purchase_id,SUM(w.amount_in_purchase_currency) refunded FROM weaver_refunds w WHERE NOT EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=w.account_transaction_id) GROUP BY w.weaver_purchase_id)f ON f.weaver_purchase_id=p.id LEFT JOIN(SELECT w.purchase_id,SUM(w.document_amount) paid FROM supplier_carpet_payments w WHERE NOT EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=w.account_transaction_id) GROUP BY w.purchase_id)sp ON sp.purchase_id=p.id");
        foreach (['supplier_carpet_payments','operating_expenses','operating_expense_payments','business_voids','material_lots','material_lot_payments','production_orders','production_carpets','material_movements','production_progress','production_charges','production_payments','partnership_contributions','production_receipts','production_receipt_items','trade_sales','trade_sale_items','trade_customer_receipts','partnership_settlements','partnership_settlement_payments'] as $table) {
            DB::unprepared('CREATE TRIGGER '.$table.'_no_update BEFORE UPDATE ON '.$table." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'");
            DB::unprepared('CREATE TRIGGER '.$table.'_no_delete BEFORE DELETE ON '.$table." FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END");
        }
        foreach (['purchasing'=>['view','create','pay','reverse'],'expenses'=>['view','create','pay','void','reverse'],'production'=>['view','create','manage','pay','receive','reverse'],'materials'=>['view','create','receive','move','pay','reverse'],'sales'=>['view','create','collect','reverse'],'partnerships'=>['view','create','manage','settle','pay','reverse']] as $module=>$actions) {
            foreach ($actions as $action) {
                $permission=Permission::firstOrCreate(['code'=>$module.'.'.$action],['module'=>$module,'action'=>$action,'description'=>'Allow '.$action.' on '.$module]);
                Role::where('name','Administrator')->first()?->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve business history. Use a reviewed forward migration or verified backup.');
    }
};
