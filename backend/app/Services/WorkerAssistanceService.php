<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkerAssistanceService
{
    public static function balances(int $id): array
    {
        $funded = BusinessSupport::paid('worker_assistance_fundings', 'assistance_id', $id);
        $returned = BusinessSupport::paid('worker_assistance_returns', 'assistance_id', $id);
        $recovered = BusinessSupport::paid('worker_assistance_recoveries', 'assistance_id', $id);
        $delivered = (string) DB::table('worker_assistance_deliveries')->where('assistance_id', $id)->sum('amount');
        $held = bcsub(bcsub($funded, $returned, 2), $delivered, 2);
        $advance = DB::table('worker_assistance')->where('id', $id)->value('assistance_type') === 'advance';
        $outstanding = $advance ? bcsub($delivered, $recovered, 2) : '0.00';

        return ['funded' => $funded, 'delivered' => $delivered, 'returned' => $returned, 'recovered' => $recovered,
            'with_manager' => $held, 'outstanding' => $outstanding,
            'funded_amount' => $funded, 'delivered_amount' => $delivered, 'returned_amount' => $returned,
            'recovered_amount' => $recovered, 'with_manager_amount' => $held, 'advance_outstanding' => $outstanding];
    }

    public function create(User $actor, array $data, object $settings): array
    {
        $manager = ManagerMaterialsService::manager($actor, $data['manager_id']);
        if ((int) $manager->branch_id !== (int) $data['branch_id']) {
            InventoryService::fail('manager_id', 'Choose a manager in the selected branch.');
        }
        $currency = BusinessSupport::currency($data, $settings);
        InventoryService::precision($data['amount'], $currency, 'amount');
        $data['assistance_no'] = DocumentNumbers::next('MAS');

        return ['id' => BusinessSupport::insert($actor, 'worker_assistance', $data), 'assistance_no' => $data['assistance_no']];
    }

    private function document(User $actor, int $id): object
    {
        $row = BusinessSupport::document($actor, 'worker_assistance', $id);
        // BusinessSupport::money uses a document date shared across business workflows.
        $row->ordered_on = $row->assistance_on;

        return $row;
    }

    private function chronology(int $id, string $date): void
    {
        $latest = DB::table('worker_assistance_deliveries')->where('assistance_id', $id)->max('delivered_on') ?? '';
        foreach (['fundings', 'returns', 'recoveries'] as $suffix) {
            $latest = max($latest, DB::table('worker_assistance_'.$suffix.' as p')->join('account_transactions as t', 't.id', '=', 'p.account_transaction_id')
                ->where('p.assistance_id', $id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('account_transactions as r')->whereColumn('r.reversal_of_id', 't.id'))->max('t.transaction_date') ?? '');
        }
        $created = DB::table('worker_assistance')->where('id', $id)->value('assistance_on');
        if ($date < max($created, $latest)) {
            InventoryService::fail('date', 'Date cannot precede the assistance or its latest delivery, funding, return or recovery.');
        }
    }

    public function transact(User $actor, int $id, string $action, array $data, string $key): array
    {
        $row = $this->document($actor, $id);
        $balance = self::balances($id);
        $this->chronology($id, $data['payment_on']);
        if ($action === 'recover' && $row->assistance_type !== 'advance') {
            InventoryService::fail('assistance_id', 'A bonus is not recoverable worker debt.');
        }
        $remaining = match ($action) {
            'fund' => bcsub($row->amount, bcsub($balance['funded'], $balance['returned'], 2), 2),
            'return' => $balance['with_manager'],
            'recover' => $balance['outstanding'],
            default => abort(404),
        };
        $entry = BusinessSupport::money($actor, $row, $data, $key, $action === 'fund' ? 'out' : 'in', $remaining);
        $table = match ($action) {
            'fund' => 'fundings', 'return' => 'returns', 'recover' => 'recoveries'
        };

        return ['id' => BusinessSupport::insert($actor, 'worker_assistance_'.$table, $entry + ['assistance_id' => $id])];
    }

    public function deliver(User $actor, int $id, array $data): array
    {
        $row = $this->document($actor, $id);
        $this->chronology($id, $data['delivered_on']);
        InventoryService::precision($data['amount'], DB::table('currencies')->find($row->currency_id), 'amount');
        if (bccomp($data['amount'], self::balances($id)['with_manager'], 2) > 0) {
            InventoryService::fail('amount', 'Delivery exceeds undelivered assistance funds held by this manager.');
        }

        return ['id' => BusinessSupport::insert($actor, 'worker_assistance_deliveries', $data + ['assistance_id' => $id])];
    }
}
