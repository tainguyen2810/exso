<?php

namespace Modules\Einvoice\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $einvoice_id
 * @property int|null $variant_id
 * @property string|null $code
 * @property string $name
 * @property string|null $unit
 * @property int $quantity
 * @property int $price
 * @property int $discount
 * @property int $amount
 * @property int $tax_percent
 * @property int $amount_before_tax
 * @property int $tax_amount
 * @property int $amount_after_tax
 * @property bool $is_promotion
 * @property int $sort
 */
class EinvoiceItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['variant_id', 'code', 'name', 'unit', 'quantity', 'price', 'discount', 'amount', 'tax_percent', 'amount_before_tax', 'tax_amount', 'amount_after_tax', 'is_promotion', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'integer',
            'discount' => 'integer',
            'amount' => 'integer',
            'tax_percent' => 'integer',
            'amount_before_tax' => 'integer',
            'tax_amount' => 'integer',
            'amount_after_tax' => 'integer',
            'is_promotion' => 'boolean',
        ];
    }
}
