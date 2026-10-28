<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role given to a staff member in one branch, or in all of their
 * branches when branch_id is null (Sapo: "Vai trò × Chi nhánh").
 *
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 * @property ?int $branch_id
 */
class RoleAssignment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'role_id', 'branch_id'];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
