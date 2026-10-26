<?php

declare(strict_types=1);

namespace App\Supplier\OrsNasco;

/**
 * An ORS Nasco API call failed as a whole (not configured, rejected
 * credentials, service error, timeout). Syncs stop before writing anything
 * when they get one, so a bad call never zeroes stock or prices.
 */
final class OrsApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
