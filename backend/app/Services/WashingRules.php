<?php

namespace App\Services;

class WashingRules
{
    public static function for(string $operation): array
    {
        $code = ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'];
        $id = ['required', 'integer', 'min:1'];
        $items = ['required', 'array', 'min:1', 'max:50'];
        $visitItems = ['items' => $items, 'items.*' => ['required', 'array:washing_order_item_id,expected_sequence'],
            'items.*.washing_order_item_id' => [...$id, 'distinct'], 'items.*.expected_sequence' => $id];

        return match ($operation) {
            'dispatch' => ['order_no' => $code, 'branch_id' => $id, 'facility_id' => $id, 'facility_location_id' => $id,
                'sent_on' => InventoryRules::date(), 'notes' => ['nullable', 'string', 'max:500'],
                'items' => $items, 'items.*' => ['required', 'array:carpet_id,expected_sequence'],
                'items.*.carpet_id' => [...$id, 'distinct'], 'items.*.expected_sequence' => $id],
            'complete' => ['completed_on' => InventoryRules::date(), ...$visitItems],
            'return' => ['returned_on' => InventoryRules::date(), 'location_id' => $id, ...$visitItems],
            'bill' => ['expense_no' => $code, 'invoice_no' => ['required', 'string', 'max:80', 'regex:/\S/'],
                'branch_id' => $id, 'facility_id' => $id, 'currency_id' => $id, 'rate_to_base' => InventoryRules::rate(),
                'billed_on' => InventoryRules::date(), 'amount' => InventoryRules::money(),
                'notes' => ['nullable', 'string', 'max:500'], 'allocations' => $items,
                'allocations.*' => ['required', 'array:washing_order_item_id,amount'],
                'allocations.*.washing_order_item_id' => [...$id, 'distinct'], 'allocations.*.amount' => InventoryRules::money()],
            'payment' => ['payment_no' => $code, 'account_id' => $id, 'amount' => InventoryRules::money(),
                'rate_to_base' => InventoryRules::rate(), 'bill_units_per_payment_unit' => InventoryRules::rate(),
                'payment_on' => InventoryRules::date(), 'reason' => ['required', 'string', 'min:3', 'max:255']],
            'void' => ['voided_on' => InventoryRules::date(), 'reason' => ['required', 'string', 'min:3', 'max:500']],
            'reverse' => ['reason' => ['required', 'string', 'min:3', 'max:255']],
        };
    }
}
