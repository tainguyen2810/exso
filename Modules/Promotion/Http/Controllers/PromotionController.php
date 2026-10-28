<?php

namespace Modules\Promotion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity\ActivityLogger;
use App\Support\DataTable\DataTable;
use App\Support\Sequences\Sequence;
use App\Support\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Promotion\Http\Concerns\HandlesPromotionInput;
use Modules\Promotion\Models\OrderPromotion;
use Modules\Promotion\Models\Promotion;

/**
 * "Quản lý khuyến mại": list, create (chiết khấu / tặng quà), edit, pause,
 * stop, and the "Cấu hình khuyến mại" page.
 */
class PromotionController extends Controller
{
    use HandlesPromotionInput;

    public function index(Request $request): Response
    {
        $now = CarbonImmutable::now();

        $table = DataTable::for(Promotion::query(), $request, 'promotions')
            ->search(['code', 'name'])
            ->filterExact('kind')
            ->filterExact('method')
            ->filter('state', fn (Builder $query, string $value) => match ($value) {
                'running' => $query->where('status', Promotion::ACTIVE)->where('starts_at', '<=', $now)->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now)),
                'scheduled' => $query->where('status', Promotion::ACTIVE)->where('starts_at', '>', $now),
                'paused' => $query->where('status', Promotion::PAUSED),
                'expired' => $query->where('status', Promotion::ACTIVE)->whereNotNull('ends_at')->where('ends_at', '<', $now),
                'stopped' => $query->where('status', Promotion::STOPPED),
                default => $query,
            })
            ->filterDateRange('starts_at')
            ->sortable(['code', 'name', 'starts_at', 'ends_at', 'used_count', 'created_at'], '-id');

        return Inertia::render('promotions/index', [
            'promotions' => $table->paginate(fn (Promotion $promotion): array => [
                'id' => $promotion->id,
                'code' => $promotion->code,
                'name' => $promotion->name,
                'kind' => $promotion->kind,
                'method_label' => $promotion->methodLabel(),
                'state' => $promotion->state($now),
                'starts_at' => $promotion->starts_at->toIso8601String(),
                'ends_at' => $promotion->ends_at?->toIso8601String(),
                'usage_limit' => $promotion->usage_limit,
                'used_count' => $promotion->used_count,
                'created_at' => $promotion->created_at?->toIso8601String(),
            ]),
            'methods' => collect(Promotion::METHODS)->map(fn (array $method, string $key): array => ['value' => $key, 'label' => $method[1]])->values(),
            'states' => Promotion::STATES,
            'enabled' => (bool) settings('promotions.enabled'),
        ]);
    }

    public function create(Request $request): Response
    {
        $kind = $request->query('type') === 'gift' ? 'gift' : 'discount';

        return Inertia::render('promotions/form', [
            'promotion' => null,
            'kind' => $kind,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $values = $this->validated($request, null);

        $promotion = new Promotion([...$values, 'created_by' => $user->id]);
        $promotion->code = $values['code'] ?? Sequence::next('KM', Promotion::class);
        $promotion->save();

        $this->toast("Đã tạo chương trình khuyến mại {$promotion->code}.");

        return to_route('promotions.show', $promotion);
    }

    public function show(Promotion $promotion): Response
    {
        $promotion->load('creator:id,name');
        $variantIds = [];

        foreach ($promotion->conditions as $row) {
            foreach (['ids', 'buy_ids', 'get_ids', 'gift_ids'] as $key) {
                $variantIds = [...$variantIds, ...array_values(array_map('intval', $row[$key] ?? []))];
            }
        }

        return Inertia::render('promotions/form', [
            'promotion' => [
                'id' => $promotion->id,
                'code' => $promotion->code,
                'name' => $promotion->name,
                'description' => $promotion->description,
                'kind' => $promotion->kind,
                'method' => $promotion->method,
                'status' => $promotion->status,
                'state' => $promotion->state(),
                'starts_at' => $promotion->starts_at->setTimezone((string) config('app.timezone'))->format('Y-m-d\TH:i'),
                'ends_at' => $promotion->ends_at?->setTimezone((string) config('app.timezone'))->format('Y-m-d\TH:i'),
                'schedule' => $promotion->schedule,
                'usage_limit' => $promotion->usage_limit,
                'used_count' => $promotion->used_count,
                'target_type' => $promotion->target_type,
                'get_target_type' => $promotion->get_target_type,
                'repeatable' => $promotion->repeatable,
                'conditions' => $promotion->conditions,
                'all_branches' => $promotion->all_branches,
                'branch_ids' => $promotion->branch_ids ?? [],
                'all_sources' => $promotion->all_sources,
                'source_ids' => $promotion->source_ids ?? [],
                'all_customers' => $promotion->all_customers,
                'customer_group_ids' => $promotion->customer_group_ids ?? [],
                'creator' => $promotion->creator?->name,
                'created_at' => $promotion->created_at?->toIso8601String(),
                'orders' => OrderPromotion::query()->with('order:id,code,total,status')->where('promotion_id', $promotion->id)->latest('id')->limit(20)->get()
                    ->map(fn (OrderPromotion $use): array => [
                        'order_id' => $use->order_id,
                        'code' => $use->order->code,
                        'amount' => $use->amount,
                        'created_at' => $use->created_at?->toIso8601String(),
                    ])->all(),
            ],
            'kind' => $promotion->kind,
            'variants' => $this->variantLabels($variantIds),
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Promotion $promotion): RedirectResponse
    {
        $values = $this->validated($request, $promotion);
        $promotion->fill($values);
        $promotion->save();

        $this->toast("Đã lưu chương trình khuyến mại {$promotion->code}.");

        return back();
    }

    /** Tạm ngừng, kích hoạt lại, hoặc kết thúc chương trình. */
    public function status(Request $request, Promotion $promotion): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in([Promotion::ACTIVE, Promotion::PAUSED, Promotion::STOPPED])]])['status'];

        if ($promotion->status === Promotion::STOPPED) {
            throw ValidationException::withMessages(['status' => 'Chương trình đã kết thúc, không đổi trạng thái được.']);
        }

        $promotion->status = $status;
        $promotion->save();

        $this->toast(match ($status) {
            Promotion::PAUSED => 'Đã tạm ngừng chương trình.',
            Promotion::STOPPED => 'Đã kết thúc chương trình.',
            default => 'Đã kích hoạt chương trình.',
        });

        return back();
    }

    public function destroy(Promotion $promotion): RedirectResponse
    {
        $promotion->delete();
        $this->toast("Đã xóa chương trình khuyến mại {$promotion->code}.");

        return to_route('promotions.index');
    }

    /** "Cấu hình khuyến mại". */
    public function settings(): Response
    {
        return Inertia::render('promotions/settings', [
            'values' => [
                'enabled' => (bool) settings('promotions.enabled'),
                'apply_mode' => settings('promotions.apply_mode') === 'many' ? 'many' : 'one',
                'coupons_enabled' => (bool) settings('coupons.enabled'),
            ],
        ]);
    }

    public function saveSettings(Request $request, SettingsRepository $settings, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'apply_mode' => ['required', Rule::in(['one', 'many'])],
            'coupons_enabled' => ['required', 'boolean'],
        ]);

        $values = [
            'promotions.enabled' => (bool) $data['enabled'],
            'promotions.apply_mode' => $data['apply_mode'],
            'coupons.enabled' => (bool) $data['coupons_enabled'],
        ];
        $old = [];

        foreach ($values as $key => $value) {
            if ($settings->get($key) !== $value) {
                $old[$key] = $settings->get($key);
            }
        }

        $settings->setMany($values);

        if ($old !== []) {
            $activity->log('updated', 'Sửa cấu hình khuyến mại', null, ['old' => $old, 'new' => array_intersect_key($values, $old)]);
        }

        $this->toast('Đã lưu cấu hình khuyến mại.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Promotion $promotion): array
    {
        $tenant = tenancy()->id();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\-.]+$/', Rule::unique('promotions', 'code')->where('tenant_id', $tenant)->ignore($promotion?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'method' => ['required', Rule::in(array_keys(Promotion::METHODS))],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'target_type' => ['nullable', Rule::in(array_keys(Promotion::TARGETS))],
            'get_target_type' => ['nullable', Rule::in(array_keys(Promotion::TARGETS))],
            'repeatable' => ['boolean'],
            'schedule' => ['nullable', 'array'],
            'schedule.time_from' => ['nullable', 'date_format:H:i'],
            'schedule.time_to' => ['nullable', 'date_format:H:i'],
            'schedule.weekdays' => ['array'],
            'schedule.weekdays.*' => ['integer', 'between:1,7'],
            'schedule.months' => ['array'],
            'schedule.months.*' => ['integer', 'between:1,12'],
            'schedule.days' => ['array'],
            'schedule.days.*' => ['integer', 'between:1,31'],
            'conditions' => ['required', 'array', 'min:1', 'max:100'],
            ...$this->scopeRules(),
        ], [], [
            'name' => 'tên khuyến mại',
            'code' => 'mã khuyến mại',
            'usage_limit' => 'số lượng áp dụng',
            'conditions' => 'điều kiện khuyến mại',
        ]);

        $method = (string) $data['method'];

        // The method of a program that orders already used stays as it was.
        if ($promotion !== null && $promotion->used_count > 0 && $promotion->method !== $method) {
            throw ValidationException::withMessages(['method' => 'Chương trình đã được áp dụng cho đơn hàng, không đổi phương thức được.']);
        }

        $schedule = $data['schedule'] ?? [];
        $schedule = [
            'time_from' => ($schedule['time_from'] ?? null) ?: null,
            'time_to' => ($schedule['time_to'] ?? null) ?: null,
            'weekdays' => array_values(array_unique(array_map('intval', $schedule['weekdays'] ?? []))),
            'months' => array_values(array_unique(array_map('intval', $schedule['months'] ?? []))),
            'days' => array_values(array_unique(array_map('intval', $schedule['days'] ?? []))),
        ];

        if (($schedule['time_from'] === null) !== ($schedule['time_to'] === null)) {
            throw ValidationException::withMessages(['schedule.time_to' => 'Nhập đủ giờ bắt đầu và giờ kết thúc của khung giờ.']);
        }

        return [
            'name' => trim((string) $data['name']),
            'code' => isset($data['code']) && $data['code'] !== '' ? mb_strtoupper((string) $data['code']) : null,
            'description' => $data['description'] ?? null,
            'kind' => Promotion::METHODS[$method][0],
            'method' => $method,
            'usage_limit' => isset($data['usage_limit']) ? (int) $data['usage_limit'] : null,
            'target_type' => $data['target_type'] ?? 'variant',
            'get_target_type' => $data['get_target_type'] ?? 'variant',
            'repeatable' => (bool) ($data['repeatable'] ?? false),
            'schedule' => array_filter($schedule) === [] ? null : $schedule,
            'conditions' => $this->conditions($method, $data['conditions']),
            ...$this->scopeValues($data),
        ];
    }

    /**
     * Check and clean the rows of a method; percentages arrive in basis points.
     *
     * @param  array<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    protected function conditions(string $method, array $rows): array
    {
        $clean = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $line = $index + 1;
            $fail = fn (string $message) => throw ValidationException::withMessages(['conditions' => "Điều kiện {$line}: {$message}"]);
            $int = fn (string $key, int $default = 0): int => max(0, (int) ($row[$key] ?? $default));
            $ids = function (string $key) use ($row, $fail): array {
                $ids = array_values(array_unique(array_filter(array_map('intval', is_array($row[$key] ?? null) ? $row[$key] : []))));

                if ($ids === []) {
                    $fail('chọn sản phẩm.');
                }

                return array_slice($ids, 0, 500);
            };
            $discount = function (array $source) use ($fail): array {
                $type = ($source['type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
                $value = max(0, (int) ($source['value'] ?? 0));

                if ($value <= 0 || ($type === 'percent' && $value > 10000)) {
                    $fail($type === 'percent' ? 'chiết khấu phải từ 0,01% đến 100%.' : 'nhập số tiền chiết khấu.');
                }

                return ['type' => $type, 'value' => $value];
            };

            $clean[] = match ($method) {
                'order_total' => (function () use ($int, $row, $discount, $fail): array {
                    $max = isset($row['max']) && $row['max'] !== '' ? $int('max') : null;

                    if ($max !== null && $max < $int('min')) {
                        $fail('giá trị đến phải lớn hơn giá trị từ.');
                    }

                    return ['min' => $int('min'), 'max' => $max, ...$discount($row)];
                })(),
                'product' => ['ids' => $ids('ids'), 'min_qty' => max(1, $int('min_qty', 1)), 'limit_qty' => $int('limit_qty') ?: null, ...$discount($row)],
                'buy_more' => ['min_order' => $int('min_order'), 'ids' => $ids('ids'), 'max_qty' => max(1, $int('max_qty', 1)), ...$discount($row)],
                'quantity' => (function () use ($row, $ids, $discount, $fail): array {
                    $tiers = [];

                    foreach (is_array($row['tiers'] ?? null) ? $row['tiers'] : [] as $tier) {
                        $from = max(1, (int) ($tier['from'] ?? 1));
                        $to = isset($tier['to']) && $tier['to'] !== '' ? (int) $tier['to'] : null;

                        if ($to !== null && $to < $from) {
                            $fail('số lượng đến phải lớn hơn số lượng từ.');
                        }

                        $tiers[] = ['from' => $from, 'to' => $to, ...$discount($tier)];
                    }

                    if ($tiers === []) {
                        $fail('thêm mức số lượng.');
                    }

                    usort($tiers, fn (array $a, array $b): int => $a['from'] <=> $b['from']);

                    return ['ids' => $ids('ids'), 'tiers' => $tiers];
                })(),
                'buy_get' => ['buy_ids' => $ids('buy_ids'), 'buy_qty' => max(1, $int('buy_qty', 1)), 'get_ids' => $ids('get_ids'), 'get_qty' => max(1, $int('get_qty', 1)), ...$discount($row)],
                'gift_order' => ['min_order' => $int('min_order'), 'gift_ids' => $ids('gift_ids'), 'gift_qty' => max(1, $int('gift_qty', 1))],
                'gift_product' => ['buy_ids' => $ids('buy_ids'), 'buy_qty' => max(1, $int('buy_qty', 1)), 'gift_ids' => $ids('gift_ids'), 'gift_qty' => max(1, $int('gift_qty', 1))],
                default => [],
            };
        }

        if ($clean === []) {
            throw ValidationException::withMessages(['conditions' => 'Thêm ít nhất một điều kiện khuyến mại.']);
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            ...$this->scopeOptions(),
            'methods' => collect(Promotion::METHODS)->map(fn (array $method, string $key): array => ['value' => $key, 'kind' => $method[0], 'label' => $method[1]])->values(),
            'targets' => collect(Promotion::TARGETS)->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values(),
        ];
    }
}
