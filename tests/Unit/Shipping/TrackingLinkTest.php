<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shipping;

use App\Shipping\Carrier;
use App\Shipping\TrackingLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackingLinkTest extends TestCase
{
    /** @return iterable<string, array{string, ?Carrier}> */
    public static function numbers(): iterable
    {
        yield 'UPS' => ['1Z999AA10123456784', Carrier::Ups];
        yield 'UPS, lower case' => ['1z999aa10123456784', Carrier::Ups];
        yield 'FedEx 12 digits' => ['771234567890', Carrier::Fedex];
        yield 'FedEx 15 digits' => ['123456789012345', Carrier::Fedex];
        yield 'USPS 22 digits' => ['9400111899223197428490', Carrier::Usps];
        yield 'USPS international' => ['EA123456789US', Carrier::Usps];
        yield 'unknown' => ['ABC-123', null];
    }

    #[DataProvider('numbers')]
    public function testTheCarrierIsReadFromTheNumber(string $number, ?Carrier $carrier): void
    {
        self::assertSame($carrier, TrackingLink::for($number)->carrier);
    }

    public function testAStoredCarrierNameWins(): void
    {
        $link = TrackingLink::for('1ZTEST0001', 'UPS GRD COML');

        self::assertSame(Carrier::Ups, $link->carrier);
        self::assertSame('https://www.ups.com/track?tracknum=1ZTEST0001', $link->url());
        self::assertSame(Carrier::Fedex, TrackingLink::for('1Z999AA10123456784', 'FedEx Ground')->carrier);
        self::assertSame(Carrier::Ups, TrackingLink::for('1Z999AA10123456784', 'Our truck')->carrier, 'a name without a carrier falls back to the number');
    }

    public function testEveryNumberInAShipmentsListGetsALink(): void
    {
        $links = TrackingLink::all('1Z999AA10123456784, 771234567890, ABC-123');

        self::assertSame(['1Z999AA10123456784', '771234567890', 'ABC-123'], array_map(static fn (TrackingLink $l): string => $l->number, $links));
        self::assertSame([
            'https://www.ups.com/track?tracknum=1Z999AA10123456784',
            'https://www.fedex.com/fedextrack/?trknbr=771234567890',
            null,
        ], array_map(static fn (TrackingLink $l): ?string => $l->url(), $links));
        self::assertSame([], TrackingLink::all(null));
        self::assertSame([], TrackingLink::all(' , '));
    }

    public function testTheNumberIsEscapedInTheUrl(): void
    {
        self::assertSame('https://www.ups.com/track?tracknum=1Z%20%26x%3D1', TrackingLink::for('1Z &x=1', 'UPS')->url());
    }

    public function testCarrierNames(): void
    {
        self::assertSame(Carrier::Ups, Carrier::fromName('UPS 2nd Day Air'));
        self::assertSame(Carrier::Fedex, Carrier::fromName('Federal Express'));
        self::assertSame(Carrier::Usps, Carrier::fromName('US Postal Service'));
        self::assertNull(Carrier::fromName('Upshaw Freight'));
        self::assertNull(Carrier::fromName(null));
    }
}
