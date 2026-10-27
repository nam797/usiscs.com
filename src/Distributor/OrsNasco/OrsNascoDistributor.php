<?php

declare(strict_types=1);

namespace App\Distributor\OrsNasco;

use App\Distributor\DistributorInterface;
use App\Distributor\SubmissionResult;
use App\Entity\Catalog\SupplierItem;
use App\Entity\Procurement\PurchaseOrder;

/**
 * ORS Nasco. ORS hasn't told us how they take orders yet (their web
 * services cover stock, prices and order *lookup* only), so for now staff
 * enter each purchase order in the ORS portal and mark it submitted.
 *
 * Next: findExisting() (and order tracking) through the ORS Order Query web
 * service, by our PO number ("custpo"); submit() once ORS gives us an
 * order-entry method.
 */
final class OrsNascoDistributor implements DistributorInterface
{
    public function getCode(): string
    {
        return SupplierItem::ORS_NASCO;
    }

    public function getName(): string
    {
        return 'ORS Nasco';
    }

    public function canSubmit(): bool
    {
        return false;
    }

    public function submit(PurchaseOrder $purchaseOrder): SubmissionResult
    {
        throw new \LogicException('ORS Nasco orders are entered by hand until ORS gives us an order API.');
    }

    public function findExisting(PurchaseOrder $purchaseOrder): ?string
    {
        return null;
    }
}
