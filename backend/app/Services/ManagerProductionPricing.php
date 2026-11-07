<?php

namespace App\Services;

/** A small, ordered decimal calculator. Saved terms are data, never executable expressions. */
class ManagerProductionPricing
{
    public static function area(string $length, string $width): string
    {
        $area = bcdiv(bcmul($length, $width, 4), '10000', 6);
        if (bccomp($area, '0', 6) <= 0) {
            InventoryService::fail('items', 'Carpet dimensions must produce a positive measurable area.');
        }

        return $area;
    }

    public static function bounded(string $value, string $field = 'pricing'): string
    {
        if (bccomp($value, '0', 8) < 0 || bccomp($value, '9999999999999999.99', 8) > 0) {
            InventoryService::fail($field, 'The calculated amount must be nonnegative and within the supported range.');
        }

        return $value;
    }

    private static function factors(string $subtotal, array $factors, string $count, string $area, string $target): string
    {
        foreach ($factors as $factor) {
            $factor = (object) $factor;
            $fraction = bcdiv($count, $target, 12);
            if ($factor->kind === 'multiplier') {
                $subtotal = bcmul($subtotal, $factor->value, 12);
            } else {
                $amount = match ($factor->kind) {
                    'fixed' => bcmul($factor->value, $fraction, 12),
                    'per_piece' => bcmul($factor->value, $count, 12),
                    'per_area' => bcmul($factor->value, $area, 12),
                    'rate_quantity' => bcmul(bcmul($factor->value, $factor->quantity, 12), $fraction, 12),
                    'percentage' => bcdiv(bcmul($subtotal, $factor->value, 12), '100', 12),
                    default => throw new \LogicException('Unknown saved pricing factor.'),
                };
                $subtotal = $factor->operation === 'deduct' ? bcsub($subtotal, $amount, 12) : bcadd($subtotal, $amount, 12);
            }
            self::bounded($subtotal);
        }

        return $subtotal;
    }

    public static function total(array $lines, array $factors, array $quantities, int $precision): string
    {
        $subtotal = $count = $area = $target = '0';
        foreach ($lines as $line) {
            $line = (object) $line;
            $qty = $quantities[$line->id] ?? ['count' => '0', 'area' => '0'];
            $amount = match ($line->pricing_method) {
                'area' => bcmul($line->rate, $qty['area'], 12),
                'piece' => bcmul($line->rate, $qty['count'], 12),
                'fixed', 'manual' => bcdiv(bcmul($line->rate, $qty['count'], 12), (string) $line->target_quantity, 12),
                default => throw new \LogicException('Unknown saved pricing method.'),
            };
            $lineFactors = array_values(array_filter($factors, fn ($f) => (int) $f->line_id === (int) $line->id));
            $subtotal = bcadd($subtotal, self::factors($amount, $lineFactors, $qty['count'], $qty['area'], (string) $line->target_quantity), 12);
            $count = bcadd($count, $qty['count'], 0);
            $area = bcadd($area, $qty['area'], 6);
            $target = bcadd($target, (string) $line->target_quantity, 0);
        }
        $orderFactors = array_values(array_filter($factors, fn ($f) => $f->line_id === null));
        $amount = self::bounded(self::factors($subtotal, $orderFactors, $count, $area, $target));

        return bcadd(InventoryService::converted($amount, '1', $precision), '0', 2);
    }

    public static function delivery(array $lines, array $factors, array $previous, array $items, int $precision): array
    {
        $quantities = $previous;
        $prior = self::total($lines, $factors, $quantities, $precision);
        $start = $prior;
        $result = [];
        foreach ($items as $item) {
            $area = self::area($item['length_cm'], $item['width_cm']);
            $old = $quantities[$item['line_id']] ?? ['count' => '0', 'area' => '0'];
            $quantities[$item['line_id']] = ['count' => bcadd($old['count'], '1', 0), 'area' => bcadd($old['area'], $area, 6)];
            $next = self::total($lines, $factors, $quantities, $precision);
            $charge = self::bounded(bcsub($next, $prior, 2), 'items');
            $result[] = $item + ['area_m2' => $area, 'charge_amount' => $charge];
            $prior = $next;
        }

        return ['items' => $result, 'charge_amount' => bcsub($prior, $start, 2), 'cumulative_amount' => $prior];
    }
}
