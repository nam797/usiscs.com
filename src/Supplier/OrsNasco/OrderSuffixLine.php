<?php

declare(strict_types=1);

namespace App\Supplier\OrsNasco;

/** One item on an ORS order suffix; quantities are in the ORS unit of measure. */
final class OrderSuffixLine
{
    public function __construct(
        public readonly string $item,
        public readonly ?string $unit,
        public readonly int $quantityOrdered,
        public readonly int $quantityThisSuffix,
        public readonly int $quantityBackordered,
        public readonly int $quantityShipped,
        /** ORS's price to us per unit, in cents */
        public readonly ?int $unitPriceInCents,
    ) {
    }
}
