<?php

declare(strict_types=1);

namespace App\Supplier\OrsNasco;

interface BulkAvailabilityClientInterface
{
    /**
     * Stock for every item ORS stocks, keyed by ORS item number.
     *
     * @return array<string, AvailabilityRow>
     *
     * @throws OrsApiException
     */
    public function fetchAll(): array;
}
