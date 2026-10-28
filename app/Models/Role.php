<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role belongs to one store (spatie "team" = tenant).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $guard_name
 * @property ?string $description
 * @property int $permissions_count
 * @property int $active_users_count
 * @property int $inactive_users_count
 */
class Role extends SpatieRole
{
    use BelongsToTenant, LogsActivity;

    public function activityLabel(): string
    {
        return "vai trò {$this->name}";
    }
}
