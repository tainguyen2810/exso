<?php

namespace Modules\Customer\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A contact person of a (business) customer.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $position
 * @property string|null $note
 */
#[Fillable(['name', 'phone', 'email', 'position', 'note'])]
class CustomerContact extends Model
{
    use BelongsToTenant;
}
