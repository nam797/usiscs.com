<?php

declare(strict_types=1);

namespace App\Supplier\OrsNasco;

interface OrderQueryClientInterface
{
    /**
     * Every suffix of the ORS order(s) placed with this customer PO number
     * (our purchase order number); empty when ORS has none.
     *
     * @return list<OrderSuffix>
     *
     * @throws OrsApiException
     */
    public function findByCustomerPo(string $customerPo, ?\DateTimeImmutable $placedAfter = null): array;
}
