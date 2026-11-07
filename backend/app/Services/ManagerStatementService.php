<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManagerStatementService
{
    public function read(User $actor, int $id): array
    {
        FoundationAccess::permit($actor, 'production.view');
        $manager = ManagerMaterialsService::manager($actor, $id, false);
        $data = ['manager' => $manager, 'materials' => [], 'tools' => [], 'material_movements' => [], 'tool_movements' => [], 'assistance' => [], 'production_orders' => []];
        if ($actor->hasPermission('materials.view')) {
            $data['materials'] = DB::table('manager_material_movements as x')->join('material_lots as l', 'l.id', '=', 'x.lot_id')
                ->join('materials as m', 'm.id', '=', 'l.material_id')->where('x.manager_id', $id)->where('l.branch_id', $manager->branch_id)
                ->select('l.id as lot_id', 'l.lot_no', 'm.name as material_name', 'm.unit')
                ->selectRaw("SUM(CASE WHEN x.movement_type='issue' THEN x.quantity ELSE -x.quantity END) held_quantity")
                ->groupBy('l.id', 'l.lot_no', 'm.name', 'm.unit')->orderBy('l.id')->get();
            $data['tools'] = DB::table('manager_tool_movements as x')->join('production_tool_lots as l', 'l.id', '=', 'x.lot_id')
                ->join('production_tools as t', 't.id', '=', 'l.tool_id')->where('x.manager_id', $id)->where('l.branch_id', $manager->branch_id)
                ->select('l.id as lot_id', 'l.lot_no', 't.name as tool_name')
                ->selectRaw("SUM(CASE WHEN x.movement_type='issue' THEN x.quantity ELSE -x.quantity END) held_quantity")
                ->selectRaw("SUM(CASE WHEN x.movement_type='damage' THEN x.quantity ELSE 0 END) damaged_quantity,SUM(CASE WHEN x.movement_type='loss' THEN x.quantity ELSE 0 END) lost_quantity")
                ->groupBy('l.id', 'l.lot_no', 't.name')->orderBy('l.id')->get();
            $data['material_movements'] = DB::table('manager_material_movements as m')
                ->join('material_lots as l', 'l.id', '=', 'm.lot_id')->join('materials as t', 't.id', '=', 'l.material_id')
                ->leftJoin('manager_production_orders as o', 'o.id', '=', 'm.production_order_id')
                ->where('m.manager_id', $id)->where('l.branch_id', $manager->branch_id)
                ->orderByDesc('m.moved_on')->orderByDesc('m.id')->limit(100)
                ->get(['m.id', 'm.movement_type', 'm.quantity', 'm.moved_on', 'm.reason', 'l.lot_no', 't.name as material_name', 't.unit', 'o.order_no']);
            $data['tool_movements'] = DB::table('manager_tool_movements as m')
                ->join('production_tool_lots as l', 'l.id', '=', 'm.lot_id')->join('production_tools as t', 't.id', '=', 'l.tool_id')
                ->where('m.manager_id', $id)->where('l.branch_id', $manager->branch_id)
                ->orderByDesc('m.moved_on')->orderByDesc('m.id')->limit(100)
                ->get(['m.id', 'm.movement_type', 'm.quantity', 'm.moved_on', 'm.condition', 'm.reason', 'l.lot_no', 't.name as tool_name']);
        }
        $data['assistance'] = DB::table('worker_assistance as a')->join('currencies as c', 'c.id', '=', 'a.currency_id')
            ->where('a.manager_id', $id)->where('a.branch_id', $manager->branch_id)->orderByDesc('a.id')
            ->get(['a.*', 'c.code as currency_code'])->map(fn ($row) => (object) ((array) $row + WorkerAssistanceService::balances($row->id)));
        $data['production_orders'] = DB::table('manager_production_orders as o')->join('currencies as c', 'c.id', '=', 'o.currency_id')
            ->where('o.manager_id', $id)->where('o.branch_id', $manager->branch_id)->orderByDesc('o.id')->get(['o.*', 'c.code as currency_code'])
            ->map(fn ($order) => ManagerProductionService::summary($order));

        return $data;
    }
}
