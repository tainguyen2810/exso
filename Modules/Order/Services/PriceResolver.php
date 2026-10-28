<?php

namespace Modules\Order\Services;

use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerGroup;
use Modules\Product\Models\PriceList;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\VariantPrice;

/**
 * Selling price of a variant under a price policy: a price list ("Giá đại
 * lý"...) when the variant has a price there, otherwise a price column
 * (retail or wholesale). A customer's group decides the policy.
 *
 * @phpstan-type Policy array{column: string, price_list_id: int|null, label: string}
 */
class PriceResolver
{
    /**
     * @return Policy
     */
    public function policyFor(?Customer $customer): array
    {
        if ($customer === null) {
            return ['column' => 'retail_price', 'price_list_id' => null, 'label' => CustomerGroup::PRICE_COLUMNS['retail_price']];
        }

        $defaults = $customer->salesDefaults();

        return ['column' => $defaults['column'], 'price_list_id' => $defaults['price_list_id'], 'label' => $defaults['label']];
    }

    /**
     * Policy picked on the order form ("Giá bán lẻ ▾"): a column name or "list:<id>".
     *
     * @return Policy|null
     */
    public function policyFromKey(?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }

        if (array_key_exists($key, CustomerGroup::PRICE_COLUMNS)) {
            return ['column' => $key, 'price_list_id' => null, 'label' => CustomerGroup::PRICE_COLUMNS[$key]];
        }

        if (str_starts_with($key, 'list:')) {
            $list = PriceList::query()->where('status', 'active')->find((int) substr($key, 5));

            if ($list !== null) {
                return ['column' => 'retail_price', 'price_list_id' => $list->id, 'label' => $list->name];
            }
        }

        return null;
    }

    /**
     * @param  Policy  $policy
     */
    public function price(ProductVariant $variant, array $policy): int
    {
        return $this->prices([$variant], $policy)[$variant->id] ?? 0;
    }

    /**
     * @param  iterable<ProductVariant>  $variants
     * @param  Policy  $policy
     * @return array<int, int> variant id => price
     */
    public function prices(iterable $variants, array $policy): array
    {
        $prices = [];
        $ids = [];

        foreach ($variants as $variant) {
            $column = $policy['column'];
            $price = (int) $variant->getAttribute($column);
            // A variant without a wholesale price sells at retail.
            $prices[$variant->id] = $price > 0 || $column === 'retail_price' ? $price : $variant->retail_price;
            $ids[] = $variant->id;
        }

        if ($policy['price_list_id'] !== null && $ids !== []) {
            $listed = VariantPrice::query()
                ->where('price_list_id', $policy['price_list_id'])
                ->whereIn('variant_id', $ids)
                ->pluck('price', 'variant_id');

            foreach ($listed as $variantId => $price) {
                $prices[(int) $variantId] = (int) $price;
            }
        }

        return $prices;
    }
}
