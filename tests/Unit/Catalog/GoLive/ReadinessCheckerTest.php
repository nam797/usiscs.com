<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\GoLive;

use App\Catalog\Fulfilment\Fulfilment;
use App\Catalog\GoLive\ReadinessChecker;
use App\Entity\Catalog\SupplierItem;
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
        self::assertSame([], (new ReadinessChecker())->problems($this->product(cost: 1298)));
    }

    public function testItNeedsAPriceAboveTheSupplierCost(): void
    {
        self::assertSame(
            ['price $12.99 in channel "default" is not above the supplier cost $12.99'],
            (new ReadinessChecker())->problems($this->product(cost: 1299)),
        );
    }

    public function testAnUnknownCostDoesNotBlockGoingLive(): void
    {
        self::assertSame([], (new ReadinessChecker())->problems($this->product(cost: null)));
    }

    public function testEverySwitchedOnSourceMustCostLessThanThePrice(): void
    {
        $product = $this->product(cost: 900);
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariant::class, $variant);
        $dearer = new SupplierItem($variant, 'other');
        $dearer->setFulfilment(Fulfilment::OrsDirect);
        $dearer->setCostInCents(1350);

        self::assertSame(['price $12.99 in channel "default" is not above the supplier cost $13.50'], (new ReadinessChecker())->problems($product));

        $dearer->setEnabled(false);
        self::assertSame([], (new ReadinessChecker())->problems($product), 'a switched-off source doesn\'t count');
    }

    public function testASwitchedOffSourceDoesNotConfirmShipping(): void
    {
        $product = $this->product();
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariant::class, $variant);
        $source = $variant->getSources()->first();
        self::assertInstanceOf(SupplierItem::class, $source);
        $source->setEnabled(false);

        self::assertSame(['shipping not confirmed (ORS direct or cross-dock)'], (new ReadinessChecker())->problems($product));
    }

    /** @param array<string, ?int> $channels channel code => price in cents (null: no pricing) */
    private function product(string $name = 'Safety glasses', bool $withCategory = true, array $channels = ['default' => 1299], Fulfilment $fulfilment = Fulfilment::OrsDirect, ?int $cost = null): Product
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
        $source = new SupplierItem($variant, SupplierItem::ORS_NASCO);
        $source->setFulfilment($fulfilment);
        $source->setCostInCents($cost);
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
