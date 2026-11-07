<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Current document and cash summaries, with the same permissions as their source pages. */
class DashboardBusinessSummary
{
    public function read(User $actor, array $permissions): array
    {
        $allows = fn ($code) => in_array($code, $permissions, true);
        $financial = $allows('accounts.view') && $allows('ledger.view');

        return [
            'purchases' => $allows('materials.view') ? app(SupplyPurchaseReadModel::class)->totals($actor) : null,
            'legacy_purchases' => $allows('weaver-purchases.view') ? $this->purchases($actor) : null,
            'expenses' => $financial ? $this->expenses($actor, $allows('production.view')) : null,
            'accounts' => $financial ? $this->accounts($actor) : null,
            'recent_transactions' => $financial ? $this->transactions($actor) : null,
            'branches' => $allows('branches.view') ? $this->branches($actor, $allows('stock.view')) : null,
            'sales' => $allows('sales.view') ? $this->sales($actor) : null,
        ];
    }

    private function purchases(User $actor): array
    {
        return InventoryService::branchScope(DB::table('weaver_purchases as p'), $actor, 'p.branch_id')
            ->join('v_weaver_purchase_balances as b', 'b.purchase_id', '=', 'p.id')
            ->join('currencies as c', 'c.id', '=', 'p.currency_id')
            ->select('c.code as currency', 'c.decimal_places as precision')
            ->selectRaw('SUM(b.net_amount) AS amount')->groupBy('c.id', 'c.code', 'c.decimal_places')
            ->orderBy('c.code')->get()->map(fn ($row) => [
                'currency' => $row->currency, 'precision' => (int) $row->precision, 'amount' => (string) $row->amount,
            ])->all();
    }

    private function sales(User $actor): array
    {
        $items = DB::table('trade_sale_items')->select('sale_id')->selectRaw('SUM(unit_price-discount) total,COUNT(*) sold')->groupBy('sale_id');
        $payments = DB::table('trade_customer_receipts as p')->select('p.sale_id')->selectRaw('SUM(p.document_amount) paid')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('account_transactions as r')->whereColumn('r.reversal_of_id', 'p.account_transaction_id'))->groupBy('p.sale_id');

        return InventoryService::branchScope(DB::table('trade_sales as s'), $actor, 's.branch_id')->joinSub($items, 'i', 'i.sale_id', '=', 's.id')
            ->leftJoinSub($payments, 'p', 'p.sale_id', '=', 's.id')->join('currencies as c', 'c.id', '=', 's.currency_id')
            ->select('c.code as currency', 'c.decimal_places as precision')->selectRaw('SUM(i.total) amount,SUM(i.sold) sold,SUM(i.total-COALESCE(p.paid,0)) receivable')
            ->selectRaw('SUM(CASE WHEN s.due_on<? THEN i.total-COALESCE(p.paid,0) ELSE 0 END) overdue', [FoundationCatalog::today()])
            ->groupBy('c.id', 'c.code', 'c.decimal_places')->orderBy('c.code')->get()->all();
    }

    private function expenses(User $actor, bool $production): array
    {
        // Cash expenses are distinct from inventory purchases and allocated washing cost.
        // A reversal offsets its original expense once; opening/income never count here.
        $totals = FoundationAccess::accounts(DB::table('account_transactions as t')
            ->join('accounts as a', 'a.id', '=', 't.account_id'), $actor, 'a.id')
            ->join('currencies as c', 'c.id', '=', 't.currency_id')
            ->leftJoin('account_transactions as r', 'r.reversal_of_id', '=', 't.id')
            ->where('t.entry_type', 'expense')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('weaver_payments as w')
                ->whereColumn('w.account_transaction_id', 't.id')->where('w.payment_type', 'purchase'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('washing_payments as w')
                ->whereColumn('w.account_transaction_id', 't.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('supplier_carpet_payments as w')->whereColumn('w.account_transaction_id', 't.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('material_lot_payments as w')->whereColumn('w.account_transaction_id', 't.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('production_payments as w')->whereColumn('w.account_transaction_id', 't.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('partnership_settlement_payments as w')->whereColumn('w.account_transaction_id', 't.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('manager_production_payments as w')->whereColumn('w.account_transaction_id', 't.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('production_tool_payments as w')->whereColumn('w.account_transaction_id', 't.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('worker_assistance_fundings as w')->whereColumn('w.account_transaction_id', 't.id'))
            ->select('c.code as currency', 'c.decimal_places as precision')
            ->selectRaw('SUM(t.amount-COALESCE(r.amount,0)) AS amount')
            ->groupBy('c.id', 'c.code', 'c.decimal_places')->orderBy('c.code')->get()->map(fn ($row) => [
                'currency' => $row->currency, 'precision' => (int) $row->precision, 'amount' => (string) $row->amount,
            ])->keyBy('currency')->all();
        if ($production) {
            // Giving a manager custody of money is not an expense. A confirmed bonus
            // handover is recognized once, in its document currency, without another
            // ledger withdrawal. Advances remain recoverable and never enter this sum.
            $bonuses = InventoryService::branchScope(DB::table('worker_assistance as a'), $actor, 'a.branch_id')
                ->join('worker_assistance_deliveries as d', 'd.assistance_id', '=', 'a.id')
                ->join('currencies as c', 'c.id', '=', 'a.currency_id')->where('a.assistance_type', 'bonus')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('worker_assistance_fundings as f')
                    ->whereColumn('f.assistance_id', 'a.id')->whereNotExists(fn ($r) => $r->selectRaw('1')->from('account_transactions as r')->whereColumn('r.reversal_of_id', 'f.account_transaction_id')));
            if (! FoundationAccess::administrator($actor)) {
                // Handover records do not allocate a delivery to particular fundings.
                // Include the document only when every active funding account is visible.
                $accounts = FoundationAccess::accountIds($actor);
                $bonuses->whereNotExists(fn ($q) => $q->selectRaw('1')->from('worker_assistance_fundings as f')
                    ->join('account_transactions as t', 't.id', '=', 'f.account_transaction_id')->whereColumn('f.assistance_id', 'a.id')
                    ->whereNotIn('t.account_id', $accounts)->whereNotExists(fn ($r) => $r->selectRaw('1')->from('account_transactions as r')->whereColumn('r.reversal_of_id', 't.id')));
            }
            foreach ($bonuses->select('c.code as currency', 'c.decimal_places as precision')->selectRaw('SUM(d.amount) amount')
                ->groupBy('c.id', 'c.code', 'c.decimal_places')->get() as $bonus) {
                $existing = $totals[$bonus->currency]['amount'] ?? '0';
                $totals[$bonus->currency] = ['currency' => $bonus->currency, 'precision' => (int) $bonus->precision,
                    'amount' => bcadd($existing, (string) $bonus->amount, 2)];
            }
        }
        ksort($totals);

        return array_values($totals);
    }

    private function accounts(User $actor): array
    {
        $query = FoundationAccess::accounts(DB::table('accounts as a'), $actor, 'a.id');
        $total = (clone $query)->count();
        $items = $query->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')
            ->join('currencies as c', 'c.id', '=', 'a.currency_id')
            ->join('v_account_balances as bal', 'bal.account_id', '=', 'a.id')
            ->orderBy('a.name')->orderBy('a.id')->limit(5)
            ->get(['a.id', 'a.name', 'a.account_type', 'a.status', 'b.name as branch_name',
                'c.code as currency', 'c.decimal_places as precision', 'bal.balance'])->all();

        return ['total' => $total, 'items' => $items];
    }

    private function transactions(User $actor): array
    {
        return FoundationAccess::accounts(DB::table('account_transactions as t')
            ->join('accounts as a', 'a.id', '=', 't.account_id'), $actor, 'a.id')
            ->join('currencies as c', 'c.id', '=', 't.currency_id')
            ->orderByDesc('t.transaction_date')->orderByDesc('t.id')->limit(5)
            ->select('t.id', 't.transaction_date', 't.entry_type', 't.direction', 't.amount', 't.description',
                'a.name as account_name', 'c.code as currency', 'c.decimal_places as precision')
            ->selectRaw('EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=t.id) AS reversed')
            ->get()->all();
    }

    private function branches(User $actor, bool $stock): array
    {
        $query = InventoryService::branchScope(DB::table('branches as b'), $actor, 'b.id')->whereNull('b.deleted_at');
        $total = (clone $query)->count();
        $query->select('b.id', 'b.name', 'b.code', 'b.location', 'b.status', 'b.is_main');
        if ($stock) {
            $query->selectSub(DB::table('v_current_stock as s')->selectRaw('COUNT(*)')
                ->whereColumn('s.custody_branch_id', 'b.id')->whereNull('s.deleted_at')
                ->where('s.stock_status', 'in_stock'), 'available');
        }

        return ['total' => $total, 'items' => $query->orderByDesc('b.is_main')->orderBy('b.name')
            ->orderBy('b.id')->limit(5)->get()->all()];
    }
}
