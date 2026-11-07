<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FoundationAccess;
use App\Services\SupplyPurchaseReadModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierBalanceController extends Controller
{
    private function filters(Request $request): array
    {
        FoundationAccess::permit($request->user(), 'suppliers.view');
        FoundationAccess::permit($request->user(), 'materials.view');

        return $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'], 'unpaid' => ['nullable', 'boolean']]);
    }

    public function index(Request $request, SupplyPurchaseReadModel $purchases)
    {
        $filters = $this->filters($request);
        $query = $purchases->suppliers($request->user());
        if (! empty($filters['search'])) $query->where(fn ($q) => $q->where('supplier.name', 'like', '%'.$filters['search'].'%')->orWhere('supplier.phone', 'like', '%'.$filters['search'].'%'));
        if (! empty($filters['unpaid'])) $query->havingRaw('SUM(facts.remaining_amount)>0');

        return response()->json($query->orderBy('supplier.name')->orderBy('facts.currency_code')->paginate($filters['per_page'] ?? 15))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id, SupplyPurchaseReadModel $purchases)
    {
        $filters = $this->filters($request);
        $actor = $request->user();
        $supplier = DB::table('suppliers')->where('id', $id)->first();
        abort_unless($supplier && (FoundationAccess::administrator($actor) || $purchases->lots($actor)->where('supplier_id', $id)->exists()), 404);

        return response()->json(['data' => ['supplier' => $supplier, 'balances' => $purchases->totals($actor, $id),
            'purchases' => $purchases->documents($actor, $id)->orderByDesc('purchased_on')->orderByDesc('document_id')->paginate($filters['per_page'] ?? 15),
            'payments' => $purchases->payments($actor, $id)]])->header('Cache-Control', 'private, no-store');
    }
}
