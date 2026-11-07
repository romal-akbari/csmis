<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BusinessPurchasing;
use App\Services\BusinessRules;
use App\Services\BusinessSupport;
use App\Services\DirectoryCatalog;
use App\Services\FoundationAccess as Access;
use App\Services\FoundationCatalog;
use App\Services\InventoryService as Inventory;
use App\Services\LedgerService;
use App\Services\ProductionService;
use App\Services\SalesStockGroups;
use App\Services\TradeService;
use App\Services\WeaverStatement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BusinessController extends Controller
{
    private const MODULES = ['purchases' => 'purchasing', 'supplier-payments' => 'purchasing',
        'expenses' => 'expenses', 'materials' => 'materials', 'material-receipts' => 'materials',
        'production' => 'production', 'partnerships' => 'partnerships', 'sales' => 'sales', 'customer-receipts' => 'sales'];

    private function permit(Request $request, string $resource, string $action = 'view'): string
    {
        abort_unless(isset(self::MODULES[$resource]), 404);
        $module = self::MODULES[$resource];
        Access::permit($request->user(), $module.'.'.$action);

        return $module;
    }

    public function weaver(Request $request, int $id)
    {
        return response()->json(['data' => app(WeaverStatement::class)->read($request->user(), $id)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function lookups(Request $request)
    {
        $actor = $request->user();
        abort_unless(collect(self::MODULES)->contains(fn ($module) => $actor->hasPermission($module.'.view')), 403);
        $settings = DB::table('company_settings')->find(1);
        $data = ['company' => ['base_currency_id' => $settings->base_currency_id, 'timezone' => $settings->timezone],
            'branches' => Inventory::branchScope(DB::table('branches'), $actor, 'id')->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'locations' => Inventory::branchScope(DB::table('stock_locations'), $actor)->where('status', 'active')->whereNotNull('branch_id')->orderBy('name')->get(['id', 'name', 'branch_id']),
            'currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'decimal_places']),
            'accounts' => Access::accounts(DB::table('accounts as a'), $actor, 'a.id')->join('v_account_balances as b', 'b.account_id', '=', 'a.id')
                ->where('a.status', 'active')->orderBy('a.name')->get(['a.id', 'a.name', 'a.currency_id', 'b.balance']),
            'weavers' => DirectoryCatalog::scope(DB::table('weavers'), 'weavers', $actor)->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'suppliers' => DB::table('suppliers')->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'expense_categories' => DB::table('expense_categories')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'name_fa']),
            'materials' => DB::table('materials')->where('status', 'active')->orderBy('name')->get(),
            'carpets' => [], 'stock_groups' => [], 'customers' => [], 'lots' => []];
        if ($actor->hasPermission('stock.view')) {
            $data['carpets'] = Inventory::carpets($actor)->leftJoin('stock_locations as l', 'l.id', '=', 's.location_id')
                ->whereIn('s.stock_status', ['registered', 'returned', 'in_stock'])->orderBy('s.carpet_code')
                ->get(['s.id', 's.name', 's.carpet_code', 's.custody_branch_id', 's.stock_status', 's.sequence_no', 's.production_order_id', 's.acquisition_item_id', 's.business_model', 'l.name as location_name']);
        }
        if ($actor->hasPermission('sales.view')) {
            $data['customers'] = DB::table('customers')->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
            if ($actor->hasPermission('stock.view')) {
                $data['stock_groups'] = SalesStockGroups::lookups($actor);
            }
        }
        if ($actor->hasPermission('materials.view')) {
            $data['lots'] = Inventory::branchScope(DB::table('material_lots as l'), $actor, 'l.branch_id')->join('materials as m', 'm.id', '=', 'l.material_id')
                ->orderByDesc('l.id')->get(['l.id', 'l.lot_no', 'l.branch_id', 'm.name', 'm.unit'])->map(function ($lot) {
                    $lot->warehouse_quantity = ProductionService::warehouse($lot->id);

                    return $lot;
                });
        }
        if (! $actor->hasPermission('accounts.view') || ! $actor->hasPermission('ledger.view')) {
            $data['accounts']->transform(function ($account) {
                unset($account->balance);

                return $account;
            });
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, string $resource)
    {
        $this->permit($request, $resource);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'], 'branch_id' => ['nullable', 'integer']]);
        $actor = $request->user();
        $column = 'p.id';
        if ($resource === 'purchases') {
            $query = Inventory::branchScope(DB::table('weaver_purchases as p'), $actor, 'p.branch_id')
                ->leftJoin('weavers as w', 'w.id', '=', 'p.weaver_id')->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
                ->join('currencies as c', 'c.id', '=', 'p.currency_id')->join('v_weaver_purchase_balances as b', 'b.purchase_id', '=', 'p.id')
                ->leftJoin('purchase_voids as v', 'v.weaver_purchase_id', '=', 'p.id')
                ->select('p.*', 'c.code as currency_code', 'b.total_amount', 'b.remaining_amount', 'b.paid_amount', 'b.returned_amount', 'b.refunded_amount')
                ->selectRaw("COALESCE(w.name,s.name) seller_name,IF(v.id IS NULL,'posted','void') state");
            $column = 'p.purchase_no';
        } elseif ($resource === 'expenses') {
            $query = Inventory::branchScope(DB::table('operating_expenses as p'), $actor, 'p.branch_id')
                ->join('expense_categories as cat', 'cat.id', '=', 'p.expense_category_id')->join('currencies as c', 'c.id', '=', 'p.currency_id')
                ->select('p.*', 'cat.name as category_name', 'c.code as currency_code');
            $column = 'p.expense_no';
        } elseif ($resource === 'materials') {
            $query = DB::table('materials as p')->select('p.*');
            $column = 'p.name';
        } elseif ($resource === 'material-receipts') {
            $query = Inventory::branchScope(DB::table('material_lots as p'), $actor, 'p.branch_id')
                ->join('materials as m', 'm.id', '=', 'p.material_id')->join('stock_locations as l', 'l.id', '=', 'p.location_id')
                ->join('currencies as c', 'c.id', '=', 'p.currency_id')->select('p.*', 'm.name as material_name', 'm.unit', 'l.name as location_name', 'c.code as currency_code');
            $column = 'p.lot_no';
        } elseif (in_array($resource, ['production', 'partnerships'], true)) {
            $query = Inventory::branchScope(DB::table('production_orders as p'), $actor, 'p.branch_id')->join('weavers as w', 'w.id', '=', 'p.weaver_id')
                ->join('currencies as c', 'c.id', '=', 'p.currency_id')->where('p.model', $resource === 'partnerships' ? 'partnership' : 'company_funded')
                ->select('p.*', 'w.name as weaver_name', 'c.code as currency_code');
            $column = 'p.order_no';
        } elseif ($resource === 'sales') {
            $query = Inventory::branchScope(DB::table('trade_sales as p'), $actor, 'p.branch_id')->join('customers as c', 'c.id', '=', 'p.customer_id')
                ->join('currencies as cur', 'cur.id', '=', 'p.currency_id')->select('p.*', 'c.name as customer_name', 'cur.code as currency_code');
            $column = 'p.sale_no';
        } else {
            $supplier = $resource === 'supplier-payments';
            $query = DB::table(($supplier ? 'supplier_carpet_payments' : 'trade_customer_receipts').' as p')
                ->join(($supplier ? 'weaver_purchases' : 'trade_sales').' as d', 'd.id', '=', $supplier ? 'p.purchase_id' : 'p.sale_id')
                ->join('account_transactions as t', 't.id', '=', 'p.account_transaction_id')->join('accounts as a', 'a.id', '=', 't.account_id')
                ->join('currencies as c', 'c.id', '=', 't.currency_id');
            Inventory::branchScope($query, $actor, 'd.branch_id');
            $query->select('p.*', 't.transaction_date', 't.amount', 'a.name as account_name', 'c.code as currency_code')
                ->selectRaw('EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=t.id) reversed');
        }
        if (! empty($filters['search'])) {
            $query->where($column, 'like', '%'.$filters['search'].'%');
        }
        if (! empty($filters['branch_id']) && ! in_array($resource, ['materials', 'supplier-payments', 'customer-receipts'], true)) {
            $query->where('p.branch_id', $filters['branch_id']);
        }
        $page = $query->orderByDesc('p.id')->paginate($filters['per_page'] ?? 15);
        $page->getCollection()->each(function ($row) use ($resource, $actor) {
            if ($resource === 'purchases') {
                $items = DB::table('weaver_purchase_items as i')->leftJoin('v_current_stock as s', 's.id', '=', 'i.carpet_id')
                    ->where('i.weaver_purchase_id', $row->id)->get(['i.id', 's.acquisition_item_id', 's.stock_status']);
                $row->can_void = $row->state === 'posted' && $items->isNotEmpty() && $items->every(fn ($i) => $i->stock_status === 'purchased' && (int) $i->id === (int) $i->acquisition_item_id);
            } elseif ($resource === 'expenses') {
                $row->paid_amount = BusinessSupport::paid('operating_expense_payments', 'expense_id', $row->id);
                $row->state = DB::table('business_voids')->where('expense_id', $row->id)->exists() ? 'void' : 'recorded';
                $row->remaining_amount = bcsub($row->state === 'void' ? '0' : $row->amount, $row->paid_amount, 2);
            } elseif ($resource === 'materials') {
                $lots = Inventory::branchScope(DB::table('material_lots'), $actor)->where('material_id', $row->id)->pluck('id');
                $row->warehouse_quantity = '0';
                foreach ($lots as $lot) {
                    $row->warehouse_quantity = bcadd($row->warehouse_quantity, ProductionService::warehouse($lot), 3);
                }
            } elseif ($resource === 'material-receipts') {
                $row->warehouse_quantity = ProductionService::warehouse($row->id);
                $row->remaining_amount = bcsub($row->total_cost, BusinessSupport::paid('material_lot_payments', 'lot_id', $row->id), 2);
            } elseif (in_array($resource, ['production', 'partnerships'], true)) {
                $row->state = ProductionService::state($row->id);
                $row->progress = DB::table('production_progress')->where('production_order_id', $row->id)->orderByDesc('id')->value('percent') ?? 0;
            } elseif ($resource === 'sales') {
                $row->total_amount = TradeService::saleTotal($row->id);
                $row->paid_amount = BusinessSupport::paid('trade_customer_receipts', 'sale_id', $row->id);
                $row->remaining_amount = bcsub($row->total_amount, $row->paid_amount, 2);
                $row->state = bccomp($row->remaining_amount, '0', 2) === 0 ? 'paid' : ($row->due_on < FoundationCatalog::today() ? 'overdue' : 'unpaid');
            }
        });

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $resource, int $id)
    {
        $this->permit($request, $resource);
        $tables = ['purchases' => 'weaver_purchases', 'expenses' => 'operating_expenses', 'material-receipts' => 'material_lots',
            'production' => 'production_orders', 'partnerships' => 'production_orders', 'sales' => 'trade_sales'];
        abort_unless(isset($tables[$resource]), 404);
        $row = BusinessSupport::document($request->user(), $tables[$resource], $id);
        $row->payments = [];
        if ($resource === 'purchases') {
            $row->items = DB::table('weaver_purchase_items as i')->join('carpets as c', 'c.id', '=', 'i.carpet_id')
                ->leftJoin('v_current_stock as s', function ($join) use ($request) {
                    $join->on('s.id', '=', 'c.id');
                    if (! Access::administrator($request->user())) {
                        $join->whereIn('s.custody_branch_id', Access::branchIds($request->user()));
                    }
                })->leftJoin('stock_locations as l', 'l.id', '=', 's.location_id')->where('i.weaver_purchase_id', $id)
                ->get(['c.carpet_code', 'c.name', 'i.unit_price', 's.stock_status', 'l.name as location_name']);
            $row->balance = DB::table('v_weaver_purchase_balances')->where('purchase_id', $id)->first();
            $row->remaining_amount = $row->balance->remaining_amount;
            $row->paid_amount = $row->balance->paid_amount;
            $row->total_amount = $row->balance->total_amount;
            $table = $row->weaver_id ? 'weaver_payments' : 'supplier_carpet_payments';
            if ($row->weaver_id) {
                $ids = DB::table('weaver_payment_allocations')->where('weaver_purchase_id', $id)->pluck('weaver_payment_id');
                $row->payments = $this->payments(DB::table($table.' as p')->whereIn('p.id', $ids));
            } else {
                $row->payments = $this->payments(DB::table($table.' as p')->where('p.purchase_id', $id));
            }
        } elseif ($resource === 'expenses') {
            $row->payments = $this->payments(DB::table('operating_expense_payments as p')->where('p.expense_id', $id));
            $row->void = DB::table('business_voids')->where('expense_id', $id)->first();
            $row->state = $row->void ? 'void' : 'recorded';
            $row->paid_amount = BusinessSupport::paid('operating_expense_payments', 'expense_id', $id);
            $row->remaining_amount = bcsub($row->void ? '0' : $row->amount, $row->paid_amount, 2);
            $row->attachments = DB::table('expense_attachments')->where('expense_id', $id)->get(['id', 'original_name']);
        } elseif ($resource === 'material-receipts') {
            $row->warehouse_quantity = ProductionService::warehouse($id);
            $row->remaining_amount = bcsub($row->total_cost, BusinessSupport::paid('material_lot_payments', 'lot_id', $id), 2);
            $row->payments = $this->payments(DB::table('material_lot_payments as p')->where('p.lot_id', $id), true);
            $row->movements = DB::table('material_movements as m')->join('production_orders as o', 'o.id', '=', 'm.production_order_id')->where('m.lot_id', $id)->orderBy('m.id')->get(['m.*', 'o.order_no']);
        } elseif ($resource === 'sales') {
            $row->items = DB::table('trade_sale_items as i')->join('carpets as c', 'c.id', '=', 'i.carpet_id')->where('i.sale_id', $id)->get(['i.*', 'c.carpet_code', 'c.name']);
            $row->total_amount = TradeService::saleTotal($id);
            $row->paid_amount = BusinessSupport::paid('trade_customer_receipts', 'sale_id', $id);
            $row->remaining_amount = bcsub($row->total_amount, $row->paid_amount, 2);
            $row->payments = $this->payments(DB::table('trade_customer_receipts as p')->where('p.sale_id', $id));
        } else {
            abort_unless($row->model === ($resource === 'partnerships' ? 'partnership' : 'company_funded'), 404);
            $row->state = ProductionService::state($id);
            $row->items = DB::table('production_carpets as p')->join('carpets as c', 'c.id', '=', 'p.carpet_id')->where('p.production_order_id', $id)->get(['p.*', 'c.carpet_code', 'c.name']);
            $row->movements = DB::table('material_movements as m')->join('material_lots as l', 'l.id', '=', 'm.lot_id')->join('materials as mat', 'mat.id', '=', 'l.material_id')
                ->where('m.production_order_id', $id)->orderBy('m.id')->get(['m.*', 'l.lot_no', 'mat.name', 'mat.unit']);
            $row->progress_history = DB::table('production_progress')->where('production_order_id', $id)->orderBy('id')->get();
            $row->labor_charges = DB::table('production_charges')->where('production_order_id', $id)->get();
            if ($resource === 'production') {
                $row->labor_earned = (string) DB::table('production_charges')->where('production_order_id', $id)->sum('amount');
                $row->labor_paid = BusinessSupport::paid('production_payments', 'production_order_id', $id);
                $row->labor_balance = bcsub($row->labor_earned, $row->labor_paid, 2);
            }
            $row->payments = $this->payments(DB::table('production_payments as p')->where('p.production_order_id', $id));
            $row->receipt = DB::table('production_receipts')->where('production_order_id', $id)->first();
            if ($row->receipt) {
                $row->total_cost_base = $row->receipt->total_cost_base;
                $row->received_on = $row->receipt->received_on;
            }
            if ($resource === 'partnerships') {
                $row->contributions = DB::table('partnership_contributions')->where('production_order_id', $id)->get();
                $row->settlement_preview = TradeService::settlementPreview($request->user(), $id);
                $row->settlement = DB::table('partnership_settlements')->where('production_order_id', $id)->first();
                $row->settlement_payments = $row->settlement ? $this->payments(DB::table('partnership_settlement_payments as p')->where('p.settlement_id', $row->settlement->id)) : [];
                if ($row->settlement) {
                    $paid = '0';
                    foreach ($row->settlement_payments as $payment) {
                        if (! $payment->reversed) {
                            $paid = bcadd($paid, $payment->amount_base, 2);
                        }
                    }
                    $owed = ltrim($row->settlement->balance_due_base, '-');
                    $row->settlement->settlement_paid_base = $paid;
                    $row->settlement->settlement_remaining_base = bcsub($owed, $paid, 2);
                }
            }
        }

        return response()->json(['data' => $row])->header('Cache-Control', 'private, no-store');
    }

    private function payments($query, bool $allocated = false)
    {
        return $query->join('account_transactions as t', 't.id', '=', 'p.account_transaction_id')->join('accounts as a', 'a.id', '=', 't.account_id')
            ->join('currencies as c', 'c.id', '=', 't.currency_id')->select('p.*', 't.transaction_date', 't.description', 't.direction', 'a.name as account_name', 'c.code as currency_code')
            ->selectRaw($allocated ? 'COALESCE(p.amount_in_payment_currency,t.amount) amount' : 't.amount')
            ->selectRaw('EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=t.id) reversed')->orderBy('p.id')->get();
    }

    public function command(Request $request, string $resource, ?int $id = null, string $action = 'create')
    {
        $module = $this->permit($request, $resource, match ($action) {
            'create' => $resource === 'material-receipts' ? 'receive' : 'create',
            'pay', 'settlement-pay' => 'pay', 'collect' => 'collect', 'void' => 'void', 'settle' => 'settle',
            'receive' => $resource === 'partnerships' ? 'manage' : 'receive',
            'move' => 'view', default => 'manage',
        });
        $operation = $action === 'create' ? match ($resource) {
            'purchases' => 'purchase', 'expenses' => 'expense', 'materials' => 'material',
            'material-receipts' => 'material-receipt', 'production', 'partnerships' => 'production', 'sales' => 'sale', default => abort(404),
        } : match ($action) {
            'move' => 'material-move', 'receive' => 'production-receive',
            'pay' => in_array($resource, ['production', 'partnerships'], true) ? 'production-pay' : 'payment',
            'progress', 'charge', 'contribution', 'void', 'settle' => $action,
            'collect', 'settlement-pay' => 'payment', default => abort(404),
        };
        $key = validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[a-zA-Z0-9_.:-]+$/']])->validate()['key'];
        // Validation runs inside the command so a successful retry can replay even a unique material code.
        $raw = $request->all();
        $result = app(Inventory::class)->command($request->user(), 'business:'.$resource.':'.$action.':'.$id, $raw, $key,
            function ($settings) use ($request, $resource, $id, $action, $operation, $raw, $key) {
                if ($operation === 'sale' && array_key_exists('items', $raw) && array_key_exists('stock_groups', $raw)) {
                    Inventory::fail('stock_groups', 'Choose either grouped stock or individual carpets for an invoice.');
                }
                $data = validator($raw, BusinessRules::for($operation))->validate();
                $actor = $request->user();
                if (in_array($resource, ['production', 'partnerships'], true)) {
                    $model = $resource === 'partnerships' ? 'partnership' : 'company_funded';
                    if ($id) {
                        abort_unless(BusinessSupport::document($actor, 'production_orders', $id)->model === $model, 404);
                    } else {
                        abort_unless($data['model'] === $model, 422, 'Choose the correct production model.');
                    }
                    $production = app(ProductionService::class);

                    return match ($action) {
                        'create' => $production->create($actor, $data, $settings),
                        'move' => $this->move($request, $id, $data),
                        'progress' => $production->progress($actor, $id, $data),
                        'charge' => $production->charge($actor, $id, $data),
                        'contribution' => $production->contribution($actor, $id, $data),
                        'receive' => $production->receive($actor, $id, $data),
                        'pay' => $production->pay($actor, $id, $data, $key),
                        'settle' => app(TradeService::class)->settle($actor, $id, $data),
                        'settlement-pay' => app(TradeService::class)->settlementPay($actor, $id, $data, $key),
                        default => abort(404),
                    };
                }

                return match ($resource.':'.$action) {
                    'purchases:create' => app(BusinessPurchasing::class)->purchase($actor, $data, $settings, $key),
                    'purchases:pay' => app(BusinessPurchasing::class)->pay($actor, $id, $data, $key),
                    'expenses:create' => app(BusinessPurchasing::class)->expense($actor, $data, $settings, $key),
                    'expenses:pay' => app(BusinessPurchasing::class)->expensePay($actor, $id, $data, $key),
                    'expenses:void' => app(BusinessPurchasing::class)->expenseVoid($actor, $id, $data),
                    'materials:create' => app(ProductionService::class)->material($actor, $data),
                    'material-receipts:create' => app(ProductionService::class)->lot($actor, $data, $settings, $key),
                    'material-receipts:pay' => app(ProductionService::class)->lotPay($actor, $id, $data, $key),
                    'sales:create' => app(TradeService::class)->sale($actor, $data, $settings, $key),
                    'sales:collect' => app(TradeService::class)->collect($actor, $id, $data, $key),
                    default => abort(404),
                };
            });

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    private function move(Request $request, int $id, array $data): array
    {
        Access::permit($request->user(), 'materials.move');

        return app(ProductionService::class)->move($request->user(), $id, $data);
    }

    public function reverse(Request $request, int $id)
    {
        $data = $request->validate(BusinessRules::for('reverse'));
        $key = validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:100']])->validate()['key'];
        Access::permit($request->user(), 'ledger.reverse');

        return response()->json(app(LedgerService::class)->reverse($request->user(), $id, $data['reason'], $key));
    }

    public function attachment(Request $request, int $id)
    {
        Access::permit($request->user(), 'expenses.create');
        BusinessSupport::document($request->user(), 'operating_expenses', $id);
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120']]);
        $file = $request->file('file');
        $path = BusinessSupport::receiptDirectory().'/'.Str::uuid().'.'.$file->guessExtension();
        try {
            $attachment = DB::transaction(function () use ($request, $id, $path, $file) {
                DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
                BusinessSupport::document($request->user(), 'operating_expenses', $id);
                if (DB::table('expense_attachments')->where('expense_id', $id)->count() >= 10) {
                    Inventory::fail('file', 'An expense can have at most ten receipt attachments.');
                }
                if (! Storage::disk('local')->put($path, file_get_contents($file->getRealPath()))) {
                    abort(500, 'Could not store the receipt.');
                }

                return BusinessSupport::insert($request->user(), 'expense_attachments', [
                    'expense_id' => $id, 'storage_path' => $path, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 150), 'mime_type' => $file->getMimeType()]);
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return response()->json(['data' => ['id' => $attachment]], 201);
    }

    public function download(Request $request, int $id, int $attachment)
    {
        Access::permit($request->user(), 'expenses.view');
        BusinessSupport::document($request->user(), 'operating_expenses', $id);
        $row = DB::table('expense_attachments')->where('expense_id', $id)->where('id', $attachment)->first();
        abort_unless($row && Storage::disk('local')->exists($row->storage_path), 404);

        return Storage::disk('local')->download($row->storage_path, $row->original_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
