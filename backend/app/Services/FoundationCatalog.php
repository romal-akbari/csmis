<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Explicit allowlist: never accept arbitrary table or column names from a URL. */
class FoundationCatalog
{
    public const RESOURCES = [
        'branches' => ['branches', 'branches'],
        'locations' => ['stock_locations', 'locations'],
        'currencies' => ['currencies', 'currencies'],
        'exchange-rates' => ['exchange_rates', 'currencies'],
        'employees' => ['employees', 'employees'],
        'categories' => ['carpet_categories', 'categories'],
        'expense-categories' => ['expense_categories', 'categories'],
        'accounts' => ['accounts', 'accounts'],
        'users' => ['users', 'users'],
        'roles' => ['roles', 'users'],
        'facilities' => ['washing_facilities', 'facilities'],
    ];

    public static function resource(string $resource): array
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404);

        return self::RESOURCES[$resource];
    }

    public static function rules(string $resource, ?int $id): array
    {
        [$table] = self::resource($resource);
        $name = ['required', 'string', 'min:2', 'max:120'];
        $status = ['required', Rule::in(['active', 'inactive'])];
        $phone = ['nullable', 'string', 'max:30', 'regex:/^[+0-9()\s.\-]{5,30}$/'];
        $code = [$id ? 'required' : 'nullable', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/',
            Rule::unique($table, 'code')->ignore($id)];
        $branch = ['required', 'integer', Rule::exists('branches', 'id')
            ->where('status', 'active')->whereNull('deleted_at')];
        $currency = ['required', 'integer', Rule::exists('currencies', 'id')
            ->where('is_active', true)];
        $rules = match ($resource) {
            'branches' => [
                'name' => $name, 'code' => [...$code, 'max:20'],
                'location' => ['nullable', 'string', 'max:255'],
                'phone' => $phone, 'is_main' => ['required', 'boolean'],
                'status' => $status,
            ],
            'locations' => [
                'name' => $name, 'code' => $code,
                'branch_id' => ['nullable', ...array_slice($branch, 1)],
                'washing_facility_id' => ['nullable', 'integer',
                    Rule::exists('washing_facilities', 'id')->where('status', 'active')],
                'location_type' => ['required', Rule::in([
                    'showroom', 'warehouse', 'shelf', 'washing', 'other',
                ])], 'status' => $status,
            ],
            'currencies' => [
                'name' => [...$name, 'max:50'],
                'code' => ['required', ...array_slice($code, 1), 'size:3', 'regex:/^[A-Z]{3}$/'],
                'symbol' => ['required', 'string', 'max:8'],
                'decimal_places' => ['required', 'integer', 'between:0,2'],
                'is_active' => ['required', 'boolean'],
            ],
            'exchange-rates' => [
                'base_currency_id' => $currency,
                'quote_currency_id' => [...$currency, 'different:base_currency_id',
                    Rule::unique('exchange_rates', 'quote_currency_id')->ignore($id)
                        ->where('base_currency_id', request('base_currency_id'))
                        ->where('effective_date', request('effective_date'))],
                'rate' => ['required', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/',
                    'numeric', 'gt:0'],
                'effective_date' => ['required', 'date_format:Y-m-d',
                    'after_or_equal:1000-01-01', 'before_or_equal:'.self::today()],
            ],
            'employees' => [
                'name' => [...$name, 'max:150'], 'phone' => $phone,
                'email' => ['nullable', 'email', 'max:150'],
                'position' => ['nullable', 'string', 'max:100'],
                'branch_id' => $branch,
                'joining_date' => ['nullable', 'date_format:Y-m-d',
                    'after_or_equal:1000-01-01', 'before_or_equal:'.self::today()],
                'status' => ['required', Rule::in(['active', 'inactive', 'terminated'])],
            ],
            'categories', 'expense-categories' => [
                'name' => [...$name, 'max:100', Rule::unique($table, 'name')->ignore($id)],
                'name_fa' => ['nullable', 'string', 'max:100'], 'status' => $status,
                ...($resource === 'categories'
                    ? ['description' => ['nullable', 'string', 'max:255']] : []),
            ],
            'accounts' => [
                'name' => $name, 'code' => $code,
                'currency_id' => $currency,
                'account_type' => ['required', Rule::in(['cash', 'bank', 'mobile_money', 'other'])],
                'status' => $status,
                ...($id ? [] : [
                    'opening_amount' => ['sometimes', ...array_slice(InventoryRules::money(true), 1)],
                    'opening_on' => ['required_with:opening_amount', ...array_slice(InventoryRules::date(), 1)],
                    'opening_rate' => ['sometimes', ...array_slice(InventoryRules::rate(), 1)],
                ]),
            ],
            'users' => [
                'name' => [...$name, 'max:150'],
                'username' => ['required', 'string', 'min:3', 'max:60',
                    'regex:/^[a-zA-Z0-9_.-]+$/', Rule::unique('users')->ignore($id)],
                'email' => ['nullable', 'email', 'max:150', Rule::unique('users')->ignore($id)],
                'password' => [$id ? 'nullable' : 'required', 'string', 'min:12', 'max:72',
                    function ($attribute, $value, $fail) {
                        if (is_string($value) && strlen($value) > 72) {
                            $fail('Passwords must fit within 72 UTF-8 bytes.');
                        }
                    }],
                'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')
                    ->where('status', 'active')->whereNull('deleted_at'),
                    Rule::unique('users', 'employee_id')->ignore($id)],
                'locale' => ['required', Rule::in(['en', 'fa'])],
                'is_active' => ['required', 'boolean'],
                'role_ids' => ['required', 'array', 'max:30'],
                'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
                'branch_ids' => ['present', 'array', 'max:100'],
                'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')
                    ->where('status', 'active')->whereNull('deleted_at')],
                'facility_ids' => ['present', 'array', 'max:100'],
                'facility_ids.*' => ['integer', 'distinct',
                    Rule::exists('washing_facilities', 'id')->where('status', 'active')],
                'account_ids' => ['sometimes', 'array', 'max:500'],
                'account_ids.*' => ['integer', 'distinct', 'exists:accounts,id'],
            ],
            'roles' => [
                'name' => [...$name, 'max:60', Rule::unique('roles', 'name')->ignore($id)],
                'description' => ['nullable', 'string', 'max:255'],
                'permission_ids' => ['present', 'array', 'max:300'],
                'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
            ],
            'facilities' => [
                'name' => [...$name, 'max:150'], 'location' => ['nullable', 'string', 'max:255'],
                'contact_name' => ['nullable', 'string', 'max:150'],
                'phone' => $phone, 'status' => $status,
            ],
        };

        return $rules;
    }

    public static function clean(string $table, object $row): array
    {
        $data = (array) $row;
        unset($data['password_hash'], $data['password'], $data['remember_token']);
        if ($table === 'users') {
            foreach (['role_ids' => ['user_roles', 'role_id'],
                'branch_ids' => ['user_branches', 'branch_id'],
                'facility_ids' => ['user_washing_facilities', 'washing_facility_id'],
                'account_ids' => ['user_accounts', 'account_id']] as $key => $pivot) {
                $data[$key] = DB::table($pivot[0])->where('user_id', $row->id)
                    ->pluck($pivot[1])->map(fn ($id) => (int) $id)->all();
            }
        }
        if ($table === 'roles') {
            $data['permission_ids'] = DB::table('role_permissions')->where('role_id', $row->id)
                ->pluck('permission_id')->map(fn ($id) => (int) $id)->all();
        }
        if ($table === 'accounts') {
            $data['balance'] = DB::table('v_account_balances')
                ->where('account_id', $row->id)->value('balance') ?? '0.00';
            unset($data['opening_balance'], $data['current_balance']);
        }

        return $data;
    }

    public static function today(): string
    {
        return now(DB::table('company_settings')->where('id', 1)
            ->value('timezone') ?? 'Asia/Kabul')->toDateString();
    }
}
