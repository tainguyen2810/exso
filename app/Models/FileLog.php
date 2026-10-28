<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Excel export or import ("Xuất/nhập file" log).
 *
 * @property int $id
 * @property ?int $user_id
 * @property string $module list key, e.g. "staff", "products"
 * @property string $action export | import
 * @property string $file_name
 * @property string $status processing | done | failed
 * @property int $rows
 * @property ?string $message
 * @property CarbonImmutable|null $created_at
 */
class FileLog extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'module', 'action', 'file_name', 'status', 'rows', 'message'];

    protected function casts(): array
    {
        return ['rows' => 'integer'];
    }

    /**
     * Record an export or import by the signed-in user.
     */
    public static function record(string $module, string $action, string $fileName, string $status = 'processing', int $rows = 0, ?string $message = null): self
    {
        return self::query()->create([
            'user_id' => auth()->id(),
            'module' => $module,
            'action' => $action,
            'file_name' => mb_substr($fileName, 0, 191),
            'status' => $status,
            'rows' => $rows,
            'message' => $message,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
