<?php

namespace Modules\Promotion\Models;

use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * "Hạng thành viên": a customer whose total spending reaches min_spent is
 * in the tier and earns points × earn_multiplier_bp / 10000.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property int $min_spent
 * @property int $earn_multiplier_bp
 * @property string|null $description
 */
class LoyaltyTier extends Model
{
    use BelongsToTenant, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['name', 'min_spent', 'earn_multiplier_bp', 'description'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['min_spent' => 'integer', 'earn_multiplier_bp' => 'integer'];
    }

    public function activityLabel(): string
    {
        return 'hạng thành viên '.$this->name;
    }
}
