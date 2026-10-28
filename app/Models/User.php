<?php

namespace App\Models;

use App\Authorization\Permissions;
use App\Support\Activity\LogsActivity;
use App\Support\Branches\CurrentBranch;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property bool $is_owner
 * @property string $status
 * @property CarbonImmutable|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'phone', 'password', 'status'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, HasRoles, LogsActivity, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_owner' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Branches this user may work in. The owner works in all of them.
     *
     * @return list<int>
     */
    public function branchIds(): array
    {
        $query = $this->is_owner ? Branch::query() : $this->branches();

        return array_values($query->where('status', 'active')->pluck('branches.id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * Permissions in effect right now: the member's own data permissions plus
     * the roles that apply in the branch they are working in. Used by
     * Gate::before, so every `can:` check follows the current branch.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->is_owner) {
            return Permissions::all();
        }

        return $this->permissionsInBranch(app(CurrentBranch::class)->id($this));
    }

    /**
     * A role applies in a branch when it was given for that branch or for
     * all branches. A role without any assignment row (older data) applies
     * everywhere.
     *
     * @return list<string>
     */
    public function permissionsInBranch(?int $branchId): array
    {
        $this->loadMissing(['roles.permissions', 'permissions', 'roleAssignments']);
        $assignments = $this->roleAssignments->groupBy('role_id');
        $names = $this->permissions->pluck('name')->all();

        foreach ($this->roles as $role) {
            if (! $role instanceof Role) {
                continue;
            }

            $rows = $assignments->get($role->id);

            if ($rows === null || $rows->contains(fn (RoleAssignment $row): bool => $row->branch_id === null || $row->branch_id === $branchId)) {
                array_push($names, ...$role->permissions->pluck('name')->all());
            }
        }

        return array_values(array_unique(array_map(fn ($name): string => (string) $name, $names)));
    }

    /**
     * Everything the member holds in any branch; what they may hand out to
     * others (see StaffController and RoleController).
     *
     * @return list<string>
     */
    public function grantablePermissions(): array
    {
        if ($this->is_owner) {
            return Permissions::all();
        }

        $this->loadMissing(['roles.permissions', 'permissions']);
        $names = $this->permissions->pluck('name')->all();

        foreach ($this->roles as $role) {
            if ($role instanceof Role) {
                array_push($names, ...$role->permissions->pluck('name')->all());
            }
        }

        return array_values(array_unique(array_map(fn ($name): string => (string) $name, $names)));
    }

    /**
     * Whether this user may edit, lock or delete $other: never the owner or
     * themselves (unless owner), and only staff whose permissions they hold
     * too, so nobody can take over a more powerful account.
     */
    public function canManage(User $other): bool
    {
        if ($this->is_owner) {
            return true;
        }

        if ($other->is_owner || $other->is($this)) {
            return false;
        }

        return array_diff($other->grantablePermissions(), $this->grantablePermissions()) === [];
    }

    public function activityLabel(): string
    {
        return "nhân viên {$this->name}";
    }
}
