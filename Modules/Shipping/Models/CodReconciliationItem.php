<?php

namespace Modules\Shipping\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\Order;

/**
 * One shipment of a reconciliation: what EXSO expects ("hệ thống") and what
 * the partner reports ("đối tác").
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $cod_reconciliation_id
 * @property int $shipment_id
 * @property int $order_id
 * @property int $cod
 * @property int $fee
 * @property int $partner_cod
 * @property int $partner_fee
 * @property int $paid
 * @property-read Shipment $shipment
 * @property-read Order $order
 */
class CodReconciliationItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['cod_reconciliation_id', 'shipment_id', 'order_id', 'cod', 'fee', 'partner_cod', 'partner_fee', 'paid'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cod' => 'integer', 'fee' => 'integer', 'partner_cod' => 'integer', 'partner_fee' => 'integer', 'paid' => 'integer'];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function differs(): bool
    {
        return $this->cod !== $this->partner_cod || $this->fee !== $this->partner_fee;
    }
}
