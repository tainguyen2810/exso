<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Where an order came from (Tại quầy, Facebook, Shopee...).
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property bool $is_default
 * @property bool $is_active
 */
class SalesSource extends Model
{
    use BelongsToTenant, LogsActivity;

    protected $fillable = ['name', 'code', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function activityLabel(): string
    {
        return "nguồn bán hàng {$this->name}";
    }
}
