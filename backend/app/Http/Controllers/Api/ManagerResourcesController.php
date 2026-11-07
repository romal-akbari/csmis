<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\DocumentNumbers;
use App\Services\BusinessSupport;
use App\Services\FoundationAccess as Access;
use App\Services\InventoryService as Inventory;
use App\Services\ManagerMaterialsService;
use App\Services\ManagerResourcesRules;
use App\Services\ManagerStatementService;
use App\Services\ManagerToolsService;
use App\Services\WorkerAssistanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManagerResourcesController extends Controller
{
    private const TABLES = ['managers' => 'production_managers', 'tools' => 'production_tools',
        'tool-receipts' => 'production_tool_lots', 'assistance' => 'worker_assistance'];

    private function permit(Request $request, string $resource, bool $create = false): void
    {
        abort_unless(isset(self::TABLES[$resource]), 404);
        $code = match ($resource) {
            'managers' => $create ? 'production.create' : 'production.view',
            'tools' => $create ? 'materials.create' : 'materials.view',
            'tool-receipts' => $create ? 'materials.receive' : 'materials.view',
            'assistance' => $create ? 'production.manage' : 'production.view',
        };
        Access::permit($request->user(), $code);
    }

    public function index(Request $request, string $resource)
    {
        $this->permit($request, $resource);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'], 'branch_id' => ['nullable', 'integer', 'min:1']]);
        $query = DB::table(self::TABLES[$resource].' as p');
        if ($resource !== 'tools') {
            Inventory::branchScope($query, $request->user(), 'p.branch_id');
            if (! empty($filters['branch_id'])) {
                $query->where('p.branch_id', $filters['branch_id']);
            }
        }
        $column = match ($resource) {
            'managers', 'tools' => 'p.name', 'tool-receipts' => 'p.lot_no', 'assistance' => 'p.assistance_no'
        };
        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters, $column, $resource) {
                $q->where($column, 'like', '%'.$filters['search'].'%');
                if (in_array($resource, ['managers', 'tools'], true)) {
                    $q->orWhere('p.code', 'like', '%'.$filters['search'].'%');
                }
                if ($resource === 'assistance') {
                    $q->orWhere('p.beneficiary_name', 'like', '%'.$filters['search'].'%');
                }
            });
        }
        if ($resource === 'tool-receipts') {
            $query->join('production_tools as t', 't.id', '=', 'p.tool_id')->join('currencies as c', 'c.id', '=', 'p.currency_id')
                ->join('stock_locations as l', 'l.id', '=', 'p.location_id')->select('p.*', 't.name as tool_name', 'c.code as currency_code', 'l.name as location_name');
        } elseif ($resource === 'assistance') {
            $query->join('production_managers as m', 'm.id', '=', 'p.manager_id')->join('currencies as c', 'c.id', '=', 'p.currency_id')
                ->select('p.*', 'm.name as manager_name', 'c.code as currency_code');
        } else {
            $query->select('p.*');
        }
        $page = $query->orderByDesc('p.id')->paginate($filters['per_page'] ?? 15);
        $page->getCollection()->each(function ($row) use ($resource, $request) {
            if ($resource === 'assistance') {
                foreach (WorkerAssistanceService::balances($row->id) as $key => $value) {
                    $row->$key = $value;
                }
            }
            if ($resource === 'tool-receipts') {
                $row->warehouse_quantity = ManagerToolsService::warehouse($row->id);
                $row->paid_amount = BusinessSupport::paid('production_tool_payments', 'lot_id', $row->id);
                $row->remaining_amount = bcsub($row->total_cost, $row->paid_amount, 2);
            }
            if ($resource === 'tools') {
                $row->warehouse_quantity = 0;
                foreach (Inventory::branchScope(DB::table('production_tool_lots'), $request->user())->where('tool_id', $row->id)->pluck('id') as $lot) {
                    $row->warehouse_quantity += ManagerToolsService::warehouse($lot);
                }
            }
        });

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $resource)
    {
        $this->permit($request, $resource, true);
        $data = $request->validate(ManagerResourcesRules::for($resource));

        return $this->command($request, $resource.':create', $data, function ($settings, $key) use ($request, $resource, $data) {
            $actor = $request->user();
            if (in_array($resource, ['managers', 'tools'], true)) {
                $data['code'] = $data['code'] ?? DocumentNumbers::next($resource === 'managers' ? 'MGR' : 'TOOL');
                $this->uniqueCode(self::TABLES[$resource], $data['code']);
            }
            if ($resource === 'managers') {
                app(Inventory::class)->branch($actor, $data['branch_id']);
                if (! empty($data['employee_id']) && ! Access::scope(DB::table('employees'), 'employees', $actor)->where('id', $data['employee_id'])
                    ->where('branch_id', $data['branch_id'])->where('status', 'active')->whereNull('deleted_at')->exists()) {
                    Inventory::fail('employee_id', 'Choose an active employee in the manager branch.');
                }

                return ['id' => BusinessSupport::insert($actor, 'production_managers', $data)];
            }

            return match ($resource) {
                'tools' => ['id' => BusinessSupport::insert($actor, 'production_tools', $data)],
                'tool-receipts' => app(ManagerToolsService::class)->receive($actor, $data, $settings, $key),
                'assistance' => app(WorkerAssistanceService::class)->create($actor, $data, $settings),
            };
        });
    }

    public function show(Request $request, string $resource, int $id)
    {
        $this->permit($request, $resource);
        if ($resource === 'managers') {
            return $this->statement($request, $id);
        }
        if ($resource === 'tools') {
            return response()->json(['data' => DB::table('production_tools')->where('id', $id)->firstOrFail()])->header('Cache-Control', 'private, no-store');
        }
        abort_unless(in_array($resource, ['tool-receipts', 'assistance'], true), 404);
        $row = BusinessSupport::document($request->user(), self::TABLES[$resource], $id);
        $row->currency_code = DB::table('currencies')->where('id', $row->currency_id)->value('code');
        if ($resource === 'assistance') {
            $row->manager_name = DB::table('production_managers')->where('id', $row->manager_id)->value('name');
            foreach (WorkerAssistanceService::balances($id) as $key => $value) {
                $row->$key = $value;
            }
            $row->deliveries = DB::table('worker_assistance_deliveries')->where('assistance_id', $id)->orderBy('id')->get();
            foreach (['fundings', 'returns', 'recoveries'] as $kind) {
                $row->$kind = $this->payments($request, 'worker_assistance_'.$kind, 'assistance_id', $id);
            }
        } else {
            $row->tool_name = DB::table('production_tools')->where('id', $row->tool_id)->value('name');
            $row->warehouse_quantity = ManagerToolsService::warehouse($id);
            $row->paid_amount = BusinessSupport::paid('production_tool_payments', 'lot_id', $id);
            $row->remaining_amount = bcsub($row->total_cost, $row->paid_amount, 2);
            $row->payments = $this->payments($request, 'production_tool_payments', 'lot_id', $id);
        }

        return response()->json(['data' => $row])->header('Cache-Control', 'private, no-store');
    }

    private function payments(Request $request, string $table, string $column, int $id)
    {
        if (! $request->user()->hasPermission('ledger.view') || ! $request->user()->hasPermission('accounts.view')) {
            return [];
        }

        return Access::accounts(DB::table($table.' as p')->join('account_transactions as t', 't.id', '=', 'p.account_transaction_id'), $request->user(), 't.account_id')
            ->join('accounts as a', 'a.id', '=', 't.account_id')->join('currencies as c', 'c.id', '=', 't.currency_id')
            ->where('p.'.$column, $id)->select('p.*', 't.transaction_date', 't.currency_id', 't.direction', 't.description', 'a.name as account_name', 'c.code as currency_code')
            ->selectRaw($table === 'production_tool_payments' ? 'COALESCE(p.amount_in_payment_currency,t.amount) amount' : 't.amount')
            ->selectRaw('EXISTS(SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=t.id) reversed')->orderBy('p.id')->get();
    }

    private function uniqueCode(string $table, string $code, ?int $except = null): void
    {
        $query = DB::table($table)->where('code', $code);
        if ($except !== null) {
            $query->where('id', '<>', $except);
        }
        if ($query->exists()) {
            Inventory::fail('code', 'This code is already in use.');
        }
    }

    public function update(Request $request, string $resource, int $id)
    {
        abort_unless(in_array($resource, ['managers', 'tools'], true), 404);
        Access::permit($request->user(), $resource === 'managers' ? 'production.manage' : 'materials.create');
        $data = $request->validate(ManagerResourcesRules::for($resource));

        return $this->command($request, $resource.':update:'.$id, $data, function () use ($request, $resource, $id, $data) {
            $table = self::TABLES[$resource];
            $old = $resource === 'managers' ? BusinessSupport::document($request->user(), $table, $id) : DB::table($table)->find($id);
            abort_unless($old, 404);
            $data['code'] = $data['code'] ?? $old->code;
            $this->uniqueCode($table, $data['code'], $id);
            if ($resource === 'managers') {
                app(Inventory::class)->branch($request->user(), $data['branch_id']);
                $used = false;
                foreach (['manager_material_movements', 'manager_tool_movements', 'worker_assistance', 'manager_production_orders'] as $history) {
                    $used = $used || DB::table($history)->where('manager_id', $id)->exists();
                }
                $employee = array_key_exists('employee_id', $data) ? $data['employee_id'] : $old->employee_id;
                if ($used && ((int) $old->branch_id !== (int) $data['branch_id'] || (int) $old->employee_id !== (int) $employee)) {
                    Inventory::fail('branch_id', 'The branch and employee link of a used production manager cannot change.');
                }
                if (! empty($data['employee_id']) && ! Access::scope(DB::table('employees'), 'employees', $request->user())
                    ->where('id', $data['employee_id'])->where('branch_id', $data['branch_id'])->where('status', 'active')->whereNull('deleted_at')->exists()) {
                    Inventory::fail('employee_id', 'Choose an active employee in the manager branch.');
                }
            }
            DB::table($table)->where('id', $id)->update($data);
            AuditLogger::log('update', $table, $id, (array) $old, $data);

            return ['id' => $id];
        });
    }

    public function statement(Request $request, int $id)
    {
        return response()->json(['data' => app(ManagerStatementService::class)->read($request->user(), $id)])->header('Cache-Control', 'private, no-store');
    }

    public function managerMaterial(Request $request, int $id)
    {
        Access::permit($request->user(), 'materials.move');
        $data = $request->validate(ManagerResourcesRules::for('materials'));

        return $this->command($request, 'manager-material:'.$id, $data, fn () => app(ManagerMaterialsService::class)->move($request->user(), $id, $data));
    }

    public function managerTool(Request $request, int $id)
    {
        Access::permit($request->user(), 'materials.move');
        $data = $request->validate(ManagerResourcesRules::for('tool-move'));

        return $this->command($request, 'manager-tool:'.$id, $data, fn () => app(ManagerToolsService::class)->move($request->user(), $id, $data));
    }

    public function action(Request $request, string $resource, int $id, string $action)
    {
        abort_unless(($resource === 'tool-receipts' && $action === 'pay') || ($resource === 'assistance' && in_array($action, ['fund', 'deliver', 'recover', 'return'], true)), 404);
        Access::permit($request->user(), $resource === 'tool-receipts' ? 'materials.pay' : ($action === 'deliver' ? 'production.manage' : 'production.pay'));
        $data = $request->validate(ManagerResourcesRules::for($action));

        return $this->command($request, $resource.':'.$id.':'.$action, $data, fn ($settings, $key) => $resource === 'tool-receipts'
            ? app(ManagerToolsService::class)->pay($request->user(), $id, $data, $key)
            : ($action === 'deliver' ? app(WorkerAssistanceService::class)->deliver($request->user(), $id, $data)
                : app(WorkerAssistanceService::class)->transact($request->user(), $id, $action, $data, $key)));
    }

    private function command(Request $request, string $operation, array $data, callable $work)
    {
        $key = validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:100']])->validate()['key'];
        $result = app(Inventory::class)->command($request->user(), 'manager-resources:'.$operation, $data, $key, fn ($settings) => $work($settings, $key));

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }
}
