<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Catalog\Unit\PackUnit;
use App\Entity\Channel\Channel;
use App\Entity\Channel\ChannelPricing;
use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use App\Twig\SeoExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class SeoExtensionTest extends TestCase
{
    public function testTheCanonicalUrlDropsEverythingButThePage(): void
    {
        self::assertSame('https://usiscs.com/safety-ppe', $this->canonical('https://usiscs.com/safety-ppe?limit=48&sorting%5Bprice%5D=asc'));
        self::assertSame('https://usiscs.com/safety-ppe?page=3', $this->canonical('https://usiscs.com/safety-ppe?page=3&limit=96'));
        self::assertSame('https://usiscs.com/safety-ppe', $this->canonical('https://usiscs.com/safety-ppe?page=1'));
        self::assertSame('https://usiscs.com/products/safety-glasses', $this->canonical('https://usiscs.com/products/safety-glasses?criteria%5Bsearch%5D%5Bvalue%5D=x'));
    }

    public function testAProductsOwnTextWins(): void
    {
        $product = $this->product();
        $product->setShortDescription('<p>Lint-free   pads that clean respirator face pieces.</p>');
        self::assertSame('Lint-free pads that clean respirator face pieces.', $this->extension()->productMetaDescription($product));

        $product->setMetaDescription('Written for search.');
        self::assertSame('Written for search.', $this->extension()->productMetaDescription($product));
    }

    public function testWithoutTextItIsBuiltFromNamePackUnitAndPrice(): void
    {
        $channel = new Channel();
        $channel->setCode('default');

        self::assertSame(
            'Respirator Cleaning Pads. $17.40 per case of 12. Order online or request bulk pricing from US Industrial Supply Chain Solutions.',
            $this->extension()->productMetaDescription($this->product(), $channel),
        );
    }

    public function testALongDescriptionIsCutAtAWord(): void
    {
        $product = $this->product();
        $product->setDescription(str_repeat('Heavy duty nitrile gloves for chemical handling. ', 6));

        $description = $this->extension()->productMetaDescription($product);

        self::assertLessThanOrEqual(SeoExtension::DESCRIPTION_LENGTH, mb_strlen($description));
        self::assertStringEndsWith('…', $description);
        self::assertStringNotContainsString('  ', $description);
    }

    private function product(): Product
    {
        $product = new Product();
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Respirator Cleaning Pads');

        $variant = new ProductVariant();
        $variant->setCode('PADS');
        $variant->setPackUnit(PackUnit::parse('case of 12'));
        $pricing = new ChannelPricing();
        $pricing->setChannelCode('default');
        $pricing->setPrice(1740);
        $variant->addChannelPricing($pricing);
        $product->addVariant($variant);

        return $product;
    }

    private function canonical(string $url): string
    {
        $requests = new RequestStack();
        $requests->push(Request::create($url));

        return (new SeoExtension($requests))->canonicalUrl();
    }

    private function extension(): SeoExtension
    {
        return new SeoExtension(new RequestStack());
    }
}
