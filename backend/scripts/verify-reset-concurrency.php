<?php

use App\Models\User;
use App\Services\SystemReset;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'csms_reset_browser',
    'database.connections.mysql.url' => null, 'database.default' => 'mysql',
    'filesystems.disks.local.root' => storage_path('app/reset-browser'),
    'system_reset.backup_root' => storage_path('app/reset-browser-backups')]);
DB::purge('mysql');
if (DB::getDatabaseName() !== 'csms_reset_browser') {
    throw new RuntimeException('Reset races require the isolated reset browser database.');
}
if (($argv[1] ?? '') === 'worker') {
    $input = json_decode(file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
    while (microtime(true) < $input['start']) {
        usleep(1000);
    }
    $request = Request::create('/api/'.$input['path'], 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'], 'REMOTE_ADDR' => '127.0.0.1',
    ], json_encode($input['body'], JSON_THROW_ON_ERROR));
    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle($request);
    $body = json_decode($response->getContent(), true);
    echo json_encode(['status' => $response->getStatusCode(),
        'reset_id' => $body['data']['reset_id'] ?? null], JSON_THROW_ON_ERROR);
    $kernel->terminate($request, $response);
    exit;
}
$actor = User::where('username', 'reset_admin')->firstOrFail();
$service = app(SystemReset::class);
$startProcess = function (array $request): Process {
    $process = new Process([PHP_BINARY, __FILE__, 'worker']);
    $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
    $process->setTimeout(180);
    $process->start();

    return $process;
};
$finish = function (Process $process): array {
    $process->wait();
    if (! $process->isSuccessful()) {
        throw new RuntimeException('Reset worker failed: '.$process->getErrorOutput());
    }

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
};
$request = function () use ($actor, $service): array {
    return ['path' => 'system/reset', 'token' => $actor->createToken('reset-race')->plainTextToken,
        'start' => microtime(true) + 2, 'body' => [
            'password' => 'ResetBrowserOnly!42', 'confirmation' => 'RESET ALL DATA',
            'preview_token' => $service->preview($actor)['preview_token'],
        ]];
};
$before = (int) DB::table('company_settings')->value('reset_version');
$duplicate = $request();
$one = $startProcess($duplicate);
$two = $startProcess($duplicate);
$results = [$finish($one), $finish($two)];
$statuses = array_column($results, 'status');
sort($statuses);
if ($statuses !== [200, 401]
    || (int) DB::table('company_settings')->value('reset_version') !== $before + 1) {
    throw new RuntimeException('Concurrent reset did not execute exactly once: '.json_encode($results));
}
$reset = $request();
$first = $startProcess($reset);
$name = 'csms-write-'.substr(hash('sha256', DB::getDatabaseName()), 0, 40);
$deadline = microtime(true) + 15;
do {
    $owner = DB::selectOne('SELECT IS_USED_LOCK(?) AS owner', [$name])->owner;
    if ($owner) {
        break;
    }
    usleep(20000);
} while (microtime(true) < $deadline);
if (! $owner) {
    throw new RuntimeException('Reset write lock was not observed.');
}
$queued = $startProcess(['path' => 'customers', 'token' => $reset['token'],
    'start' => microtime(true), 'body' => ['name' => 'Queued old-session customer',
        'customer_type' => 'retail', 'registration_date' => now()->toDateString(),
        'status' => 'active']]);
$resetResult = $finish($first);
$queuedResult = $finish($queued);
if ($resetResult['status'] !== 200 || $queuedResult['status'] !== 401
    || DB::table('customers')->exists()
    || (int) DB::table('company_settings')->value('reset_version') !== $before + 2) {
    throw new RuntimeException('A queued old session crossed the reset boundary.');
}
$result = ['verified_at' => now()->toIso8601String(), 'database' => 'csms_reset_browser',
    'duplicate_reset_statuses' => $statuses, 'queued_reset_status' => $resetResult['status'],
    'queued_old_session_write_status' => $queuedResult['status'],
    'customers_after_reset' => 0, 'resets_committed' => 2];
file_put_contents(storage_path('framework/testing/reset-concurrency.json'),
    json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
