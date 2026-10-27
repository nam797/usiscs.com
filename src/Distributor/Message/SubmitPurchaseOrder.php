<?php

declare(strict_types=1);

namespace App\Distributor\Message;

/**
 * Send this purchase order to its distributor. Goes through the
 * "distributor" transport (a table in our database), so it survives the
 * distributor, the worker or the site being down. Safe to deliver twice:
 * the handler only acts on a purchase order that is still queued.
 */
final class SubmitPurchaseOrder
{
    public function __construct(public readonly int $purchaseOrderId)
    {
    }
}
