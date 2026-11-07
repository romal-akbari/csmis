<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

class Phase2FoundationApiTest extends ApiTestCase
{
    public function test_reference_directories_archive_restore_filter_and_keep_audit_history(): void
    {
        $this->actingAsAdmin();
        foreach (['categories', 'expense-categories', 'facilities'] as $resource) {
            $data = ['name' => 'Phase Two '.$resource, 'status' => 'active'];
            $id = $this->postJson('/api/foundation/'.$resource, $data)
                ->assertCreated()->json('data.id');
            $this->putJson('/api/foundation/'.$resource.'/'.$id,
                array_replace($data, ['status' => 'inactive']))->assertOk();
            $this->getJson('/api/foundation/'.$resource.'?search=Phase%20Two&status=active')
                ->assertOk()->assertJsonPath('total', 0);
            $this->getJson('/api/foundation/'.$resource.'?search=Phase%20Two&status=inactive')
                ->assertOk()->assertJsonPath('total', 1);
            $this->putJson('/api/foundation/'.$resource.'/'.$id, $data)->assertOk();
            $table = match ($resource) {
                'categories' => 'carpet_categories',
                'expense-categories' => 'expense_categories',
                default => 'washing_facilities',
            };
            $this->assertDatabaseHas($table, ['id' => $id, 'status' => 'active']);
            $this->assertSame(3, DB::table('audit_logs')->where('table_name', $table)
                ->where('record_id', $id)->count());
            $this->getJson('/api/foundation/'.$resource.'?status=unknown')->assertUnprocessable();
        }
    }

    public function test_facility_archive_requires_archiving_its_active_stock_locations_first(): void
    {
        $this->actingAsAdmin();
        $data = ['name' => 'Active washing facility', 'status' => 'active'];
        $id = $this->postJson('/api/foundation/facilities', $data)
            ->assertCreated()->json('data.id');
        $location = ['code' => 'PHASE2-WASH', 'name' => 'Washing custody',
            'washing_facility_id' => $id, 'branch_id' => null,
            'location_type' => 'washing', 'status' => 'active'];
        $locationId = $this->postJson('/api/foundation/locations', $location)
            ->assertCreated()->json('data.id');
        $inactive = array_replace($data, ['status' => 'inactive']);
        $this->putJson('/api/foundation/facilities/'.$id, $inactive)
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('washing_facilities', ['id' => $id, 'status' => 'active']);
        $this->putJson('/api/foundation/locations/'.$locationId,
            array_replace($location, ['status' => 'inactive']))->assertOk();
        $this->putJson('/api/foundation/facilities/'.$id, $inactive)->assertOk();
        $this->assertDatabaseHas('stock_locations', ['id' => $locationId]);
    }

    public function test_manager_branch_changes_cannot_silently_move_weaver_access(): void
    {
        $this->actingAsAdmin();
        $branches = [];
        foreach (['ONE', 'TWO'] as $code) {
            $branches[] = DB::table('branches')->insertGetId(['code' => 'MANAGER-'.$code, 'name' => $code]);
        }
        $data = ['name' => 'Assigned manager', 'branch_id' => $branches[0], 'status' => 'active'];
        $manager = $this->postJson('/api/foundation/employees', $data)->assertCreated()->json('data.id');
        $province = DB::table('provinces')->insertGetId(['name' => 'Manager province']);
        $district = DB::table('districts')->insertGetId(['name' => 'Manager district', 'province_id' => $province]);
        $area = DB::table('areas')->insertGetId(['name' => 'Manager area', 'district_id' => $district]);
        $weaver = DB::table('weavers')->insertGetId(['name' => 'Assigned weaver', 'area_id' => $area,
            'manager_employee_id' => $manager, 'registration_date' => '2026-01-01']);
        $this->putJson('/api/foundation/employees/'.$manager, array_replace($data, ['branch_id' => $branches[1]]))
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->putJson('/api/foundation/employees/'.$manager, array_replace($data, ['status' => 'inactive']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('employees', ['id' => $manager, 'branch_id' => $branches[0], 'status' => 'active']);
        DB::table('weavers')->where('id', $weaver)->update(['manager_employee_id' => null]);
        $this->putJson('/api/foundation/employees/'.$manager, array_replace($data, ['branch_id' => $branches[1]]))
            ->assertOk();
    }
}
