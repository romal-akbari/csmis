<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** A permission-scoped read model; money is never combined across currencies. */
class DashboardOverview
{
    public function read(User $actor, int $days): array
    {
        return DB::transaction(function () use ($actor, $days) {
            $permissions = $actor->permissionCodes();
            $allows = fn ($code) => in_array($code, $permissions, true);
            $administrator = FoundationAccess::administrator($actor);
            $branches = FoundationAccess::branchIds($actor);
            $scope = fn (Builder $query, string $column) => $administrator
                ? $query : $query->whereIn($column, $branches);
            $settings = DB::table('company_settings')->where('id', 1)->firstOrFail();
            $now = CarbonImmutable::now($settings->timezone);
            $stock = $allows('stock.view')
                ? $this->stock($scope, $now, $days) : null;

            return [
                'as_of' => $now->toIso8601String(),
                'timezone' => $settings->timezone,
                'days' => $days,
                'metrics' => [
                    'at_washing' => $stock === null ? null : ($stock['counts']['at_washing'] ?? 0) + ($stock['counts']['washing_completed'] ?? 0),
                    'managers' => $allows('production.view') ? $scope(DB::table('production_managers'), 'branch_id')->where('status', 'active')->count() : null,
                    'available' => $stock['counts']['in_stock'] ?? null,
                    'awaiting_receipt' => $stock['counts']['purchased'] ?? null,
                    'customers' => $allows('customers.view') ? DB::table('customers')
                        ->whereNull('deleted_at')->where('status', 'active')->count() : null,
                    'weavers' => $allows('weavers.view')
                        ? DirectoryCatalog::scope(DB::table('weavers'), 'weavers', $actor)
                            ->whereNull('deleted_at')->where('status', 'active')->count() : null,
                ],
                'stock' => $stock,
                'finance' => [
                    'manager_payables' => $allows('production.view') ? $this->managerPayables($scope) : null,
                    'supplier_payables' => $allows('materials.view') ? app(SupplyPurchaseReadModel::class)->totals($actor) : null,
                    'payables' => $allows('weaver-purchases.view')
                        ? $this->payables($scope) : null,
                    'cash' => $allows('ledger.view') && $allows('accounts.view')
                        ? $this->cash($actor) : null,
                    'washing_payables' => $allows('washing-costs.view') ? $this->washingPayables($scope) : null,
                ],
                'business' => app(DashboardBusinessSummary::class)->read($actor, $permissions),
            ];
        });
    }

    private function stock(callable $scope, CarbonImmutable $now, int $days): array
    {
        $carpets = $scope(DB::table('v_current_stock as s'), 's.custody_branch_id')
            ->whereNull('s.deleted_at');
        $counts = array_fill_keys([
            'in_stock', 'purchased', 'registered', 'missing', 'returned', 'at_washing', 'washing_completed',
        ], 0);
        foreach ((clone $carpets)->select('s.stock_status')
            ->selectRaw('COUNT(*) AS total')->groupBy('s.stock_status')->get() as $row) {
            $counts[$row->stock_status] = (int) $row->total;
        }
        // Event visibility follows current carpet custody, like its movement history.
        $events = (clone $carpets)->join('stock_events as e', 'e.carpet_id', '=', 's.id');
        $start = $now->startOfDay()->subDays($days - 1);
        $groups = (clone $events)->whereBetween('e.occurred_on', [
            $start->toDateString(), $now->toDateString(),
        ])->whereIn('e.event_type', ['purchase', 'receipt', 'production_complete', 'washing_return'])
            ->select('e.occurred_on', 'e.event_type')->selectRaw('COUNT(*) AS total')
            ->groupBy('e.occurred_on', 'e.event_type')->get();
        $totals = [];
        foreach ($groups as $row) {
            $totals[$row->occurred_on][$row->event_type] = (int) $row->total;
        }
        $trend = [];
        for ($day = $start; $day->lte($now); $day = $day->addDay()) {
            $date = $day->toDateString();
            $trend[] = ['date' => $date, 'purchased' => $totals[$date]['purchase'] ?? 0,
                'produced' => $totals[$date]['production_complete'] ?? 0,
                'received' => ($totals[$date]['receipt'] ?? 0) + ($totals[$date]['washing_return'] ?? 0)];
        }
        $recent = (clone $events)
            ->leftJoin('stock_locations as l', 'l.id', '=', 'e.resulting_location_id')
            ->orderByDesc('e.occurred_on')->orderByDesc('e.id')->limit(5)
            ->get(['e.id', 'e.event_type', 'e.resulting_status', 'e.occurred_on',
                's.carpet_code', 's.name', 'l.name as location_name']);

        return ['counts' => $counts, 'trend' => $trend, 'recent' => $recent];
    }

    private function payables(callable $scope): array
    {
        return $scope(DB::table('weaver_purchases as p'), 'p.branch_id')
            ->whereNotNull('p.weaver_id')
            ->join('v_weaver_purchase_balances as b', 'b.purchase_id', '=', 'p.id')
            ->join('currencies as c', 'c.id', '=', 'p.currency_id')
            ->select('c.code as currency', 'c.decimal_places as precision')
            ->selectRaw('SUM(CASE WHEN b.remaining_amount > 0 '
                .'THEN b.remaining_amount ELSE 0 END) AS owed')
            ->selectRaw('SUM(CASE WHEN b.remaining_amount < 0 '
                .'THEN -b.remaining_amount ELSE 0 END) AS credit')
            ->groupBy('c.id', 'c.code', 'c.decimal_places')->orderBy('c.code')
            ->get()->map(fn ($row) => [
                'currency' => $row->currency, 'precision' => (int) $row->precision,
                'owed' => (string) $row->owed, 'credit' => (string) $row->credit,
            ])->all();
    }

    private function managerPayables(callable $scope): array
    {
        $earned = DB::table('manager_earned_charges')->select('production_order_id')->selectRaw('SUM(amount) amount')->groupBy('production_order_id');
        $paid = DB::table('manager_production_payments as payment')->select('payment.production_order_id')->selectRaw('SUM(payment.document_amount) amount')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('account_transactions as reversal')->whereColumn('reversal.reversal_of_id', 'payment.account_transaction_id'))
            ->groupBy('payment.production_order_id');

        return $scope(DB::table('manager_production_orders as orders'), 'orders.branch_id')
            ->leftJoinSub($earned, 'earned', 'earned.production_order_id', '=', 'orders.id')
            ->leftJoinSub($paid, 'paid', 'paid.production_order_id', '=', 'orders.id')->join('currencies as currency', 'currency.id', '=', 'orders.currency_id')
            ->select('currency.code as currency', 'currency.decimal_places as precision')
            ->selectRaw('SUM(GREATEST(COALESCE(earned.amount,0)-COALESCE(paid.amount,0),0)) owed')
            ->selectRaw('SUM(GREATEST(COALESCE(paid.amount,0)-COALESCE(earned.amount,0),0)) credit')
            ->groupBy('currency.id', 'currency.code', 'currency.decimal_places')->orderBy('currency.code')->get()->all();
    }

    private function cash(User $actor): array
    {
        return FoundationAccess::accounts(DB::table('accounts as a'), $actor, 'a.id')
            ->join('v_account_balances as b', 'b.account_id', '=', 'a.id')
            ->join('currencies as c', 'c.id', '=', 'a.currency_id')
            ->select('c.code as currency', 'c.decimal_places as precision')
            ->selectRaw('SUM(b.balance) AS balance')
            ->groupBy('c.id', 'c.code', 'c.decimal_places')->orderBy('c.code')
            ->get()->map(fn ($row) => [
                'currency' => $row->currency, 'precision' => (int) $row->precision,
                'balance' => (string) $row->balance,
            ])->all();
    }

    private function washingPayables(callable $scope): array
    {
        return $scope(DB::table('washing_bills as b'), 'b.branch_id')
            ->join('v_washing_bill_balances as bal', 'bal.bill_id', '=', 'b.id')
            ->join('currencies as c', 'c.id', '=', 'b.currency_id')->where('bal.state', 'posted')
            ->select('c.code as currency', 'c.decimal_places as precision')->selectRaw('SUM(bal.remaining_amount) AS owed')
            ->groupBy('c.id', 'c.code', 'c.decimal_places')->orderBy('c.code')->get()->map(fn ($row) => [
                'currency' => $row->currency, 'precision' => (int) $row->precision, 'owed' => (string) $row->owed,
            ])->all();
    }
}
