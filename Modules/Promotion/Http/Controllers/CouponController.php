<?php

namespace Modules\Promotion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DataTable\DataTable;
use App\Support\Sequences\Sequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Promotion\Http\Concerns\HandlesPromotionInput;
use Modules\Promotion\Models\Coupon;
use Modules\Promotion\Models\CouponBatch;
use Modules\Promotion\Models\OrderPromotion;
use Modules\Promotion\Models\Promotion;

/**
 * "Quản lý mã giảm giá": batches (đợt phát hành) with their rules, then
 * the codes of a batch, typed one by one or generated.
 */
class CouponController extends Controller
{
    use HandlesPromotionInput;

    public function index(Request $request): Response
    {
        $now = CarbonImmutable::now();

        $table = DataTable::for(CouponBatch::query()->withCount('coupons'), $request, 'coupons')
            ->search(['code', 'name'])
            ->filter('state', fn (Builder $query, string $value) => match ($value) {
                'running' => $query->where('is_active', true)->where('starts_at', '<=', $now)->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now)),
                'scheduled' => $query->where('is_active', true)->where('starts_at', '>', $now),
                'paused' => $query->where('is_active', false),
                'expired' => $query->where('is_active', true)->whereNotNull('ends_at')->where('ends_at', '<', $now),
                default => $query,
            })
            ->filterDateRange('starts_at')
            ->sortable(['code', 'name', 'starts_at', 'ends_at', 'used_count', 'created_at'], '-id');

        return Inertia::render('coupons/index', [
            'batches' => $table->paginate(fn (CouponBatch $batch): array => [
                'id' => $batch->id,
                'code' => $batch->code,
                'name' => $batch->name,
                'value_label' => $batch->valueLabel(),
                'state' => $batch->state($now),
                'starts_at' => $batch->starts_at->toIso8601String(),
                'ends_at' => $batch->ends_at?->toIso8601String(),
                'coupons_count' => (int) $batch->getAttribute('coupons_count'),
                'used_count' => $batch->used_count,
                'created_at' => $batch->created_at?->toIso8601String(),
            ]),
            'states' => array_diff_key(Promotion::STATES, ['stopped' => true]),
            'enabled' => (bool) settings('coupons.enabled'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('coupons/form', [
            'batch' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $values = $this->validated($request, null);

        $batch = new CouponBatch([...$values, 'created_by' => $user->id]);
        $batch->code = $values['code'] ?? Sequence::next('DPH', CouponBatch::class);
        $batch->save();

        $this->toast("Đã tạo đợt phát hành {$batch->code}. Thêm mã giảm giá cho đợt này.");

        return to_route('coupons.show', $batch);
    }

    public function show(CouponBatch $batch): Response
    {
        $batch->load('creator:id,name');

        return Inertia::render('coupons/form', [
            'batch' => [
                'id' => $batch->id,
                'code' => $batch->code,
                'name' => $batch->name,
                'description' => $batch->description,
                'is_active' => $batch->is_active,
                'state' => $batch->state(),
                'starts_at' => $batch->starts_at->setTimezone((string) config('app.timezone'))->format('Y-m-d\TH:i'),
                'ends_at' => $batch->ends_at?->setTimezone((string) config('app.timezone'))->format('Y-m-d\TH:i'),
                'value_type' => $batch->value_type,
                'value' => $batch->value,
                'max_discount' => $batch->max_discount,
                'uses_per_code' => $batch->uses_per_code,
                'uses_per_customer' => $batch->uses_per_customer,
                'combinable' => $batch->combinable,
                'min_order' => $batch->min_order,
                'product_target_type' => $batch->product_target_type,
                'product_ids' => $batch->product_ids ?? [],
                'all_branches' => $batch->all_branches,
                'branch_ids' => $batch->branch_ids ?? [],
                'all_sources' => $batch->all_sources,
                'source_ids' => $batch->source_ids ?? [],
                'all_customers' => $batch->all_customers,
                'customer_group_ids' => $batch->customer_group_ids ?? [],
                'used_count' => $batch->used_count,
                'creator' => $batch->creator?->name,
                'created_at' => $batch->created_at?->toIso8601String(),
                'coupons' => $batch->coupons()->orderByDesc('id')->limit(500)->get()->map(fn (Coupon $coupon): array => [
                    'id' => $coupon->id,
                    'code' => $coupon->code,
                    'is_active' => $coupon->is_active,
                    'used_count' => $coupon->used_count,
                    'created_at' => $coupon->created_at?->toIso8601String(),
                ])->all(),
                'coupons_count' => $batch->coupons()->count(),
            ],
            'variants' => $batch->product_target_type === 'variant' ? $this->variantLabels($batch->product_ids ?? []) : [],
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, CouponBatch $batch): RedirectResponse
    {
        $batch->fill($this->validated($request, $batch));
        $batch->save();

        $this->toast("Đã lưu đợt phát hành {$batch->code}.");

        return back();
    }

    public function destroy(CouponBatch $batch): RedirectResponse
    {
        if (OrderPromotion::query()->where('coupon_batch_id', $batch->id)->exists()) {
            // Codes used by orders stay readable; the batch just stops.
            $batch->forceFill(['is_active' => false])->save();
            $batch->delete();
        } else {
            DB::transaction(function () use ($batch): void {
                $batch->coupons()->delete();
                $batch->delete();
            });
        }

        $this->toast("Đã xóa đợt phát hành {$batch->code}.");

        return to_route('coupons.index');
    }

    /**
     * "Thêm mã giảm giá": one code typed, or several generated as
     * <prefix><random letters/digits>.
     */
    public function addCodes(Request $request, CouponBatch $batch): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['manual', 'generate'])],
            'code' => ['required_if:mode,manual', 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'count' => ['required_if:mode,generate', 'nullable', 'integer', 'min:1', 'max:1000'],
            'prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-]*$/'],
            'length' => ['nullable', 'integer', 'min:4', 'max:16'],
        ], [], ['code' => 'mã giảm giá', 'count' => 'số lượng mã']);

        if ($data['mode'] === 'manual') {
            $code = Coupon::normalize((string) $data['code']);

            if (Coupon::query()->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => "Mã {$code} đã tồn tại trong cửa hàng."]);
            }

            $batch->coupons()->create(['code' => $code]);
            $this->toast("Đã thêm mã {$code}.");

            return back();
        }

        $prefix = Coupon::normalize((string) ($data['prefix'] ?? ''));
        $length = (int) ($data['length'] ?? 8);
        $count = (int) $data['count'];
        $added = 0;

        DB::transaction(function () use ($batch, $prefix, $length, $count, &$added): void {
            for ($try = 0; $added < $count && $try < $count * 5; $try++) {
                // No 0/O/1/I so codes read out loud stay unambiguous.
                $code = $prefix.strtoupper(substr(str_replace(['0', 'O', '1', 'I', 'L'], '', Str::random($length * 2)), 0, $length));

                if (strlen($code) < strlen($prefix) + $length || Coupon::query()->where('code', $code)->exists()) {
                    continue;
                }

                $batch->coupons()->create(['code' => $code]);
                $added++;
            }
        });

        $this->toast("Đã tạo {$added} mã giảm giá.");

        return back();
    }

    /** Ngừng / dùng lại một mã. */
    public function toggleCode(Request $request, CouponBatch $batch, Coupon $coupon): RedirectResponse
    {
        abort_unless($coupon->batch_id === $batch->id, 404);
        $coupon->forceFill(['is_active' => $request->boolean('is_active')])->save();
        $this->toast($coupon->is_active ? "Đã kích hoạt mã {$coupon->code}." : "Đã ngừng mã {$coupon->code}.");

        return back();
    }

    public function destroyCode(CouponBatch $batch, Coupon $coupon): RedirectResponse
    {
        abort_unless($coupon->batch_id === $batch->id, 404);

        if ($coupon->used_count > 0 || OrderPromotion::query()->where('coupon_id', $coupon->id)->exists()) {
            throw ValidationException::withMessages(['code' => "Mã {$coupon->code} đã được dùng, chỉ ngừng được, không xóa được."]);
        }

        $coupon->delete();
        $this->toast("Đã xóa mã {$coupon->code}.");

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?CouponBatch $batch): array
    {
        $tenant = tenancy()->id();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\-.]+$/', Rule::unique('coupon_batches', 'code')->where('tenant_id', $tenant)->ignore($batch?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'value_type' => ['required', Rule::in(['percent', 'amount'])],
            'value' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'max_discount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'uses_per_code' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'uses_per_customer' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'combinable' => ['boolean'],
            'min_order' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'product_target_type' => ['nullable', Rule::in(array_keys(Promotion::TARGETS))],
            'product_ids' => ['array', 'max:500'],
            'product_ids.*' => ['integer'],
            ...$this->scopeRules(),
        ], [], [
            'name' => 'tên đợt phát hành',
            'code' => 'mã đợt phát hành',
            'value' => 'giá trị khuyến mại',
            'uses_per_code' => 'số lần mỗi mã được sử dụng',
        ]);

        if ($data['value_type'] === 'percent' && (int) $data['value'] > 10000) {
            throw ValidationException::withMessages(['value' => 'Giá trị khuyến mại tối đa 100%.']);
        }

        $productIds = array_values(array_unique(array_map('intval', $data['product_ids'] ?? [])));
        $target = $productIds === [] ? null : ($data['product_target_type'] ?? 'variant');

        return [
            'name' => trim((string) $data['name']),
            'code' => isset($data['code']) && $data['code'] !== '' ? mb_strtoupper((string) $data['code']) : null,
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'value_type' => $data['value_type'],
            'value' => (int) $data['value'],
            'max_discount' => $data['value_type'] === 'percent' && ! empty($data['max_discount']) ? (int) $data['max_discount'] : null,
            'uses_per_code' => isset($data['uses_per_code']) ? (int) $data['uses_per_code'] : null,
            'uses_per_customer' => isset($data['uses_per_customer']) ? (int) $data['uses_per_customer'] : null,
            'combinable' => (bool) ($data['combinable'] ?? false),
            'min_order' => (int) ($data['min_order'] ?? 0),
            'product_target_type' => $target,
            'product_ids' => $target === null ? null : $productIds,
            ...$this->scopeValues($data),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            ...$this->scopeOptions(),
            'targets' => collect(Promotion::TARGETS)->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values(),
        ];
    }
}
