<?php

namespace Modules\Shipping\Services;

use Modules\Shipping\Carriers\CarrierException;
use Modules\Shipping\Carriers\CarrierManager;
use Modules\Shipping\Models\Carrier;

/**
 * Label of a carrier's raw status ("Trạng thái đối tác").
 */
class CarrierStatus
{
    public static function label(Carrier $carrier, string $status): string
    {
        if (! $carrier->isIntegrated()) {
            return $status;
        }

        try {
            return app(CarrierManager::class)->for($carrier)->statusLabel($status);
        } catch (CarrierException) {
            return $status;
        }
    }
}
