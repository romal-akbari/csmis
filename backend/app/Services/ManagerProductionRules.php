<?php

namespace App\Services;

class ManagerProductionRules
{
    public static function factors(string $prefix): array
    {
        $decimal = ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,6})?$/', 'numeric', 'gte:0'];

        return [$prefix => ['sometimes', 'array', 'max:20'],
            $prefix.'.*' => ['array:label,kind,operation,value,quantity'],
            $prefix.'.*.label' => ['required', 'string', 'min:2', 'max:150'],
            $prefix.'.*.kind' => ['required', 'in:fixed,per_piece,per_area,rate_quantity,percentage,multiplier'],
            $prefix.'.*.operation' => ['required', 'in:add,deduct'],
            $prefix.'.*.value' => $decimal,
            $prefix.'.*.quantity' => ['nullable', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,6})?$/', 'numeric', 'gt:0']];
    }

    public static function dimensions(): array
    {
        return ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/', 'numeric', 'gt:0'];
    }

    public static function items(): array
    {
        return ['items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:line_id,length_cm,width_cm,carpet_code'], 'items.*.line_id' => ['required', 'integer', 'min:1'],
            'items.*.length_cm' => self::dimensions(), 'items.*.width_cm' => self::dimensions(),
            'items.*.carpet_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', 'distinct']];
    }

    public static function for(string $operation): array
    {
        $id = ['required', 'integer', 'min:1'];

        return match ($operation) {
            'order' => ['manager_id' => $id, 'branch_id' => $id, 'currency_id' => $id, 'rate_to_base' => InventoryRules::rate(),
                'ordered_on' => InventoryRules::date(), 'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:ordered_on'],
                'description' => ['required', 'string', 'min:3', 'max:1000'], 'payment_trigger' => ['required', 'in:completion,stock_acceptance'],
                'lines' => ['required', 'array', 'min:1', 'max:50'],
                'lines.*' => ['array:name,category_id,target_quantity,length_cm,width_cm,pricing_method,rate,manual_reason,notes,factors'],
                'lines.*.name' => ['required', 'string', 'min:2', 'max:150'], 'lines.*.category_id' => $id,
                'lines.*.target_quantity' => ['required', 'integer', 'between:1,10000'],
                'lines.*.length_cm' => ['nullable', ...array_slice(self::dimensions(), 1)],
                'lines.*.width_cm' => ['nullable', ...array_slice(self::dimensions(), 1)],
                'lines.*.pricing_method' => ['required', 'in:area,piece,fixed,manual'],
                'lines.*.rate' => InventoryRules::money(true),
                'lines.*.manual_reason' => ['nullable', 'string', 'min:3', 'max:500'],
                'lines.*.notes' => ['nullable', 'string', 'max:1000'],
                ...self::factors('factors'), ...self::factors('lines.*.factors')],
            'delivery' => ['delivered_on' => InventoryRules::date(), 'facility_id' => $id, 'facility_location_id' => $id,
                'material_movement_ids' => ['present', 'array', 'max:100'], 'material_movement_ids.*' => [...$id, 'distinct'], ...self::items()],
            'preview' => self::items(),
            'pay' => BusinessRules::payment() + ['payment_type' => ['required', 'in:production,advance']],
            'close' => ['closed_on' => InventoryRules::date(), 'reason' => ['required', 'string', 'min:3', 'max:500']],
            default => throw new \InvalidArgumentException('Unknown production command.'),
        };
    }
}
