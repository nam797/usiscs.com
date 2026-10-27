<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\DistributorRegistry;
use App\Distributor\Fake\FakeDistributor;
use App\Distributor\OrsNasco\OrsNascoDistributor;
use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderPlanner;
use App\Distributor\PurchaseOrderState;
use App\Distributor\SupplierSkuLookupInterface;
use App\Entity\Procurement\PurchaseOrder;
use App\Supplier\OrsNasco\OrderQueryClientInterface;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderPlannerTest extends TestCase
{
    use PurchaseOrderTestHelpers;

    public function testOneOrderPerDistributorAndFulfilmentNumberedInAStableOrder(): void
    {
        $order = self::order('000042', [
            ['code' => 'G-1', 'distributor' => 'ors_nasco', 'fulfilment' => Fulfilment::OrsDirect, 'quantity' => 3],
            ['code' => 'G-2', 'distributor' => 'fake', 'fulfilment' => Fulfilment::OrsDirect],
            ['code' => 'G-3', 'distributor' => 'ors_nasco', 'fulfilment' => Fulfilment::CrossDock],
            ['code' => 'G-4', 'distributor' => 'ors_nasco', 'fulfilment' => Fulfilment::OrsDirect],
        ]);

        $purchaseOrders = $this->planner()->plan($order);

        self::assertSame(
            [
                ['000042-1', 'fake', 'ors_direct', ['G-2']],
                ['000042-2', 'ors_nasco', 'cross_dock', ['G-3']],
                ['000042-3', 'ors_nasco', 'ors_direct', ['G-1', 'G-4']],
            ],
            array_map(static fn (PurchaseOrder $p): array => [
                $p->getNumber(),
                $p->getDistributor(),
                $p->getFulfilment()->value,
                array_values($p->getLines()->map(static fn ($line): string => $line->getVariantCode())->toArray()),
            ], $purchaseOrders),
        );

        foreach ($purchaseOrders as $purchaseOrder) {
            self::assertSame(PurchaseOrderState::QUEUED, $purchaseOrder->getState());
            self::assertSame(PurchaseOrderEventType::Created, $purchaseOrder->getLastEvent()?->getType());
        }
    }

    public function testLinesCarryTheSuppliersSkuCostQuantityAndPrice(): void
    {
        $order = self::order('000042', [
            ['code' => 'G-1_5', 'distributor' => 'ors_nasco', 'fulfilment' => Fulfilment::OrsDirect, 'quantity' => 3],
        ]);

        $line = $this->planner()->plan($order)[0]->getLines()->first();

        self::assertNotFalse($line);
        self::assertSame('G-1_5', $line->getVariantCode());
        self::assertSame('G-1.5', $line->getSupplierSku());
        self::assertSame(850, $line->getUnitCostInCents());
        self::assertSame(3, $line->getQuantity());
        self::assertSame(1299, $line->getUnitPriceInCents());
        self::assertSame('Glove G-1_5', $line->getName());
    }

    public function testOrdersItCannotSendAsTheyAreStillGetAPurchaseOrderWithTheProblemsSpelledOut(): void
    {
        $order = self::order('000042', [
            ['code' => 'H-1', 'distributor' => null, 'fulfilment' => Fulfilment::OrsDirect],
            ['code' => 'U-1', 'distributor' => 'ors_nasco', 'fulfilment' => Fulfilment::Unconfirmed],
            ['code' => 'X-1', 'distributor' => 'gone', 'fulfilment' => Fulfilment::CrossDock],
        ]);

        $planner = $this->planner();
        $purchaseOrders = $planner->plan($order);

        self::assertCount(3, $purchaseOrders);
        self::assertSame(['There is no distributor called "gone".'], $planner->problems($purchaseOrders[0]));
        self::assertSame(['Fulfilment is not confirmed for U-1: choose direct or cross-dock.'], $planner->problems($purchaseOrders[1]));
        self::assertSame(PurchaseOrder::UNASSIGNED, $purchaseOrders[2]->getDistributor());
        self::assertSame(['No distributor is set for H-1: set one on the purchase order (and on the variant for next time).'], $planner->problems($purchaseOrders[2]));
    }

    private function planner(): PurchaseOrderPlanner
    {
        $skus = new class implements SupplierSkuLookupInterface {
            public function find(array $variantCodes): array
            {
                return ['G-1_5' => ['supplierSku' => 'G-1.5', 'unitCostInCents' => 850]];
            }
        };

        return new PurchaseOrderPlanner(new DistributorRegistry([new OrsNascoDistributor(new class implements OrderQueryClientInterface {
            public function findByCustomerPo(string $customerPo, ?\DateTimeImmutable $placedAfter = null): array
            {
                return [];
            }
        }), new FakeDistributor()]), $skus);
    }
}
