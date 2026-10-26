<?php

declare(strict_types=1);

namespace App\Supplier\OrsNasco;

/** One item from an ORS price check (our account's cost). */
final class PriceRow
{
    public function __construct(
        public readonly string $itemNumber,
        /** our cost per selling unit, in cents; null when ORS gave no price */
        public readonly ?int $costInCents,
        /** ORS selling unit of measure, e.g. "PK" */
        public readonly ?string $unitSell,
        /** e.g. "100 EA/PK" */
        public readonly ?string $unitConversion,
        /** ORS's own message, e.g. "Invalid Product Number", "Product is Inactive" */
        public readonly ?string $error,
    ) {
    }

    /** Why this row can't be used as a cost, or null when it can. */
    public function problem(): ?string
    {
        if (null !== $this->error) {
            return $this->error;
        }

        return null === $this->costInCents ? 'no price returned' : null;
    }
}
