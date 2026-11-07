<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AccountOpeningRate
{
    public static function resolve(int $currency, string $date, ?int $base): string
    {
        if (! $base) InventoryService::fail('currency_id', 'Set the base currency in Company settings before entering an opening balance.');
        if ($currency === $base) return '1';

        $rate = DB::table('exchange_rates')->where('effective_date', '<=', $date)
            ->where(fn ($q) => $q->where(fn ($pair) => $pair->where('base_currency_id', $currency)->where('quote_currency_id', $base))
                ->orWhere(fn ($pair) => $pair->where('base_currency_id', $base)->where('quote_currency_id', $currency)))
            ->orderByDesc('effective_date')->orderByDesc('id')->first();
        if (! $rate) InventoryService::fail('currency_id', 'Add an exchange rate for this currency on or before the opening date, then save the account.');

        $value = (int) $rate->base_currency_id === $currency ? bcadd($rate->rate, '0', 8) : bcdiv('1', $rate->rate, 8);
        if (bccomp($value, '0', 8) <= 0) InventoryService::fail('currency_id', 'The saved exchange rate cannot represent this opening balance.');

        return $value;
    }
}
