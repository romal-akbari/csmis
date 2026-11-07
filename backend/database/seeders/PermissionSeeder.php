<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * §17 — Seeds the permission catalogue and built-in role grants.
 *
 * Re-running restores built-in grants. Custom roles are preserved.
 */
class PermissionSeeder extends Seeder
{
    /** module => actions available on that module. */
    private const MODULES = [
        'dashboard' => ['view'],
        'stock' => ['view', 'create', 'update', 'export', 'receive', 'transfer', 'adjust'],
        'weaver-purchases' => ['view', 'create', 'draft', 'cancel', 'return'],
        'weaver-payments' => ['view', 'create', 'reverse'],
        'weaver-refunds' => ['view', 'create', 'reverse'],
        'washing' => ['view', 'send', 'complete', 'return', 'export'],
        'washing-costs' => ['view', 'create', 'void', 'pay', 'reverse'],
        'orders' => ['view', 'create', 'update', 'delete'],
        'customers' => ['view', 'create', 'update', 'delete', 'export'],
        'weavers' => ['view', 'create', 'update', 'delete'],
        'geography' => ['view', 'create', 'update', 'delete'],
        'suppliers' => ['view', 'create', 'update', 'delete'],
        'shipments' => ['view', 'update'],
        'exports' => ['view', 'create'],
        'warehouses' => ['view'],
        'analytics' => ['view'],
        'reports' => ['view', 'export'],
        'users' => ['view', 'create', 'update', 'delete'],
        'settings' => ['view', 'update'],
        'branches' => ['view', 'create', 'update'],
        'locations' => ['view', 'create', 'update'],
        'currencies' => ['view', 'create', 'update'],
        'employees' => ['view', 'create', 'update'],
        'categories' => ['view', 'create', 'update'],
        'facilities' => ['view', 'create', 'update'],
        'accounts' => ['view', 'create', 'update'],
        'ledger' => ['view', 'create', 'reverse'],
        'audit' => ['view'],
        'purchasing' => ['view', 'create', 'pay', 'reverse'],
        'expenses' => ['view', 'create', 'pay', 'void', 'reverse'],
        'production' => ['view', 'create', 'manage', 'pay', 'receive', 'reverse'],
        'materials' => ['view', 'create', 'receive', 'move', 'pay', 'reverse'],
        'sales' => ['view', 'create', 'collect', 'reverse'],
        'partnerships' => ['view', 'create', 'manage', 'settle', 'pay', 'reverse'],
    ];

    /**
     * Role => permission patterns. '*.<a>' grants action <a> everywhere,
     * '<m>.*' grants every action on module <m>, and plain codes are
     * matched literally. Administrator receives everything above.
     */
    private const ROLE_GRANTS = [
        'Branch Manager' => [
            '*.view',
            'stock.create', 'stock.update', 'stock.export',
            'orders.create', 'orders.update', 'orders.delete',
            'customers.create', 'customers.update',
            'customers.delete', 'customers.export',
            'weavers.create', 'weavers.update', 'weavers.delete',
            'shipments.update', 'exports.create',
            'reports.export', 'locations.create', 'locations.update',
            'employees.create', 'employees.update',
            'stock.receive', 'stock.transfer', 'weaver-purchases.create',
            'weaver-purchases.draft', 'weaver-purchases.cancel', 'weaver-purchases.return', 'stock.adjust',
            'washing.send', 'washing.complete', 'washing.return', 'washing.export',
            'purchasing.create', 'expenses.create', 'production.create', 'production.manage', 'production.receive',
            'materials.create', 'materials.receive', 'materials.move', 'sales.create', 'partnerships.create', 'partnerships.manage',
        ],
        'Sales User' => [
            'dashboard.view',
            'customers.view', 'customers.create',
            'customers.update', 'customers.export',
            'orders.view', 'orders.create', 'orders.update',
            'stock.view', 'warehouses.view', 'shipments.view',
            'exports.view', 'analytics.view', 'reports.view',
            'branches.view', 'locations.view', 'currencies.view', 'categories.view',
            'washing.view',
            'sales.view', 'sales.create', 'sales.collect',
        ],
        'Stock User' => [
            'dashboard.view', 'stock.*', 'warehouses.view',
            'shipments.*', 'orders.view', 'customers.view',
            'reports.view', 'branches.view', 'locations.*', 'currencies.view',
            'categories.view',
            'stock.receive', 'stock.transfer',
            'washing.view', 'washing.send', 'washing.return', 'washing.export',
            'materials.view', 'materials.receive', 'materials.move', 'production.view', 'production.receive',
        ],
        'Accountant' => [
            'dashboard.view', 'analytics.view', 'reports.*',
            'orders.view', 'customers.view', 'settings.view',
            'accounts.*', 'ledger.*', 'branches.view', 'currencies.view',
            'weaver-purchases.view', 'weaver-payments.*',
            'weaver-refunds.*',
            'washing.view', 'washing-costs.*',
            'purchasing.view', 'purchasing.pay', 'purchasing.reverse', 'expenses.*', 'production.view', 'production.pay', 'production.reverse',
            'materials.view', 'materials.pay', 'materials.reverse', 'sales.view', 'sales.collect', 'sales.reverse', 'partnerships.view', 'partnerships.settle', 'partnerships.pay', 'partnerships.reverse',
        ],
        'Purchase User' => [
            'dashboard.view', 'stock.view', 'stock.create',
            'stock.update', 'orders.view', 'weavers.*',
            'reports.view', 'branches.view', 'locations.view', 'currencies.view',
            'categories.view', 'geography.view', 'suppliers.*',
            'weaver-purchases.*',
            'washing.view', 'washing.send',
            'purchasing.view', 'purchasing.create', 'materials.view', 'materials.create', 'materials.receive', 'materials.move', 'production.view', 'production.create', 'production.manage',
        ],
        'Washing Facility User' => [
            'dashboard.view', 'orders.view',
            'shipments.view', 'warehouses.view', 'locations.view', 'facilities.view',
            'branches.view', 'currencies.view',
            'washing.view', 'washing.complete',
        ],
    ];

    public function run(): void
    {
        // 1. Permission catalogue (idempotent).
        $all = [];
        foreach (self::MODULES as $module => $actions) {
            foreach ($actions as $action) {
                $permission = Permission::firstOrCreate(
                    ['code' => $module.'.'.$action],
                    [
                        'module' => $module,
                        'action' => $action,
                        'description' => "Allow {$action} on {$module}",
                    ],
                );
                $all[$permission->code] = $permission->id;
            }
        }

        // 2. Administrator gets every permission.
        $admin = Role::firstOrCreate(['name' => 'Administrator']);
        $admin->permissions()->sync(array_values($all));

        // 3. Every other role gets its pattern-based grant set
        //    (sync keeps the DB exactly matching this code).
        foreach (self::ROLE_GRANTS as $roleName => $patterns) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $ids = [];
            foreach ($patterns as $pattern) {
                foreach ($this->expand($pattern, $all) as $code) {
                    if (isset($all[$code])) {
                        $ids[] = $all[$code];
                    }
                }
            }
            $role->permissions()->sync($ids);
        }

        // Retain existing demo identity; never provision known-password users here.
        $salesUser = User::where('username', 'sales')->first();
        $salesRole = Role::where('name', 'Sales User')->first();
        if ($salesUser && $salesRole) {
            DB::table('user_roles')->updateOrInsert([
                'user_id' => $salesUser->id,
                'role_id' => $salesRole->id,
            ]);
        }
    }

    /**
     * Turn a pattern into concrete permission codes.
     *
     * @param  array<string, int>  $all  code => id
     * @return array<int, string>
     */
    private function expand(string $pattern, array $all): array
    {
        if ($pattern === '*') {
            return array_keys($all);
        }
        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1); // ".view"

            return array_keys(array_filter(
                $all,
                fn ($value, $code) => str_ends_with($code, $suffix),
                ARRAY_FILTER_USE_BOTH,
            ));
        }
        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -1); // "customers."

            return array_keys(array_filter(
                $all,
                fn ($value, $code) => str_starts_with($code, $prefix),
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        return [$pattern];
    }
}
