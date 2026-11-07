<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Allocate actual quantities against existing lots; every movement remains a separate audited fact. */
class ManagerWorkflowStock
{
    public function available(User $actor, object $order): array
    {
        FoundationAccess::permit($actor, 'materials.view');
        $materials = DB::table('material_lots as l')->join('materials as m', 'm.id', '=', 'l.material_id')
            ->where('l.branch_id', $order->branch_id)->orderBy('l.received_on')->orderBy('l.id')->get(['l.*', 'm.name', 'm.unit']);
        $tools = DB::table('production_tool_lots as l')->join('production_tools as t', 't.id', '=', 'l.tool_id')
            ->where('l.branch_id', $order->branch_id)->orderBy('l.received_on')->orderBy('l.id')->get(['l.*', 't.name']);
        return ['materials' => $materials->map(function ($lot) use ($order) {
            $lot->warehouse_quantity = ProductionService::warehouse($lot->id);
            $lot->held_quantity = ManagerMaterialsService::held($order->manager_id, $lot->id);
            return $lot;
        }), 'tools' => $tools->map(function ($lot) use ($order) {
            $lot->warehouse_quantity = ManagerToolsService::warehouse($lot->id);
            $lot->held_quantity = ManagerToolsService::held($order->manager_id, $lot->id);
            return $lot;
        })];
    }

    public function move(User $actor, object $order, bool $tool, int $item, string $quantity, string $type, string $date, string $reason, ?int $location = null): array
    {
        FoundationAccess::permit($actor, 'materials.move');
        $table = $tool ? 'production_tool_lots' : 'material_lots';
        $column = $tool ? 'tool_id' : 'material_id';
        $query = DB::table($table)->where('branch_id', $order->branch_id)->where($column, $item);
        if ($type === 'issue') $query->where('location_id', $location);
        $lots = $query->orderBy('received_on')->orderBy('id')->get();
        $remaining = $quantity;
        $movements = [];
        foreach ($lots as $lot) {
            $available = $tool
                ? (string) ($type === 'issue' ? ManagerToolsService::warehouse($lot->id) : ManagerToolsService::held($order->manager_id, $lot->id))
                : ($type === 'issue' ? ProductionService::warehouse($lot->id) : ManagerMaterialsService::held($order->manager_id, $lot->id));
            if (bccomp($available, '0', 3) <= 0) continue;
            $take = bccomp($remaining, $available, 3) > 0 ? $available : $remaining;
            $data = ['lot_id' => $lot->id, 'movement_type' => $type, 'quantity' => $tool ? (int) $take : $take,
                'moved_on' => $date, 'reason' => $reason, 'production_order_id' => $order->id];
            $result = $tool ? app(ManagerToolsService::class)->move($actor, $order->manager_id, $data + ['condition' => 'good'])
                : app(ManagerMaterialsService::class)->move($actor, $order->manager_id, $data);
            $movements[] = $result['id'];
            $remaining = bcsub($remaining, $take, 3);
            if (bccomp($remaining, '0', 3) === 0) break;
        }
        if (bccomp($remaining, '0', 3) > 0) InventoryService::fail($tool ? 'tools' : 'materials', 'Quantity exceeds the stock available at the selected source.');
        return $movements;
    }
}
