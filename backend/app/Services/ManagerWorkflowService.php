<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagerWorkflowService
{
    public function defaults(User $actor, int $branch): ?object
    {
        app(InventoryService::class)->branch($actor, $branch);
        return DB::table('production_workflow_defaults')->where('branch_id', $branch)->first();
    }

    public function setup(User $actor, array $data): array
    {
        foreach (['materials_location_id', 'finished_location_id'] as $key) BusinessSupport::location($actor, $data[$key], $data['branch_id']);
        if (! DB::table('washing_facilities')->where('id', $data['facility_id'])->where('status', 'active')->whereNull('deleted_at')->exists())
            InventoryService::fail('facility_id', 'Choose an active washing facility.');
        if (! DB::table('stock_locations')->where('id', $data['facility_location_id'])->where('washing_facility_id', $data['facility_id'])
            ->where('location_type', 'washing')->where('status', 'active')->exists()) InventoryService::fail('facility_location_id', 'Choose an active washing location owned by this facility.');
        $old = $this->defaults($actor, $data['branch_id']);
        if ($old) {
            DB::table('production_workflow_defaults')->where('id', $old->id)->update($data + ['updated_at' => now()]);
            AuditLogger::log('update', 'production_workflow_defaults', $old->id, (array) $old, $data);
            return ['id' => $old->id];
        }
        return ['id' => BusinessSupport::insert($actor, 'production_workflow_defaults', $data + ['updated_at' => now()])];
    }

    public function issue(User $actor, int $id, array $data, string $key): array
    {
        $order = ManagerProductionService::open($actor, $id);
        if ($data['movement_type'] === 'issue') {
            $progress = ManagerProductionService::summary(clone $order);
            if ($progress->delivered_quantity >= $progress->target_quantity) InventoryService::fail('materials', 'All carpets are delivered. Return unused supplies or settle the earned balance.');
        }
        $defaults = $this->defaults($actor, $order->branch_id);
        $location = $data['location_id'] ?? $defaults?->materials_location_id;
        if ($data['movement_type'] === 'issue' && (count($data['materials']) + count($data['tools']) > 0)) {
            if (! $location) InventoryService::fail('location_id', 'Choose the materials store or save production setup first.');
            BusinessSupport::location($actor, $location, $order->branch_id);
        }
        if (! count($data['materials']) && ! count($data['tools']) && empty($data['advance'])) InventoryService::fail('materials', 'Enter materials, tools or a production advance.');
        foreach (['materials' => false, 'tools' => true] as $section => $tool) {
            foreach ($data[$section] as $item) app(ManagerWorkflowStock::class)->move($actor, $order, $tool,
                $item[$tool ? 'tool_id' : 'material_id'], (string) $item['quantity'], $data['movement_type'], $data['moved_on'], $data['reason'], $location);
        }
        if (! empty($data['advance'])) {
            FoundationAccess::permit($actor, 'production.pay');
            if ($data['movement_type'] !== 'issue') InventoryService::fail('advance', 'Record an advance when giving materials, not returning stock.');
            $payment = validator($data['advance'], BusinessRules::payment())->validate();
            app(ManagerProductionService::class)->pay($actor, $id, $payment + ['payment_type' => 'advance'], hash('sha256', $key.':advance'));
        }
        return ['id' => $id];
    }

    public function receive(User $actor, int $id, array $data): array
    {
        $order = ManagerProductionService::open($actor, $id);
        $defaults = $this->defaults($actor, $order->branch_id);
        $hasUsage = false;
        foreach ($data['usages'] as $usage) {
            foreach (['used_quantity' => 'consume', 'waste_quantity' => 'waste'] as $field => $type) {
                if (bccomp($usage[$field], '0', 3) <= 0) continue;
                $hasUsage = true;
                app(ManagerWorkflowStock::class)->move($actor, $order, false, $usage['material_id'], $usage[$field], $type,
                    $data['delivered_on'], ($type === 'waste' ? 'Waste for ' : 'Materials used for ').$order->order_no);
            }
        }
        $movements = DB::table('manager_material_movements as m')->where('m.production_order_id', $id)->whereIn('m.movement_type', ['consume', 'waste'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('manager_delivery_materials as a')->whereColumn('a.movement_id', 'm.id'))->pluck('m.id')->all();
        if (! $hasUsage && ! $movements && ! ($data['no_materials_used'] ?? false)) {
            InventoryService::fail('usages', 'Enter the actual material used, or confirm that this delivery used no company materials.');
        }
        if (($data['no_materials_used'] ?? false) && $hasUsage) InventoryService::fail('usages', 'Material quantities conflict with the no-materials confirmation.');
        $facility = $data['facility_id'] ?? $defaults?->facility_id;
        $location = $data['facility_location_id'] ?? ($facility == $defaults?->facility_id ? $defaults?->facility_location_id : null);
        if (! $facility || ! $location) InventoryService::fail('facility_location_id', 'Choose the washing facility and its washing location.');
        return app(ManagerProductionService::class)->deliver($actor, $id, ['delivered_on' => $data['delivered_on'],
            'facility_id' => $facility, 'facility_location_id' => $location, 'items' => $data['items'], 'material_movement_ids' => $movements]);
    }

    public function washingItems(User $actor, object $order)
    {
        return InventoryService::branchScope(DB::table('manager_delivery_items as m')->join('carpets as c', 'c.id', '=', 'm.carpet_id')
            ->join('v_current_stock as s', 's.id', '=', 'c.id'), $actor, 's.custody_branch_id')
            ->join('stock_events as e', function ($j) { $j->on('e.carpet_id', '=', 's.id')->on('e.sequence_no', '=', 's.sequence_no'); })
            ->join('washing_order_items as w', 'w.id', '=', 'e.washing_order_item_id')->join('washing_orders as o', 'o.id', '=', 'w.washing_order_id')
            ->join('washing_facilities as f', 'f.id', '=', 'o.facility_id')->where('m.production_order_id', $order->id)
            ->whereIn('s.stock_status', ['at_washing', 'washing_completed'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('manager_delivery_acceptances as a')->whereColumn('a.item_id', 'm.id'))
            ->select('w.id as washing_order_item_id', 'o.id as washing_order_id', 'o.facility_id', 'f.name as facility_name', 'c.carpet_code', 'm.area_m2', 's.sequence_no as expected_sequence', 's.stock_status')
            ->selectRaw('EXISTS(SELECT 1 FROM washing_bill_allocations a WHERE a.washing_order_item_id=w.id AND NOT EXISTS(SELECT 1 FROM washing_bill_voids v WHERE v.washing_bill_id=a.washing_bill_id)) AS billed')->get();
    }

    public function accept(User $actor, int $id, array $data, object $settings, string $key): array
    {
        FoundationAccess::permit($actor, 'washing.return');
        $order = ManagerProductionService::open($actor, $id);
        $defaults = $this->defaults($actor, $order->branch_id);
        $location = $data['location_id'] ?? $defaults?->finished_location_id;
        if (! $location) InventoryService::fail('location_id', 'Choose the finished-carpet store or save production setup first.');
        $available = $this->washingItems($actor, $order)->keyBy('washing_order_item_id');
        $selected = collect($data['items'])->map(function ($item) use ($available) {
            $row = $available->get($item['washing_order_item_id']);
            if (! $row) InventoryService::fail('items', 'Select carpets still at washing for this production order.');
            abort_unless((int) $row->expected_sequence === (int) $item['expected_sequence'], 409, 'Stock changed. Refresh the order.');
            return $row;
        });
        $washing = app(WashingService::class);
        foreach ($selected->groupBy('washing_order_id') as $batch => $items) {
            $pending = $items->where('stock_status', 'at_washing');
            if ($pending->count()) {
                FoundationAccess::permit($actor, 'washing.complete');
                $washing->progress($actor, $batch, ['completed_on' => $data['returned_on'], 'items' => $pending->map(fn ($i) => ['washing_order_item_id' => $i->washing_order_item_id, 'expected_sequence' => $i->expected_sequence])->values()->all()], false);
            }
            $washing->progress($actor, $batch, ['returned_on' => $data['returned_on'], 'location_id' => $location,
                'items' => $items->map(fn ($i) => ['washing_order_item_id' => $i->washing_order_item_id, 'expected_sequence' => $i->expected_sequence + ($i->stock_status === 'at_washing' ? 1 : 0)])->values()->all()], true);
        }
        $unbilled = $selected->filter(fn ($i) => ! $i->billed)->values();
        $bill = null;
        if ($unbilled->count() && ! $data['record_cost_later']) {
            FoundationAccess::permit($actor, 'washing-costs.create');
            if (empty($data['washing_amount'])) InventoryService::fail('washing_amount', 'Enter the actual washing charge or choose to record it later.');
            if ($unbilled->pluck('facility_id')->unique()->count() !== 1) InventoryService::fail('items', 'Receive one washing facility at a time when recording its bill.');
            $currency = DB::table('currencies')->find($order->currency_id);
            InventoryService::precision($data['washing_amount'], $currency, 'washing_amount');
            $totalArea = '0';
            foreach ($unbilled as $item) $totalArea = bcadd($totalArea, $item->area_m2, 6);
            $area = $previous = '0';
            $allocations = [];
            foreach ($unbilled as $index => $item) {
                $area = bcadd($area, $item->area_m2, 6);
                $amount = $index === $unbilled->count() - 1 ? $data['washing_amount'] : InventoryService::converted(bcdiv($area, $totalArea, 12), $data['washing_amount'], $currency->decimal_places);
                $part = bcsub($amount, $previous, 2);
                if (bccomp($part, '0', 2) <= 0) InventoryService::fail('washing_amount', 'The washing charge is too small to allocate to every selected carpet.');
                $allocations[] = ['washing_order_item_id' => $item->washing_order_item_id, 'amount' => $part];
                $previous = $amount;
            }
            $reference = DocumentNumbers::next('WBILL');
            $bill = $washing->bill($actor, ['expense_no' => $reference, 'invoice_no' => $data['invoice_no'] ?? $reference,
                'branch_id' => $order->branch_id, 'facility_id' => $unbilled[0]->facility_id, 'currency_id' => $order->currency_id,
                'rate_to_base' => $order->rate_to_base, 'billed_on' => $data['returned_on'], 'amount' => $data['washing_amount'], 'allocations' => $allocations], $settings);
            if (! empty($data['payment'])) {
                FoundationAccess::permit($actor, 'washing-costs.pay');
                $payment = validator($data['payment'], BusinessRules::payment())->validate();
                $payment['bill_units_per_payment_unit'] = $payment['units_per_payment_unit'];
                unset($payment['units_per_payment_unit']);
                $washing->payment($actor, $bill['id'], $payment + ['payment_no' => DocumentNumbers::next('WMPAY')], hash('sha256', $key.':washing'));
            }
        } elseif (! empty($data['payment'])) InventoryService::fail('payment', 'A new washing payment requires a new washing bill in this receipt.');
        return ['id' => $id, 'washing_bill_id' => $bill['id'] ?? null];
    }

    public function assistance(User $actor, int $manager, array $data, object $settings, string $key): array
    {
        if ((int) $data['manager_id'] !== $manager) InventoryService::fail('manager_id', 'Choose the manager whose profile is open.');
        $payment = $data['payment'] ?? null;
        $delivery = $data['delivery'] ?? null;
        unset($data['payment'], $data['delivery']);
        $service = app(WorkerAssistanceService::class);
        $result = $service->create($actor, $data, $settings);
        if ($payment) {
            FoundationAccess::permit($actor, 'production.pay');
            $this->section('payment', fn () => $service->transact($actor, $result['id'], 'fund', validator($payment, BusinessRules::payment())->validate(), hash('sha256', $key.':support')));
        }
        if ($delivery) $this->section('delivery', fn () => $service->deliver($actor, $result['id'], validator($delivery, ManagerResourcesRules::for('deliver'))->validate()));
        return $result;
    }

    private function section(string $name, callable $work): mixed
    {
        try {
            return $work();
        } catch (ValidationException $error) {
            $errors = [];
            foreach ($error->errors() as $field => $messages) $errors[$name.'.'.$field] = $messages;
            throw ValidationException::withMessages($errors);
        }
    }
}
