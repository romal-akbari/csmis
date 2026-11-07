<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    /** Column holding the hashed password (schema uses password_hash). */
    public const PASSWORD_COLUMN = 'password_hash';

    protected $fillable = [
        'name',
        'username',
        'email',
        'password_hash',
        'employee_id',
        'locale',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password_hash',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** Tell Laravel's user provider to read password_hash, not password. */
    public function getAuthPasswordName(): string
    {
        return self::PASSWORD_COLUMN;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /**
     * Permission codes granted through any assigned role (§17 RBAC).
     *
     * @return array<int, string>
     */
    public function permissionCodes(): array
    {
        return Permission::query()
            ->whereIn('id', DB::table('role_permissions')
                ->select('permission_id')
                ->whereIn('role_id', $this->roles()->select('roles.id')))
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    /** True when the user's roles grant this permission code. */
    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissionCodes(), true);
    }
}
