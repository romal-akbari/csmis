<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCustomerRequest;
use App\Http\Requests\Api\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * §4 customer management — the reference CRUD module every later
 * directory module (weavers, suppliers, ...) will copy.
 */
class CustomerController extends Controller
{
    /**
     * GET /api/customers — paginated list with search + filters.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'customer_type' => ['nullable', 'in:retail,wholesale,vip,other'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'archived' => ['nullable', 'in:only'],
        ]);

        $query = Customer::query()->latest('id');
        if (($filters['archived'] ?? null) === 'only') {
            $query->onlyTrashed();
        }

        if (! empty($filters['search'])) {
            $term = trim($filters['search']);
            $query->where(function ($inner) use ($term) {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['customer_type'])) {
            $query->where('customer_type', $filters['customer_type']);
        }

        $perPage = (int) ($filters['per_page'] ?? 15);

        $timezone = DB::table('company_settings')->value('timezone') ?: 'Asia/Kabul';

        return response()->json($query->paginate($perPage)->toArray() + [
            'company_timezone' => $timezone,
            'company_today' => now($timezone)->toDateString(),
        ]);
    }

    /**
     * POST /api/customers — create + audit.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = DB::transaction(function () use ($request) {
            $customer = Customer::create($request->validated() + [
                'created_by' => $request->user()->id,
            ])->refresh();
            AuditLogger::log('create', 'customers', $customer->id, null, $customer->toArray());

            return $customer;
        }, 3);

        return response()->json(['data' => $customer], 201);
    }

    /**
     * GET /api/customers/{customer} — single record (404 if soft-deleted).
     */
    public function show(Customer $customer): JsonResponse
    {
        return response()->json(['data' => $customer]);
    }

    /**
     * PUT/PATCH /api/customers/{customer} — update + audit before/after.
     */
    public function update(
        UpdateCustomerRequest $request,
        Customer $customer,
    ): JsonResponse {
        $fresh = DB::transaction(function () use ($request, $customer) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $old = $locked->toArray();
            $locked->update($request->validated());
            $locked->refresh();
            AuditLogger::log('update', 'customers', $locked->id, $old, $locked->toArray());

            return $locked;
        }, 3);

        return response()->json(['data' => $fresh]);
    }

    /**
     * DELETE /api/customers/{customer} — soft delete + audit.
     */
    public function destroy(Customer $customer): JsonResponse
    {
        DB::transaction(function () use ($customer) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $old = $locked->toArray();
            // Keep the row (and all future sales references) permanently.
            $locked->update(['status' => 'inactive']);
            $locked->delete();
            AuditLogger::log('delete', 'customers', $locked->id, $old, $locked->toArray());
        }, 3);

        return response()->json([
            'message' => 'Customer deleted.',
            'data' => ['id' => $customer->id],
        ]);
    }

    /** Restore the directory row without automatically making it active. */
    public function restore(int $id): JsonResponse
    {
        $customer = DB::transaction(function () use ($id) {
            $customer = Customer::onlyTrashed()->lockForUpdate()->findOrFail($id);
            $old = $customer->toArray();
            $customer->restore();
            $customer->refresh();
            // Restoring is an update: audit action is an existing SQL enum.
            AuditLogger::log('update', 'customers', $customer->id, $old, $customer->toArray());

            return $customer;
        }, 3);

        return response()->json(['data' => $customer]);
    }
}
