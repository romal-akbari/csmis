<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class SalesStockGroups
{
    /** Group saleable designs without losing the individual carpets used for custody and cost. */
    private static function available(User $actor, ?int $branch = null): Collection
    {
        $query = InventoryService::carpets($actor)
            ->join('stock_locations as l', 'l.id', '=', 's.location_id')
            ->leftJoin('manager_delivery_items as mi', 'mi.carpet_id', '=', 's.id')
            ->leftJoin('manager_production_orders as mo', 'mo.id', '=', 'mi.production_order_id')
            ->leftJoin('production_orders as po', 'po.id', '=', 's.production_order_id')
            ->where('s.stock_status', 'in_stock');
        if ($branch !== null) {
            $query->where('s.custody_branch_id', $branch);
        }
        $carpets = $query->orderBy('s.occurred_on')->orderBy('s.id')->get([
            's.id', 's.sequence_no', 's.name', 's.category_id', 's.material', 's.color', 's.length_cm', 's.width_cm',
            's.production_order_id', 's.location_id', 's.custody_branch_id', 'l.name as location_name',
            'mi.line_id', 'mo.order_no as manager_order_no', 'po.order_no as legacy_order_no',
        ]);

        return $carpets->groupBy(function ($carpet) {
            $origin = $carpet->line_id ? ['line', (int) $carpet->line_id]
                : ($carpet->production_order_id ? ['order', (int) $carpet->production_order_id] : ['unlinked']);
            $identity = [$origin, (int) $carpet->category_id, $carpet->name, $carpet->material, $carpet->color,
                $carpet->length_cm, $carpet->width_cm, (int) $carpet->location_id, (int) $carpet->custody_branch_id];

            return 'SG1-'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        });
    }

    public static function lookups(User $actor): Collection
    {
        return self::available($actor)->map(function (Collection $carpets, string $key) {
            $first = $carpets->first();

            return ['stock_key' => $key, 'name' => $first->name,
                'production_order_no' => $first->manager_order_no ?? $first->legacy_order_no,
                'length_cm' => $first->length_cm, 'width_cm' => $first->width_cm,
                'location_name' => $first->location_name, 'location_id' => (int) $first->location_id,
                'branch_id' => (int) $first->custody_branch_id, 'available_quantity' => $carpets->count()];
        })->sortBy(fn ($group) => [$group['name'], $group['location_name'], $group['stock_key']])->values();
    }

    /** Called only inside the inventory command's company settings lock. */
    public static function allocate(User $actor, int $branch, array $groups): array
    {
        FoundationAccess::permit($actor, 'stock.view');
        if (array_sum(array_column($groups, 'quantity')) > 50) {
            InventoryService::fail('stock_groups', 'An invoice can contain at most 50 carpets.');
        }
        $available = self::available($actor, $branch);
        $items = [];
        foreach ($groups as $index => $group) {
            $carpets = $available->get($group['stock_key'], collect());
            if ($carpets->count() < $group['quantity']) {
                InventoryService::fail("stock_groups.{$index}.quantity", 'The requested quantity is no longer available. Reload the available stock.');
            }
            foreach ($carpets->take($group['quantity']) as $carpet) {
                $items[] = ['carpet_id' => $carpet->id, 'expected_sequence' => $carpet->sequence_no,
                    'unit_price' => $group['unit_price'], 'discount' => $group['discount'], '_stock_group' => $index];
            }
        }

        return $items;
    }
}
