<?php

declare(strict_types=1);

namespace App\Distributor;

/** What the distributor calls our SKUs, and what it charges us for them. */
interface SupplierSkuLookupInterface
{
    /**
     * @param list<string> $variantCodes
     *
     * @return array<string, array{supplierSku: string, unitCostInCents: ?int}> by variant code; codes it knows nothing about are left out
     */
    public function find(array $variantCodes): array;
}
