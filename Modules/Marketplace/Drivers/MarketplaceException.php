<?php

namespace Modules\Marketplace\Drivers;

use RuntimeException;

/**
 * The platform refused a call or could not be reached; the message is
 * shown to the user as is.
 */
class MarketplaceException extends RuntimeException
{
    /** The seller must authorise EXSO again ("Cấp quyền lại"). */
    public bool $authExpired = false;

    public static function expired(string $message): self
    {
        $exception = new self($message);
        $exception->authExpired = true;

        return $exception;
    }
}
