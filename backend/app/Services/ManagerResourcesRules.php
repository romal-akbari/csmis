<?php

namespace App\Services;

class ManagerResourcesRules
{
    public static function for(string $operation): array
    {
        $id = ['required', 'integer', 'min:1'];
        $code = ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'];
        $text = ['required', 'string', 'min:3', 'max:500'];
        $date = InventoryRules::date();
        $currency = ['currency_id' => $id, 'rate_to_base' => InventoryRules::rate()];
        $quantity = ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,3})?$/', 'numeric', 'gt:0'];

        return match ($operation) {
            'managers' => ['code' => $code, 'name' => ['required', 'string', 'min:2', 'max:150'],
                'branch_id' => $id, 'status' => ['required', 'in:active,inactive'], 'phone' => ['nullable', 'string', 'max:30'],
                'address' => ['nullable', 'string', 'max:500'], 'employee_id' => ['nullable', 'integer', 'min:1']],
            'tools' => ['code' => $code, 'name' => ['required', 'string', 'min:2', 'max:150'], 'status' => ['required', 'in:active,inactive']],
            'tool-receipts' => $currency + ['tool_id' => $id, 'branch_id' => $id, 'location_id' => $id,
                'supplier_id' => ['nullable', 'integer', 'min:1'], 'quantity' => ['required', 'integer', 'min:1', 'max:1000000000'],
                'unit_cost' => InventoryRules::money(), 'received_on' => $date,
                'payment' => ['nullable', 'array:account_id,amount,rate_to_base,units_per_payment_unit,payment_on,reason']],
            'materials' => ['lot_id' => $id, 'movement_type' => ['required', 'in:issue,consume,return,waste'],
                'quantity' => $quantity, 'moved_on' => $date, 'reason' => $text,
                'production_order_id' => ['nullable', 'required_if:movement_type,consume,waste', 'integer', 'min:1']],
            'tool-move' => ['lot_id' => $id, 'movement_type' => ['required', 'in:issue,return,damage,loss'],
                'quantity' => ['required', 'integer', 'min:1', 'max:1000000000'], 'moved_on' => $date,
                'condition' => ['required', 'string', 'min:2', 'max:255'], 'reason' => $text],
            'assistance' => $currency + ['branch_id' => $id, 'manager_id' => $id,
                'beneficiary_name' => ['required', 'string', 'min:2', 'max:150'], 'beneficiary_phone' => ['nullable', 'string', 'max:30'],
                'assistance_type' => ['required', 'in:advance,bonus'], 'amount' => InventoryRules::money(), 'assistance_on' => $date, 'reason' => $text,
                'responsible_party' => ['nullable', 'required_if:assistance_type,advance', 'prohibited_if:assistance_type,bonus', 'in:worker,manager'],
                'recovery_terms' => ['nullable', 'required_if:assistance_type,advance', 'prohibited_if:assistance_type,bonus', 'string', 'min:3', 'max:1000']],
            'deliver' => ['amount' => InventoryRules::money(), 'delivered_on' => $date, 'acknowledgement' => $text],
            default => BusinessRules::payment(),
        };
    }
}
