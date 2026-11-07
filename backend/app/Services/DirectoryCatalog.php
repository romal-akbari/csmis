<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DirectoryCatalog
{
    public static function module(string $resource): string
    {
        abort_unless(in_array($resource, ['provinces', 'districts', 'areas', 'weavers', 'suppliers'], true), 404);

        return in_array($resource, ['provinces', 'districts', 'areas'], true) ? 'geography' : $resource;
    }

    public static function rules(string $resource, ?int $id): array
    {
        self::module($resource);
        $geography = in_array($resource, ['provinces', 'districts', 'areas'], true);
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:'.($geography ? 100 : 150)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'id' => ['missing'], 'created_by' => ['missing'], 'deleted_at' => ['missing'],
            'created_at' => ['missing'], 'updated_at' => ['missing'],
        ];
        if ($geography) {
            $unique = Rule::unique($resource, 'name')->ignore($id);
            if ($resource === 'districts') {
                $unique->where('province_id', request('province_id'));
                $rules['province_id'] = ['required', 'integer', Rule::exists('provinces', 'id')];
            } elseif ($resource === 'areas') {
                $unique->where('district_id', request('district_id'));
                $rules['district_id'] = ['required', 'integer', Rule::exists('districts', 'id')];
            }
            $rules['name'][] = $unique;
            $rules['name_fa'] = ['nullable', 'string', 'max:100'];
        } else {
            $rules['phone'] = ['nullable', 'string', 'min:7', 'max:30', 'regex:/\A\+?[0-9][0-9\s().-]+\z/'];
            $rules['notes'] = ['nullable', 'string', 'max:1000'];
            if ($resource === 'weavers') {
                $rules['area_id'] = ['required', 'integer', Rule::exists('areas', 'id')];
                $rules['manager_employee_id'] = ['nullable', 'integer', Rule::exists('employees', 'id')];
                $today = now(DB::table('company_settings')->value('timezone') ?: 'Asia/Kabul')->toDateString();
                $rules['registration_date'] = ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:'.$today];
                $rules['province_id'] = ['missing'];
                $rules['district_id'] = ['missing'];
                $rules['manager_id'] = ['missing'];
            } else {
                $rules['address'] = ['nullable', 'string', 'max:255'];
            }
        }

        return $rules;
    }

    public static function scope(Builder $query, string $resource, User $actor): Builder
    {
        if ($resource === 'weavers' && ! FoundationAccess::administrator($actor)) {
            $query->whereIn('weavers.manager_employee_id', DB::table('employees')
                ->whereIn('branch_id', FoundationAccess::branchIds($actor))->select('id'));
        }

        return $query;
    }

    public static function query(string $resource, User $actor): Builder
    {
        self::module($resource);
        $query = self::scope(DB::table($resource), $resource, $actor)->select($resource.'.*');
        if ($resource === 'districts') {
            $query->leftJoin('provinces as p', 'p.id', '=', 'districts.province_id')
                ->addSelect('p.name as province_name');
        } elseif ($resource === 'areas') {
            $query->leftJoin('districts as d', 'd.id', '=', 'areas.district_id')
                ->leftJoin('provinces as p', 'p.id', '=', 'd.province_id')
                ->addSelect('d.name as district_name', 'd.province_id', 'p.name as province_name');
        } elseif ($resource === 'weavers') {
            $query->leftJoin('areas as a', 'a.id', '=', 'weavers.area_id')
                ->leftJoin('districts as d', 'd.id', '=', 'a.district_id')
                ->leftJoin('provinces as p', 'p.id', '=', 'd.province_id')
                ->leftJoin('employees as e', 'e.id', '=', 'weavers.manager_employee_id')
                ->addSelect('a.name as area_name', 'a.name_fa as area_name_fa', 'a.district_id',
                    'd.name as district_name', 'd.name_fa as district_name_fa', 'd.province_id',
                    'p.name as province_name', 'p.name_fa as province_name_fa', 'e.name as manager_name');
        }

        return $query;
    }
}
