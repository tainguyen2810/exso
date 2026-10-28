<?php

namespace Modules\Order\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Lịch sử đơn hàng": one line per step or change.
 *
 * @property int $id
 * @property int $order_id
 * @property string $action
 * @property string|null $note
 * @property int|null $created_by
 * @property-read User|null $creator
 * @property CarbonImmutable|null $created_at
 */
class OrderHistory extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created' => 'Tạo đơn hàng',
        'updated' => 'Sửa đơn hàng',
        'approved' => 'Duyệt đơn hàng',
        'packed' => 'Đóng gói',
        'shipped' => 'Xuất kho',
        'delivered' => 'Giao hàng thành công',
        'delivery_failed' => 'Giao hàng thất bại',
        'received_back' => 'Nhận lại hàng giao thất bại',
        'completed' => 'Hoàn thành đơn hàng',
        'paid' => 'Thanh toán',
        'refunded' => 'Hoàn tiền',
        'returned' => 'Khách trả hàng',
        'cancelled' => 'Hủy đơn hàng',
        'printed' => 'In đơn hàng',
    ];

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
