<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FoundationAccess as Access;
use App\Services\InventoryService as Inventory;
use App\Services\ManagerProductionRules as Rules;
use App\Services\ManagerProductionService as Production;
use App\Services\ManagerToolsService;
use App\Services\ProductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManagerProductionController extends Controller
{
    public function lookups(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('production.view') || $actor->hasPermission('materials.view'), 403);
        $settings = DB::table('company_settings')->find(1);
        $data = ['company' => ['base_currency_id' => $settings->base_currency_id, 'timezone' => $settings->timezone],
            'managers' => Inventory::branchScope(DB::table('production_managers'), $actor)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'branch_id']),
            'branches' => Inventory::branchScope(DB::table('branches'), $actor, 'id')->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'locations' => Inventory::branchScope(DB::table('stock_locations'), $actor)->where('status', 'active')->whereNotNull('branch_id')->whereNull('washing_facility_id')->orderBy('name')->get(['id', 'name', 'branch_id']),
            'categories' => DB::table('carpet_categories')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'name_fa']),
            'currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'decimal_places']),
            'suppliers' => DB::table('suppliers')->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'facilities' => DB::table('washing_facilities')->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'facility_locations' => DB::table('stock_locations')->where('status', 'active')->whereNotNull('washing_facility_id')->orderBy('name')->get(['id', 'name', 'washing_facility_id']),
            'accounts' => Access::accounts(DB::table('accounts as a'), $actor, 'a.id')->join('v_account_balances as b', 'b.account_id', '=', 'a.id')
                ->where('a.status', 'active')->orderBy('a.name')->get(['a.id', 'a.name', 'a.currency_id', 'b.balance']),
            'material_lots' => [], 'tools' => [], 'tool_lots' => [], 'production_orders' => []];
        if (! $actor->hasPermission('accounts.view') || ! $actor->hasPermission('ledger.view')) {
            $data['accounts']->transform(function ($account) {
                unset($account->balance);

                return $account;
            });
        }
        if ($actor->hasPermission('materials.view')) {
            $data['material_lots'] = Inventory::branchScope(DB::table('material_lots as l'), $actor, 'l.branch_id')
                ->join('materials as m', 'm.id', '=', 'l.material_id')->orderByDesc('l.id')->get(['l.id', 'l.lot_no', 'l.branch_id', 'm.name', 'm.name as material_name', 'm.unit'])
                ->map(function ($lot) {
                    $lot->warehouse_quantity = ProductionService::warehouse($lot->id);

                    return $lot;
                });
            $data['tools'] = DB::table('production_tools')->where('status', 'active')->orderBy('name')->get(['id', 'name']);
            $data['tool_lots'] = Inventory::branchScope(DB::table('production_tool_lots as l'), $actor, 'l.branch_id')->join('production_tools as t', 't.id', '=', 'l.tool_id')
                ->orderByDesc('l.id')->get(['l.id', 'l.lot_no', 'l.branch_id', 't.name', 't.name as tool_name'])
                ->map(function ($lot) {
                    $lot->warehouse_quantity = ManagerToolsService::warehouse($lot->id);

                    return $lot;
                });
        }
        if ($actor->hasPermission('production.view')) {
            $data['production_orders'] = Inventory::branchScope(DB::table('manager_production_orders as o'), $actor, 'o.branch_id')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('manager_production_closures as c')->whereColumn('c.production_order_id', 'o.id'))
                ->orderByDesc('o.id')->get(['o.id', 'o.order_no', 'o.manager_id', 'o.branch_id']);
        }
        $data['lots'] = $data['material_lots'];
        $data['materials'] = $actor->hasPermission('materials.view') ? DB::table('materials')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']) : [];
        $data['workflow_defaults'] = Inventory::branchScope(DB::table('production_workflow_defaults'), $actor)->get();
        $data['has_production_history'] = $actor->hasPermission('production.view') && Inventory::branchScope(DB::table('production_orders'), $actor)->where('model', 'company_funded')->exists();
        $data['legacy_production_exists'] = $data['has_production_history'];

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        Access::permit($request->user(), 'production.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $query = Inventory::branchScope(DB::table('manager_production_orders as o'), $request->user(), 'o.branch_id')
            ->join('production_managers as m', 'm.id', '=', 'o.manager_id')->join('branches as b', 'b.id', '=', 'o.branch_id')
            ->join('currencies as c', 'c.id', '=', 'o.currency_id')->select('o.*', 'm.name as manager_name', 'b.name as branch_name', 'c.code as currency_code');
        if (! empty($filters['search'])) {
            $query->where(fn ($q) => $q->where('o.order_no', 'like', '%'.$filters['search'].'%')->orWhere('m.name', 'like', '%'.$filters['search'].'%'));
        }
        $page = $query->orderByDesc('o.id')->paginate($filters['per_page'] ?? 15);
        $page->getCollection()->transform(fn ($row) => Production::summary($row));

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id)
    {
        Access::permit($request->user(), 'production.view');
        $actor = $request->user();
        $order = Production::summary(Production::order($actor, $id));
        $order->manager_name = DB::table('production_managers')->where('id', $order->manager_id)->value('name');
        $order->branch_name = DB::table('branches')->where('id', $order->branch_id)->value('name');
        $order->currency_code = DB::table('currencies')->where('id', $order->currency_id)->value('code');
        $order->lines = DB::table('manager_production_lines')->where('production_order_id', $id)->get()->map(function ($line) {
            $line->delivered_quantity = DB::table('manager_delivery_items')->where('line_id', $line->id)->count();
            $line->accepted_quantity = DB::table('manager_delivery_acceptances as a')->join('manager_delivery_items as i', 'i.id', '=', 'a.item_id')->where('i.line_id', $line->id)->count();

            return $line;
        });
        $order->factors = DB::table('manager_pricing_factors')->where('production_order_id', $id)->orderBy('position')->orderBy('id')->get();
        $order->deliveries = DB::table('manager_production_deliveries')->where('production_order_id', $id)->orderByDesc('id')->get()->map(function ($delivery) use ($actor) {
            $delivery->items = DB::table('manager_delivery_items as i')->join('carpets as c', 'c.id', '=', 'i.carpet_id')
                ->leftJoin('manager_delivery_acceptances as a', 'a.item_id', '=', 'i.id')
                ->leftJoin('v_current_stock as s', function ($join) use ($actor) {
                    $join->on('s.id', '=', 'c.id');
                    if (! Access::administrator($actor)) {
                        $join->whereIn('s.custody_branch_id', Access::branchIds($actor));
                    }
                })->leftJoin('stock_locations as l', 'l.id', '=', 's.location_id')->where('i.delivery_id', $delivery->id)
                ->get(['i.*', 'c.carpet_code', 'a.accepted_on', 's.stock_status', 's.sequence_no', 'l.name as location_name']);

            $washing = DB::table('stock_events as e')
                ->join('washing_order_items as i', 'i.id', '=', 'e.washing_order_item_id')
                ->join('washing_orders as o', 'o.id', '=', 'i.washing_order_id')
                ->join('washing_facilities as f', 'f.id', '=', 'o.facility_id')
                ->join('stock_locations as l', 'l.id', '=', 'o.facility_location_id')
                ->whereIn('e.carpet_id', $delivery->items->pluck('carpet_id'))
                ->where('e.sequence_no', 2)->where('e.event_type', 'washing_dispatch')
                ->first(['o.id as washing_order_id', 'o.order_no as washing_reference', 'f.name as facility_name', 'l.name as facility_location_name']);
            if ($washing) {
                foreach ((array) $washing as $key => $value) {
                    $delivery->{$key} = $value;
                }
            }

            return $delivery;
        });
        $order->payments = [];
        if ($actor->hasPermission('accounts.view') && $actor->hasPermission('ledger.view')) {
            $order->payments = Access::accounts(DB::table('manager_production_payments as p')->join('account_transactions as t', 't.id', '=', 'p.account_transaction_id')
                ->join('accounts as a', 'a.id', '=', 't.account_id'), $actor, 'a.id')->join('currencies as c', 'c.id', '=', 't.currency_id')->where('p.production_order_id', $id)
                ->select('p.*', 't.amount', 't.transaction_date', 't.description', 'a.name as account_name', 'c.code as currency_code')
                ->selectRaw('EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=t.id) reversed')->orderBy('p.id')->get();
        }
        $order->unallocated_material_movements = [];
        if ($actor->hasPermission('materials.view')) {
            $order->unallocated_material_movements = DB::table('manager_material_movements as m')->join('material_lots as l', 'l.id', '=', 'm.lot_id')->join('materials as t', 't.id', '=', 'l.material_id')
                ->where('m.production_order_id', $id)->whereIn('m.movement_type', ['consume', 'waste'])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('manager_delivery_materials as a')->whereColumn('a.movement_id', 'm.id'))
                ->orderBy('m.id')->get(['m.*', 'l.lot_no', 't.name as material_name', 't.unit']);
        }
        $order->closure = DB::table('manager_production_closures')->where('production_order_id', $id)->first();

        return response()->json(['data' => $order])->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, int $id, Production $service)
    {
        Access::permit($request->user(), 'production.view');
        $data = $request->validate(Rules::for('preview'));

        return response()->json(['data' => $service->preview($request->user(), $id, $data['items'])])->header('Cache-Control', 'private, no-store');
    }

    public function command(Request $request, Production $service)
    {
        $action = (string) $request->route('action', 'order');
        $id = $request->route('id') === null ? null : (int) $request->route('id');
        Access::permit($request->user(), 'production.'.match ($action) {
            'order' => 'create', 'delivery' => 'receive', 'pay' => 'pay', 'close' => 'manage', default => abort(404)
        });
        $key = validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[a-zA-Z0-9_.:-]+$/']])->validate()['key'];
        $raw = $request->all();
        $result = app(Inventory::class)->command($request->user(), 'manager-production:'.$action.':'.$id, $raw, $key, function ($settings) use ($request, $service, $action, $id, $key, $raw) {
            $data = validator($raw, Rules::for($action))->validate();

            return match ($action) {
                'order' => $service->create($request->user(), $data, $settings),
                'delivery' => $service->deliver($request->user(), $id, $data),
                'pay' => $service->pay($request->user(), $id, $data, $key),
                'close' => $service->close($request->user(), $id, $data),
            };
        });

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }
}
