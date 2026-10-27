<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\PurchaseOrderTransition;
use App\Distributor\PurchaseOrderWatchdog;
use App\Entity\Order\Order;
use App\Entity\Procurement\PurchaseOrder;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderWatchdogTest extends TestCase
{
    use PurchaseOrderTestHelpers;

    public function testAnOrderWaitingTooLongForManualEntryIsReported(): void
    {
        $purchaseOrder = $this->awaitingManualEntry();
        $watchdog = new PurchaseOrderWatchdog();

        self::assertSame([], $watchdog->stuck([$purchaseOrder], new \DateTimeImmutable('+3 hours')));

        $stuck = $watchdog->stuck([$purchaseOrder], new \DateTimeImmutable('+5 hours'));
        self::assertCount(1, $stuck);
        self::assertSame($purchaseOrder, $stuck[0]['purchaseOrder']);
        self::assertSame('Awaiting manual entry for 5 hours: not placed with the distributor yet', $stuck[0]['reason']);
    }

    public function testItRemindsAtMostOnceADay(): void
    {
        $purchaseOrder = $this->awaitingManualEntry();
        $purchaseOrder->markAlerted();
        $watchdog = new PurchaseOrderWatchdog();

        self::assertSame([], $watchdog->stuck([$purchaseOrder], new \DateTimeImmutable('+5 hours')));
        self::assertCount(1, $watchdog->stuck([$purchaseOrder], new \DateTimeImmutable('+25 hours')));
    }

    public function testQueuedAndFinishedOrdersAreNotWatched(): void
    {
        $queued = new PurchaseOrder(new Order(), '000001-1', 'fake', Fulfilment::OrsDirect);

        self::assertSame([], (new PurchaseOrderWatchdog())->stuck([$queued], new \DateTimeImmutable('+30 days')));
    }

    private function awaitingManualEntry(): PurchaseOrder
    {
        $purchaseOrder = new PurchaseOrder(new Order(), '000001-1', 'fake', Fulfilment::OrsDirect);
        self::stateMachine()->apply($purchaseOrder, PurchaseOrderTransition::Start);
        self::stateMachine()->apply($purchaseOrder, PurchaseOrderTransition::RequestManualEntry);

        return $purchaseOrder;
    }
}
