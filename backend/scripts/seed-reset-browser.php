<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'csms_reset_browser',
    'database.connections.mysql.url' => null, 'database.default' => 'mysql']);
DB::purge('mysql');
if (DB::getDatabaseName() !== 'csms_reset_browser'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'csms_reset_browser') {
    throw new RuntimeException('Reset browser database only.');
}
// Reproduce the deployed legacy schema. MySQL DDL must stay outside transactions.
if (DB::transactionLevel() !== 0) {
    throw new RuntimeException('Legacy reset fixture requires an outermost connection.');
}
$updated = DB::selectOne("SHOW COLUMNS FROM users WHERE Field = 'updated_at'");
if (! str_contains(strtolower($updated->Extra), 'on update current_timestamp')) {
    DB::statement('ALTER TABLE users MODIFY updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
}
DB::table('branches')->insertOrIgnore(['code' => 'RESET-USER-LINK', 'name' => 'Reset employee branch']);
$employeeBranch = DB::table('branches')->where('code', 'RESET-USER-LINK')->value('id');
if (! DB::table('company_settings')->value('base_currency_id')) {
    $base = DB::table('currencies')->where('code', 'USD')->value('id');
    if ($base) {
        DB::table('company_settings')->where('id', 1)->update(['base_currency_id' => $base]);
        DB::table('currencies')->where('id', $base)->update(['is_default' => true]);
    }
}
foreach (['reset_admin' => 'Administrator', 'reset_staff' => 'Branch Manager'] as $name => $role) {
    $user = User::updateOrCreate(['username' => $name], ['name' => 'Reset test '.$role,
        'password_hash' => Hash::make('ResetBrowserOnly!42'), 'locale' => 'en', 'is_active' => true]);
    $user->roles()->sync([Role::where('name', $role)->firstOrFail()->id]);
    DB::table('employees')->updateOrInsert(['name' => 'Reset linked '.$name],
        ['branch_id' => $employeeBranch, 'status' => 'active']);
    $employee = DB::table('employees')->where('name', 'Reset linked '.$name)->value('id');
    // The staff user remains dated in the past even after the administrator signs in,
    // so an implicit timestamp change cannot accidentally pass within the same second.
    DB::table('users')->where('id', $user->id)->update([
        'employee_id' => $employee, 'updated_at' => '2001-01-01 00:00:00',
    ]);
    DB::table('user_branches')->insertOrIgnore(['user_id' => $user->id, 'branch_id' => $employeeBranch]);
    $user->tokens()->delete();
    RateLimiter::clear(md5('login'.$name.'|127.0.0.1'));
    RateLimiter::clear(md5('system-reset-preview'.'reset-preview:'.$user->id));
    RateLimiter::clear(md5('system-reset'.'reset-submit:'.$user->id));
}
echo "Isolated reset users ready.\n";
