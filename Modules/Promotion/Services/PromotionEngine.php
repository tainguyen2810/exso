<?php

namespace Modules\Promotion\Services;

use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Customer\Models\Customer;
use Modules\Order\Contracts\OrderPromotions;
use Modules\Order\Contracts\PromotionContext;
use Modules\Order\Contracts\PromotionOutcome;
use Modules\Order\Models\Order;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Promotion\Models\Coupon;
use Modules\Promotion\Models\CouponBatch;
use Modules\Promotion\Models\OrderPromotion;
use Modules\Promotion\Models\Promotion;

/**
 * PromotionEngine: works out, on the server, what the chosen programs and
 * coupon give an order. The order form and POS call the same preview, and
 * saving runs it again, so both show and save the same result.
 *
 * Rules when programs overlap ("quy tắc ưu tiên"):
 *  - settings promotions.apply_mode = one: one program per order (Sapo's
 *    default); many: several, but
 *  - a line gets at most one program discount: the largest;
 *  - the order total gets at most one program discount: the largest;
 *    order thresholds ("giá trị đơn hàng") use the lines after their own
 *    discounts and before promotions; percentages apply after line promotions;
 *  - gifts of every chosen program are added;
 *  - the coupon comes last, on what is left, and only with programs when
 *    its batch is "áp dụng chung với các khuyến mại khác";
 *  - the programs offered are sorted by value so the best one is first.
 *
 * @phpstan-import-type PromoLine from PromotionContext
 *
 * @phpstan-type Spec array{type: string, value: int}
 * @phpstan-type Gift array{row: int, quantity: int, options: list<int>, variant_id: int}
 * @phpstan-type Benefit array{lines: array<int, int>, order: Spec|null, gifts: list<Gift>}
 */
class PromotionEngine implements OrderPromotions
{
    public function __construct(protected LoyaltyService $loyalty) {}

    public function apply(PromotionContext $context, bool $strict): PromotionOutcome
    {
        $errors = [];
        $at = $context->at ?? CarbonImmutable::now();
        $lines = $context->lines;
        $info = $this->productInfo($lines);
        $base = array_sum(array_column($lines, 'total'));
        $enabled = (bool) settings('promotions.enabled');

        /** @var Collection<int, Promotion> $candidates */
        $candidates = $lines === [] || (! $enabled && $context->keepIds === [])
            ? collect()
            : Promotion::query()
                ->where(fn ($query) => $query->where('status', Promotion::ACTIVE)->orWhereIn('id', $context->keepIds))
                ->orderBy('id')
                ->get();

        /** @var array<int, Benefit> $benefits */
        $benefits = [];

        foreach ($candidates as $promotion) {
            $kept = in_array($promotion->id, $context->keepIds, true);

            if (! $kept && (! $enabled || ! $this->eligible($promotion, $context, $at))) {
                continue;
            }

            $benefit = $this->evaluate($promotion, $lines, $info, $base, $context->gifts);

            if ($benefit['lines'] !== [] || $benefit['order'] !== null || $benefit['gifts'] !== []) {
                $benefits[$promotion->id] = $benefit;
            }
        }

        $giftVariants = $this->giftVariants($benefits);
        $available = $strict ? [] : $this->available($candidates, $benefits, $base, $giftVariants);

        // The programs chosen, in the order given.
        $chosen = [];

        if ($context->promotionIds !== [] && ! $enabled && array_diff($context->promotionIds, $context->keepIds) !== []) {
            $errors['promotion_ids'] = 'Cửa hàng đang tắt chương trình khuyến mại.';
        } else {
            foreach ($context->promotionIds as $id) {
                if (isset($benefits[$id])) {
                    $chosen[] = $id;

                    continue;
                }

                $name = $candidates->firstWhere('id', $id)->name ?? Promotion::query()->whereKey($id)->value('name');
                $errors['promotion_ids'] = $name === null
                    ? 'Chương trình khuyến mại không tồn tại.'
                    : "Chương trình \"{$name}\" không áp dụng được cho đơn hàng này.";
            }

            if (count($chosen) > 1 && settings('promotions.apply_mode') !== 'many') {
                $errors['promotion_ids'] = 'Mỗi đơn hàng chỉ áp dụng một chương trình khuyến mại (Cấu hình khuyến mại).';
                $chosen = [$chosen[0]];
            }
        }

        // A line keeps the largest discount among the chosen programs.
        $lineDiscounts = [];
        $won = [];

        foreach ($chosen as $id) {
            foreach ($benefits[$id]['lines'] as $index => $amount) {
                if ($amount > ($lineDiscounts[$index]['amount'] ?? 0)) {
                    $lineDiscounts[$index] = ['amount' => $amount, 'promotion_id' => $id, 'name' => (string) $candidates->firstWhere('id', $id)?->name];
                }
            }
        }

        foreach ($lineDiscounts as $line) {
            $won[$line['promotion_id']] = ($won[$line['promotion_id']] ?? 0) + $line['amount'];
        }

        // One order discount: the largest, on the lines after their promotions.
        $afterLines = $base - array_sum(array_column($lineDiscounts, 'amount'));
        $orderDiscount = 0;
        $orderWinner = null;

        foreach ($chosen as $id) {
            $spec = $benefits[$id]['order'];
            $amount = $spec === null ? 0 : $this->amountOf($spec, $afterLines);

            if ($amount > $orderDiscount) {
                $orderDiscount = $amount;
                $orderWinner = $id;
            }
        }

        if ($orderWinner !== null) {
            $won[$orderWinner] = ($won[$orderWinner] ?? 0) + $orderDiscount;
        }

        $gifts = [];

        foreach ($chosen as $id) {
            foreach ($benefits[$id]['gifts'] as $gift) {
                $gifts[] = ['variant_id' => $gift['variant_id'], 'promotion_id' => $id, 'row' => $gift['row'], 'quantity' => $gift['quantity']];
                $won[$id] ??= 0;
            }
        }

        $applied = [];

        foreach ($chosen as $id) {
            if (array_key_exists($id, $won)) {
                /** @var Promotion $promotion */
                $promotion = $candidates->firstWhere('id', $id);
                $applied[] = ['promotion_id' => $id, 'coupon_id' => null, 'name' => $promotion->name, 'code' => $promotion->code, 'amount' => $won[$id]];
            }
        }

        // The coupon, on what is left.
        if ($context->couponCode !== null) {
            $coupon = $this->coupon($context, $at, $lines, $info, $base, $applied !== [], $errors);

            if ($coupon !== null) {
                $batch = $coupon->batch;
                $amount = $this->couponAmount($batch, $afterLines - $orderDiscount);
                $orderDiscount += $amount;
                $applied[] = ['promotion_id' => null, 'coupon_id' => $coupon->id, 'name' => $batch->name, 'code' => $coupon->code, 'amount' => $amount];
            }
        }

        if ($strict && $errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new PromotionOutcome(
            lineDiscounts: $lineDiscounts,
            gifts: $gifts,
            orderDiscount: $orderDiscount,
            applied: $applied,
            errors: $errors,
            available: $available,
        );
    }

    public function record(Order $order, PromotionOutcome $outcome): void
    {
        $applied = $outcome->applied;
        usort($applied, fn (array $a, array $b): int => [$a['promotion_id'] ?? 0, $a['coupon_id'] ?? 0] <=> [$b['promotion_id'] ?? 0, $b['coupon_id'] ?? 0]);

        foreach ($applied as $use) {
            $batchId = null;

            if ($use['promotion_id'] !== null) {
                /** @var Promotion|null $promotion */
                $promotion = Promotion::query()->withTrashed()->lockForUpdate()->find($use['promotion_id']);

                if ($promotion === null) {
                    continue;
                }

                if (! $promotion->hasRoom()) {
                    throw ValidationException::withMessages(['promotion_ids' => "Chương trình \"{$promotion->name}\" đã hết lượt áp dụng."]);
                }

                $promotion->increment('used_count');
            }

            if ($use['coupon_id'] !== null) {
                /** @var Coupon|null $coupon */
                $coupon = Coupon::query()->lockForUpdate()->find($use['coupon_id']);

                if ($coupon === null) {
                    continue;
                }

                /** @var CouponBatch $batch */
                $batch = CouponBatch::query()->withTrashed()->lockForUpdate()->findOrFail($coupon->batch_id);

                if ($batch->uses_per_code !== null && $coupon->used_count >= $batch->uses_per_code) {
                    throw ValidationException::withMessages(['coupon_code' => "Mã {$coupon->code} đã hết lượt sử dụng."]);
                }

                if ($batch->uses_per_customer !== null && $order->customer_id !== null && $this->customerUses($coupon, $order->customer_id) >= $batch->uses_per_customer) {
                    throw ValidationException::withMessages(['coupon_code' => "Khách hàng đã dùng hết lượt của mã {$coupon->code}."]);
                }

                $coupon->increment('used_count');
                $batch->increment('used_count');
                $batchId = $batch->id;
            }

            OrderPromotion::query()->create([
                'order_id' => $order->id,
                'promotion_id' => $use['promotion_id'],
                'coupon_id' => $use['coupon_id'],
                'coupon_batch_id' => $batchId,
                'customer_id' => $order->customer_id,
                'name' => $use['name'],
                'code' => $use['code'],
                'amount' => $use['amount'],
                'created_at' => now(),
            ]);
        }
    }

    public function release(Order $order): void
    {
        $uses = OrderPromotion::query()->where('order_id', $order->id)->orderBy('promotion_id')->orderBy('coupon_id')->get();

        foreach ($uses as $use) {
            if ($use->promotion_id !== null) {
                Promotion::query()->withTrashed()->whereKey($use->promotion_id)->where('used_count', '>', 0)->lockForUpdate()->decrement('used_count');
            }

            if ($use->coupon_id !== null) {
                Coupon::query()->whereKey($use->coupon_id)->where('used_count', '>', 0)->lockForUpdate()->decrement('used_count');
            }

            if ($use->coupon_batch_id !== null) {
                CouponBatch::query()->withTrashed()->whereKey($use->coupon_batch_id)->where('used_count', '>', 0)->lockForUpdate()->decrement('used_count');
            }

            $use->delete();
        }
    }

    public function beforePayment(Order $order, PaymentMethod $method, int $amount): void
    {
        if ($method->type === 'points') {
            $this->loyalty->payWithPoints($order, $amount);
        }
    }

    /**
     * Dates, hours, branch, source, customer group and usage limit.
     */
    public function eligible(Promotion $promotion, PromotionContext $context, CarbonImmutable $at): bool
    {
        return $promotion->status === Promotion::ACTIVE
            && $this->inSchedule($promotion->starts_at, $promotion->ends_at, $promotion->schedule, $at)
            && $promotion->hasRoom()
            && $this->inScope($promotion->all_branches, $promotion->branch_ids, $context->branchId)
            && $this->inScope($promotion->all_sources, $promotion->source_ids, $context->sourceId)
            && $this->customerInScope($promotion->all_customers, $promotion->customer_group_ids, $context->customer);
    }

    /**
     * @param  array{time_from?: string|null, time_to?: string|null, weekdays?: list<int>, months?: list<int>, days?: list<int>}|null  $schedule
     */
    public function inSchedule(CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, ?array $schedule, CarbonImmutable $at): bool
    {
        if ($startsAt->gt($at) || ($endsAt !== null && $endsAt->lt($at))) {
            return false;
        }

        $local = $at->setTimezone((string) config('app.timezone'));
        $from = $schedule['time_from'] ?? null;
        $to = $schedule['time_to'] ?? null;

        if ($from && $to) {
            $now = $local->format('H:i');
            // A window like 22:00 - 02:00 runs over midnight.
            $inside = $from <= $to ? ($now >= $from && $now <= $to) : ($now >= $from || $now <= $to);

            if (! $inside) {
                return false;
            }
        }

        foreach (['weekdays' => $local->dayOfWeekIso, 'months' => $local->month, 'days' => $local->day] as $key => $value) {
            $allowed = $schedule[$key] ?? [];

            if ($allowed !== [] && ! in_array($value, array_map('intval', $allowed), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What a program gives these lines (before choosing among programs).
     *
     * @param  list<PromoLine>  $lines
     * @param  array<int, array{category_id: int|null, brand_id: int|null}>  $info
     * @param  array<string, int>  $giftChoices
     * @return Benefit
     */
    public function evaluate(Promotion $promotion, array $lines, array $info, int $base, array $giftChoices = []): array
    {
        $result = ['lines' => [], 'order' => null, 'gifts' => []];
        $rows = $promotion->conditions ?? [];
        $target = $promotion->target_type;
        $getTarget = $promotion->get_target_type;

        switch ($promotion->method) {
            case 'order_total':
                $best = null;

                foreach ($rows as $row) {
                    $min = (int) ($row['min'] ?? 0);
                    $max = isset($row['max']) && $row['max'] !== '' ? (int) $row['max'] : null;

                    if ($base >= $min && ($max === null || $base <= $max) && ($best === null || $min > (int) ($best['min'] ?? 0))) {
                        $best = $row;
                    }
                }

                $result['order'] = $best === null ? null : $this->spec($best);
                break;

            case 'product':
                foreach ($rows as $row) {
                    $indexes = $this->matching($lines, $info, $target, $row['ids'] ?? []);
                    $quantity = $this->quantityOf($lines, $indexes);

                    if ($indexes !== [] && $quantity >= max(1, (int) ($row['min_qty'] ?? 1))) {
                        $limit = isset($row['limit_qty']) && (int) $row['limit_qty'] > 0 ? (int) $row['limit_qty'] : null;
                        $this->allocate($result['lines'], $lines, $indexes, $limit === null ? $quantity : min($quantity, $limit), $this->spec($row));
                    }
                }
                break;

            case 'quantity':
                foreach ($rows as $row) {
                    $indexes = $this->matching($lines, $info, $target, $row['ids'] ?? []);
                    $quantity = $this->quantityOf($lines, $indexes);

                    foreach ($row['tiers'] ?? [] as $tier) {
                        $from = max(1, (int) ($tier['from'] ?? 1));
                        $to = isset($tier['to']) && $tier['to'] !== '' ? (int) $tier['to'] : null;

                        if ($indexes !== [] && $quantity >= $from && ($to === null || $quantity <= $to)) {
                            $this->allocate($result['lines'], $lines, $indexes, $quantity, $this->spec($tier));
                            break;
                        }
                    }
                }
                break;

            case 'buy_more':
                foreach ($this->topRows($rows, $base) as $row) {
                    $indexes = $this->matching($lines, $info, $target, $row['ids'] ?? []);
                    $this->allocate($result['lines'], $lines, $indexes, max(1, (int) ($row['max_qty'] ?? 1)), $this->spec($row));
                }
                break;

            case 'buy_get':
                foreach ($rows as $row) {
                    [$times, $left] = $this->bought($lines, $info, $target, $row, $promotion->repeatable);

                    if ($times > 0) {
                        $indexes = $this->matching($lines, $info, $getTarget, $row['get_ids'] ?? []);
                        $this->allocate($result['lines'], $lines, $indexes, $times * max(1, (int) ($row['get_qty'] ?? 1)), $this->spec($row), $left);
                    }
                }
                break;

            case 'gift_order':
                foreach ($this->topRows($rows, $base) as $index => $row) {
                    $this->gift($result['gifts'], $promotion, $index, $row, max(1, (int) ($row['gift_qty'] ?? 1)), $giftChoices);
                }
                break;

            case 'gift_product':
                foreach ($rows as $index => $row) {
                    [$times] = $this->bought($lines, $info, $target, $row, $promotion->repeatable);

                    if ($times > 0) {
                        $this->gift($result['gifts'], $promotion, $index, $row, $times * max(1, (int) ($row['gift_qty'] ?? 1)), $giftChoices);
                    }
                }
                break;
        }

        return $result;
    }

    /**
     * @param  Spec  $spec
     */
    public function unitDiscount(int $price, array $spec): int
    {
        return $spec['type'] === 'percent'
            ? intdiv($price * min(10000, max(0, $spec['value'])) + 5000, 10000)
            : min(max(0, $spec['value']), $price);
    }

    /**
     * @param  Spec  $spec
     */
    public function amountOf(array $spec, int $total): int
    {
        return min(max(0, $total), $spec['type'] === 'percent'
            ? intdiv(max(0, $total) * min(10000, max(0, $spec['value'])) + 5000, 10000)
            : max(0, $spec['value']));
    }

    public function couponAmount(CouponBatch $batch, int $total): int
    {
        $amount = $this->amountOf(['type' => $batch->value_type, 'value' => $batch->value], $total);

        return $batch->value_type === 'percent' && $batch->max_discount !== null && $batch->max_discount > 0
            ? min($amount, $batch->max_discount)
            : $amount;
    }

    /**
     * Category and brand of the lines' products.
     *
     * @param  list<PromoLine>  $lines
     * @return array<int, array{category_id: int|null, brand_id: int|null}>
     */
    public function productInfo(array $lines): array
    {
        $ids = array_values(array_unique(array_filter(array_column($lines, 'product_id'))));

        if ($ids === []) {
            return [];
        }

        return Product::query()->withTrashed()->whereIn('id', $ids)->get(['id', 'category_id', 'brand_id'])
            ->mapWithKeys(fn ($product): array => [$product->id => ['category_id' => $product->category_id, 'brand_id' => $product->brand_id]])
            ->all();
    }

    /**
     * Find the coupon typed and check its batch; on a problem, note it and return null.
     *
     * @param  list<PromoLine>  $lines
     * @param  array<int, array{category_id: int|null, brand_id: int|null}>  $info
     * @param  array<string, string>  $errors
     */
    protected function coupon(PromotionContext $context, CarbonImmutable $at, array $lines, array $info, int $base, bool $withPrograms, array &$errors): ?Coupon
    {
        $code = Coupon::normalize((string) $context->couponCode);
        $kept = $context->keepCoupon !== null && Coupon::normalize($context->keepCoupon) === $code;

        if (! settings('coupons.enabled') && ! $kept) {
            $errors['coupon_code'] = 'Cửa hàng đang tắt mã giảm giá.';

            return null;
        }

        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()->with('batch')->where('code', $code)->first();
        $batch = $coupon?->batch;

        if ($coupon === null || $batch === null || $batch->trashed() || (! $kept && ! $coupon->is_active)) {
            $errors['coupon_code'] = "Mã giảm giá {$code} không tồn tại.";

            return null;
        }

        $customer = $context->customer;
        $error = match (true) {
            $kept => null,
            ! $batch->is_active => 'Mã giảm giá đang tạm ngừng.',
            ! $this->inSchedule($batch->starts_at, $batch->ends_at, null, $at) => $batch->starts_at->gt($at) ? 'Mã giảm giá chưa đến thời gian áp dụng.' : 'Mã giảm giá đã hết hạn.',
            ! $this->inScope($batch->all_branches, $batch->branch_ids, $context->branchId) => 'Mã giảm giá không áp dụng ở chi nhánh này.',
            ! $this->inScope($batch->all_sources, $batch->source_ids, $context->sourceId) => 'Mã giảm giá không áp dụng cho nguồn đơn này.',
            ! $this->customerInScope($batch->all_customers, $batch->customer_group_ids, $customer) => 'Mã giảm giá không áp dụng cho nhóm khách hàng này.',
            $batch->uses_per_code !== null && $coupon->used_count >= $batch->uses_per_code => 'Mã giảm giá đã hết lượt sử dụng.',
            $batch->uses_per_customer !== null && $customer === null => 'Chọn khách hàng để dùng mã này (mỗi khách dùng tối đa '.$batch->uses_per_customer.' lần).',
            $batch->uses_per_customer !== null && $this->customerUses($coupon, $customer->id) >= $batch->uses_per_customer => 'Khách hàng đã dùng hết lượt của mã này.',
            default => null,
        };

        // Purchase conditions hold even for a kept coupon: the order must still qualify.
        $error ??= match (true) {
            $base < $batch->min_order => 'Mã giảm giá áp dụng cho đơn từ '.number_format($batch->min_order, 0, ',', '.').'đ.',
            $batch->product_target_type !== null && ($batch->product_ids ?? []) !== [] && $this->matching($lines, $info, $batch->product_target_type, $batch->product_ids ?? []) === [] => 'Đơn hàng chưa có sản phẩm áp dụng mã giảm giá.',
            $withPrograms && ! $batch->combinable => 'Mã giảm giá không dùng chung với chương trình khuyến mại khác.',
            default => null,
        };

        if ($error !== null) {
            $errors['coupon_code'] = $error;

            return null;
        }

        return $coupon;
    }

    protected function customerUses(Coupon $coupon, int $customerId): int
    {
        return OrderPromotion::query()->where('coupon_id', $coupon->id)->where('customer_id', $customerId)->count();
    }

    /**
     * @param  list<int>|null  $ids
     */
    protected function inScope(bool $all, ?array $ids, ?int $id): bool
    {
        return $all || ($id !== null && in_array($id, array_map('intval', $ids ?? []), true));
    }

    /**
     * @param  list<int>|null  $groupIds
     */
    protected function customerInScope(bool $all, ?array $groupIds, ?Customer $customer): bool
    {
        return $all || ($customer !== null && $customer->group_id !== null && in_array($customer->group_id, array_map('intval', $groupIds ?? []), true));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return Spec
     */
    protected function spec(array $row): array
    {
        return ['type' => ($row['type'] ?? 'percent') === 'amount' ? 'amount' : 'percent', 'value' => max(0, (int) ($row['value'] ?? 0))];
    }

    /**
     * Indexes of the lines whose product is one of $ids (variants,
     * categories or brands). Service lines without a product never match.
     *
     * @param  list<PromoLine>  $lines
     * @param  array<int, array{category_id: int|null, brand_id: int|null}>  $info
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    protected function matching(array $lines, array $info, string $target, array $ids): array
    {
        $ids = array_map('intval', $ids);
        $found = [];

        foreach ($lines as $index => $line) {
            if ($line['variant_id'] === null) {
                continue;
            }

            $value = match ($target) {
                'category' => $info[$line['product_id']]['category_id'] ?? null,
                'brand' => $info[$line['product_id']]['brand_id'] ?? null,
                default => $line['variant_id'],
            };

            if ($value !== null && in_array((int) $value, $ids, true)) {
                $found[] = $index;
            }
        }

        return $found;
    }

    /**
     * @param  list<PromoLine>  $lines
     * @param  list<int>  $indexes
     */
    protected function quantityOf(array $lines, array $indexes): int
    {
        return array_sum(array_map(fn (int $index): int => $lines[$index]['quantity'], $indexes));
    }

    /**
     * Discount up to $units units of the matching lines, in cart order;
     * a line keeps the largest discount it is given.
     *
     * @param  array<int, int>  $result
     * @param  list<PromoLine>  $lines
     * @param  list<int>  $indexes
     * @param  Spec  $spec
     * @param  array<int, int>|null  $left  units of each line not already used to qualify
     */
    protected function allocate(array &$result, array $lines, array $indexes, int $units, array $spec, ?array $left = null): void
    {
        foreach ($indexes as $index) {
            if ($units <= 0) {
                break;
            }

            $line = $lines[$index];
            $quantity = min($units, $left[$index] ?? $line['quantity']);

            if ($quantity <= 0) {
                continue;
            }

            $amount = min($quantity * $this->unitDiscount($line['price'], $spec), $line['total']);
            $result[$index] = max($result[$index] ?? 0, $amount);
            $units -= $quantity;
        }
    }

    /**
     * How many times the "buy" part of a row is met, and the units of each
     * line left once those bought units are set aside (a product both
     * bought and discounted is not discounted on the units that qualified).
     *
     * @param  list<PromoLine>  $lines
     * @param  array<int, array{category_id: int|null, brand_id: int|null}>  $info
     * @param  array<string, mixed>  $row
     * @return array{0: int, 1: array<int, int>}
     */
    protected function bought(array $lines, array $info, string $target, array $row, bool $repeatable): array
    {
        $indexes = $this->matching($lines, $info, $target, $row['buy_ids'] ?? []);
        $need = max(1, (int) ($row['buy_qty'] ?? 1));
        $quantity = $this->quantityOf($lines, $indexes);
        $times = $quantity < $need ? 0 : ($repeatable ? intdiv($quantity, $need) : 1);
        $left = array_map(fn (array $line): int => $line['quantity'], $lines);
        $used = $times * $need;

        foreach ($indexes as $index) {
            $take = min($used, $left[$index]);
            $left[$index] -= $take;
            $used -= $take;
        }

        return [$times, $left];
    }

    /**
     * The rows with the highest order threshold the order reaches (tiers).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function topRows(array $rows, int $base): array
    {
        $reached = array_filter($rows, fn (array $row): bool => $base >= (int) ($row['min_order'] ?? 0));

        if ($reached === []) {
            return [];
        }

        $top = max(array_map(fn (array $row): int => (int) ($row['min_order'] ?? 0), $reached));

        return array_filter($reached, fn (array $row): bool => (int) ($row['min_order'] ?? 0) === $top);
    }

    /**
     * @param  list<Gift>  $gifts
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $choices
     */
    protected function gift(array &$gifts, Promotion $promotion, int $index, array $row, int $quantity, array $choices): void
    {
        $options = array_values(array_unique(array_map('intval', $row['gift_ids'] ?? [])));

        if ($options === []) {
            return;
        }

        $choice = $choices["{$promotion->id}-{$index}"] ?? null;

        $gifts[] = [
            'row' => $index,
            'quantity' => $quantity,
            'options' => $options,
            'variant_id' => $choice !== null && in_array((int) $choice, $options, true) ? (int) $choice : $options[0],
        ];
    }

    /**
     * Gift variants of all benefits, for names and value.
     *
     * @param  array<int, Benefit>  $benefits
     * @return Collection<int, ProductVariant>
     */
    protected function giftVariants(array $benefits): Collection
    {
        $ids = [];

        foreach ($benefits as $benefit) {
            foreach ($benefit['gifts'] as $gift) {
                $ids = [...$ids, ...$gift['options']];
            }
        }

        return $ids === []
            ? collect()
            : ProductVariant::query()->whereIn('id', array_unique($ids))->get(['id', 'name', 'sku', 'unit', 'retail_price'])->keyBy('id');
    }

    /**
     * Programs the order qualifies for, best value first, for the dialog.
     *
     * @param  Collection<int, Promotion>  $candidates
     * @param  array<int, Benefit>  $benefits
     * @param  Collection<int, ProductVariant>  $giftVariants
     * @return list<array<string, mixed>>
     */
    protected function available(Collection $candidates, array $benefits, int $base, Collection $giftVariants): array
    {
        $list = [];

        foreach ($benefits as $id => $benefit) {
            /** @var Promotion $promotion */
            $promotion = $candidates->firstWhere('id', $id);
            $lines = array_sum($benefit['lines']);
            $order = $benefit['order'] === null ? 0 : $this->amountOf($benefit['order'], $base - $lines);
            $giftValue = 0;
            $gifts = [];

            foreach ($benefit['gifts'] as $gift) {
                $giftValue += ($giftVariants->get($gift['variant_id'])->retail_price ?? 0) * $gift['quantity'];
                $gifts[] = [
                    'key' => "{$id}-{$gift['row']}",
                    'quantity' => $gift['quantity'],
                    'variant_id' => $gift['variant_id'],
                    'options' => array_values(array_filter(array_map(fn (int $variantId): ?array => ($variant = $giftVariants->get($variantId)) === null ? null : [
                        'id' => $variant->id,
                        'name' => $variant->name,
                        'sku' => $variant->sku,
                    ], $gift['options']))),
                ];
            }

            $list[] = [
                'id' => $promotion->id,
                'code' => $promotion->code,
                'name' => $promotion->name,
                'kind' => $promotion->kind,
                'method' => $promotion->method,
                'method_label' => $promotion->methodLabel(),
                'description' => $promotion->description,
                'ends_at' => $promotion->ends_at?->toIso8601String(),
                'discount' => $lines + $order,
                'gift_value' => $giftValue,
                'value' => $lines + $order + $giftValue,
                'gifts' => $gifts,
            ];
        }

        usort($list, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $list;
    }
}
