<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FoundationAccess
{
    public static function administrator(User $user): bool
    {
        return $user->roles()->where('name', 'Administrator')->exists();
    }

    public static function branchIds(User $user): array
    {
        return DB::table('user_branches')->where('user_id', $user->id)
            ->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
    }

    public static function facilityIds(User $user): array
    {
        return DB::table('user_washing_facilities')->where('user_id', $user->id)
            ->pluck('washing_facility_id')->map(fn ($id) => (int) $id)->all();
    }

    public static function scope(Builder $query, string $table, User $user): Builder
    {
        if (self::administrator($user)) {
            return $query;
        }
        if ($table === 'branches') {
            $query->whereIn('id', self::branchIds($user));
        } elseif ($table === 'accounts') {
            self::accounts($query, $user);
        } elseif ($table === 'employees') {
            $query->whereIn('branch_id', self::branchIds($user));
        } elseif ($table === 'washing_facilities') {
            $query->whereIn('id', self::facilityIds($user));
        } elseif ($table === 'stock_locations') {
            $query->where(function ($q) use ($user) {
                $q->whereIn('branch_id', self::branchIds($user))
                    ->orWhereIn('washing_facility_id', self::facilityIds($user));
            });
        }

        return $query;
    }

    public static function accounts(Builder $query, User $user, string $column = 'accounts.id'): Builder
    {
        return self::administrator($user) ? $query : $query->whereIn($column,
            DB::table('user_accounts')->where('user_id', $user->id)->select('account_id'));
    }

    public static function accountIds(User $user): array
    {
        return DB::table('user_accounts')->where('user_id', $user->id)->orderBy('account_id')->pluck('account_id')->map(fn ($id) => (int) $id)->all();
    }

    public static function permit(User $user, string $permission): void
    {
        abort_unless($user->hasPermission($permission), 403,
            'You do not have permission to perform this action.');
    }
}
