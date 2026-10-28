<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $table_key
 * @property string $name
 * @property array<string, mixed> $query
 */
#[Fillable(['user_id', 'table_key', 'name', 'query'])]
class SavedView extends Model
{
    use BelongsToTenant;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'query' => 'array',
        ];
    }
}
