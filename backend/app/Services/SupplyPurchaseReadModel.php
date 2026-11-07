<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Receipt facts are counted once, including supply receipts saved before combined purchases existed. */
class SupplyPurchaseReadModel
{
    public function lots(User $actor): Builder
    {
        $queries = [];
        foreach (['material' => 'material_lots', 'tool' => 'production_tool_lots'] as $kind => $table) {
            $payments = DB::table(($kind === 'tool' ? 'production_tool_payments' : 'material_lot_payments').' as pay')
                ->select('pay.lot_id')->selectRaw('SUM(pay.document_amount) paid_amount')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('account_transactions as reversal')
                    ->whereColumn('reversal.reversal_of_id', 'pay.account_transaction_id'))->groupBy('pay.lot_id');
            $queries[] = InventoryService::branchScope(DB::table($table.' as lot'), $actor, 'lot.branch_id')
                ->leftJoinSub($payments, 'paid', 'paid.lot_id', '=', 'lot.id')
                ->leftJoin('supply_purchase_lines as line', 'line.'.$kind.'_lot_id', '=', 'lot.id')
                ->leftJoin('supply_purchases as purchase', 'purchase.id', '=', 'line.purchase_id')
                ->join('currencies as currency', 'currency.id', '=', 'lot.currency_id')
                ->join('stock_locations as location', 'location.id', '=', 'lot.location_id')
                ->select('lot.supplier_id', 'lot.branch_id', 'lot.currency_id', 'lot.rate_to_base', 'lot.location_id',
                    'location.name as location_name', 'currency.code as currency_code', 'currency.decimal_places as precision')
                ->selectRaw("CASE WHEN purchase.id IS NULL THEN ? ELSE 'supply' END document_kind", [$kind.'-receipt'])
                ->selectRaw('COALESCE(purchase.id,lot.id) document_id,COALESCE(purchase.purchase_no,lot.lot_no) purchase_no')
                ->selectRaw('COALESCE(purchase.purchased_on,lot.received_on) purchased_on,lot.total_cost total_amount,COALESCE(paid.paid_amount,0) paid_amount')
                ->selectRaw('lot.total_cost-COALESCE(paid.paid_amount,0) remaining_amount');
        }

        return DB::query()->fromSub($queries[0]->unionAll($queries[1]), 'facts');
    }

    public function documents(User $actor, int $supplier): Builder
    {
        $columns = ['document_kind', 'document_id', 'purchase_no', 'purchased_on', 'currency_id',
            'currency_code', 'precision', 'rate_to_base', 'branch_id', 'location_id', 'location_name'];

        return $this->lots($actor)->where('supplier_id', $supplier)->select($columns)
            ->selectRaw('SUM(total_amount) total_amount,SUM(paid_amount) paid_amount,SUM(remaining_amount) remaining_amount')
            ->groupBy($columns);
    }

    public function totals(User $actor, ?int $supplier = null): array
    {
        $query = $this->lots($actor);
        if ($supplier !== null) $query->where('supplier_id', $supplier);

        return $query->select('currency_code as currency', 'precision')
            ->selectRaw('SUM(total_amount) amount,SUM(paid_amount) paid_amount,SUM(remaining_amount) owed')
            ->groupBy('currency_id', 'currency_code', 'precision')->orderBy('currency_code')->get()->all();
    }

    public function suppliers(User $actor): Builder
    {
        return $this->lots($actor)->join('suppliers as supplier', 'supplier.id', '=', 'facts.supplier_id')
            ->select('supplier.id as supplier_id', 'supplier.name', 'supplier.phone', 'facts.currency_id', 'facts.currency_code', 'facts.precision')
            ->selectRaw('SUM(facts.total_amount) total_amount,SUM(facts.paid_amount) paid_amount,SUM(facts.remaining_amount) remaining_amount')
            ->groupBy('supplier.id', 'supplier.name', 'supplier.phone', 'facts.currency_id', 'facts.currency_code', 'facts.precision');
    }

    public function payments(User $actor, int $supplier): array
    {
        if (! $actor->hasPermission('accounts.view') || ! $actor->hasPermission('ledger.view')) return [];
        $queries = [];
        foreach (['material_lot_payments' => 'material_lots', 'production_tool_payments' => 'production_tool_lots'] as $table => $lots) {
            $query = InventoryService::branchScope(DB::table($table.' as payment')->join($lots.' as lot', 'lot.id', '=', 'payment.lot_id'), $actor, 'lot.branch_id')
                ->join('account_transactions as entry', 'entry.id', '=', 'payment.account_transaction_id')
                ->join('accounts as account', 'account.id', '=', 'entry.account_id')->join('currencies as currency', 'currency.id', '=', 'entry.currency_id')
                ->where('lot.supplier_id', $supplier)->select('entry.id', 'entry.transaction_date', 'entry.description', 'account.name as account_name', 'currency.code as currency_code')
                ->selectRaw('COALESCE(payment.amount_in_payment_currency,entry.amount) amount')
                ->selectRaw('EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=entry.id) reversed');
            $queries[] = FoundationAccess::accounts($query, $actor, 'account.id');
        }

        return DB::query()->fromSub($queries[0]->unionAll($queries[1]), 'allocations')
            ->select('id', 'transaction_date', 'description', 'account_name', 'currency_code', 'reversed')->selectRaw('SUM(amount) amount')
            ->groupBy('id', 'transaction_date', 'description', 'account_name', 'currency_code', 'reversed')
            ->orderByDesc('transaction_date')->orderByDesc('id')->limit(50)->get()->all();
    }
}
