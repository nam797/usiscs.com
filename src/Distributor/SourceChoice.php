<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Entity\Catalog\SupplierItem;

/** What SourceSelector picked for an order line, and why (for the purchase order's timeline). */
final class SourceChoice
{
    public function __construct(
        /** null: no usable source; the line needs staff */
        public readonly ?SupplierItem $source,
        public readonly string $reason,
        /** no source had the whole quantity in stock */
        public readonly bool $backorder = false,
    ) {
    }
}
