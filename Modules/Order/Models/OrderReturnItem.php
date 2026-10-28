<?php

namespace Modules\Order\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_return_id
 * @property int $order_item_id
 * @property int|null $variant_id
 * @property int $quantity
 * @property int $price
 * @property int $total
 * @property int $cost
 * @property-read OrderItem $orderItem
 */
class OrderReturnItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price' => 'integer', 'total' => 'integer', 'cost' => 'integer'];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
