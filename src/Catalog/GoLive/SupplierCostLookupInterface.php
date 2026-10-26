<?php

declare(strict_types=1);

namespace App\Catalog\GoLive;

interface SupplierCostLookupInterface
{
    /** Our supplier cost for a variant code, in cents; null when unknown. */
    public function costInCents(string $sku): ?int;
}
