<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class WeaverStatement
{
    public function read(User $actor, int $id): array
    {
        FoundationAccess::permit($actor, 'weavers.view');
        $weaver = DirectoryCatalog::scope(DB::table('weavers'), 'weavers', $actor)->where('id', $id)->whereNull('deleted_at')->first(['id', 'name']);
        abort_unless($weaver, 404);
        $allows = fn ($code) => $actor->hasPermission($code);
        $purchases = null;
        if ($allows('weaver-purchases.view') || $allows('purchasing.view')) {
            $purchases = InventoryService::branchScope(DB::table('weaver_purchases as p'), $actor, 'p.branch_id')
                ->join('v_weaver_purchase_balances as b', 'b.purchase_id', '=', 'p.id')->join('currencies as c', 'c.id', '=', 'p.currency_id')
                ->where('p.weaver_id', $id)->orderByDesc('p.id')->get(['p.id', 'p.purchase_no', 'p.purchase_on', 'b.total_amount', 'b.paid_amount', 'b.remaining_amount', 'c.code as currency_code']);
        }
        $orders = InventoryService::branchScope(DB::table('production_orders as o'), $actor, 'o.branch_id')
            ->join('currencies as c', 'c.id', '=', 'o.currency_id')->where('o.weaver_id', $id)->where(function ($q) use ($allows) {
                $q->whereRaw('1=0');
                if ($allows('production.view')) {
                    $q->orWhere('o.model', 'company_funded');
                }
                if ($allows('partnerships.view')) {
                    $q->orWhere('o.model', 'partnership');
                }
            })->orderByDesc('o.id')->get(['o.id', 'o.order_no', 'o.model', 'o.ordered_on', 'c.code as currency_code']);
        foreach ($orders as $order) {
            $order->state = ProductionService::state($order->id);
            if ($order->model === 'company_funded') {
                $order->earned = (string) DB::table('production_charges')->where('production_order_id', $order->id)->sum('amount');
                $order->paid = BusinessSupport::paid('production_payments', 'production_order_id', $order->id);
                $order->remaining = bcsub($order->earned, $order->paid, 2);
            } else {
                $order->settlement = DB::table('partnership_settlements')->where('production_order_id', $order->id)->first(['id', 'balance_due_base']);
                if ($order->settlement) {
                    $paid = (string) DB::table('partnership_settlement_payments as p')->where('p.settlement_id', $order->settlement->id)
                        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('account_transactions as r')->whereColumn('r.reversal_of_id', 'p.account_transaction_id'))->sum('p.amount_base');
                    $negative = str_starts_with($order->settlement->balance_due_base, '-');
                    $remaining = bcsub(ltrim($order->settlement->balance_due_base, '-'), $paid, 2);
                    $order->settlement_remaining_base = $negative ? bcsub('0', $remaining, 2) : $remaining;
                }
            }
        }
        $payments = [];
        if ($allows('weaver-payments.view')) {
            $payments = $this->entries(InventoryService::branchScope(DB::table('weaver_payments as p'), $actor, 'p.branch_id')->where('p.weaver_id', $id), 'Weaver payment');
        }
        foreach ($orders as $order) {
            $payments = array_merge($payments, $this->entries(DB::table('production_payments as p')->where('p.production_order_id', $order->id), $order->model === 'partnership' ? 'Partnership support' : 'Production labor / advance'));
            if (isset($order->settlement) && $order->settlement) {
                $payments = array_merge($payments, $this->entries(DB::table('partnership_settlement_payments as p')->where('p.settlement_id', $order->settlement->id), 'Partnership settlement'));
            }
        }
        if ($allows('weaver-refunds.view')) {
            $refunds = InventoryService::branchScope(DB::table('weaver_refunds as p')->join('weaver_purchases as d', 'd.id', '=', 'p.weaver_purchase_id'), $actor, 'd.branch_id')->where('d.weaver_id', $id);
            $payments = array_merge($payments, $this->entries($refunds, 'Weaver refund'));
        }
        usort($payments, fn ($a, $b) => [$b->transaction_date, $b->id] <=> [$a->transaction_date, $a->id]);

        return ['weaver' => $weaver, 'purchases' => $purchases, 'production' => $orders,
            'transactions' => $payments, 'base_currency' => DB::table('currencies')->where('id', DB::table('company_settings')->value('base_currency_id'))->value('code')];
    }

    private function entries($query, string $source): array
    {
        return $query->join('account_transactions as t', 't.id', '=', 'p.account_transaction_id')->join('accounts as a', 'a.id', '=', 't.account_id')
            ->join('currencies as c', 'c.id', '=', 't.currency_id')->select('t.id', 't.transaction_date', 't.amount', 't.direction', 't.description', 'a.name as account_name', 'c.code as currency_code')
            ->selectRaw('EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=t.id) reversed')->get()->map(function ($row) use ($source) {
                $row->source = $source;

                return $row;
            })->all();
    }
}
