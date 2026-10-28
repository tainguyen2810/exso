<?php

namespace Modules\Cashbook\Models;

use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * "Loại phiếu thu" / "Loại phiếu chi". TUDONG (Tự động) is the type of
 * vouchers created from other documents.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $kind
 * @property string $code
 * @property string $name
 * @property bool $affects_profit
 * @property string|null $description
 * @property bool $is_system
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['kind', 'code', 'name', 'affects_profit', 'description', 'is_system'])]
class CashVoucherType extends Model
{
    use BelongsToTenant, LogsActivity;

    public const AUTO = 'TUDONG';

    /**
     * Built-in types of a new store, as in Sapo: kind => [code, name, affects profit].
     */
    public const DEFAULTS = [
        CashVoucher::RECEIPT => [
            [self::AUTO, 'Tự động', false],
            ['THUNOKH', 'Thu nợ khách hàng', false],
            ['THANHLY', 'Nhượng bán, thanh lý tài sản', true],
            ['CHOTHUE', 'Cho thuê tài sản', true],
            ['BOITHUONG', 'Tiền bồi thường', true],
            ['THUONG', 'Tiền thưởng', true],
            ['THUKHAC', 'Thu nhập khác', true],
        ],
        CashVoucher::PAYMENT => [
            [self::AUTO, 'Tự động', false],
            ['TRANONCC', 'Trả nợ nhà cung cấp', false],
            ['LUONG', 'Chi phí lương nhân viên', true],
            ['MATBANG', 'Chi phí thuê mặt bằng', true],
            ['DIENNUOC', 'Chi phí điện, nước, internet', true],
            ['VANCHUYEN', 'Chi phí vận chuyển', true],
            ['QUANGCAO', 'Chi phí quảng cáo', true],
            ['CHIKHAC', 'Chi phí khác', true],
        ],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['affects_profit' => 'boolean', 'is_system' => 'boolean'];
    }

    /** Add the built-in types that are missing; safe to run again. */
    public static function installDefaults(): void
    {
        foreach (self::DEFAULTS as $kind => $types) {
            foreach ($types as [$code, $name, $affectsProfit]) {
                self::query()->firstOrCreate(
                    ['kind' => $kind, 'code' => $code],
                    ['name' => $name, 'affects_profit' => $affectsProfit, 'is_system' => true],
                );
            }
        }
    }

    public static function auto(string $kind): self
    {
        $find = fn (): ?self => self::query()->where('kind', $kind)->where('code', self::AUTO)->first();

        if (($type = $find()) !== null) {
            return $type;
        }

        self::installDefaults();

        return $find() ?? throw new \RuntimeException('Missing automatic voucher type.');
    }

    public function activityLabel(): string
    {
        return ($this->kind === CashVoucher::RECEIPT ? 'loại phiếu thu ' : 'loại phiếu chi ').$this->name;
    }
}
