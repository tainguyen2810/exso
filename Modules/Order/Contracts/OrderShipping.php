<?php

namespace Modules\Order\Contracts;

use App\Models\User;
use Modules\Order\Models\Order;

/**
 * What orders need from a shipping module (G6): the partners offered in
 * "Đóng gói và giao hàng", creating the shipment chosen on the order form
 * and the shipments shown on the order page. Bound by the Shipping module;
 * without it only "Giao hàng sau" / "Nhận tại cửa hàng" and a typed partner
 * name are available.
 */
interface OrderShipping
{
    /**
     * Options for the order form and order page (partners, defaults).
     *
     * @return array<string, mixed>
     */
    public function options(User $user): array;

    /**
     * Create the shipment the order form asked for ($input = the form's
     * "shipment" fields) once the order is saved and approved. Returns an
     * error to show when the partner refused it; the order stays saved.
     *
     * @param  array<string, mixed>  $input
     */
    public function dispatch(Order $order, array $input, User $user): ?string;

    /**
     * The order page's "Đóng gói và giao hàng" block: shipments of the
     * order (newest first) and what a new one starts with.
     *
     * @return array{shipments: list<array<string, mixed>>, defaults: array<string, mixed>|null}
     */
    public function panel(Order $order, User $user): array;
}
