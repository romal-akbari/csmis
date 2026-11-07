<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Posted documents, stock events and money are committed together, once. */
class InventoryService
{
    public static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function branchScope(Builder $query, User $actor, string $column = 'branch_id'): Builder
    {
        return FoundationAccess::administrator($actor) ? $query
            : $query->whereIn($column, FoundationAccess::branchIds($actor));
    }

    public static function carpets(User $actor): Builder
    {
        return self::branchScope(DB::table('v_current_stock as s'), $actor, 's.custody_branch_id')
            ->whereNull('s.deleted_at');
    }

    public function command(User $actor, string $operation, array $data, string $key, Closure $work): array
    {
        return DB::transaction(function () use ($actor, $operation, $data, $key, $work) {
            // Same first lock as foundation/ledger mutations; deterministic cross-module order.
            $settings = DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $scope = FoundationAccess::branchIds($actor);
            sort($scope);
            $facilities = FoundationAccess::facilityIds($actor);
            sort($facilities);
            $context = [$actor->id, FoundationAccess::administrator($actor), $scope, $operation, $data];
            $context[] = FoundationAccess::accountIds($actor);
            if (str_starts_with($operation, 'washing:')) {
                $context[] = $facilities;
            }
            $hash = hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
            $old = DB::table('inventory_commands')->where('idempotency_key', $key)->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'This idempotency key belongs to a different request.');

                return ['data' => json_decode($old->response, true, 512, JSON_THROW_ON_ERROR), 'replayed' => true];
            }
            $response = $work($settings);
            DB::table('inventory_commands')->insert(['idempotency_key' => $key, 'actor_id' => $actor->id,
                'operation' => $operation, 'request_hash' => $hash,
                'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return ['data' => $response, 'replayed' => false];
        }, 3);
    }

    public function branch(User $actor, int $id): object
    {
        $branch = self::branchScope(DB::table('branches'), $actor, 'id')->where('id', $id)->first();
        abort_unless($branch, 404);
        if ($branch->status !== 'active' || $branch->deleted_at) {
            self::fail('branch_id', 'Choose an active branch.');
        }

        return $branch;
    }

    private function location(User $actor, int $id): object
    {
        $row = self::branchScope(DB::table('stock_locations'), $actor)->where('id', $id)->first();
        abort_unless($row, 404);
        if ($row->status !== 'active' || ! $row->branch_id || $row->washing_facility_id) {
            self::fail('location_id', 'Choose an active branch stock location.');
        }
        $this->branch($actor, $row->branch_id);

        return $row;
    }

    private function active(string $table, int $id, string $field, string $status = 'status'): object
    {
        $row = DB::table($table)->find($id);
        if (! $row || ($status === 'is_active' ? ! $row->is_active : $row->status !== 'active')) {
            self::fail($field, 'Choose an active record.');
        }

        return $row;
    }

    private function unique(string $table, string $column, string $value, ?int $except = null): void
    {
        $query = DB::table($table)->where($column, $value);
        if ($except) {
            $query->where('id', '<>', $except);
        }
        if ($query->exists()) {
            self::fail($column, 'This reference is already in use.');
        }
    }

    public static function precision(string $amount, object $currency, string $field): void
    {
        if (bccomp($amount, bcadd($amount, '0', $currency->decimal_places), 2) !== 0) {
            self::fail($field, 'Amount exceeds the currency precision.');
        }
    }

    public function carpet(User $actor, array $data, ?int $id = null): array
    {
        $old = $id ? self::carpets($actor)->where('s.id', $id)->first() : null;
        abort_if($id && ! $old, 404);
        if ($old && (int) $old->version !== (int) $data['version']) {
            abort(409, 'This carpet changed. Reload it before editing.');
        }
        if (! $old || ! $old->sequence_no || (int) $old->registration_branch_id !== (int) $data['registration_branch_id']) {
            $this->branch($actor, $data['registration_branch_id']);
        }
        $this->active('carpet_categories', $data['category_id'], 'category_id');
        $this->unique('carpets', 'carpet_code', $data['carpet_code'], $id);
        if ($old && ($old->sequence_no > 0 || $old->production_order_id || $old->manager_production_order_id) && ($old->carpet_code !== $data['carpet_code']
            || (int) $old->registration_branch_id !== (int) $data['registration_branch_id'])) {
            self::fail('carpet_code', 'Purchased carpet code and registration branch are fixed.');
        }
        unset($data['version']);
        $data += ['updated_by' => $actor->id, 'updated_at' => now()];
        if ($id) {
            $data['version'] = $old->version + 1;
            DB::table('carpets')->where('id', $id)->update($data);
        } else {
            $id = DB::table('carpets')->insertGetId($data + ['created_by' => $actor->id, 'created_at' => now()]);
        }
        $saved = (array) DB::table('carpets')->find($id);
        AuditLogger::log($old ? 'update' : 'create', 'carpets', $id, $old ? (array) $old : null, $saved);

        return $saved;
    }

    public function purchase(User $actor, array $data, object $settings, ?int $draftId = null): array
    {
        $data['purchase_no'] = ! empty($data['purchase_no']) ? $data['purchase_no'] : DocumentNumbers::next('PUR');
        if (! $settings->base_currency_id) {
            self::fail('base_currency_id',
                'Set the base currency in Company settings before posting transactions.');
        }
        $this->branch($actor, $data['branch_id']);
        if (! empty($data['weaver_id'])) {
            $weaver = DirectoryCatalog::scope(DB::table('weavers'), 'weavers', $actor)
                ->where('id', $data['weaver_id'])->whereNull('deleted_at')->first();
            abort_unless($weaver, 404);
            if ($weaver->status !== 'active') {
                self::fail('weaver_id', 'Choose an active weaver.');
            }
        } elseif (! DB::table('suppliers')->where('id', $data['supplier_id'] ?? 0)->where('status', 'active')->whereNull('deleted_at')->exists()) {
            self::fail('supplier_id', 'Choose an active supplier.');
        }
        $currency = $this->active('currencies', $data['currency_id'], 'currency_id', 'is_active');
        if ((int) $currency->id === (int) $settings->base_currency_id && bccomp($data['rate_to_base'], '1', 8) !== 0) {
            self::fail('rate_to_base', 'Base-currency entries must use a rate of 1.');
        }
        $this->unique('weaver_purchases', 'purchase_no', $data['purchase_no']);
        if (DB::table('purchase_drafts')->where('purchase_no', $data['purchase_no'])
            ->when($draftId, fn ($q) => $q->where('id', '<>', $draftId))->exists()) {
            self::fail('purchase_no', 'This reference belongs to a saved draft. Open and post that draft.');
        }
        $items = $data['items'];
        unset($data['items']);
        $id = DB::table('weaver_purchases')->insertGetId($data + ['created_by' => $actor->id, 'created_at' => now()]);
        foreach ($items as $index => $item) {
            $carpet = self::carpets($actor)->where('s.id', $item['carpet_id'])->first();
            abort_unless($carpet, 404);
            if (! in_array($carpet->stock_status, ['registered', 'returned'], true) || (int) $carpet->custody_branch_id !== (int) $data['branch_id'] || $carpet->production_order_id || $carpet->manager_production_order_id) {
                self::fail('items', 'Choose unpurchased carpets registered to the purchase branch.');
            }
            if ($carpet->occurred_on && $data['purchase_on'] < $carpet->occurred_on) {
                self::fail('purchase_on', 'Purchase date cannot precede the previous stock event.');
            }
            $this->active('carpet_categories', $carpet->category_id, 'items');
            self::precision($item['unit_price'], $currency, "items.{$index}.unit_price");
            $line = DB::table('weaver_purchase_items')->insertGetId(['weaver_purchase_id' => $id] + $item);
            $this->event($actor, $carpet, 'purchase', null, $data['purchase_on'], ['weaver_purchase_item_id' => $line]);
        }
        $saved = (array) DB::table('weaver_purchases')->find($id);
        $saved['balance'] = DB::table('v_weaver_purchase_balances')->where('purchase_id', $id)->first();
        AuditLogger::log('create', 'weaver_purchases', $id, null, $saved + ['items' => $items]);

        return $saved;
    }

    public function move(User $actor, array $data, bool $transfer): array
    {
        $table = $transfer ? 'stock_transfers' : 'stock_receipts';
        $number = $transfer ? 'transfer_no' : 'receipt_no';
        $data[$number] = $data[$number] ?? DocumentNumbers::next($transfer ? 'TRF' : 'REC');
        $date = $data[$transfer ? 'transferred_on' : 'received_on'];
        $to = $this->location($actor, $data[$transfer ? 'to_location_id' : 'location_id']);
        $from = $transfer ? $this->location($actor, $data['from_location_id']) : null;
        $this->unique($table, $number, $data[$number]);
        $items = $data['items'];
        unset($data['items']);
        $id = DB::table($table)->insertGetId($data + ['created_by' => $actor->id, 'created_at' => now()]);
        foreach ($items as $item) {
            $carpet = self::carpets($actor)->where('s.id', $item['carpet_id'])->first();
            abort_unless($carpet, 404);
            abort_unless((int) $carpet->sequence_no === (int) $item['expected_sequence'], 409,
                'Stock changed. Reload before receiving or transferring.');
            if ($transfer ? ($carpet->stock_status !== 'in_stock' || (int) $carpet->location_id !== (int) $from->id)
                : ($carpet->stock_status !== 'purchased' || (int) $carpet->custody_branch_id !== (int) $to->branch_id)) {
                self::fail('items', 'The selected carpet is not available at the expected source.');
            }
            if ($date < $carpet->occurred_on) {
                self::fail('items', 'Movement date cannot precede the previous event.');
            }
            $this->event($actor, $carpet, $transfer ? 'transfer' : 'receipt', $to->id, $date,
                [$transfer ? 'stock_transfer_id' : 'stock_receipt_id' => $id]);
        }
        $saved = (array) DB::table($table)->find($id);
        AuditLogger::log('create', $table, $id, null, $saved + ['items' => $items]);

        return $saved;
    }

    private function event(User $actor, object $carpet, string $type, ?int $location, string $date, array $source): void
    {
        $id = DB::table('stock_events')->insertGetId($source + ['carpet_id' => $carpet->id,
            'sequence_no' => $carpet->sequence_no + 1, 'event_type' => $type,
            'resulting_status' => $type === 'purchase' ? 'purchased' : 'in_stock',
            'resulting_location_id' => $location, 'occurred_on' => $date,
            'handled_by' => $actor->id, 'created_at' => now()]);
        AuditLogger::log('create', 'stock_events', $id, null, (array) DB::table('stock_events')->find($id));
    }

    /** Positive amounts round half up, without a binary float conversion. */
    public static function converted(string $amount, string $rate, int $places): string
    {
        $half = $places === 0 ? '0.5' : '0.'.str_repeat('0', $places).'5';

        return bcadd(bcmul($amount, $rate, 10), $half, $places);
    }

    public function payment(User $actor, array $data, string $key): array
    {
        $this->branch($actor, $data['branch_id']);
        $account = FoundationAccess::accounts(DB::table('accounts'), $actor)->where('id', $data['account_id'])->first();
        abort_unless($account, 404);
        $weaver = DB::table('weavers')->find($data['weaver_id']);
        abort_unless($weaver, 404);
        $this->unique('weaver_payments', 'payment_no', $data['payment_no']);
        if ($data['payment_type'] === 'additional') {
            $visible = DirectoryCatalog::scope(DB::table('weavers'), 'weavers', $actor)
                ->where('id', $weaver->id)->whereNull('deleted_at')->where('status', 'active')->exists();
            abort_unless($visible, 404);
            $this->active('expense_categories', $data['expense_category_id'], 'expense_category_id');
        }
        $sum = '0.00';
        $allocations = [];
        foreach ($data['allocations'] ?? [] as $index => $item) {
            $purchase = self::branchScope(DB::table('weaver_purchases'), $actor)->where('id', $item['weaver_purchase_id'])->first();
            abort_unless($purchase, 404);
            if ((int) $purchase->weaver_id !== (int) $weaver->id || (int) $purchase->branch_id !== (int) $data['branch_id']) {
                self::fail('allocations', 'Payment allocations must belong to the same weaver and branch.');
            }
            if ($data['payment_on'] < $purchase->purchase_on) {
                self::fail('payment_on', 'Payment date cannot precede purchase date.');
            }
            $currency = DB::table('currencies')->find($purchase->currency_id);
            if ((int) $account->currency_id === (int) $purchase->currency_id && bccomp($item['purchase_units_per_payment_unit'], '1', 8) !== 0) {
                self::fail('allocations', 'Same-currency allocations must use a rate of 1.');
            }
            $paymentCurrency = DB::table('currencies')->find($account->currency_id);
            self::precision($item['amount_in_payment_currency'], $paymentCurrency, "allocations.{$index}.amount_in_payment_currency");
            $amount = self::converted($item['amount_in_payment_currency'], $item['purchase_units_per_payment_unit'], $currency->decimal_places);
            if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, '9999999999999999.99', 2) > 0) {
                self::fail('allocations', 'Converted allocation is outside the allowed range.');
            }
            $remaining = DB::table('v_weaver_purchase_balances')->where('purchase_id', $purchase->id)->value('remaining_amount');
            if (bccomp($amount, (string) $remaining, 2) > 0) {
                self::fail('allocations', 'Payment exceeds the unpaid purchase balance.');
            }
            $sum = bcadd($sum, $item['amount_in_payment_currency'], 2);
            $allocations[] = $item + ['amount_in_purchase_currency' => $amount];
        }
        if ($data['payment_type'] === 'purchase' && (! $allocations || bccomp($sum, $data['amount'], 2) !== 0)) {
            self::fail('allocations', 'Allocate the entire payment to purchases.');
        }
        $entry = app(LedgerService::class)->post($actor, ['account_id' => $account->id,
            'entry_type' => 'expense', 'amount' => $data['amount'], 'rate_to_base' => $data['rate_to_base'],
            'transaction_date' => $data['payment_on'], 'description' => $data['reason']], 'weaver:'.hash('sha256', $key))['entry'];
        $id = DB::table('weaver_payments')->insertGetId([
            'payment_no' => $data['payment_no'], 'weaver_id' => $weaver->id, 'branch_id' => $data['branch_id'],
            'account_transaction_id' => $entry->id, 'payment_type' => $data['payment_type'],
            'expense_category_id' => $data['expense_category_id'] ?? null, 'reason' => $data['reason'],
            'created_by' => $actor->id, 'created_at' => now(),
        ]);
        foreach ($allocations as $allocation) {
            DB::table('weaver_payment_allocations')->insert(['weaver_payment_id' => $id] + $allocation);
        }
        $saved = (array) DB::table('weaver_payments')->find($id);
        AuditLogger::log('create', 'weaver_payments', $id, null, $saved + ['allocations' => $allocations]);

        return $saved;
    }
}
