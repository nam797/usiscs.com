<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\GoLive;

use App\Catalog\Fulfilment\Fulfilment;
use App\Catalog\GoLive\ReadinessChecker;
use App\Catalog\GoLive\SupplierCostLookupInterface;
use App\Entity\Channel\Channel;
use App\Entity\Channel\ChannelPricing;
use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use App\Entity\Taxonomy\Taxon;
use PHPUnit\Framework\TestCase;

final class ReadinessCheckerTest extends TestCase
{
    public function testACompleteProductIsReady(): void
    {
        self::assertSame([], (new ReadinessChecker())->problems($this->product()));
    }

    public function testItNeedsAName(): void
    {
        $product = $this->product(name: '  ');

        self::assertSame(['no name'], (new ReadinessChecker())->problems($product));
    }

    public function testItNeedsACategory(): void
    {
        $product = $this->product(withCategory: false);

        self::assertSame(['no category'], (new ReadinessChecker())->problems($product));
    }

    public function testItNeedsASalesChannel(): void
    {
        $product = $this->product(channels: []);

        self::assertSame(['not in any sales channel'], (new ReadinessChecker())->problems($product));
    }

    public function testItNeedsAPriceInEveryChannelItIsSoldIn(): void
    {
        $product = $this->product(channels: ['default' => 1299, 'wholesale' => null]);

        self::assertSame(['no price in channel "wholesale"'], (new ReadinessChecker())->problems($product));
    }

    public function testAZeroPriceDoesNotCount(): void
    {
        $product = $this->product(channels: ['default' => 0]);

        self::assertSame(['no price in channel "default"'], (new ReadinessChecker())->problems($product));
    }

    public function testItNeedsShippingConfirmed(): void
    {
        $product = $this->product(fulfilment: Fulfilment::Unconfirmed);

        self::assertSame(['shipping not confirmed (ORS direct or cross-dock)'], (new ReadinessChecker())->problems($product));
    }

    public function testEveryChoiceNeedsShippingConfirmed(): void
    {
        $product = $this->product(fulfilment: Fulfilment::CrossDock);
        $second = new ProductVariant();
        $second->setCode('GLASSES-XL');
        $product->addVariant($second);

        self::assertSame(['shipping not confirmed (ORS direct or cross-dock) for GLASSES-XL'], (new ReadinessChecker())->problems($product));
    }

    public function testItReportsEveryProblem(): void
    {
        $product = $this->product(name: '', withCategory: false, channels: ['default' => null]);

        self::assertCount(3, (new ReadinessChecker())->problems($product));
    }

    public function testAPriceAboveTheSupplierCostIsFine(): void
    {
        self::assertSame([], (new ReadinessChecker($this->costs(['GLASSES' => 1298])))->problems($this->product()));
    }

    public function testItNeedsAPriceAboveTheSupplierCost(): void
    {
        $checker = new ReadinessChecker($this->costs(['GLASSES' => 1299]));

        self::assertSame(
            ['price $12.99 in channel "default" is not above the supplier cost $12.99'],
            $checker->problems($this->product()),
        );
    }

    public function testAnUnknownCostDoesNotBlockGoingLive(): void
    {
        self::assertSame([], (new ReadinessChecker($this->costs([])))->problems($this->product()));
    }

    /** @param array<string, int> $costs */
    private function costs(array $costs): SupplierCostLookupInterface
    {
        return new class($costs) implements SupplierCostLookupInterface {
            /** @param array<string, int> $costs */
            public function __construct(private readonly array $costs)
            {
            }

            public function costInCents(string $sku): ?int
            {
                return $this->costs[$sku] ?? null;
            }
        };
    }

    /** @param array<string, ?int> $channels channel code => price in cents (null: no pricing) */
    private function product(string $name = 'Safety glasses', bool $withCategory = true, array $channels = ['default' => 1299], Fulfilment $fulfilment = Fulfilment::OrsDirect): Product
    {
        $product = new Product();
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName($name);

        if ($withCategory) {
            $taxon = new Taxon();
            $taxon->setCode('safety_ppe');
            $product->setMainTaxon($taxon);
        }

        $variant = new ProductVariant();
        $variant->setCode('GLASSES');
        $variant->setFulfilment($fulfilment);
        $product->addVariant($variant);

        foreach ($channels as $code => $price) {
            $channel = new Channel();
            $channel->setCode($code);
            $product->addChannel($channel);

            if (null !== $price) {
                $pricing = new ChannelPricing();
                $pricing->setChannelCode($code);
                $pricing->setPrice($price);
                $variant->addChannelPricing($pricing);
            }
        }

        return $product;
    }
}
