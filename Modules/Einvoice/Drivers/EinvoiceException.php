<?php

namespace Modules\Einvoice\Drivers;

use RuntimeException;

/**
 * The provider refused a call or could not be reached; the message is
 * shown to the user as is.
 */
class EinvoiceException extends RuntimeException {}
