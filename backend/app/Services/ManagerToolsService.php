<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManagerToolsService
{
    public static function held(int $manager, int $lot): int
    {
        return (int) (DB::table('manager_tool_movements')->where('manager_id', $manager)->where('lot_id', $lot)
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type='issue' THEN quantity ELSE -quantity END),0) qty")->value('qty') ?? 0);
    }

    public static function warehouse(int $lot): int
    {
        $quantity = (int) DB::table('production_tool_lots')->where('id', $lot)->value('quantity');
        $out = (int) DB::table('manager_tool_movements')->where('lot_id', $lot)
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type='issue' THEN quantity WHEN movement_type='return' THEN -quantity ELSE 0 END),0) qty")->value('qty');

        return $quantity - $out;
    }

    public function receive(User $actor, array $data, object $settings, string $key): array
    {
        BusinessSupport::location($actor, $data['location_id'], (int) $data['branch_id']);
        $currency = BusinessSupport::currency($data, $settings);
        InventoryService::precision($data['unit_cost'], $currency, 'unit_cost');
        if (! DB::table('production_tools')->where('id', $data['tool_id'])->where('status', 'active')->exists()) {
            InventoryService::fail('tool_id', 'Choose an active tool.');
        }
        if (! empty($data['supplier_id']) && ! DB::table('suppliers')->where('id', $data['supplier_id'])->where('status', 'active')->whereNull('deleted_at')->exists()) {
            InventoryService::fail('supplier_id', 'Choose an active supplier.');
        }
        $payment = $data['payment'] ?? null;
        unset($data['payment']);
        $data['lot_no'] = DocumentNumbers::next('MTL');
        $data['total_cost'] = InventoryService::converted((string) $data['quantity'], $data['unit_cost'], $currency->decimal_places);
        $data['total_cost_base'] = BusinessSupport::base($data['total_cost'], $data['rate_to_base']);
        $id = BusinessSupport::insert($actor, 'production_tool_lots', $data);
        if ($payment) {
            FoundationAccess::permit($actor, 'materials.pay');
            $this->pay($actor, $id, validator($payment, BusinessRules::payment())->validate(), $key);
        }

        return ['id' => $id, 'lot_no' => $data['lot_no']];
    }

    public function pay(User $actor, int $id, array $data, string $key): array
    {
        $lot = BusinessSupport::document($actor, 'production_tool_lots', $id);
        $remaining = bcsub($lot->total_cost, BusinessSupport::paid('production_tool_payments', 'lot_id', $id), 2);
        $entry = BusinessSupport::money($actor, $lot, $data, $key, 'out', $remaining);

        return ['id' => BusinessSupport::insert($actor, 'production_tool_payments', $entry + ['lot_id' => $id])];
    }

    public function move(User $actor, int $managerId, array $data): array
    {
        $manager = ManagerMaterialsService::manager($actor, $managerId, $data['movement_type'] === 'issue');
        if (! empty($data['production_order_id'])) {
            $order = ManagerProductionService::open($actor, $data['production_order_id']);
            if ((int) $order->manager_id !== $managerId || (int) $order->branch_id !== (int) $manager->branch_id)
                InventoryService::fail('production_order_id', 'Choose an open order belonging to this manager and branch.');
        }
        app(InventoryService::class)->branch($actor, $manager->branch_id);
        $lot = BusinessSupport::document($actor, 'production_tool_lots', $data['lot_id']);
        if ((int) $manager->branch_id !== (int) $lot->branch_id) {
            InventoryService::fail('lot_id', 'Choose a tool lot in the manager branch.');
        }
        $latest = DB::table('manager_tool_movements')->where('lot_id', $lot->id)->max('moved_on');
        if ($data['moved_on'] < max($lot->received_on, $latest ?? '')) {
            InventoryService::fail('moved_on', 'Movement date cannot precede receiving or the latest movement for this tool lot.');
        }
        $available = $data['movement_type'] === 'issue' ? self::warehouse($lot->id) : self::held($managerId, $lot->id);
        if ((int) $data['quantity'] > $available) {
            InventoryService::fail('quantity', 'Quantity exceeds the tools available at the source.');
        }
        if ($data['movement_type'] === 'return' && strtolower(trim($data['condition'])) !== 'good') {
            InventoryService::fail('condition', 'Only tools in good condition can return to usable warehouse stock. Record damaged tools as damage.');
        }

        return ['id' => BusinessSupport::insert($actor, 'manager_tool_movements', $data + ['manager_id' => $managerId])];
    }
}
