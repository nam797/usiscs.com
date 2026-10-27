<?php

declare(strict_types=1);

namespace App\Distributor;

/**
 * The distributor refused the order for a reason retrying won't fix (unknown
 * item, account on hold, bad address). The purchase order goes to "needs
 * attention" and staff are emailed.
 */
final class OrderRejectedException extends \RuntimeException
{
}
