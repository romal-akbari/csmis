<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\DirectoryCatalog;
use App\Services\FoundationAccess as Access;
use App\Services\InventoryRules;
use App\Services\InventoryService as Inventory;
use App\Services\WashingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    private const RESOURCES = [
        'carpets' => ['stock.view', 'stock.create', 'carpet'],
        'purchases' => ['weaver-purchases.view', 'weaver-purchases.create', 'purchase'],
        'receipts' => ['stock.view', 'stock.receive', 'receipt'],
        'transfers' => ['stock.view', 'stock.transfer', 'transfer'],
        'payments' => ['weaver-payments.view', 'weaver-payments.create', 'payment'],
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

    public function lookups(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('weaver-refunds.view') || collect(self::RESOURCES)->contains(fn ($r) => $actor->hasPermission($r[0])), 403);
        $settings = DB::table('company_settings')->find(1);
        $data = [
            'company' => ['base_currency_id' => $settings->base_currency_id, 'timezone' => $settings->timezone],
            'branches' => Inventory::branchScope(DB::table('branches'), $actor, 'id')
                ->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'locations' => Inventory::branchScope(DB::table('stock_locations'), $actor)
                ->where('status', 'active')->whereNotNull('branch_id')->orderBy('name')->get(['id', 'name', 'branch_id']),
            'categories' => DB::table('carpet_categories')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'name_fa']),
            'currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'decimal_places']),
            'weavers' => [], 'accounts' => [], 'expense_categories' => [],
        ];
        if ($actor->hasPermission('weaver-purchases.create') || $actor->hasPermission('weaver-payments.create')) {
            $data['weavers'] = DirectoryCatalog::scope(DB::table('weavers'), 'weavers', $actor)
                ->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        }
        if ($actor->hasPermission('weaver-payments.create') || $actor->hasPermission('weaver-refunds.create')) {
            $data['accounts'] = Access::accounts(DB::table('accounts'), $actor)
                ->join('v_account_balances as b', 'b.account_id', '=', 'accounts.id')
                ->where('accounts.status', 'active')->get(['accounts.id', 'name', 'branch_id', 'accounts.currency_id', 'b.balance']);
            $data['expense_categories'] = DB::table('expense_categories')->where('status', 'active')->get(['id', 'name', 'name_fa']);
        }

        return response()->json(['data' => $data]);
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        [$permission] = $this->resource($resource);
        Access::permit($request->user(), $permission);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
            'branch_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'],
            'stock_status' => ['nullable', 'in:registered,purchased,in_stock,missing,returned,at_washing,washing_completed,sold,production_completed,available_for_purchase'], 'unpaid' => ['nullable', 'boolean'], 'credit' => ['nullable', 'boolean']]);
        $actor = $request->user();
        if ($resource === 'carpets') {
            $query = Inventory::carpets($actor)->leftJoin('stock_locations as l', 'l.id', '=', 's.location_id')
                ->join('branches as b', 'b.id', '=', 's.custody_branch_id')
                ->join('carpet_categories as cat', 'cat.id', '=', 's.category_id')
                ->select('s.*', 'l.name as location_name', 'b.name as branch_name', 'cat.name as category_name');
            foreach (['branch_id' => 's.custody_branch_id', 'location_id' => 's.location_id', 'stock_status' => 's.stock_status'] as $key => $column) {
                if (isset($filters[$key])) {
                    if ($key === 'stock_status' && $filters[$key] === 'available_for_purchase') {
                        $query->whereIn($column, ['registered', 'returned']);
                    } else {
                        $query->where($column, $filters[$key]);
                    }
                }
            }
            if (! empty($filters['search'])) {
                $query->where(fn ($q) => $q->where('s.name', 'like', '%'.$filters['search'].'%')->orWhere('s.carpet_code', 'like', '%'.$filters['search'].'%'));
            }

            return response()->json($query->orderByDesc('s.id')->paginate($filters['per_page'] ?? 15));
        }
        if ($resource === 'purchases') {
            $query = Inventory::branchScope(DB::table('weaver_purchases as p'), $actor, 'p.branch_id')
                ->join('weavers as w', 'w.id', '=', 'p.weaver_id')->join('currencies as c', 'c.id', '=', 'p.currency_id')
                ->join('v_weaver_purchase_balances as b', 'b.purchase_id', '=', 'p.id')
                ->leftJoin('purchase_voids as v', 'v.weaver_purchase_id', '=', 'p.id')
                ->select('p.*', 'w.name as weaver_name', 'c.code as currency_code', 'b.total_amount', 'b.net_amount', 'b.cancelled_amount', 'b.returned_amount', 'b.refunded_amount', 'b.paid_amount', 'b.remaining_amount', 'v.reason as void_reason')
                ->selectRaw("IF(v.id IS NULL,'posted','void') AS state");
            $searchColumn = 'p.purchase_no';
            if (isset($filters['branch_id'])) {
                $query->where('p.branch_id', $filters['branch_id']);
            }
            if (! empty($filters['unpaid'])) {
                $query->where('b.remaining_amount', '>', 0);
            }
            if (! empty($filters['credit'])) {
                $query->where('b.remaining_amount', '<', 0);
            }
        } elseif ($resource === 'payments') {
            $query = Inventory::branchScope(DB::table('weaver_payments as p'), $actor, 'p.branch_id')
                ->join('weavers as w', 'w.id', '=', 'p.weaver_id')->join('account_transactions as t', 't.id', '=', 'p.account_transaction_id')
                ->join('currencies as c', 'c.id', '=', 't.currency_id')
                ->select('p.*', 'w.name as weaver_name', 't.amount', 't.transaction_date as payment_on', 'c.code as currency_code')
                ->selectRaw('EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=t.id) AS reversed');
            $searchColumn = 'p.payment_no';
        } elseif ($resource === 'receipts') {
            $query = Inventory::branchScope(DB::table('stock_receipts as p')->join('stock_locations as l', 'l.id', '=', 'p.location_id'), $actor, 'l.branch_id')
                ->select('p.*', 'l.name as location_name');
            $searchColumn = 'p.receipt_no';
        } else {
            $query = DB::table('stock_transfers as p')->join('stock_locations as f', 'f.id', '=', 'p.from_location_id')
                ->join('stock_locations as t', 't.id', '=', 'p.to_location_id')->select('p.*', 'f.name as from_location_name', 't.name as to_location_name');
            if (! Access::administrator($actor)) {
                $query->where(fn ($q) => $q->whereIn('f.branch_id', Access::branchIds($actor))->orWhereIn('t.branch_id', Access::branchIds($actor)));
            }
            $searchColumn = 'p.transfer_no';
        }
        if (! empty($filters['search'])) {
            $query->where($searchColumn, 'like', '%'.$filters['search'].'%');
        }

        return response()->json($query->orderByDesc('p.id')->paginate($filters['per_page'] ?? 15));
    }

    public function show(Request $request, string $resource, int $id): JsonResponse
    {
        [$permission] = $this->resource($resource);
        if ($resource === 'carpets' && $request->user()->hasPermission('washing.view')) {
            // Facility users may inspect carpet custody/history, but no purchase prices or bills.
        } else {
            Access::permit($request->user(), $permission);
        }
        $actor = $request->user();
        if ($resource === 'carpets') {
            $query = DB::table('v_current_stock as s')->whereNull('s.deleted_at');
            if (! Access::administrator($actor)) {
                $query->where(function ($q) use ($actor) {
                    $q->whereIn('s.custody_branch_id', Access::branchIds($actor));
                    if ($actor->hasPermission('washing.view')) {
                        $q->orWhereExists(fn ($l) => $l->selectRaw('1')->from('stock_locations as fl')->whereColumn('fl.id', 's.location_id')
                            ->whereIn('fl.washing_facility_id', Access::facilityIds($actor)));
                    }
                });
            }
            $row = $query->leftJoin('stock_locations as l', 'l.id', '=', 's.location_id')
                ->join('branches as b', 'b.id', '=', 's.custody_branch_id')->where('s.id', $id)
                ->first(['s.*', 'l.name as location_name', 'b.name as branch_name']);
            abort_unless($row, 404);
            $row->events = DB::table('stock_events as e')->leftJoin('stock_locations as l', 'l.id', '=', 'e.resulting_location_id')
                ->join('users as u', 'u.id', '=', 'e.handled_by')->where('e.carpet_id', $id)->orderBy('sequence_no')
                ->get(['e.*', 'l.name as location_name', 'u.name as handled_by_name']);
            $row->images = DB::table('carpet_images')->where('carpet_id', $id)->orderBy('id')->get(['id', 'alt_text']);
            $row->washing_visits = WashingService::visits($actor)->where('i.carpet_id', $id)->orderBy('i.id')->get();
            if ($actor->hasPermission('washing-costs.view')) {
                $row->washing_costs = WashingService::carpetCosts($actor, $id);
            }
        } elseif ($resource === 'purchases') {
            $row = Inventory::branchScope(DB::table('weaver_purchases'), $actor)->where('id', $id)->first();
            abort_unless($row, 404);
            $row->items = DB::table('weaver_purchase_items as i')->join('carpets as c', 'c.id', '=', 'i.carpet_id')
                ->leftJoin('v_current_stock as s', function ($join) use ($actor) {
                    $join->on('s.id', '=', 'i.carpet_id');
                    if (! Access::administrator($actor)) {
                        $join->whereIn('s.custody_branch_id', Access::branchIds($actor));
                    }
                })->where('weaver_purchase_id', $id)->get(['i.*', 'c.carpet_code', 'c.name', 's.sequence_no', 's.stock_status', 's.location_id', 's.acquisition_item_id']);
            $row->balance = DB::table('v_weaver_purchase_balances')->where('purchase_id', $id)->first();
            $row->void = DB::table('purchase_voids')->where('weaver_purchase_id', $id)->first();
        } elseif ($resource === 'payments') {
            $row = Inventory::branchScope(DB::table('weaver_payments'), $actor)->where('id', $id)->first();
            abort_unless($row, 404);
            $row->allocations = DB::table('weaver_payment_allocations as a')->join('weaver_purchases as p', 'p.id', '=', 'a.weaver_purchase_id')
                ->where('weaver_payment_id', $id)->get(['a.*', 'p.purchase_no']);
        } else {
            $query = DB::table($resource === 'receipts' ? 'stock_receipts' : 'stock_transfers')->where('id', $id);
            $row = $query->first();
            abort_unless($row, 404);
            $locations = [$row->location_id ?? $row->from_location_id, $row->location_id ?? $row->to_location_id];
            abort_unless(Inventory::branchScope(DB::table('stock_locations'), $actor)->whereIn('id', $locations)->exists(), 404);
            $row->items = DB::table('stock_events as e')->join('carpets as c', 'c.id', '=', 'e.carpet_id')
                ->where($resource === 'receipts' ? 'stock_receipt_id' : 'stock_transfer_id', $id)->get(['c.id', 'c.carpet_code', 'c.name', 'e.sequence_no']);
        }

        return response()->json(['data' => $row]);
    }

    public function store(Request $request, string $resource, Inventory $inventory): JsonResponse
    {
        [, $permission, $operation] = $this->resource($resource);
        Access::permit($request->user(), $permission);
        $data = $request->validate(InventoryRules::for($operation));
        $actor = $request->user();
        $key = $this->key($request);
        $result = $inventory->command($actor, $operation, $data, $key, fn ($settings) => match ($operation) {
            'carpet' => $inventory->carpet($actor, $data),
            'purchase' => $inventory->purchase($actor, $data, $settings),
            'receipt', 'transfer' => $inventory->move($actor, $data, $operation === 'transfer'),
            'payment' => $inventory->payment($actor, $data, $key),
        });

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    public function updateCarpet(Request $request, int $id, Inventory $inventory): JsonResponse
    {
        Access::permit($request->user(), 'stock.update');
        $data = $request->validate(InventoryRules::for('edit-carpet'));
        $result = $inventory->command($request->user(), 'edit-carpet:'.$id, $data, $this->key($request),
            fn () => $inventory->carpet($request->user(), $data, $id));

        return response()->json($result);
    }

    public function upload(Request $request, int $id, Inventory $inventory): JsonResponse
    {
        Access::permit($request->user(), 'stock.update');
        $data = $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
            'alt_text' => ['nullable', 'string', 'max:255']]);
        $file = $data['image'];
        $payload = ['sha256' => hash_file('sha256', $file->getRealPath()), 'alt_text' => $data['alt_text'] ?? null];
        $path = null;
        try {
            $result = $inventory->command($request->user(), 'image:'.$id, $payload, $this->key($request), function () use ($request, $id, $file, $payload, &$path) {
                abort_unless(Inventory::carpets($request->user())->where('s.id', $id)->exists(), 404);
                if (DB::table('carpet_images')->where('carpet_id', $id)->count() >= 8) {
                    Inventory::fail('image', 'A carpet can have at most eight images.');
                }
                $extension = match ($file->getMimeType()) {
                    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => abort(422)
                };
                $path = 'carpets/'.Str::uuid().'.'.$extension;
                if (! Storage::disk('local')->put($path, file_get_contents($file->getRealPath()))) {
                    abort(500, 'Could not store the image.');
                }
                $imageId = DB::table('carpet_images')->insertGetId(['carpet_id' => $id, 'storage_path' => $path, 'mime_type' => $file->getMimeType(),
                    'alt_text' => $payload['alt_text'], 'created_by' => $request->user()->id, 'created_at' => now()]);
                AuditLogger::log('create', 'carpet_images', $imageId, null, ['carpet_id' => $id, 'alt_text' => $payload['alt_text']]);

                return ['id' => $imageId, 'alt_text' => $payload['alt_text']];
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    public function image(Request $request, int $id, int $imageId)
    {
        Access::permit($request->user(), 'stock.view');
        abort_unless(Inventory::carpets($request->user())->where('s.id', $id)->exists(), 404);
        $row = DB::table('carpet_images')->where('carpet_id', $id)->where('id', $imageId)->first();
        abort_unless($row && Storage::disk('local')->exists($row->storage_path), 404);

        return response()->file(Storage::disk('local')->path($row->storage_path), ['Content-Type' => $row->mime_type,
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function deleteImage(Request $request, int $id, int $imageId, Inventory $inventory): JsonResponse
    {
        Access::permit($request->user(), 'stock.update');
        $result = $inventory->command($request->user(), 'delete-image:'.$id.':'.$imageId, [], $this->key($request), function () use ($request, $id, $imageId) {
            abort_unless(Inventory::carpets($request->user())->where('s.id', $id)->exists(), 404);
            $row = DB::table('carpet_images')->where('carpet_id', $id)->where('id', $imageId)->first();
            abort_unless($row, 404);
            DB::table('carpet_images')->where('id', $imageId)->delete();
            AuditLogger::log('delete', 'carpet_images', $imageId, ['carpet_id' => $id, 'alt_text' => $row->alt_text], null);
            // Remove physical file only AFTER commit; a rollback must retain the original.
            DB::afterCommit(fn () => Storage::disk('local')->delete($row->storage_path));

            return ['id' => $imageId];
        });

        return response()->json($result);
    }
}
