<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Entity\Procurement\PurchaseOrder;

/** Tells staff when a purchase order needs a person. */
interface PurchaseOrderNotifierInterface
{
    public function needsAttention(PurchaseOrder $purchaseOrder, string $reason): void;

    /** The distributor has no order API (or it's switched off): someone must place it by hand. */
    public function manualEntryNeeded(PurchaseOrder $purchaseOrder): void;
}
