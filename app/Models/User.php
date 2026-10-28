<?php

namespace App\Models;

use App\Authorization\Permissions;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * Permission names for the UI (menu, buttons). The owner has all of them.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->is_owner) {
            return Permissions::all();
        }

        return array_values($this->getAllPermissions()->pluck('name')->map(fn ($name): string => (string) $name)->all());
    }

    public function activityLabel(): string
    {
        return "nhân viên {$this->name}";
    }
}
