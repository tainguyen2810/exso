<?php

namespace Modules\Marketplace\Drivers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * A marketplace EXSO connects to. One instance works for one shop
 * connection (MarketplaceShop) and its credentials; token changes are
 * written back to it. Add a platform by implementing this and registering
 * it with Hook::filter('marketplace.drivers').
 */
interface MarketplaceDriver
{
    /** Platform name shown on the pages, e.g. "Shopee". */
    public static function label(): string;

    /**
     * Page of the platform where the seller authorises EXSO; the platform
     * then sends the browser back to $redirect with the authorisation code.
     */
    public function authorizeUrl(string $redirect): string;

    /**
     * Finish the authorisation with what the platform added to the
     * redirect: stores the tokens on the shop and returns its id and name.
     *
     * @param  array<string, mixed>  $query
     * @return array{external_id: string, name: string}
     *
     * @throws MarketplaceException
     */
    public function connect(array $query): array;

    /**
     * Ids of orders created or changed between two moments.
     *
     * @return list<string>
     *
     * @throws MarketplaceException
     */
    public function changedOrders(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * @param  list<string>  $ids
     * @return list<PlatformOrder>
     *
     * @throws MarketplaceException
     */
    public function orders(array $ids): array;

    /**
     * Every listing (item × variation) of the shop.
     *
     * @return list<PlatformListing>
     *
     * @throws MarketplaceException
     */
    public function listings(): array;

    /**
     * Set the stock shown on the platform.
     *
     * @param  list<array{item_id: string, model_id: string, stock: int}>  $stocks
     * @return array<string, string> errors by "item_id:model_id"
     *
     * @throws MarketplaceException
     */
    public function updateStock(array $stocks): array;

    /**
     * Check that a push call really comes from the platform (signature)
     * and return the order ids it is about. Null = not a valid call. The
     * orders themselves are fetched again with orders().
     *
     * @return list<string>|null
     */
    public function webhookOrders(Request $request): ?array;
}
