<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\CustomerShipmentUpdater;
use App\Distributor\Fake\FakeDistributor;
use App\Distributor\PurchaseOrderTransition;
use App\Entity\Order\Order;
use App\Entity\Procurement\PurchaseOrder;
use App\Entity\Shipping\Shipment;
use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\Mailer\ShipmentEmailManagerInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

final class CustomerShipmentUpdaterTest extends TestCase
{
    use PurchaseOrderTestHelpers;

    /** @var \ArrayObject<int, ShipmentInterface> */
    private \ArrayObject $emailed;

    protected function setUp(): void
    {
        $this->emailed = new \ArrayObject();
    }

    public function testADirectOrderShipsWhenTheDistributorShips(): void
    {
        [$order, $shipment] = self::order();
        $direct = self::shipped($order, Fulfilment::OrsDirect, '1ZDIRECT');

        self::assertTrue($this->updater()->update($order, [$direct]));

        self::assertSame('1ZDIRECT', $shipment->getTracking());
        self::assertSame(ShipmentInterface::STATE_SHIPPED, $shipment->getState());
        self::assertCount(1, $this->emailed);
    }

    public function testACrossDockOrderWaitsUntilWeShipItOnAndNeverShowsTheInboundTracking(): void
    {
        [$order, $shipment] = self::order();
        $crossDock = self::shipped($order, Fulfilment::CrossDock, '1ZTOWAREHOUSE');

        self::assertFalse($this->updater()->update($order, [$crossDock]));
        self::assertNull($shipment->getTracking());
        self::assertSame(ShipmentInterface::STATE_READY, $shipment->getState());

        $crossDock->markReceived('staff@usiscs.test');
        self::assertFalse($this->updater()->update($order, [$crossDock]));

        $crossDock->markForwarded('UPS Ground', '1ZOURS', 'staff@usiscs.test');
        self::assertTrue($this->updater()->update($order, [$crossDock]));
        self::assertSame('1ZOURS', $shipment->getTracking());
        self::assertSame(ShipmentInterface::STATE_SHIPPED, $shipment->getState());
        self::assertCount(1, $this->emailed);
    }

    public function testAMixedOrderGetsTrackingAsEachPartGoesButShipsWhenBothHave(): void
    {
        [$order, $shipment] = self::order();
        $direct = self::shipped($order, Fulfilment::OrsDirect, '1ZDIRECT');
        $crossDock = self::shipped($order, Fulfilment::CrossDock, '1ZTOWAREHOUSE');

        self::assertTrue($this->updater()->update($order, [$direct, $crossDock]));
        self::assertSame('1ZDIRECT', $shipment->getTracking());
        self::assertSame(ShipmentInterface::STATE_READY, $shipment->getState());
        self::assertCount(0, $this->emailed);

        $crossDock->markReceived('staff@usiscs.test');
        $crossDock->markForwarded('UPS Ground', '1ZOURS', 'staff@usiscs.test');
        self::assertTrue($this->updater()->update($order, [$direct, $crossDock]));

        self::assertSame('1ZDIRECT, 1ZOURS', $shipment->getTracking());
        self::assertSame(ShipmentInterface::STATE_SHIPPED, $shipment->getState());
        self::assertCount(1, $this->emailed);
    }

    public function testACancelledPartDoesNotHoldTheOrderBack(): void
    {
        [$order, $shipment] = self::order();
        $direct = self::shipped($order, Fulfilment::OrsDirect, '1ZDIRECT');
        $cancelled = new PurchaseOrder($order, '000001-2', FakeDistributor::CODE, Fulfilment::CrossDock);
        self::stateMachine()->apply($cancelled, PurchaseOrderTransition::Cancel);

        $this->updater()->update($order, [$direct, $cancelled]);

        self::assertSame(ShipmentInterface::STATE_SHIPPED, $shipment->getState());
    }

    /** @return array{Order, Shipment} */
    private static function order(): array
    {
        $order = new Order();
        $shipment = new Shipment();
        $shipment->setState(ShipmentInterface::STATE_READY);
        $order->addShipment($shipment);

        return [$order, $shipment];
    }

    private static function shipped(Order $order, Fulfilment $fulfilment, string $tracking): PurchaseOrder
    {
        $purchaseOrder = new PurchaseOrder($order, '000001-'.$fulfilment->value, FakeDistributor::CODE, $fulfilment);
        foreach ([PurchaseOrderTransition::Start, PurchaseOrderTransition::Submit, PurchaseOrderTransition::Ship] as $transition) {
            self::stateMachine()->apply($purchaseOrder, $transition);
        }
        $purchaseOrder->addShipment($tracking, 'UPS GRD COML');

        return $purchaseOrder;
    }

    private function updater(): CustomerShipmentUpdater
    {
        $stateMachine = new class implements StateMachineInterface {
            public function can(object $subject, string $graphName, string $transition): bool
            {
                return $subject instanceof ShipmentInterface && ShipmentInterface::STATE_READY === $subject->getState();
            }

            public function apply(object $subject, string $graphName, string $transition, array $context = []): void
            {
                if ($subject instanceof ShipmentInterface) {
                    $subject->setState(ShipmentInterface::STATE_SHIPPED);
                }
            }

            public function getEnabledTransitions(object $subject, string $graphName): array
            {
                return [];
            }

            public function getTransitionFromState(object $subject, string $graphName, string $fromState): ?string
            {
                return null;
            }

            public function getTransitionToState(object $subject, string $graphName, string $toState): ?string
            {
                return null;
            }
        };

        $emails = new class($this->emailed) implements ShipmentEmailManagerInterface {
            /** @param \ArrayObject<int, ShipmentInterface> $emailed */
            public function __construct(private readonly \ArrayObject $emailed)
            {
            }

            public function sendConfirmationEmail(ShipmentInterface $shipment): void
            {
                $this->emailed[] = $shipment;
            }

            public function resendConfirmationEmail(ShipmentInterface $shipment): void
            {
            }
        };

        return new CustomerShipmentUpdater($stateMachine, $emails);
    }
}
