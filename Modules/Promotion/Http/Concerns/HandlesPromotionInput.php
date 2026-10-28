<?php

namespace Modules\Promotion\Http\Concerns;

use App\Models\Branch;
use App\Models\SalesSource;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Customer\Models\CustomerGroup;
use Modules\Product\Models\Brand;
use Modules\Product\Models\Category;
use Modules\Product\Models\ProductVariant;

/**
 * Fields shared by programs and coupon batches: dates and where they apply
 * (chi nhánh, nguồn đơn, nhóm khách hàng).
 */
trait HandlesPromotionInput
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function scopeRules(): array
    {
        $tenant = tenancy()->id();

        return [
            'starts_at' => ['required', 'string', 'max:30'],
            'ends_at' => ['nullable', 'string', 'max:30'],
            'all_branches' => ['boolean'],
            'branch_ids' => ['array', 'max:200'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'all_sources' => ['boolean'],
            'source_ids' => ['array', 'max:200'],
            'source_ids.*' => ['integer', Rule::exists('sales_sources', 'id')->where('tenant_id', $tenant)],
            'all_customers' => ['boolean'],
            'customer_group_ids' => ['array', 'max:200'],
            'customer_group_ids.*' => ['integer', Rule::exists('customer_groups', 'id')->where('tenant_id', $tenant)],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function scopeValues(array $data): array
    {
        $startsAt = $this->dateTime($data['starts_at'] ?? null, 'starts_at');
        $endsAt = $this->dateTime($data['ends_at'] ?? null, 'ends_at');

        if ($startsAt === null) {
            throw ValidationException::withMessages(['starts_at' => 'Nhập ngày bắt đầu.']);
        }

        if ($endsAt !== null && $endsAt->lte($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'Ngày kết thúc phải sau ngày bắt đầu.']);
        }

        $values = ['starts_at' => $startsAt, 'ends_at' => $endsAt];

        foreach (['branches' => 'branch_ids', 'sources' => 'source_ids', 'customers' => 'customer_group_ids'] as $all => $ids) {
            $list = array_values(array_unique(array_map('intval', $data[$ids] ?? [])));
            $everyone = (bool) ($data["all_{$all}"] ?? true) || $list === [];
            $values["all_{$all}"] = $everyone;
            $values[$ids] = $everyone ? null : $list;
        }

        return $values;
    }

    /**
     * "dd/mm/yyyy HH:mm", "dd/mm/yyyy" or ISO (datetime-local), in the store's time zone.
     */
    protected function dateTime(mixed $value, string $field): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $timezone = (string) config('app.timezone');

        foreach (['d/m/Y H:i', 'd/m/Y', 'Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value, $timezone);
            } catch (\Throwable) {
                continue;
            }

            if ($date !== null && $date->format($format) === $value) {
                return $date;
            }
        }

        throw ValidationException::withMessages([$field => 'Ngày giờ không hợp lệ (dd/mm/yyyy hh:mm).']);
    }

    /**
     * Lists the forms pick from.
     *
     * @return array<string, mixed>
     */
    protected function scopeOptions(): array
    {
        return [
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'sources' => SalesSource::query()->orderBy('name')->get(['id', 'name']),
            'customerGroups' => CustomerGroup::query()->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Name and SKU of the variants a saved program or batch refers to.
     *
     * @param  list<int>  $ids
     * @return array<int, array{id: int, name: string, sku: string}>
     */
    protected function variantLabels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ProductVariant::query()->withTrashed()->whereIn('id', array_unique($ids))->get(['id', 'name', 'sku'])
            ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => ['id' => $variant->id, 'name' => $variant->name, 'sku' => $variant->sku]])
            ->all();
    }
}
