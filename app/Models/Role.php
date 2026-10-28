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
 */
class Role extends SpatieRole
{
    use BelongsToTenant, LogsActivity;

    public function activityLabel(): string
    {
        return "vai trò {$this->name}";
    }
}
