<?php

namespace Modules\Order\Contracts;

use App\Models\User;
use Modules\Order\Models\Order;

/**
 * Used when no shipping module is enabled.
 */
class NoShipping implements OrderShipping
{
    public function options(User $user): array
    {
        return ['enabled' => false];
    }

    public function dispatch(Order $order, array $input, User $user): ?string
    {
        return null;
    }

    public function panel(Order $order, User $user): array
    {
        return ['shipments' => [], 'defaults' => null];
    }
}
