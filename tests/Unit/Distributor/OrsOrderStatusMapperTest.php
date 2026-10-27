<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor;

use App\Distributor\OrsNasco\OrsOrderStatusMapper;
use App\Distributor\PurchaseOrderState;
use App\Supplier\OrsNasco\OrderQueryClient;
use App\Supplier\OrsNasco\OrderSuffix;
use App\Supplier\OrsNasco\OrderSuffixLine;
use PHPUnit\Framework\TestCase;

final class OrsOrderStatusMapperTest extends TestCase
{
    public function testNothingFoundIsNoStatus(): void
    {
        self::assertNull(OrsOrderStatusMapper::map([]));
    }

    public function testShippedAndInvoicedFromOrsExample(): void
    {
        $status = OrsOrderStatusMapper::map(OrderQueryClient::parse(self::fixture('order_query_invoiced.xml')));

        self::assertNotNull($status);
        self::assertSame('12345678', $status->reference);
        self::assertSame(PurchaseOrderState::INVOICED, $status->state);
        self::assertSame(['012-11-500-10' => ['shipped' => 10, 'backordered' => 0]], $status->lines);
        self::assertSame([['trackingNumber' => '1Z4704980326987203', 'carrier' => 'UPS GRD COML']], $status->shipments);
        self::assertSame(1655, $status->invoiceInCents['Freight Out']);
    }

    public function testACancelledSuffixReplacedByAnotherIsNotACancelledOrder(): void
    {
        $status = OrsOrderStatusMapper::map(OrderQueryClient::parse(self::fixture('order_query_cancelled_suffix.xml')));

        self::assertNotNull($status);
        self::assertSame('90803340', $status->reference);
        self::assertSame(PurchaseOrderState::INVOICED, $status->state);
        self::assertSame(['127-6001L' => ['shipped' => 10, 'backordered' => 0]], $status->lines);
        self::assertSame(9768, $status->invoiceInCents['Invoice Total'], 'only live suffixes count');
    }

    public function testEverySuffixCancelledIsACancelledOrder(): void
    {
        $status = OrsOrderStatusMapper::map([self::suffix('90803340-00', OrderSuffix::STAGE_HELD_OR_CANCELLED, ordered: 10, shipped: 0)]);

        self::assertSame(PurchaseOrderState::CANCELLED, $status?->state);
    }

    public function testEnteredOrPickingIsAcknowledged(): void
    {
        self::assertSame(PurchaseOrderState::ACKNOWLEDGED, OrsOrderStatusMapper::map([self::suffix('1-00', OrderSuffix::STAGE_ENTERED, ordered: 5, shipped: 0)])?->state);
        self::assertSame(PurchaseOrderState::ACKNOWLEDGED, OrsOrderStatusMapper::map([self::suffix('1-00', OrderSuffix::STAGE_PICKING, ordered: 5, shipped: 0)])?->state);
    }

    public function testAHoldIsOnHold(): void
    {
        self::assertSame(PurchaseOrderState::ON_HOLD, OrsOrderStatusMapper::map([self::suffix('1-00', OrderSuffix::STAGE_HELD_OR_CANCELLED, ordered: 5, shipped: 0, held: true)])?->state);
        self::assertSame(PurchaseOrderState::ON_HOLD, OrsOrderStatusMapper::map([self::suffix('1-00', OrderSuffix::STAGE_ENTERED, ordered: 5, shipped: 0, held: true)])?->state);
    }

    public function testABackOrderKeepsItPartiallyShippedEvenWhenTheFirstPartIsInvoiced(): void
    {
        $status = OrsOrderStatusMapper::map([
            self::suffix('1-00', OrderSuffix::STAGE_INVOICED, ordered: 10, shipped: 6, backordered: 4, tracking: '1ZA'),
            self::suffix('1-01', OrderSuffix::STAGE_ENTERED, ordered: 10, shipped: 0, backordered: 4),
        ]);

        self::assertNotNull($status);
        self::assertSame(PurchaseOrderState::PARTIALLY_SHIPPED, $status->state);
        self::assertSame(['G-1' => ['shipped' => 6, 'backordered' => 4]], $status->lines);
        self::assertSame([], $status->invoiceInCents, 'not invoiced until every part is');
        self::assertSame('1ZA', $status->shipments[0]['trackingNumber']);
    }

    public function testTheBackOrderShippingLaterCompletesIt(): void
    {
        $status = OrsOrderStatusMapper::map([
            self::suffix('1-00', OrderSuffix::STAGE_INVOICED, ordered: 10, shipped: 6, backordered: 4, tracking: '1ZA'),
            self::suffix('1-01', OrderSuffix::STAGE_SHIPPED, ordered: 10, shipped: 4, backordered: 0, tracking: '1ZB'),
        ]);

        self::assertNotNull($status);
        self::assertSame(PurchaseOrderState::SHIPPED, $status->state);
        self::assertSame(['G-1' => ['shipped' => 10, 'backordered' => 0]], $status->lines);
        self::assertCount(2, $status->shipments);
    }

    private static function suffix(string $number, int $stage, int $ordered, int $shipped, int $backordered = 0, bool $held = false, ?string $tracking = null): OrderSuffix
    {
        return new OrderSuffix(
            $number,
            'PO-1',
            $stage,
            $held,
            '2026-10-27',
            'CIN',
            [new OrderSuffixLine('G-1', 'BX', $ordered, $ordered - $shipped, $backordered, $shipped, 717)],
            null === $tracking ? [] : [['shipVia' => 'UPSG', 'shipViaDescription' => 'UPS GRD COML', 'trackingNumber' => $tracking]],
            OrderSuffix::STAGE_INVOICED === $stage ? '2026-10-28' : null,
            OrderSuffix::STAGE_INVOICED === $stage ? ['Invoice Total' => 5000] : [],
        );
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/Supplier/OrsNasco/fixtures/'.$name);
    }
}
