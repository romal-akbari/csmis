<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FoundationAccess as Access;
use App\Services\InventoryCorrectionRules as Rules;
use App\Services\InventoryCorrectionService as Corrections;
use App\Services\InventoryService as Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryCorrectionController extends Controller
{
    private const RESOURCES = [
        'drafts' => ['purchase_drafts', 'weaver-purchases.view', 'weaver-purchases.draft'],
        'adjustments' => ['stock_adjustments', 'stock.view', 'stock.adjust'],
        'returns' => ['weaver_returns', 'weaver-purchases.view', 'weaver-purchases.return'],
        'refunds' => ['weaver_refunds', 'weaver-refunds.view', 'weaver-refunds.create'],
    ];

    private function resource(string $resource): array
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404);

        return self::RESOURCES[$resource];
    }

    private function key(Request $request): string
    {
        return validator(['idempotency_key' => $request->header('Idempotency-Key')], [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[a-zA-Z0-9_.:-]+$/'],
        ])->validate()['idempotency_key'];
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        [$table, $permission] = $this->resource($resource);
        Access::permit($request->user(), $permission);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'], 'state' => ['nullable', 'in:all,draft,posted,cancelled']]);
        $query = DB::table($table.' as x');
        if (in_array($resource, ['returns', 'refunds'], true)) {
            $query->join('weaver_purchases as p', 'p.id', '=', 'x.weaver_purchase_id')->join('weavers as w', 'w.id', '=', 'p.weaver_id')
                ->join('currencies as c', 'c.id', '=', 'p.currency_id')->select('x.*', 'p.purchase_no', 'p.branch_id', 'w.name as weaver_name', 'c.code as purchase_currency');
            Inventory::branchScope($query, $request->user(), 'p.branch_id');
            if ($resource === 'refunds') {
                $query->join('account_transactions as t', 't.id', '=', 'x.account_transaction_id')
                    ->join('currencies as pc', 'pc.id', '=', 't.currency_id')->addSelect('t.amount', 't.transaction_date as refunded_on', 'pc.code as currency_code')
                    ->selectRaw('EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=t.id) AS reversed');
            } else {
                $query->selectRaw('(SELECT SUM(i.unit_price) FROM weaver_return_items ri JOIN weaver_purchase_items i ON i.id=ri.weaver_purchase_item_id WHERE ri.weaver_return_id=x.id) AS returned_amount');
            }
        } else {
            Inventory::branchScope($query, $request->user(), 'x.branch_id')->select('x.*');
            if ($resource === 'drafts' && ! empty($filters['state']) && $filters['state'] !== 'all') {
                $query->where('x.state', $filters['state']);
            }
        }
        $number = match ($resource) {
            'drafts' => 'purchase_no', 'returns' => 'return_no', 'adjustments' => 'adjustment_no', 'refunds' => 'refund_no'
        };
        if (! empty($filters['search'])) {
            $query->where('x.'.$number, 'like', '%'.$filters['search'].'%');
        }

        return response()->json($query->orderByDesc('x.id')->paginate($filters['per_page'] ?? 15));
    }

    public function show(Request $request, string $resource, int $id): JsonResponse
    {
        [$table, $permission] = $this->resource($resource);
        Access::permit($request->user(), $permission);
        $row = DB::table($table)->where('id', $id)->first();
        abort_unless($row, 404);
        if (isset($row->weaver_purchase_id)) {
            $purchase = Corrections::purchase($request->user(), $row->weaver_purchase_id);
            $row->purchase_no = $purchase->purchase_no;
            $row->weaver_name = DB::table('weavers')->where('id', $purchase->weaver_id)->value('name');
            $row->purchase_currency = DB::table('currencies')->where('id', $purchase->currency_id)->value('code');
        } else {
            abort_unless(Inventory::branchScope(DB::table($table), $request->user())->where('id', $id)->exists(), 404);
        }
        if ($resource === 'drafts') {
            $row->weaver_name = $row->weaver_id ? DB::table('weavers')->where('id', $row->weaver_id)->value('name') : null;
            $row->currency_code = $row->currency_id ? DB::table('currencies')->where('id', $row->currency_id)->value('code') : null;
            $row->items = DB::table('purchase_draft_items as i')->join('carpets as c', 'c.id', '=', 'i.carpet_id')->where('purchase_draft_id', $id)->get(['i.*', 'c.carpet_code', 'c.name']);
        }
        if ($resource === 'returns') {
            $row->items = DB::table('weaver_return_items as r')->join('weaver_purchase_items as i', 'i.id', '=', 'r.weaver_purchase_item_id')
                ->join('carpets as c', 'c.id', '=', 'i.carpet_id')->where('weaver_return_id', $id)->get(['i.carpet_id', 'i.unit_price', 'c.carpet_code', 'c.name']);
        }
        if ($resource === 'adjustments') {
            $row->items = DB::table('stock_events as e')->join('carpets as c', 'c.id', '=', 'e.carpet_id')->where('stock_adjustment_id', $id)->get(['e.carpet_id', 'e.sequence_no', 'e.resulting_status', 'c.carpet_code', 'c.name']);
        }
        if ($resource === 'refunds') {
            $entry = DB::table('account_transactions')->find($row->account_transaction_id);
            $row->amount = $entry->amount;
            $row->refunded_on = $entry->transaction_date;
            $row->currency_code = DB::table('currencies')->where('id', $entry->currency_id)->value('code');
        }

        return response()->json(['data' => $row]);
    }

    public function store(Request $request, string $resource, Corrections $service, Inventory $inventory): JsonResponse
    {
        [, , $permission] = $this->resource($resource);
        Access::permit($request->user(), $permission);
        $data = $request->validate(Rules::rules($resource));
        $key = $this->key($request);
        $result = $inventory->command($request->user(), 'correction-'.$resource, $data, $key, fn () => match ($resource) {
            'drafts' => $service->draft($request->user(), $data), 'adjustments' => $service->adjust($request->user(), $data),
            'returns' => $service->return($request->user(), $data), 'refunds' => $service->refund($request->user(), $data, $key),
        });

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    public function updateDraft(Request $request, int $id, Corrections $service, Inventory $inventory): JsonResponse
    {
        Access::permit($request->user(), 'weaver-purchases.draft');
        $data = $request->validate(Rules::rules('drafts', true));

        return response()->json($inventory->command($request->user(), 'edit-draft:'.$id, $data, $this->key($request), fn () => $service->draft($request->user(), $data, $id)));
    }

    public function finalizeDraft(Request $request, int $id, string $action, Corrections $service, Inventory $inventory): JsonResponse
    {
        abort_unless(in_array($action, ['post', 'cancel'], true), 404);
        Access::permit($request->user(), 'weaver-purchases.draft');
        if ($action === 'post') {
            Access::permit($request->user(), 'weaver-purchases.create');
        }
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => [$action === 'cancel' ? 'required' : 'prohibited', 'string', 'min:3', 'max:500'],
            'location_id' => [$action === 'post' ? 'nullable' : 'prohibited', 'integer', 'min:1']]);
        $result = $inventory->command($request->user(), $action.'-draft:'.$id, $data, $this->key($request), fn ($settings) => $service->finalizeDraft($request->user(), $id, $data, $settings, $action === 'post'));

        return response()->json($result);
    }

    public function voidPurchase(Request $request, int $id, Corrections $service, Inventory $inventory): JsonResponse
    {
        Access::permit($request->user(), 'weaver-purchases.cancel');
        $data = $request->validate(Rules::rules('void'));
        $result = $inventory->command($request->user(), 'void-purchase:'.$id, $data, $this->key($request), fn () => $service->void($request->user(), $id, $data));

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }
}
