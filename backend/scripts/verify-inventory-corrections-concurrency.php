<?php

use App\Models\User;
use App\Services\InventoryCorrectionService;
use App\Services\InventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') === 'worker') {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.connections.mysql.database' => 'csms_phase1_browser', 'database.connections.mysql.url' => null]);
    DB::purge('mysql');
    $actor = User::where('username', 'browser_admin')->firstOrFail();
    auth()->setUser($actor);
    [$operation, $data, $id] = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    while (microtime(true) < (float) $argv[4]) {
        usleep(1000);
    }
    try {
        $inventory = app(InventoryService::class);
        $corrections = app(InventoryCorrectionService::class);
        $result = $inventory->command($actor, $operation.':'.$id, $data, $argv[3], fn ($settings) => match ($operation) {
            'post-draft' => $corrections->finalizeDraft($actor, $id, $data, $settings, true),
            'void' => $corrections->void($actor, $id, $data), 'receipt' => $inventory->move($actor, $data, false),
            'adjustment' => $corrections->adjust($actor, $data), 'refund' => $corrections->refund($actor, $data, $argv[3]),
        });
        echo $result['replayed'] ? 'replayed' : 'created';
    } catch (ValidationException $error) {
        echo 'rejected';
    } catch (HttpExceptionInterface $error) {
        if ($error->getStatusCode() !== 409) {
            throw $error;
        } echo 'stale';
    }
    exit;
}
ob_start();
require __DIR__.'/seed-inventory-browser.php';
$f = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
if (DB::getDatabaseName() !== 'csms_phase1_browser') {
    throw new RuntimeException('Isolated browser database only.');
}
$inventory = app(InventoryService::class);
$corrections = app(InventoryCorrectionService::class);
$command = fn ($operation, $data, $work) => $inventory->command($actor, $operation, $data, 'cor-race-'.bin2hex(random_bytes(12)), $work)['data'];
$carpets = [];
foreach ([1, 2, 3, 4] as $n) {
    $data = ['carpet_code' => 'COR-RACE-'.$f['suffix'].'-'.$n, 'name' => 'Correction race carpet', 'registration_branch_id' => $f['branch'], 'category_id' => $f['category']];
    $carpets[] = $command('carpet', $data, fn () => $inventory->carpet($actor, $data))['id'];
}
$purchaseData = ['purchase_no' => 'COR-D-'.$f['suffix'], 'branch_id' => $f['branch'], 'weaver_id' => $f['weaver'], 'currency_id' => $f['currency'], 'purchase_on' => '2026-01-02', 'rate_to_base' => '1',
    'items' => [['carpet_id' => $carpets[0], 'unit_price' => '100'], ['carpet_id' => $carpets[1], 'unit_price' => '200']]];
$draft = $command('draft', $purchaseData, fn () => $corrections->draft($actor, $purchaseData));
$run = function (array $requests, array $keys) {
    $start = (string) (microtime(true) + 1);
    $processes = [];
    foreach ($requests as $n => $request) {
        $process = new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($request, JSON_THROW_ON_ERROR)), $keys[$n], $start]);
        $process->setTimeout(30);
        $process->start();
        $processes[] = $process;
    }
    $results = [];
    foreach ($processes as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput().$process->getOutput());
        } $results[] = trim($process->getOutput());
    }
    sort($results);

    return $results;
};
$draftRace = $run([['post-draft', ['version' => 1], $draft['id']], ['post-draft', ['version' => 1], $draft['id']]], ['d-a-'.$f['suffix'], 'd-b-'.$f['suffix']]);
$purchase = DB::table('purchase_drafts')->where('id', $draft['id'])->value('posted_purchase_id');
$items = array_map(fn ($carpet) => ['carpet_id' => $carpet, 'expected_sequence' => 1], [$carpets[0], $carpets[1]]);
$void = ['voided_on' => '2026-01-03', 'reason' => 'Competing cancellation', 'items' => $items];
$receipt = ['receipt_no' => 'COR-R-'.$f['suffix'], 'location_id' => $f['location'], 'received_on' => '2026-01-03', 'items' => $items];
$voidReceiptRace = $run([['void', $void, $purchase], ['receipt', $receipt, 0]], ['v-'.$f['suffix'], 'r-'.$f['suffix']]);
// A separate paid acquisition creates a genuine refundable credit.
$purchaseData['purchase_no'] = 'COR-P-'.$f['suffix'];
$purchaseData['items'] = [['carpet_id' => $carpets[2], 'unit_price' => '200']];
$paid = $command('purchase', $purchaseData, fn ($settings) => $inventory->purchase($actor, $purchaseData, $settings));
$pay = ['payment_no' => 'COR-PAY-'.$f['suffix'], 'branch_id' => $f['branch'], 'weaver_id' => $f['weaver'], 'account_id' => $f['account'], 'payment_type' => 'purchase', 'amount' => '200', 'rate_to_base' => '1', 'payment_on' => '2026-01-03', 'reason' => 'Genuine race payment', 'allocations' => [['weaver_purchase_id' => $paid['id'], 'amount_in_payment_currency' => '200', 'purchase_units_per_payment_unit' => '1']]];
$command('payment', $pay, fn () => $inventory->payment($actor, $pay, 'cor-pay-'.$f['suffix']));
$void['items'] = [['carpet_id' => $carpets[2], 'expected_sequence' => 1]];
$command('void', $void, fn () => $corrections->void($actor, $paid['id'], $void));
$refund = ['refund_no' => 'COR-RF1-'.$f['suffix'], 'weaver_purchase_id' => $paid['id'], 'account_id' => $f['account'], 'amount' => '120', 'rate_to_base' => '1', 'purchase_units_per_refund_unit' => '1', 'refunded_on' => '2026-01-04', 'reason' => 'Competing genuine refund'];
$second = $refund;
$second['refund_no'] = 'COR-RF2-'.$f['suffix'];
$refundRace = $run([['refund', $refund, 0], ['refund', $second, 0]], ['rf-a-'.$f['suffix'], 'rf-b-'.$f['suffix']]);
$refund['refund_no'] = 'COR-RF3-'.$f['suffix'];
$refund['amount'] = '50';
$sameRefund = $run([['refund', $refund, 0], ['refund', $refund, 0]], ['rf-same-'.$f['suffix'], 'rf-same-'.$f['suffix']]);
// A stale stock-count correction cannot overwrite another correction.
$purchaseData['purchase_no'] = 'COR-STOCK-'.$f['suffix'];
$purchaseData['items'] = [['carpet_id' => $carpets[3], 'unit_price' => '100']];
$command('purchase', $purchaseData, fn ($settings) => $inventory->purchase($actor, $purchaseData, $settings));
$receipt['receipt_no'] = 'COR-STOCK-R-'.$f['suffix'];
$receipt['items'] = [['carpet_id' => $carpets[3], 'expected_sequence' => 1]];
$command('receipt', $receipt, fn () => $inventory->move($actor, $receipt, false));
$shelf = DB::table('stock_locations')->insertGetId(['code' => 'COR-SHELF-'.$f['suffix'], 'name' => 'Correction destination', 'branch_id' => $f['branch'], 'location_type' => 'shelf']);
$adjust = ['adjustment_no' => 'COR-A1-'.$f['suffix'], 'branch_id' => $f['branch'], 'adjustment_type' => 'missing', 'adjusted_on' => '2026-01-04', 'reason' => 'Competing physical count', 'items' => [['carpet_id' => $carpets[3], 'expected_sequence' => 2]]];
$relocate = $adjust;
$relocate['adjustment_no'] = 'COR-A2-'.$f['suffix'];
$relocate['adjustment_type'] = 'relocate';
$relocate['location_id'] = $shelf;
$adjustmentRace = $run([['adjustment', $adjust, 0], ['adjustment', $relocate, 0]], ['a-a-'.$f['suffix'], 'a-b-'.$f['suffix']]);
$remaining = DB::table('v_weaver_purchase_balances')->where('purchase_id', $paid['id'])->value('remaining_amount');
$cash = DB::table('v_account_balances')->where('account_id', $f['account'])->value('balance');
$sequence = (int) DB::table('v_current_stock')->where('id', $carpets[3])->value('sequence_no');
if ($draftRace !== ['created', 'stale'] || $voidReceiptRace !== ['created', 'stale'] || $refundRace !== ['created', 'rejected'] || $sameRefund !== ['created', 'replayed'] || $adjustmentRace !== ['created', 'stale'] || $remaining !== '-30.00' || $cash !== '4970.00' || $sequence !== 3) {
    throw new RuntimeException('Correction concurrency invariants failed.');
}
echo json_encode(compact('draftRace', 'voidReceiptRace', 'refundRace', 'sameRefund', 'adjustmentRace', 'remaining', 'cash', 'sequence'), JSON_PRETTY_PRINT).PHP_EOL;
