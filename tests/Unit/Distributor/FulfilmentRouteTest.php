<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\Fake\FakeDistributor;
use App\Distributor\FulfilmentRoute;
use App\Distributor\PurchaseOrderTransition;
use App\Entity\Order\Order;
use App\Entity\Procurement\PurchaseOrder;
use PHPUnit\Framework\TestCase;

final class FulfilmentRouteTest extends TestCase
{
    use PurchaseOrderTestHelpers;

    public function testItSummarisesTheOrdersPurchaseOrders(): void
    {
        self::assertNull(FulfilmentRoute::of([]));
        self::assertSame(FulfilmentRoute::Direct, FulfilmentRoute::of([self::po(Fulfilment::OrsDirect)]));
        self::assertSame(FulfilmentRoute::CrossDock, FulfilmentRoute::of([self::po(Fulfilment::CrossDock), self::po(Fulfilment::CrossDock)]));
        self::assertSame(FulfilmentRoute::Mixed, FulfilmentRoute::of([self::po(Fulfilment::OrsDirect), self::po(Fulfilment::CrossDock)]));
        self::assertSame(FulfilmentRoute::Unconfirmed, FulfilmentRoute::of([self::po(Fulfilment::CrossDock), self::po(Fulfilment::Unconfirmed)]));
    }

    public function testCancelledPurchaseOrdersDontCount(): void
    {
        self::assertSame(FulfilmentRoute::Direct, FulfilmentRoute::of([self::po(Fulfilment::OrsDirect), self::po(Fulfilment::CrossDock, cancelled: true)]));
        self::assertNull(FulfilmentRoute::of([self::po(Fulfilment::CrossDock, cancelled: true)]));
    }

    public function testTheOrderStoresIt(): void
    {
        $order = new Order();
        self::assertNull($order->getFulfilmentRoute());

        $order->setFulfilmentRoute(FulfilmentRoute::Mixed);

        self::assertSame(FulfilmentRoute::Mixed, $order->getFulfilmentRoute());
    }

    private static function po(Fulfilment $fulfilment, bool $cancelled = false): PurchaseOrder
    {
        $purchaseOrder = new PurchaseOrder(new Order(), '000001-1', FakeDistributor::CODE, $fulfilment);
        if ($cancelled) {
            self::stateMachine()->apply($purchaseOrder, PurchaseOrderTransition::Cancel);
        }

        return $purchaseOrder;
    }
}
