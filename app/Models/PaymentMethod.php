<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * How customers pay. "bank" methods carry the receiving account, used to
 * show a VietQR code at checkout.
 *
 * @property int $id
 * @property string $name
 * @property string $type cash | bank | ewallet | cod | points
 * @property ?string $bank_bin
 * @property ?string $account_number
 * @property ?string $account_name
 * @property bool $is_system
 * @property bool $is_active
 * @property int $sort
 */
class PaymentMethod extends Model
{
    use BelongsToTenant, LogsActivity;

    public const TYPES = [
        'cash' => 'Tiền mặt',
        'bank' => 'Chuyển khoản',
        'ewallet' => 'Ví điện tử',
        'cod' => 'Thu hộ (COD)',
        'points' => 'Điểm tích lũy',
    ];

    protected $fillable = ['name', 'type', 'bank_bin', 'account_number', 'account_name', 'is_system', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function activityLabel(): string
    {
        return "hình thức thanh toán {$this->name}";
    }
}
