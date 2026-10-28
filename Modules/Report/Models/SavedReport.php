<?php

namespace Modules\Report\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Báo cáo tùy chỉnh: a report with its filters saved under a name.
 *
 * @property int $id
 * @property string $name
 * @property string $report
 * @property array<string, mixed>|null $query
 * @property bool $is_shared
 * @property int $user_id
 */
class SavedReport extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['name', 'report', 'query', 'is_shared', 'user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['query' => 'array', 'is_shared' => 'boolean'];
    }

    /**
     * The user's own saved reports and the shared ones.
     *
     * @param  Builder<SavedReport>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query->where('user_id', $user->id)->orWhere('is_shared', true));
    }
}
