<?php

namespace Modules\Product\Models;

use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Price and stock live on the variants; a product without attributes has
 * one variant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $type
 * @property string $name
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property string|null $description
 * @property string|null $tags
 * @property string|null $unit
 * @property string|null $option1
 * @property string|null $option2
 * @property string|null $option3
 * @property string|null $image_url
 * @property bool $is_sellable
 * @property bool $allow_negative_stock
 * @property int|null $created_by
 * @property-read Category|null $category
 * @property-read Brand|null $brand
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read Collection<int, ComboItem> $comboItems
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['type', 'name', 'category_id', 'brand_id', 'description', 'tags', 'unit', 'option1', 'option2', 'option3', 'image_url', 'is_sellable', 'allow_negative_stock', 'created_by'])]
class Product extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    public const TYPE_NORMAL = 'normal';

    /** Services and licenses: sold without stock. */
    public const TYPE_SERVICE = 'service';

    public const TYPE_COMBO = 'combo';

    public const TYPES = [
        self::TYPE_NORMAL => 'Sản phẩm thường',
        self::TYPE_SERVICE => 'Dịch vụ / license',
        self::TYPE_COMBO => 'Combo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean',
            'allow_negative_stock' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    /**
     * @return HasMany<ComboItem, $this>
     */
    public function comboItems(): HasMany
    {
        return $this->hasMany(ComboItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Only ordinary goods have stock; services and combos do not (a combo's
     * stock comes from its components).
     */
    public function tracksInventory(): bool
    {
        return $this->type === self::TYPE_NORMAL;
    }

    /**
     * @return list<string>
     */
    public function optionNames(): array
    {
        return array_values(array_filter([$this->option1, $this->option2, $this->option3], fn (?string $name): bool => $name !== null && $name !== ''));
    }

    public function activityLabel(): string
    {
        return "sản phẩm {$this->name}";
    }
}
