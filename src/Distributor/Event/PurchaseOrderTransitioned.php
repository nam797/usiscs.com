<?php

declare(strict_types=1);

namespace App\Distributor\Event;

use App\Distributor\PurchaseOrderTransition;
use App\Entity\Procurement\PurchaseOrder;

/**
 * A purchase order moved, and the move is already on its timeline
 * (PurchaseOrderStateMachine). For reactions that should come after it on
 * the timeline, such as charging the customer's card when it ships.
 */
final class PurchaseOrderTransitioned
{
    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly PurchaseOrderTransition $transition,
    ) {
    }
}
