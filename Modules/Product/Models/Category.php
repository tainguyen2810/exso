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
 * "Loại sản phẩm".
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property-read int|null $products_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name'])]
class Category extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    protected $table = 'product_categories';

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function activityLabel(): string
    {
        return "loại sản phẩm {$this->name}";
    }
}
