<?php

namespace App\Services;

class ManagerWorkflowRules
{
    public static function for(string $action): array
    {
        $id = ['required', 'integer', 'min:1'];
        $optionalId = ['nullable', 'integer', 'min:1'];
        $quantity = ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,3})?$/', 'numeric', 'gt:0'];
        $zeroQuantity = ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,3})?$/', 'numeric', 'gte:0'];
        $payment = ['nullable', 'array:account_id,amount,rate_to_base,units_per_payment_unit,payment_on,reason'];
        return match ($action) {
            'setup' => ['branch_id' => $id, 'materials_location_id' => $id, 'finished_location_id' => $id,
                'facility_id' => $id, 'facility_location_id' => $id, 'payment_trigger' => ['required', 'in:completion,stock_acceptance']],
            'issue' => ['moved_on' => InventoryRules::date(), 'location_id' => $optionalId, 'movement_type' => ['required', 'in:issue,return'],
                'materials' => ['present', 'array', 'max:50'], 'materials.*' => ['array:material_id,quantity'],
                'materials.*.material_id' => [...$id, 'distinct'], 'materials.*.quantity' => $quantity,
                'tools' => ['present', 'array', 'max:50'], 'tools.*' => ['array:tool_id,quantity'],
                'tools.*.tool_id' => [...$id, 'distinct'], 'tools.*.quantity' => ['required', 'integer', 'between:1,1000000000'],
                'advance' => $payment, 'reason' => ['required', 'string', 'min:3', 'max:500']],
            'receive' => ['delivered_on' => InventoryRules::date(), 'facility_id' => $optionalId, 'facility_location_id' => $optionalId,
                ...ManagerProductionRules::items(), 'usages' => ['present', 'array', 'max:50'],
                'usages.*' => ['array:material_id,used_quantity,waste_quantity'], 'usages.*.material_id' => [...$id, 'distinct'],
                'usages.*.used_quantity' => $zeroQuantity, 'usages.*.waste_quantity' => $zeroQuantity,
                'no_materials_used' => ['sometimes', 'boolean']],
            'accept' => ['returned_on' => InventoryRules::date(), 'location_id' => $optionalId, 'confirmed_received' => ['required', 'accepted'],
                'items' => ['required', 'array', 'min:1', 'max:50'], 'items.*' => ['array:washing_order_item_id,expected_sequence'],
                'items.*.washing_order_item_id' => [...$id, 'distinct'], 'items.*.expected_sequence' => $id,
                'washing_amount' => ['nullable', ...array_slice(InventoryRules::money(), 1)],
                'invoice_no' => ['nullable', 'string', 'max:80'], 'record_cost_later' => ['required', 'boolean'], 'payment' => $payment],
            'settle', 'supply-pay' => BusinessRules::payment(),
            'supply' => ['branch_id' => $id, 'location_id' => $optionalId, 'supplier_id' => $id,
                'currency_id' => $id, 'rate_to_base' => InventoryRules::rate(), 'purchased_on' => InventoryRules::date(),
                'items' => ['required', 'array', 'min:1', 'max:50'], 'items.*' => ['array:kind,item_id,quantity,unit_cost'],
                'items.*.kind' => ['required', 'in:material,tool'], 'items.*.item_id' => $id,
                'items.*.quantity' => $quantity, 'items.*.unit_cost' => InventoryRules::money(), 'payment' => $payment],
            'assistance' => ManagerResourcesRules::for('assistance') + ['payment' => $payment,
                'delivery' => ['nullable', 'array:amount,delivered_on,acknowledgement']],
            default => throw new \InvalidArgumentException('Unknown workflow action.'),
        };
    }
}
