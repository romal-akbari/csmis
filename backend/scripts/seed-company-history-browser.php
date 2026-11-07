<?php

use App\Models\User;
use App\Services\InventoryCorrectionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'csms_phase1_browser', 'database.connections.mysql.url' => null]);
DB::purge('mysql');
if (DB::selectOne('SELECT DATABASE() name')->name !== 'csms_phase1_browser') throw new RuntimeException('Isolated browser database only.');
$fixture = json_decode($argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
$actor = User::where('username', 'browser_admin')->firstOrFail();
auth()->setUser($actor);
$result = DB::transaction(fn () => app(InventoryCorrectionService::class)->draft($actor, [
    'purchase_no' => 'HISTORY-'.$fixture['suffix'], 'branch_id' => $fixture['branch'], 'weaver_id' => $fixture['weaver'],
    'currency_id' => $fixture['currency'], 'rate_to_base' => '1', 'purchase_on' => '2026-01-02', 'notes' => 'Earlier draft retained for read-only history verification.', 'items' => [],
]));
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
