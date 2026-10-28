<?php

namespace Modules\Order\Models;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received for an order (amount > 0) or paid back (refund, < 0).
 * The cash book (G4) turns these into automatic receipts / payments.
 *
 * @property int $id
 * @property int $order_id
 * @property int $branch_id
 * @property int|null $payment_method_id
 * @property string $method_name
 * @property string $method_type
 * @property string $kind
 * @property int $amount
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $pos_shift_id
 * @property string|null $note
 * @property CarbonImmutable $paid_at
 * @property int|null $created_by
 * @property-read Order $order
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read User|null $creator
 * @property CarbonImmutable|null $created_at
 */
class OrderPayment extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const PAYMENT = 'payment';

    public const REFUND = 'refund';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
