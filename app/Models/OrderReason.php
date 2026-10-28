<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A reason to pick when cancelling an order or taking goods back.
 *
 * @property int $id
 * @property string $type cancel | return
 * @property string $name
 * @property bool $is_active
 * @property int $sort
 */
class OrderReason extends Model
{
    use BelongsToTenant, LogsActivity;

    public const TYPES = ['cancel' => 'Lý do hủy đơn', 'return' => 'Lý do trả hàng'];

    protected $fillable = ['type', 'name', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function activityLabel(): string
    {
        return ($this->type === 'cancel' ? 'lý do hủy ' : 'lý do trả hàng ').$this->name;
    }
}
