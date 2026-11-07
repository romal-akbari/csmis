<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FoundationAccess as Access;
use App\Services\FoundationCatalog;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LedgerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Access::permit($request->user(), 'ledger.view');
        $filters = $request->validate([
            'account_id' => ['required', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $account = Access::scope(DB::table('accounts'), 'accounts', $request->user())
            ->where('id', $filters['account_id'])->first();
        abort_unless($account, 404);
        $entries = DB::table('account_transactions')->where('account_id', $account->id)
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 15);
        $balance = DB::table('v_account_balances')->where('account_id', $account->id)
            ->value('balance');

        return response()->json(['entries' => $entries, 'balance' => $balance,
            'currency' => DB::table('currencies')->find($account->currency_id)->code])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, LedgerService $ledger): JsonResponse
    {
        Access::permit($request->user(), 'ledger.create');
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'entry_type' => ['required', 'in:opening,income,expense'],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,15})(?:\.\d{1,2})?$/', 'numeric', 'gt:0'],
            'rate_to_base' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/', 'numeric', 'gt:0'],
            'transaction_date' => ['required', 'date_format:Y-m-d',
                'after_or_equal:1000-01-01',
                'before_or_equal:'.FoundationCatalog::today()],
            'description' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $key = $this->key($request);
        $result = $ledger->post($request->user(), $data, $key);

        return response()->json(['data' => $result['entry'], 'replayed' => $result['replayed']],
            $result['replayed'] ? 200 : 201);
    }

    public function reverse(Request $request, int $id, LedgerService $ledger): JsonResponse
    {
        Access::permit($request->user(), 'ledger.reverse');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $result = $ledger->reverse($request->user(), $id, $data['reason'], $this->key($request));

        return response()->json(['data' => $result['entry'], 'replayed' => $result['replayed']],
            $result['replayed'] ? 200 : 201);
    }

    private function key(Request $request): string
    {
        return validator(['idempotency_key' => $request->header('Idempotency-Key')], [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[a-zA-Z0-9_.:-]+$/'],
        ])->validate()['idempotency_key'];
    }
}
