<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class TradeService
{
    public static function saleTotal(int $id): string
    {
        return (string) DB::table('trade_sale_items')->where('sale_id', $id)->selectRaw('COALESCE(SUM(unit_price-discount),0) amount')->value('amount');
    }

    public function sale(User $actor, array $data, object $settings, string $key): array
    {
        app(InventoryService::class)->branch($actor, $data['branch_id']);
        $currency = BusinessSupport::currency($data, $settings);
        if (! DB::table('customers')->where('id', $data['customer_id'])->where('status', 'active')->whereNull('deleted_at')->exists()) {
            InventoryService::fail('customer_id', 'Choose an active customer.');
        }
        $items = isset($data['stock_groups'])
            ? SalesStockGroups::allocate($actor, (int) $data['branch_id'], $data['stock_groups']) : $data['items'];
        $payment = $data['payment'] ?? null;
        unset($data['items'], $data['stock_groups'], $data['payment']);
        $data['sale_no'] = DocumentNumbers::next('SALE');
        $id = BusinessSupport::insert($actor, 'trade_sales', $data);
        foreach ($items as $index => $item) {
            $field = isset($item['_stock_group']) ? 'stock_groups.'.$item['_stock_group'] : 'items.'.$index;
            unset($item['_stock_group']);
            $carpet = InventoryService::carpets($actor)->where('s.id', $item['carpet_id'])->first();
            abort_unless($carpet, 404);
            if ($carpet->stock_status !== 'in_stock' || (int) $carpet->custody_branch_id !== (int) $data['branch_id']
                || (int) $carpet->sequence_no !== (int) $item['expected_sequence']) {
                abort(409, 'Stock changed. Select an available carpet and reload its current version.');
            }
            $unbilled = DB::table('washing_order_items as w')->where('w.carpet_id', $carpet->id)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('washing_bill_allocations as a')
                    ->whereColumn('a.washing_order_item_id', 'w.id')
                    ->whereNotExists(fn ($v) => $v->selectRaw('1')->from('washing_bill_voids as b')->whereColumn('b.washing_bill_id', 'a.washing_bill_id')))->exists();
            if ($unbilled) {
                InventoryService::fail($field, 'Record the washing bill for every visit before selling this carpet.');
            }
            $latestCost = DB::table('washing_bill_allocations as a')->join('washing_order_items as w', 'w.id', '=', 'a.washing_order_item_id')
                ->join('washing_bills as b', 'b.id', '=', 'a.washing_bill_id')->where('w.carpet_id', $carpet->id)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('washing_bill_voids as v')->whereColumn('v.washing_bill_id', 'b.id'))->max('b.billed_on');
            if ($latestCost && $data['sold_on'] < $latestCost) {
                InventoryService::fail('sold_on', 'Sale date cannot precede the recorded washing costs.');
            }
            InventoryService::precision($item['unit_price'], $currency, $field.'.unit_price');
            InventoryService::precision($item['discount'], $currency, $field.'.discount');
            if (bccomp($item['discount'], $item['unit_price'], 2) >= 0) {
                InventoryService::fail($field.'.discount', 'Discount must be smaller than the carpet price.');
            }
            unset($item['expected_sequence']);
            $cost = BusinessSupport::costBase($carpet->id);
            if (bccomp($cost, '9999999999999999.99', 2) > 0) {
                InventoryService::fail('items', 'Carpet cost exceeds the supported range.');
            }
            $line = DB::table('trade_sale_items')->insertGetId($item + ['sale_id' => $id, 'cost_base' => $cost]);
            BusinessSupport::stockEvent($actor, $carpet, 'sale', null, $data['sold_on'], ['trade_sale_item_id' => $line]);
        }
        if ($payment) {
            FoundationAccess::permit($actor, 'sales.collect');
            $this->collect($actor, $id, validator($payment, BusinessRules::payment())->validate(), $key);
        }

        return ['id' => $id, 'sale_no' => $data['sale_no']];
    }

    public function collect(User $actor, int $id, array $data, string $key): array
    {
        $sale = BusinessSupport::document($actor, 'trade_sales', $id);
        $remaining = bcsub(self::saleTotal($id), BusinessSupport::paid('trade_customer_receipts', 'sale_id', $id), 2);
        $entry = BusinessSupport::money($actor, $sale, $data, $key, 'in', $remaining);

        return ['id' => BusinessSupport::insert($actor, 'trade_customer_receipts', $entry + ['sale_id' => $id])];
    }

    public static function settlementPreview(User $actor, int $id): array
    {
        $order = BusinessSupport::document($actor, 'production_orders', $id);
        abort_unless($order->model === 'partnership', 404);
        $items = DB::table('production_carpets as p')->leftJoin('trade_sale_items as i', 'i.carpet_id', '=', 'p.carpet_id')
            ->leftJoin('trade_sales as s', 's.id', '=', 'i.sale_id')->where('p.production_order_id', $id)
            ->get(['p.carpet_id', 'i.sale_id', 'i.unit_price', 'i.discount', 'i.cost_base', 's.rate_to_base', 's.branch_id', 's.sold_on']);
        $revenue = $companyCost = $partnerCost = $supportPaid = '0';
        $received = ProductionService::state($id) === 'received';
        $ready = $received;
        $latest = DB::table('production_receipts')->where('production_order_id', $id)->value('received_on') ?? $order->ordered_on;
        foreach ($items as $item) {
            if ((! $item->sale_id && ! InventoryService::carpets($actor)->where('s.id', $item->carpet_id)->exists())
                || ($item->sale_id && ! FoundationAccess::administrator($actor) && ! in_array((int) $item->branch_id, FoundationAccess::branchIds($actor), true))) {
                return ['ready' => false, 'earliest_date' => $latest];
            }
            if ($received) {
                $companyCost = bcadd($companyCost, BusinessSupport::costBase($item->carpet_id), 2);
            }
            if (! $item->sale_id) {
                $ready = false;

                continue;
            }
            $revenue = bcadd($revenue, BusinessSupport::base(bcsub($item->unit_price, $item->discount, 2), $item->rate_to_base), 2);
            $remaining = bcsub(self::saleTotal($item->sale_id), BusinessSupport::paid('trade_customer_receipts', 'sale_id', $item->sale_id), 2);
            if (bccomp($remaining, '0', 2) !== 0) {
                $ready = false;
            }
            $paidOn = DB::table('trade_customer_receipts as p')->join('account_transactions as t', 't.id', '=', 'p.account_transaction_id')
                ->where('p.sale_id', $item->sale_id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('account_transactions as r')->whereColumn('r.reversal_of_id', 't.id'))->max('t.transaction_date');
            $latest = max($latest, $item->sold_on, $paidOn ?? '');
        }
        $contributions = (string) DB::table('partnership_contributions')->where('production_order_id', $id)->sum('amount');
        $partnerCost = BusinessSupport::base($contributions, $order->rate_to_base);
        $companyCost = $received ? bcsub($companyCost, $partnerCost, 2)
            : (string) DB::table('material_movements')->where('production_order_id', $id)->sum('cost_base');
        $support = DB::table('production_payments as p')->join('account_transactions as t', 't.id', '=', 'p.account_transaction_id')
            ->where('p.production_order_id', $id)->where('p.payment_type', 'support')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('account_transactions as r')->whereColumn('r.reversal_of_id', 't.id'))->get(['t.amount', 't.rate_to_base', 't.transaction_date']);
        foreach ($support as $payment) {
            $supportPaid = bcadd($supportPaid, BusinessSupport::base($payment->amount, $payment->rate_to_base), 2);
            $latest = max($latest, $payment->transaction_date);
        }
        if ($order->share_basis === 'gross') {
            $company = bcadd(bcdiv(bcmul($revenue, $order->company_share, 6), '100', BusinessSupport::basePrecision()), '0', 2);
            $partner = bcsub($revenue, $company, 2);
        } else {
            $profit = bcsub(bcsub($revenue, $companyCost, 2), $partnerCost, 2);
            $percent = bccomp($profit, '0', 2) < 0 ? $order->company_loss_share : $order->company_share;
            $companyProfit = bcdiv(bcmul($profit, $percent, 6), '100', BusinessSupport::basePrecision());
            $company = bcadd($companyCost, $companyProfit, 2);
            $partner = bcsub($revenue, $company, 2);
        }

        return ['ready' => $ready, 'earliest_date' => $latest, 'revenue_base' => $revenue,
            'company_cost_base' => $companyCost, 'partner_cost_base' => $partnerCost,
            'company_entitlement_base' => $company, 'partner_entitlement_base' => $partner,
            'partner_paid_base' => $supportPaid, 'balance_due_base' => bcsub($partner, $supportPaid, 2)];
    }

    public function settle(User $actor, int $id, array $data): array
    {
        abort_if(DB::table('partnership_settlements')->where('production_order_id', $id)->exists(), 409, 'This partnership already has a settlement.');
        $preview = self::settlementPreview($actor, $id);
        if (! $preview['ready'] || $data['settled_on'] < $preview['earliest_date']) {
            InventoryService::fail('settled_on', 'Receive and sell all partnership carpets, collect all customer balances, then choose a settlement date after those transactions.');
        }
        unset($preview['ready'], $preview['earliest_date']);

        return ['id' => BusinessSupport::insert($actor, 'partnership_settlements', $data + $preview + ['production_order_id' => $id])];
    }

    public function settlementPay(User $actor, int $id, array $data, string $key): array
    {
        $order = BusinessSupport::document($actor, 'production_orders', $id);
        $settlement = DB::table('partnership_settlements')->where('production_order_id', $id)->first();
        abort_unless($settlement, 422, 'Calculate and save the partnership settlement first.');
        $paid = (string) DB::table('partnership_settlement_payments as p')->where('settlement_id', $settlement->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('account_transactions as r')->whereColumn('r.reversal_of_id', 'p.account_transaction_id'))->sum('p.amount_base');
        $negative = bccomp($settlement->balance_due_base, '0', 2) < 0;
        $owed = $negative ? bcsub('0', $settlement->balance_due_base, 2) : $settlement->balance_due_base;
        $document = (object) ['currency_id' => DB::table('company_settings')->value('base_currency_id'), 'ordered_on' => $settlement->settled_on];
        $entry = BusinessSupport::money($actor, $document, $data, $key, $negative ? 'in' : 'out', bcsub($owed, $paid, 2));

        return ['id' => BusinessSupport::insert($actor, 'partnership_settlement_payments', ['settlement_id' => $settlement->id,
            'account_transaction_id' => $entry['account_transaction_id'], 'amount_base' => $entry['document_amount']])];
    }
}
