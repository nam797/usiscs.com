<?php

declare(strict_types=1);

namespace App\Supplier\OrsNasco;

/**
 * One part ("suffix") of an ORS order, from the Order Query web service.
 * ORS splits an order into suffixes as it ships from different warehouses
 * or ships back-orders later: "90803340-00", "90803340-01"...
 */
final class OrderSuffix
{
    public const STAGE_ENTERED = 1;
    public const STAGE_PICKING = 2;
    public const STAGE_SHIPPED = 3;
    public const STAGE_INVOICED = 4;
    /** Held or cancelled: Held = Y tells them apart. */
    public const STAGE_HELD_OR_CANCELLED = 9;

    /**
     * @param list<OrderSuffixLine>       $lines
     * @param list<array{shipVia: string, shipViaDescription: string, trackingNumber: string}> $shipments
     * @param array<string, int>          $invoiceValuesInCents e.g. ["Invoice Total" => 8070, "Freight Out" => 1655]
     */
    public function __construct(
        /** "12345678-12" */
        public readonly string $orderNumber,
        public readonly ?string $customerPo,
        public readonly int $stage,
        public readonly bool $held,
        public readonly ?string $enterDate,
        public readonly ?string $warehouse,
        public readonly array $lines,
        public readonly array $shipments,
        public readonly ?string $invoiceDate,
        public readonly array $invoiceValuesInCents,
    ) {
    }

    /** "12345678" from "12345678-12". */
    public function baseOrderNumber(): string
    {
        return explode('-', $this->orderNumber, 2)[0];
    }

    public function isCancelled(): bool
    {
        return self::STAGE_HELD_OR_CANCELLED === $this->stage && !$this->held;
    }
}
