<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public static function held(int $lot, ?int $order = null): string
    {
        $query = DB::table('material_movements')->where('lot_id', $lot);
        if ($order !== null) {
            $query->where('production_order_id', $order);
        }

        return (string) ($query->selectRaw("COALESCE(SUM(CASE WHEN movement_type='issue' THEN quantity ELSE -quantity END),0) qty")->value('qty') ?? '0');
    }

    public static function warehouse(int $lot): string
    {
        $quantity = (string) DB::table('material_lots')->where('id', $lot)->value('quantity');
        $out = (string) DB::table('material_movements')->where('lot_id', $lot)
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type='issue' THEN quantity WHEN movement_type='return' THEN -quantity ELSE 0 END),0) qty")->value('qty');

        $managerOut = (string) DB::table('manager_material_movements')->where('lot_id', $lot)
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type='issue' THEN quantity WHEN movement_type='return' THEN -quantity ELSE 0 END),0) qty")->value('qty');

        return bcsub(bcsub($quantity, $out, 3), $managerOut, 3);
    }

    public static function state(int $id): string
    {
        if (DB::table('production_receipts')->where('production_order_id', $id)->exists()) {
            return 'received';
        }
        $progress = DB::table('production_progress')->where('production_order_id', $id)->orderByDesc('id')->value('percent');

        return (int) $progress === 100 ? 'completed' : ((int) $progress > 0 ? 'weaving' : (DB::table('material_movements')->where('production_order_id', $id)->exists() ? 'materials_issued' : 'planned'));
    }

    private function open(User $actor, int $id): object
    {
        $order = BusinessSupport::document($actor, 'production_orders', $id);
        if (self::state($id) === 'received') {
            InventoryService::fail('order_id', 'This production order is received. Its materials and costs are fixed.');
        }

        return $order;
    }

    public function material(User $actor, array $data): array
    {
        $data['code'] = $data['code'] ?? DocumentNumbers::next('ITEM');
        return ['id' => BusinessSupport::insert($actor, 'materials', $data)];
    }

    public function lot(User $actor, array $data, object $settings, string $key): array
    {
        BusinessSupport::location($actor, $data['location_id'], (int) $data['branch_id']);
        $currency = BusinessSupport::currency($data, $settings);
        InventoryService::precision($data['unit_cost'], $currency, 'unit_cost');
        abort_unless(DB::table('materials')->where('id', $data['material_id'])->where('status', 'active')->exists(), 422, 'Choose an active material.');
        if (! empty($data['supplier_id'])) {
            abort_unless(DB::table('suppliers')->where('id', $data['supplier_id'])->where('status', 'active')->whereNull('deleted_at')->exists(), 422, 'Choose an active supplier.');
        }
        $payment = $data['payment'] ?? null;
        unset($data['payment']);
        $data['lot_no'] = DocumentNumbers::next('MAT');
        $data['total_cost'] = InventoryService::converted($data['quantity'], $data['unit_cost'], $currency->decimal_places);
        $data['total_cost_base'] = BusinessSupport::base($data['total_cost'], $data['rate_to_base']);
        if (bccomp($data['total_cost'], '0', 2) <= 0) {
            InventoryService::fail('quantity', 'The receipt value must not round to zero.');
        }
        if (bccomp($data['total_cost_base'], '9999999999999999.99', 2) > 0) {
            InventoryService::fail('quantity', 'The receipt total exceeds the allowed range.');
        }
        $id = BusinessSupport::insert($actor, 'material_lots', $data);
        if ($payment) {
            FoundationAccess::permit($actor, 'materials.pay');
            $this->lotPay($actor, $id, validator($payment, BusinessRules::payment())->validate(), $key);
        }

        return ['id' => $id, 'lot_no' => $data['lot_no']];
    }

    public function lotPay(User $actor, int $id, array $data, string $key): array
    {
        $lot = BusinessSupport::document($actor, 'material_lots', $id);
        $remaining = bcsub($lot->total_cost, BusinessSupport::paid('material_lot_payments', 'lot_id', $id), 2);
        $entry = BusinessSupport::money($actor, $lot, $data, $key, 'out', $remaining);

        return ['id' => BusinessSupport::insert($actor, 'material_lot_payments', $entry + ['lot_id' => $id])];
    }

    public function create(User $actor, array $data, object $settings): array
    {
        app(InventoryService::class)->branch($actor, $data['branch_id']);
        BusinessSupport::currency($data, $settings);
        $weaver = DirectoryCatalog::scope(DB::table('weavers'), 'weavers', $actor)->where('id', $data['weaver_id'])
            ->where('status', 'active')->whereNull('deleted_at')->first();
        abort_unless($weaver, 404);
        if ($data['model'] === 'partnership') {
            FoundationAccess::permit($actor, 'partnerships.create');
        }
        $items = $data['items'];
        unset($data['items']);
        $data['order_no'] = DocumentNumbers::next($data['model'] === 'partnership' ? 'PART' : 'PROD');
        $id = BusinessSupport::insert($actor, 'production_orders', $data);
        foreach ($items as $item) {
            $carpet = InventoryService::carpets($actor)->where('s.id', $item['carpet_id'])->first();
            abort_unless($carpet, 404);
            if ($carpet->stock_status !== 'registered' || $carpet->acquisition_item_id || $carpet->production_order_id
                || (int) $carpet->custody_branch_id !== (int) $data['branch_id']) {
                InventoryService::fail('items', 'Choose registered, unpurchased carpets in this branch that have no production order.');
            }
            DB::table('production_carpets')->insert(['production_order_id' => $id, 'carpet_id' => $carpet->id, 'cost_weight' => $item['cost_weight']]);
        }

        return ['id' => $id, 'order_no' => $data['order_no']];
    }

    public function move(User $actor, int $id, array $data): array
    {
        $order = $this->open($actor, $id);
        $lot = BusinessSupport::document($actor, 'material_lots', $data['lot_id']);
        if ((int) $lot->branch_id !== (int) $order->branch_id) {
            InventoryService::fail('lot_id', 'Choose a material lot in the production branch.');
        }
        $latest = DB::table('material_movements')->where('lot_id', $lot->id)->max('moved_on');
        if ($data['moved_on'] < max($order->ordered_on, $lot->received_on, $latest ?? '')) {
            InventoryService::fail('moved_on', 'Material date cannot precede the order, receipt or latest movement for this lot.');
        }
        $issue = $data['movement_type'] === 'issue';
        $available = $issue ? self::warehouse($lot->id) : self::held($lot->id, $id);
        if (bccomp($data['quantity'], $available, 3) > 0) {
            InventoryService::fail('quantity', 'Quantity exceeds the material available at the source.');
        }
        $data['cost_base'] = '0';
        if (in_array($data['movement_type'], ['consume', 'waste'], true)) {
            $previous = (string) DB::table('material_movements')->where('lot_id', $lot->id)->whereIn('movement_type', ['consume', 'waste'])->sum('quantity');
            $previousCost = (string) DB::table('material_movements')->where('lot_id', $lot->id)->sum('cost_base');
            $previous = bcadd($previous, (string) DB::table('manager_material_movements')->where('lot_id', $lot->id)
                ->whereIn('movement_type', ['consume', 'waste'])->sum('quantity'), 3);
            $newTotal = bcadd($previous, $data['quantity'], 3);
            $previousCost = bcadd($previousCost, (string) DB::table('manager_material_movements')->where('lot_id', $lot->id)->sum('cost_base'), 2);
            // Cumulative valuation assigns rounding residue once when the last quantity is consumed.
            $target = InventoryService::converted(bcdiv($newTotal, $lot->quantity, 12), $lot->total_cost_base, BusinessSupport::basePrecision());
            $data['cost_base'] = bcsub($target, $previousCost, 2);
        }

        return ['id' => BusinessSupport::insert($actor, 'material_movements', $data + ['production_order_id' => $id])];
    }

    public function progress(User $actor, int $id, array $data): array
    {
        $order = $this->open($actor, $id);
        $latest = DB::table('production_progress')->where('production_order_id', $id)->orderByDesc('id')->first();
        if ($data['progress_on'] < max($order->ordered_on, $latest->progress_on ?? '') || ($latest && $data['percent'] < $latest->percent)) {
            InventoryService::fail('percent', 'Progress and its date must not move backwards.');
        }

        return ['id' => BusinessSupport::insert($actor, 'production_progress', $data + ['production_order_id' => $id])];
    }

    public function charge(User $actor, int $id, array $data): array
    {
        $order = $this->open($actor, $id);
        if ($order->model !== 'company_funded') {
            InventoryService::fail('order_id', 'Record partnership labor as a partner contribution under the agreement.');
        }
        InventoryService::precision($data['amount'], DB::table('currencies')->find($order->currency_id), 'amount');
        if ($data['earned_on'] < $order->ordered_on) {
            InventoryService::fail('earned_on', 'Labor date cannot precede the order.');
        }

        return ['id' => BusinessSupport::insert($actor, 'production_charges', $data + ['production_order_id' => $id])];
    }

    public function contribution(User $actor, int $id, array $data): array
    {
        $order = $this->open($actor, $id);
        if ($order->model !== 'partnership' || $data['contributed_on'] < $order->ordered_on) {
            InventoryService::fail('order_id', 'Choose an open partnership and a contribution date on or after its start.');
        }
        InventoryService::precision($data['amount'], DB::table('currencies')->find($order->currency_id), 'amount');

        return ['id' => BusinessSupport::insert($actor, 'partnership_contributions', $data + ['production_order_id' => $id])];
    }

    public function pay(User $actor, int $id, array $data, string $key): array
    {
        $order = BusinessSupport::document($actor, 'production_orders', $id);
        abort_if(DB::table('partnership_settlements')->where('production_order_id', $id)->exists(), 422, 'This partnership has already been settled.');
        $type = $data['payment_type'];
        if (($order->model === 'partnership') !== ($type === 'support')) {
            InventoryService::fail('payment_type', 'Use labor/advance for company production or support for a partnership.');
        }
        $remaining = null;
        if ($type === 'labor') {
            $earned = (string) DB::table('production_charges')->where('production_order_id', $id)->sum('amount');
            $remaining = bcsub($earned, BusinessSupport::paid('production_payments', 'production_order_id', $id), 2);
            $latest = DB::table('production_charges')->where('production_order_id', $id)->max('earned_on');
            if ($latest && $data['payment_on'] < $latest) {
                InventoryService::fail('payment_on', 'Labor settlement must follow the recorded labor charges.');
            }
        }
        $entry = BusinessSupport::money($actor, $order, $data, $key, 'out', $remaining);

        return ['id' => BusinessSupport::insert($actor, 'production_payments', $entry + ['production_order_id' => $id, 'payment_type' => $type])];
    }

    public function receive(User $actor, int $id, array $data): array
    {
        $order = $this->open($actor, $id);
        BusinessSupport::location($actor, $data['location_id'], (int) $order->branch_id);
        if (self::state($id) !== 'completed') {
            InventoryService::fail('order_id', 'Record 100% production completion before receiving the finished carpets.');
        }
        $lots = DB::table('material_movements')->where('production_order_id', $id)->distinct()->pluck('lot_id');
        foreach ($lots as $lot) {
            if (bccomp(self::held($lot, $id), '0', 3) !== 0) {
                InventoryService::fail('order_id', 'Consume, return or record waste for all materials held by this weaver before receiving.');
            }
        }
        $latest = max($order->ordered_on,
            DB::table('production_progress')->where('production_order_id', $id)->max('progress_on') ?? '',
            DB::table('production_charges')->where('production_order_id', $id)->max('earned_on') ?? '',
            DB::table('partnership_contributions')->where('production_order_id', $id)->max('contributed_on') ?? '',
            DB::table('material_movements')->where('production_order_id', $id)->max('moved_on') ?? '');
        if ($data['received_on'] < $latest) {
            InventoryService::fail('received_on', 'Receiving date must follow production progress, costs and material movements.');
        }
        $materials = (string) DB::table('material_movements')->where('production_order_id', $id)->sum('cost_base');
        $labor = (string) DB::table('production_charges')->where('production_order_id', $id)->sum('amount');
        $partner = (string) DB::table('partnership_contributions')->where('production_order_id', $id)->sum('amount');
        $cost = bcadd($materials, BusinessSupport::base(bcadd($labor, $partner, 2), $order->rate_to_base), 2);
        if (bccomp($cost, '0', 2) <= 0 || bccomp($cost, '9999999999999999.99', 2) > 0) {
            InventoryService::fail('order_id', 'Record the actual production costs before receiving. The total must fit the allowed range.');
        }
        $receipt = BusinessSupport::insert($actor, 'production_receipts', $data + ['production_order_id' => $id, 'total_cost_base' => $cost]);
        $items = DB::table('production_carpets')->where('production_order_id', $id)->orderBy('id')->get();
        $weight = '0';
        foreach ($items as $item) {
            $weight = bcadd($weight, $item->cost_weight, 3);
        }
        $allocated = '0';
        foreach ($items as $index => $item) {
            $carpet = InventoryService::carpets($actor)->where('s.id', $item->carpet_id)->first();
            abort_unless($carpet && $carpet->stock_status === 'registered', 409, 'A production carpet is no longer available.');
            $amount = $index === $items->count() - 1 ? bcsub($cost, $allocated, 2)
                : bcdiv(bcmul($cost, $item->cost_weight, 8), $weight, BusinessSupport::basePrecision());
            $allocated = bcadd($allocated, $amount, 2);
            $line = DB::table('production_receipt_items')->insertGetId(['production_receipt_id' => $receipt, 'production_carpet_id' => $item->id, 'cost_base' => $amount]);
            BusinessSupport::stockEvent($actor, $carpet, 'production_receive', $data['location_id'], $data['received_on'], ['production_receipt_item_id' => $line]);
        }

        return ['id' => $receipt, 'total_cost_base' => $cost];
    }
}
