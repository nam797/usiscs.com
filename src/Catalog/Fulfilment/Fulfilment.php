<?php

declare(strict_types=1);

namespace App\Catalog\Fulfilment;

/**
 * How a SKU reaches the customer. ORS ships it straight to them, or to our
 * warehouse and we ship it on (cross-dock). Every SKU starts unconfirmed:
 * no ORS file says which, and a product can't go live until it's known.
 */
enum Fulfilment: string
{
    case Unconfirmed = 'unconfirmed';
    case OrsDirect = 'ors_direct';
    case CrossDock = 'cross_dock';

    public function label(): string
    {
        return match ($this) {
            self::Unconfirmed => 'Not confirmed',
            self::OrsDirect => 'ORS direct',
            self::CrossDock => 'Cross-dock (via our warehouse)',
        };
    }

    /**
     * What people write in a sheet: "ORS direct", "direct ship", "cross-dock",
     * "crossdock", "not confirmed", blank (= not confirmed).
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $text): self
    {
        $key = (string) preg_replace('/[^a-z]/', '', strtolower($text));

        return match ($key) {
            '', 'unconfirmed', 'notconfirmed' => self::Unconfirmed,
            'orsdirect', 'direct', 'directship', 'dropship', 'orsdirectship' => self::OrsDirect,
            'crossdock', 'crossdocked', 'viawarehouse', 'warehouse' => self::CrossDock,
            default => throw new \InvalidArgumentException(sprintf('"%s" is not a fulfilment (ORS direct, cross-dock or not confirmed)', $text)),
        };
    }
}
