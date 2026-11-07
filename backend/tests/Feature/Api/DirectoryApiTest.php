<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

class DirectoryApiTest extends HistoricalApiTestCase
{
    private function geography(string $suffix = 'One'): array
    {
        $province = DB::table('provinces')->insertGetId(['name' => 'Province '.$suffix]);
        $district = DB::table('districts')->insertGetId(['name' => 'District '.$suffix, 'province_id' => $province]);
        $area = DB::table('areas')->insertGetId(['name' => 'Area '.$suffix, 'district_id' => $district]);

        return [$province, $district, $area];
    }

    private function manager(string $code = 'DIR-ONE'): array
    {
        $branch = DB::table('branches')->insertGetId(['code' => $code, 'name' => $code]);
        $employee = DB::table('employees')->insertGetId(['name' => 'Manager '.$code, 'branch_id' => $branch]);

        return [$branch, $employee];
    }

    private function actor(string $role, array $branches = []): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());
        foreach ($branches as $branch) {
            DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $branch]);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function weaver(int $area, ?int $manager = null, array $overrides = []): array
    {
        return $overrides + ['name' => 'Test Weaver', 'phone' => '+93 701234567', 'area_id' => $area,
            'manager_employee_id' => $manager, 'registration_date' => '2026-01-01',
            'notes' => 'Hand-knotted wool', 'status' => 'active'];
    }

    public function test_geography_crud_derives_hierarchy_and_audits(): void
    {
        $this->actingAsAdmin();
        $province = $this->postJson('/api/directories/provinces', ['name' => 'Kabul', 'name_fa' => 'کابل', 'status' => 'active'])
            ->assertCreated()->json('data.id');
        $district = $this->postJson('/api/directories/districts', ['name' => 'District One', 'province_id' => $province, 'status' => 'active'])
            ->assertCreated()->json('data.id');
        $area = $this->postJson('/api/directories/areas', ['name' => 'Area One', 'district_id' => $district, 'status' => 'active'])
            ->assertCreated()->assertJsonPath('data.province_id', $province)->json('data.id');
        $this->getJson('/api/directories/areas/'.$area)->assertOk()->assertJsonPath('data.district_name', 'District One');
        $this->putJson('/api/directories/areas/'.$area, ['name' => 'Area Updated', 'district_id' => $district, 'status' => 'active'])
            ->assertOk()->assertJsonPath('data.name', 'Area Updated');
        $this->assertDatabaseHas('audit_logs', ['action' => 'create', 'table_name' => 'provinces', 'record_id' => $province]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update', 'table_name' => 'areas', 'record_id' => $area]);
        $this->assertFalse(Schema::hasColumn('weavers', 'province_id'));
        $this->assertFalse(Schema::hasColumn('weavers', 'district_id'));
    }

    public function test_required_length_name_phone_date_and_unknown_resource_validation(): void
    {
        $this->actingAsAdmin();
        foreach (['provinces', 'districts', 'areas', 'weavers', 'suppliers'] as $resource) {
            $this->postJson('/api/directories/'.$resource, [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'status']);
            $this->postJson('/api/directories/'.$resource, ['name' => ' A ', 'status' => 'active'])
                ->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        [, , $area] = $this->geography();
        $this->postJson('/api/directories/weavers', $this->weaver($area, null, [
            'name' => str_repeat('a', 151), 'phone' => 'abcdefg', 'notes' => str_repeat('a', 1001), 'registration_date' => '2026-02-30',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone', 'notes', 'registration_date']);
        foreach (['0999-12-31', '2099-01-01', '2026-1-01'] as $date) {
            $this->postJson('/api/directories/weavers', $this->weaver($area, null, ['registration_date' => $date]))
                ->assertUnprocessable()->assertJsonValidationErrors('registration_date');
        }
        foreach (['123456', '++93701234567', '+93 letters', str_repeat('9', 31)] as $phone) {
            $this->postJson('/api/directories/suppliers', ['name' => 'Supplier', 'status' => 'active', 'phone' => $phone])
                ->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->postJson('/api/directories/suppliers', ['name' => 'Supplier', 'status' => 'active', 'address' => str_repeat('a', 256)])
            ->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->postJson('/api/directories/unknown', [])->assertNotFound();
    }

    public function test_name_uniqueness_is_scoped_to_parent_and_retained_for_archives(): void
    {
        $this->actingAsAdmin();
        [$first] = $this->geography('First');
        [$second] = $this->geography('Second');
        $data = ['name' => 'Shared District', 'province_id' => $first, 'status' => 'active'];
        $id = $this->postJson('/api/directories/districts', $data)->assertCreated()->json('data.id');
        $this->postJson('/api/directories/districts', $data)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/directories/districts', array_replace($data, ['province_id' => $second]))->assertCreated();
        $this->deleteJson('/api/directories/districts/'.$id)->assertOk();
        $this->postJson('/api/directories/districts', $data)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson('/api/directories/districts/'.$id, $data)->assertOk();
    }

    public function test_hierarchy_references_and_inactive_parents_are_rejected(): void
    {
        $this->actingAsAdmin();
        [$province, $district, $area] = $this->geography();
        $this->postJson('/api/directories/districts', ['name' => 'Unknown', 'province_id' => 99999999, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('province_id');
        $this->postJson('/api/directories/areas', ['name' => 'Unknown', 'district_id' => 99999999, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('district_id');
        $this->postJson('/api/directories/weavers', $this->weaver(99999999))->assertUnprocessable()->assertJsonValidationErrors('area_id');
        DB::table('provinces')->where('id', $province)->update(['status' => 'inactive']);
        $this->postJson('/api/directories/districts', ['name' => 'New District', 'province_id' => $province, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('province_id');
        $this->postJson('/api/directories/areas', ['name' => 'New Area', 'district_id' => $district, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('district_id');
        $this->postJson('/api/directories/weavers', $this->weaver($area))->assertUnprocessable()->assertJsonValidationErrors('area_id');
        $this->getJson('/api/directories/lookups')->assertOk()->assertJsonCount(0, 'data.areas');
    }

    public function test_weaver_manager_and_geo_ids_are_derived_and_protected_from_mass_assignment(): void
    {
        $actor = $this->actingAsAdmin();
        [$province, $district, $area] = $this->geography();
        [, $manager] = $this->manager();
        $id = $this->postJson('/api/directories/weavers', $this->weaver($area, $manager))
            ->assertCreated()->assertJsonPath('data.province_id', $province)->assertJsonPath('data.district_id', $district)
            ->assertJsonPath('data.manager_name', 'Manager DIR-ONE')->json('data.id');
        $this->assertDatabaseHas('weavers', ['id' => $id, 'created_by' => $actor->id, 'manager_employee_id' => $manager]);
        foreach (['province_id' => $province, 'district_id' => $district, 'manager_id' => $manager, 'created_by' => $actor->id, 'id' => 123] as $field => $value) {
            $this->postJson('/api/directories/weavers', $this->weaver($area, $manager, [$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->putJson('/api/directories/weavers/'.$id, $this->weaver($area, null))->assertOk()->assertJsonPath('data.manager_employee_id', null);
    }

    public function test_non_admin_weavers_are_scoped_by_manager_branch_for_all_actions(): void
    {
        [, , $area] = $this->geography();
        [$ownBranch, $ownManager] = $this->manager();
        [, $otherManager] = $this->manager('DIR-TWO');
        $own = DB::table('weavers')->insertGetId($this->weaver($area, $ownManager));
        $other = DB::table('weavers')->insertGetId($this->weaver($area, $otherManager));
        DB::table('weavers')->insert($this->weaver($area));
        $this->actor('Purchase User', [$ownBranch]);
        $this->getJson('/api/directories/weavers')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $own);
        $this->getJson('/api/directories/weavers/'.$other)->assertNotFound();
        $this->putJson('/api/directories/weavers/'.$other, $this->weaver($area, $ownManager))->assertNotFound();
        $this->deleteJson('/api/directories/weavers/'.$other)->assertNotFound();
        $this->postJson('/api/directories/weavers', $this->weaver($area, $otherManager))->assertForbidden();
        $this->postJson('/api/directories/weavers', $this->weaver($area))->assertUnprocessable()->assertJsonValidationErrors('manager_employee_id');
        $this->postJson('/api/directories/weavers', $this->weaver($area, $ownManager))->assertCreated();
        $this->getJson('/api/directories/lookups')->assertOk()->assertJsonCount(1, 'data.managers')->assertJsonPath('data.managers.0.id', $ownManager);
        $this->getJson('/api/directories/lookups?weaver_id='.$other)->assertNotFound();
    }

    public function test_forbidden_fields_are_rejected_even_when_null_or_empty(): void
    {
        $actor = $this->actingAsAdmin();
        [, , $area] = $this->geography();
        $payload = $this->weaver($area);
        $id = $this->postJson('/api/directories/weavers', $payload)->assertCreated()->json('data.id');
        foreach (['id', 'created_by', 'deleted_at', 'created_at', 'updated_at', 'province_id', 'district_id', 'manager_id'] as $field) {
            foreach ([null, ''] as $value) {
                $this->postJson('/api/directories/weavers', $payload + [$field => $value])
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->putJson('/api/directories/weavers/'.$id, $payload + [$field => $value])
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->assertDatabaseHas('weavers', ['id' => $id, 'created_by' => $actor->id]);
        $this->assertSame(1, DB::table('weavers')->count());
    }

    public function test_inactive_manager_or_branch_is_rejected_even_for_admin(): void
    {
        $this->actingAsAdmin();
        [, , $area] = $this->geography();
        [$branch, $manager] = $this->manager();
        DB::table('employees')->where('id', $manager)->update(['status' => 'inactive']);
        $this->postJson('/api/directories/weavers', $this->weaver($area, $manager))
            ->assertUnprocessable()->assertJsonValidationErrors('manager_employee_id');
        DB::table('employees')->where('id', $manager)->update(['status' => 'active']);
        DB::table('branches')->where('id', $branch)->update(['status' => 'inactive']);
        $this->postJson('/api/directories/weavers', $this->weaver($area, $manager))
            ->assertUnprocessable()->assertJsonValidationErrors('manager_employee_id');
    }

    public function test_permission_denial_and_shared_suppliers_policy(): void
    {
        $this->actor('Sales User');
        $this->getJson('/api/directories/weavers')->assertForbidden();
        $this->getJson('/api/directories/suppliers')->assertForbidden();
        $this->getJson('/api/directories/provinces')->assertForbidden();
        $this->getJson('/api/directories/lookups')->assertForbidden();
        $this->actor('Purchase User');
        $this->postJson('/api/directories/provinces', ['name' => 'No rights', 'status' => 'active'])->assertForbidden();
        $this->deleteJson('/api/directories/provinces/999999')->assertForbidden();
        $id = $this->postJson('/api/directories/suppliers', ['name' => 'Shared Supplier', 'status' => 'active'])
            ->assertCreated()->json('data.id');
        $this->actor('Purchase User');
        $this->getJson('/api/directories/suppliers/'.$id)->assertOk();
        $this->putJson('/api/directories/suppliers/'.$id, ['name' => 'Supplier Updated', 'status' => 'active'])->assertOk();
    }

    public function test_archive_restore_and_status_filters_are_audited_without_physical_delete(): void
    {
        $this->actingAsAdmin();
        [, , $area] = $this->geography();
        $id = $this->postJson('/api/directories/weavers', $this->weaver($area))->assertCreated()->json('data.id');
        $this->deleteJson('/api/directories/weavers/'.$id)->assertOk();
        $this->getJson('/api/directories/weavers')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/directories/weavers?status=inactive')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/directories/weavers?status=all')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/directories/weavers/'.$id)->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->assertNotNull(DB::table('weavers')->where('id', $id)->value('deleted_at'));
        $this->putJson('/api/directories/weavers/'.$id, $this->weaver($area))->assertOk();
        $this->assertDatabaseHas('weavers', ['id' => $id, 'status' => 'active', 'deleted_at' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'delete', 'table_name' => 'weavers', 'record_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update', 'table_name' => 'weavers', 'record_id' => $id]);
        $archive = AuditLog::where('table_name', 'weavers')->where('record_id', $id)->where('action', 'delete')->firstOrFail();
        $this->assertSame('active', $archive->old_values['status']);
        $this->assertSame('inactive', $archive->new_values['status']);
        $this->assertNotNull($archive->new_values['deleted_at']);
        $restore = AuditLog::where('table_name', 'weavers')->where('record_id', $id)->where('action', 'update')->firstOrFail();
        $this->assertSame('inactive', $restore->old_values['status']);
        $this->assertSame('active', $restore->new_values['status']);
        $this->assertNull($restore->new_values['deleted_at']);
    }

    public function test_supplier_full_lifecycle_preserves_contact_and_archive_history(): void
    {
        $this->actingAsAdmin();
        $data = ['name' => 'Wool Supplier', 'phone' => '+93 701234567', 'address' => 'Kabul', 'notes' => 'Wool supplies', 'status' => 'active'];
        $id = $this->postJson('/api/directories/suppliers', $data)->assertCreated()->json('data.id');
        $updated = array_replace($data, ['address' => 'Mazar-e-Sharif', 'phone' => '+93 791234567']);
        $this->putJson('/api/directories/suppliers/'.$id, $updated)->assertOk()->assertJsonPath('data.address', 'Mazar-e-Sharif');
        $this->getJson('/api/directories/suppliers/'.$id)->assertOk()->assertJsonPath('data.phone', '+93 791234567');
        $this->deleteJson('/api/directories/suppliers/'.$id)->assertOk();
        $this->getJson('/api/directories/suppliers?status=inactive&search=Wool')->assertOk()->assertJsonPath('total', 1);
        $this->putJson('/api/directories/suppliers/'.$id, $updated)->assertOk()->assertJsonPath('data.deleted_at', null);
        $this->assertDatabaseHas('suppliers', ['id' => $id, 'status' => 'active', 'notes' => 'Wool supplies']);
        $audit = AuditLog::where('table_name', 'suppliers')->where('record_id', $id)
            ->where('action', 'update')->orderBy('id')->firstOrFail();
        $this->assertSame('Kabul', $audit->old_values['address']);
        $this->assertSame('Mazar-e-Sharif', $audit->new_values['address']);
    }

    public function test_referenced_geography_cannot_archive_or_reparent(): void
    {
        $this->actingAsAdmin();
        [$province, $district, $area] = $this->geography();
        [$otherProvince, $otherDistrict] = $this->geography('Other');
        DB::table('weavers')->insert($this->weaver($area));
        foreach (['provinces' => $province, 'districts' => $district, 'areas' => $area] as $resource => $id) {
            $this->deleteJson('/api/directories/'.$resource.'/'.$id)->assertUnprocessable()->assertJsonValidationErrors('status');
            $this->assertDatabaseHas($resource, ['id' => $id, 'status' => 'active']);
        }
        $this->putJson('/api/directories/districts/'.$district, ['name' => 'District One', 'province_id' => $otherProvince, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('province_id');
        $this->putJson('/api/directories/areas/'.$area, ['name' => 'Area One', 'district_id' => $otherDistrict, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('district_id');
    }

    public function test_pagination_search_and_filters_validate_and_keep_archives_separate(): void
    {
        $this->actingAsAdmin();
        foreach (range(1, 24) as $index) {
            DB::table('suppliers')->insert(['name' => 'Match Supplier '.$index, 'status' => $index === 24 ? 'inactive' : 'active']);
        }
        $this->getJson('/api/directories/suppliers?search=Match&per_page=10&page=2')
            ->assertOk()->assertJsonPath('current_page', 2)->assertJsonPath('total', 23)->assertJsonCount(10, 'data');
        foreach (['per_page=101', 'page=0', 'status=invalid', 'province_id=letters'] as $filter) {
            $this->getJson('/api/directories/suppliers?'.$filter)->assertUnprocessable();
        }
        $this->getJson('/api/directories/suppliers?search='.str_repeat('a', 101))->assertUnprocessable();
    }

    public function test_lookup_options_are_complete_and_selected_inactive_records_remain_visible(): void
    {
        $this->actingAsAdmin();
        [$province, $district, $area] = $this->geography();
        [, $manager] = $this->manager();
        $id = DB::table('weavers')->insertGetId($this->weaver($area, $manager));
        DB::table('provinces')->where('id', $province)->update(['status' => 'inactive']);
        DB::table('employees')->where('id', $manager)->update(['status' => 'inactive']);
        $this->getJson('/api/directories/lookups')->assertOk()->assertJsonCount(0, 'data.provinces')->assertJsonCount(0, 'data.managers');
        $this->getJson('/api/directories/lookups?weaver_id='.$id)->assertOk()
            ->assertJsonPath('data.provinces.0.id', $province)->assertJsonPath('data.districts.0.id', $district)
            ->assertJsonPath('data.areas.0.id', $area)->assertJsonPath('data.managers.0.id', $manager)
            ->assertJsonPath('data.company.0.name', DB::table('company_settings')->value('timezone'));
        $rows = array_map(fn ($i) => ['name' => 'Bulk Province '.$i], range(1, 1001));
        DB::table('provinces')->insert($rows);
        $this->getJson('/api/directories/lookups')->assertOk()->assertJsonCount(1001, 'data.provinces');
    }

    public function test_supplier_only_and_geography_only_lookups_do_not_disclose_employees(): void
    {
        $this->manager();
        foreach (['suppliers.view', 'geography.view'] as $code) {
            $user = User::factory()->create();
            $role = Role::create(['name' => 'Custom '.$code]);
            $role->permissions()->attach(Permission::where('code', $code)->value('id'));
            $user->roles()->attach($role);
            Sanctum::actingAs($user);
            $this->getJson('/api/directories/lookups')->assertOk()->assertJsonCount(0, 'data.managers')
                ->assertJsonPath('data.company.0.id', 1);
        }
    }

    public function test_update_only_role_cannot_bypass_archive_permission_through_status(): void
    {
        $id = DB::table('suppliers')->insertGetId(['name' => 'Protected Supplier']);
        $role = Role::create(['name' => 'Supplier Editor']);
        $role->permissions()->attach(Permission::whereIn('code', ['suppliers.view', 'suppliers.update'])->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
        $this->putJson('/api/directories/suppliers/'.$id, ['name' => 'Still Active', 'status' => 'active'])->assertOk();
        $this->putJson('/api/directories/suppliers/'.$id, ['name' => 'Protected Supplier', 'status' => 'inactive'])->assertForbidden();
        $this->deleteJson('/api/directories/suppliers/'.$id)->assertForbidden();
        $this->assertDatabaseHas('suppliers', ['id' => $id, 'name' => 'Still Active', 'status' => 'active']);
    }

    public function test_failed_audit_rolls_back_the_directory_mutation(): void
    {
        $this->actingAsAdmin();
        AuditLog::creating(function () {
            throw new \RuntimeException('Directory audit failed');
        });
        try {
            $this->postJson('/api/directories/suppliers', ['name' => 'Must Roll Back', 'status' => 'active'])->assertStatus(500);
            $this->assertDatabaseMissing('suppliers', ['name' => 'Must Roll Back']);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_registration_date_uses_company_calendar_day(): void
    {
        $this->actingAsAdmin();
        [, , $area] = $this->geography();
        DB::table('company_settings')->where('id', 1)->update(['timezone' => 'Asia/Kabul']);
        $this->travelTo(Carbon::parse('2026-10-17 21:00:00', 'UTC'));
        try {
            $this->postJson('/api/directories/weavers', $this->weaver($area, null, ['registration_date' => '2026-10-18']))->assertCreated();
            $this->postJson('/api/directories/weavers', $this->weaver($area, null, ['registration_date' => '2026-10-19']))
                ->assertUnprocessable()->assertJsonValidationErrors('registration_date');
        } finally {
            $this->travelBack();
        }
    }
}
