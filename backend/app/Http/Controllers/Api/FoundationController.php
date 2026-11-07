<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\FoundationRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AccountOpeningRate;
use App\Services\DocumentNumbers;
use App\Services\FoundationAccess as Access;
use App\Services\FoundationCatalog as Catalog;
use App\Services\LedgerService;
use App\Services\ManagerToolsService;
use App\Services\ProductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FoundationController extends Controller
{
    public function index(Request $request, string $resource): JsonResponse
    {
        [$table, $module] = Catalog::resource($resource);
        Access::permit($request->user(), $module.'.view');
        if (in_array($resource, ['users', 'roles'], true)) {
            abort_unless(Access::administrator($request->user()), 403);
        }
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
        ]);
        $query = Access::scope(DB::table($table), $table, $request->user());
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        if (isset($filters['status']) && $filters['status'] !== 'all'
            && in_array($resource, ['facilities', 'categories', 'expense-categories'], true)) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $column = $resource === 'exchange-rates' ? 'effective_date' : 'name';
            $query->where($column, 'like', '%'.$filters['search'].'%');
        }
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 15);
        $page->setCollection($page->getCollection()->map(
            fn ($row) => Catalog::clean($table, $row),
        ));

        $response = response()->json($page);

        return $resource === 'accounts' ? $response->header('Cache-Control', 'private, no-store') : $response;
    }

    public function lookups(Request $request): JsonResponse
    {
        $data = [];
        $configuration = DB::table('company_settings')->find(1);
        $data['company'] = [['id' => 1, 'name' => $configuration->timezone,
            'code' => (string) $configuration->base_currency_id]];
        $resources = [
            'branches', 'facilities', 'currencies', 'employees',
        ];
        if (Access::administrator($request->user())) {
            $resources[] = 'roles';
            $data['accounts'] = DB::table('accounts')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
            $data['permissions'] = DB::table('permissions')->orderBy('code')
                ->get(['id', 'code', 'description']);
        }
        foreach ($resources as $resource) {
            if ($resource === 'employees' && ! Access::administrator($request->user())
                && ! $request->user()->hasPermission('employees.view')) {
                $data[$resource] = [];

                continue;
            }
            [$table] = Catalog::resource($resource);
            $query = Access::scope(DB::table($table), $table, $request->user());
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            $fields = ['id', 'name'];
            if ($table === 'currencies') {
                $fields = ['id', 'name', 'code', 'is_active', 'decimal_places'];
            }
            // Do not silently omit valid selections once a directory grows.
            $data[$resource] = $query->orderBy('name')->get($fields);
        }

        return response()->json(['data' => $data]);
    }

    public function store(FoundationRequest $request, string $resource): JsonResponse
    {
        return $this->save($request, $resource, null);
    }

    public function update(FoundationRequest $request, string $resource, int $id): JsonResponse
    {
        return $this->save($request, $resource, $id);
    }

    private function save(FoundationRequest $request, string $resource, ?int $id): JsonResponse
    {
        [$table] = Catalog::resource($resource);
        $result = DB::transaction(function () use ($request, $resource, $table, $id) {
            // Shared lock serializes configuration and administrator safety checks.
            $settings = DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $old = $id ? Access::scope(DB::table($table), $table, $request->user())
                ->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $old, 404);
            $data = $request->validated();
            if (! $id && ($sequence = DocumentNumbers::foundation($resource))) {
                $data['code'] = $data['code'] ?? DocumentNumbers::next($sequence);
            }
            $opening = $resource === 'accounts' && ! $id ? [
                'amount' => $data['opening_amount'] ?? '0',
                'date' => $data['opening_on'] ?? Catalog::today(),
                'rate' => $data['opening_rate'] ?? (bccomp($data['opening_amount'] ?? '0', '0', 2) > 0
                    ? AccountOpeningRate::resolve((int) $data['currency_id'], $data['opening_on'], $settings->base_currency_id) : '1'),
            ] : null;
            unset($data['opening_amount'], $data['opening_on'], $data['opening_rate']);
            if (! Access::administrator($request->user()) && $resource !== 'accounts') {
                $branch = $data['branch_id'] ?? null;
                $facility = $data['washing_facility_id'] ?? null;
                abort_unless(($branch && in_array((int) $branch, Access::branchIds($request->user()), true))
                    || ($facility && in_array((int) $facility, Access::facilityIds($request->user()), true)), 403);
            }
            $this->businessRules($resource, $data, $old, $request->user());
            $assignments = [];
            foreach (['role_ids', 'branch_ids', 'facility_ids', 'account_ids', 'permission_ids'] as $key) {
                if (array_key_exists($key, $data)) {
                    $assignments[$key] = $data[$key];
                    unset($data[$key]);
                }
            }
            if (array_key_exists('password', $data)) {
                if ($data['password']) {
                    $data['password_hash'] = Hash::make($data['password']);
                }
                unset($data['password']);
            }
            if (! $id && Schema::hasColumn($table, 'created_by')) {
                $data['created_by'] = $request->user()->id;
            }
            if (Schema::hasColumn($table, 'updated_at')) {
                $data['updated_at'] = now();
            }
            $before = $old ? Catalog::clean($table, $old) : null;
            if ($id) {
                DB::table($table)->where('id', $id)->update($data);
            } else {
                $id = DB::table($table)->insertGetId($data);
                if ($resource === 'accounts' && ! Access::administrator($request->user())) {
                    DB::table('user_accounts')->insert(['user_id' => $request->user()->id, 'account_id' => $id]);
                }
            }
            $this->assign($table, $id, $assignments);
            if ($opening && bccomp($opening['amount'], '0', 2) > 0) {
                Access::permit($request->user(), 'ledger.create');
                app(LedgerService::class)->post($request->user(), [
                    'account_id' => $id, 'entry_type' => 'opening', 'amount' => $opening['amount'],
                    'rate_to_base' => $opening['rate'], 'transaction_date' => $opening['date'],
                    'description' => 'Opening balance on account creation',
                ], 'account-opening:'.$id);
            }
            if ($resource === 'users') {
                // Changed access must take effect for already signed-in sessions.
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)
                    ->where('tokenable_id', $id)->delete();
            }
            if ($resource === 'roles' && $old) {
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', DB::table('user_roles')
                        ->where('role_id', $id)->select('user_id'))->delete();
            }
            $saved = Catalog::clean($table, DB::table($table)->find($id));
            AuditLogger::log($old ? 'update' : 'create', $table, $id, $before, $saved);

            return $saved;
        }, 3);

        return response()->json(['data' => $result], $id ? 200 : 201);
    }

    private function businessRules(string $resource, array $data, ?object $old, User $actor): void
    {
        $fail = fn ($field, $message) => throw ValidationException::withMessages([$field => $message]);
        if ($resource === 'branches' && $data['is_main']) {
            if (DB::table('branches')->where('is_main', true)
                ->when($old, fn ($q) => $q->where('id', '<>', $old->id))->exists()) {
                $fail('is_main', 'Only one main branch is allowed.');
            }
        }
        if ($resource === 'currencies') {
            $base = DB::table('company_settings')->value('base_currency_id');
            if ($old && (int) $old->id === (int) $base && ! $data['is_active']) {
                $fail('is_active', 'The base currency must remain active.');
            }
            if ($old && (DB::table('accounts')->where('currency_id', $old->id)->exists()
                || DB::table('weaver_purchases')->where('currency_id', $old->id)->exists()
                || DB::table('washing_bills')->where('currency_id', $old->id)->exists()
                || DB::table('operating_expenses')->where('currency_id', $old->id)->exists()
                || DB::table('material_lots')->where('currency_id', $old->id)->exists()
                || DB::table('production_orders')->where('currency_id', $old->id)->exists()
                || DB::table('manager_production_orders')->where('currency_id', $old->id)->exists()
                || DB::table('production_tool_lots')->where('currency_id', $old->id)->exists()
                || DB::table('worker_assistance')->where('currency_id', $old->id)->exists()
                || DB::table('trade_sales')->where('currency_id', $old->id)->exists())
                && ($old->code !== $data['code'] || (int) $old->decimal_places !== (int) $data['decimal_places'])) {
                $fail('code', 'Currency code and precision are fixed once an account or posted document uses them.');
            }
        }
        if ($resource === 'accounts' && $old) {
            $used = DB::table('account_transactions')->where('account_id', $old->id)->exists();
            if ($used && (int) $old->currency_id !== (int) $data['currency_id']) {
                $fail('currency_id', 'Posted accounts cannot change currency.');
            }
            if ($data['status'] === 'inactive' && bccomp((string) DB::table('v_account_balances')
                ->where('account_id', $old->id)->value('balance'), '0', 2) !== 0) {
                $fail('status', 'Reconcile the account to zero before archiving it.');
            }
        }
        if ($resource === 'locations' && $old) {
            $used = DB::table('stock_events')->where('resulting_location_id', $old->id)->exists()
                || DB::table('material_lots')->where('location_id', $old->id)->exists()
                || DB::table('production_tool_lots')->where('location_id', $old->id)->exists();
            if ($used && ($old->branch_id != ($data['branch_id'] ?? null)
                || $old->washing_facility_id != ($data['washing_facility_id'] ?? null))) {
                $fail('branch_id', 'A used stock location cannot change ownership.');
            }
            if ($data['status'] === 'inactive' && DB::table('v_current_stock')->where('location_id', $old->id)->exists()) {
                $fail('status', 'Move the carpets before archiving this stock location.');
            }
            if ($data['status'] === 'inactive') {
                foreach (DB::table('material_lots')->where('location_id', $old->id)->pluck('id') as $lot) {
                    if (bccomp(ProductionService::warehouse($lot), '0', 3) > 0
                        || bccomp(ProductionService::held($lot), '0', 3) > 0
                        || bccomp((string) DB::table('manager_material_movements')->where('lot_id', $lot)
                            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type='issue' THEN quantity ELSE -quantity END),0) qty")->value('qty'), '0', 3) > 0) {
                        $fail('status', 'Account for the remaining material stock and weaver-held quantities before archiving this location.');
                    }
                }
                foreach (DB::table('production_tool_lots')->where('location_id', $old->id)->pluck('id') as $lot) {
                    if (bccomp(ManagerToolsService::warehouse($lot), '0', 3) > 0
                        || (int) DB::table('manager_tool_movements')->where('lot_id', $lot)
                            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type='issue' THEN quantity ELSE -quantity END),0) qty")->value('qty') > 0) {
                        $fail('status', 'Reconcile tool stock and manager custody before archiving this location.');
                    }
                }
            }
        }
        if ($resource === 'branches' && $old && $data['status'] === 'inactive'
            && DB::table('v_current_stock')->where('custody_branch_id', $old->id)->whereNull('deleted_at')->exists()) {
            $fail('status', 'Move the carpets before archiving this branch.');
        }
        if ($resource === 'facilities' && $old && $data['status'] === 'inactive'
            && DB::table('stock_locations')->where('washing_facility_id', $old->id)
                ->where('status', 'active')->exists()) {
            $fail('status', 'Archive the facility stock locations first.');
        }
        if ($resource === 'employees' && $old && Schema::hasColumn('weavers', 'manager_employee_id')) {
            $assigned = DB::table('weavers')->where('manager_employee_id', $old->id);
            if ((int) $old->branch_id !== (int) $data['branch_id'] && (clone $assigned)->exists()) {
                $fail('branch_id', 'Reassign the managed weavers before changing the employee branch.');
            }
            if ($data['status'] !== 'active' && (clone $assigned)->where('status', 'active')->exists()) {
                $fail('status', 'Reassign or archive the active weavers before deactivating their manager.');
            }
        }
        if ($resource === 'roles' && $old?->name === 'Administrator') {
            $fail('name', 'The Administrator role is managed by the permission catalogue.');
        }
        if ($resource === 'users') {
            $adminRole = DB::table('roles')->where('name', 'Administrator')->value('id');
            $becomingAdmin = in_array((int) $adminRole, array_map('intval', $data['role_ids']), true);
            if (! $becomingAdmin && ! $data['branch_ids'] && ! $data['facility_ids']) {
                $fail('branch_ids', 'Assign at least one branch or washing facility.');
            }
            if ($old && (int) $old->id === (int) $actor->id
                && (! $data['is_active'] || ! $becomingAdmin)) {
                $fail('is_active', 'You cannot disable or remove your own administrator access.');
            }
            if ($old && User::find($old->id)?->roles()->where('name', 'Administrator')->exists()
                && (! $data['is_active'] || ! $becomingAdmin)) {
                $others = User::where('id', '<>', $old->id)->where('is_active', true)
                    ->whereHas('roles', fn ($q) => $q->where('name', 'Administrator'))->exists();
                if (! $others) {
                    $fail('is_active', 'At least one active administrator is required.');
                }
            }
        }
    }

    private function assign(string $table, int $id, array $assignments): void
    {
        $pivots = [
            'role_ids' => ['user_roles', 'user_id', 'role_id'],
            'branch_ids' => ['user_branches', 'user_id', 'branch_id'],
            'facility_ids' => ['user_washing_facilities', 'user_id', 'washing_facility_id'],
            'account_ids' => ['user_accounts', 'user_id', 'account_id'],
            'permission_ids' => ['role_permissions', 'role_id', 'permission_id'],
        ];
        foreach ($assignments as $key => $values) {
            [$pivot, $owner, $target] = $pivots[$key];
            DB::table($pivot)->where($owner, $id)->delete();
            foreach ($values as $value) {
                DB::table($pivot)->insert([$owner => $id, $target => $value]);
            }
        }
    }

    public function settings(Request $request): JsonResponse
    {
        Access::permit($request->user(), 'settings.view');

        return response()->json(['data' => DB::table('company_settings')->find(1)]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        Access::permit($request->user(), 'settings.update');
        abort_unless(Access::administrator($request->user()), 403);
        $data = $request->validate([
            'base_currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where('is_active', true)],
            'default_locale' => ['required', Rule::in(['en', 'fa'])],
            'timezone' => ['required', 'timezone:all'],
            'reminder_days_before' => ['required', 'integer', 'between:0,365'],
        ]);
        DB::transaction(function () use ($request, $data) {
            $old = DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ((int) $old->base_currency_id !== (int) $data['base_currency_id']
                && (DB::table('account_transactions')->exists() || DB::table('weaver_purchases')->exists()
                    || DB::table('washing_bills')->exists() || DB::table('operating_expenses')->exists()
                    || DB::table('material_lots')->exists() || DB::table('production_orders')->exists() || DB::table('trade_sales')->exists()
                    || DB::table('manager_production_orders')->exists() || DB::table('production_tool_lots')->exists() || DB::table('worker_assistance')->exists())) {
                throw ValidationException::withMessages([
                    'base_currency_id' => 'Base currency is fixed after the first ledger posting.',
                ]);
            }
            DB::table('company_settings')->where('id', 1)->update($data + [
                'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
            DB::table('currencies')->update(['is_default' => false]);
            DB::table('currencies')->where('id', $data['base_currency_id'])->update(['is_default' => true]);
            AuditLogger::log('update', 'company_settings', 1, (array) $old, $data);
        }, 3);

        return $this->settings($request);
    }

    public function audit(Request $request): JsonResponse
    {
        Access::permit($request->user(), 'audit.view');
        abort_unless(Access::administrator($request->user()), 403);
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json(DB::table('audit_logs')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20));
    }
}
