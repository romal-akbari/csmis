<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Manager contracts, cumulative partial deliveries and earned output. No worker performance records. */
class ManagerProductionService
{
    public static function order(User $actor, int $id): object
    {
        return BusinessSupport::document($actor, 'manager_production_orders', $id);
    }

    public static function open(User $actor, int $id): object
    {
        $order = self::order($actor, $id);
        if (DB::table('manager_production_closures')->where('production_order_id', $id)->exists()) {
            InventoryService::fail('order_id', 'This production order is closed. Its accepted output and settlement are fixed.');
        }

        return $order;
    }

    public static function earned(int $id): string
    {
        return bcadd((string) DB::table('manager_earned_charges')->where('production_order_id', $id)->sum('amount'), '0', 2);
    }

    public static function paid(int $id): string
    {
        return bcadd(BusinessSupport::paid('manager_production_payments', 'production_order_id', $id), '0', 2);
    }

    public static function summary(object $order): object
    {
        $order->target_quantity = (int) DB::table('manager_production_lines')->where('production_order_id', $order->id)->sum('target_quantity');
        $order->delivered_quantity = DB::table('manager_delivery_items')->where('production_order_id', $order->id)->count();
        $order->accepted_quantity = DB::table('manager_delivery_acceptances as a')->join('manager_delivery_items as i', 'i.id', '=', 'a.item_id')->where('i.production_order_id', $order->id)->count();
        $order->earned_amount = self::earned($order->id);
        $order->paid_amount = self::paid($order->id);
        $order->balance_due = bcsub($order->earned_amount, $order->paid_amount, 2);
        $order->state = DB::table('manager_production_closures')->where('production_order_id', $order->id)->exists() ? 'closed'
            : ($order->accepted_quantity === $order->target_quantity ? (bccomp($order->balance_due, '0', 2) === 0 ? 'completed' : 'accepted')
                : ($order->accepted_quantity > 0 ? 'partially_accepted'
                    : ($order->delivered_quantity > 0 ? 'washing'
                        : ((DB::table('manager_material_movements')->where('production_order_id', $order->id)->exists() || DB::table('manager_tool_movements')->where('production_order_id', $order->id)->exists() || DB::table('manager_production_payments')->where('production_order_id', $order->id)->exists()) ? 'in_production' : 'planned'))));

        return $order;
    }

    private function factors(User $actor, int $order, ?int $line, array $factors): void
    {
        foreach ($factors as $position => $factor) {
            if ($factor['kind'] === 'rate_quantity' && empty($factor['quantity'])) {
                InventoryService::fail('factors', 'Enter the agreed quantity for a rate × quantity factor.');
            }
            if ($factor['kind'] === 'multiplier' && ($factor['operation'] !== 'add' || bccomp($factor['value'], '0', 6) <= 0)) {
                InventoryService::fail('factors', 'A multiplier must be positive and use Add. Use a percentage deduction to reduce the price.');
            }
            BusinessSupport::insert($actor, 'manager_pricing_factors', $factor + ['production_order_id' => $order, 'line_id' => $line, 'position' => $position]);
        }
    }

    public function create(User $actor, array $data, object $settings): array
    {
        app(InventoryService::class)->branch($actor, $data['branch_id']);
        $currency = BusinessSupport::currency($data, $settings);
        $manager = InventoryService::branchScope(DB::table('production_managers'), $actor)->where('id', $data['manager_id'])->first();
        abort_unless($manager, 404);
        if ($manager->status !== 'active' || (int) $manager->branch_id !== (int) $data['branch_id']) {
            InventoryService::fail('manager_id', 'Choose an active production manager in this branch.');
        }
        $lines = $data['lines'];
        $factors = $data['factors'] ?? [];
        unset($data['lines'], $data['factors']);
        $data['order_no'] = DocumentNumbers::next('MPR');
        $id = BusinessSupport::insert($actor, 'manager_production_orders', $data);
        foreach ($lines as $line) {
            if (! DB::table('carpet_categories')->where('id', $line['category_id'])->where('status', 'active')->exists()) {
                InventoryService::fail('lines', 'Choose an active carpet category for every design.');
            }
            InventoryService::precision($line['rate'], $currency, 'lines');
            if ($line['pricing_method'] === 'manual' && empty($line['manual_reason'])) {
                InventoryService::fail('lines', 'Explain every manually agreed line amount.');
            }
            if (empty($line['length_cm']) !== empty($line['width_cm'])) {
                InventoryService::fail('lines', 'Enter both planned dimensions, or leave both blank.');
            }
            $lineFactors = $line['factors'] ?? [];
            unset($line['factors']);
            $lineId = BusinessSupport::insert($actor, 'manager_production_lines', $line + ['production_order_id' => $id]);
            $this->factors($actor, $id, $lineId, $lineFactors);
        }
        $this->factors($actor, $id, null, $factors);

        return ['id' => $id, 'order_no' => $data['order_no']];
    }

    public function preview(User $actor, int $id, array $items): array
    {
        $order = self::open($actor, $id);
        $lines = DB::table('manager_production_lines')->where('production_order_id', $id)->get()->keyBy('id');
        $existing = DB::table('manager_delivery_items')->where('production_order_id', $id)
            ->select('line_id')->selectRaw('COUNT(*) count,SUM(area_m2) area')->groupBy('line_id')->get();
        $quantities = [];
        foreach ($existing as $row) {
            $quantities[$row->line_id] = ['count' => (string) $row->count, 'area' => (string) $row->area];
        }
        $counts = [];
        foreach ($items as $item) {
            $line = $lines->get($item['line_id']);
            if (! $line) {
                InventoryService::fail('items', 'Choose design lines belonging to this production order.');
            }
            $counts[$line->id] = ($counts[$line->id] ?? 0) + 1;
            if ($line->target_quantity < $counts[$line->id] + (int) ($quantities[$line->id]['count'] ?? 0)) {
                InventoryService::fail('items', 'Delivered quantity cannot exceed the agreed target for a design.');
            }
        }
        $factors = DB::table('manager_pricing_factors')->where('production_order_id', $id)->orderBy('position')->orderBy('id')->get()->all();
        $precision = (int) DB::table('currencies')->where('id', $order->currency_id)->value('decimal_places');

        return ManagerProductionPricing::delivery($lines->values()->all(), $factors, $quantities, $items, $precision);
    }

    public function deliver(User $actor, int $id, array $data): array
    {
        FoundationAccess::permit($actor, 'washing.send');
        $order = self::open($actor, $id);
        app(InventoryService::class)->branch($actor, $order->branch_id);
        $latest = DB::table('manager_production_deliveries')->where('production_order_id', $id)->max('delivered_on');
        if ($data['delivered_on'] < max($order->ordered_on, $latest ?? '')) {
            InventoryService::fail('delivered_on', 'Delivery date cannot precede the order or its latest delivery.');
        }
        $pricing = $this->preview($actor, $id, $data['items']);
        $target = (int) DB::table('manager_production_lines')->where('production_order_id', $id)->sum('target_quantity');
        $delivered = DB::table('manager_delivery_items')->where('production_order_id', $id)->count();
        if ($delivered + count($data['items']) === $target) {
            $missing = DB::table('manager_material_movements as m')->where('m.production_order_id', $id)->whereIn('m.movement_type', ['consume', 'waste'])
                ->whereNotIn('m.id', $data['material_movement_ids'])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('manager_delivery_materials as a')->whereColumn('a.movement_id', 'm.id'))->exists();
            if ($missing) {
                InventoryService::fail('material_movement_ids', 'The final delivery must include every remaining consumption and waste cost for this order.');
            }
        }
        $materials = DB::table('manager_material_movements as m')->whereIn('m.id', $data['material_movement_ids'])
            ->where('m.production_order_id', $id)->where('m.manager_id', $order->manager_id)->whereIn('m.movement_type', ['consume', 'waste'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('manager_delivery_materials as a')->whereColumn('a.movement_id', 'm.id'))->get();
        if ($materials->count() !== count($data['material_movement_ids'])) {
            InventoryService::fail('material_movement_ids', 'Choose unallocated consumption or waste records belonging to this order and manager.');
        }
        $materialCost = '0';
        foreach ($materials as $movement) {
            if ($data['delivered_on'] < $movement->moved_on) {
                InventoryService::fail('delivered_on', 'Delivery date cannot precede the allocated material consumption.');
            }
            $materialCost = bcadd($materialCost, $movement->cost_base, 2);
        }
        $laborBase = BusinessSupport::base($pricing['charge_amount'], $order->rate_to_base);
        $totalCost = ManagerProductionPricing::bounded(bcadd($materialCost, $laborBase, 2), 'material_movement_ids');
        if (bccomp($totalCost, '0', 2) <= 0) {
            InventoryService::fail('material_movement_ids', 'Record the actual material consumption or an agreed production charge before delivering.');
        }
        $deliveryNo = DocumentNumbers::next('MDL');
        $delivery = BusinessSupport::insert($actor, 'manager_production_deliveries', ['production_order_id' => $id, 'delivery_no' => $deliveryNo,
            'delivered_on' => $data['delivered_on'], 'charge_amount' => $pricing['charge_amount'], 'material_cost_base' => $materialCost, 'total_cost_base' => $totalCost]);
        foreach ($materials as $movement) {
            BusinessSupport::insert($actor, 'manager_delivery_materials', ['delivery_id' => $delivery, 'movement_id' => $movement->id]);
        }
        $area = '0';
        foreach ($pricing['items'] as $item) {
            $area = bcadd($area, $item['area_m2'], 6);
        }
        $lines = DB::table('manager_production_lines')->where('production_order_id', $id)->get()->keyBy('id');
        $cumulativeArea = $cumulativeCharge = $previousMaterial = $previousLabor = '0';
        $dispatch = [];
        foreach ($pricing['items'] as $index => $item) {
            $line = $lines->get($item['line_id']);
            $code = ! empty($item['carpet_code']) ? $item['carpet_code'] : DocumentNumbers::next('MPC');
            if (DB::table('carpets')->where('carpet_code', $code)->exists()) {
                InventoryService::fail('items', 'A carpet code is already in use. Choose a unique code or leave it blank.');
            }
            $carpet = app(InventoryService::class)->carpet($actor, ['carpet_code' => $code, 'name' => $line->name,
                'registration_branch_id' => $order->branch_id, 'category_id' => $line->category_id,
                'length_cm' => $item['length_cm'], 'width_cm' => $item['width_cm'], 'notes' => $line->notes]);
            $cumulativeArea = bcadd($cumulativeArea, $item['area_m2'], 6);
            $cumulativeCharge = bcadd($cumulativeCharge, $item['charge_amount'], 2);
            $last = $index === count($pricing['items']) - 1;
            $currentMaterial = $last ? $materialCost : bcadd(InventoryService::converted(bcdiv($cumulativeArea, $area, 12), $materialCost, BusinessSupport::basePrecision()), '0', 2);
            $currentLabor = $last ? $laborBase : BusinessSupport::base($cumulativeCharge, $order->rate_to_base);
            $cost = bcadd(bcsub($currentMaterial, $previousMaterial, 2), bcsub($currentLabor, $previousLabor, 2), 2);
            $previousMaterial = $currentMaterial;
            $previousLabor = $currentLabor;
            $itemId = BusinessSupport::insert($actor, 'manager_delivery_items', ['delivery_id' => $delivery, 'production_order_id' => $id,
                'line_id' => $line->id, 'carpet_id' => $carpet['id'], 'length_cm' => $item['length_cm'], 'width_cm' => $item['width_cm'],
                'area_m2' => $item['area_m2'], 'charge_amount' => $item['charge_amount'], 'cost_base' => $cost]);
            $event = ['carpet_id' => $carpet['id'], 'sequence_no' => 1, 'event_type' => 'production_complete',
                'resulting_status' => 'production_completed', 'resulting_location_id' => null, 'manager_delivery_item_id' => $itemId,
                'occurred_on' => $data['delivered_on'], 'handled_by' => $actor->id, 'created_at' => now()];
            $eventId = DB::table('stock_events')->insertGetId($event);
            AuditLogger::log('create', 'stock_events', $eventId, null, $event);
            if ($order->payment_trigger === 'completion') {
                $this->earn($actor, $order, $itemId, $item['charge_amount'], $data['delivered_on']);
            }
            $dispatch[] = ['carpet_id' => $carpet['id'], 'expected_sequence' => 1];
        }
        $washing = app(WashingService::class)->dispatch($actor, ['order_no' => DocumentNumbers::next('MWASH'), 'branch_id' => $order->branch_id,
            'facility_id' => $data['facility_id'], 'facility_location_id' => $data['facility_location_id'], 'sent_on' => $data['delivered_on'], 'items' => $dispatch]);

        return ['id' => $delivery, 'delivery_no' => $deliveryNo, 'washing_order_id' => $washing['id'], 'charge_amount' => $pricing['charge_amount'], 'total_cost_base' => $totalCost];
    }

    private function earn(User $actor, object $order, int $item, string $amount, string $date): void
    {
        BusinessSupport::insert($actor, 'manager_earned_charges', ['production_order_id' => $order->id, 'item_id' => $item, 'earned_on' => $date, 'amount' => $amount]);
    }

    public static function acceptWashing(User $actor, int $visitId, string $date): void
    {
        $visit = DB::table('washing_order_items')->where('id', $visitId)->first();
        $item = $visit ? DB::table('manager_delivery_items')->where('carpet_id', $visit->carpet_id)->first() : null;
        if (! $item || DB::table('manager_delivery_acceptances')->where('item_id', $item->id)->exists()) {
            return;
        }
        $order = self::open($actor, $item->production_order_id);
        $deliveryDate = DB::table('manager_production_deliveries')->where('id', $item->delivery_id)->value('delivered_on');
        if ($date < $deliveryDate) {
            InventoryService::fail('returned_on', 'Acceptance cannot precede the production delivery.');
        }
        BusinessSupport::insert($actor, 'manager_delivery_acceptances', ['item_id' => $item->id, 'washing_order_item_id' => $visitId, 'accepted_on' => $date]);
        if ($order->payment_trigger === 'stock_acceptance') {
            (new self)->earn($actor, $order, $item->id, $item->charge_amount, $date);
        }
    }

    public function pay(User $actor, int $id, array $data, string $key): array
    {
        $order = self::open($actor, $id);
        $remaining = $data['payment_type'] === 'production' ? bcsub(self::earned($id), self::paid($id), 2) : null;
        $latest = DB::table('manager_earned_charges')->where('production_order_id', $id)->max('earned_on');
        if ($data['payment_type'] === 'production' && $latest && $data['payment_on'] < $latest) {
            InventoryService::fail('payment_on', 'Production payment must follow the earned charges it settles.');
        }
        $entry = BusinessSupport::money($actor, $order, $data, $key, 'out', $remaining);

        return ['id' => BusinessSupport::insert($actor, 'manager_production_payments', $entry + ['production_order_id' => $id, 'payment_type' => $data['payment_type']])];
    }

    public function close(User $actor, int $id, array $data): array
    {
        $order = self::summary(self::open($actor, $id));
        if ($order->accepted_quantity !== $order->target_quantity || $order->delivered_quantity !== $order->target_quantity) {
            InventoryService::fail('order_id', 'Deliver all agreed carpets and accept them from washing before closing.');
        }
        if (bccomp($order->balance_due, '0', 2) !== 0) {
            InventoryService::fail('order_id', 'Settle the earned production balance and all advances before closing.');
        }
        $unallocated = DB::table('manager_material_movements as m')->where('production_order_id', $id)->whereIn('movement_type', ['consume', 'waste'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('manager_delivery_materials as a')->whereColumn('a.movement_id', 'm.id'))->exists();
        if ($unallocated) {
            InventoryService::fail('order_id', 'Allocate all consumed material and waste to the delivered carpets before closing.');
        }
        $latest = max($order->ordered_on,
            DB::table('manager_material_movements')->where('production_order_id', $id)->max('moved_on') ?? '',
            DB::table('manager_delivery_acceptances as a')->join('manager_delivery_items as i', 'i.id', '=', 'a.item_id')->where('i.production_order_id', $id)->max('accepted_on') ?? '',
            DB::table('manager_production_payments as p')->join('account_transactions as t', 't.id', '=', 'p.account_transaction_id')->where('p.production_order_id', $id)->max('transaction_date') ?? '');
        if ($data['closed_on'] < $latest) {
            InventoryService::fail('closed_on', 'Closing date must follow material movements, accepted deliveries and payments.');
        }

        return ['id' => BusinessSupport::insert($actor, 'manager_production_closures', $data + ['production_order_id' => $id])];
    }
}
