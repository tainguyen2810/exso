<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A tax rate ("mức thuế"); rate_bp is in hundredths of a percent (1000 = 10%).
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $rate_bp
 * @property string $type sale | purchase | other
 */
class TaxRate extends Model
{
    use BelongsToTenant, LogsActivity;

    public const TYPES = ['sale' => 'Thuế bán hàng', 'purchase' => 'Thuế nhập hàng', 'other' => 'Khác'];

    protected $fillable = ['name', 'code', 'rate_bp', 'type'];

    protected function casts(): array
    {
        return ['rate_bp' => 'integer'];
    }

    public function activityLabel(): string
    {
        return "mức thuế {$this->name}";
    }
}
