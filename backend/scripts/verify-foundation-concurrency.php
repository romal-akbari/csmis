<?php

use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// This harness can never write to the business database.
config(['database.connections.mysql.database' => 'csms_phase1_browser',
    'database.connections.mysql.url' => null, 'database.default' => 'mysql']);
DB::purge('mysql');
$actor = User::where('username', 'browser_admin')->firstOrFail();
auth()->setUser($actor);

if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    while (microtime(true) < (float) $argv[4]) {
        usleep(1000);
    }
    try {
        $result = app(LedgerService::class)->post($actor, $data, $argv[3]);
        echo $result['replayed'] ? 'replayed' : 'created';
    } catch (ValidationException $error) {
        echo 'rejected';
    }
    exit;
}
$suffix = strtoupper(bin2hex(random_bytes(4)));
$branch = DB::table('branches')->insertGetId(['code' => 'RACE-'.$suffix, 'name' => 'Concurrency test']);
$account = DB::table('accounts')->insertGetId(['code' => 'RACE-'.$suffix,
    'name' => 'Concurrency cash', 'branch_id' => $branch,
    'currency_id' => DB::table('company_settings')->value('base_currency_id')]);
$base = ['account_id' => $account, 'entry_type' => 'opening', 'amount' => '100.00',
    'rate_to_base' => '1', 'transaction_date' => now('Asia/Kabul')->toDateString(),
    'description' => 'Concurrency verification'];
app(LedgerService::class)->post($actor, $base, 'race-open-'.$suffix);
$run = function (array $data, array $keys) {
    $start = (string) (microtime(true) + 1);
    $processes = [];
    foreach ($keys as $key) {
        $process = new Process([PHP_BINARY, __FILE__, 'worker',
            base64_encode(json_encode($data)), $key, $start]);
        $process->setTimeout(30);
        $process->start();
        $processes[] = $process;
    }
    $results = [];
    foreach ($processes as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Concurrent worker failed: '.$process->getErrorOutput());
        }
        $results[] = trim($process->getOutput());
    }
    sort($results);

    return $results;
};
$sameKey = $run(array_replace($base, ['entry_type' => 'income', 'amount' => '0.20']),
    ['race-same-'.$suffix, 'race-same-'.$suffix]);
$spending = $run(array_replace($base, ['entry_type' => 'expense', 'amount' => '80.00']),
    ['race-out-a-'.$suffix, 'race-out-b-'.$suffix]);
$balance = DB::table('v_account_balances')->where('account_id', $account)->value('balance');
$entries = DB::table('account_transactions')->where('account_id', $account)->count();
if ($sameKey !== ['created', 'replayed'] || $spending !== ['created', 'rejected']
    || $balance !== '20.20' || $entries !== 3) {
    throw new RuntimeException('Concurrency invariants failed.');
}
echo json_encode(['same_key' => $sameKey, 'competing_expenses' => $spending,
    'balance' => $balance, 'entries' => $entries], JSON_PRETTY_PRINT).PHP_EOL;
