<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\DistributorRegistry;
use App\Distributor\SourceSelector;
use App\Entity\Catalog\SupplierItem;
use App\Entity\Product\ProductVariant;
use PHPUnit\Framework\TestCase;

final class SourceSelectorTest extends TestCase
{
    private const PRICE = 1000;

    public function testTheCheapestSourceWithStockWins(): void
    {
        $choice = $this->choose([['a', 800, 10], ['b', 700, 10], ['c', 750, 10]]);

        self::assertSame('b', $choice->source?->getSupplier());
        self::assertFalse($choice->backorder);
        self::assertSame('b $7.00 (stock 10) over c $7.50 (stock 10), a $8.00 (stock 10)', $choice->reason);
    }

    public function testACheaperSourceThatIsShortLosesToOneWithStock(): void
    {
        self::assertSame('a', $this->choose([['a', 800, 5], ['b', 700, 4]], quantity: 5)->source?->getSupplier());
    }

    public function testUnknownStockComesAfterInStockButBeforeShort(): void
    {
        self::assertSame('a', $this->choose([['a', 900, 5], ['b', 700, null]], quantity: 5)->source?->getSupplier());
        self::assertSame('b', $this->choose([['a', 600, 1], ['b', 700, null]], quantity: 5)->source?->getSupplier());
    }

    public function testUnknownCostComesAfterKnownCost(): void
    {
        self::assertSame('b', $this->choose([['a', null, 10], ['b', 900, 10]])->source?->getSupplier());
    }

    public function testASourceAtOrAboveThePricePaidIsPassedOver(): void
    {
        $choice = $this->choose([['a', 1000, 10], ['b', 1200, 10], ['c', 990, 0]]);

        self::assertSame('c', $choice->source?->getSupplier());
        self::assertTrue($choice->backorder);
        self::assertStringContainsString('No source has 1 in stock: expect a back-order', $choice->reason);
        self::assertStringContainsString('Passed over: a $10.00 is not below the price paid; b $12.00 is not below the price paid', $choice->reason);
    }

    public function testNothingAtALossMeansNoChoice(): void
    {
        $choice = $this->choose([['a', 1000, 10]]);

        self::assertNull($choice->source);
        self::assertSame('a $10.00 is not below the price paid', $choice->reason);
    }

    public function testOnlySwitchedOnConfirmedSourcesCount(): void
    {
        $variant = $this->variant([['off', 100, 10], ['unconfirmed', 200, 10], ['ok', 900, 10]]);
        $variant->getSource('off')?->setEnabled(false);
        $variant->getSource('unconfirmed')?->setFulfilment(Fulfilment::Unconfirmed);

        self::assertSame('ok', (new SourceSelector(new DistributorRegistry([])))->choose($variant, 1, self::PRICE)->source?->getSupplier());

        $variant->getSource('ok')?->setEnabled(false);
        $choice = (new SourceSelector(new DistributorRegistry([])))->choose($variant, 1, self::PRICE);
        self::assertNull($choice->source);
        self::assertSame('no source with a confirmed fulfilment', $choice->reason);
    }

    public function testTiesGoToDirectThenTheDistributorCode(): void
    {
        $variant = $this->variant([['b', 700, 10], ['a', 700, 10], ['c', 700, 10]]);
        $variant->getSource('a')?->setFulfilment(Fulfilment::CrossDock);

        self::assertSame('b', (new SourceSelector(new DistributorRegistry([])))->choose($variant, 1, self::PRICE)->source?->getSupplier());
    }

    /** @param list<array{string, ?int, ?int}> $sources distributor, cost, stock */
    private function choose(array $sources, int $quantity = 1): \App\Distributor\SourceChoice
    {
        return (new SourceSelector(new DistributorRegistry([])))->choose($this->variant($sources), $quantity, self::PRICE);
    }

    /** @param list<array{string, ?int, ?int}> $sources distributor, cost, stock */
    private function variant(array $sources): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->setCode('G-1');
        foreach ($sources as [$distributor, $cost, $stock]) {
            $source = new SupplierItem($variant, $distributor);
            $source->setFulfilment(Fulfilment::OrsDirect);
            $source->setCostInCents($cost);
            $source->recordStock($stock);
        }

        return $variant;
    }
}
