<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Immutable washing visits and inventory cost vouchers; cash is posted only on payment. */
class WashingService
{
    public static function scope(Builder $query, User $actor, string $branch = 'o.branch_id', string $facility = 'o.facility_id'): Builder
    {
        return FoundationAccess::administrator($actor) ? $query : $query->where(function ($q) use ($actor, $branch, $facility) {
            $q->whereIn($branch, FoundationAccess::branchIds($actor))->orWhereIn($facility, FoundationAccess::facilityIds($actor));
        });
    }

    public static function orders(User $actor): Builder
    {
        return self::scope(DB::table('washing_orders as o'), $actor)
            ->join('branches as b', 'b.id', '=', 'o.branch_id')
            ->join('washing_facilities as f', 'f.id', '=', 'o.facility_id')
            ->join('stock_locations as l', 'l.id', '=', 'o.facility_location_id')
            ->select('o.*', 'b.name as branch_name', 'f.name as facility_name', 'l.name as location_name')
            ->selectRaw('(SELECT COUNT(*) FROM washing_order_items i WHERE i.washing_order_id=o.id) AS item_count')
            ->selectRaw("(SELECT COUNT(*) FROM washing_order_items i JOIN stock_events e ON e.washing_order_item_id=i.id AND e.event_type='washing_complete' WHERE i.washing_order_id=o.id) AS completed_count")
            ->selectRaw("(SELECT COUNT(*) FROM washing_order_items i JOIN stock_events e ON e.washing_order_item_id=i.id AND e.event_type='washing_return' WHERE i.washing_order_id=o.id) AS returned_count");
    }

    public static function orderState(object $row): object
    {
        $row->state = $row->returned_count == $row->item_count ? 'returned'
            : ($row->returned_count > 0 ? 'partially_returned' : ($row->completed_count == $row->item_count
                ? 'completed' : ($row->completed_count > 0 ? 'partially_completed' : 'sent')));

        return $row;
    }

    public static function visits(User $actor): Builder
    {
        return self::scope(DB::table('washing_order_items as i')->join('washing_orders as o', 'o.id', '=', 'i.washing_order_id'), $actor)
            ->join('carpets as c', 'c.id', '=', 'i.carpet_id')
            ->join('washing_facilities as f', 'f.id', '=', 'o.facility_id')
            ->leftJoin('stock_events as ce', fn ($j) => $j->on('ce.washing_order_item_id', '=', 'i.id')->where('ce.event_type', 'washing_complete'))
            ->leftJoin('stock_events as re', fn ($j) => $j->on('re.washing_order_item_id', '=', 'i.id')->where('re.event_type', 'washing_return'))
            ->leftJoin('stock_locations as sl', 'sl.id', '=', 'i.source_location_id')
            ->leftJoin('stock_locations as rl', 'rl.id', '=', 're.resulting_location_id')
            ->select('i.*', 'o.id as order_id', 'o.order_no', 'o.sent_on', 'o.branch_id', 'o.facility_id', 'f.name as facility_name',
                'c.carpet_code', 'c.name', 'ce.occurred_on as completed_on', 're.occurred_on as returned_on',
                'sl.name as source_location_name', 'rl.name as return_location_name')
            ->selectRaw("CASE WHEN re.id IS NOT NULL THEN 'returned' WHEN ce.id IS NOT NULL THEN 'completed' ELSE 'sent' END AS state");
    }

    public static function bills(User $actor): Builder
    {
        return InventoryService::branchScope(DB::table('washing_bills as p'), $actor, 'p.branch_id')
            ->join('branches as b', 'b.id', '=', 'p.branch_id')->join('washing_facilities as f', 'f.id', '=', 'p.facility_id')
            ->join('currencies as c', 'c.id', '=', 'p.currency_id')->join('v_washing_bill_balances as bal', 'bal.bill_id', '=', 'p.id')
            ->select('p.*', 'b.name as branch_name', 'f.name as facility_name', 'c.code as currency_code',
                'bal.paid_amount', 'bal.remaining_amount', 'bal.state');
    }

    private function facility(User $actor, int $id): object
    {
        $query = DB::table('washing_facilities')->where('id', $id);
        if (! FoundationAccess::administrator($actor) && ! FoundationAccess::branchIds($actor)) {
            $query->whereIn('id', FoundationAccess::facilityIds($actor));
        }
        $facility = $query->first();
        abort_unless($facility, 404);
        if ($facility->status !== 'active' || $facility->deleted_at) {
            InventoryService::fail('facility_id', 'Choose an active washing facility.');
        }

        return $facility;
    }

    private function unique(string $table, string $column, string $value): void
    {
        if (DB::table($table)->where($column, $value)->exists()) {
            InventoryService::fail($column, 'This reference is already in use.');
        }
    }

    private function stockEvent(User $actor, object $carpet, int $visit, string $type, int $location, string $date): void
    {
        $status = ['washing_dispatch' => 'at_washing', 'washing_complete' => 'washing_completed', 'washing_return' => 'in_stock'][$type];
        $id = DB::table('stock_events')->insertGetId(['carpet_id' => $carpet->id, 'sequence_no' => $carpet->sequence_no + 1,
            'event_type' => $type, 'resulting_status' => $status, 'resulting_location_id' => $location,
            'washing_order_item_id' => $visit, 'occurred_on' => $date, 'handled_by' => $actor->id, 'created_at' => now()]);
        AuditLogger::log('create', 'stock_events', $id, null, (array) DB::table('stock_events')->find($id));
    }

    public function dispatch(User $actor, array $data): array
    {
        $data['order_no'] = $data['order_no'] ?? DocumentNumbers::next('MWASH');
        app(InventoryService::class)->branch($actor, $data['branch_id']);
        $this->facility($actor, $data['facility_id']);
        $location = DB::table('stock_locations')->where('id', $data['facility_location_id'])
            ->where('washing_facility_id', $data['facility_id'])->where('status', 'active')->first();
        if (! $location) {
            InventoryService::fail('facility_location_id', 'Choose an active location belonging to this washing facility.');
        }
        $this->unique('washing_orders', 'order_no', $data['order_no']);
        $items = $data['items'];
        unset($data['items']);
        $id = DB::table('washing_orders')->insertGetId($data + ['created_by' => $actor->id, 'created_at' => now()]);
        foreach ($items as $item) {
            $carpet = InventoryService::carpets($actor)->where('s.id', $item['carpet_id'])->first();
            abort_unless($carpet, 404);
            abort_unless((int) $carpet->sequence_no === (int) $item['expected_sequence'], 409, 'Stock changed. Reload before sending for washing.');
            if (! in_array($carpet->stock_status, ['purchased', 'in_stock', 'production_completed'], true)
                || (int) $carpet->custody_branch_id !== (int) $data['branch_id']
                || (! $carpet->acquisition_item_id && ! $carpet->production_order_id && ! $carpet->manager_production_order_id)) {
                InventoryService::fail('items', 'Choose purchased, completed-production or in-stock carpets belonging to the sending branch.');
            }
            if ($data['sent_on'] < $carpet->occurred_on) {
                InventoryService::fail('sent_on', 'Dispatch date cannot precede the previous stock event.');
            }
            $visit = DB::table('washing_order_items')->insertGetId(['washing_order_id' => $id, 'carpet_id' => $carpet->id,
                'acquisition_item_id' => $carpet->acquisition_item_id, 'source_location_id' => $carpet->location_id]);
            $this->stockEvent($actor, $carpet, $visit, 'washing_dispatch', $location->id, $data['sent_on']);
        }
        AuditLogger::log('create', 'washing_orders', $id, null, $data + ['items' => $items]);

        return ['id' => $id, 'order_no' => $data['order_no']];
    }

    public function progress(User $actor, int $id, array $data, bool $return): array
    {
        $order = self::scope(DB::table('washing_orders as o'), $actor)->where('o.id', $id)->first();
        abort_unless($order, 404);
        $date = $data[$return ? 'returned_on' : 'completed_on'];
        if ($return) {
            app(InventoryService::class)->branch($actor, $order->branch_id);
            $location = InventoryService::branchScope(DB::table('stock_locations'), $actor)->where('id', $data['location_id'])
                ->where('branch_id', $order->branch_id)->whereNull('washing_facility_id')->where('status', 'active')->first();
            if (! $location) {
                InventoryService::fail('location_id', 'Return to an active stock location of the sending branch; transfer afterward if needed.');
            }
        } else {
            // Completing an existing visit remains possible after a reference is archived.
            $location = (object) ['id' => $order->facility_location_id];
        }
        foreach ($data['items'] as $item) {
            $visit = DB::table('washing_order_items')->where('id', $item['washing_order_item_id'])->where('washing_order_id', $id)->first();
            if (! $visit) {
                InventoryService::fail('items', 'Choose visits belonging to this washing batch.');
            }
            $carpet = DB::table('v_current_stock')->where('id', $visit->carpet_id)->whereNull('deleted_at')->firstOrFail();
            abort_unless((int) $carpet->sequence_no === (int) $item['expected_sequence'], 409, 'Stock changed. Reload this washing batch.');
            $latest = DB::table('stock_events')->where('carpet_id', $carpet->id)->where('sequence_no', $carpet->sequence_no)->first();
            if ((int) $latest->washing_order_item_id !== (int) $visit->id || $carpet->stock_status !== ($return ? 'washing_completed' : 'at_washing')) {
                InventoryService::fail('items', $return ? 'Only completed carpets still at this facility can be returned.' : 'Only carpets awaiting completion at this facility can be completed.');
            }
            if ($date < $carpet->occurred_on) {
                InventoryService::fail($return ? 'returned_on' : 'completed_on', 'This date cannot precede the previous stock event.');
            }
            $this->stockEvent($actor, $carpet, $visit->id, $return ? 'washing_return' : 'washing_complete', $location->id, $date);
            if ($return && $carpet->manager_production_order_id) {
                app(ManagerProductionService::class)->acceptWashing($actor, $visit->id, $date);
            }
        }

        return ['id' => $id, 'order_no' => $order->order_no];
    }

    public function bill(User $actor, array $data, object $settings): array
    {
        $data['expense_no'] = $data['expense_no'] ?? DocumentNumbers::next('WBILL');
        app(InventoryService::class)->branch($actor, $data['branch_id']);
        $this->facility($actor, $data['facility_id']);
        $currency = DB::table('currencies')->where('id', $data['currency_id'])->where('is_active', true)->first();
        if (! $settings->base_currency_id || ! $currency) {
            InventoryService::fail('currency_id', 'Configure the base currency and choose an active currency.');
        }
        InventoryService::precision($data['amount'], $currency, 'amount');
        if ((int) $currency->id === (int) $settings->base_currency_id && bccomp($data['rate_to_base'], '1', 8) !== 0) {
            InventoryService::fail('rate_to_base', 'Base-currency entries must use a rate of 1.');
        }
        $this->unique('washing_bills', 'expense_no', $data['expense_no']);
        $data['invoice_no'] = trim($data['invoice_no']);
        if (DB::table('washing_bills')->where('facility_id', $data['facility_id'])->where('invoice_no', $data['invoice_no'])->exists()) {
            InventoryService::fail('invoice_no', 'This facility invoice is already recorded, including voided vouchers.');
        }
        $total = '0';
        foreach ($data['allocations'] as $index => $allocation) {
            $visit = DB::table('washing_order_items as i')->join('washing_orders as o', 'o.id', '=', 'i.washing_order_id')
                ->where('i.id', $allocation['washing_order_item_id'])->where('o.branch_id', $data['branch_id'])
                ->where('o.facility_id', $data['facility_id'])->first(['o.sent_on', 'i.carpet_id']);
            if (! $visit || $data['billed_on'] < $visit->sent_on) {
                InventoryService::fail('allocations', 'Allocate only visits at this facility and branch, on or after their dispatch date.');
            }
            if (DB::table('trade_sale_items')->where('carpet_id', $visit->carpet_id)->exists()) {
                InventoryService::fail('allocations', 'Costs are fixed after a carpet is sold.');
            }
            if (DB::table('washing_bill_allocations as a')->where('a.washing_order_item_id', $allocation['washing_order_item_id'])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('washing_bill_voids as v')->whereColumn('v.washing_bill_id', 'a.washing_bill_id'))->exists()) {
                InventoryService::fail('allocations', 'This washing visit already has an active cost allocation.');
            }
            InventoryService::precision($allocation['amount'], $currency, "allocations.{$index}.amount");
            $total = bcadd($total, $allocation['amount'], 2);
        }
        if (bccomp($total, $data['amount'], 2) !== 0) {
            InventoryService::fail('allocations', 'Allocated costs must equal the bill amount exactly.');
        }
        $allocations = $data['allocations'];
        unset($data['allocations']);
        $base = DB::table('currencies')->find($settings->base_currency_id);
        $data['amount_base'] = InventoryService::converted($data['amount'], $data['rate_to_base'], $base->decimal_places);
        if (bccomp($data['amount_base'], '9999999999999999.99', 2) > 0) {
            InventoryService::fail('amount', 'Converted amount exceeds the supported limit.');
        }
        $id = DB::table('washing_bills')->insertGetId($data + ['created_by' => $actor->id, 'created_at' => now()]);
        // Allocate the rounded base total by cumulative differences so it reconciles to the voucher exactly.
        $cumulative = $previousBase = '0';
        foreach ($allocations as $allocation) {
            $cumulative = bcadd($cumulative, $allocation['amount'], 2);
            $cumulativeBase = InventoryService::converted($cumulative, $data['rate_to_base'], $base->decimal_places);
            DB::table('washing_bill_allocations')->insert($allocation + ['washing_bill_id' => $id, 'amount_base' => bcsub($cumulativeBase, $previousBase, 2)]);
            $previousBase = $cumulativeBase;
        }
        AuditLogger::log('create', 'washing_bills', $id, null, $data + ['allocations' => $allocations]);

        return ['id' => $id, 'expense_no' => $data['expense_no']];
    }

    public function payment(User $actor, int $id, array $data, string $key): array
    {
        $data['payment_no'] = $data['payment_no'] ?? DocumentNumbers::next('WMPAY');
        $bill = self::bills($actor)->where('p.id', $id)->first();
        abort_unless($bill, 404);
        if ($bill->state !== 'posted') {
            InventoryService::fail('bill_id', 'A voided bill cannot be paid.');
        }
        $account = FoundationAccess::accounts(DB::table('accounts'), $actor)->where('id', $data['account_id'])->first();
        abort_unless($account, 404);
        if ($data['payment_on'] < $bill->billed_on) {
            InventoryService::fail('payment_on', 'Payment cannot precede the bill date.');
        }
        if ((int) $account->currency_id === (int) $bill->currency_id && bccomp($data['bill_units_per_payment_unit'], '1', 8) !== 0) {
            InventoryService::fail('bill_units_per_payment_unit', 'Same-currency payments must use a conversion of 1.');
        }
        $currency = DB::table('currencies')->find($bill->currency_id);
        $settled = InventoryService::converted($data['amount'], $data['bill_units_per_payment_unit'], $currency->decimal_places);
        if (bccomp($settled, '0', 2) <= 0 || bccomp($settled, $bill->remaining_amount, 2) > 0) {
            InventoryService::fail('amount', 'The converted payment must be positive and cannot exceed the remaining bill balance.');
        }
        $this->unique('washing_payments', 'payment_no', $data['payment_no']);
        $entry = app(LedgerService::class)->post($actor, ['account_id' => $account->id, 'entry_type' => 'expense',
            'amount' => $data['amount'], 'rate_to_base' => $data['rate_to_base'], 'transaction_date' => $data['payment_on'],
            'description' => $data['reason']], $key.':ledger')['entry'];
        $payment = DB::table('washing_payments')->insertGetId(['payment_no' => $data['payment_no'], 'washing_bill_id' => $id,
            'account_transaction_id' => $entry->id, 'amount_in_bill_currency' => $settled,
            'bill_units_per_payment_unit' => $data['bill_units_per_payment_unit'], 'created_by' => $actor->id, 'created_at' => now()]);
        $saved = (array) DB::table('washing_payments')->find($payment);
        AuditLogger::log('create', 'washing_payments', $payment, null, $saved);

        return $saved;
    }

    public function void(User $actor, int $id, array $data): array
    {
        $sold = DB::table('washing_bill_allocations as a')->join('washing_order_items as i', 'i.id', '=', 'a.washing_order_item_id')
            ->join('trade_sale_items as s', 's.carpet_id', '=', 'i.carpet_id')->where('a.washing_bill_id', $id)->exists();
        abort_if($sold, 422, 'A washing cost included in a sold carpet cannot be voided.');
        $bill = self::bills($actor)->where('p.id', $id)->first();
        abort_unless($bill, 404);
        if ($bill->state !== 'posted' || bccomp($bill->paid_amount, '0', 2) !== 0) {
            InventoryService::fail('reason', 'Reverse all active payments before voiding an unvoided bill.');
        }
        $latestPayment = DB::table('washing_payments as w')->join('account_transactions as t', 't.id', '=', 'w.account_transaction_id')
            ->leftJoin('account_transactions as r', 'r.reversal_of_id', '=', 't.id')->where('w.washing_bill_id', $id)
            ->selectRaw('MAX(GREATEST(t.transaction_date,COALESCE(r.transaction_date,t.transaction_date))) AS latest')->value('latest');
        if ($data['voided_on'] < $bill->billed_on || ($latestPayment && $data['voided_on'] < $latestPayment)) {
            InventoryService::fail('voided_on', 'Void date cannot precede the bill or its payment/reversal dates.');
        }
        $void = DB::table('washing_bill_voids')->insertGetId($data + ['washing_bill_id' => $id, 'created_by' => $actor->id, 'created_at' => now()]);
        AuditLogger::log('create', 'washing_bill_voids', $void, null, $data + ['washing_bill_id' => $id]);

        return ['id' => $id, 'state' => 'void'];
    }

    public function reverse(User $actor, int $id, array $data, string $key): array
    {
        $payment = InventoryService::branchScope(DB::table('washing_payments as w')->join('washing_bills as b', 'b.id', '=', 'w.washing_bill_id'), $actor, 'b.branch_id')
            ->where('w.id', $id)->first(['w.*']);
        abort_unless($payment, 404);
        $entry = app(LedgerService::class)->reverse($actor, $payment->account_transaction_id, $data['reason'], $key.':ledger')['entry'];

        return ['id' => $id, 'account_transaction_id' => $entry->id, 'reversed' => true];
    }

    public static function carpetCosts(User $actor, int $carpetId): array
    {
        return InventoryService::branchScope(DB::table('washing_bill_allocations as a')->join('washing_bills as b', 'b.id', '=', 'a.washing_bill_id'), $actor, 'b.branch_id')
            ->join('washing_order_items as i', 'i.id', '=', 'a.washing_order_item_id')->join('currencies as c', 'c.id', '=', 'b.currency_id')
            ->where('i.carpet_id', $carpetId)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('washing_bill_voids as v')->whereColumn('v.washing_bill_id', 'b.id'))
            ->select('c.id as currency_id', 'c.code as currency_code')->selectRaw('SUM(a.amount) AS total_amount,SUM(a.amount_base) AS total_base_amount')
            ->groupBy('c.id', 'c.code')->orderBy('c.code')->get()->all();
    }
}
