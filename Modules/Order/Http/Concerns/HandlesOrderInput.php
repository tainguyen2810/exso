<?php

namespace Modules\Order\Http\Concerns;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\SalesSource;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use App\Support\Settings\Banks;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Customer\Http\Controllers\CustomerController;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;

/**
 * Validation of the order form / POS input and the options the pages need.
 *
 * @phpstan-import-type OrderInput from OrderService
 */
trait HandlesOrderInput
{
    /**
     * @return OrderInput
     */
    protected function orderInput(Request $request, bool $pos = false): array
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = tenancy()->id();
        $money = ['integer', 'min:0', 'max:999999999999'];

        $data = $request->validate([
            'branch_id' => [$pos ? 'nullable' : 'required', 'integer', Rule::in($user->branchIds())],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'source_id' => ['nullable', 'integer', Rule::exists('sales_sources', 'id')->where('tenant_id', $tenant)],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenant)],
            'price_policy' => ['nullable', 'string', 'max:30'],
            'delivery_method' => ['nullable', Rule::in(array_keys(Order::DELIVERY_METHODS))],
            'carrier_name' => ['nullable', 'string', 'max:100'],
            'tracking_code' => ['nullable', 'string', 'max:100'],
            'receiver_name' => ['nullable', 'string', 'max:255'],
            'receiver_phone' => ['nullable', 'string', 'max:20'],
            'shipping_address' => ['nullable', 'string', 'max:500'],
            'shipping_fee' => ['nullable', ...$money],
            'discount' => ['nullable', ...$money],
            'discount_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'note' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'expected_delivery_at' => ['nullable', 'string', 'max:20'],
            'sale_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.price' => ['nullable', ...$money],
            'items.*.discount' => ['nullable', ...$money],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'payments' => ['array', 'max:10'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', ...$money],
            'promotion_ids' => ['array', 'max:20'],
            'promotion_ids.*' => ['integer'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'gifts' => ['array', 'max:50'],
            'gifts.*' => ['integer'],
        ], [], [
            'branch_id' => 'chi nhánh',
            'customer_id' => 'khách hàng',
            'items' => 'sản phẩm',
            'items.*.quantity' => 'số lượng',
            'items.*.price' => 'đơn giá',
            'payments.*.amount' => 'số tiền thanh toán',
        ]);

        $payments = array_values(array_filter(
            array_map(fn (array $payment): array => ['payment_method_id' => (int) $payment['payment_method_id'], 'amount' => (int) $payment['amount']], $data['payments'] ?? []),
            fn (array $payment): bool => $payment['amount'] > 0,
        ));

        abort_if($payments !== [] && ! $pos && ! $user->can('orders.pay'), 403, 'Bạn không có quyền thanh toán đơn hàng.');

        return [
            'branch_id' => (int) ($data['branch_id'] ?? 0),
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'source_id' => isset($data['source_id']) ? (int) $data['source_id'] : null,
            'assigned_to' => isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            'price_policy' => $data['price_policy'] ?? null,
            'delivery_method' => $data['delivery_method'] ?? 'later',
            'carrier_name' => $data['carrier_name'] ?? null,
            'tracking_code' => $data['tracking_code'] ?? null,
            'receiver_name' => $data['receiver_name'] ?? null,
            'receiver_phone' => $data['receiver_phone'] ?? null,
            'shipping_address' => $data['shipping_address'] ?? null,
            'shipping_fee' => (int) ($data['shipping_fee'] ?? 0),
            'discount' => (int) ($data['discount'] ?? 0),
            'discount_bp' => isset($data['discount_bp']) ? (int) $data['discount_bp'] : null,
            'note' => $data['note'] ?? null,
            'tags' => $data['tags'] ?? null,
            'reference' => $data['reference'] ?? null,
            'expected_delivery_at' => $this->date($data['expected_delivery_at'] ?? null),
            'sale_at' => $data['sale_at'] ?? null,
            'items' => array_values(array_map(fn (array $item): array => [
                'variant_id' => isset($item['variant_id']) ? (int) $item['variant_id'] : null,
                'name' => $item['name'] ?? null,
                'quantity' => (int) $item['quantity'],
                'price' => isset($item['price']) ? (int) $item['price'] : null,
                'discount' => (int) ($item['discount'] ?? 0),
                'note' => $item['note'] ?? null,
            ], $data['items'])),
            'payments' => $payments,
            'promotion_ids' => array_values(array_map('intval', $data['promotion_ids'] ?? [])),
            'coupon_code' => $data['coupon_code'] ?? null,
            // "<promotion id>-<row>" => gift variant chosen.
            'gifts' => $this->giftChoices($data['gifts'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderOptions(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return [
            'branches' => Branch::query()->whereIn('id', $user->branchIds())->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'currentBranchId' => app(CurrentBranch::class)->id($user),
            'sources' => SalesSource::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'staff' => User::query()->where('status', 'active')->whereHas('branches', fn ($query) => $query->whereIn('branches.id', $user->branchIds()))->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => $this->paymentMethods(),
            'pricePolicies' => CustomerController::pricePolicies(),
            'canEditPrice' => app(OrderService::class)->canEditPrice($user),
            'defaultOnlineMethod' => settings('payment.default_online'),
            'defaultPosMethod' => settings('payment.default_pos'),
            // Khuyến mại (G5): one program per order, or several.
            'promotionSettings' => [
                'enabled' => (bool) settings('promotions.enabled'),
                'multiple' => settings('promotions.apply_mode') === 'many',
                'coupons' => (bool) settings('coupons.enabled'),
            ],
        ];
    }

    /**
     * Active payment methods; bank ones carry the account for VietQR.
     *
     * @return list<array<string, mixed>>
     */
    protected function paymentMethods(): array
    {
        return array_values(PaymentMethod::query()->where('is_active', true)->orderBy('sort')->get()->map(fn (PaymentMethod $method): array => [
            'id' => $method->id,
            'name' => $method->name,
            'type' => $method->type,
            'bank_bin' => $method->bank_bin,
            'bank_name' => $method->bank_bin === null ? null : (Banks::LIST[$method->bank_bin] ?? null),
            'account_number' => $method->account_number,
            'account_name' => $method->account_name,
        ])->all());
    }

    protected function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        foreach (['d/m/Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, trim($value));
            } catch (\Throwable) {
                continue;
            }

            if ($date !== null && $date->format($format) === trim($value)) {
                return $date->toDateString();
            }
        }

        return null;
    }

    protected function authorizeOrder(Request $request, Order $order): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($order->isVisibleTo($user), 403, 'Bạn không xem được đơn hàng này.');
    }

    /**
     * "<promotion id>-<row>" => gift variant chosen.
     *
     * @param  array<mixed>  $gifts
     * @return array<string, int>
     */
    protected function giftChoices(array $gifts): array
    {
        $choices = [];

        foreach ($gifts as $key => $variant) {
            if (preg_match('/^\d+-\d+$/', (string) $key) === 1) {
                $choices[(string) $key] = (int) $variant;
            }
        }

        return $choices;
    }
}
