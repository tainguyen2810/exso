<?php

namespace Modules\Promotion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity\ActivityLogger;
use App\Support\DataTable\DataTable;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Customer\Models\Customer;
use Modules\Order\Models\Order;
use Modules\Promotion\Models\LoyaltyAccount;
use Modules\Promotion\Models\LoyaltyPoint;
use Modules\Promotion\Models\LoyaltyTier;
use Modules\Promotion\Services\LoyaltyService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Loyalty: points rules, member tiers, points history and manual
 * adjustments ("Điều chỉnh điểm").
 */
class LoyaltyController extends Controller
{
    public function __construct(protected LoyaltyService $loyalty) {}

    public function index(): Response
    {
        return Inertia::render('loyalty/index', [
            'values' => [
                'enabled' => $this->loyalty->enabled(),
                'earn_amount' => $this->loyalty->earnAmount(),
                'redeem_value' => $this->loyalty->redeemValue(),
            ],
            'tiers' => LoyaltyTier::query()->orderBy('min_spent')->get()->map(fn (LoyaltyTier $tier): array => [
                'id' => $tier->id,
                'name' => $tier->name,
                'min_spent' => $tier->min_spent,
                'earn_multiplier_bp' => $tier->earn_multiplier_bp,
                'description' => $tier->description,
                'customers' => LoyaltyAccount::query()->where('tier_id', $tier->id)->count(),
            ]),
            'stats' => [
                'members' => LoyaltyAccount::query()->count(),
                'points' => (int) LoyaltyAccount::query()->sum('points'),
                'earned' => (int) LoyaltyAccount::query()->sum('earned_total'),
                'used' => (int) LoyaltyAccount::query()->sum('used_total'),
            ],
        ]);
    }

    public function saveSettings(Request $request, SettingsRepository $settings, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'earn_amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'redeem_value' => ['required', 'integer', 'min:1', 'max:999999999'],
        ], [], ['earn_amount' => 'số tiền tích 1 điểm', 'redeem_value' => 'giá trị 1 điểm']);

        $values = [
            'loyalty.enabled' => (bool) $data['enabled'],
            'loyalty.earn_amount' => (int) $data['earn_amount'],
            'loyalty.redeem_value' => (int) $data['redeem_value'],
        ];
        $old = [];

        foreach ($values as $key => $value) {
            if ($settings->get($key) !== $value) {
                $old[$key] = $settings->get($key);
            }
        }

        $settings->setMany($values);

        if ($old !== []) {
            $activity->log('updated', 'Sửa cấu hình tích điểm', null, ['old' => $old, 'new' => array_intersect_key($values, $old)]);
        }

        $this->toast('Đã lưu cấu hình tích điểm.');

        return back();
    }

    public function storeTier(Request $request): RedirectResponse
    {
        LoyaltyTier::query()->create($this->tierInput($request, null));
        $this->loyalty->refreshTiers();
        $this->toast('Đã thêm hạng thành viên.');

        return back();
    }

    public function updateTier(Request $request, LoyaltyTier $tier): RedirectResponse
    {
        $tier->fill($this->tierInput($request, $tier))->save();
        $this->loyalty->refreshTiers();
        $this->toast("Đã lưu hạng {$tier->name}.");

        return back();
    }

    public function destroyTier(LoyaltyTier $tier): RedirectResponse
    {
        $tier->delete();
        $this->loyalty->refreshTiers();
        $this->toast("Đã xóa hạng {$tier->name}.");

        return back();
    }

    /** Lịch sử điểm của mọi khách hàng. */
    public function points(Request $request): Response|StreamedResponse
    {
        $table = DataTable::for(LoyaltyPoint::query()->with(['customer:id,code,name,phone', 'creator:id,name']), $request, 'loyalty-points')
            ->filterExact('customer_id')
            ->filterExact('type')
            ->filterDateRange('created_at')
            ->filter('search', fn ($query, string $value) => $query->where(fn ($query) => $query
                ->where('reference_code', 'like', '%'.addcslashes($value, '%_\\').'%')
                ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', '%'.addcslashes($value, '%_\\').'%')->orWhere('phone', 'like', '%'.addcslashes($value, '%_\\').'%'))))
            ->sortable(['created_at', 'points'], '-created_at');

        $customer = $request->input('filter.customer_id') ? Customer::query()->find((int) $request->input('filter.customer_id')) : null;

        return Inertia::render('loyalty/points', [
            'points' => $table->paginate(fn (LoyaltyPoint $line): array => [
                'id' => $line->id,
                'customer' => $line->customer->only('id', 'code', 'name', 'phone'),
                'type' => $line->type,
                'type_label' => LoyaltyPoint::TYPES[$line->type] ?? $line->type,
                'points' => $line->points,
                'balance_after' => $line->balance_after,
                'reference_code' => $line->reference_code,
                'reference_url' => $line->reference_type === (new Order)->getMorphClass() ? "/orders/{$line->reference_id}" : null,
                'note' => $line->note,
                'creator' => $line->creator?->name,
                'created_at' => $line->created_at?->toIso8601String(),
            ]),
            'types' => LoyaltyPoint::TYPES,
            'customer' => $customer === null ? null : [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
                'points' => $this->loyalty->account($customer->id)->points ?? 0,
            ],
            'enabled' => $this->loyalty->enabled(),
        ]);
    }

    /** "Điều chỉnh điểm" by hand, with a reason. */
    public function adjust(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('tenant_id', tenancy()->id())->whereNull('deleted_at')],
            'points' => ['required', 'integer', 'not_in:0', 'min:-100000000', 'max:100000000'],
            'note' => ['required', 'string', 'max:500'],
        ], [], ['customer_id' => 'khách hàng', 'points' => 'số điểm', 'note' => 'lý do']);

        /** @var User $user */
        $user = $request->user();
        /** @var Customer $customer */
        $customer = Customer::query()->findOrFail((int) $data['customer_id']);

        if (! $customer->isVisibleTo($user)) {
            throw ValidationException::withMessages(['customer_id' => 'Bạn không xem được khách hàng này.']);
        }

        $this->loyalty->record($customer->id, LoyaltyPoint::ADJUST, (int) $data['points'], ['note' => $data['note']]);
        $this->toast("Đã điều chỉnh điểm của {$customer->name}.");

        return back();
    }

    /**
     * @return array{name: string, min_spent: int, earn_multiplier_bp: int, description: string|null}
     */
    protected function tierInput(Request $request, ?LoyaltyTier $tier): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('loyalty_tiers', 'name')->where('tenant_id', tenancy()->id())->ignore($tier?->id)],
            'min_spent' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'earn_multiplier_bp' => ['required', 'integer', 'min:0', 'max:100000'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [], ['name' => 'tên hạng', 'min_spent' => 'tổng chi tiêu', 'earn_multiplier_bp' => 'hệ số tích điểm']);

        return [
            'name' => trim((string) $data['name']),
            'min_spent' => (int) $data['min_spent'],
            'earn_multiplier_bp' => (int) $data['earn_multiplier_bp'],
            'description' => $data['description'] ?? null,
        ];
    }
}
