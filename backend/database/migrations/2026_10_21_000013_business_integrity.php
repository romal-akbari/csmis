<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'operating_expenses' => 'amount>0 AND rate_to_base>0',
            'material_lots' => 'quantity>0 AND unit_cost>0 AND total_cost>0 AND total_cost_base>=0 AND rate_to_base>0',
            'production_orders' => "rate_to_base>0 AND due_on>=ordered_on AND ((model='company_funded' AND share_basis IS NULL AND company_share IS NULL AND company_loss_share IS NULL AND agreement_terms IS NULL) OR (model='partnership' AND share_basis IS NOT NULL AND company_share IS NOT NULL AND company_loss_share IS NOT NULL AND agreement_terms IS NOT NULL AND share_basis IN ('gross','net_cost_recovery') AND company_share BETWEEN 0 AND 100 AND company_loss_share BETWEEN 0 AND 100 AND CHAR_LENGTH(agreement_terms)>=10))",
            'production_carpets' => 'cost_weight>0',
            'material_movements' => "quantity>0 AND cost_base>=0 AND movement_type IN ('issue','consume','return','waste') AND (movement_type IN ('consume','waste') OR cost_base=0)",
            'production_progress' => 'percent BETWEEN 0 AND 100',
            'production_charges' => 'amount>0',
            'partnership_contributions' => 'amount>0',
            'production_payments' => "document_amount>0 AND units_per_payment_unit>0 AND payment_type IN ('labor','advance','support')",
            'production_receipts' => 'total_cost_base>0',
            'production_receipt_items' => 'cost_base>=0',
            'trade_sales' => 'rate_to_base>0 AND due_on>=sold_on',
            'trade_sale_items' => 'unit_price>0 AND discount>=0 AND discount<unit_price AND cost_base>=0',
        ] as $table => $condition) {
            DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT chk_'.$table.' CHECK ('.$condition.')');
        }
        foreach (['supplier_carpet_payments', 'operating_expense_payments', 'material_lot_payments', 'trade_customer_receipts'] as $table) {
            DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT chk_'.$table.' CHECK (document_amount>0 AND units_per_payment_unit>0)');
        }
        DB::statement('ALTER TABLE partnership_settlement_payments ADD CONSTRAINT chk_settlement_payment CHECK(amount_base>0)');
        DB::statement('ALTER TABLE material_movements ADD INDEX idx_material_lot_type(lot_id,movement_type), ADD INDEX idx_production_material_type(production_order_id,movement_type)');
        $grants = [
            'Branch Manager' => ['purchasing.view', 'purchasing.create', 'expenses.view', 'expenses.create', 'production.view', 'production.create', 'production.manage', 'production.receive', 'materials.view', 'materials.create', 'materials.receive', 'materials.move', 'sales.view', 'sales.create', 'partnerships.view', 'partnerships.create', 'partnerships.manage'],
            'Accountant' => ['purchasing.view', 'purchasing.pay', 'purchasing.reverse', 'expenses.view', 'expenses.create', 'expenses.pay', 'expenses.void', 'expenses.reverse', 'production.view', 'production.pay', 'production.reverse', 'materials.view', 'materials.pay', 'materials.reverse', 'sales.view', 'sales.collect', 'sales.reverse', 'partnerships.view', 'partnerships.settle', 'partnerships.pay', 'partnerships.reverse'],
            'Purchase User' => ['purchasing.view', 'purchasing.create', 'materials.view', 'materials.create', 'materials.receive', 'production.view', 'production.create', 'production.manage', 'materials.move'],
            'Stock User' => ['materials.view', 'materials.receive', 'materials.move', 'production.view', 'production.receive'],
            'Sales User' => ['sales.view', 'sales.create', 'sales.collect'],
        ];
        foreach ($grants as $name => $codes) {
            Role::where('name', $name)->first()?->permissions()->syncWithoutDetaching(Permission::whereIn('code', $codes)->pluck('id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve posted business facts. Use a reviewed forward migration.');
    }
};
