<?php

namespace Modules\Shipping\Carriers;

use RuntimeException;

/**
 * The carrier refused a request or could not be reached. The message is
 * shown to staff (in Vietnamese when it comes from EXSO, as the carrier
 * sent it otherwise).
 */
class CarrierException extends RuntimeException {}
