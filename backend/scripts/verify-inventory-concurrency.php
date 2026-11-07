<?php

use App\Models\User;
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
    [$operation, $data] = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    while (microtime(true) < (float) $argv[4]) {
        usleep(1000);
    }
    try {
        $service = app(InventoryService::class);
        $result = $service->command($actor, $operation, $data, $argv[3], fn () => $operation === 'transfer'
            ? $service->move($actor, $data, true) : $service->payment($actor, $data, $argv[3]));
        echo $result['replayed'] ? 'replayed' : 'created';
    } catch (ValidationException $error) {
        echo 'rejected';
    } catch (HttpExceptionInterface $error) {
        if ($error->getStatusCode() !== 409) {
            throw $error;
        }
        echo 'stale';
    }
    exit;
}
ob_start();
require __DIR__.'/seed-inventory-browser.php';
$f = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
if (DB::getDatabaseName() !== 'csms_phase1_browser') {
    throw new RuntimeException('Isolated browser database only.');
}
$service = app(InventoryService::class);
$command = fn ($operation, $data, $work) => $service->command($actor, $operation, $data, 'race-'.bin2hex(random_bytes(12)), $work)['data'];
$carpets = [];
foreach ([1, 2] as $n) {
    $data = ['carpet_code' => 'RACE-'.$f['suffix'].'-'.$n, 'name' => 'Race carpet', 'registration_branch_id' => $f['branch'], 'category_id' => $f['category']];
    $carpets[] = $command('carpet', $data, fn () => $service->carpet($actor, $data))['id'];
}
$data = ['purchase_no' => 'RACE-P-'.$f['suffix'], 'weaver_id' => $f['weaver'], 'branch_id' => $f['branch'],
    'purchase_on' => '2026-01-02', 'currency_id' => $f['currency'], 'rate_to_base' => '1',
    'items' => [['carpet_id' => $carpets[0], 'unit_price' => '100.10'], ['carpet_id' => $carpets[1], 'unit_price' => '200.20']]];
$purchase = $command('purchase', $data, fn ($settings) => $service->purchase($actor, $data, $settings));
$data = ['receipt_no' => 'RACE-R-'.$f['suffix'], 'location_id' => $f['location'], 'received_on' => '2026-01-03',
    'items' => array_map(fn ($id) => ['carpet_id' => $id, 'expected_sequence' => 1], $carpets)];
$command('receipt', $data, fn () => $service->move($actor, $data, false));
$run = function (string $operation, array $requests, array $keys) {
    $start = (string) (microtime(true) + 1);
    $processes = [];
    foreach ($keys as $index => $key) {
        $process = new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode([$operation, $requests[$index]], JSON_THROW_ON_ERROR)), $key, $start]);
        $process->setTimeout(30);
        $process->start();
        $processes[] = $process;
    }
    $results = [];
    foreach ($processes as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Worker failed: '.$process->getErrorOutput().$process->getOutput());
        }
        $results[] = trim($process->getOutput());
    }
    sort($results);

    return $results;
};
$transfer = ['transfer_no' => 'RACE-T1-'.$f['suffix'], 'from_location_id' => $f['location'], 'to_location_id' => $f['destination'],
    'transferred_on' => '2026-01-04', 'items' => [['carpet_id' => $carpets[0], 'expected_sequence' => 2]]];
$competingTransfer = $transfer;
$competingTransfer['transfer_no'] = 'RACE-T2-'.$f['suffix'];
$competing = $run('transfer', [$transfer, $competingTransfer], ['race-t-a-'.$f['suffix'], 'race-t-b-'.$f['suffix']]);
$transfer['transfer_no'] = 'RACE-T3-'.$f['suffix'];
$transfer['items'][0]['carpet_id'] = $carpets[1];
$sameTransfer = $run('transfer', [$transfer, $transfer], ['race-t-same-'.$f['suffix'], 'race-t-same-'.$f['suffix']]);
$payment = ['payment_no' => 'RACE-PAY1-'.$f['suffix'], 'branch_id' => $f['branch'], 'weaver_id' => $f['weaver'],
    'account_id' => $f['account'], 'payment_type' => 'purchase', 'payment_on' => '2026-01-04', 'amount' => '200',
    'rate_to_base' => '1', 'reason' => 'Concurrent settlement', 'allocations' => [['weaver_purchase_id' => $purchase['id'],
        'amount_in_payment_currency' => '200', 'purchase_units_per_payment_unit' => '1']]];
$second = $payment;
$second['payment_no'] = 'RACE-PAY2-'.$f['suffix'];
$overpay = $run('payment', [$payment, $second], ['race-p-a-'.$f['suffix'], 'race-p-b-'.$f['suffix']]);
$payment['payment_no'] = 'RACE-PAY3-'.$f['suffix'];
$payment['amount'] = '20';
$payment['allocations'][0]['amount_in_payment_currency'] = '20';
$samePayment = $run('payment', [$payment, $payment], ['race-p-same-'.$f['suffix'], 'race-p-same-'.$f['suffix']]);
$remaining = DB::table('v_weaver_purchase_balances')->where('purchase_id', $purchase['id'])->value('remaining_amount');
$cash = DB::table('v_account_balances')->where('account_id', $f['account'])->value('balance');
$events = DB::table('stock_events')->whereIn('carpet_id', $carpets)->count();
if ($competing !== ['created', 'stale'] || $sameTransfer !== ['created', 'replayed'] || $overpay !== ['created', 'rejected']
    || $samePayment !== ['created', 'replayed'] || $remaining !== '80.30' || $cash !== '4780.00' || $events !== 6) {
    throw new RuntimeException('Inventory concurrency invariants failed.');
}
echo json_encode(compact('competing', 'sameTransfer', 'overpay', 'samePayment', 'remaining', 'cash', 'events'), JSON_PRETTY_PRINT).PHP_EOL;
