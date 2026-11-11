<?php

declare(strict_types=1);

namespace App\Shipping;

/**
 * A parcel carrier: the one a shipping method ships by
 * (ShippingMethod::carrier), and the one a tracking number belongs to
 * (TrackingLink). UPS is the default, FedEx is an option the client
 * switches on by enabling its shipping methods. USPS is here only because
 * ORS sometimes ships small parcels with it.
 */
enum Carrier: string
{
    case Ups = 'ups';
    case Fedex = 'fedex';
    case Usps = 'usps';

    public function label(): string
    {
        return match ($this) {
            self::Ups => 'UPS',
            self::Fedex => 'FedEx',
            self::Usps => 'USPS',
        };
    }

    public function trackingUrl(string $trackingNumber): string
    {
        return sprintf(match ($this) {
            self::Ups => 'https://www.ups.com/track?tracknum=%s',
            self::Fedex => 'https://www.fedex.com/fedextrack/?trknbr=%s',
            self::Usps => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
        }, rawurlencode($trackingNumber));
    }

    /** "UPS Ground", "fedex", "Federal Express": the carrier a name mentions, if any. */
    public static function fromName(?string $name): ?self
    {
        $name = strtolower((string) $name);

        return match (true) {
            1 === preg_match('/\bfed\s*ex\b|\bfederal express\b/', $name) => self::Fedex,
            1 === preg_match('/\busps\b|\bpostal\b/', $name) => self::Usps,
            1 === preg_match('/\bups\b/', $name) => self::Ups,
            default => null,
        };
    }
}
