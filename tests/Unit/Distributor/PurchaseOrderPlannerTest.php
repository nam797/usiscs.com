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
use App\Distributor\SourceSelector;
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
            ['code' => 'G-1_5', 'distributor' => 'ors_nasco', 'fulfilment' => Fulfilment::OrsDirect, 'quantity' => 3, 'cost' => 850, 'supplierSku' => 'G-1.5'],
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
        // No source at all: neither who nor how is known.
        self::assertSame([
            'No distributor is set for H-1: set one on the purchase order (and add a source for next time).',
            'Fulfilment is not confirmed for H-1: choose direct or cross-dock.',
        ], $planner->problems($purchaseOrders[2]));
    }

    public function testEachLineGoesToItsCheapestSourceAndTheTimelineSaysWhy(): void
    {
        $order = self::order('000042', [
            // Fake is cheaper and has stock: it wins.
            ['code' => 'A-1', 'distributor' => 'ors_nasco', 'fulfilment' => Fulfilment::OrsDirect, 'cost' => 900, 'stock' => 50, 'sources' => [['fake', Fulfilment::OrsDirect, 700, 50]]],
            // Fake is cheaper but short: ORS has it.
            ['code' => 'B-1', 'distributor' => 'ors_nasco', 'fulfilment' => Fulfilment::CrossDock, 'cost' => 900, 'stock' => 50, 'quantity' => 5, 'sources' => [['fake', Fulfilment::OrsDirect, 700, 2]]],
        ]);

        $purchaseOrders = $this->planner()->plan($order);

        self::assertSame(
            [['fake', ['A-1']], ['ors_nasco', ['B-1']]],
            array_map(static fn (PurchaseOrder $p): array => [$p->getDistributor(), array_values($p->getLines()->map(static fn ($line): string => $line->getVariantCode())->toArray())], $purchaseOrders),
        );
        self::assertSame(700, $purchaseOrders[0]->getLines()->first() ? $purchaseOrders[0]->getLines()->first()->getUnitCostInCents() : null);
        self::assertSame(Fulfilment::CrossDock, $purchaseOrders[1]->getFulfilment());
        self::assertStringContainsString('A-1: Test distributor $7.00 (stock 50) over ORS Nasco $9.00 (stock 50)', (string) $purchaseOrders[0]->getLastEvent()?->getMessage());
        self::assertStringContainsString('B-1: ORS Nasco $9.00 (stock 50) over Test distributor $7.00 (stock 2)', (string) $purchaseOrders[1]->getLastEvent()?->getMessage());
    }

    private function planner(): PurchaseOrderPlanner
    {
        $registry = new DistributorRegistry([new OrsNascoDistributor(new class implements OrderQueryClientInterface {
            public function findByCustomerPo(string $customerPo, ?\DateTimeImmutable $placedAfter = null): array
            {
                return [];
            }
        }), new FakeDistributor()]);

        return new PurchaseOrderPlanner($registry, new SourceSelector($registry));
    }
}
