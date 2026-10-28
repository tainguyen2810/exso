<?php

namespace Modules\Purchase\Models;

use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * "Nhà cung cấp". `debt` is what the store owes; only SupplierLedger changes it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $tax_code
 * @property string|null $contact_name
 * @property string|null $note
 * @property string $status
 * @property int $debt
 * @property int|null $created_by
 * @property-read User|null $creator
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'name', 'phone', 'email', 'address', 'tax_code', 'contact_name', 'note', 'status', 'created_by'])]
class Supplier extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['debt' => 'integer'];
    }

    /**
     * @return HasMany<SupplierDebt, $this>
     */
    public function debts(): HasMany
    {
        return $this->hasMany(SupplierDebt::class);
    }

    /**
     * @return HasMany<PurchaseReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityLabel(): string
    {
        return "nhà cung cấp {$this->name}";
    }

    /**
     * @return list<string>
     */
    public function activityIgnored(): array
    {
        return ['debt'];
    }
}
