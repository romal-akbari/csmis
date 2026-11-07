<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'csms_reset_browser',
    'database.connections.mysql.url' => null, 'database.default' => 'mysql']);
DB::purge('mysql');
if (DB::getDatabaseName() !== 'csms_reset_browser'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'csms_reset_browser') {
    throw new RuntimeException('Isolated reset verification only.');
}
$path = storage_path('framework/testing/reset-browser-before.json');
$runtime = ['cache', 'cache_locks', 'personal_access_tokens', 'sessions', 'password_reset_tokens'];
$fingerprint = function (string $database, bool $includeViews = false, bool $clearEmployeeLinks = false) use ($runtime): array {
    if (! preg_match('/^csms_reset_(browser|restore_[a-f0-9]+)$/', $database)) {
        throw new RuntimeException('Unapproved verification database.');
    }
    $data = [];
    foreach (DB::select('SHOW FULL TABLES FROM `'.$database.'`') as $row) {
        [$table, $type] = array_values((array) $row);
        if (in_array($table, $runtime, true) || (! $includeViews && $type !== 'BASE TABLE')) {
            continue;
        }
        $rows = DB::table($database.'.'.$table)->get()->map(function ($row) use ($table, $clearEmployeeLinks) {
            $row = (array) $row;
            if ($table === 'users' && $clearEmployeeLinks) {
                unset($row['employee_id']);
            }

            return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        })->all();
        sort($rows, SORT_STRING);
        $data[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
    }
    ksort($data);

    return $data;
};
if (($argv[1] ?? '') === 'capture') {
    $updated = DB::selectOne("SHOW COLUMNS FROM users WHERE Field = 'updated_at'");
    $linkedUsers = DB::table('users')->whereNotNull('employee_id')->count();
    if (! str_contains(strtolower($updated->Extra), 'on update current_timestamp')
        || $linkedUsers < 2 || ! DB::table('users')->where('username', 'reset_staff')
            ->whereNotNull('employee_id')->where('updated_at', '2001-01-01 00:00:00')->exists()) {
        throw new RuntimeException('Employee-linked legacy timestamp fixture is missing.');
    }
    $tables = $fingerprint('csms_reset_browser');
    $washing = ['washing_orders', 'washing_order_items', 'washing_bills',
        'washing_bill_allocations', 'washing_payments', 'washing_bill_voids'];
    foreach ($washing as $table) {
        if (($tables[$table]['rows'] ?? 0) === 0) {
            throw new RuntimeException('Populated washing reset coverage is missing in '.$table);
        }
    }
    file_put_contents($path, json_encode(['tables' => $tables,
        'preserved_users' => $fingerprint('csms_reset_browser', false, true)['users'],
        'linked_users' => $linkedUsers, 'washing_tables' => $washing], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Isolated reset baseline captured.\n";
    exit;
}
$id = $argv[2] ?? '';
if (($argv[1] ?? '') !== 'verify' || ! preg_match('/^[a-f0-9-]{36}$/', $id)) {
    throw new RuntimeException('Use capture or verify with a reset UUID.');
}
$baseline = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$before = $baseline['tables'];
$after = $fingerprint('csms_reset_browser');
$auth = ['users', 'roles', 'permissions', 'user_roles', 'role_permissions', 'migrations'];
foreach ($after as $table => $data) {
    if (in_array($table, $auth, true)) {
        $expected = $table === 'users' ? $baseline['preserved_users'] : $before[$table];
        $actual = $table === 'users' ? $fingerprint('csms_reset_browser', false, true)['users'] : $data;
        if ($actual !== $expected) {
            throw new RuntimeException('Authentication changed in '.$table);
        }
    } elseif ($table === 'company_settings') {
        if (DB::table($table)->value('base_currency_id') !== null) {
            throw new RuntimeException('Base currency was not cleared.');
        }
    } elseif ($data['rows'] !== 0) {
        throw new RuntimeException('Business data remained in '.$table);
    }
}
if (DB::table('users')->whereNotNull('employee_id')->exists()
    || (int) $baseline['linked_users'] < 2) {
    throw new RuntimeException('Employee links were not cleared with user data preserved.');
}
if (DB::table('personal_access_tokens')->count() !== 0
    || is_dir(storage_path('app/reset-browser/carpets'))) {
    throw new RuntimeException('Sessions or active uploaded images remained.');
}
$directory = storage_path('app/reset-browser-backups/'.$id);
$receipt = json_decode(file_get_contents($directory.'/reset.json'), true, 512, JSON_THROW_ON_ERROR);
$sql = $directory.'/'.$receipt['database_backup'];
if (hash_file('sha256', $sql) !== $receipt['database_sha256']) {
    throw new RuntimeException('Backup checksum mismatch.');
}
if (! is_dir($directory.'/carpets') || count(glob($directory.'/carpets/*')) < 1) {
    throw new RuntimeException('Uploaded image backup missing.');
}
$restore = 'csms_reset_restore_'.bin2hex(random_bytes(6));
DB::statement('CREATE DATABASE `'.$restore.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config = DB::connection()->getConfig();
$binary = env('MYSQL_BINARY', PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/mysql/bin/mysql.exe' : 'mysql');
$process = new Process([$binary, '--host='.$config['host'], '--port='.$config['port'],
    '--user='.$config['username'], '--default-character-set=utf8mb4', $restore],
    null, ['MYSQL_PWD' => $config['password'] ?? '']);
$stream = fopen($sql, 'rb');
$process->setInput($stream);
$process->setTimeout(120);
$process->mustRun();
fclose($stream);
if ($fingerprint($restore) !== $before) {
    throw new RuntimeException('The restored backup does not match the pre-reset baseline.');
}
$result = ['verified_at' => now()->toIso8601String(), 'reset_id' => $id,
    'authentication_preserved' => true, 'business_data_cleared' => true,
    'legacy_user_timestamps_preserved' => true, 'employee_links_cleared' => true,
    'populated_washing_tables_cleared' => count($baseline['washing_tables']),
    'uploaded_images_archived' => true, 'backup_restore_matched' => true,
    'matched_backup_tables' => count($before), 'isolated_restore_database' => $restore];
file_put_contents(storage_path('framework/testing/reset-browser-verification.json'),
    json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
