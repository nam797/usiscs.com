<?php

declare(strict_types=1);

namespace App\Shipping;

/**
 * A tracking number and where to follow it.
 *
 * The carrier is read from the number itself, since ORS ships direct
 * orders with whichever carrier it picks and a customer's shipment can
 * hold several numbers ("1Z…, 7712…", see CustomerShipmentUpdater). A
 * carrier name stored next to the number (PurchaseOrderShipment::carrier,
 * PurchaseOrder::forwardedCarrier) wins when it names one.
 */
final class TrackingLink
{
    private function __construct(
        public readonly string $number,
        public readonly ?Carrier $carrier,
    ) {
    }

    public static function for(string $number, ?string $carrierName = null): self
    {
        $number = trim($number);

        return new self($number, Carrier::fromName($carrierName) ?? self::detect($number));
    }

    /**
     * One link per number in a comma-separated list, as stored on a Sylius shipment.
     *
     * @return list<self>
     */
    public static function all(?string $numbers, ?string $carrierName = null): array
    {
        $links = [];
        foreach (explode(',', (string) $numbers) as $number) {
            if ('' !== trim($number)) {
                $links[] = self::for($number, $carrierName);
            }
        }

        return $links;
    }

    public function url(): ?string
    {
        return $this->carrier?->trackingUrl($this->number);
    }

    private static function detect(string $number): ?Carrier
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $number));

        return match (true) {
            1 === preg_match('/^1Z[0-9A-Z]{16}$/', $compact) => Carrier::Ups,
            // USPS: 20-22 digits starting 92-95 (FedEx SmartPost hands these to USPS too), or international "EA123456789US".
            1 === preg_match('/^9[2-5]\d{18,20}$/', $compact), 1 === preg_match('/^[A-Z]{2}\d{9}US$/', $compact) => Carrier::Usps,
            1 === preg_match('/^(\d{12}|\d{15}|\d{20}|\d{22})$/', $compact) => Carrier::Fedex,
            default => null,
        };
    }
}
