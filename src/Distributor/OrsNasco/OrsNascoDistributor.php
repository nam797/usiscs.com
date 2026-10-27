<?php

declare(strict_types=1);

namespace App\Distributor\OrsNasco;

use App\Distributor\DistributorInterface;
use App\Distributor\DistributorOrderStatus;
use App\Distributor\DistributorUnavailableException;
use App\Distributor\PurchaseOrderState;
use App\Distributor\SubmissionResult;
use App\Entity\Catalog\SupplierItem;
use App\Entity\Procurement\PurchaseOrder;
use App\Supplier\OrsNasco\OrderQueryClientInterface;
use App\Supplier\OrsNasco\OrderSuffix;
use App\Supplier\OrsNasco\OrsApiException;

/**
 * ORS Nasco. ORS hasn't told us how they take orders yet (their web
 * services cover stock, prices and order *lookup* only), so staff enter
 * each purchase order in the ORS portal with our PO number as the customer
 * PO, and mark it submitted.
 *
 * Status comes from the ORS Order Query web service, by that PO number:
 * it also finds orders staff placed but didn't mark. submit() waits for an
 * order-entry method from ORS.
 */
final class OrsNascoDistributor implements DistributorInterface
{
    public function __construct(private readonly OrderQueryClientInterface $orderQuery)
    {
    }

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
        $status = $this->fetchStatus($purchaseOrder);

        return null === $status || PurchaseOrderState::CANCELLED === $status->state ? null : $status->reference;
    }

    public function fetchStatus(PurchaseOrder $purchaseOrder): ?DistributorOrderStatus
    {
        try {
            // A day's margin: ORS's entry date can be later than ours, never earlier.
            $suffixes = $this->orderQuery->findByCustomerPo($purchaseOrder->getNumber(), $purchaseOrder->getCreatedAt()->modify('-1 day'));
        } catch (OrsApiException $exception) {
            throw new DistributorUnavailableException($exception->getMessage(), 0, $exception);
        }

        // The search is by PO number; keep only exact matches.
        $suffixes = array_values(array_filter(
            $suffixes,
            static fn (OrderSuffix $suffix): bool => 0 === strcasecmp(trim((string) $suffix->customerPo), $purchaseOrder->getNumber()),
        ));

        return OrsOrderStatusMapper::map($suffixes);
    }
}
