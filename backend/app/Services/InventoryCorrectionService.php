<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Append compensating facts; never erase posted stock or pretend cash was refunded. */
class InventoryCorrectionService
{
    public static function purchase(User $actor, int $id): object
    {
        $purchase = InventoryService::branchScope(DB::table('weaver_purchases'), $actor)->where('id', $id)->first();
        abort_unless($purchase, 404);

        return $purchase;
    }

    private function unique(string $table, string $field, string $number, ?int $id = null): void
    {
        if (DB::table($table)->where($field, $number)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            InventoryService::fail($field, 'This reference is already in use.');
        }
    }

    private function active(string $table, int $id, string $field): object
    {
        $row = DB::table($table)->find($id);
        if (! $row || (isset($row->status) ? $row->status !== 'active' : ! $row->is_active) || ! empty($row->deleted_at)) {
            InventoryService::fail($field, 'Choose an active record.');
        }

        return $row;
    }

    private function location(User $actor, int $id): object
    {
        $row = InventoryService::branchScope(DB::table('stock_locations'), $actor)->where('id', $id)->first();
        abort_unless($row, 404);
        if ($row->status !== 'active' || ! $row->branch_id) {
            InventoryService::fail('location_id', 'Choose an active branch stock location.');
        }
        app(InventoryService::class)->branch($actor, $row->branch_id);

        return $row;
    }

    private function current(User $actor, array $item, string $date): object
    {
        $carpet = InventoryService::carpets($actor)->where('s.id', $item['carpet_id'])->first();
        abort_unless($carpet, 404);
        abort_unless((int) $carpet->sequence_no === (int) $item['expected_sequence'], 409, 'Stock changed. Reload before correcting it.');
        if ($date < $carpet->occurred_on) {
            InventoryService::fail('items', 'Movement date cannot precede the previous event.');
        }

        return $carpet;
    }

    private function event(User $actor, object $carpet, string $type, string $status, ?int $location, string $date, array $source): void
    {
        $id = DB::table('stock_events')->insertGetId($source + ['carpet_id' => $carpet->id,
            'sequence_no' => $carpet->sequence_no + 1, 'event_type' => $type, 'resulting_status' => $status,
            'resulting_location_id' => $location, 'occurred_on' => $date, 'handled_by' => $actor->id, 'created_at' => now()]);
        AuditLogger::log('create', 'stock_events', $id, null, (array) DB::table('stock_events')->find($id));
    }

    public function draft(User $actor, array $data, ?int $id = null): array
    {
        $old = $id ? InventoryService::branchScope(DB::table('purchase_drafts'), $actor)->where('id', $id)->first() : null;
        abort_if($id && ! $old, 404);
        if ($old) {
            $this->draftVersion($old, $data['version']);
        }
        if ($old) {
            $old->items = DB::table('purchase_draft_items')->where('purchase_draft_id', $id)->get(['carpet_id', 'unit_price'])->all();
        }
        app(InventoryService::class)->branch($actor, $data['branch_id']);
        $data['purchase_no'] = ! empty($data['purchase_no']) ? $data['purchase_no'] : ($old->purchase_no ?? DocumentNumbers::next('DRAFT'));
        $this->unique('purchase_drafts', 'purchase_no', $data['purchase_no'], $id);
        $this->unique('weaver_purchases', 'purchase_no', $data['purchase_no']);
        if (! empty($data['weaver_id'])) {
            abort_unless(DirectoryCatalog::scope(DB::table('weavers'), 'weavers', $actor)->where('id', $data['weaver_id'])->exists(), 404);
            $this->active('weavers', $data['weaver_id'], 'weaver_id');
        }
        $currency = ! empty($data['currency_id']) ? $this->active('currencies', $data['currency_id'], 'currency_id') : null;
        if ($currency && ! empty($data['rate_to_base']) && (int) $currency->id === (int) DB::table('company_settings')->value('base_currency_id')
            && bccomp($data['rate_to_base'], '1', 8) !== 0) {
            InventoryService::fail('rate_to_base', 'Base-currency entries must use a rate of 1.');
        }
        $items = $data['items'];
        unset($data['items'], $data['version']);
        $data += ['updated_by' => $actor->id, 'updated_at' => now()];
        if ($id) {
            DB::table('purchase_draft_items')->where('purchase_draft_id', $id)->delete();
            DB::table('purchase_drafts')->where('id', $id)->update($data + ['version' => $old->version + 1]);
        } else {
            $id = DB::table('purchase_drafts')->insertGetId($data + ['created_by' => $actor->id, 'created_at' => now()]);
        }
        foreach ($items as $index => $item) {
            $carpet = InventoryService::carpets($actor)->where('s.id', $item['carpet_id'])->first();
            abort_unless($carpet, 404);
            if (! in_array($carpet->stock_status, ['registered', 'returned'], true) || (int) $carpet->custody_branch_id !== (int) $data['branch_id']) {
                InventoryService::fail('items', 'Choose unpurchased carpets registered to the purchase branch.');
            }
            if ($currency && isset($item['unit_price'])) {
                InventoryService::precision($item['unit_price'], $currency, "items.{$index}.unit_price");
            }
            DB::table('purchase_draft_items')->insert(['purchase_draft_id' => $id, 'carpet_id' => $item['carpet_id'], 'unit_price' => $item['unit_price'] ?? null]);
        }
        $saved = (array) DB::table('purchase_drafts')->find($id);
        AuditLogger::log($old ? 'update' : 'create', 'purchase_drafts', $id, $old ? (array) $old : null, $saved + ['items' => $items]);

        return $saved;
    }

    private function draftVersion(object $row, int $version): void
    {
        abort_unless($row->state === 'draft' && (int) $row->version === $version, 409, 'This draft changed or was finalized. Reload it.');
    }

    public function finalizeDraft(User $actor, int $id, array $data, object $settings, bool $post): array
    {
        $draft = InventoryService::branchScope(DB::table('purchase_drafts'), $actor)->where('id', $id)->first();
        abort_unless($draft, 404);
        $this->draftVersion($draft, (int) $data['version']);
        $update = ['state' => $post ? 'posted' : 'cancelled', 'version' => $draft->version + 1, 'updated_at' => now(), 'updated_by' => $actor->id];
        if ($post) {
            $payload = array_intersect_key((array) $draft, InventoryRules::for('purchase'));
            $payload['items'] = DB::table('purchase_draft_items')->where('purchase_draft_id', $id)->get(['carpet_id', 'unit_price'])->map(fn ($row) => (array) $row)->all();
            $payload = validator($payload, InventoryRules::for('purchase'))->validate();
            $purchase = app(InventoryService::class)->purchase($actor, $payload, $settings, $id);
            if (! empty($data['location_id'])) {
                FoundationAccess::permit($actor, 'stock.receive');
                BusinessSupport::location($actor, $data['location_id'], (int) $draft->branch_id);
                $carpets = InventoryService::carpets($actor)->whereIn('s.id', array_column($payload['items'], 'carpet_id'))->get();
                app(InventoryService::class)->move($actor, ['receipt_no' => DocumentNumbers::next('REC'), 'location_id' => $data['location_id'],
                    'received_on' => $draft->purchase_on, 'items' => $carpets->map(fn ($c) => ['carpet_id' => $c->id, 'expected_sequence' => $c->sequence_no])->all()], false);
            }
            $update['posted_purchase_id'] = $purchase['id'];
        } else {
            $update['cancel_reason'] = $data['reason'];
        }
        DB::table('purchase_drafts')->where('id', $id)->update($update);
        $saved = (array) DB::table('purchase_drafts')->find($id);
        AuditLogger::log('update', 'purchase_drafts', $id, (array) $draft, $saved);

        return $saved;
    }

    public function void(User $actor, int $id, array $data): array
    {
        $purchase = self::purchase($actor, $id);
        abort_if(DB::table('purchase_voids')->where('weaver_purchase_id', $id)->exists(), 409, 'This purchase is already void.');
        $lines = DB::table('weaver_purchase_items')->where('weaver_purchase_id', $id)->get()->keyBy('carpet_id');
        $requested = collect($data['items'])->keyBy('carpet_id');
        if ($requested->keys()->sort()->values()->all() !== $lines->keys()->sort()->values()->all()) {
            InventoryService::fail('items', 'Cancellation must include every purchase item.');
        }
        $voidId = DB::table('purchase_voids')->insertGetId(['weaver_purchase_id' => $id, 'voided_on' => $data['voided_on'], 'reason' => $data['reason'], 'created_by' => $actor->id, 'created_at' => now()]);
        foreach ($data['items'] as $item) {
            $carpet = $this->current($actor, $item, $data['voided_on']);
            if ($carpet->stock_status !== 'purchased' || (int) $carpet->acquisition_item_id !== (int) $lines[$carpet->id]->id) {
                InventoryService::fail('items', 'Only an unreceived purchase can be voided. Use a return for received carpets.');
            }
            $this->event($actor, $carpet, 'purchase_void', 'registered', null, $data['voided_on'], ['purchase_void_id' => $voidId]);
        }
        $saved = (array) DB::table('purchase_voids')->find($voidId);
        AuditLogger::log('create', 'purchase_voids', $voidId, null, $saved);

        return $saved;
    }

    public function adjust(User $actor, array $data): array
    {
        $data['adjustment_no'] = $data['adjustment_no'] ?? DocumentNumbers::next('ADJ');
        app(InventoryService::class)->branch($actor, $data['branch_id']);
        $this->unique('stock_adjustments', 'adjustment_no', $data['adjustment_no']);
        $target = $data['adjustment_type'] !== 'missing' ? $this->location($actor, $data['location_id']) : null;
        if ($target && (int) $target->branch_id !== (int) $data['branch_id']) {
            InventoryService::fail('location_id', 'Use a stock transfer to change branches.');
        }
        $items = $data['items'];
        unset($data['items']);
        $id = DB::table('stock_adjustments')->insertGetId($data + ['created_by' => $actor->id, 'created_at' => now()]);
        foreach ($items as $item) {
            $carpet = $this->current($actor, $item, $data['adjusted_on']);
            $requiredState = $data['adjustment_type'] === 'found' ? 'missing' : 'in_stock';
            if ($carpet->stock_status !== $requiredState || (int) $carpet->custody_branch_id !== (int) $data['branch_id']) {
                InventoryService::fail('items', 'The carpet is not eligible for this stock correction.');
            }
            if ($data['adjustment_type'] === 'relocate' && (int) $carpet->location_id === (int) $target->id) {
                InventoryService::fail('location_id', 'Choose a different destination.');
            }
            $this->event($actor, $carpet, 'adjustment', $target ? 'in_stock' : 'missing', $target?->id ?? $carpet->location_id, $data['adjusted_on'], ['stock_adjustment_id' => $id]);
        }
        $saved = (array) DB::table('stock_adjustments')->find($id);
        AuditLogger::log('create', 'stock_adjustments', $id, null, $saved + ['items' => $items]);

        return $saved;
    }

    public function return(User $actor, array $data): array
    {
        $purchase = self::purchase($actor, $data['weaver_purchase_id']);
        app(InventoryService::class)->branch($actor, $purchase->branch_id);
        $from = isset($data['from_location_id']) ? $this->location($actor, $data['from_location_id']) : null;
        abort_if(DB::table('purchase_voids')->where('weaver_purchase_id', $purchase->id)->exists(), 409, 'This purchase is already void.');
        $this->unique('weaver_returns', 'return_no', $data['return_no']);
        $items = $data['items'];
        unset($data['items']);
        $id = DB::table('weaver_returns')->insertGetId($data + ['created_by' => $actor->id, 'created_at' => now()]);
        foreach ($items as $item) {
            $carpet = $this->current($actor, $item, $data['returned_on']);
            $line = DB::table('weaver_purchase_items')->where('id', $carpet->acquisition_item_id)->where('weaver_purchase_id', $purchase->id)->first();
            $eligible = $from ? ($carpet->stock_status === 'in_stock' && (int) $carpet->location_id === (int) $from->id)
                : ($carpet->stock_status === 'purchased' && (int) $carpet->custody_branch_id === (int) $purchase->branch_id);
            if (! $line || ! $eligible) {
                InventoryService::fail('items', 'Return only available carpets from this purchase and source location.');
            }
            abort_if(DB::table('weaver_return_items')->where('weaver_purchase_item_id', $line->id)->exists(), 409, 'This carpet was already returned.');
            $returnLine = DB::table('weaver_return_items')->insertGetId(['weaver_return_id' => $id, 'weaver_purchase_item_id' => $line->id]);
            $this->event($actor, $carpet, 'weaver_return', 'returned', null, $data['returned_on'], ['weaver_return_item_id' => $returnLine]);
        }
        $saved = (array) DB::table('weaver_returns')->find($id);
        AuditLogger::log('create', 'weaver_returns', $id, null, $saved + ['items' => $items]);

        return $saved;
    }

    public function refund(User $actor, array $data, string $key): array
    {
        $purchase = self::purchase($actor, $data['weaver_purchase_id']);
        $this->unique('weaver_refunds', 'refund_no', $data['refund_no']);
        $account = FoundationAccess::accounts(DB::table('accounts'), $actor)->where('id', $data['account_id'])->first();
        abort_unless($account, 404);
        $currency = DB::table('currencies')->find($purchase->currency_id);
        InventoryService::precision($data['amount'], DB::table('currencies')->find($account->currency_id), 'amount');
        if ((int) $account->currency_id === (int) $currency->id && bccomp($data['purchase_units_per_refund_unit'], '1', 8) !== 0) {
            InventoryService::fail('purchase_units_per_refund_unit', 'Same-currency allocations must use a rate of 1.');
        }
        $amount = InventoryService::converted($data['amount'], $data['purchase_units_per_refund_unit'], $currency->decimal_places);
        $balance = (string) DB::table('v_weaver_purchase_balances')->where('purchase_id', $purchase->id)->value('remaining_amount');
        if (bccomp($balance, '0', 2) >= 0 || bccomp($amount, '0', 2) <= 0 || bccomp($amount, bcsub('0', $balance, 2), 2) > 0 || bccomp($amount, '9999999999999999.99', 2) > 0) {
            InventoryService::fail('amount', 'Refund exceeds the credit owed by the weaver.');
        }
        $creditDate = max($purchase->purchase_on, DB::table('purchase_voids')->where('weaver_purchase_id', $purchase->id)->value('voided_on') ?? '', DB::table('weaver_returns')->where('weaver_purchase_id', $purchase->id)->max('returned_on') ?? '');
        if ($data['refunded_on'] < $creditDate) {
            InventoryService::fail('refunded_on', 'Refund date cannot precede the cancellation or return.');
        }
        $entry = app(LedgerService::class)->post($actor, ['account_id' => $account->id, 'entry_type' => 'income', 'amount' => $data['amount'], 'rate_to_base' => $data['rate_to_base'], 'transaction_date' => $data['refunded_on'], 'description' => $data['reason']], 'weaver-refund:'.hash('sha256', $key))['entry'];
        $id = DB::table('weaver_refunds')->insertGetId(['weaver_purchase_id' => $purchase->id, 'refund_no' => $data['refund_no'],
            'account_transaction_id' => $entry->id, 'amount_in_purchase_currency' => $amount, 'purchase_units_per_refund_unit' => $data['purchase_units_per_refund_unit'], 'reason' => $data['reason'], 'created_by' => $actor->id, 'created_at' => now()]);
        $saved = (array) DB::table('weaver_refunds')->find($id);
        AuditLogger::log('create', 'weaver_refunds', $id, null, $saved);

        return $saved;
    }
}
