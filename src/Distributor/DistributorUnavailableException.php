<?php

declare(strict_types=1);

namespace App\Distributor;

/**
 * The distributor couldn't be reached or failed on its side (down, timeout,
 * HTTP 5xx). The order stays queued and is retried with backoff
 * (config/packages/messenger.yaml, "distributor" transport).
 */
final class DistributorUnavailableException extends \RuntimeException
{
}
