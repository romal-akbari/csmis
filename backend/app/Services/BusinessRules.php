<?php

namespace App\Services;

class BusinessRules
{
    public static function payment(): array
    {
        return ['account_id' => ['required', 'integer'], 'amount' => InventoryRules::money(),
            'rate_to_base' => InventoryRules::rate(), 'units_per_payment_unit' => InventoryRules::rate(),
            'payment_on' => InventoryRules::date(), 'reason' => ['required', 'string', 'min:3', 'max:255']];
    }

    public static function for(string $operation): array
    {
        $id = ['required', 'integer', 'min:1'];
        $date = InventoryRules::date();
        $money = InventoryRules::money();
        $text = ['required', 'string', 'min:3', 'max:500'];
        $currency = ['currency_id' => $id, 'rate_to_base' => InventoryRules::rate()];
        $quantity = ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,3})?$/', 'numeric', 'gt:0'];
        $carpets = ['items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.carpet_id' => [...$id, 'distinct']];

        return match ($operation) {
            'purchase' => $currency + $carpets + [
                'seller_type' => ['required', 'in:weaver,supplier'],
                'weaver_id' => ['nullable', 'required_if:seller_type,weaver', 'integer', 'prohibited_if:seller_type,supplier'],
                'supplier_id' => ['nullable', 'required_if:seller_type,supplier', 'integer', 'prohibited_if:seller_type,weaver'],
                'branch_id' => $id, 'location_id' => $id, 'purchase_on' => $date,
                'seller_invoice' => ['nullable', 'string', 'max:80'], 'notes' => ['nullable', 'string', 'max:1000'],
                'items.*' => ['required', 'array:carpet_id,unit_price'], 'items.*.unit_price' => $money,
                'payment' => ['nullable', 'array:account_id,amount,rate_to_base,units_per_payment_unit,payment_on,reason'],
            ],
            'expense' => $currency + ['branch_id' => $id, 'expense_on' => $date, 'expense_category_id' => $id,
                'payee' => ['required', 'string', 'min:2', 'max:150'], 'description' => $text, 'amount' => $money,
                'invoice_no' => ['nullable', 'string', 'max:80'], 'payment' => ['nullable', 'array:account_id,amount,rate_to_base,units_per_payment_unit,payment_on,reason']],
            'material' => ['code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', 'unique:materials,code'],
                'name' => ['required', 'string', 'min:2', 'max:150'], 'unit' => ['required', 'in:kg,g,m,unit'],
                'color' => ['nullable', 'string', 'max:60']],
            'material-receipt' => $currency + ['material_id' => $id, 'supplier_id' => ['nullable', 'integer'],
                'branch_id' => $id, 'location_id' => $id, 'received_on' => $date, 'quantity' => $quantity,
                'unit_cost' => $money, 'payment' => ['nullable', 'array:account_id,amount,rate_to_base,units_per_payment_unit,payment_on,reason']],
            'production' => $currency + $carpets + ['model' => ['required', 'in:company_funded,partnership'],
                'weaver_id' => $id, 'branch_id' => $id, 'ordered_on' => $date,
                'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:ordered_on'], 'description' => $text,
                'items.*' => ['required', 'array:carpet_id,cost_weight'], 'items.*.cost_weight' => [...$quantity, 'lt:1000000000'],
                'share_basis' => ['nullable', 'required_if:model,partnership', 'prohibited_if:model,company_funded', 'in:gross,net_cost_recovery'],
                'company_share' => ['nullable', 'required_if:model,partnership', 'prohibited_if:model,company_funded', 'numeric', 'between:0,100', 'decimal:0,2'],
                'company_loss_share' => ['nullable', 'required_if:model,partnership', 'prohibited_if:model,company_funded', 'numeric', 'between:0,100', 'decimal:0,2'],
                'agreement_terms' => ['nullable', 'required_if:model,partnership', 'prohibited_if:model,company_funded', 'string', 'min:10', 'max:2000']],
            'material-move' => ['lot_id' => $id, 'movement_type' => ['required', 'in:issue,consume,return,waste'],
                'moved_on' => $date, 'quantity' => $quantity, 'reason' => $text],
            'progress' => ['progress_on' => $date, 'percent' => ['required', 'integer', 'between:0,100'], 'notes' => $text],
            'charge' => ['earned_on' => $date, 'amount' => $money, 'description' => $text],
            'contribution' => ['contributed_on' => $date, 'amount' => $money, 'description' => $text],
            'production-receive' => ['location_id' => $id, 'received_on' => $date],
            'sale' => $currency + ['items' => ['required_without:stock_groups', 'array', 'min:1', 'max:50'],
                'items.*.carpet_id' => [...$id, 'distinct'],
                'stock_groups' => ['required_without:items', 'array', 'min:1', 'max:50'],
                'stock_groups.*' => ['required', 'array:stock_key,quantity,unit_price,discount'],
                'stock_groups.*.stock_key' => ['required', 'string', 'regex:/^SG1-[a-f0-9]{64}$/', 'distinct'],
                'stock_groups.*.quantity' => ['required', 'integer', 'between:1,50'],
                'stock_groups.*.unit_price' => $money, 'stock_groups.*.discount' => InventoryRules::money(true),
                'customer_id' => $id, 'branch_id' => $id, 'sold_on' => $date,
                'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:sold_on'], 'notes' => ['nullable', 'string', 'max:500'],
                'items.*' => ['required', 'array:carpet_id,expected_sequence,unit_price,discount'],
                'items.*.expected_sequence' => $id, 'items.*.unit_price' => $money, 'items.*.discount' => InventoryRules::money(true),
                'payment' => ['nullable', 'array:account_id,amount,rate_to_base,units_per_payment_unit,payment_on,reason']],
            'void' => ['voided_on' => $date, 'reason' => $text],
            'settle' => ['settled_on' => $date],
            'reverse' => ['reason' => $text],
            'production-pay' => self::payment() + ['payment_type' => ['required', 'in:labor,advance,support']],
            default => self::payment(),
        };
    }
}
