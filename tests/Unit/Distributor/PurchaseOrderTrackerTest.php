<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\DistributorOrderStatus;
use App\Distributor\DistributorRegistry;
use App\Distributor\Fake\FakeDistributor;
use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderNotifierInterface;
use App\Distributor\PurchaseOrderState;
use App\Distributor\PurchaseOrderTracker;
use App\Distributor\PurchaseOrderTransition;
use App\Entity\Order\Order;
use App\Entity\Procurement\PurchaseOrder;
use App\Entity\Procurement\PurchaseOrderEvent;
use App\Entity\Procurement\PurchaseOrderLine;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderTrackerTest extends TestCase
{
    use PurchaseOrderTestHelpers;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $notified;

    private FakeDistributor $distributor;

    protected function setUp(): void
    {
        $this->notified = new \ArrayObject();
        $this->distributor = new FakeDistributor();
    }

    public function testAnOrderStaffPlacedByHandIsFoundAndMarkedSubmitted(): void
    {
        $purchaseOrder = $this->purchaseOrder(PurchaseOrderTransition::Start, PurchaseOrderTransition::RequestManualEntry);
        $this->report($purchaseOrder, PurchaseOrderState::ACKNOWLEDGED);

        self::assertTrue($this->tracker()->track($purchaseOrder));

        self::assertSame(PurchaseOrderState::ACKNOWLEDGED, $purchaseOrder->getState());
        self::assertSame('90803340', $purchaseOrder->getReference());
        self::assertSame(
            [PurchaseOrderEventType::MarkSubmitted, PurchaseOrderEventType::Acknowledge],
            self::eventTypes($purchaseOrder, after: 2),
        );
        self::assertNotNull($purchaseOrder->getLastCheckedAt());
    }

    public function testShippingRecordsTrackingAndQuantitiesThenInvoicing(): void
    {
        $purchaseOrder = $this->submitted();
        $this->report($purchaseOrder, PurchaseOrderState::SHIPPED, lines: ['G-1.5' => ['shipped' => 3, 'backordered' => 0]], tracking: '1ZA');

        self::assertTrue($this->tracker()->track($purchaseOrder));
        self::assertSame(PurchaseOrderState::SHIPPED, $purchaseOrder->getState());
        self::assertSame(3, self::line($purchaseOrder)->getQuantityShipped());
        self::assertSame(['1ZA'], $purchaseOrder->getShipments()->map(static fn ($s): string => $s->getTrackingNumber())->toArray());

        // The same report again changes nothing.
        $events = $purchaseOrder->getEvents()->count();
        self::assertFalse($this->tracker()->track($purchaseOrder));
        self::assertSame($events, $purchaseOrder->getEvents()->count());

        $this->report($purchaseOrder, PurchaseOrderState::INVOICED, lines: ['G-1.5' => ['shipped' => 3, 'backordered' => 0]], tracking: '1ZA', invoice: ['Freight Out' => 1655]);
        self::assertTrue($this->tracker()->track($purchaseOrder));
        self::assertSame(PurchaseOrderState::INVOICED, $purchaseOrder->getState());
        self::assertSame(['Freight Out' => 1655], $purchaseOrder->getLastEvent()?->getPayload()['invoice_in_cents'] ?? null);
    }

    public function testItCatchesUpSeveralStepsAtOnce(): void
    {
        $purchaseOrder = $this->submitted();
        $this->report($purchaseOrder, PurchaseOrderState::INVOICED);

        $this->tracker()->track($purchaseOrder);

        self::assertSame(PurchaseOrderState::INVOICED, $purchaseOrder->getState());
        self::assertSame([PurchaseOrderEventType::Ship, PurchaseOrderEventType::Invoice], self::eventTypes($purchaseOrder, after: 2));
    }

    public function testAHoldAndItsRelease(): void
    {
        $purchaseOrder = $this->submitted();
        $this->report($purchaseOrder, PurchaseOrderState::ON_HOLD);
        $this->tracker()->track($purchaseOrder);
        self::assertSame(PurchaseOrderState::ON_HOLD, $purchaseOrder->getState());

        $this->report($purchaseOrder, PurchaseOrderState::PARTIALLY_SHIPPED, lines: ['G-1.5' => ['shipped' => 1, 'backordered' => 2]]);
        $this->tracker()->track($purchaseOrder);

        self::assertSame(PurchaseOrderState::PARTIALLY_SHIPPED, $purchaseOrder->getState());
        self::assertSame(2, self::line($purchaseOrder)->getQuantityBackordered());
        self::assertContains(PurchaseOrderEventType::Backordered, self::eventTypes($purchaseOrder));
        self::assertContains(PurchaseOrderEventType::Release, self::eventTypes($purchaseOrder));

        // Still partially shipped: no new state event.
        self::assertFalse($this->tracker()->track($purchaseOrder));
    }

    public function testADistributorCancellingAnOrderWeSentNeedsAttention(): void
    {
        $purchaseOrder = $this->submitted();
        $this->report($purchaseOrder, PurchaseOrderState::CANCELLED);

        $this->tracker()->track($purchaseOrder);

        self::assertSame(PurchaseOrderState::NEEDS_ATTENTION, $purchaseOrder->getState());
        self::assertCount(1, $this->notified);
        self::assertStringContainsString('cancelled order 90803340', (string) $this->notified[0]);

        // Asking again doesn't email again.
        $this->tracker()->track($purchaseOrder);
        self::assertCount(1, $this->notified);
    }

    public function testItNeverMovesBackwards(): void
    {
        $purchaseOrder = $this->submitted();
        $this->report($purchaseOrder, PurchaseOrderState::SHIPPED);
        $this->tracker()->track($purchaseOrder);

        $this->report($purchaseOrder, PurchaseOrderState::ACKNOWLEDGED);
        self::assertFalse($this->tracker()->track($purchaseOrder));
        self::assertSame(PurchaseOrderState::SHIPPED, $purchaseOrder->getState());
    }

    public function testAnOrderTheDistributorDoesNotKnowIsLeftAlone(): void
    {
        $purchaseOrder = $this->submitted();

        self::assertFalse($this->tracker()->track($purchaseOrder));
        self::assertSame(PurchaseOrderState::SUBMITTED, $purchaseOrder->getState());
        self::assertNotNull($purchaseOrder->getLastCheckedAt());
    }

    public function testQueuedOrdersAreNotAsked(): void
    {
        $purchaseOrder = $this->purchaseOrder();
        $this->report($purchaseOrder, PurchaseOrderState::SHIPPED);

        self::assertFalse($this->tracker()->track($purchaseOrder));
        self::assertNull($purchaseOrder->getLastCheckedAt());
    }

    private function submitted(): PurchaseOrder
    {
        $purchaseOrder = $this->purchaseOrder(PurchaseOrderTransition::Start, PurchaseOrderTransition::Submit);
        $purchaseOrder->markSubmitted('90803340');

        return $purchaseOrder;
    }

    private function purchaseOrder(PurchaseOrderTransition ...$transitions): PurchaseOrder
    {
        $purchaseOrder = new PurchaseOrder(new Order(), '000001-1', FakeDistributor::CODE, Fulfilment::OrsDirect);
        new PurchaseOrderLine($purchaseOrder, null, 'G-1_5', 'G-1.5', 'Gloves', 3, 'BX', 1299, 717);
        foreach ($transitions as $transition) {
            self::stateMachine()->apply($purchaseOrder, $transition);
        }

        return $purchaseOrder;
    }

    /**
     * @param array<string, array{shipped: int, backordered: int}> $lines
     * @param array<string, int>                                   $invoice
     */
    private function report(PurchaseOrder $purchaseOrder, string $state, array $lines = [], ?string $tracking = null, array $invoice = []): void
    {
        $this->distributor->reportStatus($purchaseOrder->getNumber(), new DistributorOrderStatus(
            '90803340',
            $state,
            'ORS order 90803340: '.$state,
            $lines,
            null === $tracking ? [] : [['trackingNumber' => $tracking, 'carrier' => 'UPS GRD COML']],
            $invoice,
        ));
    }

    private function tracker(): PurchaseOrderTracker
    {
        $notifier = new class($this->notified) implements PurchaseOrderNotifierInterface {
            /** @param \ArrayObject<int, string> $notified */
            public function __construct(private readonly \ArrayObject $notified)
            {
            }

            public function needsAttention(PurchaseOrder $purchaseOrder, string $reason): void
            {
                $this->notified[] = $reason;
            }

            public function manualEntryNeeded(PurchaseOrder $purchaseOrder): void
            {
            }

            public function stuck(array $stuck): void
            {
            }
        };

        return new PurchaseOrderTracker(new DistributorRegistry([$this->distributor]), self::stateMachine(), $notifier);
    }

    private static function line(PurchaseOrder $purchaseOrder): PurchaseOrderLine
    {
        $line = $purchaseOrder->getLines()->first();
        self::assertInstanceOf(PurchaseOrderLine::class, $line);

        return $line;
    }

    /** @return list<PurchaseOrderEventType> */
    private static function eventTypes(PurchaseOrder $purchaseOrder, int $after = 0): array
    {
        return array_slice(array_values($purchaseOrder->getEvents()->map(static fn (PurchaseOrderEvent $e): PurchaseOrderEventType => $e->getType())->toArray()), $after);
    }
}
