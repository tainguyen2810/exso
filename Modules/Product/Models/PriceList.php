<?php

namespace Modules\Product\Models;

use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Extra price policy ("Giá đại lý", "Giá CTV"...). Retail, wholesale and
 * import prices are columns on the variant and always exist.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string $status
 * @property-read int|null $prices_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'name', 'status'])]
class PriceList extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    /** Built-in policies, stored as variant columns. */
    public const BUILT_IN = [
        'retail_price' => 'Giá bán lẻ',
        'wholesale_price' => 'Giá bán buôn',
        'import_price' => 'Giá nhập',
    ];

    /**
     * @return HasMany<VariantPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(VariantPrice::class);
    }

    public function activityLabel(): string
    {
        return "chính sách giá {$this->name}";
    }
}
