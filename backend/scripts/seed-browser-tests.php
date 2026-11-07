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
config(['database.connections.mysql.database' => 'csms_phase1_browser',
    'database.connections.mysql.url' => null, 'database.default' => 'mysql']);
DB::purge('mysql');
DB::table('branches')->updateOrInsert(
    ['code' => 'BROWSER-FIXTURE'],
    ['name' => 'Browser fixture', 'is_main' => false, 'status' => 'active'],
);

// These credentials exist only in the isolated browser test database.
foreach (['browser_admin' => 'Administrator', 'browser_sales' => 'Sales User',
    'browser_rtl' => 'Administrator', 'browser_sidebar' => 'Administrator'] as $username => $role) {
    $user = User::updateOrCreate(['username' => $username], [
        'name' => 'Browser Test '.$role,
        'password_hash' => Hash::make('BrowserOnlyTest!42'),
        'locale' => 'en', 'is_active' => true,
    ]);
    $user->roles()->sync([Role::where('name', $role)->firstOrFail()->id]);
    $user->tokens()->delete();
    RateLimiter::clear(md5('login'.$username.'|127.0.0.1'));
}
echo "Isolated browser fixtures ready.\n";
