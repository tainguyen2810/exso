<?php

namespace Modules\Shipping\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of "Chi tiết trạng thái đơn giao hàng": a status reported by the
 * carrier or set by staff.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $shipment_id
 * @property string|null $status
 * @property string|null $carrier_status
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $creator
 */
class ShipmentEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['shipment_id', 'status', 'carrier_status', 'note', 'occurred_at', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
