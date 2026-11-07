<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class BusinessSupport
{
    public static function receiptDirectory(): string
    {
        return 'expense-receipts/'.hash('sha256', DB::getDatabaseName());
    }

    public static function basePrecision(): int
    {
        return (int) DB::table('currencies')->where('id', DB::table('company_settings')->value('base_currency_id'))->value('decimal_places');
    }

    public static function base(string $amount, string $rate): string
    {
        return bcadd(InventoryService::converted($amount, $rate, self::basePrecision()), '0', 2);
    }

    public static function insert(User $actor, string $table, array $data): int
    {
        foreach ($data as $field => $amount) {
            if (($field === 'amount' || $field === 'document_amount' || $field === 'total_cost' || str_ends_with($field, '_base'))
                && is_string($amount) && bccomp(ltrim($amount, '-'), '9999999999999999.99', 2) > 0) {
                InventoryService::fail($field, 'Amount exceeds the supported range.');
            }
        }
        $id = DB::table($table)->insertGetId($data + ['created_by' => $actor->id, 'created_at' => now()]);
        AuditLogger::log('create', $table, $id, null, $data);

        return $id;
    }

    public static function currency(array $data, object $settings): object
    {
        $currency = DB::table('currencies')->where('id', $data['currency_id'])->where('is_active', true)->first();
        if (! $currency) {
            InventoryService::fail('currency_id', 'Choose an active currency.');
        }
        if ((int) $currency->id === (int) $settings->base_currency_id && bccomp($data['rate_to_base'], '1', 8) !== 0) {
            InventoryService::fail('rate_to_base', 'Base-currency entries must use a rate of 1.');
        }
        abort_unless($settings->base_currency_id, 422, 'Set the base currency in Company settings first.');

        return $currency;
    }

    public static function location(User $actor, int $id, ?int $branch = null): object
    {
        $row = InventoryService::branchScope(DB::table('stock_locations'), $actor)->where('id', $id)
            ->where('status', 'active')->whereNotNull('branch_id')->whereNull('washing_facility_id')->first();
        abort_unless($row, 404);
        app(InventoryService::class)->branch($actor, $row->branch_id);
        if ($branch !== null && (int) $row->branch_id !== $branch) {
            InventoryService::fail('location_id', 'Choose a stock location in the selected branch.');
        }

        return $row;
    }

    public static function document(User $actor, string $table, int $id): object
    {
        $row = InventoryService::branchScope(DB::table($table), $actor)->where('id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    public static function paid(string $table, string $column, int $id): string
    {
        return (string) DB::table($table.' as p')->where('p.'.$column, $id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('account_transactions as r')->whereColumn('r.reversal_of_id', 'p.account_transaction_id'))
            ->sum('document_amount');
    }

    public static function money(User $actor, object $document, array $data, string $key, string $direction, ?string $remaining = null): array
    {
        $account = FoundationAccess::accounts(DB::table('accounts'), $actor)->where('id', $data['account_id'])->first();
        abort_unless($account, 404);
        $currency = DB::table('currencies')->find($document->currency_id);
        $date = $data['payment_on'];
        $documentDate = $document->purchase_on ?? $document->expense_on ?? $document->received_on ?? $document->ordered_on ?? $document->sold_on ?? $document->assistance_on;
        if ($date < $documentDate) {
            InventoryService::fail('payment_on', 'Payment date cannot precede the document date.');
        }
        if ((int) $account->currency_id === (int) $currency->id && bccomp($data['units_per_payment_unit'], '1', 8) !== 0) {
            InventoryService::fail('units_per_payment_unit', 'Same-currency allocations must use a rate of 1.');
        }
        if ((int) $document->currency_id === (int) DB::table('company_settings')->value('base_currency_id')
            && bccomp($data['units_per_payment_unit'], $data['rate_to_base'], 8) !== 0) {
            InventoryService::fail('units_per_payment_unit', 'For a base-currency document, the settlement rate must equal the payment rate to base.');
        }
        $amount = InventoryService::converted($data['amount'], $data['units_per_payment_unit'], $currency->decimal_places);
        if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, '9999999999999999.99', 2) > 0
            || ($remaining !== null && bccomp($amount, $remaining, 2) > 0)) {
            InventoryService::fail('amount', 'Payment exceeds the remaining balance or rounds outside the allowed range.');
        }
        $entry = app(LedgerService::class)->post($actor, [
            'account_id' => $account->id, 'entry_type' => $direction === 'in' ? 'income' : 'expense',
            'amount' => $data['amount'], 'rate_to_base' => $data['rate_to_base'],
            'transaction_date' => $date, 'description' => $data['reason'],
        ], 'business:'.hash('sha256', $key))['entry'];

        return ['account_transaction_id' => $entry->id, 'document_amount' => $amount,
            'units_per_payment_unit' => $data['units_per_payment_unit']];
    }

    public static function stockEvent(User $actor, object $carpet, string $type, ?int $location, string $date, array $source): void
    {
        if ($carpet->occurred_on && $date < $carpet->occurred_on) {
            InventoryService::fail('date', 'Date cannot precede the latest carpet movement.');
        }
        $payload = $source + ['carpet_id' => $carpet->id,
            'sequence_no' => $carpet->sequence_no + 1, 'event_type' => $type,
            'resulting_status' => $type === 'sale' ? 'sold' : 'in_stock', 'resulting_location_id' => $location,
            'occurred_on' => $date, 'handled_by' => $actor->id, 'created_at' => now()];
        $id = DB::table('stock_events')->insertGetId($payload);
        AuditLogger::log('create', 'stock_events', $id, null, $payload);
    }

    public static function costBase(int $carpet): string
    {
        $purchased = DB::table('weaver_purchase_items as i')->join('weaver_purchases as p', 'p.id', '=', 'i.weaver_purchase_id')
            ->where('i.carpet_id', $carpet)->orderByDesc('i.id')->first(['i.unit_price', 'p.rate_to_base']);
        $produced = DB::table('production_receipt_items as i')->join('production_carpets as c', 'c.id', '=', 'i.production_carpet_id')
            ->where('c.carpet_id', $carpet)->value('i.cost_base');
        $managerProduced = DB::table('manager_delivery_items')->where('carpet_id', $carpet)->value('cost_base');
        $base = $managerProduced ?? $produced ?? ($purchased ? self::base($purchased->unit_price, $purchased->rate_to_base) : '0');
        $washing = DB::table('washing_bill_allocations as a')->join('washing_bills as b', 'b.id', '=', 'a.washing_bill_id')
            ->join('washing_order_items as i', 'i.id', '=', 'a.washing_order_item_id')->where('i.carpet_id', $carpet)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('washing_bill_voids as v')->whereColumn('v.washing_bill_id', 'b.id'))->sum('a.amount_base');

        return bcadd($base, (string) $washing, 2);
    }
}
