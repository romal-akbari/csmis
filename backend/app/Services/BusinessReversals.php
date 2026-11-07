<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class BusinessReversals
{
    public static function authorize(User $actor, int $transaction): void
    {
        foreach ([
            ['supplier_carpet_payments', 'purchase_id', 'weaver_purchases', 'purchasing.reverse'],
            ['operating_expense_payments', 'expense_id', 'operating_expenses', 'expenses.reverse'],
            ['material_lot_payments', 'lot_id', 'material_lots', 'materials.reverse'],
            ['production_payments', 'production_order_id', 'production_orders', 'production.reverse'],
            ['trade_customer_receipts', 'sale_id', 'trade_sales', 'sales.reverse'],
            ['manager_production_payments', 'production_order_id', 'manager_production_orders', 'production.reverse'],
            ['production_tool_payments', 'lot_id', 'production_tool_lots', 'materials.reverse'],
            ['worker_assistance_fundings', 'assistance_id', 'worker_assistance', 'production.reverse'],
            ['worker_assistance_returns', 'assistance_id', 'worker_assistance', 'production.reverse'],
            ['worker_assistance_recoveries', 'assistance_id', 'worker_assistance', 'production.reverse'],
        ] as [$table, $column, $parent, $permission]) {
            foreach (DB::table($table)->where('account_transaction_id', $transaction)->get() as $payment) {
                $document = BusinessSupport::document($actor, $parent, $payment->{$column});
                if ($parent === 'production_orders' && $document->model === 'partnership') {
                    $permission = 'partnerships.reverse';
                    abort_if(DB::table('partnership_settlements')->where('production_order_id', $document->id)->exists(), 422, 'Settled partnership support cannot be reversed.');
                }
                FoundationAccess::permit($actor, $permission);
                if ($parent === 'manager_production_orders') {
                    abort_if(DB::table('manager_production_closures')->where('production_order_id', $document->id)->exists(), 422, 'A closed production order cannot change its payments.');
                }
                if ($table === 'worker_assistance_fundings') {
                    $balances = WorkerAssistanceService::balances($document->id);
                    $used = bcadd($balances['delivered'], $balances['returned'], 2);
                    abort_if(bccomp(bcsub($balances['funded'], $payment->document_amount, 2), $used, 2) < 0, 422,
                        'This funding has already been delivered or returned. Correct the linked handovers before reversing it.');
                }
                if ($table === 'worker_assistance_returns') {
                    $balances = WorkerAssistanceService::balances($document->id);
                    $remainingReturns = bcsub($balances['returned'], $payment->document_amount, 2);
                    abort_if(bccomp(bcsub($balances['funded'], $remainingReturns, 2), $document->amount, 2) > 0, 422,
                        'Replacement funding depends on this return. Reverse the replacement funding first.');
                }
                if ($parent === 'trade_sales') {
                    $settled = DB::table('trade_sale_items as i')->join('production_carpets as c', 'c.carpet_id', '=', 'i.carpet_id')
                        ->join('partnership_settlements as s', 's.production_order_id', '=', 'c.production_order_id')->where('i.sale_id', $document->id)->exists();
                    abort_if($settled, 422, 'Customer receipts included in a final partnership settlement cannot be reversed.');
                }
            }
        }
        $payment = DB::table('partnership_settlement_payments')->where('account_transaction_id', $transaction)->first();
        if ($payment) {
            FoundationAccess::permit($actor, 'partnerships.reverse');
            $order = DB::table('partnership_settlements')->where('id', $payment->settlement_id)->value('production_order_id');
            BusinessSupport::document($actor, 'production_orders', $order);
        }
    }
}
