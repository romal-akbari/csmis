<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Money stays as decimal strings. No float arithmetic and no cached balances. */
class LedgerService
{
    public function post(User $actor, array $data, string $key): array
    {
        return DB::transaction(function () use ($actor, $data, $key) {
            $settings = DB::table('company_settings')->where('id', 1)
                ->lockForUpdate()->firstOrFail();
            if (! $settings->base_currency_id) {
                throw ValidationException::withMessages(['base_currency_id' =>
                    'Set the base currency in Company settings before posting transactions.']);
            }
            $account = FoundationAccess::scope(DB::table('accounts'), 'accounts', $actor)
                ->where('id', $data['account_id'])->lockForUpdate()->first();
            abort_unless($account, 404);
            $canonical = [
                'account_id' => (int) $account->id,
                'direction' => $data['entry_type'] === 'expense' ? 'out' : 'in',
                'amount' => bcadd($data['amount'], '0', 2),
                'entry_type' => $data['entry_type'],
                'transaction_date' => $data['transaction_date'],
                'description' => trim($data['description']),
                'rate_to_base' => bcadd($data['rate_to_base'], '0', 8),
                'created_by' => (int) $actor->id,
                'reversal_of_id' => null,
            ];

            return $this->create($canonical, $account, $settings, $key);
        }, 3);
    }

    public function reverse(User $actor, int $id, string $reason, string $key): array
    {
        return DB::transaction(function () use ($actor, $id, $reason, $key) {
            $settings = DB::table('company_settings')->where('id', 1)
                ->lockForUpdate()->firstOrFail();
            $original = DB::table('account_transactions')->where('id', $id)->first();
            abort_unless($original, 404);
            $account = FoundationAccess::scope(DB::table('accounts'), 'accounts', $actor)
                ->where('id', $original->account_id)->lockForUpdate()->first();
            abort_unless($account, 404);
            BusinessReversals::authorize($actor, $id);
            if (DB::table('weaver_payments')->where('account_transaction_id', $id)->exists()) {
                FoundationAccess::permit($actor, 'weaver-payments.reverse');
            }
            if (DB::table('weaver_refunds')->where('account_transaction_id', $id)->exists()) {
                FoundationAccess::permit($actor, 'weaver-refunds.reverse');
            }
            if (DB::table('washing_payments')->where('account_transaction_id', $id)->exists()) {
                FoundationAccess::permit($actor, 'washing-costs.reverse');
            }
            if ($original->reversal_of_id !== null) {
                throw ValidationException::withMessages(['reason' => 'A reversal cannot be reversed.']);
            }
            $canonical = [
                'account_id' => (int) $account->id,
                'direction' => $original->direction === 'in' ? 'out' : 'in',
                'amount' => bcadd($original->amount, '0', 2),
                'entry_type' => 'reversal', 'transaction_date' => DB::table('account_transactions')->where('idempotency_key', $key)
                    ->value('transaction_date') ?? now($settings->timezone)->toDateString(),
                'description' => trim($reason),
                'rate_to_base' => bcadd($original->rate_to_base, '0', 8),
                'created_by' => (int) $actor->id, 'reversal_of_id' => $id,
            ];

            return $this->create($canonical, $account, $settings, $key);
        }, 3);
    }

    private function create(array $data, object $account, object $settings, string $key): array
    {
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $existing = DB::table('account_transactions')->where('idempotency_key', $key)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->request_hash, $hash), 409,
                'This idempotency key belongs to a different request.');

            return ['entry' => $existing, 'replayed' => true];
        }
        if ($data['reversal_of_id'] !== null) {
            abort_if(DB::table('account_transactions')
                ->where('reversal_of_id', $data['reversal_of_id'])->exists(), 409,
                'This entry has already been reversed.');
        } else {
            if ($account->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'Choose an active account.']);
            }
        }
        $currency = DB::table('currencies')->find($account->currency_id);
        if (! $currency->is_active && $data['reversal_of_id'] === null) {
            throw ValidationException::withMessages(['account_id' => 'Account currency is inactive.']);
        }
        if (bccomp($data['amount'], bcadd($data['amount'], '0', $currency->decimal_places), 2) !== 0) {
            throw ValidationException::withMessages(['amount' => 'Amount exceeds the currency precision.']);
        }
        if ($data['entry_type'] !== 'reversal'
            && (int) $account->currency_id === (int) $settings->base_currency_id
            && bccomp($data['rate_to_base'], '1', 8) !== 0) {
            throw ValidationException::withMessages(['rate_to_base' => 'Base-currency entries must use a rate of 1.']);
        }
        if ($data['entry_type'] === 'opening'
            && DB::table('account_transactions')->where('account_id', $account->id)->exists()) {
            throw ValidationException::withMessages(['entry_type' => 'Opening balance must be the first entry.']);
        }
        $balance = (string) DB::table('v_account_balances')
            ->where('account_id', $account->id)->value('balance');
        if ($data['direction'] === 'out' && bccomp($balance, $data['amount'], 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => 'Insufficient balance. Account overdrafts are disabled.',
            ]);
        }
        // Preserve the imported columns for compatibility; they are not read-model truth.
        $id = DB::table('account_transactions')->insertGetId($data + [
            'idempotency_key' => $key, 'request_hash' => $hash,
            'currency_id' => $account->currency_id,
            'exchange_rate' => $data['rate_to_base'],
            'ref_type' => $data['entry_type'] === 'income' ? 'other_income' : 'adjustment',
            'ref_id' => null, 'created_at' => now(),
        ]);
        $saved = DB::table('account_transactions')->find($id);
        AuditLogger::log('create', 'account_transactions', $id, null, (array) $saved);

        return ['entry' => $saved, 'replayed' => false];
    }
}
