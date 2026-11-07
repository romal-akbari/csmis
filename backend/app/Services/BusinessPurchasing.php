<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class BusinessPurchasing
{
    public function purchase(User $actor, array $data, object $settings, string $key): array
    {
        $location = BusinessSupport::location($actor, $data['location_id'], (int) $data['branch_id']);
        $payment = $data['payment'] ?? null;
        unset($data['payment'], $data['location_id'], $data['seller_type']);
        $data['purchase_no'] = DocumentNumbers::next('PUR');
        $inventory = app(InventoryService::class);
        $purchase = $inventory->purchase($actor, $data, $settings);
        $carpets = InventoryService::carpets($actor)->whereIn('s.id', array_column($data['items'], 'carpet_id'))->get();
        $receipt = $inventory->move($actor, ['receipt_no' => DocumentNumbers::next('REC'),
            'location_id' => $location->id, 'received_on' => $data['purchase_on'],
            'items' => $carpets->map(fn ($c) => ['carpet_id' => $c->id, 'expected_sequence' => $c->sequence_no])->all()], false);
        if ($payment) {
            FoundationAccess::permit($actor, 'purchasing.pay');
            $this->pay($actor, $purchase['id'], validator($payment, BusinessRules::payment())->validate(), $key);
        }

        return ['id' => $purchase['id'], 'purchase_no' => $data['purchase_no'], 'receipt_id' => $receipt['id'], 'location_name' => $location->name];
    }

    public function pay(User $actor, int $id, array $data, string $key): array
    {
        $purchase = BusinessSupport::document($actor, 'weaver_purchases', $id);
        $balance = DB::table('v_weaver_purchase_balances')->where('purchase_id', $id)->first();
        abort_if(DB::table('purchase_voids')->where('weaver_purchase_id', $id)->exists(), 422, 'A voided purchase cannot be paid.');
        if ($purchase->weaver_id) {
            FoundationAccess::permit($actor, 'weaver-payments.create');

            return app(InventoryService::class)->payment($actor, [
                'payment_no' => DocumentNumbers::next('WPAY'), 'weaver_id' => $purchase->weaver_id,
                'branch_id' => $purchase->branch_id, 'account_id' => $data['account_id'],
                'payment_type' => 'purchase', 'amount' => $data['amount'], 'rate_to_base' => $data['rate_to_base'],
                'payment_on' => $data['payment_on'], 'reason' => $data['reason'],
                'allocations' => [['weaver_purchase_id' => $id, 'amount_in_payment_currency' => $data['amount'],
                    'purchase_units_per_payment_unit' => $data['units_per_payment_unit']]],
            ], $key);
        }
        $entry = BusinessSupport::money($actor, $purchase, $data, $key, 'out', (string) $balance->remaining_amount);
        $payment = BusinessSupport::insert($actor, 'supplier_carpet_payments', $entry + ['purchase_id' => $id, 'payment_no' => DocumentNumbers::next('SPAY')]);

        return ['id' => $payment];
    }

    public function expense(User $actor, array $data, object $settings, string $key): array
    {
        app(InventoryService::class)->branch($actor, $data['branch_id']);
        $currency = BusinessSupport::currency($data, $settings);
        InventoryService::precision($data['amount'], $currency, 'amount');
        if (! DB::table('expense_categories')->where('id', $data['expense_category_id'])->where('status', 'active')->exists()) {
            InventoryService::fail('expense_category_id', 'Choose an active expense category.');
        }
        $payment = $data['payment'] ?? null;
        unset($data['payment']);
        if (! empty($data['invoice_no']) && DB::table('operating_expenses')->where('payee', $data['payee'])->where('invoice_no', $data['invoice_no'])->exists()) {
            InventoryService::fail('invoice_no', 'This invoice is already recorded for this payee, including voided vouchers.');
        }
        $data['expense_no'] = DocumentNumbers::next('EXP');
        $id = BusinessSupport::insert($actor, 'operating_expenses', $data);
        if ($payment) {
            FoundationAccess::permit($actor, 'expenses.pay');
            $this->expensePay($actor, $id, validator($payment, BusinessRules::payment())->validate(), $key);
        }

        return ['id' => $id, 'expense_no' => $data['expense_no']];
    }

    public function expensePay(User $actor, int $id, array $data, string $key): array
    {
        $expense = BusinessSupport::document($actor, 'operating_expenses', $id);
        abort_if(DB::table('business_voids')->where('expense_id', $id)->exists(), 422, 'A voided expense cannot be paid.');
        $remaining = bcsub($expense->amount, BusinessSupport::paid('operating_expense_payments', 'expense_id', $id), 2);
        $entry = BusinessSupport::money($actor, $expense, $data, $key, 'out', $remaining);

        return ['id' => BusinessSupport::insert($actor, 'operating_expense_payments', $entry + ['expense_id' => $id])];
    }

    public function expenseVoid(User $actor, int $id, array $data): array
    {
        $expense = BusinessSupport::document($actor, 'operating_expenses', $id);
        abort_if(DB::table('business_voids')->where('expense_id', $id)->exists(), 409, 'This expense is already void.');
        if (bccomp(BusinessSupport::paid('operating_expense_payments', 'expense_id', $id), '0', 2) !== 0 || $data['voided_on'] < $expense->expense_on) {
            InventoryService::fail('reason', 'Reverse active payments first and choose a date on or after the expense.');
        }

        return ['id' => BusinessSupport::insert($actor, 'business_voids', $data + ['expense_id' => $id])];
    }
}
