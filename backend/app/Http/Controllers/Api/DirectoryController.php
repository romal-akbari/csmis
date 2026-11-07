<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DirectoryRequest;
use App\Services\AuditLogger;
use App\Services\DirectoryCatalog as Catalog;
use App\Services\FoundationAccess as Access;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DirectoryController extends Controller
{
    public function index(Request $request, string $resource): JsonResponse
    {
        Access::permit($request->user(), Catalog::module($resource).'.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,all'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'province_id' => ['nullable', 'integer', 'min:1'],
            'district_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Catalog::query($resource, $request->user());
        $status = $filters['status'] ?? 'active';
        if ($status !== 'all') {
            $query->where($resource.'.status', $status);
        }
        if ($search = $filters['search'] ?? null) {
            $query->where(function ($q) use ($resource, $search) {
                $q->where($resource.'.name', 'like', '%'.$search.'%');
                $q->orWhere($resource.'.'.(in_array($resource, ['weavers', 'suppliers'], true) ? 'phone' : 'name_fa'), 'like', '%'.$search.'%');
            });
        }
        if (isset($filters['province_id']) && in_array($resource, ['districts', 'areas', 'weavers'], true)) {
            $query->where($resource === 'districts' ? 'districts.province_id' : 'd.province_id', $filters['province_id']);
        }
        if (isset($filters['district_id']) && in_array($resource, ['areas', 'weavers'], true)) {
            $query->where($resource === 'areas' ? 'areas.district_id' : 'a.district_id', $filters['district_id']);
        }

        return response()->json($query->orderByDesc($resource.'.id')->paginate($filters['per_page'] ?? 15));
    }

    public function show(Request $request, string $resource, int $id): JsonResponse
    {
        Access::permit($request->user(), Catalog::module($resource).'.view');

        return response()->json(['data' => Catalog::query($resource, $request->user())->where($resource.'.id', $id)->firstOrFail()]);
    }

    public function lookups(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless(collect(['geography.view', 'weavers.view', 'weavers.create', 'weavers.update', 'suppliers.view', 'suppliers.create', 'suppliers.update'])
            ->contains(fn ($code) => $actor->hasPermission($code)), 403);
        $input = $request->validate(['weaver_id' => ['nullable', 'integer', 'min:1']]);
        $company = [['id' => 1, 'name' => DB::table('company_settings')->value('timezone') ?: 'Asia/Kabul']];
        $canGeography = collect(['geography.view', 'weavers.view', 'weavers.create', 'weavers.update'])
            ->contains(fn ($code) => $actor->hasPermission($code));
        if (! $canGeography) {
            abort_if(isset($input['weaver_id']), 403);

            return response()->json(['data' => ['company' => $company, 'provinces' => [], 'districts' => [], 'areas' => [], 'managers' => []]]);
        }
        $selected = null;
        if ($id = $input['weaver_id'] ?? null) {
            abort_unless($actor->hasPermission('weavers.view') || $actor->hasPermission('weavers.update'), 403);
            $selected = Catalog::query('weavers', $actor)->where('weavers.id', $id)->firstOrFail();
        }
        // Complete lookup lists: no silent limit that makes a valid record unselectable.
        $provinces = DB::table('provinces')->where('status', 'active');
        $districts = DB::table('districts as d')->join('provinces as p', 'p.id', '=', 'd.province_id')
            ->where(fn ($q) => $q->where('d.status', 'active')->where('p.status', 'active'));
        $areas = DB::table('areas as a')->join('districts as d', 'd.id', '=', 'a.district_id')
            ->join('provinces as p', 'p.id', '=', 'd.province_id')
            ->where(fn ($q) => $q->where('a.status', 'active')->where('d.status', 'active')->where('p.status', 'active'));
        $managers = DB::table('employees as e')->join('branches as b', 'b.id', '=', 'e.branch_id')
            ->where(fn ($q) => $q->where('e.status', 'active')->whereNull('e.deleted_at')
                ->where('b.status', 'active')->whereNull('b.deleted_at'));
        if ($selected) {
            $provinces->orWhere('id', $selected->province_id);
            $districts->orWhere('d.id', $selected->district_id);
            $areas->orWhere('a.id', $selected->area_id);
            $managers->orWhere('e.id', $selected->manager_employee_id);
        }
        if (! Access::administrator($actor)) {
            // Wrap OR selection inside the branch scope; never leak another branch.
            $managers = DB::query()->fromSub($managers->select('e.id', 'e.name', 'e.branch_id', 'e.status'), 'manager_options')
                ->whereIn('branch_id', Access::branchIds($actor));
            $managerRows = $managers->orderBy('name')->get();
        } else {
            $managerRows = $managers->orderBy('e.name')->get(['e.id', 'e.name', 'e.branch_id', 'e.status']);
        }
        if (! collect(['weavers.view', 'weavers.create', 'weavers.update'])
            ->contains(fn ($code) => $actor->hasPermission($code))) {
            $managerRows = [];
        }

        return response()->json(['data' => [
            'company' => $company,
            'provinces' => $provinces->orderBy('name')->get(['id', 'name', 'name_fa', 'status']),
            'districts' => $districts->orderBy('d.name')->get(['d.id', 'd.province_id', 'd.name', 'd.name_fa', 'd.status']),
            'areas' => $areas->orderBy('a.name')->get(['a.id', 'a.district_id', 'a.name', 'a.name_fa', 'a.status']),
            'managers' => $managerRows,
        ]]);
    }

    public function store(DirectoryRequest $request, string $resource): JsonResponse
    {
        return $this->save($request, $resource, null);
    }

    public function update(DirectoryRequest $request, string $resource, int $id): JsonResponse
    {
        return $this->save($request, $resource, $id);
    }

    private function save(DirectoryRequest $request, string $resource, ?int $id): JsonResponse
    {
        $creating = $id === null;
        try {
            $saved = DB::transaction(function () use ($request, $resource, $id) {
                DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
                $old = $id ? Catalog::scope(DB::table($resource), $resource, $request->user())
                    ->where('id', $id)->lockForUpdate()->firstOrFail() : null;
                $data = $request->validated();
                $this->businessRules($resource, $data, $old, $request);
                if ($old && $old->status === 'active' && $data['status'] === 'inactive') {
                    Access::permit($request->user(), Catalog::module($resource).'.delete');
                    $this->checkDependencies($resource, $id);
                }
                if (Schema::hasColumn($resource, 'deleted_at')) {
                    $data['deleted_at'] = $data['status'] === 'inactive' ? ($old?->deleted_at ?? now()) : null;
                }
                $data['updated_at'] = now();
                if ($old) {
                    DB::table($resource)->where('id', $id)->update($data);
                } else {
                    $data['created_at'] = now();
                    if (Schema::hasColumn($resource, 'created_by')) {
                        $data['created_by'] = $request->user()->id;
                    }
                    $id = DB::table($resource)->insertGetId($data);
                }
                $saved = (array) DB::table($resource)->find($id);
                // The shared audit catalogue records status transitions in the before/after image.
                AuditLogger::log($old ? 'update' : 'create', $resource, $id, $old ? (array) $old : null, $saved);

                return Catalog::query($resource, $request->user())->where($resource.'.id', $id)->firstOrFail();
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['name' => 'This name already exists within the selected parent.']);
            }
            throw $exception;
        }

        return response()->json(['data' => $saved], $creating ? 201 : 200);
    }

    private function businessRules(string $resource, array $data, ?object $old, Request $request): void
    {
        $active = $data['status'] === 'active';
        if ($resource === 'districts') {
            $parent = DB::table('provinces')->where('id', $data['province_id'])->lockForUpdate()->firstOrFail();
            if (($active || ! $old || $old->province_id != $data['province_id']) && $parent->status !== 'active') {
                $this->fail('province_id', 'Select an active province.');
            }
            if ($old && $old->province_id != $data['province_id']) {
                $this->checkDependencies($resource, $old->id, 'province_id');
            }
        } elseif ($resource === 'areas') {
            $parent = DB::table('districts as d')->join('provinces as p', 'p.id', '=', 'd.province_id')
                ->where('d.id', $data['district_id'])->lockForUpdate()->firstOrFail(['d.status', 'p.status as province_status']);
            if (($active || ! $old || $old->district_id != $data['district_id'])
                && ($parent->status !== 'active' || $parent->province_status !== 'active')) {
                $this->fail('district_id', 'Select an active district in an active province.');
            }
            if ($old && $old->district_id != $data['district_id']) {
                $this->checkDependencies($resource, $old->id, 'district_id');
            }
        } elseif ($resource === 'weavers') {
            $area = DB::table('areas as a')->join('districts as d', 'd.id', '=', 'a.district_id')
                ->join('provinces as p', 'p.id', '=', 'd.province_id')->where('a.id', $data['area_id'])
                ->lockForUpdate()->firstOrFail(['a.status', 'd.status as district_status', 'p.status as province_status']);
            if (($active || ! $old || $old->area_id != $data['area_id'])
                && ($area->status !== 'active' || $area->district_status !== 'active' || $area->province_status !== 'active')) {
                $this->fail('area_id', 'Select an active area, district and province.');
            }
            $manager = $data['manager_employee_id'] ?? null;
            if (! Access::administrator($request->user()) && ! $manager) {
                $this->fail('manager_employee_id', 'A manager in your assigned branch is required.');
            }
            if ($manager) {
                $employee = DB::table('employees as e')->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
                    ->where('e.id', $manager)->lockForUpdate()->firstOrFail(['e.*', 'b.status as branch_status', 'b.deleted_at as branch_deleted_at']);
                if (! Access::administrator($request->user())) {
                    abort_unless(in_array((int) $employee->branch_id, Access::branchIds($request->user()), true), 403);
                }
                if (($active || ! $old || $old->manager_employee_id != $manager)
                    && ($employee->status !== 'active' || $employee->deleted_at || $employee->branch_status !== 'active' || $employee->branch_deleted_at)) {
                    $this->fail('manager_employee_id', 'Choose an active employee in an active branch.');
                }
            }
        }
    }

    public function destroy(Request $request, string $resource, int $id): JsonResponse
    {
        $module = Catalog::module($resource);
        Access::permit($request->user(), $module.'.delete');
        abort_unless($module !== 'geography' || Access::administrator($request->user()), 403);
        DB::transaction(function () use ($request, $resource, $id) {
            DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $old = Catalog::scope(DB::table($resource), $resource, $request->user())
                ->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($old->status === 'inactive') {
                return;
            }
            $this->checkDependencies($resource, $id);
            $data = ['status' => 'inactive', 'updated_at' => now()];
            if (Schema::hasColumn($resource, 'deleted_at')) {
                $data['deleted_at'] = now();
            }
            DB::table($resource)->where('id', $id)->update($data);
            AuditLogger::log('delete', $resource, $id, (array) $old, (array) DB::table($resource)->find($id));
        }, 3);

        return response()->json(['message' => 'Record archived.']);
    }

    private function checkDependencies(string $resource, int $id, string $field = 'status'): void
    {
        // Includes legacy and future modules: physical references are retained, never cascaded.
        $references = DB::table('information_schema.KEY_COLUMN_USAGE')->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())
            ->where('REFERENCED_TABLE_NAME', $resource)->where('REFERENCED_COLUMN_NAME', 'id')
            ->get(['TABLE_NAME', 'COLUMN_NAME']);
        foreach ($references as $reference) {
            if (DB::table($reference->TABLE_NAME)->where($reference->COLUMN_NAME, $id)->exists()) {
                $this->fail($field, 'This record is in use by '.$reference->TABLE_NAME.'. Update its dependent records first.');
            }
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
