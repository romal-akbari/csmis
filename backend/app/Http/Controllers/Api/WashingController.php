<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FoundationAccess as Access;
use App\Services\InventoryService as Inventory;
use App\Services\WashingRules;
use App\Services\WashingService as Washing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WashingController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate(['search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
            'branch_id' => ['nullable', 'integer', 'min:1'], 'facility_id' => ['nullable', 'integer', 'min:1'],
            'stock_status' => ['nullable', 'in:at_washing,washing_completed'],
            'state' => ['nullable', 'in:sent,partially_completed,completed,partially_returned,returned,posted,void'],
            'unpaid' => ['nullable', 'boolean'], 'unbilled' => ['nullable', 'boolean'], 'format' => ['nullable', 'in:csv']]);
    }

    private function common($query, array $filters, string $alias): void
    {
        foreach (['branch_id', 'facility_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($alias.'.'.$column, $filters[$column]);
            }
        }
    }

    public function lookups(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('washing.view') || $actor->hasPermission('washing-costs.view'), 403);
        $settings = DB::table('company_settings')->find(1);
        $facilities = DB::table('washing_facilities')->whereNull('deleted_at')->where('status', 'active');
        if (! Access::administrator($actor) && ! Access::branchIds($actor)) {
            $facilities->whereIn('id', Access::facilityIds($actor));
        }
        $facilityRows = $facilities->orderBy('name')->get(['id', 'name']);
        $data = ['company' => ['base_currency_id' => $settings->base_currency_id, 'timezone' => $settings->timezone],
            'branches' => Inventory::branchScope(DB::table('branches'), $actor, 'id')->whereNull('deleted_at')->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'facilities' => $facilityRows,
            'facility_locations' => DB::table('stock_locations')->whereIn('washing_facility_id', $facilityRows->pluck('id'))->where('status', 'active')->orderBy('name')->get(['id', 'name', 'washing_facility_id']),
            'locations' => Inventory::branchScope(DB::table('stock_locations'), $actor)->whereNotNull('branch_id')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'branch_id']),
            'currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'decimal_places']), 'accounts' => []];
        if ($actor->hasPermission('washing-costs.pay')) {
            $data['accounts'] = Access::accounts(DB::table('accounts as a'), $actor, 'a.id')
                ->join('v_account_balances as b', 'b.account_id', '=', 'a.id')->where('a.status', 'active')
                ->get(['a.id', 'a.name', 'a.branch_id', 'a.currency_id', 'b.balance']);
        }

        return response()->json(['data' => $data]);
    }

    public function eligible(Request $request): JsonResponse
    {
        Access::permit($request->user(), 'washing.send');
        $filters = $this->filters($request);
        $query = Inventory::carpets($request->user())->whereIn('s.stock_status', ['purchased', 'in_stock', 'production_completed'])
            ->leftJoin('stock_locations as l', 'l.id', '=', 's.location_id')
            ->leftJoin('weaver_purchase_items as pi', 'pi.id', '=', 's.acquisition_item_id')
            ->leftJoin('weaver_purchases as p', 'p.id', '=', 'pi.weaver_purchase_id')->leftJoin('weavers as w', 'w.id', '=', 'p.weaver_id')
            ->leftJoin('manager_production_orders as mo', 'mo.id', '=', 's.manager_production_order_id')
            ->leftJoin('production_managers as pm', 'pm.id', '=', 'mo.manager_id')
            ->leftJoin('production_orders as po', 'po.id', '=', 's.production_order_id')
            ->leftJoin('weavers as pw', 'pw.id', '=', 'po.weaver_id')
            ->select('s.id', 's.carpet_code', 's.name', 's.sequence_no', 's.stock_status', 's.location_id', 's.custody_branch_id', 'l.name as location_name')
            ->selectRaw('COALESCE(p.purchase_no,mo.order_no,po.order_no) purchase_no,COALESCE(w.name,pm.name,pw.name) weaver_name');
        if (isset($filters['branch_id'])) {
            $query->where('s.custody_branch_id', $filters['branch_id']);
        }
        if (! empty($filters['search'])) {
            $query->where(fn ($q) => $q->where('s.carpet_code', 'like', '%'.$filters['search'].'%')->orWhere('s.name', 'like', '%'.$filters['search'].'%'));
        }

        return response()->json($query->orderByDesc('s.id')->paginate($filters['per_page'] ?? 15));
    }

    public function orders(Request $request): JsonResponse
    {
        Access::permit($request->user(), 'washing.view');
        $filters = $this->filters($request);
        $query = Washing::orders($request->user());
        $this->common($query, $filters, 'o');
        if (! empty($filters['search'])) {
            $query->where(fn ($q) => $q->where('o.order_no', 'like', '%'.$filters['search'].'%')->orWhere('f.name', 'like', '%'.$filters['search'].'%'));
        }
        $query = DB::query()->fromSub($query, 'q')->select('q.*')->selectRaw("CASE WHEN returned_count=item_count THEN 'returned' WHEN returned_count>0 THEN 'partially_returned' WHEN completed_count=item_count THEN 'completed' WHEN completed_count>0 THEN 'partially_completed' ELSE 'sent' END AS state");
        if (isset($filters['state'])) {
            $query->having('state', $filters['state']);
        }

        return response()->json($query->orderByDesc('q.id')->paginate($filters['per_page'] ?? 15));
    }

    public function order(Request $request, int $id): JsonResponse
    {
        Access::permit($request->user(), 'washing.view');
        $row = Washing::orders($request->user())->where('o.id', $id)->first();
        abort_unless($row, 404);
        Washing::orderState($row);
        $actor = $request->user();
        $row->items = Washing::visits($actor)->where('o.id', $id)
            // Historical visit access does not grant visibility of later, unrelated custody.
            ->leftJoin('v_current_stock as s', function ($join) use ($actor) {
                $join->on('s.id', '=', 'i.carpet_id');
                if (! Access::administrator($actor)) {
                    $join->where(function ($query) use ($actor) {
                        $query->whereIn('s.custody_branch_id', Access::branchIds($actor))
                            ->orWhereExists(fn ($locations) => $locations->selectRaw('1')->from('stock_locations as visible_location')
                                ->whereColumn('visible_location.id', 's.location_id')
                                ->whereIn('visible_location.washing_facility_id', Access::facilityIds($actor)));
                    });
                }
            })
            ->leftJoin('weaver_purchase_items as pi', 'pi.id', '=', 'i.acquisition_item_id')
            ->leftJoin('weaver_purchases as p', 'p.id', '=', 'pi.weaver_purchase_id')->leftJoin('weavers as w', 'w.id', '=', 'p.weaver_id')
            ->leftJoin('manager_delivery_items as mi', 'mi.carpet_id', '=', 'i.carpet_id')
            ->leftJoin('manager_production_orders as mo', 'mo.id', '=', 'mi.production_order_id')
            ->leftJoin('production_managers as pm', 'pm.id', '=', 'mo.manager_id')
            ->leftJoin('production_carpets as pc', 'pc.carpet_id', '=', 'i.carpet_id')
            ->leftJoin('production_orders as po', 'po.id', '=', 'pc.production_order_id')
            ->leftJoin('weavers as pw', 'pw.id', '=', 'po.weaver_id')
            ->leftJoin('stock_locations as cl', 'cl.id', '=', 's.location_id')
            ->addSelect('s.sequence_no as current_sequence', 's.stock_status', 's.location_id', 'cl.name as location_name')
            ->selectRaw('COALESCE(p.purchase_no,mo.order_no,po.order_no) purchase_no,COALESCE(w.name,pm.name,pw.name) weaver_name')
            ->orderBy('i.id')->get();

        return response()->json(['data' => $row]);
    }

    public function stock(Request $request)
    {
        Access::permit($request->user(), 'washing.view');
        $filters = $this->filters($request);
        $query = Washing::scope(DB::table('v_current_stock as s')->join('stock_events as e', fn ($j) => $j->on('e.carpet_id', '=', 's.id')->on('e.sequence_no', '=', 's.sequence_no'))
            ->join('washing_order_items as i', 'i.id', '=', 'e.washing_order_item_id')->join('washing_orders as o', 'o.id', '=', 'i.washing_order_id'), $request->user())
            ->join('washing_facilities as f', 'f.id', '=', 'o.facility_id')->join('branches as b', 'b.id', '=', 'o.branch_id')
            ->join('stock_locations as l', 'l.id', '=', 's.location_id')
            ->leftJoin('stock_events as ce', fn ($j) => $j->on('ce.washing_order_item_id', '=', 'i.id')->where('ce.event_type', 'washing_complete'))
            ->whereNull('s.deleted_at')->whereIn('s.stock_status', ['at_washing', 'washing_completed'])
            ->select('s.id', 's.carpet_code', 's.name', 's.sequence_no', 's.stock_status', 's.location_id', 'l.name as location_name',
                's.custody_branch_id', 'b.name as branch_name', 'o.facility_id', 'f.name as facility_name', 'o.id as washing_order_id',
                'i.id as washing_order_item_id', 'o.order_no', 'o.sent_on', 'ce.occurred_on as completed_on');
        $this->common($query, $filters, 'o');
        if (isset($filters['stock_status'])) {
            $query->where('s.stock_status', $filters['stock_status']);
        }
        if (! empty($filters['search'])) {
            $query->where(fn ($q) => $q->where('s.carpet_code', 'like', '%'.$filters['search'].'%')->orWhere('s.name', 'like', '%'.$filters['search'].'%')->orWhere('o.order_no', 'like', '%'.$filters['search'].'%'));
        }
        $query->orderByDesc('s.id');
        if (($filters['format'] ?? '') === 'csv') {
            Access::permit($request->user(), 'washing.export');

            return response()->streamDownload(function () use ($query) {
                $stream = fopen('php://output', 'w');
                fputcsv($stream, ['Carpet code', 'Name', 'Branch', 'Facility', 'Location', 'Batch', 'Sent on', 'Completed on', 'Status']);
                foreach ($query->cursor() as $row) {
                    $values = [$row->carpet_code, $row->name, $row->branch_name, $row->facility_name, $row->location_name, $row->order_no, $row->sent_on, $row->completed_on, $row->stock_status];
                    fputcsv($stream, array_map(fn ($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'".$value : $value, $values));
                }
                fclose($stream);
            }, 'washing-facility-stock.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }

        return response()->json($query->paginate($filters['per_page'] ?? 15));
    }

    public function visits(Request $request): JsonResponse
    {
        Access::permit($request->user(), 'washing-costs.view');
        $filters = $this->filters($request);
        // Financial selection is branch-scoped even when the actor also has a facility assignment.
        $query = Washing::visits($request->user());
        Inventory::branchScope($query, $request->user(), 'o.branch_id');
        $this->common($query, $filters, 'o');
        if (! empty($filters['unbilled'])) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('washing_bill_allocations as a')->whereColumn('a.washing_order_item_id', 'i.id')
                ->whereNotExists(fn ($v) => $v->selectRaw('1')->from('washing_bill_voids as v')->whereColumn('v.washing_bill_id', 'a.washing_bill_id')));
        }
        if (! empty($filters['search'])) {
            $query->where(fn ($q) => $q->where('c.carpet_code', 'like', '%'.$filters['search'].'%')->orWhere('o.order_no', 'like', '%'.$filters['search'].'%'));
        }

        return response()->json($query->orderByDesc('i.id')->paginate($filters['per_page'] ?? 15));
    }

    public function bills(Request $request): JsonResponse
    {
        Access::permit($request->user(), 'washing-costs.view');
        $filters = $this->filters($request);
        $query = Washing::bills($request->user());
        $this->common($query, $filters, 'p');
        if (! empty($filters['search'])) {
            $query->where(fn ($q) => $q->where('p.expense_no', 'like', '%'.$filters['search'].'%')->orWhere('p.invoice_no', 'like', '%'.$filters['search'].'%')->orWhere('f.name', 'like', '%'.$filters['search'].'%'));
        }
        if (! empty($filters['unpaid'])) {
            $query->where('bal.remaining_amount', '>', 0);
        }
        if (isset($filters['state'])) {
            $query->where('bal.state', $filters['state']);
        }

        return response()->json($query->orderByDesc('p.id')->paginate($filters['per_page'] ?? 15));
    }

    public function bill(Request $request, int $id): JsonResponse
    {
        Access::permit($request->user(), 'washing-costs.view');
        $row = Washing::bills($request->user())->where('p.id', $id)->first();
        abort_unless($row, 404);
        $row->allocations = DB::table('washing_bill_allocations as a')->join('washing_order_items as i', 'i.id', '=', 'a.washing_order_item_id')
            ->join('washing_orders as o', 'o.id', '=', 'i.washing_order_id')->join('carpets as c', 'c.id', '=', 'i.carpet_id')->where('a.washing_bill_id', $id)
            ->get(['a.*', 'c.carpet_code', 'c.name as carpet_name', 'o.order_no', 'o.sent_on']);
        $row->payments = DB::table('washing_payments as w')->join('account_transactions as t', 't.id', '=', 'w.account_transaction_id')
            ->join('accounts as a', 'a.id', '=', 't.account_id')->join('currencies as c', 'c.id', '=', 't.currency_id')->where('w.washing_bill_id', $id)
            ->select('w.*', 't.account_id', 'a.name as account_name', 't.amount', 'c.code as currency_code', 't.transaction_date as payment_on')
            ->selectRaw('EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=t.id) AS reversed')->orderBy('w.id')->get();
        $row->void = DB::table('washing_bill_voids')->where('washing_bill_id', $id)->first();

        return response()->json(['data' => $row]);
    }

    public function command(Request $request, Inventory $inventory, Washing $washing): JsonResponse
    {
        $operation = $request->route('operation');
        $id = $request->route('id') ? (int) $request->route('id') : null;
        $permission = ['dispatch' => 'washing.send', 'complete' => 'washing.complete', 'return' => 'washing.return',
            'bill' => 'washing-costs.create', 'payment' => 'washing-costs.pay', 'void' => 'washing-costs.void', 'reverse' => 'washing-costs.reverse'][$operation] ?? abort(404);
        Access::permit($request->user(), $permission);
        $data = $request->validate(WashingRules::for($operation));
        $key = validator(['idempotency_key' => $request->header('Idempotency-Key')], [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[a-zA-Z0-9_.:-]+$/'],
        ])->validate()['idempotency_key'];
        $actor = $request->user();
        $result = $inventory->command($actor, 'washing:'.$operation.':'.($id ?? 'new'), $data, $key, fn ($settings) => match ($operation) {
            'dispatch' => $washing->dispatch($actor, $data),
            'complete', 'return' => $washing->progress($actor, $id, $data, $operation === 'return'),
            'bill' => $washing->bill($actor, $data, $settings),
            'payment' => $washing->payment($actor, $id, $data, $key),
            'void' => $washing->void($actor, $id, $data),
            'reverse' => $washing->reverse($actor, $id, $data, $key),
        });

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }
}
