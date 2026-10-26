<?php

declare(strict_types=1);

namespace App\Supplier\OrsNasco;

/** One item from the ORS bulk availability list. */
final class AvailabilityRow
{
    public function __construct(
        public readonly string $itemNumber,
        /** ORS selling unit of measure, e.g. "EA", "BX", "PK" */
        public readonly string $unitOfMeasure,
        /** quantity available across all ORS warehouses, in that unit */
        public readonly int $totalAvailable,
    ) {
    }
}
