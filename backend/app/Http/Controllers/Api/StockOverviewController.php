<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FoundationAccess as Access;
use App\Services\InventoryService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockOverviewController extends Controller
{
    private function filters(Request $request): array
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('stock.view') || $actor->hasPermission('materials.view') || $actor->hasPermission('washing.view'), 403);

        return $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'tools_page' => ['nullable', 'integer', 'min:1'], 'carpets_page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100']]);
    }

    private function locations(User $actor): Builder
    {
        $scoped = Access::scope(DB::table('stock_locations'), 'stock_locations', $actor);

        return DB::query()->fromSub($scoped, 'location')->leftJoin('branches as branch', 'branch.id', '=', 'location.branch_id')
            ->leftJoin('washing_facilities as facility', 'facility.id', '=', 'location.washing_facility_id')
            ->select('location.*', 'branch.name as branch_name', 'facility.name as facility_name');
    }

    private function tools(User $actor): Builder
    {
        $movements = DB::table('manager_tool_movements')->select('lot_id')
            ->selectRaw("SUM(CASE WHEN movement_type='issue' THEN quantity WHEN movement_type='return' THEN -quantity ELSE 0 END) issued")
            ->groupBy('lot_id');

        return InventoryService::branchScope(DB::table('production_tool_lots as lot'), $actor, 'lot.branch_id')
            ->leftJoinSub($movements, 'movement', 'movement.lot_id', '=', 'lot.id')
            ->select('lot.id', 'lot.location_id', 'lot.tool_id')->selectRaw('lot.quantity-COALESCE(movement.issued,0) quantity');
    }

    private function carpets(User $actor): Builder
    {
        $query = DB::table('v_current_stock as carpet')->whereNull('carpet.deleted_at')
            ->whereIn('carpet.stock_status', ['in_stock', 'at_washing', 'washing_completed']);
        if (! Access::administrator($actor)) {
            $query->where(function ($q) use ($actor) {
                if ($actor->hasPermission('stock.view')) $q->whereIn('carpet.custody_branch_id', Access::branchIds($actor));
                else $q->whereRaw('1=0');
                if ($actor->hasPermission('washing.view')) $q->orWhereExists(fn ($location) => $location->selectRaw('1')->from('stock_locations as washing')
                    ->whereColumn('washing.id', 'carpet.location_id')->whereIn('washing.washing_facility_id', Access::facilityIds($actor)));
            });
        }

        return $query;
    }

    private function withCounts(User $actor): Builder
    {
        $query = $this->locations($actor);
        if ($actor->hasPermission('materials.view')) {
            $tools = DB::query()->fromSub($this->tools($actor), 'tool_stock')->select('location_id')->selectRaw('SUM(quantity) quantity')->groupBy('location_id');
            $query->leftJoinSub($tools, 'tools', 'tools.location_id', '=', 'location.id')->selectRaw('COALESCE(tools.quantity,0) tool_quantity');
        } else $query->selectRaw('NULL tool_quantity');
        if ($actor->hasPermission('stock.view') || $actor->hasPermission('washing.view')) {
            $carpets = $this->carpets($actor)->select('carpet.location_id')->selectRaw('COUNT(*) quantity')->groupBy('carpet.location_id');
            $query->leftJoinSub($carpets, 'carpets', 'carpets.location_id', '=', 'location.id')->selectRaw('COALESCE(carpets.quantity,0) carpet_quantity');
        } else $query->selectRaw('NULL carpet_quantity');

        return $query;
    }

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $query = $this->withCounts($request->user());
        if (! empty($filters['search'])) $query->where(fn ($q) => $q->where('location.name', 'like', '%'.$filters['search'].'%')
            ->orWhere('location.code', 'like', '%'.$filters['search'].'%')->orWhere('branch.name', 'like', '%'.$filters['search'].'%')
            ->orWhere('facility.name', 'like', '%'.$filters['search'].'%'));

        return response()->json($query->orderBy('location.name')->orderBy('location.id')->paginate($filters['per_page'] ?? 15))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id)
    {
        $filters = $this->filters($request); $actor = $request->user();
        $location = $this->withCounts($actor)->where('location.id', $id)->first();
        abort_unless($location, 404);
        $tools = $carpets = null; $size = $filters['per_page'] ?? 15;
        if ($actor->hasPermission('materials.view')) {
            $query = DB::query()->fromSub($this->tools($actor), 'stock')->join('production_tools as tool', 'tool.id', '=', 'stock.tool_id')
                ->where('stock.location_id', $id)->select('tool.id', 'tool.code', 'tool.name')->selectRaw('SUM(stock.quantity) quantity')
                ->groupBy('tool.id', 'tool.code', 'tool.name')->havingRaw('SUM(stock.quantity)>0');
            $tools = $query->orderBy('tool.name')->orderBy('tool.id')->paginate($size, ['*'], 'tools_page');
        }
        if ($actor->hasPermission('stock.view') || $actor->hasPermission('washing.view')) {
            $carpets = $this->carpets($actor)->where('carpet.location_id', $id)
                ->select('carpet.id', 'carpet.carpet_code', 'carpet.name', 'carpet.length_cm', 'carpet.width_cm', 'carpet.stock_status')
                ->orderBy('carpet.carpet_code')->orderBy('carpet.id')->paginate($size, ['*'], 'carpets_page');
        }

        return response()->json(['data' => ['location' => $location, 'tools' => $tools, 'carpets' => $carpets]])
            ->header('Cache-Control', 'private, no-store');
    }
}
