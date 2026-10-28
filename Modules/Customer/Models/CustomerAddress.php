<?php

namespace Modules\Customer\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A delivery address of a customer.
 *
 * @property int $id
 * @property int $customer_id
 * @property string|null $name
 * @property string|null $phone
 * @property string $address
 * @property string|null $province
 * @property string|null $district
 * @property string|null $ward
 * @property bool $is_default
 */
#[Fillable(['name', 'phone', 'address', 'province', 'district', 'ward', 'is_default'])]
class CustomerAddress extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function fullAddress(): string
    {
        return implode(', ', array_filter([$this->address, $this->ward, $this->district, $this->province]));
    }
}
