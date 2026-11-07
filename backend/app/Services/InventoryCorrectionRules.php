<?php

namespace App\Services;

class InventoryCorrectionRules
{
    public static function rules(string $operation, bool $editing = false): array
    {
        $code = ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'];
        $reason = ['required', 'string', 'min:3', 'max:500'];
        $items = ['items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:carpet_id,expected_sequence'],
            'items.*.carpet_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.expected_sequence' => ['required', 'integer', 'min:1']];
        if ($operation === 'drafts') {
            $rules = InventoryRules::for('purchase');
            foreach (['weaver_id', 'currency_id', 'purchase_on', 'rate_to_base'] as $field) {
                $rules[$field][0] = 'nullable';
            }
            $rules['items'] = ['present', 'array', 'max:50'];
            $rules['items.*.unit_price'][0] = 'nullable';
            $rules['version'] = [$editing ? 'required' : 'prohibited', 'integer', 'min:1'];

            return $rules;
        }

        return match ($operation) {
            'void' => ['reason' => $reason, 'voided_on' => InventoryRules::date()] + $items,
            'adjustments' => ['adjustment_no' => ['nullable', ...array_slice($code, 1)], 'branch_id' => ['required', 'integer'],
                'adjustment_type' => ['required', 'in:relocate,missing,found'],
                'location_id' => ['nullable', 'integer', 'required_unless:adjustment_type,missing', 'prohibited_if:adjustment_type,missing'],
                'adjusted_on' => InventoryRules::date(), 'reason' => $reason] + $items,
            'returns' => ['return_no' => $code, 'weaver_purchase_id' => ['required', 'integer'],
                'from_location_id' => ['present', 'nullable', 'integer'], 'returned_on' => InventoryRules::date(), 'reason' => $reason] + $items,
            'refunds' => ['refund_no' => $code, 'weaver_purchase_id' => ['required', 'integer'],
                'account_id' => ['required', 'integer'], 'amount' => InventoryRules::money(),
                'rate_to_base' => InventoryRules::rate(), 'purchase_units_per_refund_unit' => InventoryRules::rate(),
                'refunded_on' => InventoryRules::date(), 'reason' => ['required', 'string', 'min:3', 'max:255']],
        };
    }
}
