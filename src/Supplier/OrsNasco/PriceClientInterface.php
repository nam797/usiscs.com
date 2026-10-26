<?php

declare(strict_types=1);

namespace App\Supplier\OrsNasco;

interface PriceClientInterface
{
    /**
     * Our account's price for each item, keyed by ORS item number. Items ORS
     * doesn't answer for are missing from the result.
     *
     * @param list<string> $itemNumbers
     *
     * @return array<string, PriceRow>
     *
     * @throws OrsApiException
     */
    public function fetch(array $itemNumbers): array;
}
