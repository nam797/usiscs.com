<?php

declare(strict_types=1);

namespace App\Distributor;

/**
 * Where the distributor says an order is, in our terms. The adapter works
 * out $state from its own codes; PurchaseOrderTracker moves the purchase
 * order there and records the rest on its timeline.
 */
final class DistributorOrderStatus
{
    /** States a distributor can report. */
    public const STATES = [
        PurchaseOrderState::ACKNOWLEDGED,
        PurchaseOrderState::ON_HOLD,
        PurchaseOrderState::PARTIALLY_SHIPPED,
        PurchaseOrderState::SHIPPED,
        PurchaseOrderState::INVOICED,
        PurchaseOrderState::CANCELLED,
    ];

    /**
     * @param array<string, array{shipped: int, backordered: int}> $lines     by the distributor's SKU
     * @param list<array{trackingNumber: string, carrier: string}> $shipments
     * @param array<string, int>                                   $invoiceInCents e.g. ["Invoice Total" => 8070, "Freight Out" => 1655]; empty until invoiced
     * @param array<string, mixed>                                 $details   logged with state changes; never credentials
     */
    public function __construct(
        /** the distributor's order number */
        public readonly string $reference,
        public readonly string $state,
        /** one line for the timeline, e.g. "ORS order 90803340: 2 parts, shipped" */
        public readonly string $summary,
        public readonly array $lines = [],
        public readonly array $shipments = [],
        public readonly array $invoiceInCents = [],
        public readonly array $details = [],
    ) {
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a state a distributor can report.', $state));
        }
    }
}
