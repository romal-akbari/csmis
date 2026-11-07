<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupplyPurchaseService
{
    public function create(User $actor, array $data, object $settings, string $key): array
    {
        if (! DB::table('suppliers')->where('id', $data['supplier_id'] ?? null)->where('status', 'active')->whereNull('deleted_at')->exists())
            InventoryService::fail('supplier_id', 'Choose an active supplier.');
        $defaults = app(ManagerWorkflowService::class)->defaults($actor, $data['branch_id']);
        $location = $data['location_id'] ?? $defaults?->materials_location_id;
        if (! $location) InventoryService::fail('location_id', 'Choose the receiving store.');
        BusinessSupport::location($actor, $location, $data['branch_id']);
        BusinessSupport::currency($data, $settings);
        $items = $data['items'];
        $payment = $data['payment'] ?? null;
        unset($data['items'], $data['payment']);
        $data['location_id'] = $location;
        $data['purchase_no'] = DocumentNumbers::next('SUP');
        $id = BusinessSupport::insert($actor, 'supply_purchases', $data);
        $total = '0';
        foreach ($items as $item) {
            if ($item['kind'] === 'tool' && (! preg_match('/^[1-9]\d{0,9}$/', $item['quantity']) || bccomp($item['quantity'], '1000000000', 0) > 0)) InventoryService::fail('items', 'Tools must be received in whole quantities.');
            $fields = ['branch_id' => $data['branch_id'], 'location_id' => $location, 'supplier_id' => $data['supplier_id'] ?? null,
                'currency_id' => $data['currency_id'], 'rate_to_base' => $data['rate_to_base'], 'received_on' => $data['purchased_on'],
                'quantity' => $item['quantity'], 'unit_cost' => $item['unit_cost']];
            $tool = $item['kind'] === 'tool';
            $fields[$tool ? 'tool_id' : 'material_id'] = $item['item_id'];
            $lot = $tool ? app(ManagerToolsService::class)->receive($actor, $fields, $settings, $key)
                : app(ProductionService::class)->lot($actor, $fields, $settings, $key);
            $table = $tool ? 'production_tool_lots' : 'material_lots';
            $document = DB::table($table)->find($lot['id']);
            $total = bcadd($total, $document->total_cost, 2);
            ManagerProductionPricing::bounded($total, 'items');
            BusinessSupport::insert($actor, 'supply_purchase_lines', ['purchase_id' => $id, $tool ? 'tool_lot_id' : 'material_lot_id' => $lot['id']]);
        }
        if ($payment) $this->pay($actor, $id, $payment, $key);
        return ['id' => $id, 'purchase_no' => $data['purchase_no'], 'total_amount' => $total];
    }

    public function pay(User $actor, int $id, array $payment, string $key): array
    {
        FoundationAccess::permit($actor, 'materials.pay');
        $purchase = BusinessSupport::document($actor, 'supply_purchases', $id);
        $purchase = $this->summary($purchase);
        $payment = validator($payment, BusinessRules::payment())->validate();
        $account = FoundationAccess::accounts(DB::table('accounts'), $actor)->where('id', $payment['account_id'])->first();
        abort_unless($account, 404);
        $currency = DB::table('currencies')->find($purchase->currency_id);
        $paid = InventoryService::converted($payment['amount'], $payment['units_per_payment_unit'], $currency->decimal_places);
        if (bccomp($purchase->remaining_amount, '0', 2) <= 0 || bccomp($paid, $purchase->remaining_amount, 2) > 0) InventoryService::fail('amount', 'Payment cannot exceed the purchase balance.');
        $lots = $purchase->items->filter(fn ($lot) => bccomp($lot->total_cost, $lot->paid_amount, 2) > 0)->values();
        $precision = DB::table('currencies')->where('id', $account->currency_id)->value('decimal_places');
        $cumulative = $previous = '0';
        $allocations = [];
        foreach ($lots as $index => $lot) {
            $cumulative = bcadd($cumulative, bcsub($lot->total_cost, $lot->paid_amount, 2), 2);
            $target = $index === $lots->count() - 1 ? $paid : $this->share($cumulative, $purchase->remaining_amount, $paid, $currency->decimal_places);
            $amount = bcsub($target, $previous, 2);
            $previous = $target;
            if (bccomp($amount, '0', 2) <= 0) continue;
            $allocations[] = ['lot' => $lot, 'document_amount' => $amount];
        }
        // A payment has one cash conversion. Its lot allocations share that entry and its rounded document total.
        $purchase->purchase_on = $purchase->purchased_on;
        $entry = BusinessSupport::money($actor, $purchase, $payment, hash('sha256', $key.':supply'), 'out', $purchase->remaining_amount);
        $cumulative = $previous = '0';
        foreach ($allocations as $index => $allocation) {
            $cumulative = bcadd($cumulative, $allocation['document_amount'], 2);
            $target = $index === count($allocations) - 1 ? $payment['amount'] : $this->share($cumulative, $paid, $payment['amount'], $precision);
            $cashAmount = bcsub($target, $previous, 2);
            $previous = $target;
            $lot = $allocation['lot'];
            BusinessSupport::insert($actor, $lot->kind === 'tool' ? 'production_tool_payments' : 'material_lot_payments', [
                'lot_id' => $lot->id, 'account_transaction_id' => $entry['account_transaction_id'],
                'document_amount' => $allocation['document_amount'], 'units_per_payment_unit' => $payment['units_per_payment_unit'],
                'amount_in_payment_currency' => $cashAmount]);
        }
        return ['id' => $id, 'account_transaction_id' => $entry['account_transaction_id']];
    }

    private function share(string $portion, string $total, string $amount, int $precision): string
    {
        // Multiply the exact monetary values before division; a truncated ratio loses cents on large payments.
        $value = bcdiv(bcmul($portion, $amount, 4), $total, $precision + 1);
        return InventoryService::converted($value, '1', $precision);
    }

    public function summary(object $purchase): object
    {
        $purchase->total_amount = $purchase->paid_amount = '0';
        $purchase->items = DB::table('supply_purchase_lines')->where('purchase_id', $purchase->id)->get()->map(function ($line) use ($purchase) {
            $tool = $line->tool_lot_id !== null;
            $lot = DB::table($tool ? 'production_tool_lots' : 'material_lots')->find($tool ? $line->tool_lot_id : $line->material_lot_id);
            $lot->kind = $tool ? 'tool' : 'material';
            $lot->name = DB::table($tool ? 'production_tools' : 'materials')->where('id', $tool ? $lot->tool_id : $lot->material_id)->value('name');
            $lot->paid_amount = BusinessSupport::paid($tool ? 'production_tool_payments' : 'material_lot_payments', 'lot_id', $lot->id);
            $purchase->total_amount = bcadd($purchase->total_amount, $lot->total_cost, 2);
            $purchase->paid_amount = bcadd($purchase->paid_amount, $lot->paid_amount, 2);
            return $lot;
        });
        $purchase->remaining_amount = bcsub($purchase->total_amount, $purchase->paid_amount, 2);
        return $purchase;
    }
}
