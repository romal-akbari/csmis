<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FoundationAccess as Access;
use App\Services\InventoryService;
use App\Services\ManagerProductionService;
use App\Services\ManagerWorkflowRules;
use App\Services\ManagerWorkflowService;
use App\Services\ManagerWorkflowStock;
use App\Services\SupplyPurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManagerWorkflowController extends Controller
{
    public function context(Request $request, int $id)
    {
        $actor = $request->user();
        Access::permit($actor, 'production.view');
        $order = ManagerProductionService::order($actor, $id);
        $stock = $actor->hasPermission('materials.view') ? app(ManagerWorkflowStock::class)->available($actor, $order) : ['materials' => [], 'tools' => []];
        return response()->json(['data' => $stock + ['defaults' => app(ManagerWorkflowService::class)->defaults($actor, $order->branch_id),
            'washing_items' => app(ManagerWorkflowService::class)->washingItems($actor, $order)]])->header('Cache-Control', 'private, no-store');
    }

    public function supplies(Request $request)
    {
        Access::permit($request->user(), 'materials.view');
        $filters = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100']]);
        $query = InventoryService::branchScope(DB::table('supply_purchases as p'), $request->user(), 'p.branch_id')
            ->join('currencies as c', 'c.id', '=', 'p.currency_id')->join('stock_locations as l', 'l.id', '=', 'p.location_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')->select('p.*', 'c.code as currency_code', 'l.name as location_name', 's.name as supplier_name');
        if (! empty($filters['search'])) $query->where(fn ($q) => $q->where('p.purchase_no', 'like', '%'.$filters['search'].'%')->orWhere('s.name', 'like', '%'.$filters['search'].'%'));
        $page = $query->orderByDesc('p.id')->paginate(15);
        $page->getCollection()->transform(fn ($row) => app(SupplyPurchaseService::class)->summary($row));
        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function command(Request $request)
    {
        $action = (string) $request->route('action');
        $id = $request->route('id') === null ? null : (int) $request->route('id');
        $permission = match ($action) { 'setup', 'assistance' => 'production.manage', 'issue' => 'production.view', 'receive' => 'production.receive',
            'accept' => 'production.view', 'settle' => 'production.pay', 'supply' => 'materials.receive', 'supply-pay' => 'materials.pay', default => abort(404) };
        Access::permit($request->user(), $permission);
        $key = validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[a-zA-Z0-9_.:-]+$/']])->validate()['key'];
        $raw = $request->all();
        $result = app(InventoryService::class)->command($request->user(), 'production-workflow:'.$action.':'.$id, $raw, $key, function ($settings) use ($request, $action, $id, $raw, $key) {
            $data = validator($raw, ManagerWorkflowRules::for($action))->validate();
            $service = app(ManagerWorkflowService::class);
            return match ($action) {
                'setup' => $service->setup($request->user(), $data),
                'issue' => $service->issue($request->user(), $id, $data, $key),
                'receive' => $service->receive($request->user(), $id, $data),
                'accept' => $service->accept($request->user(), $id, $data, $settings, $key),
                'settle' => app(ManagerProductionService::class)->pay($request->user(), $id, $data + ['payment_type' => 'production'], $key),
                'supply-pay' => app(SupplyPurchaseService::class)->pay($request->user(), $id, $data, $key),
                'supply' => app(SupplyPurchaseService::class)->create($request->user(), $data, $settings, $key),
                'assistance' => $service->assistance($request->user(), $id, $data, $settings, $key),
            };
        });
        return response()->json($result, $result['replayed'] ? 200 : 201);
    }
}
