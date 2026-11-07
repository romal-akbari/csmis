<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManagerMaterialsService
{
    public static function held(int $manager, int $lot): string
    {
        return (string) (DB::table('manager_material_movements')->where('manager_id', $manager)->where('lot_id', $lot)
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type='issue' THEN quantity ELSE -quantity END),0) qty")->value('qty') ?? '0');
    }

    public static function manager(User $actor, int $id, bool $active = true): object
    {
        $manager = BusinessSupport::document($actor, 'production_managers', $id);
        if ($active) {
            app(InventoryService::class)->branch($actor, $manager->branch_id);
        }
        if ($active && $manager->status !== 'active') {
            InventoryService::fail('manager_id', 'Choose an active production manager.');
        }

        return $manager;
    }

    public function move(User $actor, int $managerId, array $data): array
    {
        $manager = self::manager($actor, $managerId, $data['movement_type'] === 'issue');
        app(InventoryService::class)->branch($actor, $manager->branch_id);
        $lot = BusinessSupport::document($actor, 'material_lots', $data['lot_id']);
        if ((int) $manager->branch_id !== (int) $lot->branch_id) {
            InventoryService::fail('lot_id', 'Choose a material lot in the manager branch.');
        }
        $earliest = $lot->received_on;
        if (! empty($data['production_order_id'])) {
            $order = BusinessSupport::document($actor, 'manager_production_orders', $data['production_order_id']);
            if ((int) $order->manager_id !== $managerId || (int) $order->branch_id !== (int) $manager->branch_id) {
                InventoryService::fail('production_order_id', 'Choose a production order belonging to this manager and branch.');
            }
            if (DB::table('manager_production_closures')->where('production_order_id', $order->id)->exists()) {
                InventoryService::fail('production_order_id', 'This production order is closed. Its material costs are fixed.');
            }
            if (in_array($data['movement_type'], ['consume', 'waste'], true)) {
                $target = (int) DB::table('manager_production_lines')->where('production_order_id', $order->id)->sum('target_quantity');
                $delivered = DB::table('manager_delivery_items')->where('production_order_id', $order->id)->count();
                if ($delivered >= $target) {
                    InventoryService::fail('production_order_id', 'All production targets have been delivered. Material costs must be recorded before final delivery.');
                }
            }
            $earliest = max($earliest, $order->ordered_on);
        }
        $latest = max(DB::table('material_movements')->where('lot_id', $lot->id)->max('moved_on') ?? '',
            DB::table('manager_material_movements')->where('lot_id', $lot->id)->max('moved_on') ?? '');
        if ($data['moved_on'] < max($earliest, $latest)) {
            InventoryService::fail('moved_on', 'Movement date cannot precede the receipt, order or latest movement for this lot.');
        }
        $available = $data['movement_type'] === 'issue' ? ProductionService::warehouse($lot->id) : self::held($managerId, $lot->id);
        if (bccomp($data['quantity'], $available, 3) > 0) {
            InventoryService::fail('quantity', 'Quantity exceeds the material available at the source.');
        }
        $data['cost_base'] = '0';
        if (in_array($data['movement_type'], ['consume', 'waste'], true)) {
            $previous = '0';
            $previousCost = '0';
            foreach (['material_movements', 'manager_material_movements'] as $table) {
                $previous = bcadd($previous, (string) DB::table($table)->where('lot_id', $lot->id)->whereIn('movement_type', ['consume', 'waste'])->sum('quantity'), 3);
                $previousCost = bcadd($previousCost, (string) DB::table($table)->where('lot_id', $lot->id)->sum('cost_base'), 2);
            }
            $newTotal = bcadd($previous, $data['quantity'], 3);
            if (bccomp($newTotal, $lot->quantity, 3) > 0) {
                InventoryService::fail('quantity', 'Consumption exceeds the quantity received for this lot.');
            }
            $target = bccomp($newTotal, $lot->quantity, 3) === 0 ? $lot->total_cost_base
                : InventoryService::converted(bcdiv($newTotal, $lot->quantity, 12), $lot->total_cost_base, BusinessSupport::basePrecision());
            $data['cost_base'] = bcsub($target, $previousCost, 2);
        }

        return ['id' => BusinessSupport::insert($actor, 'manager_material_movements', $data + ['manager_id' => $managerId])];
    }
}
