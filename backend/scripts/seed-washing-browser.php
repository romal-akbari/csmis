<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => 'csms_phase1_browser',
    'database.connections.mysql.url' => null,
]);
DB::purge('mysql');
if (DB::getDatabaseName() !== 'csms_phase1_browser') {
    throw new RuntimeException('Washing browser fixtures require the isolated browser database.');
}

// Never reset a real user's password or reuse another test's facility assignment.
$suffix = strtoupper(bin2hex(random_bytes(5)));
$fixture = DB::transaction(function () use ($suffix) {
    $facility = DB::table('washing_facilities')->insertGetId([
        'name' => 'Browser washing '.$suffix,
        'location' => 'Browser test facility',
        'contact_name' => 'Browser facility manager',
        'status' => 'active',
    ]);
    $otherFacility = DB::table('washing_facilities')->insertGetId([
        'name' => 'Other washing '.$suffix,
        'status' => 'active',
    ]);
    $facilityLocation = DB::table('stock_locations')->insertGetId([
        'code' => 'W-A-'.$suffix,
        'name' => 'Washing custody '.$suffix,
        'washing_facility_id' => $facility,
        'location_type' => 'washing',
        'status' => 'active',
    ]);
    $otherFacilityLocation = DB::table('stock_locations')->insertGetId([
        'code' => 'W-B-'.$suffix,
        'name' => 'Other custody '.$suffix,
        'washing_facility_id' => $otherFacility,
        'location_type' => 'washing',
        'status' => 'active',
    ]);
    $facilityUsername = 'browser_wash_'.$suffix;
    $user = User::create([
        'name' => 'Browser washing facility user '.$suffix,
        'username' => $facilityUsername,
        'password_hash' => Hash::make('BrowserOnlyTest!42'),
        'locale' => 'en',
        'is_active' => true,
    ]);
    $user->roles()->sync([Role::where('name', 'Washing Facility User')->firstOrFail()->id]);
    DB::table('user_washing_facilities')->insert([
        'user_id' => $user->id,
        'washing_facility_id' => $facility,
    ]);

    return compact('facility', 'otherFacility', 'facilityLocation', 'otherFacilityLocation', 'facilityUsername');
});
echo json_encode($fixture, JSON_THROW_ON_ERROR).PHP_EOL;
