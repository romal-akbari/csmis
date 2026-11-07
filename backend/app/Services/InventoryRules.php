<?php

namespace App\Services;

class InventoryRules
{
    public static function money(bool $zero = false): array
    {
        return ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,15})(?:\.\d{1,2})?$/', 'numeric', $zero ? 'gte:0' : 'gt:0'];
    }

    public static function rate(): array
    {
        return ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/', 'numeric', 'gt:0'];
    }

    public static function date(): array
    {
        return ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:'.FoundationCatalog::today()];
    }

    public static function for(string $operation): array
    {
        $code = ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'];
        $items = ['required', 'array', 'min:1', 'max:50'];
        $itemIds = ['required', 'integer', 'distinct', 'min:1'];

        return match ($operation) {
            'carpet', 'edit-carpet' => [
                'carpet_code' => $code, 'name' => ['required', 'string', 'min:2', 'max:150'],
                'registration_branch_id' => ['required', 'integer'], 'category_id' => ['required', 'integer'],
                'size_label' => ['nullable', 'string', 'max:60'],
                'length_cm' => ['nullable', 'required_with:width_cm', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/', 'numeric', 'gt:0'],
                'width_cm' => ['nullable', 'required_with:length_cm', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/', 'numeric', 'gt:0'],
                'color' => ['nullable', 'string', 'max:60'], 'material' => ['nullable', 'string', 'max:100'],
                'notes' => ['nullable', 'string', 'max:1000'],
                'version' => [$operation === 'edit-carpet' ? 'required' : 'prohibited', 'integer', 'min:1'],
            ],
            'purchase' => [
                'purchase_no' => ['nullable', ...array_slice($code, 1)], 'weaver_id' => ['required', 'integer'], 'branch_id' => ['required', 'integer'],
                'purchase_on' => self::date(), 'currency_id' => ['required', 'integer'], 'rate_to_base' => self::rate(),
                'notes' => ['nullable', 'string', 'max:1000'], 'items' => $items,
                'items.*' => ['required', 'array:carpet_id,unit_price'],
                'items.*.carpet_id' => $itemIds, 'items.*.unit_price' => self::money(true),
            ],
            'receipt', 'transfer' => [
                ($operation === 'receipt' ? 'receipt_no' : 'transfer_no') => ['nullable', ...array_slice($code, 1)],
                ($operation === 'receipt' ? 'received_on' : 'transferred_on') => self::date(),
                ...($operation === 'receipt' ? ['location_id' => ['required', 'integer']] : [
                    'from_location_id' => ['required', 'integer'], 'to_location_id' => ['required', 'integer', 'different:from_location_id'],
                ]),
                'notes' => ['nullable', 'string', 'max:500'], 'items' => $items,
                'items.*' => ['required', 'array:carpet_id,expected_sequence'],
                'items.*.carpet_id' => $itemIds, 'items.*.expected_sequence' => ['required', 'integer', 'min:1'],
            ],
            'payment' => [
                'payment_no' => $code, 'weaver_id' => ['required', 'integer'], 'branch_id' => ['required', 'integer'],
                'account_id' => ['required', 'integer'], 'payment_type' => ['required', 'in:purchase,additional'],
                'amount' => self::money(), 'rate_to_base' => self::rate(), 'payment_on' => self::date(),
                'reason' => ['required', 'string', 'min:3', 'max:255'],
                'expense_category_id' => ['nullable', 'required_if:payment_type,additional', 'prohibited_if:payment_type,purchase', 'integer'],
                'allocations' => ['present', 'array', 'max:50', 'required_if:payment_type,purchase', 'prohibited_if:payment_type,additional'],
                'allocations.*' => ['required', 'array:weaver_purchase_id,amount_in_payment_currency,purchase_units_per_payment_unit'],
                'allocations.*.weaver_purchase_id' => $itemIds,
                'allocations.*.amount_in_payment_currency' => self::money(),
                'allocations.*.purchase_units_per_payment_unit' => self::rate(),
            ],
        };
    }
}
