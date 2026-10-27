<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor\Warehouse;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\Fake\FakeDistributor;
use App\Distributor\PurchaseOrderTransition;
use App\Distributor\Warehouse\ExpectedArrival;
use App\Distributor\Warehouse\WarehouseStatus;
use App\Entity\Order\Order;
use App\Entity\Procurement\PurchaseOrder;
use App\Tests\Unit\Distributor\PurchaseOrderTestHelpers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WarehouseStatusTest extends TestCase
{
    use PurchaseOrderTestHelpers;

    private const TODAY = '2026-10-28';

    /** @return iterable<string, array{list<PurchaseOrderTransition>, ?string, bool, bool, ?WarehouseStatus}> */
    public static function cases(): iterable
    {
        $submitted = [PurchaseOrderTransition::Start, PurchaseOrderTransition::Submit];
        $shipped = [...$submitted, PurchaseOrderTransition::Ship];

        // transitions, expected date, received, shipped on, status
        yield 'queued' => [[], null, false, false, WarehouseStatus::AwaitingDistributor];
        yield 'submitted' => [$submitted, null, false, false, WarehouseStatus::AwaitingDistributor];
        yield 'submitted, expected later' => [$submitted, '2026-10-30', false, false, WarehouseStatus::AwaitingDistributor];
        yield 'partially shipped' => [[...$submitted, PurchaseOrderTransition::ShipPartially], null, false, false, WarehouseStatus::InTransit];
        yield 'shipped, no date' => [$shipped, null, false, false, WarehouseStatus::InTransit];
        yield 'shipped, due today' => [$shipped, self::TODAY, false, false, WarehouseStatus::InTransit];
        yield 'shipped, due yesterday' => [$shipped, '2026-10-27', false, false, WarehouseStatus::Overdue];
        yield 'not shipped, due yesterday' => [$submitted, '2026-10-27', false, false, WarehouseStatus::Overdue];
        yield 'invoiced' => [[...$shipped, PurchaseOrderTransition::Invoice], null, false, false, WarehouseStatus::InTransit];
        yield 'received late' => [$shipped, '2026-10-20', true, false, WarehouseStatus::Received];
        yield 'shipped on' => [$shipped, '2026-10-20', true, true, WarehouseStatus::ShippedToCustomer];
        yield 'cancelled' => [[PurchaseOrderTransition::Cancel], null, false, false, null];
    }

    /** @param list<PurchaseOrderTransition> $transitions */
    #[DataProvider('cases')]
    public function testStatus(array $transitions, ?string $expectedAt, bool $received, bool $shippedOn, ?WarehouseStatus $status): void
    {
        $purchaseOrder = new PurchaseOrder(new Order(), '000001-1', FakeDistributor::CODE, Fulfilment::CrossDock);
        foreach ($transitions as $transition) {
            self::stateMachine()->apply($purchaseOrder, $transition);
        }
        if (null !== $expectedAt) {
            $purchaseOrder->changeExpectedAt(new \DateTimeImmutable($expectedAt));
        }
        if ($received) {
            $purchaseOrder->markReceived('staff@usiscs.test');
        }
        if ($shippedOn) {
            $purchaseOrder->markForwarded('UPS', '1ZOURS', 'staff@usiscs.test');
        }

        self::assertSame($status, WarehouseStatus::of($purchaseOrder, new \DateTimeImmutable(self::TODAY.' 15:30')));
    }

    public function testDirectPurchaseOrdersHaveNoWarehouseStatus(): void
    {
        $purchaseOrder = new PurchaseOrder(new Order(), '000001-1', FakeDistributor::CODE, Fulfilment::OrsDirect);

        self::assertNull(WarehouseStatus::of($purchaseOrder, new \DateTimeImmutable(self::TODAY)));
    }

    public function testExpectedArrivalCountsBusinessDays(): void
    {
        // Thursday + 3 business days skips the weekend: Tuesday.
        self::assertSame('2026-11-03', ExpectedArrival::after(new \DateTimeImmutable('2026-10-29 16:45'), 3)->format('Y-m-d'));
        // Saturday + 1: Monday.
        self::assertSame('2026-11-02', ExpectedArrival::after(new \DateTimeImmutable('2026-10-31'), 1)->format('Y-m-d'));
        self::assertSame('2026-10-29', ExpectedArrival::after(new \DateTimeImmutable('2026-10-29 16:45'), 0)->format('Y-m-d'));
    }

    public function testChangingTheExpectedDateIsLoggedOnlyWhenItChanges(): void
    {
        $purchaseOrder = new PurchaseOrder(new Order(), '000001-1', FakeDistributor::CODE, Fulfilment::CrossDock);

        self::assertTrue($purchaseOrder->changeExpectedAt(new \DateTimeImmutable('2026-11-02 13:00'), 'staff@usiscs.test', 'Per ORS'));
        self::assertFalse($purchaseOrder->changeExpectedAt(new \DateTimeImmutable('2026-11-02')));

        self::assertCount(1, $purchaseOrder->getEvents());
        self::assertSame('Expected at the warehouse on Mon 2026-11-02. Per ORS', $purchaseOrder->getLastEvent()?->getMessage());
        self::assertSame('staff@usiscs.test', $purchaseOrder->getLastEvent()->getActor());
    }
}
