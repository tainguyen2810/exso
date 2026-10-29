<?php

namespace Modules\Marketplace\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\Order;

/**
 * An order of a marketplace shop and the EXSO order made from it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $shop_id
 * @property string $external_id
 * @property string|null $platform_status
 * @property string $state
 * @property int|null $order_id
 * @property string $sync_status
 * @property string|null $error
 * @property int $attempts
 * @property string|null $buyer_id
 * @property string|null $buyer_username
 * @property string|null $recipient_name
 * @property int $total
 * @property string|null $carrier
 * @property string|null $tracking_code
 * @property list<array<string, mixed>>|null $items
 * @property CarbonImmutable|null $placed_at
 * @property CarbonImmutable|null $platform_updated_at
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read MarketplaceShop $shop
 * @property-read Order|null $order
 */
class MarketplaceOrder extends Model
{
    use BelongsToTenant;

    /** Common states the platform statuses map to. */
    public const STATES = [
        'unpaid' => 'Chờ thanh toán',
        'to_ship' => 'Chờ lấy hàng',
        'shipped' => 'Đang giao',
        'completed' => 'Hoàn thành',
        'cancelled' => 'Đã hủy',
        'returned' => 'Trả hàng/Hoàn tiền',
    ];

    public const SYNCED = 'synced';

    public const UNLINKED = 'unlinked';

    public const ERROR = 'error';

    public const SYNC_STATUSES = [
        self::SYNCED => 'Thành công',
        self::UNLINKED => 'Chưa liên kết sản phẩm',
        self::ERROR => 'Lỗi',
    ];

    /** @var list<string> */
    protected $fillable = ['shop_id', 'external_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'attempts' => 'integer',
            'items' => 'array',
            'placed_at' => 'immutable_datetime',
            'platform_updated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MarketplaceShop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShop::class, 'shop_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
