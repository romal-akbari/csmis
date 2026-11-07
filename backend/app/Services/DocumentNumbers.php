<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DocumentNumbers
{
    public static function next(string $kind): string
    {
        // Caller holds the shared company-settings transaction lock.
        $number = (int) DB::table('document_sequences')->where('kind', $kind)->lockForUpdate()->value('last_number') + 1;
        $targets = match ($kind) {
            'PUR', 'DRAFT' => [['weaver_purchases', 'purchase_no'], ['purchase_drafts', 'purchase_no']],
            'REC' => [['stock_receipts', 'receipt_no']], 'WPAY' => [['weaver_payments', 'payment_no']],
            'SPAY' => [['supplier_carpet_payments', 'payment_no']], 'EXP' => [['operating_expenses', 'expense_no']],
            'MAT' => [['material_lots', 'lot_no']], 'PROD', 'PART' => [['production_orders', 'order_no']],
            'MPR' => [['manager_production_orders', 'order_no']],
            'MDL' => [['manager_production_deliveries', 'delivery_no']],
            'MPC' => [['carpets', 'carpet_code']], 'MWASH' => [['washing_orders', 'order_no']],
            'MTL' => [['production_tool_lots', 'lot_no']], 'MAS' => [['worker_assistance', 'assistance_no']],
            'SUP' => [['supply_purchases', 'purchase_no']], 'WBILL' => [['washing_bills', 'expense_no']], 'WMPAY' => [['washing_payments', 'payment_no']],
            'SALE' => [['trade_sales', 'sale_no']],
            'BRN' => [['branches', 'code']], 'LOC' => [['stock_locations', 'code']], 'ACC' => [['accounts', 'code']],
            'MGR' => [['production_managers', 'code']], 'TOOL' => [['production_tools', 'code']],
            'ITEM' => [['materials', 'code']], 'TRF' => [['stock_transfers', 'transfer_no']],
            'ADJ' => [['stock_adjustments', 'adjustment_no']],
            default => throw new \InvalidArgumentException('Unknown document sequence.'),
        };
        do {
            $reference = $kind.'-'.now()->format('Y').'-'.str_pad((string) $number, 8, '0', STR_PAD_LEFT);
            $used = false;
            foreach ($targets as [$table, $column]) {
                if (DB::table($table)->where($column, $reference)->exists()) { $used = true; ++$number; break; }
            }
        } while ($used);
        DB::table('document_sequences')->updateOrInsert(['kind' => $kind], ['last_number' => $number]);

        return $reference;
    }

    public static function foundation(string $resource): ?string
    {
        return match ($resource) {
            'branches' => 'BRN', 'locations' => 'LOC', 'accounts' => 'ACC', default => null,
        };
    }
}
