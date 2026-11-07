<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manager_material_movements', function (Blueprint $table) {
            $table->foreign('production_order_id')->references('id')->on('manager_production_orders')->restrictOnDelete();
        });
        Schema::table('stock_events', function (Blueprint $table) {
            $table->foreignId('manager_delivery_item_id')->nullable()->unique()->constrained('manager_delivery_items')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE stock_events DROP CONSTRAINT business_event_source,
            MODIFY event_type ENUM('purchase','receipt','transfer','purchase_void','adjustment','weaver_return','washing_dispatch','washing_complete','washing_return','production_receive','sale','production_complete') NOT NULL,
            MODIFY resulting_status ENUM('purchased','in_stock','registered','missing','returned','at_washing','washing_completed','sold','production_completed') NOT NULL");
        DB::statement("ALTER TABLE stock_events ADD CONSTRAINT manager_event_source CHECK (
            sequence_no>0 AND (
                (weaver_purchase_item_id IS NOT NULL)+(stock_receipt_id IS NOT NULL)+(stock_transfer_id IS NOT NULL)+
                (purchase_void_id IS NOT NULL)+(stock_adjustment_id IS NOT NULL)+(weaver_return_item_id IS NOT NULL)+
                (washing_order_item_id IS NOT NULL)+(production_receipt_item_id IS NOT NULL)+(trade_sale_item_id IS NOT NULL)+
                (manager_delivery_item_id IS NOT NULL)=1
            ) AND (
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
                (event_type='sale' AND trade_sale_item_id IS NOT NULL AND resulting_status='sold' AND resulting_location_id IS NULL) OR
                (event_type='production_complete' AND manager_delivery_item_id IS NOT NULL AND resulting_status='production_completed' AND resulting_location_id IS NULL)
            ))");

        DB::unprepared("CREATE OR REPLACE VIEW v_current_stock AS
            SELECT c.*,COALESCE(e.sequence_no,0) sequence_no,COALESCE(e.resulting_status,'registered') stock_status,
                e.resulting_location_id location_id,
                CASE WHEN e.resulting_status='registered' THEN c.registration_branch_id
                    ELSE COALESCE(l.branch_id,wo.branch_id,ts.branch_id,p.branch_id,po.branch_id,mo.branch_id,c.registration_branch_id) END custody_branch_id,
                e.occurred_on,i.id acquisition_item_id,pc.production_order_id,
                COALESCE(po.model,IF(mi.id IS NOT NULL,'company_funded',NULL),'buy_resell') business_model,
                mi.production_order_id manager_production_order_id
            FROM carpets c
            LEFT JOIN stock_events e ON e.carpet_id=c.id AND NOT EXISTS(SELECT 1 FROM stock_events n WHERE n.carpet_id=c.id AND n.sequence_no>e.sequence_no)
            LEFT JOIN stock_locations l ON l.id=e.resulting_location_id
            LEFT JOIN washing_order_items wi ON wi.id=e.washing_order_item_id
            LEFT JOIN washing_orders wo ON wo.id=wi.washing_order_id
            LEFT JOIN weaver_purchase_items i ON i.carpet_id=c.id AND NOT EXISTS(SELECT 1 FROM weaver_purchase_items ni WHERE ni.carpet_id=c.id AND ni.id>i.id)
            LEFT JOIN weaver_purchases p ON p.id=i.weaver_purchase_id
            LEFT JOIN production_carpets pc ON pc.carpet_id=c.id
            LEFT JOIN production_orders po ON po.id=pc.production_order_id
            LEFT JOIN trade_sale_items si ON si.id=e.trade_sale_item_id
            LEFT JOIN trade_sales ts ON ts.id=si.sale_id
            LEFT JOIN manager_delivery_items mi ON mi.carpet_id=c.id
            LEFT JOIN manager_production_orders mo ON mo.id=mi.production_order_id");
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve production and custody history. Use a reviewed forward migration.');
    }
};
